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
    }

    // ──────────────────────────────────────────────
    // Event handlers
    // ──────────────────────────────────────────────

    public function handle_order_created( int $order_id ): void {
        $this->sync_order( $order_id );
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

        // 1. Find contact via _shipping_email
        $contact_id = $this->crm->find_contact( $data['contact_email'] );
        if ( ! $contact_id ) {
            $this->log( 'warning', "No HubSpot contact for email {$data['contact_email']} (order {$order_id})." );
            return;
        }

        // 2. Get primary company
        $company = $this->crm->get_primary_company( $contact_id );

        // 3. Update company properties if company exists
        if ( $company ) {
            $company_props = array_filter( [
                'box_size'       => $data['box_size']       ?: null,
                'permanent_box'  => $data['permanent_box']  ?: null,
                'internal_note'  => $data['internal_note']  ?: null,
                'billing_email'  => $data['billing_email']  ?: null, // only if different from shipping
            ] );

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
            'order_items'     => $data['order_text'],
            'closedate'       => $data['completed_timestamp'] ?? null,
            'createdate'      => $data['created_timestamp']   ?? null,
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

        $this->log( 'info', "Sync complete for order {$order_id}." );
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
    }
}
