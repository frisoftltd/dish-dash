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
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'html5', array(
        'search-form', 'comment-form', 'comment-list',
        'gallery', 'caption', 'style', 'script',
    ) );
    add_theme_support( 'body-open' );
    add_theme_support( 'custom-logo' );
    add_theme_support( 'woocommerce' );
    add_theme_support( 'wc-product-gallery-zoom' );
    add_theme_support( 'wc-product-gallery-lightbox' );
    add_theme_support( 'wc-product-gallery-slider' );
    add_theme_support( 'automatic-feed-links' );
}
add_action( 'after_setup_theme', 'dish_dash_theme_setup' );

// ── Enqueue Google Fonts + Base Styles ────────────────────────
function dish_dash_theme_enqueue_styles() {
    // Google Fonts — same as plugin template
    wp_enqueue_style(
        'dish-dash-fonts',
        'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,600&family=Inter:wght@400;500;600;700&display=swap',
        [],
        null
    );

    // Remove conflicting WordPress default styles
    wp_dequeue_style( 'wp-block-library' );
    wp_dequeue_style( 'wp-block-library-theme' );
    wp_dequeue_style( 'global-styles' );
    wp_dequeue_style( 'classic-theme-styles' );
}
add_action( 'wp_enqueue_scripts', 'dish_dash_theme_enqueue_styles', 100 );

// ── Base Styles ───────────────────────────────────────────────
function dish_dash_theme_inline_styles() {
    echo '<style>
    *, *::before, *::after { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
        margin: 0;
        padding: 0;
        font-family: "Inter", system-ui, sans-serif;
        font-size: 16px;
        line-height: 1.6;
        background: #F5EFE6;
        color: #221B19;
        -webkit-font-smoothing: antialiased;
    }
    img { max-width: 100%; display: block; }
    a { text-decoration: none; color: inherit; }
    button { font: inherit; cursor: pointer; }
    ul { list-style: none; margin: 0; padding: 0; }
    h1, h2, h3, h4, h5, h6 {
        font-family: "Cormorant Garamond", Georgia, serif;
        line-height: 1.1;
        margin: 0;
    }
    /* WooCommerce base reset */
    .woocommerce,
    .woocommerce-page {
        font-family: "Inter", system-ui, sans-serif;
    }
    /* Page content spacing */
    .entry-content,
    .woocommerce-page .entry-content,
    .woocommerce-account .entry-content,
    #main {
        max-width: 1240px;
        margin: 0 auto;
        padding: 40px 20px;
    }
    </style>';
}
add_action( 'wp_head', 'dish_dash_theme_inline_styles' );

// ── Notice if Dish Dash plugin is not active ──────────────────
function dish_dash_theme_plugin_notice() {
    if ( ! defined( 'DD_VERSION' ) ) {
        echo '<div style="background:#fff3cd;border:2px solid #ffc107;padding:20px;text-align:center;font-family:sans-serif;font-size:16px;">
            <strong>⚠️ Dish Dash Plugin Required</strong><br><br>
            This theme requires the <strong>Dish Dash</strong> plugin to work correctly.
            Please install and activate the Dish Dash plugin.
        </div>';
    }
}
add_action( 'wp_body_open', 'dish_dash_theme_plugin_notice' );
