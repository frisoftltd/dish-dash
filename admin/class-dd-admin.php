<?php
/**
 * File:    admin/class-dd-admin.php
 * Module:  DD_Admin (extends DD_Module)
 * Purpose: Registers the top-level Dish Dash admin menu and all stub
 *          sub-menu items. Feature submenus (Orders, Settings, etc.)
 *          are registered independently by their own modules.
 *
 * Dependencies (this file needs):
 *   - DD_Module base class
 *   - DD_ASSETS_URL, DD_VERSION constants
 *   - admin/pages/dashboard.php (loaded via render_dashboard())
 *   - admin/pages/coming-soon.php (loaded for stub pages)
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php (instantiates DD_Admin)
 *
 * Hooks registered:
 *   - admin_menu            → register_admin_menus()
 *   - admin_enqueue_scripts → enqueue_admin_assets()
 *   - admin_init            → maybe_serve_invoice() (v3.18.1 — intercepts
 *     the Generate Invoice GET link before wp-admin chrome is echoed;
 *     calls dd_invoice_*() helpers in dishdash-core/class-dd-helpers.php)
 *   - in_admin_header       → render_health_notices() (v3.18.62 — self-healing
 *     PesaPal reconciliation watchdog: stale-cron / PesaPal-auth-broken /
 *     order-email-delivery-broken banners on every Dish Dash admin page.
 *     Deliberately not admin_notices, which DD_Hooks wipes on every screen.)
 *
 * Admin pages owned:
 *   dish-dash (Dashboard), dish-dash-menu (redirect to CPT editor),
 *   dish-dash-reservations, dish-dash-delivery, dish-dash-branches,
 *   dish-dash-pos, dish-dash-analytics (all coming-soon stubs)
 *
 * Assets enqueued:
 *   assets/css/admin.css, assets/js/admin.js
 *   Localizes: window.dishDashAdmin (ajaxUrl, nonce, restUrl, version)
 *
 * Last modified: v3.18.62
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Admin extends DD_Module {

    protected string $id = 'admin';

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'register_admin_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_init',            [ $this, 'maybe_serve_invoice' ] );

        // Self-healing PesaPal reconciliation health notices (v3.18.62) —
        // deliberately NOT a normal add_action('admin_notices', ...): that
        // hook is wiped on every screen by DD_Hooks::suppress_admin_notices()
        // at current_screen priority 999 (see class-dd-hooks.php), and this
        // needs to survive on every Dish Dash page, not just one page's own
        // late-registered notice (the existing pattern in orders.php works
        // because it registers from inside that one page's own render call,
        // after the wipe already ran — doing the same from a shared place for
        // every page would mean touching every render_*() method here).
        // in_admin_header is not on DD_Hooks' suppression list and fires on
        // every wp-admin page load, so registering once here is sufficient
        // and the output is gated to Dish Dash pages inside the callback.
        add_action( 'in_admin_header', [ $this, 'render_health_notices' ] );
    }

    /**
     * Payment-reconciliation health notices (v3.18.62) — three independent,
     * self-clearing conditions, each written by the orders/notifications
     * code that detects it and read here only:
     *   - dd_pesapal_last_sweep_at stale (>15 min) → the server cron that's
     *     supposed to hit wp-cron.php every minute has stopped. Also emails
     *     once per day while this stays true (DD_Notifications has no
     *     reason to own this — it's purely a cron-liveness fact).
     *   - dd_pesapal_auth_broken → PesaPal get_access_token() is failing;
     *     names sandbox or live mode so the right credential set gets
     *     checked (DD_Orders_Module::run_pesapal_reconcile_sweep() sets/
     *     clears this every run it manages to start).
     *   - dd_email_delivery_broken → an order email exhausted all 5 retries
     *     (DD_Notifications::process_email_retry_queue() sets this; cleared
     *     by the next successful send, retry or fresh).
     * Styled as plain div banners, not WordPress's .notice class — Admin UI
     * Rules forbid WP's default notice chrome (grey/blue) on Dish Dash pages.
     */
    public function render_health_notices(): void {
        $page = $_GET['page'] ?? '';
        if ( ! is_string( $page ) || ! str_starts_with( $page, 'dish-dash' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $banners = [];

        $last_sweep = get_option( 'dd_pesapal_last_sweep_at', '' );
        $stale      = ! $last_sweep || ( time() - strtotime( $last_sweep ) ) > 15 * MINUTE_IN_SECONDS;
        if ( $stale ) {
            $banners[] = 'Payment reconciliation is not running. Check the server cron.';

            $last_email = get_option( 'dd_pesapal_watchdog_last_email_at', '' );
            if ( ! $last_email || ( time() - strtotime( $last_email ) ) > DAY_IN_SECONDS ) {
                $admin_email = get_option( 'dd_admin_email', get_option( 'admin_email' ) );
                if ( $admin_email ) {
                    DD_Notifications::send_mail_reliably(
                        $admin_email,
                        'Payment reconciliation is not running',
                        'The PesaPal reconciliation sweep has not run in over 15 minutes. Check that the server cron hitting wp-cron.php every minute is still active.',
                        [],
                        [ 'kind' => 'reconcile_cron_stale' ]
                    );
                }
                update_option( 'dd_pesapal_watchdog_last_email_at', current_time( 'mysql' ), false );
            }
        }

        $auth_broken = get_option( 'dd_pesapal_auth_broken', '' );
        if ( is_array( $auth_broken ) && ! empty( $auth_broken['mode'] ) ) {
            $banners[] = sprintf(
                'PesaPal authentication is failing (%s mode). Check your PesaPal API credentials.',
                esc_html( $auth_broken['mode'] )
            );
        }

        $email_broken = get_option( 'dd_email_delivery_broken', '' );
        if ( $email_broken ) {
            $banners[] = 'Order emails are failing: ' . esc_html( $email_broken );
        }

        if ( ! $banners ) {
            return;
        }

        echo '<div style="margin:16px 24px 0;display:flex;flex-direction:column;gap:8px;">';
        foreach ( $banners as $text ) {
            echo '<div style="background:#fdecea;border:1px solid #f5c2bd;border-radius:12px;'
               . 'padding:12px 16px;color:#8a1f12;font-size:14px;font-family:Inter,sans-serif;">'
               . '&#9888; ' . esc_html( $text ) . '</div>';
        }
        echo '</div>';
    }

    /**
     * Intercepts the Generate Invoice GET link (?page=dish-dash-billing&dd_invoice=
     * or &dd_invoice_pdf=) on admin_init — the earliest hook available before
     * WordPress's admin-header.php echoes any wp-admin chrome (sidebar, admin
     * bar). Handling this from inside billing.php (v3.18.0) was too late: that
     * file only runs via render_billing(), which WordPress calls AFTER the
     * chrome has already been output, so the standalone invoice page rendered
     * nested inside the chrome instead of replacing it (v3.18.1 fix).
     */
    public function maybe_serve_invoice(): void {
        if ( ( $_GET['page'] ?? '' ) !== 'dish-dash-billing' ) {
            return;
        }
        if ( ! isset( $_GET['dd_invoice'] ) && ! isset( $_GET['dd_invoice_pdf'] ) ) {
            return;
        }

        $month = sanitize_text_field( wp_unslash( $_GET['dd_invoice'] ?? $_GET['dd_invoice_pdf'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
            wp_die( esc_html__( 'Invalid month.', 'dish-dash' ) );
        }

        $nonce_ok = wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ),
            'dd_invoice_' . $month
        );
        if ( ! dd_is_platform_admin() || ! $nonce_ok ) {
            wp_die( esc_html__( 'Access denied.', 'dish-dash' ) );
        }

        $data = dd_invoice_get_data( $month );

        if ( isset( $_GET['dd_invoice_pdf'] ) ) {
            dd_invoice_stream_pdf( $data );
        } else {
            dd_invoice_render_page( $data );
        }
        exit;
    }

    public function register_admin_menus(): void {

        // Custom SVG icon for Dish Dash
        $icon_svg = 'data:image/svg+xml;base64,' . base64_encode('
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" fill="white" opacity="0.15"/>
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z" fill="white"/>
            </svg>
        ');

        // Top level menu
        add_menu_page(
            __( 'Dish Dash', 'dish-dash' ),
            'Dish Dash <span class="dd-menu-badge" id="dd-menu-badge" style="display:none"></span>',
            'read',
            'dish-dash',
            [ $this, 'render_dashboard' ],
            $icon_svg,
            25
        );

        // Dashboard
        add_submenu_page( 'dish-dash',
            __( 'Dashboard', 'dish-dash' ),
            __( '📊 Dashboard', 'dish-dash' ),
            'read', 'dish-dash',
            [ $this, 'render_dashboard' ]
        );

        // Orders
        add_submenu_page( 'dish-dash',
            __( 'Orders', 'dish-dash' ),
            __( '🛒 Orders', 'dish-dash' ),
            'dd_manage_orders', 'dish-dash-orders',
            [ $this, 'render_orders' ]
        );

        // Menu Items
        add_submenu_page( 'dish-dash',
            __( 'Menu', 'dish-dash' ),
            __( '🍽️ Menu Items', 'dish-dash' ),
            'dd_manage_menu', 'dish-dash-menu',
            [ $this, 'render_menu' ]
        );

        // Delivery
        add_submenu_page( 'dish-dash',
            __( 'Delivery', 'dish-dash' ),
            __( '🚗 Delivery', 'dish-dash' ),
            'manage_options', 'dish-dash-delivery',
            [ $this, 'render_delivery' ]
        );

        // Branches
        add_submenu_page( 'dish-dash',
            __( 'Branches', 'dish-dash' ),
            __( '🏪 Branches', 'dish-dash' ),
            'manage_options', 'dish-dash-branches',
            [ $this, 'render_branches' ]
        );

        // POS Terminal
        add_submenu_page( 'dish-dash',
            __( 'POS', 'dish-dash' ),
            __( '🖥️ POS Terminal', 'dish-dash' ),
            'manage_options', 'dish-dash-pos',
            [ $this, 'render_pos' ]
        );

        // Analytics — Orders
        add_submenu_page( 'dish-dash',
            __( 'Analytics', 'dish-dash' ),
            __( '📈 Analytics', 'dish-dash' ),
            'dd_view_analytics', 'dish-dash-analytics',
            [ $this, 'render_analytics' ]
        );

        // Billing — only show when fee tracking is enabled
        if ( get_option( 'dd_fees_enabled', '0' ) === '1' ) {
            add_submenu_page(
                'dish-dash',
                __( 'Billing', 'dish-dash' ),
                __( '💳 Billing', 'dish-dash' ),
                'dd_view_billing',
                'dish-dash-billing',
                [ $this, 'render_billing' ]
            );
        }

        // Settings
        add_submenu_page( 'dish-dash',
            __( 'Settings', 'dish-dash' ),
            __( '⚙️ Settings', 'dish-dash' ),
            'dd_manage_settings', 'dish-dash-settings',
            [ $this, 'render_settings' ]
        );

        // Brand Identity
        add_submenu_page( 'dish-dash',
            __( 'Brand Identity', 'dish-dash' ),
            __( '🎨 Brand Identity', 'dish-dash' ),
            'dd_manage_brand_identity', 'dish-dash-brand-identity',
            [ $this, 'render_brand_identity' ]
        );

        // Tools (Event Health Check + future diagnostics)
        add_submenu_page( 'dish-dash',
            __( 'Tools', 'dish-dash' ),
            __( '🔧 Tools', 'dish-dash' ),
            'manage_options', 'dish-dash-tools',
            [ $this, 'render_tools' ]
        );
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'dish-dash' ) === false ) return;

        wp_enqueue_style(
            'dish-dash-admin',
            DD_ASSETS_URL . 'css/admin.css',
            [],
            DD_VERSION
        );
        wp_enqueue_script(
            'dish-dash-admin',
            DD_ASSETS_URL . 'js/admin.js',
            [ 'jquery' ],
            DD_VERSION,
            true
        );
        wp_localize_script( 'dish-dash-admin', 'dishDashAdmin', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'dish_dash_admin' ),
            'restUrl'        => rest_url( 'dish-dash/v1/' ),
            'version'        => DD_VERSION,
            'pollNonce'      => wp_create_nonce( 'dish_dash_admin' ),
            'restaurantName' => esc_js( get_option( 'dish_dash_restaurant_name', 'Restaurant' ) ),
            'pollInterval'   => 30000,
            // Order Handling setting (default '1' = on). Gates the notification
            // polling/alerts in admin.js at enqueue time. Regenerated every page
            // load, so a cached admin.js still receives the current value.
            'notifyEnabled'  => get_option( 'dish_dash_order_notify_dashboard', '1' ) === '1',
            'i18n'           => [
                'confirmDelete' => __( 'Are you sure you want to delete this?', 'dish-dash' ),
                'saved'         => __( 'Saved successfully.', 'dish-dash' ),
                'error'         => __( 'An error occurred. Please try again.', 'dish-dash' ),
            ],
        ] );

        // Chart.js — loaded on all admin pages (lightweight, cached by browser)
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        // Dashboard-specific JS — only on the main dashboard page
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'dish-dash' ) {
            wp_enqueue_script(
                'dd-dashboard',
                DD_PLUGIN_URL . 'assets/js/dashboard.js',
                [ 'chartjs' ],
                DD_VERSION,
                true
            );
            wp_localize_script( 'dd-dashboard', 'ddDashboard', [
                'brandColor' => sanitize_hex_color( get_option( 'dish_dash_primary_color', '#65040d' ) ),
            ] );
        }

        // Analytics — single page, both tabs
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'dish-dash-analytics' ) {
            wp_enqueue_script(
                'dd-analytics',
                DD_PLUGIN_URL . 'assets/js/analytics.js',
                [ 'chartjs' ],
                DD_VERSION,
                true
            );
            wp_enqueue_script(
                'dd-analytics-reservations',
                DD_PLUGIN_URL . 'assets/js/analytics-reservations.js',
                [ 'chartjs' ],
                DD_VERSION,
                true
            );
            wp_localize_script( 'dd-analytics', 'ddAnalytics', [
                'brandColor' => sanitize_hex_color( get_option( 'dish_dash_primary_color', '#65040d' ) ),
            ] );
        }

        // Media uploader for Brand Identity logo picker
        if ( strpos( $hook, 'dish-dash-brand-identity' ) !== false ) {
            wp_enqueue_media();
        }

        // Modern admin styles
        wp_add_inline_style( 'dish-dash-admin', $this->get_admin_styles() );
    }

    private function get_admin_styles(): string {
        $primary = sanitize_hex_color( get_option( 'dish_dash_primary_color', '#65040d' ) );
        $rgb     = $this->hex_to_rgb( $primary );
        $rgb_str = implode( ',', $rgb );

        $css  = ":root, body { --dd-brand: {$primary}; --dd-brand-rgb: {$rgb_str}; }\n";
        $css .= $this->get_raw_admin_styles();
        return $css;
    }

    private function hex_to_rgb( $hex ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        return [
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
        ];
    }

    private function get_raw_admin_styles(): string {
        return '
        /* ── Dish Dash Admin — Modern UI ── */

        /* Sidebar menu item styling */
        #adminmenu #toplevel_page_dish-dash .wp-menu-name {
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        /* Admin page wrapper */
        .dd-admin-wrap {
            margin: 20px 20px 0;
            width: 100%;
            box-sizing: border-box;
        }

        /* Modern header */
        .dd-admin-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(135deg, #6B1D1D 0%, #8B2020 50%, #6B1D1D 100%);
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(107,29,29,0.3);
        }
        .dd-admin-header__logo {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .dd-logo-icon {
            font-size: 36px;
            line-height: 1;
            background: rgba(255,255,255,0.15);
            width: 60px;
            height: 60px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .dd-admin-header h1 {
            color: #ffffff !important;
            font-size: 22px !important;
            font-weight: 700 !important;
            margin: 0 !important;
            line-height: 1.2 !important;
            padding: 0 !important;
        }
        .dd-version {
            color: rgba(255,255,255,0.75);
            font-size: 13px;
            margin-top: 2px;
            display: block;
        }
        .dd-admin-header .button {
            background: rgba(255,255,255,0.15) !important;
            color: #ffffff !important;
            border: 1px solid rgba(255,255,255,0.3) !important;
            border-radius: 8px !important;
            padding: 10px 18px !important;
            font-weight: 600 !important;
            text-decoration: none !important;
            transition: background 0.2s !important;
            height: auto !important;
            line-height: 1.4 !important;
        }
        .dd-admin-header .button:hover {
            background: rgba(255,255,255,0.25) !important;
        }

        /* Settings cards */
        .dd-settings-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: box-shadow 0.2s;
        }
        .dd-settings-card:hover {
            box-shadow: 0 4px 20px rgba(107,29,29,0.1);
        }
        .dd-settings-card h2 {
            font-size: 14px !important;
            font-weight: 700 !important;
            margin: 0 0 20px !important;
            padding-bottom: 14px !important;
            border-bottom: 2px solid #f5f5f5 !important;
            color: #1a1a1a !important;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .dd-form-group {
            margin-bottom: 16px;
        }
        .dd-form-group label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #999;
            margin-bottom: 6px;
        }
        .dd-form-group input[type="text"],
        .dd-form-group input[type="email"],
        .dd-form-group input[type="url"],
        .dd-form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e8e8e8;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #fafafa;
        }
        .dd-form-group input:focus,
        .dd-form-group textarea:focus {
            border-color: #6B1D1D;
            outline: none;
            box-shadow: 0 0 0 3px rgba(107,29,29,0.1);
            background: #fff;
        }

        /* Homepage settings */
        .dd-hp-section {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: box-shadow 0.2s;
        }
        .dd-hp-section:hover {
            box-shadow: 0 4px 20px rgba(107,29,29,0.08);
        }
        .dd-hp-section__header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px 24px;
            background: linear-gradient(to right, #fafafa, #fff);
            border-bottom: 1px solid #f0f0f0;
        }
        .dd-hp-section__header h2 {
            margin: 0 !important;
            font-size: 15px !important;
            font-weight: 700 !important;
            color: #1a1a1a !important;
            flex: 1;
            padding: 0 !important;
            border: none !important;
        }
        .dd-hp-section__icon {
            font-size: 22px;
            background: rgba(107,29,29,0.08);
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }
        .dd-hp-section__body {
            padding: 24px;
        }
        .dd-hp-subsection {
            background: #f9f9f9;
            border: 1px solid #ebebeb;
            border-radius: 12px;
            padding: 18px;
            margin-top: 16px;
        }
        .dd-hp-subsection h3 {
            margin: 0 0 16px !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            color: #888 !important;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .dd-hp-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .dd-hp-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        .dd-hp-field { display: flex; flex-direction: column; gap: 6px; }
        .dd-hp-field > label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #999;
        }
        .dd-hp-input {
            border: 1.5px solid #e8e8e8 !important;
            border-radius: 10px !important;
            padding: 10px 14px !important;
            font-size: 13px !important;
            width: 100% !important;
            transition: border-color 0.2s, box-shadow 0.2s !important;
            background: #fafafa !important;
            box-sizing: border-box !important;
        }
        .dd-hp-input:focus {
            border-color: #6B1D1D !important;
            outline: none !important;
            box-shadow: 0 0 0 3px rgba(107,29,29,0.1) !important;
            background: #fff !important;
        }
        .dd-hp-select {
            border: 1.5px solid #e8e8e8 !important;
            border-radius: 10px !important;
            padding: 10px 14px !important;
            font-size: 13px !important;
            width: 100% !important;
            background: #fafafa !important;
            cursor: pointer;
        }
        .dd-hp-select:focus {
            border-color: #6B1D1D !important;
            outline: none !important;
        }
        .dd-hp-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: #333;
        }
        .dd-hp-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #6B1D1D;
            cursor: pointer;
            flex-shrink: 0;
        }
        .dd-hp-toggle--header { margin-left: auto; }
        .dd-hp-row { display: flex; flex-wrap: wrap; gap: 20px; align-items: center; }
        .dd-hp-hint { font-size: 11px; color: #aaa; line-height: 1.4; }
        .dd-hp-hint a { color: #6B1D1D; }
        .dd-hp-note { margin: 12px 0 0; font-size: 12px; color: #bbb; font-style: italic; background: #f9f9f9; padding: 10px 14px; border-radius: 8px; border-left: 3px solid #e0e0e0; }
        .dd-hp-note a { color: #6B1D1D; text-decoration: none; font-weight: 600; }
        .dd-hp-save-bar {
            margin-top: 24px;
            padding: 20px 24px;
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        .dd-hp-save-bar span { color: #999; font-size: 13px; }
        .dd-hp-save-bar .button-primary {
            background: #6B1D1D !important;
            border-color: #6B1D1D !important;
            color: #fff !important;
            border-radius: 10px !important;
            padding: 10px 24px !important;
            font-size: 14px !important;
            font-weight: 700 !important;
            height: auto !important;
            line-height: 1.4 !important;
            box-shadow: 0 4px 16px rgba(107,29,29,0.3) !important;
            transition: all 0.2s !important;
        }
        .dd-hp-save-bar .button-primary:hover {
            background: #8B2020 !important;
            box-shadow: 0 6px 20px rgba(107,29,29,0.4) !important;
            transform: translateY(-1px);
        }
        .dd-hp-sections { display: grid; gap: 16px; margin-top: 20px; }

        @media (max-width: 782px) {
            .dd-hp-grid-2, .dd-hp-grid-3 { grid-template-columns: 1fr; }
            .dd-admin-header { flex-direction: column; align-items: flex-start; gap: 16px; }
        }
        ';
    }

    // ─────────────────────────────────────────
    //  PAGE RENDERERS
    // ─────────────────────────────────────────
    public function render_dashboard(): void {
        include DD_PLUGIN_DIR . 'admin/pages/dashboard.php';
    }

    public function render_orders(): void {
        include DD_PLUGIN_DIR . 'admin/pages/orders.php';
    }

    public function render_menu(): void {
        wp_redirect( admin_url( 'edit.php?post_type=dd_menu_item' ) );
        exit;
    }

    public function render_reservations(): void {
        include DD_PLUGIN_DIR . 'admin/pages/coming-soon.php';
    }

    public function render_delivery(): void {
        include DD_PLUGIN_DIR . 'admin/pages/coming-soon.php';
    }

    public function render_branches(): void {
        include DD_PLUGIN_DIR . 'admin/pages/coming-soon.php';
    }

    public function render_pos(): void {
        include DD_PLUGIN_DIR . 'admin/pages/coming-soon.php';
    }

    public function render_analytics(): void {
        include DD_PLUGIN_DIR . 'admin/pages/analytics.php';
    }

    public function render_billing(): void {
        include DD_PLUGIN_DIR . 'admin/pages/billing.php';
    }

    public function render_settings(): void {
        include DD_PLUGIN_DIR . 'admin/pages/settings.php';
    }

    public function render_brand_identity(): void {
        include DD_PLUGIN_DIR . 'admin/pages/brand-identity.php';
    }

    public function render_tools(): void {
        include DD_PLUGIN_DIR . 'admin/pages/event-health.php';
        include DD_PLUGIN_DIR . 'admin/pages/csv-menu-import.php';
    }
}
