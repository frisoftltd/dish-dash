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
 *   - admin_menu          → register_admin_menus()
 *   - admin_enqueue_scripts → enqueue_admin_assets()
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
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Admin extends DD_Module {

    protected string $id = 'admin';

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'register_admin_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
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
            __( 'Dish Dash', 'dish-dash' ),
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
            'manage_options', 'dish-dash-orders',
            [ $this, 'render_orders' ]
        );

        // Menu Items
        add_submenu_page( 'dish-dash',
            __( 'Menu', 'dish-dash' ),
            __( '🍽️ Menu Items', 'dish-dash' ),
            'manage_options', 'dish-dash-menu',
            [ $this, 'render_menu' ]
        );

        // Reservations
        add_submenu_page( 'dish-dash',
            __( 'Reservations', 'dish-dash' ),
            __( '📅 Reservations', 'dish-dash' ),
            'manage_options', 'dish-dash-reservations',
            [ $this, 'render_reservations' ]
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

        // Analytics
        add_submenu_page( 'dish-dash',
            __( 'Analytics', 'dish-dash' ),
            __( '📈 Analytics', 'dish-dash' ),
            'manage_options', 'dish-dash-analytics',
            [ $this, 'render_analytics' ]
        );

        // Settings
        add_submenu_page( 'dish-dash',
            __( 'Settings', 'dish-dash' ),
            __( '⚙️ Settings', 'dish-dash' ),
            'manage_options', 'dish-dash-settings',
            [ $this, 'render_settings' ]
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
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'dish_dash_admin' ),
            'restUrl' => rest_url( 'dish-dash/v1/' ),
            'version' => DD_VERSION,
            'i18n'    => [
                'confirmDelete' => __( 'Are you sure you want to delete this?', 'dish-dash' ),
                'saved'         => __( 'Saved successfully.', 'dish-dash' ),
                'error'         => __( 'An error occurred. Please try again.', 'dish-dash' ),
            ],
        ] );

        // Modern admin styles
        wp_add_inline_style( 'dish-dash-admin', $this->get_admin_styles() );
    }

    private function get_admin_styles(): string {
        return '
        /* ── Dish Dash Admin — Modern UI ── */

        /* Sidebar menu item styling */
        #adminmenu #toplevel_page_dish-dash .wp-menu-name {
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        /* Admin page wrapper */
        .dd-admin-wrap {
            margin: 20px 20px 20px 0;
            max-width: 1200px;
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
        include DD_PLUGIN_DIR . 'admin/pages/coming-soon.php';
    }

    public function render_settings(): void {
        include DD_PLUGIN_DIR . 'admin/pages/settings.php';
    }
}
