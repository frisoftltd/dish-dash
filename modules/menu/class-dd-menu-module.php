<?php
/**
 * Dish Dash – Menu Module v2.2
 * Registers custom page template + loads theme assets
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Menu_Module extends DD_Module {

    protected string $id = 'menu';

    public function init(): void {
        add_action( 'wp_enqueue_scripts',       [ $this, 'enqueue_frontend_assets' ] );
        add_filter( 'theme_page_templates',     [ $this, 'register_page_template' ] );
        add_filter( 'template_include',         [ $this, 'load_page_template' ] );
        add_shortcode( 'dish_dash_menu',        [ $this, 'shortcode' ] );
        add_shortcode( 'dish_dash_cart',        [ $this, 'shortcode_cart' ] );
        add_shortcode( 'dish_dash_checkout',    [ $this, 'shortcode_checkout' ] );
    }

    // ─────────────────────────────────────────
    //  REGISTER TEMPLATE IN PAGE ATTRIBUTES
    // ─────────────────────────────────────────
    public function register_page_template( array $templates ): array {
        $templates['page-dishdash.php'] = __( 'Dish Dash Full Page', 'dish-dash' );
        return $templates;
    }

    // ─────────────────────────────────────────
    //  LOAD TEMPLATE FROM PLUGIN
    // ─────────────────────────────────────────
    public function load_page_template( string $template ): string {
        if ( is_page() ) {
            $meta = get_post_meta( get_the_ID(), '_wp_page_template', true );
            if ( 'page-dishdash.php' === $meta ) {
                $plugin_template = DD_TEMPLATES_DIR . 'page-dishdash.php';
                if ( file_exists( $plugin_template ) ) {
                    return $plugin_template;
                }
            }
        }
        return $template;
    }

    // ─────────────────────────────────────────
    //  ASSETS
    // ─────────────────────────────────────────
    public function enqueue_frontend_assets(): void {
        if ( is_admin() ) return;

        $plugin_url = plugins_url( 'dish-dash' );

        wp_enqueue_style(
            'dish-dash-theme',
            $plugin_url . '/assets/css/theme.css',
            [],
            DD_VERSION
        );
        wp_enqueue_style(
            'dish-dash-menu',
            $plugin_url . '/assets/css/menu.css',
            [],
            DD_VERSION
        );
        wp_enqueue_style(
            'dish-dash-cart',
            $plugin_url . '/assets/css/cart.css',
            [],
            DD_VERSION
        );
        wp_enqueue_script(
            'dish-dash-menu',
            $plugin_url . '/assets/js/menu.js',
            [],
            DD_VERSION,
            true
        );
        wp_enqueue_script(
            'dish-dash-cart',
            $plugin_url . '/assets/js/cart.js',
            [],
            DD_VERSION,
            true
        );
        wp_localize_script(
            'dish-dash-cart',
            'dishDash',
            DD_Settings::get_public_settings()
        );
    }

    // ─────────────────────────────────────────
    //  SHORTCODES
    // ─────────────────────────────────────────
    public function shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'category'       => '',
            'columns'        => '3',
            'show_filter'    => 'yes',
            'show_search'    => 'yes',
            'items_per_page' => '-1',
        ], $atts, 'dish_dash_menu' );

        $uncategorized = get_term_by( 'slug', 'uncategorized', 'product_cat' );
        $exclude_ids   = $uncategorized ? [ $uncategorized->term_id ] : [];

        $categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'exclude'    => $exclude_ids,
            'orderby'    => 'name',
        ] );

        $query_args = [
            'post_type'      => 'product',
            'posts_per_page' => (int) $atts['items_per_page'],
            'post_status'    => 'publish',
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'tax_query'      => [[
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => [ 'uncategorized' ],
                'operator' => 'NOT IN',
            ]],
        ];

        if ( ! empty( $atts['category'] ) ) {
            $query_args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => sanitize_text_field( $atts['category'] ),
            ];
        }

        $query_args = apply_filters( 'dish_dash_menu_query_args', $query_args );
        $items      = new WP_Query( $query_args );

        return $this->get_template( 'menu/grid.php', [
            'items'      => $items,
            'categories' => $categories,
            'atts'       => $atts,
        ] );
    }

    public function shortcode_cart( $atts ): string {
        return $this->get_template( 'cart/cart.php' );
    }

    public function shortcode_checkout( $atts ): string {
        return $this->get_template( 'checkout/checkout.php' );
    }
}
