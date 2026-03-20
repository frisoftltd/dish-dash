<?php
/**
 * Dish Dash – Admin Module
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Admin extends DD_Module {

    protected string $id = 'admin';

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'register_admin_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    public function register_admin_menus(): void {

        add_menu_page(
            __( 'Dish Dash', 'dish-dash' ),
            __( 'Dish Dash', 'dish-dash' ),
            'read',
            'dish-dash',
            [ $this, 'render_dashboard' ],
            'dashicons-food',
            25
        );

        add_submenu_page( 'dish-dash',
            __( 'Dashboard', 'dish-dash' ),
            __( 'Dashboard', 'dish-dash' ),
            'read', 'dish-dash',
            [ $this, 'render_dashboard' ]
        );

        add_submenu_page( 'dish-dash',
            __( 'Orders', 'dish-dash' ),
            __( 'Orders', 'dish-dash' ),
            'dd_manage_orders', 'dish-dash-orders',
            [ $this, 'render_orders' ]
        );

        add_submenu_page( 'dish-dash',
            __( 'Menu', 'dish-dash' ),
            __( 'Menu Items', 'dish-dash' ),
            'dd_manage_menu', 'dish-dash-menu',
            [ $this, 'render_menu' ]
        );

        add_submenu_page( 'dish-dash',
            __( 'Reservations', 'dish-dash' ),
            __( 'Reservations', 'dish-dash' ),
            'dd_manage_reservations', 'dish-dash-reservations',
            [ $this, 'render_reservations' ]
        );

        add_submenu_page( 'dish-dash',
            __( 'Delivery', 'dish-dash' ),
            __( 'Delivery', 'dish-dash' ),
            'dd_manage_delivery', 'dish-dash-delivery',
            [ $this, 'render_delivery' ]
        );

        add_submenu_page( 'dish-dash',
            __( 'Branches', 'dish-dash' ),
            __( 'Branches', 'dish-dash' ),
            'dd_manage_branches', 'dish-dash-branches',
            [ $this, 'render_branches' ]
        );

        add_submenu_page( 'dish-dash',
            __( 'POS', 'dish-dash' ),
            __( 'POS Terminal', 'dish-dash' ),
            'dd_access_pos', 'dish-dash-pos',
            [ $this, 'render_pos' ]
        );

        add_submenu_page( 'dish-dash',
            __( 'Analytics', 'dish-dash' ),
            __( 'Analytics', 'dish-dash' ),
            'dd_view_analytics', 'dish-dash-analytics',
            [ $this, 'render_analytics' ]
        );

        add_submenu_page( 'dish-dash',
            __( 'Template Settings', 'dish-dash' ),
            __( '🎨 Template', 'dish-dash' ),
            'dd_manage_settings', 'dish-dash-template',
            [ $this, 'render_template_settings' ]
        );

        add_submenu_page( 'dish-dash',
            __( 'Settings', 'dish-dash' ),
            __( 'Settings', 'dish-dash' ),
            'dd_manage_settings', 'dish-dash-settings',
            [ $this, 'render_settings' ]
        );
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'dish-dash' ) === false ) return;

        wp_enqueue_style( 'dish-dash-admin', DD_ASSETS_URL . 'css/admin.css', [], DD_VERSION );
        wp_enqueue_script( 'dish-dash-admin', DD_ASSETS_URL . 'js/admin.js', [ 'jquery' ], DD_VERSION, true );
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
    }

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

    public function render_template_settings(): void {
        include DD_PLUGIN_DIR . 'admin/pages/template-settings.php';
    }

    public function render_settings(): void {
        include DD_PLUGIN_DIR . 'admin/pages/settings.php';
    }
}
