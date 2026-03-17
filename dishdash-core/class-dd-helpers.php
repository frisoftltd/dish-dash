<?php
/**
 * Dish Dash – Global Helper Functions
 *
 * Procedural helpers available everywhere in the plugin.
 * Prefix all functions with dd_ to avoid conflicts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Format a price value using plugin currency settings.
 */
function dd_price( float $amount ): string {
    $symbol   = get_option( 'dish_dash_currency_symbol', '$' );
    $position = get_option( 'dish_dash_currency_position', 'before' );
    $formatted = number_format( $amount, 2 );

    return 'before' === $position
        ? $symbol . $formatted
        : $formatted . $symbol;
}

/**
 * Generate a unique Dish Dash order number.
 * Format: DD-00001, DD-00002, …
 */
function dd_generate_order_number(): string {
    $prefix  = get_option( 'dish_dash_order_prefix', 'DD-' );
    $counter = (int) get_option( 'dish_dash_order_counter', 0 );
    $counter++;
    update_option( 'dish_dash_order_counter', $counter );
    return $prefix . str_pad( $counter, 5, '0', STR_PAD_LEFT );
}

/**
 * Get all active branches.
 *
 * @return array<int, object>
 */
function dd_get_branches(): array {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}dishdash_branches WHERE is_active = 1 ORDER BY name ASC"
    );
}

/**
 * Get a single branch by ID.
 */
function dd_get_branch( int $id ): ?object {
    global $wpdb;
    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dishdash_branches WHERE id = %d",
            $id
        )
    );
}

/**
 * Get the currently selected branch ID from session/cookie.
 * Defaults to branch 1 (main branch).
 */
function dd_get_current_branch_id(): int {
    if ( isset( $_COOKIE['dd_branch_id'] ) ) {
        return (int) $_COOKIE['dd_branch_id'];
    }
    return 1;
}

/**
 * Check if a feature module is enabled in Settings.
 */
function dd_is_enabled( string $feature ): bool {
    return '1' === get_option( "dish_dash_enable_{$feature}", '1' );
}

/**
 * Sanitize and validate an order type string.
 */
function dd_valid_order_type( string $type ): string {
    $valid = [ 'delivery', 'pickup', 'dine-in', 'pos' ];
    return in_array( $type, $valid, true ) ? $type : 'delivery';
}

/**
 * Get allowed order status transitions.
 * Returns the next valid statuses from a given status.
 */
function dd_order_status_transitions(): array {
    return [
        'pending'           => [ 'confirmed', 'cancelled' ],
        'confirmed'         => [ 'preparing', 'cancelled' ],
        'preparing'         => [ 'ready' ],
        'ready'             => [ 'out_for_delivery', 'delivered' ],
        'out_for_delivery'  => [ 'delivered' ],
        'delivered'         => [ 'refunded' ],
        'cancelled'         => [ 'refunded' ],
        'refunded'          => [],
    ];
}

/**
 * Get human-readable label for an order status.
 */
function dd_order_status_label( string $status ): string {
    $labels = [
        'pending'           => __( 'Pending',           'dish-dash' ),
        'confirmed'         => __( 'Confirmed',         'dish-dash' ),
        'preparing'         => __( 'Preparing',         'dish-dash' ),
        'ready'             => __( 'Ready',             'dish-dash' ),
        'out_for_delivery'  => __( 'Out for Delivery',  'dish-dash' ),
        'delivered'         => __( 'Delivered',         'dish-dash' ),
        'cancelled'         => __( 'Cancelled',         'dish-dash' ),
        'refunded'          => __( 'Refunded',          'dish-dash' ),
    ];
    return $labels[ $status ] ?? ucfirst( $status );
}

/**
 * Log a debug message to the WP error log.
 * Only logs when WP_DEBUG is true.
 */
function dd_log( mixed $data, string $label = 'Dish Dash' ): void {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $message = is_array( $data ) || is_object( $data )
            ? wp_json_encode( $data )
            : (string) $data;
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        error_log( "[{$label}] {$message}" );
    }
}

/**
 * Get the Dish Dash menu page URL.
 */
function dd_menu_url(): string {
    $page_id = get_option( 'dish_dash_menu_page_id' );
    return $page_id ? get_permalink( $page_id ) : home_url( '/restaurant-menu/' );
}

/**
 * Get the Dish Dash cart page URL.
 */
function dd_cart_url(): string {
    $page_id = get_option( 'dish_dash_cart_page_id' );
    return $page_id ? get_permalink( $page_id ) : home_url( '/cart-dd/' );
}

/**
 * Get the Dish Dash checkout page URL.
 */
function dd_checkout_url(): string {
    $page_id = get_option( 'dish_dash_checkout_page_id' );
    return $page_id ? get_permalink( $page_id ) : home_url( '/checkout-dd/' );
}

/**
 * Get the order tracking page URL, optionally with an order number.
 */
function dd_track_url( string $order_number = '' ): string {
    $page_id = get_option( 'dish_dash_track_page_id' );
    $base    = $page_id ? get_permalink( $page_id ) : home_url( '/track-order/' );
    return $order_number
        ? add_query_arg( 'order', urlencode( $order_number ), $base )
        : $base;
}
