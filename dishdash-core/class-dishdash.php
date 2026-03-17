<?php
/**
 * DishDash
 *
 * The main plugin singleton. Responsible for:
 *  - Loading core services (hooks, settings)
 *  - Registering and booting each module
 *  - Loading text domain for translations
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class DishDash {

    /** @var DishDash|null  Single instance */
    private static ?DishDash $instance = null;

    /** @var DD_Module[]  Registered modules, keyed by slug */
    private array $modules = [];

    // ── Singleton ─────────────────────────────────────────────────────────────

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Prevent external construction / cloning */
    private function __construct() {}
    public function __clone() {}
    public function __wakeup() {}

    // ── Boot sequence ─────────────────────────────────────────────────────────

    /**
     * Boot the plugin. Called on plugins_loaded.
     */
    public function boot(): void {
        $this->load_textdomain();
        $this->load_core();
        $this->register_modules();
        $this->boot_modules();

        /**
         * Fires after Dish Dash has fully loaded.
         * Third-party add-ons should hook here.
         */
        do_action( 'dish_dash_loaded' );
    }

    // ── Text domain ───────────────────────────────────────────────────────────

    private function load_textdomain(): void {
        load_plugin_textdomain(
            'dish-dash',
            false,
            dirname( plugin_basename( DD_FILE ) ) . '/languages/'
        );
    }

    // ── Core services ─────────────────────────────────────────────────────────

    private function load_core(): void {
        // Settings & options wrapper
        // Admin panel (only on admin pages)
        if ( is_admin() ) {
            new DD_Admin();
        }

        // REST API
        new DD_Rest_API();

        // Frontend (shortcodes, assets, templates)
        new DD_Frontend();
    }

    // ── Module registry ───────────────────────────────────────────────────────

    /**
     * Register all core modules.
     * Add new modules here as you build them.
     */
    private function register_modules(): void {
        $modules = [
            'menu'          => DD_Module_Menu::class,
            // Future modules — uncomment as you build each phase:
            // 'orders'        => DD_Module_Orders::class,
            // 'delivery'      => DD_Module_Delivery::class,
            // 'reservations'  => DD_Module_Reservations::class,
            // 'pos'           => DD_Module_POS::class,
            // 'analytics'     => DD_Module_Analytics::class,
            // 'branches'      => DD_Module_Branches::class,
            // 'customers'     => DD_Module_Customers::class,
            // 'notifications' => DD_Module_Notifications::class,
        ];

        foreach ( $modules as $slug => $class ) {
            if ( class_exists( $class ) ) {
                $this->modules[ $slug ] = new $class( $slug );
            }
        }
    }

    /**
     * Call boot() on every registered module.
     */
    private function boot_modules(): void {
        foreach ( $this->modules as $module ) {
            $module->boot();
        }
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Get a module instance by slug.
     *
     * @param  string $slug  e.g. 'menu', 'orders'
     * @return DD_Module|null
     */
    public function module( string $slug ): ?DD_Module {
        return $this->modules[ $slug ] ?? null;
    }

    /**
     * Shorthand global helper.
     * Usage:  dish_dash()->module('menu')
     */
    public static function get(): self {
        return self::instance();
    }
}

/**
 * Global helper function.
 * @return DishDash
 */
function dish_dash(): DishDash {
    return DishDash::instance();
}
