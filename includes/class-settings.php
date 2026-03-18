<?php
namespace WC_HS_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Static helper to read plugin settings.
 */
class Settings {

    public static function get_token(): string {
        return (string) get_option( 'wc_hs_sync_token', '' );
    }

    public static function get_pipeline(): string {
        return (string) get_option( 'wc_hs_sync_pipeline', 'default' );
    }

    public static function get_notification_email(): string {
        return (string) get_option( 'wc_hs_sync_notify_email', '' );
    }
    
    /**
     * Returns a map of WooCommerce status → HubSpot deal stage ID.
     * Editable via Settings UI.
     */
    public static function get_stage_map(): array {
        $default = [
            'pending'    => 'appointmentscheduled',
            'processing' => 'qualifiedtobuy',
            'on-hold'    => 'presentationscheduled',
            'completed'  => 'closedwon',
            'cancelled'  => 'closedlost',
            'refunded'   => 'closedlost',
            'failed'     => 'closedlost',
        ];
        $saved = get_option( 'wc_hs_sync_stage_map', [] );
        return wp_parse_args( $saved, $default );
    }
}
