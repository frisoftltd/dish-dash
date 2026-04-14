<?php
/**
 * File:    dishdash-core/class-dd-hooks.php
 * Module:  DD_Hooks
 * Purpose: Registers global WordPress hooks that don't belong to any
 *          specific feature module (rewrite flush, plugin action links).
 *          Also serves as the canonical documentation of all custom
 *          Dish Dash actions and filters.
 *
 * Dependencies (this file needs):
 *   - DD_PLUGIN_BASENAME constant
 *   - ABSPATH (WordPress core)
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php (instantiates DD_Hooks during boot)
 *
 * Hooks registered:
 *   - init → maybe_flush_rewrite_rules()
 *   - plugin_action_links_{basename} → adds Dashboard + Settings links
 *
 * Custom hooks documented here (fired elsewhere):
 *   Actions: dish_dash_loaded, dish_dash_order_placed,
 *            dish_dash_order_status_changed, dish_dash_order_delivered,
 *            dish_dash_reservation_created, dish_dash_before_menu_render
 *   Filters: dish_dash_menu_query_args, dish_dash_order_data,
 *            dish_dash_delivery_fee, dish_dash_price, dish_dash_email_template
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DD_Hooks {

    public static function init(): void {
        // Flush rewrite rules when CPTs are registered.
        add_action( 'init', [ __CLASS__, 'maybe_flush_rewrite_rules' ] );

        // Add a "Visit Menu" link on the plugins page.
        add_filter( 'plugin_action_links_' . DD_PLUGIN_BASENAME, [ __CLASS__, 'plugin_action_links' ] );
    }

    /**
     * Flush rewrite rules once after activation.
     * We set a transient in install.php and clear it here.
     */
    public static function maybe_flush_rewrite_rules(): void {
        if ( get_transient( 'dish_dash_flush_rewrite' ) ) {
            flush_rewrite_rules();
            delete_transient( 'dish_dash_flush_rewrite' );
        }
    }

    /**
     * Add Settings and Menu links on the WordPress plugins list.
     */
    public static function plugin_action_links( array $links ): array {
        $links[] = '<a href="' . admin_url( 'admin.php?page=dish-dash' ) . '">'
            . esc_html__( 'Dashboard', 'dish-dash' ) . '</a>';
        $links[] = '<a href="' . admin_url( 'admin.php?page=dish-dash-settings' ) . '">'
            . esc_html__( 'Settings', 'dish-dash' ) . '</a>';
        return $links;
    }

    /*
    ─────────────────────────────────────────────────────────────
     CUSTOM ACTIONS — reference list (not registered here,
     just documented so developers know what hooks exist)
    ─────────────────────────────────────────────────────────────

    do_action( 'dish_dash_loaded' )
        Fires after all modules are booted.

    do_action( 'dish_dash_order_placed', int $order_id, array $order_data )
        Fires when a new order is successfully saved to the DB.

    do_action( 'dish_dash_order_status_changed', int $order_id, string $old_status, string $new_status )
        Fires every time an order status is updated.

    do_action( 'dish_dash_order_delivered', int $order_id )
        Fires when an order reaches 'delivered' status.

    do_action( 'dish_dash_reservation_created', int $reservation_id )
        Fires when a new table reservation is saved.

    do_action( 'dish_dash_before_menu_render', array $args )
        Fires just before the menu shortcode outputs HTML.

    ─────────────────────────────────────────────────────────────
     CUSTOM FILTERS — reference list
    ─────────────────────────────────────────────────────────────

    apply_filters( 'dish_dash_menu_query_args', array $args )
        Filter the WP_Query args used to fetch menu items.

    apply_filters( 'dish_dash_order_data', array $data, int $order_id )
        Filter order data before it is saved.

    apply_filters( 'dish_dash_delivery_fee', float $fee, int $zone_id, float $subtotal )
        Filter the calculated delivery fee.

    apply_filters( 'dish_dash_price', string $formatted, float $raw )
        Filter the formatted price string.

    apply_filters( 'dish_dash_email_template', string $html, string $type, array $data )
        Filter email HTML before sending.
    */
}
