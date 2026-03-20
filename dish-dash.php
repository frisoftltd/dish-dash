<?php
/**
 * Plugin Name:       Dish Dash
 * Plugin URI:        https://frisoftltd.com/dish-dash
 * Description:       A complete restaurant ordering & management system built on WordPress and WooCommerce.
 * Version:           2.0.8
 * Author:            Fri Soft Ltd
 * Author URI:        https://frisoftltd.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dish-dash
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────
//  CONSTANTS
// ─────────────────────────────────────────────
define( 'DD_VERSION', '2.0.8' );
define( 'DD_PLUGIN_FILE',     __FILE__ );
define( 'DD_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'DD_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'DD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'DD_MODULES_DIR',     DD_PLUGIN_DIR . 'modules/' );
define( 'DD_TEMPLATES_DIR',   DD_PLUGIN_DIR . 'templates/' );
define( 'DD_ASSETS_URL',      DD_PLUGIN_URL . 'assets/' );

// ─────────────────────────────────────────────
//  GITHUB UPDATER CONFIGURATION
//  Repository: github.com/frisoftltd/dish-dash
//  Leave DD_GITHUB_TOKEN empty for public repos.
// ─────────────────────────────────────────────
define( 'DD_GITHUB_REPO',  'frisoftltd/dish-dash' );
define( 'DD_GITHUB_TOKEN', '' );

// ─────────────────────────────────────────────
//  AUTOLOADER
//  Loads dishdash-core files automatically.
// ─────────────────────────────────────────────
spl_autoload_register( function ( string $class ) {
    if ( strpos( $class, 'DD_' ) !== 0 ) {
        return;
    }
    $file = DD_PLUGIN_DIR . 'dishdash-core/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// ─────────────────────────────────────────────
//  GITHUB AUTO-UPDATER
//  Boots early so it hooks into WP update system.
// ─────────────────────────────────────────────
require_once DD_PLUGIN_DIR . 'dishdash-core/class-dd-github-updater.php';

$dd_updater = new DD_GitHub_Updater( DD_GITHUB_REPO, DD_GITHUB_TOKEN );
$dd_updater->init();

// Handle manual "Check for Updates" click from plugins page.
add_action( 'admin_init', function () use ( $dd_updater ) {
    if (
        isset( $_GET['dd_check_update'] ) &&
        isset( $_GET['_wpnonce'] ) &&
        wp_verify_nonce( $_GET['_wpnonce'], 'dd_check_update' ) &&
        current_user_can( 'update_plugins' )
    ) {
        $dd_updater->clear_cache();
        delete_site_transient( 'update_plugins' );
        wp_redirect( add_query_arg( [ 'dd_updated' => '1' ], admin_url( 'plugins.php' ) ) );
        exit;
    }
} );

// Show notice after manual update check.
add_action( 'admin_notices', function () {
    if ( isset( $_GET['dd_updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>'
            . esc_html__( 'Dish Dash: Update check complete. If a new version is available it will appear below.', 'dish-dash' )
            . '</p></div>';
    }
} );

// ─────────────────────────────────────────────
//  BOOT ON plugins_loaded
// ─────────────────────────────────────────────
add_action( 'plugins_loaded', function () {

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . sprintf(
                    esc_html__( 'Dish Dash requires %s to be installed and active.', 'dish-dash' ),
                    '<a href="https://woocommerce.com" target="_blank">WooCommerce</a>'
                )
                . '</p></div>';
        } );
        return;
    }

    DD_Loader::instance()->boot();

}, 10 );

// ─────────────────────────────────────────────
//  ACTIVATION / DEACTIVATION
// ─────────────────────────────────────────────
register_activation_hook( __FILE__, function () {
    require_once DD_PLUGIN_DIR . 'install.php';
    DD_Install::run();
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );
