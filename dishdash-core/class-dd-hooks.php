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
        self::replace_login_page_logo();
        self::style_admin_area();
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
                         . 'style="height:28px;width:28px;object-fit:contain;vertical-align:middle;margin-top:-2px;display:inline-block;background:#fff;border-radius:50%;padding:3px;" />',
                'href'  => admin_url(),
                'meta'  => [ 'class' => 'dd-admin-bar-logo' ],
            ] );
        }, 999 );
    }

    /**
     * Replace the WP logo on /wp-login.php with the restaurant logo.
     * Falls back gracefully if no logo is uploaded (WP default shows).
     */
    private static function replace_login_page_logo(): void {
        add_action( 'login_enqueue_scripts', function() {
            $logo_url    = get_option( 'dish_dash_logo_url', '' );
            $brand_color = get_option( 'dish_dash_primary_color', '#65040d' );
            $bg_color    = get_option( 'dish_dash_background_color', '#F5EFE6' );
            ?>
            <style>
                body.login {
                    background-color: <?php echo esc_attr( $bg_color ); ?>;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                }

                #login h1 a, .login h1 a {
                    <?php if ( ! empty( $logo_url ) ) : ?>
                    background-image: url('<?php echo esc_url( $logo_url ); ?>');
                    <?php endif; ?>
                    background-size: contain;
                    background-repeat: no-repeat;
                    background-position: center;
                    width: 180px;
                    height: 80px;
                }

                #login {
                    padding-top: 8vh;
                }

                #loginform,
                #lostpasswordform {
                    background: #fff;
                    border: none;
                    border-radius: 12px;
                    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
                    padding: 32px 36px;
                }

                .login label {
                    font-size: 13px;
                    color: #444;
                    font-weight: 500;
                }

                .login input[type="text"],
                .login input[type="password"],
                .login input[type="email"] {
                    border: 1.5px solid #ddd;
                    border-radius: 8px;
                    padding: 10px 14px;
                    font-size: 15px;
                    box-shadow: none;
                    transition: border-color 0.2s;
                }

                .login input[type="text"]:focus,
                .login input[type="password"]:focus {
                    border-color: <?php echo esc_attr( $brand_color ); ?>;
                    box-shadow: 0 0 0 2px <?php echo esc_attr( $brand_color ); ?>1a;
                    outline: none;
                }

                .login .button-primary,
                #loginform .button-primary {
                    background: <?php echo esc_attr( $brand_color ); ?>;
                    border: none;
                    border-radius: 8px;
                    padding: 10px 0;
                    font-size: 15px;
                    font-weight: 600;
                    letter-spacing: 0.3px;
                    box-shadow: none;
                    width: 100%;
                    transition: opacity 0.2s;
                }

                .login .button-primary:hover {
                    background: <?php echo esc_attr( $brand_color ); ?>;
                    opacity: 0.88;
                }

                #nav a, #backtoblog a {
                    color: <?php echo esc_attr( $brand_color ); ?>;
                    text-decoration: none;
                }

                #nav a:hover, #backtoblog a:hover {
                    text-decoration: underline;
                }

                .login #login_error,
                .login .message {
                    border-left-color: <?php echo esc_attr( $brand_color ); ?>;
                    border-radius: 6px;
                }
            </style>
            <?php
        } );

        add_filter( 'login_headerurl', function() {
            return home_url();
        } );

        add_filter( 'login_headertext', function() {
            return get_option( 'dish_dash_restaurant_name', 'Dish Dash' );
        } );
    }

    /**
     * Inject brand colors into the WP admin area — admin bar, sidebar,
     * page background, buttons. Colors pulled from wp_options so they
     * update automatically when the restaurant changes their branding.
     */
    private static function style_admin_area(): void {
        add_action( 'admin_enqueue_scripts', function() {
            $brand_color = get_option( 'dish_dash_primary_color', '#65040d' );
            $bg_color    = get_option( 'dish_dash_background_color', '#F5EFE6' );
            ?>
            <style>
                /* Admin bar */
                #wpadminbar {
                    background: <?php echo esc_attr( $brand_color ); ?> !important;
                }
                #wpadminbar .ab-item,
                #wpadminbar a.ab-item,
                #wpadminbar .ab-label,
                #wpadminbar .howdy {
                    color: #fff !important;
                }
                #wpadminbar .ab-top-menu > li:hover > .ab-item,
                #wpadminbar .ab-top-menu > li.hover > .ab-item {
                    background: rgba(0,0,0,0.15) !important;
                    color: #fff !important;
                }

                /* Sidebar */
                #adminmenuwrap,
                #adminmenuback,
                #adminmenu {
                    background: #1a1a1a !important;
                }
                #adminmenu a,
                #adminmenu .wp-menu-name {
                    color: #ccc !important;
                }
                #adminmenu .wp-has-current-submenu .wp-submenu,
                #adminmenu .wp-has-current-submenu.opensub .wp-submenu {
                    background: #111 !important;
                }
                #adminmenu .current a.menu-top,
                #adminmenu .wp-has-current-submenu a.wp-has-current-submenu,
                #adminmenu a.current {
                    background: <?php echo esc_attr( $brand_color ); ?> !important;
                    color: #fff !important;
                }
                #adminmenu li.menu-top:hover,
                #adminmenu li.opensub > a.menu-top {
                    background: rgba(255,255,255,0.07) !important;
                    color: #fff !important;
                }
                #adminmenu li.menu-top:hover > a,
                #adminmenu li.opensub > a.menu-top {
                    color: #fff !important;
                }

                /* Sidebar icons */
                #adminmenu .menu-icon-dashboard div.wp-menu-image:before,
                #adminmenu a .wp-menu-image:before {
                    color: #aaa !important;
                }
                #adminmenu .current div.wp-menu-image:before,
                #adminmenu .wp-has-current-submenu div.wp-menu-image:before {
                    color: #fff !important;
                }

                /* Page background */
                #wpcontent, #wpfooter {
                    background: <?php echo esc_attr( $bg_color ); ?> !important;
                }

                /* Footer text */
                #wpfooter {
                    border-top: 1px solid #ddd;
                }
                #wpfooter a {
                    color: <?php echo esc_attr( $brand_color ); ?>;
                }

                /* Buttons */
                .button-primary {
                    background: <?php echo esc_attr( $brand_color ); ?> !important;
                    border-color: <?php echo esc_attr( $brand_color ); ?> !important;
                    color: #fff !important;
                }
                .button-primary:hover {
                    opacity: 0.88;
                }
            </style>
            <?php
        } );
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
