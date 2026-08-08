<?php
namespace WC_HS_Sync\Webhook;

defined( 'ABSPATH' ) || exit;

use WC_HS_Sync\CRM\CRM_Adapter_Interface;
use WC_HS_Sync\Notion\Notion_Client;
use WC_HS_Sync\Settings;

/**
 * Receives Notion webhook events at /notion-tickets/ and creates/updates
 * a HubSpot ticket in the Production Tickets pipeline for each Notion
 * production-ticket page. Deal/Contact/Company association happens via
 * the existing Ticket_Webhook, triggered by HubSpot's own "ticket created"
 * workflow once this ticket lands in HubSpot.
 *
 *
 */
class Notion_Webhook {

    private CRM_Adapter_Interface $crm;
    private Notion_Client $notion;
    private \WC_Logger_Interface $logger;

    /** Name of the Notion property holding the order number. */
    private const ORDER_PROPERTY = 'Bestellnummer';

    public function __construct( CRM_Adapter_Interface $crm, Notion_Client $notion ) {
        $this->crm    = $crm;
        $this->notion = $notion;
        $this->logger = wc_get_logger();
    }

    public function register_hooks(): void {
        add_action( 'init', [ $this, 'add_rewrite_rule' ] );
        add_filter( 'query_vars', [ $this, 'add_query_var' ] );
        add_action( 'template_redirect', [ $this, 'maybe_handle_request' ] );
    }

    public function add_rewrite_rule(): void {
        add_rewrite_rule( '^notion/?$', 'index.php?wc_hs_notion_webhook=1', 'top' );
    }


    public function add_query_var( array $vars ): array {
        $vars[] = 'wc_hs_notion_webhook';
        return $vars;
    }

    public function maybe_handle_request(): void {
        if ( ! get_query_var( 'wc_hs_notion_webhook' ) ) return;

        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
            status_header( 405 );
            exit;
        }

        $payload = json_decode( file_get_contents( 'php://input' ), true );
        if ( empty( $payload ) ) {
            status_header( 400 );
            exit( 'Invalid payload' );
        }

        $this->process_event( $payload );

        status_header( 200 );
        header( 'Content-Type: application/json' );
        echo wp_json_encode( [ 'success' => true ] );
        exit;
    }

    private function process_event( array $payload ): void {
        $type = $payload['type'] ?? '';

        switch ( $type ) {
            case 'page.created':
            case 'page.properties_updated':
                $page_id = $payload['entity']['id'] ?? null;
                if ( $page_id ) $this->sync_ticket_from_page( $page_id );
                break;

            case 'comment.created':
            case 'comment.updated':
                $comment_id = $payload['entity']['id'] ?? null;
                $page_id    = $payload['data']['parent']['id'] ?? null;
                if ( $comment_id && $page_id ) $this->sync_comment( $comment_id, $page_id );
                break;

            default:
                $this->log( 'info', "Ignoring unhandled Notion event type '{$type}'." );
        }
    }

    private function sync_ticket_from_page( string $page_id ): void {
        $page = $this->notion->get_page( $page_id );
        if ( ! $page || empty( $page['properties'] ) ) {
            $this->log( 'error', "Could not fetch Notion page {$page_id}." );
            return;
        }

        $properties = $page['properties'];
        $title      = Notion_Client::get_title_property( $properties ) ?: '(untitled)';
        $order_id   = trim( Notion_Client::property_to_text( $properties[ self::ORDER_PROPERTY ] ?? null ) );

        $subject = $order_id !== '' ? "#{$order_id} - {$title}" : $title;

        // Everything else (Description, Batch number, etc.) goes into the
        // ticket description as "Label: value" lines — works regardless of
        // your exact Notion schema without hardcoding each property name.
        $content_lines = [];
        foreach ( $properties as $label => $prop ) {
            if ( ( $prop['type'] ?? '' ) === 'title' || $label === self::ORDER_PROPERTY ) continue;
            $val = Notion_Client::property_to_text( $prop );
            if ( $val !== '' ) $content_lines[] = "{$label}: {$val}";
        }
        $content_lines[] = 'Notion page: ' . ( $page['url'] ?? $page_id );
        $content = implode( "\n", $content_lines );

        $ticket_properties = [
            'subject'        => $subject,
            'content'        => $content,
            'hs_pipeline'    => Settings::get_notion_ticket_pipeline(),
            'notion_page_id' => $page_id,
        ];

        $existing_ticket = $this->crm->find_ticket_by_notion_page( $page_id );

        if ( $existing_ticket ) {
            if ( $this->crm->update_ticket( $existing_ticket, $ticket_properties ) ) {
                $this->log( 'info', "Updated ticket {$existing_ticket} for Notion page {$page_id}." );
            } else {
                $this->log( 'error', "Failed updating ticket {$existing_ticket} for Notion page {$page_id}." );
            }
            return;
        }

        $ticket_properties['hs_pipeline_stage'] = Settings::get_notion_ticket_stage();

        $ticket_id = $this->crm->create_ticket( $ticket_properties );
        if ( ! $ticket_id ) {
            $this->log( 'error', "Failed creating ticket for Notion page {$page_id}." );
            return;
        }

        $this->log( 'info', "Created ticket {$ticket_id} for Notion page {$page_id}" .
            ( $order_id !== '' ? " (order #{$order_id})." : ' (no order number — created unlinked).' ) );
    }

    private function sync_comment( string $comment_id, string $page_id ): void {
        $ticket_id = $this->crm->find_ticket_by_notion_page( $page_id );
        if ( ! $ticket_id ) {
            $this->log( 'info', "Comment {$comment_id}: no HubSpot ticket yet for Notion page {$page_id}, skipping." );
            return;
        }

        $comment = $this->notion->get_comment( $comment_id );
        if ( ! $comment ) {
            $this->log( 'error', "Could not fetch Notion comment {$comment_id}." );
            return;
        }

        $author = $comment['display_name']['resolved_name'] ?? $comment['created_by']['id'] ?? 'Notion';
        $text   = implode( '', array_map( fn( $t ) => $t['plain_text'] ?? '', $comment['rich_text'] ?? [] ) );

        if ( $text === '' ) {
            $this->log( 'info', "Comment {$comment_id}: empty text, skipping." );
            return;
        }

        $note = "{$author} (via Notion): {$text}";

        if ( $this->crm->add_ticket_note( $ticket_id, $note ) ) {
            $this->log( 'info', "Comment {$comment_id}: added as note on ticket {$ticket_id}." );
        } else {
            $this->log( 'error', "Comment {$comment_id}: failed to add note on ticket {$ticket_id}." );
        }
    }

    private function log( string $level, string $message ): void {
        $this->logger->$level( "[Notion Sync] {$message}", [ 'source' => 'notion-sync' ] );
    }
}