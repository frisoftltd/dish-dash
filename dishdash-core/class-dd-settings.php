<?php
/**
 * File:    dishdash-core/class-dd-settings.php
 * Module:  DD_Settings (static class)
 * Purpose: Centralized wp_options wrapper — all keys auto-prefixed with
 *          dish_dash_ so modules never hardcode option names directly.
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core)
 *   - WordPress get_option / update_option
 *
 * Dependents (files that need this):
 *   - modules/orders/class-dd-cart.php        (reads tax_rate)
 *   - modules/orders/class-dd-orders-module.php (reads currency, order_prefix)
 *   - modules/template/class-dd-template-module.php (reads primary_color etc.)
 *   - dishdash-core/class-dd-helpers.php       (via dd_price(), dd_is_enabled())
 *
 * Static methods:
 *   DD_Settings::get(key, default), DD_Settings::set(key, value),
 *   DD_Settings::get_public_settings()
 *
 * Managed option keys (prefix dish_dash_ omitted):
 *   currency, currency_symbol, currency_position, tax_rate, tax_label,
 *   min_order, order_prefix, order_counter, google_maps_key,
 *   claude_api_key, enable_pickup, enable_delivery, enable_dinein,
 *   enable_reservations, enable_pos, menu_page_id, cart_page_id,
 *   checkout_page_id, track_page_id, primary_color, dark_color, restaurant_name
 *
 * Last modified: v3.1.13
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
