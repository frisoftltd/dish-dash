<?php
/**
 * Dish Dash – AJAX Router
 *
 * Central handler that routes wp_ajax_ and wp_ajax_nopriv_
 * requests to the correct module method.
 *
 * Modules register their AJAX actions here by calling
 * DD_Ajax::register() from their init() method.
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
