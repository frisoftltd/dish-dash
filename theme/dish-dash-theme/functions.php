
<?php
/**
 * Dish Dash Theme — Functions
 *
 * Minimal theme setup. All functionality
 * is provided by the Dish Dash plugin.
 *
 * @package DishDashTheme
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Theme Setup ────────────────────────────────────────────────
function dish_dash_theme_setup() {

    // Support wp_title
    add_theme_support( 'title-tag' );

    // Support post thumbnails
    add_theme_support( 'post-thumbnails' );

    // Support HTML5
    add_theme_support( 'html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'style',
        'script',
    ) );

    // Support wp_body_open — critical for Dish Dash header injection
    add_theme_support( 'body-open' );

    // Support custom logo
    add_theme_support( 'custom-logo' );

    // Support WooCommerce
    add_theme_support( 'woocommerce' );
    add_theme_support( 'wc-product-gallery-zoom' );
    add_theme_support( 'wc-product-gallery-lightbox' );
    add_theme_support( 'wc-product-gallery-slider' );

    // Automatic feed links
    add_theme_support( 'automatic-feed-links' );

}
add_action( 'after_setup_theme', 'dish_dash_theme_setup' );

// ── Remove all default WordPress styles ───────────────────────
function dish_dash_theme_remove_styles() {
    // Remove block styles — Dish Dash provides its own
    wp_dequeue_style( 'wp-block-library' );
    wp_dequeue_style( 'wp-block-library-theme' );
    wp_dequeue_style( 'global-styles' );
    wp_dequeue_style( 'classic-theme-styles' );
}
add_action( 'wp_enqueue_scripts', 'dish_dash_theme_remove_styles', 100 );

// ── Minimal body styles ───────────────────────────────────────
function dish_dash_theme_inline_styles() {
    echo '<style>
    * { box-sizing: border-box; }
    body { margin: 0; padding: 0; font-family: inherit; }
    img { max-width: 100%; }
    a { text-decoration: none; }
    </style>';
}
add_action( 'wp_head', 'dish_dash_theme_inline_styles' );

// ── Notice if Dish Dash plugin is not active ──────────────────
function dish_dash_theme_plugin_notice() {
    if ( ! function_exists( 'dish_dash_loaded' ) && ! defined( 'DD_VERSION' ) ) {
        echo '<div style="background:#fff3cd;border:1px solid #ffc107;padding:20px;text-align:center;font-family:sans-serif;">
            <strong>⚠️ Dish Dash Plugin Required</strong><br>
            This theme requires the <strong>Dish Dash</strong> plugin to work correctly.
            Please install and activate the Dish Dash plugin.
        </div>';
    }
}
add_action( 'wp_body_open', 'dish_dash_theme_plugin_notice' );
