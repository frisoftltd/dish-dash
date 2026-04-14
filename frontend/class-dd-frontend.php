<?php
/**
 * File:    frontend/class-dd-frontend.php
 * Module:  DD_Frontend
 * Purpose: Boots the public-facing side — enqueues frontend CSS/JS,
 *          localizes dishDashFront for AJAX, and fires
 *          dish_dash_register_shortcodes for modules to self-register.
 *          Also provides the static DD_Frontend::get_template() loader
 *          with theme-override support.
 *
 * Dependencies (this file needs):
 *   - DD_ASSETS_URL, DD_VERSION, DD_TEMPLATES constants
 *   - assets/css/frontend.css, assets/js/frontend.js (enqueued)
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dishdash.php (instantiates DD_Frontend)
 *
 * Hooks registered:
 *   - wp_enqueue_scripts → enqueue_assets()
 *   - init               → register_shortcodes() (fires dish_dash_register_shortcodes)
 *
 * Localized data (window.dishDashFront):
 *   ajaxUrl, restUrl, nonce, currency, i18n messages
 *
 * Template loader:
 *   DD_Frontend::get_template(template, args) — checks theme/dish-dash/ first
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Frontend {

    public function __construct() {
        add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_assets'      ] );
        add_action( 'init',                  [ $this, 'register_shortcodes' ] );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets(): void {
        wp_enqueue_style(
            'dd-frontend',
            DD_ASSETS_URL . 'css/frontend.css',
            [],
            DD_VERSION
        );

        wp_enqueue_script(
            'dd-frontend',
            DD_ASSETS_URL . 'js/frontend.js',
            [],
            DD_VERSION,
            true
        );

        wp_localize_script( 'dd-frontend', 'dishDashFront', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'restUrl'   => rest_url( 'dish-dash/v1/' ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'currency'  => get_option( 'dd_currency_symbol', '$' ),
            'i18n'      => [
                'add_to_cart'    => __( 'Add to Cart',      'dish-dash' ),
                'added'          => __( 'Added!',           'dish-dash' ),
                'your_cart'      => __( 'Your Cart',        'dish-dash' ),
                'empty_cart'     => __( 'Your cart is empty.', 'dish-dash' ),
                'checkout'       => __( 'Checkout',         'dish-dash' ),
                'remove'         => __( 'Remove',           'dish-dash' ),
                'loading'        => __( 'Loading…',         'dish-dash' ),
            ],
        ] );
    }

    // ── Shortcodes ────────────────────────────────────────────────────────────

    public function register_shortcodes(): void {
        // Shortcode handlers live in the module files.
        // They self-register via add_shortcode() in their own boot() method.
        // This method is a hook point for future use.
        do_action( 'dish_dash_register_shortcodes' );
    }

    // ── Template loader ───────────────────────────────────────────────────────

    /**
     * Load a Dish Dash template, allowing themes to override it.
     *
     * Theme override path:  /your-theme/dish-dash/{template}.php
     * Plugin fallback:      /dish-dash/templates/{template}.php
     *
     * @param string $template  Relative path, e.g. 'menu/grid.php'
     * @param array  $args      Variables to extract into template scope
     */
    public static function get_template( string $template, array $args = [] ): void {
        if ( ! empty( $args ) ) {
            extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
        }

        $theme_file  = get_stylesheet_directory() . '/dish-dash/' . $template;
        $plugin_file = DD_TEMPLATES . $template;

        if ( file_exists( $theme_file ) ) {
            include $theme_file;
        } elseif ( file_exists( $plugin_file ) ) {
            include $plugin_file;
        } else {
            // Developer notice in debug mode
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                echo "<!-- Dish Dash: template '{$template}' not found. -->";
            }
        }
    }
}
