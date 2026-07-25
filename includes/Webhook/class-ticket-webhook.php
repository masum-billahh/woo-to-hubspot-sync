<?php
namespace WC_HS_Sync\Webhook;

defined( 'ABSPATH' ) || exit;

use WC_HS_Sync\CRM\CRM_Adapter_Interface;
use WC_HS_Sync\Settings;

/**
 * Receives the HubSpot "ticket created" workflow webhook at
 * https://repan.ch/tickets/ and auto-associates the ticket with the
 * matching deal (found via wc_order_id, extracted from the ticket subject).
 *
 * Save as: includes/Webhook/class-ticket-webhook.php
 */
class Ticket_Webhook {

    private CRM_Adapter_Interface $crm;
    private \WC_Logger_Interface $logger;

    public function __construct( CRM_Adapter_Interface $crm ) {
        $this->crm    = $crm;
        $this->logger = wc_get_logger();
    }

    public function register_hooks(): void {
        add_action( 'init', [ $this, 'add_rewrite_rule' ] );
        add_filter( 'query_vars', [ $this, 'add_query_var' ] );
        add_action( 'template_redirect', [ $this, 'maybe_handle_request' ] );
    }

    public function add_rewrite_rule(): void {
        add_rewrite_rule( '^tickets/?$', 'index.php?wc_hs_ticket_webhook=1', 'top' );
    }

    public function add_query_var( array $vars ): array {
        $vars[] = 'wc_hs_ticket_webhook';
        return $vars;
    }

    public function maybe_handle_request(): void {
        if ( ! get_query_var( 'wc_hs_ticket_webhook' ) ) return;

        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
            status_header( 405 );
            exit;
        }

        if ( ! $this->verify_secret() ) {
            status_header( 401 );
            exit( 'Unauthorized' );
        }

        $payload = json_decode( file_get_contents( 'php://input' ), true );
        if ( empty( $payload ) ) {
            status_header( 400 );
            exit( 'Invalid payload' );
        }

        // Workflow webhook actions can send a single object or a batch array.
        $events = isset( $payload[0] ) ? $payload : [ $payload ];

        foreach ( $events as $event ) {
            $this->process_event( $event );
        }

        status_header( 200 );
        exit( 'OK' );
    }

    private function verify_secret(): bool {
        // HubSpot's workflow webhook action doesn't expose custom headers,
        // so the secret is passed as a query param on the webhook URL instead,
        // e.g. https://repan.ch/tickets/?key=your-secret
        $sent   = $_GET['key'] ?? '';
        $secret = Settings::get_ticket_webhook_secret();
        return $secret !== '' && hash_equals( $secret, $sent );
    }

    private function process_event( array $event ): void {
        $ticket_id = $event['objectId'] ?? $event['ticketId'] ?? null;
        if ( ! $ticket_id ) {
            $this->log( 'error', 'Webhook payload had no ticket ID.' );
            return;
        }
        $ticket_id = (string) $ticket_id;

        $subject = $this->crm->get_ticket_subject( $ticket_id );
        if ( $subject === null ) {
            $this->log( 'error', "Could not fetch ticket {$ticket_id}." );
            return;
        }

        if ( ! preg_match( '/#(\d{4,8})/', $subject, $m ) ) {
            $this->log( 'info', "Ticket {$ticket_id}: no order number in subject '{$subject}'." );
            return;
        }
        $order_id = (int) $m[1];

        if ( $this->crm->ticket_has_deal_association( $ticket_id ) ) {
            $this->log( 'info', "Ticket {$ticket_id}: already linked to a deal, skipping." );
            return;
        }

        // Reuses the existing find_deal_by_order_id() already used by Sync_Manager.
        $deal_id = $this->crm->find_deal_by_order_id( $order_id );
        if ( ! $deal_id ) {
            $this->log( 'info', "Ticket {$ticket_id}: no deal found for order #{$order_id}." );
            return;
        }

        if ( ! $this->crm->associate_ticket_deal( $ticket_id, $deal_id ) ) {
            $this->log( 'error', "Ticket {$ticket_id}: association call failed for deal {$deal_id}." );
            return;
        }

        $this->crm->add_ticket_note( $ticket_id, "Auto-linked to Deal (#{$order_id})." );
        $this->log( 'info', "Ticket {$ticket_id}: associated with deal {$deal_id} for order #{$order_id}." );
    }

    private function log( string $level, string $message ): void {
        $this->logger->$level( "[HS Sync] {$message}", [ 'source' => 'hubspot-sync' ] );
    }
}