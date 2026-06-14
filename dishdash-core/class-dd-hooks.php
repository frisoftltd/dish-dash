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

        // Block /wp-admin and /wp-login.php for non-admins when a custom path is set.
        add_action( 'init', [ __CLASS__, 'maybe_block_wp_admin' ], 1 );

        // Register WP rewrite rule for the custom admin path (LiteSpeed-compatible).
        add_action( 'init', [ __CLASS__, 'register_admin_rewrite' ], 5 );

        // Handle the redirect when the custom path is hit.
        add_action( 'template_redirect', [ __CLASS__, 'handle_admin_redirect' ] );

        // Add a "Visit Menu" link on the plugins page.
        add_filter( 'plugin_action_links_' . DD_PLUGIN_BASENAME, [ __CLASS__, 'plugin_action_links' ] );

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
                <span>Notifications</span>
                <button type="button" id="dd-bell-mark-read">Mark all read</button>
            </div>
            <div class="dd-bell-items" id="dd-bell-items">
                <p class="dd-bell-empty">No new notifications</p>
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
     * Remove Posts and Comments from the admin sidebar — irrelevant
     * to a restaurant ordering system and confusing for owners.
     */
    private static function hide_irrelevant_menu_items(): void {
        add_action( 'admin_menu', function() {
            remove_menu_page( 'edit.php' );          // Posts
            remove_menu_page( 'edit-comments.php' ); // Comments
            remove_submenu_page( 'dish-dash', 'dish-dash-menu' );     // Menu Items
            remove_submenu_page( 'dish-dash', 'dish-dash-delivery' ); // Delivery
            remove_submenu_page( 'dish-dash', 'dish-dash-branches' ); // Branches
            remove_submenu_page( 'dish-dash', 'dish-dash-pos' );      // POS Terminal

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
                ];
                foreach ( $hide as $page ) {
                    remove_menu_page( $page );
                }

                // Hide WooCommerce top-level menus if present.
                remove_menu_page( 'woocommerce' );
                remove_menu_page( 'edit.php?post_type=product' );
                remove_menu_page( 'wc-admin&path=/analytics/overview' );
            }
        }, 999 );
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

    /**
     * Register a WP rewrite rule for the custom admin path.
     * Works on LiteSpeed (and any server) without touching .htaccess directly.
     * Flush rewrite rules after saving a new path for the rule to take effect.
     */
    public static function register_admin_rewrite(): void {
        $custom_path = get_option( 'dd_admin_custom_path', '' );

        if ( empty( $custom_path ) ) {
            return;
        }

        add_rewrite_tag( '%dd_admin_redirect%', '([0-9]+)' );

        add_rewrite_rule(
            '^' . preg_quote( $custom_path, '/' ) . '/?$',
            'index.php?dd_admin_redirect=1',
            'top'
        );
    }

    /**
     * Redirect to the login page with ?dd_entry=1 when the custom path rewrite
     * rule is matched. dd_entry signals maybe_block_wp_admin() to allow through.
     * Uses wp_login_url() + admin_url() — no hardcoded URLs.
     * Checks multiple sources in case the rewrite tag is not yet in query vars.
     */
    public static function handle_admin_redirect(): void {
        global $wp_query;

        $is_redirect = get_query_var( 'dd_admin_redirect' )
            || ( isset( $wp_query->query_vars['dd_admin_redirect'] )
                 && $wp_query->query_vars['dd_admin_redirect'] )
            || isset( $_GET['dd_admin_redirect'] );

        if ( $is_redirect ) {
            $login_url = add_query_arg( 'dd_entry', '1', wp_login_url( admin_url() ) );
            wp_redirect( $login_url );
            exit;
        }
    }

    /**
     * If a custom admin path is set, block ALL direct requests to /wp-admin
     * and /wp-login.php with a 404 — unless the request carries ?dd_entry=1
     * (set by handle_admin_redirect() via the custom path), is a login form
     * POST submission, a logout action, or the user is already logged in as
     * an admin. After logout, redirects to the custom path instead of 404.
     * Fires on 'init' priority 1 — WP auth cookies are already loaded.
     */
    public static function maybe_block_wp_admin(): void {
        $custom_path = get_option( 'dd_admin_custom_path', '' );

        if ( empty( $custom_path ) ) {
            return;
        }

        $request_uri = isset( $_SERVER['REQUEST_URI'] )
            ? trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' )
            : '';

        $is_wp_admin = strpos( $request_uri, 'wp-admin' ) === 0;

        // Never block admin-ajax.php — frontend AJAX depends on it
        if ( str_ends_with( $request_uri, 'admin-ajax.php' ) ) {
            return;
        }

        $is_wp_login = strpos( $request_uri, 'wp-login.php' ) === 0;

        if ( ! $is_wp_admin && ! $is_wp_login ) {
            return;
        }

        // Never block login form POST submissions
        if ( $is_wp_login && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            return;
        }

        // Allow logout action through (WP needs this to process logout)
        if ( $is_wp_login && isset( $_GET['action'] ) && $_GET['action'] === 'logout' ) {
            return;
        }

        // After logout, redirect to custom path instead of showing 404
        if ( $is_wp_login && isset( $_GET['loggedout'] ) ) {
            wp_redirect( home_url( '/' . $custom_path ) );
            exit;
        }

        // Allow if user came through the custom path
        if ( isset( $_GET['dd_entry'] ) ) {
            return;
        }

        // Allow if already logged in as admin or internal staff role.
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            return;
        }

        // Block everything else
        status_header( 404 );
        nocache_headers();
        exit( '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1></body></html>' );
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
