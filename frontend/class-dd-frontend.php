<?php
/**
 * DD_Frontend
 *
 * Boots the public-facing side of Dish Dash:
 * - Registers shortcodes
 * - Enqueues front-end CSS / JS
 * - Sets up the template override system
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
