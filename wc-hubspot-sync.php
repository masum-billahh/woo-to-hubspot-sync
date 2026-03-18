<?php
/**
 * Plugin Name: WooCommerce HubSpot Sync
 * Plugin URI:  https://www.upwork.com/freelancers/~01a6e65817b86d4589?mp_source=share
 * Description: Syncs WooCommerce orders to HubSpot CRM
 * Version:     1.0.0
 * Author:      Masum Billah
 * Text Domain: wc-hubspot-sync
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_HS_SYNC_VERSION', '1.0.0' );
define( 'WC_HS_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_HS_SYNC_URL',  plugin_dir_url( __FILE__ ) );

// Autoload classes
spl_autoload_register( function ( $class ) {
    $prefix = 'WC_HS_Sync\\';
    $base   = WC_HS_SYNC_PATH . 'includes/';
    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) return;

    // e.g. "Admin\Settings" → parts = ['Admin', 'Settings']
    $parts     = explode( '\\', substr( $class, strlen( $prefix ) ) );
    $classname = array_pop( $parts ); // last segment = actual class name

    // subfolder path (may be empty for top-level classes)
    $subdir = $parts ? implode( '/', $parts ) . '/' : '';

    // class filename: class-my-class-name.php
    $filename = 'class-' . strtolower( str_replace( '_', '-', $classname ) ) . '.php';

    $file = $base . $subdir . $filename;
    if ( file_exists( $file ) ) require $file;
} );

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="error"><p><strong>WC HubSpot Sync</strong> requires WooCommerce to be active.</p></div>';
        } );
        return;
    }
    WC_HS_Sync\Plugin::instance();
} );
