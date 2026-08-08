<?php
namespace WC_HS_Sync;

defined( 'ABSPATH' ) || exit;

use WC_HS_Sync\CRM\CRM_Adapter_Interface;
use WC_HS_Sync\Order\Order_Extractor;

/**
 * Sync_Manager orchestrates the full sync workflow.
 * It is CRM-agnostic — depends on the adapter interface only.
 */
class Sync_Manager {

    private CRM_Adapter_Interface $crm;
    private Order_Extractor $extractor;
    private \WC_Logger_Interface $logger;
    private bool $silent = false;

    public function __construct( CRM_Adapter_Interface $crm, Order_Extractor $extractor ) {
        $this->crm       = $crm;
        $this->extractor = $extractor;
        $this->logger    = wc_get_logger();
    }

    public function register_hooks(): void {
        // Triggered for status changes
        add_action( 'woocommerce_order_status_changed',         [ $this, 'handle_status_change' ], 20, 3 );
		add_action( 'wc_hs_sync_process_order', [ $this, 'handle_scheduled_sync' ] );

        // HPOS deletion hook (and classic post deletion fallback)
        add_action( 'woocommerce_before_delete_order',          [ $this, 'handle_order_deleted' ] );
        add_action( 'before_delete_post',                       [ $this, 'handle_post_deleted'  ] );
        add_action( 'woocommerce_email_sent',   [ $this, 'handle_email_sent' ], 10, 3 );
        add_action( 'wc_hs_sync_log_email',     [ $this, 'handle_scheduled_email_note' ], 10, 3 );

    }

    // ──────────────────────────────────────────────
    // Event handlers
    // ──────────────────────────────────────────────

    public function handle_order_created( int $order_id ): void {
        $this->sync_order( $order_id );
    }
    
    public function set_silent( bool $silent ): void {
        $this->silent = $silent;
    }

    public function handle_status_change( int $order_id, string $from, string $to ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			// Action Scheduler not available — fall back to direct sync
			$this->sync_order( $order_id );
			return;
		}

		// Cancel any pending job for this order to avoid duplicates
		as_unschedule_all_actions( 'wc_hs_sync_process_order', [ $order_id ], 'wc-hs-sync' );

		// Schedule a new job 2 minutes from now
		as_schedule_single_action( time() + 120, 'wc_hs_sync_process_order', [ $order_id ], 'wc-hs-sync' );

		$this->log( 'info', "Scheduled sync for order {$order_id} in 2 minutes." );
	}
	
	public function handle_scheduled_sync( int $order_id ): void {
		$this->log( 'info', "Action Scheduler running sync for order {$order_id}." );
		$this->sync_order( $order_id );
	}
 
 public function handle_email_sent( $sent, string $email_id, \WC_Email $email ): void {
    if ( ! $sent ) return;
    if ( ! ( $email->object instanceof \WC_Order ) ) return; // skip non-order emails (reset pw, new account, etc.)

    $order_id  = $email->object->get_id();
    $label     = $email->get_title() ?: $email_id;
    $recipient = $email->get_recipient();

    if ( ! function_exists( 'as_schedule_single_action' ) ) {
        $this->add_email_note( $order_id, $label, $recipient );
        return;
    }

    // Delay slightly past the deal-sync delay (120s) so the deal already exists.
    as_schedule_single_action( time() + 150, 'wc_hs_sync_log_email', [ $order_id, $label, $recipient ], 'wc-hs-sync' );
}

public function handle_scheduled_email_note( int $order_id, string $label, string $recipient ): void {
    $this->add_email_note( $order_id, $label, $recipient );
}

private function add_email_note( int $order_id, string $label, string $recipient ): void {
    $deal_id = $this->crm->find_deal_by_order_id( $order_id );
    if ( ! $deal_id ) {
        $this->log( 'error', "No HubSpot deal for order {$order_id} — couldn't log '{$label}' email note." );
        return;
    }
    $note = sprintf( '"%s" email sent to %s', $label, $recipient );
    $this->crm->add_deal_note( $deal_id, $note );
    $this->log( 'info', "Logged email note on deal {$deal_id}: {$note}" );
}

    public function handle_order_deleted( $order_id ): void {
        $this->delete_deal_for_order( (int) $order_id );
    }

    public function handle_post_deleted( $post_id ): void {
        // Only act on shop_order post type (classic orders)
        if ( get_post_type( $post_id ) !== 'shop_order' ) return;
        $this->delete_deal_for_order( (int) $post_id );
    }

    // ──────────────────────────────────────────────
    // Core sync workflow
    // ──────────────────────────────────────────────

    public function sync_order( int $order_id ): void {
        $data = $this->extractor->extract( $order_id );
        if ( ! $data ) {
            $this->log( 'error', "Order {$order_id} not found." );
            return;
        }

        // 1. Find or create contact via _shipping_email ONLY
		//$contact_id = $this->crm->find_contact( $data['contact_email'] );
		//
		$contact_id = null;

		if ( ! empty( $data['contact_email'] ) ) {
			$contact_id = $this->crm->find_contact( $data['contact_email'] );
		}

		// Fallback: find contact via existing deal association
		if ( ! $contact_id ) {
			$existing_deal_id = $this->crm->find_deal_by_order_id( $data['order_id'] );
			if ( $existing_deal_id ) {
				$contact_id = $this->crm->get_contact_from_deal( $existing_deal_id );
			}
		}
		
		// Fallback: use billing phone if shipping phone is empty
 	    $raw   = trim( $data['shipping_phone'] ) ?: trim( $data['contact_phone'] );
		$phone = $this->normalize_phone( $raw );

		$contact_props = array_filter( [
			'email'      => $data['contact_email'],
			'firstname'  => $data['contact_first'],
			'lastname'   => $data['contact_last'],
			'phone'      => $phone, // shipping phone is the contact phone
			// Shipping address as contact address
			'address' => trim($data['shipping_address_1'] . ' ' . $data['shipping_address_2']),
			'city'       => $data['shipping_city'],
			'zip'        => $data['shipping_postcode'],
			'state'      => $data['shipping_state'],
			'country'    => $data['shipping_country'],
			
		] );

		if ( ! $contact_id ) {
			$contact_id = $this->crm->create_contact( $contact_props );
			if ( ! $contact_id ) {
				$this->log( 'error', "Failed to create HubSpot contact for {$data['contact_email']} (order {$data['order_id']})." );
				return;
			}
			$this->log( 'info', "Created contact {$contact_id} for {$data['contact_email']}." );
		} else {
			// Always update with latest order data — shipping email is source of truth
			$this->crm->update_contact( $contact_id, $contact_props );
			$this->log( 'info', "Updated contact {$contact_id} for order {$data['order_id']}." );
		}

        // 2. Get primary company
        $company = $this->crm->get_primary_company( $contact_id );

        // 3. Update company properties if company exists
        if ( $company ) {
			
			// Convert permanent_box meta value to HubSpot boolean string
			$permanent_box = $data['permanent_box'];
			if ( $permanent_box !== '' && $permanent_box !== null ) {
				$permanent_box = ( $permanent_box == '1' ) ? 'true' : 'false';
			} else {
				$permanent_box = null;
			}
			
            $company_props = array_filter( [
				'box_size'        => $data['box_size']      ?: null,
				'mehrwegkiste'    => $permanent_box,
				'internal_note'   => $data['internal_note'] ?: null,
				'billing_email'   => $data['billing_email'] ?: null,
				// Shipping address → HubSpot standard company address fields
				'address'         => $data['shipping_address_1'],
				'address2'        => $data['shipping_address_2'],
				'city'            => $data['shipping_city'],
				'zip'             => $data['shipping_postcode'],
				// Billing address → custom company properties
				'billing_address'  => $data['billing_address_1'],
				'billing_address2' => $data['billing_address_2'],
				'billing_city'     => $data['billing_city'],
				'billing_zip'      => $data['billing_postcode'],
			], fn( $v ) => $v !== null && $v !== '' );

            if ( $company_props ) {
                $this->crm->update_company( $company['id'], $company_props );
                $this->log( 'info', "Updated company {$company['id']} properties." );
            }
        }

        // 4. Resolve deal stage
        $stage_map = Settings::get_stage_map();
        $stage     = $stage_map[ $data['order_status'] ] ?? 'appointmentscheduled';

        // 5. Build deal properties
        $deal_props = array_filter( [
            'dealname'        => $data['deal_name'],
            'amount'          => $data['order_total'],
            'dealstage'       => $stage,
            'pipeline'        => Settings::get_pipeline(),
            'wc_order_id'     => (string) $data['order_id'],
			'order_link'      => admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $data['order_id'] ),
            'order_items'     => $data['order_text'],
            'closedate'       => $data['completed_timestamp'] ?? null,
            'createdate'      => $data['created_timestamp']   ?? null,
            'production_batch' => $data['batch_number'] ?: null,
        ], fn( $v ) => $v !== null && $v !== '' );

        // 6. Upsert deal
        $deal_id = $this->crm->find_deal_by_order_id( $data['order_id'] );

        if ( $deal_id ) {
            $this->crm->update_deal( $deal_id, $deal_props );
            $this->log( 'info', "Updated deal {$deal_id} for order {$order_id}." );
        } else {
            $deal_id = $this->crm->create_deal( $deal_props );
			if ( ! $deal_id ) {
				$this->log( 'error', "Failed to create deal for order {$order_id}." );
				return;
			}
			// Save HubSpot deal ID to order meta for future reference
			
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->update_meta_data( '_hs_deal_id', $deal_id );
				$order->save();
			}
			
			$this->log( 'info', "Created deal {$deal_id} for order {$order_id}." );
        }

        // 7. Associate deal → contact
        $this->crm->associate_deal_contact( $deal_id, $contact_id );

        // 8. Associate deal → primary company only
        if ( $company ) {
            $this->crm->associate_deal_company( $deal_id, $company['id'] );

            // 9. Set deal owner from company owner
            if ( $company['owner_id'] ) {
                $this->crm->set_deal_owner( $deal_id, $company['owner_id'] );
            }
        }
        
        $this->sync_email_notes_from_order( $order_id, $deal_id );

        $this->log( 'info', "Sync complete for order {$order_id}." );
		// Add order note
		
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->add_order_note(
				sprintf( 'HubSpot sync complete. Deal ID: %s', $deal_id ),
				false, // not a customer note
				false  // not added by customer
			);
		}
		
    }
    
    private function sync_email_notes_from_order( int $order_id, string $deal_id ): void {
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_meta( '_hs_email_notes_synced' ) ) return;

    foreach ( wc_get_order_notes( [ 'order_id' => $order_id, 'type' => 'internal' ] ) as $note ) {
        $content = $note->content;
        $matched = null;

        if ( preg_match( '/^(.+?)\s+sent to customer\s+(\S+@\S+)/i', $content, $m ) ) {
            $matched = sprintf( '"%s" email sent to %s', trim( $m[1] ), $m[2] );
        } elseif ( preg_match( '/versendet\s*\|\s*to:\s*(\S+@\S+)/i', $content, $m ) ) {
            $matched = sprintf( 'Rechnungs-PDF sent to %s', $m[1] );
        } elseif ( preg_match( '/^Sending\s+"(.+?)"\s+email\.?$/i', $content, $m ) ) {
            $matched = sprintf( '"%s" email queued for sending', $m[1] );
        }

        if ( $matched ) {
            $this->crm->add_deal_note( $deal_id, $matched );
        }
    }

    $order->update_meta_data( '_hs_email_notes_synced', 'yes' );
    $order->save();
}
    
    /**
 * Normalize a raw phone string into a valid Swiss E.164 number.
 * Returns null if it can't be confidently normalized (logged for manual review).
 */
private function normalize_phone( string $raw ): ?string {
    $raw = trim( $raw );
    if ( $raw === '' ) {
        return null;
    }

    // Some records have two numbers separated by "/", "," or ";" — use the first.
    $parts = preg_split( '/[\/,;]/', $raw );
    if ( count( $parts ) > 1 ) {
        $this->log( 'warning', "Multiple phone numbers found in '{$raw}'; using first only." );
    }
    $raw = trim( $parts[0] );

    // Strip everything except digits and a leading +
    $digits = preg_replace( '/[^\d+]/', '', $raw );
    if ( $digits === '' ) {
        return null;
    }

    // "00" international prefix → "+"
    if ( strpos( $digits, '00' ) === 0 ) {
        $digits = '+' . substr( $digits, 2 );
    }

    if ( strpos( $digits, '+' ) === 0 ) {
        $national = ltrim( substr( $digits, 1 ), '0' ); // strip stray 0 right after +
        if ( strpos( $national, '41' ) === 0 ) {
            $national = '41' . ltrim( substr( $national, 2 ), '0' );
        }
        $candidate = '+' . $national;
    } elseif ( strpos( $digits, '41' ) === 0 && strlen( $digits ) === 11 ) {
        // Already has country code, just missing "+"
        $candidate = '+' . $digits;
    } elseif ( strpos( $digits, '0' ) === 0 ) {
        // Local Swiss format: 0XX XXX XX XX
        $candidate = '+41' . ltrim( $digits, '0' );
    } else {
        // Bare 9-digit subscriber number, no leading 0
        $candidate = '+41' . $digits;
    }

    // Final validation: +41 followed by exactly 9 digits, first digit 1–9
    if ( ! preg_match( '/^\+41[1-9]\d{8}$/', $candidate ) ) {
        $this->log( 'warning', "Could not confidently normalize phone '{$raw}' -> '{$candidate}'; skipping." );
        return null;
    }

    return $candidate;
}

    private function delete_deal_for_order( int $order_id ): void {
        $deal_id = $this->crm->find_deal_by_order_id( $order_id );
        if ( ! $deal_id ) return;

        $deleted = $this->crm->delete_deal( $deal_id );
        $this->log(
            $deleted ? 'info' : 'error',
            $deleted
                ? "Deleted deal {$deal_id} for deleted order {$order_id}."
                : "Failed to delete deal {$deal_id} for order {$order_id}."
        );
    }

    private function log( string $level, string $message ): void {
        $this->logger->$level( "[HS Sync] {$message}", [ 'source' => 'hubspot-sync' ] );
    
        if ( $level === 'error' && ! $this->silent ) {
            $this->maybe_send_failure_email( $message );
        }
    }
    
    private function maybe_send_failure_email( string $message ): void {
        $email = Settings::get_notification_email();
        if ( ! $email ) return;
    
        wp_mail(
            $email,
            '[WooCommerce HubSpot Sync] Sync failure',
            "A sync error occurred:\n\n" . $message . "\n\nCheck the sync logs in WooCommerce → HubSpot Sync → Logs for details."
        );
    }

}
