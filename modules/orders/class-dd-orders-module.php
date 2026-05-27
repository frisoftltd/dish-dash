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

require_once plugin_dir_path( __FILE__ ) . 'class-dd-notifications.php';
require_once plugin_dir_path( __FILE__ ) . 'class-dd-customer-manager.php';

class DD_Orders_Module extends DD_Module {

    protected string $id = 'orders';

    /** Gateway IDs that do NOT require online payment redirect */
    private const OFFLINE_GATEWAYS = [ 'cod', 'bacs', 'cheque' ];

    public function init(): void {
        DD_Customer_Manager::register_hooks();

        // REST API endpoints
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );

        // AJAX handlers
        DD_Ajax::register( 'dd_place_order',    [ $this, 'ajax_place_order' ] );
        DD_Ajax::register( 'dd_get_order',      [ $this, 'ajax_get_order' ] );
        DD_Ajax::register( 'dd_cancel_order',   [ $this, 'ajax_cancel_order' ] );
        DD_Ajax::register( 'dd_update_status',  [ $this, 'ajax_update_status' ], false );
        DD_Ajax::register( 'dd_toggle_test',         [ $this, 'ajax_toggle_test' ],         false );
        DD_Ajax::register( 'dd_poll_notifications', [ $this, 'ajax_poll_notifications' ], false );

        // WooCommerce bridge — sync payment status
        add_action( 'woocommerce_order_status_completed', [ $this, 'wc_payment_completed' ] );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'wc_payment_cancelled' ] );

        // Online gateway notifications — fires after Pesapal/DPO payment confirmed
        add_action( 'woocommerce_payment_complete', [ 'DD_Notifications', 'on_payment_complete' ] );

        // Branded thank-you page for online gateway orders
        add_action( 'woocommerce_thankyou', [ $this, 'on_order_received_page' ] );

        // Birthday WhatsApp — fired by WP-Cron 2 min after first order
        add_action( 'dd_send_birthday_whatsapp', [ $this, 'send_birthday_whatsapp' ], 10, 3 );

        // Admin assets
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Shortcodes
        add_shortcode( 'dish_dash_track',   [ $this, 'shortcode_track' ] );
        add_shortcode( 'dish_dash_account', [ $this, 'shortcode_account' ] );
    }

    // ─────────────────────────────────────────
    //  BIRTHDAY WHATSAPP (WP-CRON)
    // ─────────────────────────────────────────

    /**
     * Fired by WP-Cron 2 minutes after first order.
     * Generates birthday token + stores wa.me URL as transient.
     * Never fires twice — guarded by dd_birthday_asked flag.
     */
    public function send_birthday_whatsapp(
        int    $customer_id,
        string $whatsapp,
        string $name
    ): void {
        global $wpdb;

        // Guard — never send twice
        $asked = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT dd_birthday_asked FROM {$wpdb->prefix}dishdash_customers WHERE id = %d",
            $customer_id
        ) );
        if ( $asked ) return;

        $phone = DD_Customer_Manager::normalize_phone( $whatsapp );
        if ( ! $phone ) return;

        // Generate token
        $token        = DD_Customer_Manager::generate_birthday_token( $customer_id );
        $birthday_url = home_url( '/birthday/?c=' . $token );
        $first_name   = explode( ' ', trim( $name ) )[0] ?: $name;

        // Build message
        $msg = implode( "\n", [
            '🎁 One more thing, ' . $first_name . '!',
            'We\'d love to surprise you on your birthday.',
            'Share it here (10 sec):',
            '👉 ' . $birthday_url,
            '— Khana Khazana 🍽',
        ] );

        $wa_url = 'https://wa.me/' . $phone . '?text=' . rawurlencode( $msg );

        // Store as transient — JS picks it up on next page load via cookie
        set_transient(
            'dd_birthday_wa_' . $customer_id,
            $wa_url,
            2 * HOUR_IN_SECONDS
        );

        // Mark asked — prevents repeat
        DD_Customer_Manager::mark_birthday_asked( $customer_id );
    }

    // ─────────────────────────────────────────
    //  ONLINE GATEWAY HELPERS
    // ─────────────────────────────────────────

    /**
     * Create a WooCommerce order from DD cart data.
     * Used for online payment gateways only.
     *
     * @param array  $data         Validated order data (customer_name, whatsapp, delivery_address, payment_method)
     * @param array  $cart_items   Items from DD_Cart::summary()['items']
     * @param float  $delivery_fee Calculated delivery fee
     * @return WC_Order|WP_Error
     */
    private function create_wc_order( array $data, array $cart_items, float $delivery_fee ) {
        $order = wc_create_order();
        if ( is_wp_error( $order ) ) return $order;

        // Add items
        foreach ( $cart_items as $item ) {
            $product = wc_get_product( $item['id'] );
            if ( ! $product ) continue;
            $order->add_product( $product, (int) $item['qty'] );
        }

        // Set addresses
        $name_parts = explode( ' ', trim( $data['customer_name'] ), 2 );
        $address = [
            'first_name' => $name_parts[0] ?? $data['customer_name'],
            'last_name'  => $name_parts[1] ?? '',
            'phone'      => $data['whatsapp'],
            'address_1'  => $data['delivery_address'],
            'country'    => 'RW',
        ];
        $order->set_address( $address, 'billing' );
        $order->set_address( $address, 'shipping' );

        // Set payment method
        $order->set_payment_method( $data['payment_method'] );

        // Add delivery fee as shipping line
        if ( $delivery_fee > 0 ) {
            $shipping = new WC_Order_Item_Shipping();
            $shipping->set_name( 'Delivery' );
            $shipping->set_total( $delivery_fee );
            $order->add_item( $shipping );
        }

        // Calculate and save
        $order->calculate_totals();
        $order->set_status( 'pending' );
        $order->save();

        return $order;
    }

    /**
     * Fires on WC order-received (thank-you) page.
     * Retrieves stored wa.me URL and injects auto-redirect JS.
     * Also marks DD order as paid.
     *
     * @param int $wc_order_id
     */
    public function on_order_received_page( int $wc_order_id ): void {
        $wc_order = wc_get_order( $wc_order_id );
        if ( ! $wc_order ) return;

        // Only handle orders placed through DishDash
        $dd_order_number = $wc_order->get_meta( '_dd_order_number' );
        $dd_order_id     = $wc_order->get_meta( '_dd_order_id' );
        if ( ! $dd_order_number ) return;

        // Update DD order status to processing
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'dishdash_orders',
            [
                'status'         => 'processing',
                'payment_status' => 'paid',
            ],
            [ 'id' => (int) $dd_order_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        // Get wa.me URL from transient
        $whatsapp_url = get_transient( 'dd_whatsapp_' . $wc_order_id );
        if ( $whatsapp_url ) {
            delete_transient( 'dd_whatsapp_' . $wc_order_id );
        }

        $eta = get_option( 'dd_delivery_eta', '30–45 minutes' );
        ?>
        <style>
            /* Hide default WC thank-you content */
            .woocommerce-order { display: none !important; }
        </style>
        <div class="dd-payment-confirmed">
            <div class="dd-confirm-panel">
                <div class="dd-confirm-panel__icon">&#9989;</div>
                <h2 class="dd-confirm-panel__title"><?php esc_html_e( 'Payment Confirmed!', 'dish-dash' ); ?></h2>
                <p class="dd-confirm-panel__order-num">Order #<?php echo esc_html( $dd_order_number ); ?></p>
                <p class="dd-confirm-panel__eta">&#128757; <?php esc_html_e( 'Estimated delivery:', 'dish-dash' ); ?> <?php echo esc_html( $eta ); ?></p>
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="dd-confirm-panel__close">
                    <?php esc_html_e( 'Back to Menu', 'dish-dash' ); ?>
                </a>
            </div>
        </div>
        <?php if ( $whatsapp_url ) : ?>
        <script>
            setTimeout( function() {
                window.location.href = <?php echo wp_json_encode( $whatsapp_url ); ?>;
            }, 800 );
        </script>
        <?php endif;
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
        $required = [ 'customer_name', 'customer_phone', 'order_type', 'items' ];
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

        // Customer email confirmation (no-ops when customer_email is empty)
        $this->send_order_confirmation( $order_id );
        // Restaurant notification now handled by DD_Notifications::on_order_created()
        // called from ajax_place_order() — not here, to avoid double-firing

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

        // ─── 1. Read & validate form fields ───────────────────────────
        $customer_name    = sanitize_text_field( $_POST['customer_name']    ?? '' );
        $whatsapp         = sanitize_text_field( $_POST['whatsapp']         ?? '' );
        $delivery_address = sanitize_text_field( $_POST['delivery_address'] ?? '' );
        $payment_method   = sanitize_text_field( $_POST['payment_method']   ?? 'pay_on_delivery' );

        if ( ! $customer_name ) {
            wp_send_json_error( [ 'message' => __( 'Please enter your full name.', 'dish-dash' ) ] );
            return;
        }
        if ( ! $whatsapp ) {
            wp_send_json_error( [ 'message' => __( 'Please enter your WhatsApp number.', 'dish-dash' ) ] );
            return;
        }
        if ( ! $delivery_address ) {
            wp_send_json_error( [ 'message' => __( 'Please enter your delivery address.', 'dish-dash' ) ] );
            return;
        }

        // ─── 2. Get cart from server (never trust client items) ────────
        $cart    = new DD_Cart();
        $summary = $cart->summary();

        if ( empty( $summary['items'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Your cart is empty.', 'dish-dash' ) ] );
            return;
        }

        // ─── 3. Calculate delivery fee server-side ─────────────────────
        $threshold    = (float) get_option( 'dd_free_delivery_threshold', 10000 );
        $delivery_fee = $summary['subtotal'] >= $threshold
            ? 0.0
            : (float) get_option( 'dd_delivery_fee', 1500 );

        // ─── 4. Branch: online vs offline gateway ─────────────────────
        $is_online = ! in_array( $payment_method, self::OFFLINE_GATEWAYS, true );

        if ( $is_online ) {
            // --- ONLINE GATEWAY FLOW ---
            // Create DD order first (status: pending_payment)
            $result = $this->place_order( [
                'customer_name'    => $customer_name,
                'customer_phone'   => $whatsapp,
                'customer_email'   => '',
                'order_type'       => 'delivery',
                'delivery_address' => $delivery_address,
                'payment_method'   => $payment_method,
                'delivery_fee'     => $delivery_fee,
                'items'            => $summary['items'],
            ] );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ] );
                return;
            }

            // Create WC order and link to DD order
            $wc_order = $this->create_wc_order(
                [
                    'customer_name'    => $customer_name,
                    'whatsapp'         => $whatsapp,
                    'delivery_address' => $delivery_address,
                    'payment_method'   => $payment_method,
                ],
                $summary['items'],
                $delivery_fee
            );

            if ( is_wp_error( $wc_order ) ) {
                wp_send_json_error( [ 'message' => 'Could not create payment order. Please try again.' ] );
                return;
            }

            // Link WC order to DD order
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'dishdash_orders',
                [ 'wc_order_id' => $wc_order->get_id() ],
                [ 'id'          => $result['order_id'] ],
                [ '%d' ], [ '%d' ]
            );

            // Store DD order number on WC order for retrieval on thank-you page
            $wc_order->update_meta_data( '_dd_order_number', $result['order_number'] );
            $wc_order->update_meta_data( '_dd_order_id',     $result['order_id'] );
            $wc_order->save();

            // Clear cart
            $cart->clear();

            // Return payment URL — JS will redirect
            wp_send_json_success( [
                'redirect'     => true,
                'payment_url'  => $wc_order->get_checkout_payment_url(),
                'order_number' => $result['order_number'],
            ] );
            return;
        }

        // ─── OFFLINE GATEWAY FLOW ──────────────────────────────────────
        // ─── 4. Place order ────────────────────────────────────────────
        $result = $this->place_order( [
            'customer_name'    => $customer_name,
            'customer_phone'   => $whatsapp,
            'customer_email'   => '',
            'order_type'       => 'delivery',
            'items'            => $summary['items'],
            'delivery_fee'     => $delivery_fee,
            'payment_method'   => $payment_method,
            'delivery_address' => $delivery_address,
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
            return;
        }

        // ─── 5. Clear cart ─────────────────────────────────────────────
        $cart->clear();

        // ─── 6. Fire notifications ─────────────────────────────────────
        $notification_data = [
            'order_number'     => $result['order_number'],
            'customer_name'    => $customer_name,
            'customer_phone'   => $whatsapp,
            'delivery_address' => $delivery_address,
            'payment_method'   => $payment_method,
            'subtotal'         => $summary['subtotal'],
            'delivery_fee'     => $delivery_fee,
            'total'            => $result['total'],
            'items'            => array_values( array_map( function ( $item ) {
                return [
                    'name'  => $item['name'],
                    'qty'   => (int) ( $item['qty'] ?? 1 ),
                    'price' => (float) $item['price'],
                ];
            }, $summary['items'] ) ),
        ];

        $notification_urls = DD_Notifications::on_order_created( $notification_data );

        // ─── 7. Customer upsert + birthday flow ───────────────────────
        $customer_result = DD_Customer_Manager::upsert(
            $whatsapp,
            $customer_name,
            $delivery_address,
            (float) $result['total']
        );

        // Schedule birthday WhatsApp — first order only, never repeats
        if (
            $customer_result['is_first_order'] &&
            $customer_result['customer_id'] > 0
        ) {
            wp_schedule_single_event(
                time() + 120,
                'dd_send_birthday_whatsapp',
                [
                    $customer_result['customer_id'],
                    $whatsapp,
                    $customer_name,
                ]
            );
        }

        // ─── 8. Respond ────────────────────────────────────────────────
        wp_send_json_success( array_merge( $result, [
            'eta'                   => get_option( 'dd_delivery_eta', '30–45 minutes' ),
            'whatsapp_url'          => $notification_urls['admin_url'],
            'whatsapp_customer_url' => $notification_urls['customer_url'],
            'customer_id'           => $customer_result['customer_id'] ?? 0,
        ] ) );
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

    public function ajax_toggle_test(): void {
        DD_Ajax::verify_nonce( 'nonce', 'dish_dash_admin' );

        if ( ! current_user_can( 'dd_manage_orders' ) ) {
            $this->json_error( __( 'Permission denied.', 'dish-dash' ), 403 );
        }

        global $wpdb;
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $is_test  = (int) ( $_POST['is_test'] ?? 0 );

        $wpdb->update(
            $wpdb->prefix . 'dishdash_orders',
            [ 'is_test' => $is_test ? 1 : 0 ],
            [ 'id' => $order_id ],
            [ '%d' ],
            [ '%d' ]
        );

        $this->json_success( [ 'is_test' => $is_test ] );
    }

    /**
     * Poll for new orders and reservations since last known IDs.
     * Called every 30s by admin JS. Lightweight — no full page load.
     */
    public function ajax_poll_notifications(): void {
        DD_Ajax::verify_nonce( 'nonce', 'dish_dash_admin' );

        if ( ! current_user_can( 'manage_options' ) ) {
            $this->json_error( 'Permission denied.', 403 );
        }

        global $wpdb;

        $last_order_id = absint( $_POST['last_order_id'] ?? 0 );
        $last_res_id   = absint( $_POST['last_res_id'] ?? 0 );

        // New orders since last known ID
        $new_orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, order_number, customer_name, total
             FROM {$wpdb->prefix}dishdash_orders
             WHERE id > %d AND is_test = 0
             ORDER BY id ASC LIMIT 10",
            $last_order_id
        ), ARRAY_A );

        // New reservations since last known ID
        $new_reservations = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, date, time, guests
             FROM {$wpdb->prefix}dishdash_reservations
             WHERE id > %d
             ORDER BY id ASC LIMIT 10",
            $last_res_id
        ), ARRAY_A );

        // Current max IDs for client to store
        $max_order_id = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}dishdash_orders WHERE is_test = 0" );
        $max_res_id   = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}dishdash_reservations" );

        $this->json_success( [
            'new_orders'       => $new_orders,
            'new_reservations' => $new_reservations,
            'max_order_id'     => $max_order_id,
            'max_res_id'       => $max_res_id,
        ] );
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
