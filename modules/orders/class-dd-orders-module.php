<?php
/**
 * File:    modules/orders/class-dd-orders-module.php
 * Module:  DD_Orders_Module (extends DD_Module)
 * Purpose: Full order lifecycle management — placing orders, status
 *          transitions, email notifications, REST API routes, WooCommerce
 *          payment bridge (links WC order IDs to Dish Dash orders),
 *          and the Orders admin page.
 *
 * Dependencies (this file needs):
 *   - DD_Module base class
 *   - DD_Ajax::register() for AJAX handlers
 *   - $wpdb global (dishdash_orders, dishdash_order_items tables)
 *   - dishdash-core/class-dd-helpers.php (dd_generate_order_number, dd_price)
 *   - WooCommerce: woocommerce_order_status_* hooks (payment bridge)
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php (instantiates this module)
 *   - assets/js/cart.js (calls dd_place_order, dd_get_order, dd_cancel_order)
 *   - admin/pages/orders.php (loaded via render_orders())
 *
 * Hooks registered:
 *   - rest_api_init, admin_menu, admin_enqueue_scripts
 *   - woocommerce_order_status_completed → wc_payment_completed()
 *   - woocommerce_order_status_cancelled → wc_payment_cancelled()
 *
 * AJAX actions registered:
 *   dd_place_order (public), dd_get_order (public),
 *   dd_cancel_order (public), dd_update_status (admin only)
 *
 * REST routes: GET/PUT /dish-dash/v1/orders[/{id}[/status]] (admin only)
 *
 * Hooks fired:
 *   dish_dash_order_placed, dish_dash_order_status_changed,
 *   dish_dash_order_delivered
 *
 * DB tables owned:
 *   {prefix}dishdash_orders, {prefix}dishdash_order_items
 *
 * Depends on (modules): NONE — architecture rule
 *
 * Last modified: v3.1.13
 */
?>

<?php
/**
 * Dish Dash – Orders Module
 *
 * Handles order placement, status management,
 * WooCommerce bridge, and email notifications.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DD_Orders_Module extends DD_Module {

    protected string $id = 'orders';

    public function init(): void {
        // REST API endpoints
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );

        // AJAX handlers
        DD_Ajax::register( 'dd_place_order',    [ $this, 'ajax_place_order' ] );
        DD_Ajax::register( 'dd_get_order',      [ $this, 'ajax_get_order' ] );
        DD_Ajax::register( 'dd_cancel_order',   [ $this, 'ajax_cancel_order' ] );
        DD_Ajax::register( 'dd_update_status',  [ $this, 'ajax_update_status' ], false );

        // WooCommerce bridge — sync payment status
        add_action( 'woocommerce_order_status_completed', [ $this, 'wc_payment_completed' ] );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'wc_payment_cancelled' ] );

        // Admin assets
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Shortcodes
        add_shortcode( 'dish_dash_track',   [ $this, 'shortcode_track' ] );
        add_shortcode( 'dish_dash_account', [ $this, 'shortcode_account' ] );
    }

    // ─────────────────────────────────────────
    //  REST API ROUTES
    // ─────────────────────────────────────────
    public function register_routes(): void {
        register_rest_route( 'dish-dash/v1', '/orders', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_orders' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );

        register_rest_route( 'dish-dash/v1', '/orders/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_order' ],
            'permission_callback' => [ $this, 'order_permission' ],
        ] );

        register_rest_route( 'dish-dash/v1', '/orders/(?P<id>\d+)/status', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'rest_update_status' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );
    }

    // ─────────────────────────────────────────
    //  PLACE ORDER (main method)
    // ─────────────────────────────────────────
    public function place_order( array $data ): array|WP_Error {
        global $wpdb;

        // Validate required fields
        $required = [ 'customer_name', 'customer_phone', 'customer_email', 'order_type', 'items' ];
        foreach ( $required as $field ) {
            if ( empty( $data[ $field ] ) ) {
                return new WP_Error( 'missing_field', sprintf( __( 'Missing required field: %s', 'dish-dash' ), $field ) );
            }
        }

        if ( empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
            return new WP_Error( 'empty_cart', __( 'Your cart is empty.', 'dish-dash' ) );
        }

        // Calculate totals
        $totals = $this->calculate_totals( $data['items'], $data['delivery_fee'] ?? 0 );

        // Check minimum order
        $min_order = (float) DD_Settings::get( 'min_order', 0 );
        if ( $min_order > 0 && $totals['subtotal'] < $min_order ) {
            return new WP_Error(
                'min_order',
                sprintf( __( 'Minimum order is %s', 'dish-dash' ), dd_price( $min_order ) )
            );
        }

        // Generate order number
        $order_number = dd_generate_order_number();

        // Build order row
        $order_data = [
            'order_number'        => $order_number,
            'branch_id'           => absint( $data['branch_id'] ?? 1 ),
            'customer_id'         => get_current_user_id() ?: null,
            'customer_name'       => sanitize_text_field( $data['customer_name'] ),
            'customer_phone'      => sanitize_text_field( $data['customer_phone'] ),
            'customer_email'      => sanitize_email( $data['customer_email'] ),
            'order_type'          => dd_valid_order_type( $data['order_type'] ),
            'status'              => 'pending',
            'subtotal'            => $totals['subtotal'],
            'delivery_fee'        => $totals['delivery_fee'],
            'discount'            => $totals['discount'],
            'tip'                 => (float) ( $data['tip'] ?? 0 ),
            'tax'                 => $totals['tax'],
            'total'               => $totals['total'],
            'payment_method'      => sanitize_text_field( $data['payment_method'] ?? 'cod' ),
            'payment_status'      => 'unpaid',
            'delivery_address'    => isset( $data['delivery_address'] ) ? wp_json_encode( $data['delivery_address'] ) : null,
            'special_instructions' => sanitize_textarea_field( $data['special_instructions'] ?? '' ),
            'scheduled_at'        => ! empty( $data['scheduled_at'] ) ? sanitize_text_field( $data['scheduled_at'] ) : null,
        ];

        // Insert order
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'dishdash_orders',
            $order_data,
            array_fill( 0, count( $order_data ), '%s' )
        );

        if ( ! $inserted ) {
            return new WP_Error( 'db_error', __( 'Failed to place order. Please try again.', 'dish-dash' ) );
        }

        $order_id = $wpdb->insert_id;

        // Insert order items
        $this->insert_order_items( $order_id, $data['items'] );

        // Fire action hook
        do_action( 'dish_dash_order_placed', $order_id, $order_data );

        // Send notifications
        $this->send_order_confirmation( $order_id );
        $this->notify_restaurant( $order_id );

        return [
            'order_id'     => $order_id,
            'order_number' => $order_number,
            'total'        => $totals['total'],
            'track_url'    => dd_track_url( $order_number ),
        ];
    }

    // ─────────────────────────────────────────
    //  INSERT ORDER ITEMS
    // ─────────────────────────────────────────
    private function insert_order_items( int $order_id, array $items ): void {
        global $wpdb;

        foreach ( $items as $item ) {
            $menu_item_id = absint( $item['id'] ?? 0 );
            $qty          = absint( $item['qty'] ?? 1 );
            $price        = (float) ( $item['price'] ?? 0 );
            $addons       = $item['addons'] ?? [];
            $addon_total  = array_sum( array_column( $addons, 'price' ) );
            $line_total   = ( $price + $addon_total ) * $qty;

            $wpdb->insert(
                $wpdb->prefix . 'dishdash_order_items',
                [
                    'order_id'     => $order_id,
                    'menu_item_id' => $menu_item_id,
                    'item_name'    => sanitize_text_field( $item['name'] ?? '' ),
                    'quantity'     => $qty,
                    'unit_price'   => $price,
                    'addons'       => ! empty( $addons ) ? wp_json_encode( $addons ) : null,
                    'variation'    => sanitize_text_field( $item['variation'] ?? '' ),
                    'special_note' => sanitize_textarea_field( $item['note'] ?? '' ),
                    'line_total'   => $line_total,
                ],
                [ '%d', '%d', '%s', '%d', '%f', '%s', '%s', '%s', '%f' ]
            );
        }
    }

    // ─────────────────────────────────────────
    //  CALCULATE TOTALS
    // ─────────────────────────────────────────
    public function calculate_totals( array $items, float $delivery_fee = 0 ): array {
        $subtotal = 0;

        foreach ( $items as $item ) {
            $price       = (float) ( $item['price'] ?? 0 );
            $qty         = absint( $item['qty'] ?? 1 );
            $addon_total = array_sum( array_column( $item['addons'] ?? [], 'price' ) );
            $subtotal   += ( $price + $addon_total ) * $qty;
        }

        $tax_rate = (float) DD_Settings::get( 'tax_rate', 0 ) / 100;
        $tax      = round( $subtotal * $tax_rate, 2 );
        $total    = $subtotal + $delivery_fee + $tax;

        return [
            'subtotal'     => round( $subtotal, 2 ),
            'delivery_fee' => round( $delivery_fee, 2 ),
            'discount'     => 0,
            'tax'          => $tax,
            'total'        => round( $total, 2 ),
        ];
    }

    // ─────────────────────────────────────────
    //  GET ORDER
    // ─────────────────────────────────────────
    public function get_order( int $order_id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dishdash_orders WHERE id = %d",
                $order_id
            )
        );
    }

    public function get_order_items( int $order_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dishdash_order_items WHERE order_id = %d",
                $order_id
            )
        );
    }

    public function get_orders( array $args = [] ): array {
        global $wpdb;

        $where  = '1=1';
        $values = [];

        if ( ! empty( $args['status'] ) ) {
            $where   .= ' AND status = %s';
            $values[] = $args['status'];
        }

        if ( ! empty( $args['branch_id'] ) ) {
            $where   .= ' AND branch_id = %d';
            $values[] = $args['branch_id'];
        }

        if ( ! empty( $args['customer_id'] ) ) {
            $where   .= ' AND customer_id = %d';
            $values[] = $args['customer_id'];
        }

        $limit  = absint( $args['limit'] ?? 20 );
        $offset = absint( $args['offset'] ?? 0 );

        $sql = "SELECT * FROM {$wpdb->prefix}dishdash_orders WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
    }

    // ─────────────────────────────────────────
    //  UPDATE ORDER STATUS
    // ─────────────────────────────────────────
    public function update_status( int $order_id, string $new_status ): bool {
        global $wpdb;

        $order = $this->get_order( $order_id );
        if ( ! $order ) return false;

        $old_status = $order->status;

        // Validate transition
        $allowed = dd_order_status_transitions()[ $old_status ] ?? [];
        if ( ! in_array( $new_status, $allowed, true ) ) {
            return false;
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'dishdash_orders',
            [ 'status' => $new_status ],
            [ 'id'     => $order_id ],
            [ '%s' ],
            [ '%d' ]
        );

        if ( $updated ) {
            do_action( 'dish_dash_order_status_changed', $order_id, $old_status, $new_status );

            if ( 'delivered' === $new_status ) {
                do_action( 'dish_dash_order_delivered', $order_id );
            }

            // Notify customer of status change
            $this->send_status_update( $order_id, $new_status );
        }

        return (bool) $updated;
    }

    // ─────────────────────────────────────────
    //  WOOCOMMERCE BRIDGE
    // ─────────────────────────────────────────
    public function wc_payment_completed( int $wc_order_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'dishdash_orders',
            [ 'payment_status' => 'paid' ],
            [ 'wc_order_id'    => $wc_order_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    public function wc_payment_cancelled( int $wc_order_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'dishdash_orders',
            [ 'payment_status' => 'unpaid', 'status' => 'cancelled' ],
            [ 'wc_order_id'    => $wc_order_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    // ─────────────────────────────────────────
    //  EMAIL NOTIFICATIONS
    // ─────────────────────────────────────────
    private function send_order_confirmation( int $order_id ): void {
        $order = $this->get_order( $order_id );
        if ( ! $order ) return;

        $items   = $this->get_order_items( $order_id );
        $subject = sprintf( __( 'Order Confirmed — %s', 'dish-dash' ), $order->order_number );

        $message = $this->get_email_template( 'order-confirmation', [
            'order' => $order,
            'items' => $items,
        ] );

        wp_mail(
            $order->customer_email,
            $subject,
            $message,
            [ 'Content-Type: text/html; charset=UTF-8' ]
        );
    }

    private function notify_restaurant( int $order_id ): void {
        $order   = $this->get_order( $order_id );
        if ( ! $order ) return;

        $items   = $this->get_order_items( $order_id );
        $to      = get_option( 'admin_email' );
        $subject = sprintf( __( 'New Order Received — %s', 'dish-dash' ), $order->order_number );

        $message = $this->get_email_template( 'new-order-admin', [
            'order' => $order,
            'items' => $items,
        ] );

        wp_mail( $to, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    private function send_status_update( int $order_id, string $status ): void {
        $order = $this->get_order( $order_id );
        if ( ! $order || empty( $order->customer_email ) ) return;

        $subject = sprintf(
            __( 'Your Order %s is now %s', 'dish-dash' ),
            $order->order_number,
            dd_order_status_label( $status )
        );

        $message = $this->get_email_template( 'order-status-update', [
            'order'  => $order,
            'status' => $status,
        ] );

        wp_mail(
            $order->customer_email,
            $subject,
            $message,
            [ 'Content-Type: text/html; charset=UTF-8' ]
        );
    }

    private function get_email_template( string $template, array $vars = [] ): string {
        return $this->get_template( "emails/{$template}.php", $vars );
    }

    // ─────────────────────────────────────────
    //  AJAX HANDLERS
    // ─────────────────────────────────────────
    public function ajax_place_order(): void {
        DD_Ajax::verify_nonce();

        $data = [
            'customer_name'        => sanitize_text_field( $_POST['customer_name'] ?? '' ),
            'customer_phone'       => sanitize_text_field( $_POST['customer_phone'] ?? '' ),
            'customer_email'       => sanitize_email( $_POST['customer_email'] ?? '' ),
            'order_type'           => sanitize_text_field( $_POST['order_type'] ?? 'delivery' ),
            'items'                => json_decode( stripslashes( $_POST['items'] ?? '[]' ), true ),
            'delivery_fee'         => (float) ( $_POST['delivery_fee'] ?? 0 ),
            'tip'                  => (float) ( $_POST['tip'] ?? 0 ),
            'payment_method'       => sanitize_text_field( $_POST['payment_method'] ?? 'cod' ),
            'special_instructions' => sanitize_textarea_field( $_POST['special_instructions'] ?? '' ),
            'delivery_address'     => json_decode( stripslashes( $_POST['delivery_address'] ?? '{}' ), true ),
            'scheduled_at'         => sanitize_text_field( $_POST['scheduled_at'] ?? '' ),
            'branch_id'            => absint( $_POST['branch_id'] ?? 1 ),
        ];

        $result = $this->place_order( $data );

        if ( is_wp_error( $result ) ) {
            $this->json_error( $result->get_error_message() );
        }

        $this->json_success( $result, __( 'Order placed successfully!', 'dish-dash' ) );
    }

    public function ajax_get_order(): void {
        DD_Ajax::verify_nonce();
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = $this->get_order( $order_id );

        if ( ! $order ) {
            $this->json_error( __( 'Order not found.', 'dish-dash' ) );
        }

        $this->json_success( [
            'order' => $order,
            'items' => $this->get_order_items( $order_id ),
        ] );
    }

    public function ajax_cancel_order(): void {
        DD_Ajax::verify_nonce();
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $result   = $this->update_status( $order_id, 'cancelled' );

        if ( ! $result ) {
            $this->json_error( __( 'Cannot cancel this order.', 'dish-dash' ) );
        }

        $this->json_success( null, __( 'Order cancelled.', 'dish-dash' ) );
    }

    public function ajax_update_status(): void {
        DD_Ajax::verify_nonce( 'nonce', 'dish_dash_admin' );

        if ( ! current_user_can( 'dd_manage_orders' ) ) {
            $this->json_error( __( 'Permission denied.', 'dish-dash' ), 403 );
        }

        $order_id   = absint( $_POST['order_id'] ?? 0 );
        $new_status = sanitize_text_field( $_POST['status'] ?? '' );
        $result     = $this->update_status( $order_id, $new_status );

        if ( ! $result ) {
            $this->json_error( __( 'Cannot update status.', 'dish-dash' ) );
        }

        $this->json_success( [ 'status' => $new_status ], __( 'Status updated.', 'dish-dash' ) );
    }

    // ─────────────────────────────────────────
    //  REST HANDLERS
    // ─────────────────────────────────────────
    public function rest_get_orders( WP_REST_Request $request ): WP_REST_Response {
        $orders = $this->get_orders( [
            'status'    => $request->get_param( 'status' ),
            'branch_id' => $request->get_param( 'branch_id' ),
            'limit'     => $request->get_param( 'limit' ) ?? 20,
        ] );
        return new WP_REST_Response( $orders, 200 );
    }

    public function rest_get_order( WP_REST_Request $request ): WP_REST_Response {
        $order = $this->get_order( (int) $request->get_param( 'id' ) );
        if ( ! $order ) {
            return new WP_REST_Response( [ 'message' => 'Not found' ], 404 );
        }
        return new WP_REST_Response( [
            'order' => $order,
            'items' => $this->get_order_items( $order->id ),
        ], 200 );
    }

    public function rest_update_status( WP_REST_Request $request ): WP_REST_Response {
        $result = $this->update_status(
            (int) $request->get_param( 'id' ),
            sanitize_text_field( $request->get_param( 'status' ) )
        );
        return new WP_REST_Response( [ 'success' => $result ], $result ? 200 : 400 );
    }

    // ─────────────────────────────────────────
    //  PERMISSIONS
    // ─────────────────────────────────────────
    public function admin_permission(): bool {
        return current_user_can( 'dd_manage_orders' );
    }

    public function order_permission( WP_REST_Request $request ): bool {
        if ( current_user_can( 'dd_manage_orders' ) ) return true;
        $order = $this->get_order( (int) $request->get_param( 'id' ) );
        return $order && (int) $order->customer_id === get_current_user_id();
    }

    // ─────────────────────────────────────────
    //  ADMIN ASSETS
    // ─────────────────────────────────────────
    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'dish-dash-orders' ) === false ) return;
        $this->enqueue_style( 'orders-admin', 'admin-orders.css' );
        $this->enqueue_script( 'orders-admin', 'admin-orders.js', [ 'jquery' ] );
    }

    // ─────────────────────────────────────────
    //  SHORTCODES
    // ─────────────────────────────────────────
    public function shortcode_track( $atts ): string {
        $order_number = sanitize_text_field( $_GET['order'] ?? '' );
        return $this->get_template( 'orders/track.php', [ 'order_number' => $order_number ] );
    }

    public function shortcode_account( $atts ): string {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Please log in to view your orders.', 'dish-dash' ) . '</p>';
        }
        $orders = $this->get_orders( [ 'customer_id' => get_current_user_id(), 'limit' => 10 ] );
        return $this->get_template( 'orders/account.php', [ 'orders' => $orders ] );
    }
}
