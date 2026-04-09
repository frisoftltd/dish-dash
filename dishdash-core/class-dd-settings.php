<?php
/**
 * Dish Dash – Settings API Wrapper
 *
 * Central place to read/write all plugin settings.
 * Use DD_Settings::get() throughout the codebase instead
 * of calling get_option() directly — keeps all option keys
 * in one place and makes future changes easier.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DD_Settings {

    /**
     * Get a setting value.
     *
     * @param string $key     Setting key (without the dish_dash_ prefix)
     * @param mixed  $default Fallback value
     */
    public static function get( string $key, mixed $default = false ): mixed {
        return get_option( 'dish_dash_' . $key, $default );
    }

    /**
     * Update a setting value.
     */
    public static function set( string $key, mixed $value ): bool {
        return update_option( 'dish_dash_' . $key, $value );
    }

    /**
     * Get all settings as an associative array.
     * Useful for passing to JavaScript via wp_localize_script.
     */
    public static function get_public_settings(): array {
        return [
            'currency'          => self::get( 'currency', 'USD' ),
            'currency_symbol'   => self::get( 'currency_symbol', '$' ),
            'currency_position' => self::get( 'currency_position', 'before' ),
            'tax_rate'          => (float) self::get( 'tax_rate', 0 ),
            'tax_label'         => self::get( 'tax_label', 'Tax' ),
            'min_order'         => (float) self::get( 'min_order', 0 ),
            'enable_pickup'     => (bool) self::get( 'enable_pickup', true ),
            'enable_delivery'   => (bool) self::get( 'enable_delivery', true ),
            'enable_dinein'     => (bool) self::get( 'enable_dinein', true ),
            'menu_url'          => dd_menu_url(),
            'cart_url'          => dd_cart_url(),
            'checkout_url'      => dd_checkout_url(),
            'ajax_url'          => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'dish_dash_frontend' ),
            'rest_url'          => rest_url( 'dish-dash/v1/' ),
            'rest_nonce'        => wp_create_nonce( 'wp_rest' ),
        ];
    }
}
