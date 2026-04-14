<?php
/**
 * File:    api/class-dd-rest-api.php
 * Module:  DD_Rest_API (singleton)
 * Purpose: Registers the dish-dash/v1 REST API namespace and provides
 *          standardised success/error response helpers. Fires
 *          dish_dash_register_rest_routes so modules can add their own routes.
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core guard)
 *   - WordPress rest_api_init hook, WP_REST_Response, WP_Error
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dishdash.php (instantiates DD_Rest_API)
 *   - modules/orders/class-dd-orders-module.php (registers routes via the hook)
 *
 * Hooks registered:
 *   - rest_api_init → registers namespace + fires dish_dash_register_rest_routes
 *
 * Hooks fired:
 *   - dish_dash_register_rest_routes(namespace) — modules add their routes here
 *
 * REST namespace: dish-dash/v1
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Rest_API {

    const NAMESPACE = 'dish-dash/v1';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        // Endpoint controllers are registered here as we build each module.
        // Example (uncomment when ready):
        // ( new DD_REST_Menu_Controller() )->register_routes();
        // ( new DD_REST_Orders_Controller() )->register_routes();

        do_action( 'dish_dash_register_rest_routes', self::NAMESPACE );
    }

    /**
     * Standard JSON success response.
     */
    public static function success( mixed $data, string $message = '', int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status );
    }

    /**
     * Standard JSON error response.
     */
    public static function error( string $code, string $message, int $status = 400 ): WP_Error {
        return new WP_Error( $code, $message, [ 'status' => $status ] );
    }
}
