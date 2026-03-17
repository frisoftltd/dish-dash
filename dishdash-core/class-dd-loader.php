<?php
/**
 * Dish Dash – Core Loader
 *
 * Singleton that boots all modules in the correct order
 * and manages the module registry.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DD_Loader {

    /** @var DD_Loader|null */
    private static ?DD_Loader $instance = null;

    /** @var DD_Module[] Registered module instances */
    private array $modules = [];

    // Private constructor — use ::instance()
    private function __construct() {}

    /**
     * Get the singleton instance.
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Boot sequence — called once from dish-dash.php.
     */
    public function boot(): void {
        $this->load_core_files();
        $this->register_modules();
        $this->init_modules();
        $this->load_textdomain();

        // Signal that Dish Dash is fully loaded.
        do_action( 'dish_dash_loaded' );
    }

    // ─────────────────────────────────────────
    //  CORE FILES
    //  Load helpers and base classes that every
    //  module depends on.
    // ─────────────────────────────────────────
    private function load_core_files(): void {
        $core_files = [
            'class-dd-module',       // Abstract base for all modules
            'class-dd-hooks',        // Central hook manager
            'class-dd-helpers',      // Utility / helper functions
            'class-dd-ajax',         // AJAX router
            'class-dd-settings',     // Options/settings API wrapper
        ];

        foreach ( $core_files as $file ) {
            require_once DD_PLUGIN_DIR . "dishdash-core/{$file}.php";
        }
    }

    // ─────────────────────────────────────────
    //  MODULE REGISTRY
    //  Add new modules here as they are built.
    //  Each entry: 'ModuleClassName' => 'path/to/file.php'
    // ─────────────────────────────────────────
    private function register_modules(): void {
        $modules = [
            'DD_Admin'          => 'admin/class-dd-admin.php',
            'DD_Menu_Module'    => 'modules/menu/class-dd-menu-module.php',
            // Future modules — uncomment as they are built:
            // 'DD_Orders_Module'    => 'modules/orders/class-dd-orders-module.php',
            // 'DD_Delivery_Module'  => 'modules/delivery/class-dd-delivery-module.php',
            // 'DD_Reservations_Module' => 'modules/reservations/class-dd-reservations-module.php',
            // 'DD_POS_Module'       => 'modules/pos/class-dd-pos-module.php',
            // 'DD_Analytics_Module' => 'modules/analytics/class-dd-analytics-module.php',
            // 'DD_Branches_Module'  => 'modules/branches/class-dd-branches-module.php',
        ];

        foreach ( $modules as $class => $path ) {
            $full_path = DD_PLUGIN_DIR . $path;

            if ( ! file_exists( $full_path ) ) {
                continue; // Skip modules not yet built.
            }

            require_once $full_path;

            if ( class_exists( $class ) ) {
                $this->modules[ $class ] = new $class();
            }
        }
    }

    // ─────────────────────────────────────────
    //  INIT MODULES
    // ─────────────────────────────────────────
    private function init_modules(): void {
        foreach ( $this->modules as $module ) {
            if ( $module instanceof DD_Module ) {
                $module->init();
            }
        }
    }

    // ─────────────────────────────────────────
    //  TEXTDOMAIN
    // ─────────────────────────────────────────
    private function load_textdomain(): void {
        load_plugin_textdomain(
            'dish-dash',
            false,
            DD_PLUGIN_DIR . 'languages/'
        );
    }

    /**
     * Get a registered module instance by class name.
     * Example: DD_Loader::instance()->get_module('DD_Menu_Module')
     */
    public function get_module( string $class ): ?DD_Module {
        return $this->modules[ $class ] ?? null;
    }
}
