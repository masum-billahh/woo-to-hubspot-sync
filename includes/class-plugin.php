<?php
namespace WC_HS_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap — wires up all components.
 */
class Plugin {

    private static ?Plugin $instance = null;

    public static function instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Settings page
        new Admin\Settings();
        
         // Historical importer (Import tab + AJAX handlers)
        new Admin\Historical_Importer();

        // Order event hooks → Sync_Manager
        $crm = new CRM\HubSpot_Adapter( Settings::get_token() );

        $sync = new Sync_Manager( $crm, new Order\Order_Extractor() );
        $sync->register_hooks();

        $ticket_webhook = new Webhook\Ticket_Webhook( $crm );
        $ticket_webhook->register_hooks();
    }
}
