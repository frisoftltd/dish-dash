<?php
/**
 * File:    dishdash-core/class-dd-ajax.php
 * Module:  DD_Ajax (static utility class)
 * Purpose: Centralized AJAX registration and nonce verification — modules
 *          call DD_Ajax::register() instead of add_action('wp_ajax_*') directly.
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core)
 *   - wp_ajax_* hooks (WordPress core)
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php  (loads DD_Cart::register_ajax via this)
 *   - modules/orders/class-dd-cart.php   (calls DD_Ajax::register for cart actions)
 *   - modules/orders/class-dd-orders-module.php
 *   - modules/homepage/class-dd-homepage-module.php
 *   - modules/tracking/class-dd-tracking-module.php
 *
 * Static methods:
 *   - register(action, callback, nopriv=true)
 *   - verify_nonce(field='nonce', action='dish_dash_frontend')
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DD_Ajax {

    /** @var array<string, callable> */
    private static array $handlers = [];

    /**
     * Register an AJAX action.
     *
     * @param string   $action     The action name (without wp_ajax_ prefix)
     * @param callable $callback   The handler
     * @param bool     $nopriv     Allow non-logged-in users (default true)
     */
    public static function register( string $action, callable $callback, bool $nopriv = true ): void {
        self::$handlers[ $action ] = $callback;

        add_action( 'wp_ajax_' . $action, $callback );

        if ( $nopriv ) {
            add_action( 'wp_ajax_nopriv_' . $action, $callback );
        }
    }

    /**
     * Verify a nonce from $_POST or $_GET and die on failure.
     *
     * @param string $field  The nonce field name (default: 'nonce')
     * @param string $action The nonce action (default: 'dish_dash_frontend')
     */
    public static function verify_nonce( string $field = 'nonce', string $action = 'dish_dash_frontend' ): void {
        $nonce = sanitize_text_field( $_REQUEST[ $field ] ?? '' );

        if ( ! wp_verify_nonce( $nonce, $action ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed. Please refresh the page.', 'dish-dash' ) ], 403 );
        }
    }
}
