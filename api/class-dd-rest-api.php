<?php
/**
 * DD_Rest_API
 *
 * Registers the Dish Dash REST API namespace and loads endpoint controllers.
 * All routes live under:  /wp-json/dish-dash/v1/
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
