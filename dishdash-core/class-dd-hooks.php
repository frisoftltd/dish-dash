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

        // Redirect staff roles and administrators to DD dashboard after login.
        add_filter( 'login_redirect',                   [ __CLASS__, 'staff_login_redirect' ], 9999, 3 );
        add_filter( 'woocommerce_login_redirect',       [ __CLASS__, 'staff_wc_login_redirect' ], 9999, 2 );
        add_filter( 'woocommerce_prevent_admin_access', [ __CLASS__, 'staff_allow_admin_access' ], 9999 );

        // Add a "Visit Menu" link on the plugins page.
        add_filter( 'plugin_action_links_' . DD_PLUGIN_BASENAME, [ __CLASS__, 'plugin_action_links' ] );

        // Auto-expand the Dish Dash submenu for staff roles.
        add_action( 'admin_head', [ __CLASS__, 'staff_expand_dishdash_menu' ] );

        // Clean up WP admin noise for restaurant owner.
        self::suppress_update_badges();
        self::suppress_admin_notices();
        self::replace_admin_bar_logo();
        self::replace_login_page_logo();
        self::style_admin_area();
        self::hide_irrelevant_menu_items();
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
     * Native wp-login.php redirect for Dish Dash staff roles.
     * Sends Owner/Manager to the Dish Dash dashboard instead of profile.php.
     *
     * @param string           $redirect_to           Default redirect.
     * @param string           $requested_redirect_to Requested redirect.
     * @param WP_User|WP_Error $user                  Logged-in user or error.
     */
    public static function staff_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
        if ( $user instanceof WP_User ) {
            $staff = [ 'dd_restaurant_owner', 'dd_restaurant_manager' ];
            if ( array_intersect( $staff, (array) $user->roles ) ) {
                return admin_url( 'admin.php?page=dish-dash' );
            }
        }
        return $redirect_to;
    }

    /**
     * Override WooCommerce login redirect for Dish Dash staff roles.
     * Hooks at priority 9999 — after WooCommerce sets its redirect value.
     * Pattern: businessbloomer.com WooCommerce role redirect standard.
     *
     * @param string  $redirect The URL WooCommerce is about to redirect to.
     * @param WP_User $user     The authenticated user.
     */
    public static function staff_wc_login_redirect( string $redirect, WP_User $user ): string {
        $staff_roles = [ 'dd_restaurant_owner', 'dd_restaurant_manager', 'administrator' ];
        if ( ! empty( array_intersect( $staff_roles, (array) $user->roles ) ) ) {
            return admin_url( 'admin.php?page=dish-dash' );
        }
        return $redirect;
    }

    /**
     * Allow Dish Dash staff roles into wp-admin.
     * WooCommerce's prevent_admin_access() only checks edit_posts,
     * manage_woocommerce, and view_admin_dashboard — NOT manage_options.
     * This filter lets our staff roles through.
     */
    public static function staff_allow_admin_access( bool $prevent ): bool {
        if ( current_user_can( 'dd_manage_orders' ) || current_user_can( 'manage_options' ) ) {
            return false;
        }
        return $prevent;
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
            // Remove WP clutter from admin bar
            $wp_admin_bar->remove_node( 'wp-logo' );
            $wp_admin_bar->remove_node( 'site-name' );
            $wp_admin_bar->remove_node( 'comments' );
            $wp_admin_bar->remove_node( 'new-content' );
            $wp_admin_bar->remove_node( 'customize' );
            $wp_admin_bar->remove_node( 'updates' );
            $wp_admin_bar->remove_node( 'search' );
            $wp_admin_bar->remove_node( 'wp-logo-external' );

            // Add restaurant logo if set
            $logo_url = get_option( 'dish_dash_logo_url', '' );
            if ( ! empty( $logo_url ) ) {
                $wp_admin_bar->add_node( [
                    'id'    => 'dd-restaurant-logo',
                    'title' => '<img src="' . esc_url( $logo_url ) . '" '
                             . 'alt="' . esc_attr( get_option( 'dish_dash_restaurant_name', 'Dish Dash' ) ) . '" '
                             . 'style="height:28px;width:28px;object-fit:contain;vertical-align:middle;margin-top:-2px;display:inline-block;background:#fff;border-radius:50%;padding:3px;" />',
                    'href'  => admin_url(),
                    'meta'  => [ 'class' => 'dd-admin-bar-logo' ],
                ] );
            }

            // Add notification bell on the right side
            $wp_admin_bar->add_node( [
                'id'    => 'dd-notifications',
                'title' => '<span class="dd-bell-wrap">'
                         . '<span class="dd-bell-icon">🔔</span>'
                         . '<span class="dd-bell-badge" id="dd-bell-badge" style="display:none">0</span>'
                         . '</span>',
                'href'  => '#',
                'meta'  => [ 'class' => 'dd-notif-node' ],
            ] );
        }, 999 );
        add_action( 'admin_footer', [ __CLASS__, 'render_bell_panel' ] );
    }

    public static function render_bell_panel(): void {
        ?>
        <div class="dd-bell-panel" id="dd-bell-panel" style="display:none">
            <div class="dd-bell-header">
                <span>Pending Items</span>
            </div>
            <div class="dd-bell-items" id="dd-bell-items">
                <p class="dd-bell-empty">No pending items</p>
            </div>
        </div>
        <?php
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
     * For Restaurant Owner/Manager, force the Dish Dash submenu to stay
     * expanded in the admin sidebar so they see all options immediately
     * without clicking the top-level "Dish Dash" item first.
     */
    public static function staff_expand_dishdash_menu(): void {
        $user = wp_get_current_user();
        if ( ! array_intersect( [ 'dd_restaurant_owner', 'dd_restaurant_manager' ], $user->roles ) ) {
            return;
        }
        ?>
        <style>
            /* Keep the Dish Dash submenu open and visible at all times */
            #adminmenu li.toplevel_page_dish-dash ul.wp-submenu {
                display: block !important;
            }
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var dd = document.querySelector('#adminmenu li.toplevel_page_dish-dash');
            if (dd) {
                dd.classList.add('wp-has-current-submenu', 'wp-menu-open');
                dd.classList.remove('wp-not-current-submenu');
                var sub = dd.querySelector('ul.wp-submenu');
                if (sub) { sub.style.display = 'block'; }
            }
        });
        </script>
        <?php
    }

    /**
     * Remove Posts and Comments from the admin sidebar — irrelevant
     * to a restaurant ordering system and confusing for owners.
     */
    private static function hide_irrelevant_menu_items(): void {
        add_action( 'admin_menu', function() {
            remove_menu_page( 'edit.php' );          // Posts
            remove_menu_page( 'edit-comments.php' ); // Comments

            // Phase 7: Lock Restaurant Owner / Manager into Dish Dash only.
            $user = wp_get_current_user();
            if ( array_intersect( [ 'dd_restaurant_owner', 'dd_restaurant_manager' ], $user->roles ) ) {
                $hide = [
                    'upload.php',              // Media
                    'edit.php?post_type=page', // Pages
                    'themes.php',              // Appearance
                    'plugins.php',             // Plugins
                    'users.php',               // Users
                    'tools.php',               // Tools
                    'options-general.php',     // Settings
                    'profile.php',             // Profile (still URL-accessible)
                ];
                foreach ( $hide as $page ) {
                    remove_menu_page( $page );
                }

                // Hide WooCommerce top-level menus surfaced by manage_woocommerce.
                remove_menu_page( 'woocommerce' );
                remove_menu_page( 'edit.php?post_type=product' );
                remove_menu_page( 'wc-admin&path=/analytics/overview' );
                remove_menu_page( 'woocommerce-marketing' );
                remove_menu_page( 'wc-reports' );
                remove_menu_page( 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM' );
            }
        }, 999 );

        // CSS-only hiding for Dish Dash submenu items that should never appear
        // (Menu Items, Delivery, Branches, POS Terminal).
        add_action( 'admin_head', function() {
            ?>
            <style>
                #adminmenu .wp-submenu a[href*="page=dish-dash-menu"],
                #adminmenu .wp-submenu a[href*="page=dish-dash-delivery"],
                #adminmenu .wp-submenu a[href*="page=dish-dash-branches"],
                #adminmenu .wp-submenu a[href*="page=dish-dash-pos"] {
                    display: none !important;
                }
            </style>
            <?php
        } );

        // CSS-only hiding for Dish Dash submenu items restricted to Fri Soft admins only
        // (Settings, Tools, Template, Activity Log hidden for Owner / Manager).
        add_action( 'admin_head', function() {
            $user = wp_get_current_user();
            if ( ! array_intersect( [ 'dd_restaurant_owner', 'dd_restaurant_manager' ], $user->roles ) ) {
                return;
            }
            ?>
            <style>
                #adminmenu .wp-submenu a[href*="page=dish-dash-settings"],
                #adminmenu .wp-submenu a[href*="page=dish-dash-tools"],
                #adminmenu .wp-submenu a[href*="page=dish-dash-template"],
                #adminmenu .wp-submenu a[href*="page=dish-dash-activity-log"] {
                    display: none !important;
                }
            </style>
            <?php
        } );
    }

    /**
     * Inject brand colors into the WP admin area — admin bar, sidebar,
     * page background, buttons. Colors pulled from wp_options so they
     * update automatically when the restaurant changes their branding.
     */
    public static function style_admin_area(): void {
        add_action( 'admin_enqueue_scripts', function() {
            $primary = sanitize_hex_color( get_option( 'dish_dash_primary_color', '#65040d' ) );
            ?>
            <style>
                /* Admin bar — clean DishDash style */
                #wpadminbar {
                    background: <?php echo $primary; ?> !important;
                }
                #wpadminbar .ab-item,
                #wpadminbar a.ab-item,
                #wpadminbar .ab-label {
                    color: rgba(255,255,255,0.9) !important;
                }
                #wpadminbar .ab-item:hover,
                #wpadminbar a.ab-item:hover {
                    background: rgba(0,0,0,0.15) !important;
                    color: #fff !important;
                }
                /* Hide remaining WP icons we don't want */
                #wp-admin-bar-my-account .avatar { border-radius: 50%; }
                #wpadminbar #wp-admin-bar-dd-restaurant-logo img {
                    height: 28px;
                    width: auto;
                    margin-top: 6px;
                }
                /* Bell notification node */
                #wpadminbar #wp-admin-bar-dd-notifications {
                    position: relative;
                }
                #wpadminbar #wp-admin-bar-dd-notifications > .ab-item {
                    padding: 0 12px !important;
                }
                .dd-bell-wrap {
                    position: relative;
                    display: inline-flex;
                    align-items: center;
                }
                .dd-bell-icon { font-size: 16px; line-height: 32px; }
                .dd-bell-badge {
                    position: absolute;
                    top: 4px;
                    right: -6px;
                    background: #dc2626;
                    color: #fff;
                    font-size: 10px;
                    font-weight: 700;
                    min-width: 16px;
                    height: 16px;
                    line-height: 16px;
                    text-align: center;
                    border-radius: 8px;
                    padding: 0 3px;
                    box-sizing: border-box;
                }
                /* Bell dropdown panel */
                .dd-bell-panel {
                    position: fixed;
                    top: 46px;
                    right: 16px;
                    width: 360px;
                    background: #fff;
                    border-radius: 16px;
                    box-shadow: 0 12px 40px rgba(0,0,0,0.18), 0 2px 8px rgba(0,0,0,0.08);
                    z-index: 999999;
                    overflow: hidden;
                    font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif;
                    border: 1px solid rgba(0,0,0,0.06);
                }
                .dd-bell-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 16px 18px;
                    border-bottom: 1px solid #f3f4f6;
                }
                .dd-bell-header span {
                    font-size: 14px;
                    font-weight: 600;
                    color: #111;
                }
                .dd-bell-header button {
                    background: none;
                    border: none;
                    font-size: 12px;
                    color: #9ca3af;
                    cursor: pointer;
                    padding: 4px 10px;
                    border-radius: 6px;
                    font-family: inherit;
                    transition: all .15s;
                }
                .dd-bell-header button:hover { background: #f3f4f6; color: #374151; }
                .dd-bell-items { max-height: 400px; overflow-y: auto; }
                .dd-bell-item {
                    display: flex;
                    align-items: flex-start;
                    gap: 12px;
                    padding: 14px 18px;
                    border-bottom: 1px solid #f9fafb;
                    cursor: pointer;
                    transition: background .12s;
                    text-decoration: none !important;
                }
                .dd-bell-item:last-child { border-bottom: none; }
                .dd-bell-item:hover { background: #f9fafb; }
                .dd-bell-item.dd-unread { background: #fffbeb; }
                .dd-bell-item.dd-unread:hover { background: #fef9c3; }
                .dd-bell-item-icon {
                    width: 38px;
                    height: 38px;
                    border-radius: 10px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 17px;
                    flex-shrink: 0;
                }
                .dd-bell-item-icon.dd-icon-order       { background: #dbeafe; }
                .dd-bell-item-icon.dd-icon-reservation { background: #dcfce7; }
                .dd-bell-item-body { flex: 1; min-width: 0; }
                .dd-bell-item-title {
                    display: block;
                    font-size: 13px;
                    font-weight: 600;
                    color: #111;
                    margin-bottom: 3px;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                .dd-bell-item-meta {
                    display: block;
                    font-size: 12px;
                    color: #6b7280;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                .dd-bell-item-time {
                    font-size: 11px;
                    color: #d1d5db;
                    white-space: nowrap;
                    flex-shrink: 0;
                    padding-top: 2px;
                }
                .dd-bell-empty {
                    padding: 36px 18px;
                    text-align: center;
                    color: #9ca3af;
                    font-size: 13px;
                    margin: 0;
                }
                .dd-bell-empty::before {
                    content: '🔔';
                    display: block;
                    font-size: 28px;
                    margin-bottom: 10px;
                    opacity: 0.35;
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
