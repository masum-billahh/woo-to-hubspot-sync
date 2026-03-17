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

        // Order event hooks → Sync_Manager
        $sync = new Sync_Manager(
            new CRM\HubSpot_Adapter( Settings::get_token() ),
            new Order\Order_Extractor()
        );
        $sync->register_hooks();
    }
}
