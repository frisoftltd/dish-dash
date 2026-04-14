<?php
/**
 * File:    dishdash-core/class-dd-loader.php
 * Module:  DD_Loader (singleton)
 * Purpose: Bootstraps the entire plugin — loads core files, instantiates
 *          and initialises every module in a guaranteed order, then fires
 *          the dish_dash_loaded action to signal third-party code.
 *
 * Dependencies (this file needs):
 *   - All module classes (via autoloader): DD_Admin, DD_Template_Module,
 *     DD_Homepage_Module, DD_Auth_Module, DD_Customers_Module,
 *     DD_Tracking_Module, DD_Menu_Module, DD_Orders_Module
 *   - DD_Cart (loaded separately via DD_Cart::register_ajax())
 *   - dishdash-core/class-dd-helpers.php (require_once)
 *   - dishdash-core/class-dd-hooks.php
 *
 * Dependents (files that need this):
 *   - dish-dash.php (calls DD_Loader::instance()->boot() on plugins_loaded)
 *
 * Boot sequence:
 *   load_core_files → register_modules → DD_Cart::register_ajax
 *   → init_modules → load_textdomain → do_action('dish_dash_loaded')
 *
 * Hooks fired:
 *   - dish_dash_loaded (after all modules initialised)
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Loader {

    private static ?DD_Loader $instance = null;
    private array $modules = [];

    private function __construct() {}

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void {
        $this->load_core_files();
        $this->register_modules();
        $this->init_modules();
        $this->load_textdomain();
        do_action( 'dish_dash_loaded' );
    }

    private function load_core_files(): void {
        $core_files = [
            'class-dd-module',
            'class-dd-hooks',
            'class-dd-helpers',
            'class-dd-ajax',
            'class-dd-settings',
        ];
        foreach ( $core_files as $file ) {
            require_once DD_PLUGIN_DIR . "dishdash-core/{$file}.php";
        }
    }

    private function register_modules(): void {
        $modules = [
            // Admin module
            'DD_Admin'            => 'admin/class-dd-admin.php',
            // Template module — handles header, hero, footer, page template
            'DD_Template_Module'  => 'modules/template/class-dd-template-module.php',
            // Homepage Settings module
            'DD_Homepage_Module'  => 'modules/homepage/class-dd-homepage-module.php',
            // Auth module — custom login/register/Google OAuth
            'DD_Auth_Module'      => 'modules/auth/class-dd-auth-module.php',
            // Customers module — customer management dashboard
            'DD_Customers_Module' => 'modules/customers/class-dd-customers-module.php',
            // Tracking module — behavior events, AI foundation
            'DD_Tracking_Module'  => 'modules/tracking/class-dd-tracking-module.php',
            // Menu module — only shortcodes
            'DD_Menu_Module'      => 'modules/menu/class-dd-menu-module.php',
            // Orders module
            'DD_Orders_Module'    => 'modules/orders/class-dd-orders-module.php',
            // Coming soon:
            // 'DD_Delivery_Module'     => 'modules/delivery/class-dd-delivery-module.php',
            // 'DD_Reservations_Module' => 'modules/reservations/class-dd-reservations-module.php',
            // 'DD_POS_Module'          => 'modules/pos/class-dd-pos-module.php',
            // 'DD_Analytics_Module'    => 'modules/analytics/class-dd-analytics-module.php',
            // 'DD_Branches_Module'     => 'modules/branches/class-dd-branches-module.php',
        ];

        foreach ( $modules as $class => $path ) {
            $full_path = DD_PLUGIN_DIR . $path;
            if ( ! file_exists( $full_path ) ) continue;
            require_once $full_path;
            if ( class_exists( $class ) ) {
                $this->modules[ $class ] = new $class();
            }
        }

        // Boot cart AJAX
        if ( file_exists( DD_PLUGIN_DIR . 'modules/orders/class-dd-cart.php' ) ) {
            require_once DD_PLUGIN_DIR . 'modules/orders/class-dd-cart.php';
            DD_Cart::register_ajax();
        }
    }

    private function init_modules(): void {
        foreach ( $this->modules as $module ) {
            if ( $module instanceof DD_Module ) {
                $module->init();
            }
        }
    }

    private function load_textdomain(): void {
        load_plugin_textdomain( 'dish-dash', false, DD_PLUGIN_DIR . 'languages/' );
    }

    public function get_module( string $class ): ?DD_Module {
        return $this->modules[ $class ] ?? null;
    }
}
