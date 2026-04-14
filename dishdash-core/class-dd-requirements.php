<?php
/**
 * File:    dishdash-core/class-dd-requirements.php
 * Module:  DD_Requirements (static class)
 * Purpose: Runs before anything else loads — checks PHP version, WordPress
 *          version, and WooCommerce activation. Shows admin notices if
 *          requirements are not met and aborts plugin boot.
 *
 * Dependencies (this file needs):
 *   - DD_MIN_PHP, DD_MIN_WP constants
 *   - WordPress get_option('active_plugins') (WC detection)
 *
 * Dependents (files that need this):
 *   - dish-dash.php (calls DD_Requirements::check() before booting)
 *
 * Constants consumed:
 *   - DD_MIN_PHP (minimum PHP version, e.g. '8.0')
 *   - DD_MIN_WP  (minimum WordPress version, e.g. '6.0')
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Requirements {

    /**
     * Run all checks. Returns true if everything is OK.
     */
    public static function check(): bool {
        $errors = [];

        // PHP version
        if ( version_compare( PHP_VERSION, DD_MIN_PHP, '<' ) ) {
            $errors[] = sprintf(
                /* translators: 1: required version 2: current version */
                __( 'Dish Dash requires PHP %1$s or higher. You are running PHP %2$s.', 'dish-dash' ),
                DD_MIN_PHP,
                PHP_VERSION
            );
        }

        // WordPress version
        if ( version_compare( get_bloginfo( 'version' ), DD_MIN_WP, '<' ) ) {
            $errors[] = sprintf(
                __( 'Dish Dash requires WordPress %1$s or higher.', 'dish-dash' ),
                DD_MIN_WP
            );
        }

        // WooCommerce active?
        if ( ! self::woocommerce_active() ) {
            $errors[] = __( 'Dish Dash requires WooCommerce to be installed and activated.', 'dish-dash' );
        }

        if ( empty( $errors ) ) {
            return true;
        }

        // Show admin notices for every error found.
        add_action( 'admin_notices', function () use ( $errors ) {
            foreach ( $errors as $message ) {
                printf(
                    '<div class="notice notice-error"><p><strong>Dish Dash:</strong> %s</p></div>',
                    esc_html( $message )
                );
            }
        } );

        return false;
    }

    /**
     * Is WooCommerce active? Works even before WC loads.
     */
    private static function woocommerce_active(): bool {
        // Check the active_plugins option directly — WC may not be
        // bootstrapped yet when we run our check.
        $active = (array) get_option( 'active_plugins', [] );
        return in_array( 'woocommerce/woocommerce.php', $active, true )
            || ( is_multisite() && array_key_exists(
                'woocommerce/woocommerce.php',
                (array) get_site_option( 'active_sitewide_plugins', [] )
            ) );
    }
}
