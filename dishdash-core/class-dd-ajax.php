<?php
/**
 * File:    dishdash-core/class-dd-ajax.php
 * Module:  DD_Ajax (static utility class)
 * Purpose: Centralized AJAX registration and nonce verification — modules
 *          call DD_Ajax::register() instead of add_action('wp_ajax_*') directly.
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core)
 *   - wp_ajax_* hooks (WordPress core)
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php  (loads DD_Cart::register_ajax via this)
 *   - modules/orders/class-dd-cart.php   (calls DD_Ajax::register for cart actions)
 *   - modules/orders/class-dd-orders-module.php
 *   - modules/homepage/class-dd-homepage-module.php
 *   - modules/tracking/class-dd-tracking-module.php
 *
 * Static methods:
 *   - register(action, callback, nopriv=true)
 *   - verify_nonce(field='nonce', action='dish_dash_frontend')
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DD_Ajax {

    /** @var array<string, callable> */
    private static array $handlers = [];

    /**
     * Register an AJAX action.
     *
     * @param string   $action     The action name (without wp_ajax_ prefix)
     * @param callable $callback   The handler
     * @param bool     $nopriv     Allow non-logged-in users (default true)
     */
    public static function register( string $action, callable $callback, bool $nopriv = true ): void {
        self::$handlers[ $action ] = $callback;

        add_action( 'wp_ajax_' . $action, $callback );

        if ( $nopriv ) {
            add_action( 'wp_ajax_nopriv_' . $action, $callback );
        }
    }

    /**
     * Verify a nonce from $_POST or $_GET and die on failure.
     *
     * @param string $field  The nonce field name (default: 'nonce')
     * @param string $action The nonce action (default: 'dish_dash_frontend')
     */
    public static function verify_nonce( string $field = 'nonce', string $action = 'dish_dash_frontend' ): void {
        $nonce = sanitize_text_field( $_REQUEST[ $field ] ?? '' );

        if ( ! wp_verify_nonce( $nonce, $action ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed. Please refresh the page.', 'dish-dash' ) ], 403 );
        }
    }

    /**
     * Return full product data for the modal enrichment (attributes, ratings).
     * Called by fetchProductEnrichment() in frontend.js.
     */
    public static function ajax_get_product(): void {
        check_ajax_referer( 'dish_dash_frontend', 'nonce' );

        $product_id = (int) ( $_POST['product_id'] ?? 0 );
        $product    = $product_id ? wc_get_product( $product_id ) : null;

        if ( ! $product ) {
            wp_send_json_error( 'Not found' ); return;
        }

        // Expose VISIBLE product attributes for any product type (simple included),
        // mirroring DD_API::map_product() exactly so desktop and mobile normalize
        // to an identical { name, options[] } shape → identical stored `variation`.
        // Generic over any attribute (no pa_spiciness-level special-casing).
        $attributes = [];
        foreach ( $product->get_attributes() as $attr ) {
            if ( ! $attr->get_visible() ) {
                continue;
            }
            // Spice is rendered by the dedicated category-rule selector — skip it here
            // so a product with the attribute assigned doesn't get two spice pickers.
            if ( $attr->get_name() === DD_API::spice_taxonomy() ) {
                continue;
            }
            $options = $attr->is_taxonomy()
                ? wc_get_product_terms( $product->get_id(), $attr->get_name(), [ 'fields' => 'names' ] )
                : $attr->get_options();
            $attributes[] = [
                'name'    => wc_attribute_label( $attr->get_name(), $product ),
                'options' => array_values( (array) $options ),
            ];
        }

        wp_send_json_success( [
            'id'             => $product->get_id(),
            'name'           => $product->get_name(),
            'price'          => (float) $product->get_price(),
            'price_html'     => $product->get_price_html(),
            'description'    => $product->get_short_description() ?: $product->get_description(),
            'image'          => wp_get_attachment_url( $product->get_image_id() ) ?: wc_placeholder_img_src(),
            'average_rating' => (float) $product->get_average_rating(),
            'rating_count'   => (int) $product->get_rating_count(),
            'attributes'     => $attributes,
            'variations'     => DD_API::normalize_variations( $product ),
            'has_spice'      => DD_API::product_has_spice( $product ),
            'spice_options'  => DD_API::product_has_spice( $product ) ? DD_API::spice_options() : [],
        ] );
    }
}

// Register dd_get_product for both logged-in and guest users
DD_Ajax::register( 'dd_get_product', [ 'DD_Ajax', 'ajax_get_product' ] );
