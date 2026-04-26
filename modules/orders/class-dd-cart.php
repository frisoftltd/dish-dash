<?php
/**
 * File:    modules/orders/class-dd-cart.php
 * Module:  DD_Cart (static class — not a DD_Module)
 * Purpose: Server-side cart engine. Stores cart state in WP transients
 *          (3-day TTL). Provides AJAX handlers for all cart operations.
 *          Uses a per-session cookie (dd_session) for guest identification.
 *
 * Dependencies (this file needs):
 *   - DD_Ajax::register() (dishdash-core/class-dd-ajax.php)
 *   - DD_Settings::get('tax_rate') for totals calculation
 *   - WordPress transient API (set/get/delete_transient)
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php (calls DD_Cart::register_ajax())
 *   - assets/js/cart.js  (calls these AJAX actions from browser)
 *   - assets/js/frontend.js (calls dd_cart_add, dd_cart_get)
 *
 * AJAX actions registered:
 *   dd_cart_add, dd_cart_update, dd_cart_remove,
 *   dd_cart_get, dd_cart_clear (all public)
 *
 * Cart storage:
 *   Logged-in: transient dd_cart_user_{user_id} (3-day TTL)
 *   Guest:     transient dd_cart_{session_id}   (3-day TTL)
 *   Session:   cookie dd_session (32-char hash, 7-day TTL)
 *
 * Cart item key: MD5 of product_id + variation + addons JSON
 *
 * Depends on (modules): NONE — architecture rule
 *
 * Last modified: v3.1.13
 */
?>

<?php
/**
 * Dish Dash – Cart
 *
 * Manages the customer cart using WP session / transients.
 * The cart is stored server-side for logged-in users and
 * synced with localStorage for guests.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DD_Cart {

    private string $cart_key;

    public function __construct() {
        $user_id        = get_current_user_id();
        $this->cart_key = $user_id
            ? "dd_cart_user_{$user_id}"
            : 'dd_cart_' . $this->get_session_id();
    }

    // ─────────────────────────────────────────
    //  SESSION ID (for guests)
    // ─────────────────────────────────────────
    private function get_session_id(): string {
        if ( ! isset( $_COOKIE['dd_session'] ) ) {
            $id = wp_generate_password( 32, false );
            setcookie( 'dd_session', $id, time() + DAY_IN_SECONDS * 7, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
            return $id;
        }
        return sanitize_text_field( $_COOKIE['dd_session'] );
    }

    // ─────────────────────────────────────────
    //  GET CART
    // ─────────────────────────────────────────
    public function get(): array {
        $cart = get_transient( $this->cart_key );
        return is_array( $cart ) ? $cart : [];
    }

    // ─────────────────────────────────────────
    //  ADD ITEM
    // ─────────────────────────────────────────
    public function add( array $item ): array {
        $cart = $this->get();

        $key = $this->item_key( $item );

        if ( isset( $cart[ $key ] ) ) {
            $cart[ $key ]['qty'] += absint( $item['qty'] ?? 1 );
        } else {
            $cart[ $key ] = [
                'id'        => absint( $item['id'] ),
                'name'      => sanitize_text_field( $item['name'] ),
                'price'     => (float) $item['price'],
                'qty'       => absint( $item['qty'] ?? 1 ),
                'image'     => esc_url_raw( $item['image'] ?? '' ),
                'variation' => sanitize_text_field( $item['variation'] ?? '' ),
                'addons'    => $this->sanitize_addons( $item['addons'] ?? [] ),
                'note'      => sanitize_textarea_field( $item['note'] ?? '' ),
            ];
        }

        $this->save( $cart );
        return $this->summary();
    }

    // ─────────────────────────────────────────
    //  UPDATE ITEM QTY
    // ─────────────────────────────────────────
    public function update( string $key, int $qty ): array {
        $cart = $this->get();

        if ( $qty <= 0 ) {
            unset( $cart[ $key ] );
        } elseif ( isset( $cart[ $key ] ) ) {
            $cart[ $key ]['qty'] = $qty;
        }

        $this->save( $cart );
        return $this->summary();
    }

    // ─────────────────────────────────────────
    //  REMOVE ITEM
    // ─────────────────────────────────────────
    public function remove( string $key ): array {
        $cart = $this->get();
        unset( $cart[ $key ] );
        $this->save( $cart );
        return $this->summary();
    }

    // ─────────────────────────────────────────
    //  CLEAR CART
    // ─────────────────────────────────────────
    public function clear(): void {
        delete_transient( $this->cart_key );
    }

    // ─────────────────────────────────────────
    //  SUMMARY (totals + item list)
    // ─────────────────────────────────────────
    public function summary(): array {
        $cart     = $this->get();
        $subtotal = 0;
        $count    = 0;

        foreach ( $cart as $item ) {
            $addon_total = array_sum( array_column( $item['addons'], 'price' ) );
            $subtotal   += ( $item['price'] + $addon_total ) * $item['qty'];
            $count      += $item['qty'];
        }

        $tax_rate = (float) DD_Settings::get( 'tax_rate', 0 ) / 100;
        $tax      = round( $subtotal * $tax_rate, 2 );

        $items = [];
        foreach ( $cart as $key => $item ) {
            $items[] = array_merge( $item, [ 'key' => $key ] );
        }

        return [
            'items'    => $items,
            'count'    => $count,
            'subtotal' => round( $subtotal, 2 ),
            'tax'      => $tax,
            'total'    => round( $subtotal + $tax, 2 ),
        ];
    }

    // ─────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────
    private function save( array $cart ): void {
        set_transient( $this->cart_key, $cart, DAY_IN_SECONDS * 3 );
    }

    private function item_key( array $item ): string {
        return md5( $item['id'] . ( $item['variation'] ?? '' ) . wp_json_encode( $item['addons'] ?? [] ) );
    }

    private function sanitize_addons( array $addons ): array {
        return array_map( function ( $addon ) {
            return [
                'name'  => sanitize_text_field( $addon['name'] ?? '' ),
                'price' => (float) ( $addon['price'] ?? 0 ),
            ];
        }, $addons );
    }

    // ─────────────────────────────────────────
    //  STATIC AJAX REGISTRATION
    // ─────────────────────────────────────────
    public static function register_ajax(): void {
        DD_Ajax::register( 'dd_cart_add',     [ self::class, 'ajax_add' ] );
        DD_Ajax::register( 'dd_cart_update',  [ self::class, 'ajax_update' ] );
        DD_Ajax::register( 'dd_cart_remove',  [ self::class, 'ajax_remove' ] );
        DD_Ajax::register( 'dd_cart_get',     [ self::class, 'ajax_get' ] );
        DD_Ajax::register( 'dd_cart_clear',   [ self::class, 'ajax_clear' ] );
    }

    public static function ajax_add(): void {
        DD_Ajax::verify_nonce();

        $product_id = (int) ( $_POST['product_id'] ?? $_POST['id'] ?? 0 );
        $quantity   = max( 1, (int) ( $_POST['quantity'] ?? 1 ) );

        if ( ! $product_id ) {
            wp_send_json_error( 'Missing product_id' ); return;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( 'Product not found' ); return;
        }

        $name  = $product->get_name();
        $price = (float) $product->get_price();
        $image = wp_get_attachment_url( $product->get_image_id() ) ?: wc_placeholder_img_src();

        $cart   = new self();
        $result = $cart->add( [
            'id'        => $product_id,
            'name'      => $name,
            'price'     => $price,
            'qty'       => $quantity,
            'image'     => $image,
            'variation' => sanitize_text_field( $_POST['variation'] ?? '' ),
            'addons'    => json_decode( stripslashes( $_POST['addons'] ?? '[]' ), true ),
            'note'      => sanitize_textarea_field( $_POST['note'] ?? '' ),
        ] );
        wp_send_json_success( $result );
    }

    public static function ajax_update(): void {
        DD_Ajax::verify_nonce();
        $cart   = new self();
        $result = $cart->update(
            sanitize_text_field( $_POST['key'] ?? '' ),
            absint( $_POST['qty'] ?? 0 )
        );
        wp_send_json_success( $result );
    }

    public static function ajax_remove(): void {
        DD_Ajax::verify_nonce();
        $cart   = new self();
        $result = $cart->remove( sanitize_text_field( $_POST['key'] ?? '' ) );
        wp_send_json_success( $result );
    }

    public static function ajax_get(): void {
        DD_Ajax::verify_nonce();
        $cart = new self();
        wp_send_json_success( $cart->summary() );
    }

    public static function ajax_clear(): void {
        DD_Ajax::verify_nonce();
        $cart = new self();
        $cart->clear();
        wp_send_json_success( [ 'items' => [], 'count' => 0, 'subtotal' => 0, 'tax' => 0, 'total' => 0 ] );
    }
}
