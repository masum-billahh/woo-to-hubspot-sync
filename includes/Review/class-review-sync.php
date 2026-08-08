<?php
namespace WC_HS_Sync\Review;

defined( 'ABSPATH' ) || exit;

use WC_HS_Sync\CRM\CRM_Adapter_Interface;

/**
 * Syncs CusRev product reviews to HubSpot.
 *
 * comment_post_ID on a CusRev review IS the order ID directly (confirmed
 * against eb4xP_comments) — no product/customer matching needed.
 */
class Review_Sync {

    private CRM_Adapter_Interface $crm;
    private \WC_Logger_Interface $logger;

    public function __construct( CRM_Adapter_Interface $crm ) {
        $this->crm    = $crm;
        $this->logger = wc_get_logger();
    }

    public function register_hooks(): void {
        // Fires once a review is approved (skips spam/pending) — safer than
        // raw comment_post, which fires before moderation.
        add_action( 'wp_insert_comment', [ $this, 'maybe_handle_review' ], 20, 2 );
    }

    public function maybe_handle_review( int $comment_id, \WP_Comment $comment ): void {
        if ( $comment->comment_type !== 'review' ) return;
        if ( (int) $comment->comment_approved !== 1 ) return;

        $order_id = (int) $comment->comment_post_ID;
        $order    = wc_get_order( $order_id );
        if ( ! $order ) {
            $this->log( 'info', "Review {$comment_id}: parent post {$order_id} is not an order, skipping." );
            return;
        }

        $rating = get_comment_meta( $comment_id, 'rating', true );

        // 1. Resolve contact → company (same lookup used for orders)
        $email      = $order->get_billing_email();
        $contact_id = $email ? $this->crm->find_contact( $email ) : null;
        if ( ! $contact_id ) {
            $this->log( 'error', "Review {$comment_id}: no HubSpot contact for {$email}, cannot sync." );
            return;
        }

        $company = $this->crm->get_primary_company( $contact_id );
        if ( ! $company ) {
            $this->log( 'error', "Review {$comment_id}: contact {$contact_id} has no primary company." );
            return;
        }

        // 2. Build the single combined review property value
        $review_summary = sprintf(
            "Rating: %s/5\nReviewer: %s\nOrder: #%d\nDate: %s\n\n%s",
            $rating ?: 'N/A',
            $comment->comment_author,
            $order_id,
            $comment->comment_date,
            $comment->comment_content
        );

        $this->crm->update_company( $company['id'], [
            'latest_review' => $review_summary,
        ] );

        // 3. Resolve the responsible sales person = order creator, not
        //    necessarily the company's current HubSpot owner.
        $creator_id = $order->get_meta( '_order_creator_id', true );
        $owner_id   = null;

        if ( $creator_id ) {
            $creator = get_user_by( 'id', (int) $creator_id );
            if ( $creator && $creator->user_email ) {
                $owner_id = $this->crm->find_owner_by_email( $creator->user_email );
            }
        }

        if ( $owner_id ) {
            // Routes the HubSpot workflow's "notify owner" action to the
            // right person even if it differs from the company's normal owner.
            $this->crm->update_company( $company['id'], [
                'review_notify_owner' => $owner_id,
            ] );
            $this->log( 'info', "Review {$comment_id}: set review_notify_owner to {$owner_id} for company {$company['id']}." );
        } else {
            $this->log( 'error', "Review {$comment_id}: could not resolve HubSpot owner for order creator (order #{$order_id})." );
        }

        $this->log( 'info', "Review {$comment_id} synced to company {$company['id']} for order #{$order_id}." );
    }

    private function log( string $level, string $message ): void {
        $this->logger->$level( "[HS Sync] {$message}", [ 'source' => 'hubspot-sync' ] );
    }
}