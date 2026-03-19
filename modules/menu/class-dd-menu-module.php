<?php
/**
 * Dish Dash – Menu Module (WooCommerce Product Integration)
 *
 * Displays WooCommerce products as restaurant menu items.
 * Uses product_cat taxonomy for category filtering.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DD_Menu_Module extends DD_Module {

    protected string $id = 'menu';

    public function init(): void {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
        add_shortcode( 'dish_dash_menu',  [ $this, 'shortcode' ] );
        add_shortcode( 'dish_dash_cart',  [ $this, 'shortcode_cart' ] );
        add_shortcode( 'dish_dash_checkout', [ $this, 'shortcode_checkout' ] );
    }

    // ─────────────────────────────────────────
    //  FRONTEND ASSETS
    // ─────────────────────────────────────────
    public function enqueue_frontend_assets(): void {
        $this->enqueue_style( 'menu',  'menu.css' );
        $this->enqueue_style( 'cart',  'cart.css' );
        $this->enqueue_script( 'menu', 'menu.js', [], true );
        $this->enqueue_script( 'cart', 'cart.js', [], true );

        wp_localize_script( 'dish-dash-cart', 'dishDash', DD_Settings::get_public_settings() );
    }

    // ─────────────────────────────────────────
    //  SHORTCODE  [dish_dash_menu]
    // ─────────────────────────────────────────
    public function shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'category'       => '',
            'columns'        => '3',
            'show_filter'    => 'yes',
            'show_search'    => 'yes',
            'items_per_page' => '-1',
        ], $atts, 'dish_dash_menu' );

        // Fetch WooCommerce categories
        $categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'exclude'    => get_option( 'default_product_cat' ),
        ] );

        // Build WC product query
        $query_args = [
            'post_type'      => 'product',
            'posts_per_page' => (int) $atts['items_per_page'],
            'post_status'    => 'publish',
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ];

        if ( ! empty( $atts['category'] ) ) {
            $query_args['tax_query'] = [ [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => sanitize_text_field( $atts['category'] ),
            ] ];
        }

        $query_args = apply_filters( 'dish_dash_menu_query_args', $query_args );
        $items      = new WP_Query( $query_args );

        // Inject cart sidebar on menu page
        $cart_html = $this->get_template( 'cart/cart.php' );

        return $cart_html . $this->get_template( 'menu/grid.php', [
            'items'      => $items,
            'categories' => $categories,
            'atts'       => $atts,
        ] );
    }

    // ─────────────────────────────────────────
    //  SHORTCODE [dish_dash_cart]
    // ─────────────────────────────────────────
    public function shortcode_cart( $atts ): string {
        return $this->get_template( 'cart/cart.php' );
    }

    // ─────────────────────────────────────────
    //  SHORTCODE [dish_dash_checkout]
    // ─────────────────────────────────────────
    public function shortcode_checkout( $atts ): string {
        return $this->get_template( 'checkout/checkout.php' );
    }
}
