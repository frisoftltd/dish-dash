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

        // Clean up WP admin noise for restaurant owner.
        self::suppress_update_badges();
        self::suppress_admin_notices();
        self::replace_admin_bar_logo();
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

    /**
     * Remove update count badges from all admin menu items
     * except the Updates page itself (update-core.php).
     */
    private static function suppress_update_badges(): void {
        add_action( 'admin_menu', function() {
            global $menu, $submenu;

            $screen = isset( $_GET['page'] ) ? $_GET['page'] : basename( $_SERVER['PHP_SELF'] ?? '' );
            if ( $screen === 'update-core.php' ) {
                return;
            }

            // Strip update count bubbles from all top-level menu items
            if ( is_array( $menu ) ) {
                foreach ( $menu as $key => $item ) {
                    $menu[$key][0] = preg_replace( '/ <span[^>]*>.*?<\/span>/i', '', $item[0] ?? '' );
                }
            }

            // Strip from submenus too
            if ( is_array( $submenu ) ) {
                foreach ( $submenu as $parent => $items ) {
                    foreach ( $items as $k => $item ) {
                        $submenu[$parent][$k][0] = preg_replace( '/ <span[^>]*>.*?<\/span>/i', '', $item[0] ?? '' );
                    }
                }
            }
        }, 999 );
    }

    /**
     * Remove the WP logo from the admin bar and replace it with the
     * restaurant logo stored in dish_dash_logo_url. If no logo is set,
     * the WP logo node is still removed (nothing shown in its place).
     */
    private static function replace_admin_bar_logo(): void {
        add_action( 'admin_bar_menu', function( \WP_Admin_Bar $wp_admin_bar ) {
            $wp_admin_bar->remove_node( 'wp-logo' );

            $logo_url = get_option( 'dish_dash_logo_url', '' );
            if ( empty( $logo_url ) ) {
                return;
            }

            $wp_admin_bar->add_node( [
                'id'    => 'dd-restaurant-logo',
                'title' => '<img src="' . esc_url( $logo_url ) . '" '
                         . 'alt="' . esc_attr( get_option( 'dish_dash_restaurant_name', 'Dish Dash' ) ) . '" '
                         . 'style="height:24px;width:auto;vertical-align:middle;margin-top:-2px;display:inline-block;" />',
                'href'  => admin_url(),
                'meta'  => [ 'class' => 'dd-admin-bar-logo' ],
            ] );
        }, 999 );
    }

    /**
     * Remove all admin notice banners on every screen
     * except the Updates page (update-core).
     */
    private static function suppress_admin_notices(): void {
        add_action( 'current_screen', function( $screen ) {
            if ( $screen->id === 'update-core' ) {
                return;
            }
            remove_all_actions( 'admin_notices' );
            remove_all_actions( 'all_admin_notices' );
            remove_all_actions( 'update_nag' );
            remove_all_actions( 'network_admin_notices' );
        }, 999 );
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
