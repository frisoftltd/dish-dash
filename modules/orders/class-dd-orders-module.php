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
 *   - assets/js/cart.js (calls dd_place_order, dd_get_order)
 *   - admin/pages/orders.php (loaded via render_orders())
 *
 * Hooks registered:
 *   - rest_api_init, admin_menu, admin_enqueue_scripts
 *   - woocommerce_order_status_completed → wc_payment_completed()
 *   - woocommerce_order_status_cancelled → wc_payment_cancelled()
 *
 * AJAX actions registered:
 *   dd_place_order (public), dd_get_order (public),
 *   dd_update_status (admin only)
 *   (dd_cancel_order removed in v3.10.29 — write-path IDOR, zero callers)
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
require_once plugin_dir_path( __FILE__ ) . 'class-dd-customer-profile.php';

class DD_Orders_Module extends DD_Module {

    protected string $id = 'orders';

    /** Gateway IDs that do NOT require online payment redirect */
    private const OFFLINE_GATEWAYS = [ 'cod', 'bacs', 'cheque' ];

    public function init(): void {
        DD_Customer_Manager::register_hooks();

        // REST API endpoints
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );

        // AJAX handlers
        DD_Ajax::register( 'dd_place_order',       [ $this, 'ajax_place_order' ] );
        DD_Ajax::register( 'dd_get_order',         [ $this, 'ajax_get_order' ] );
        // dd_cancel_order deregistered in v3.10.29 — orphaned, guest-reachable,
        // no capability/ownership check (write-path IDOR). Admin cancellation
        // uses the separately-gated dd_update_status. Method kept as dead code.
        DD_Ajax::register( 'dd_update_status',     [ $this, 'ajax_update_status' ], false );
        DD_Ajax::register( 'dd_momo_check_status',   [ $this, 'ajax_momo_check_status' ],   true );
        DD_Ajax::register( 'dd_momo_claim_paid',     [ $this, 'ajax_momo_claim_paid' ],     true );
        DD_Ajax::register( 'dd_irembopay_confirm',   [ $this, 'ajax_irembopay_confirm' ],   true );
        DD_Ajax::register( 'dd_pesapal_check_status', [ $this, 'ajax_pesapal_check_status' ], false );
        DD_Ajax::register( 'dd_toggle_test',         [ $this, 'ajax_toggle_test' ],         false );
        DD_Ajax::register( 'dd_poll_notifications', [ $this, 'ajax_poll_notifications' ], false );
        add_action( 'wp_ajax_dd_mark_notifications_read', [ $this, 'ajax_mark_notifications_read' ] );
        add_action( 'wp_ajax_dd_kitchen_queue',           [ $this, 'ajax_kitchen_queue' ] );
        add_action( 'wp_ajax_dd_mark_month_paid',         [ $this, 'ajax_mark_month_paid' ] );

        // WooCommerce bridge — sync payment status
        add_action( 'woocommerce_order_status_completed', [ $this, 'wc_payment_completed' ] );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'wc_payment_cancelled' ] );

        // Online gateway notifications — fires after Pesapal/DPO payment confirmed
        add_action( 'woocommerce_payment_complete', [ 'DD_Notifications', 'on_payment_complete' ] );

        // PesaPal server-to-server IPN — authoritative order creation.
        // PesaPal posts to callback_url = home_url('/wc-api/wc_pesapal_gateway/'),
        // which WooCommerce routes to this action. The client-side status poll
        // (dd_pesapal_check_status) remains as a fast-path but is no longer
        // load-bearing; both share one idempotent creation routine.
        add_action( 'woocommerce_api_wc_pesapal_gateway', [ $this, 'handle_pesapal_ipn' ] );

        // Branded thank-you page for online gateway orders
        add_action( 'woocommerce_thankyou', [ $this, 'on_order_received_page' ] );

        // Birthday WhatsApp — fired by WP-Cron 2 min after first order
        add_action( 'dd_send_birthday_whatsapp', [ $this, 'send_birthday_whatsapp' ], 10, 3 );

        // Admin assets
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Shortcodes
        add_shortcode( 'dish_dash_track',   [ $this, 'shortcode_track' ] );
        // [dish_dash_account] registration removed in v3.10.44 (R1) — vestigial account page retired.
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
        $restaurant = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );
        $msg = implode( "\n", [
            '🎁 One more thing, ' . $first_name . '!',
            'We\'d love to surprise you on your birthday.',
            'Share it here (10 sec):',
            '👉 ' . $birthday_url,
            '— ' . $restaurant . ' 🍽',
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

        // Internal tracking only — NOT shown to customer, NOT deducted from total
        $order->update_meta_data( '_dd_platform_fee', absint( get_option( 'dd_per_order_fee', 750 ) ) );
        $order->save_meta_data();

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
        $old_dd_status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}dishdash_orders WHERE id = %d",
            (int) $dd_order_id
        ) );
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
        if ( $old_dd_status ) {
            self::recalculate_fee_for_status_change( (int) $dd_order_id, $old_dd_status, 'processing' );
        }

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

        // Snapshot platform fee at order time — stored for month-end invoicing
        $fees_enabled    = get_option( 'dd_fees_enabled', '1' ) === '1';
        $dd_platform_fee = $fees_enabled ? absint( get_option( 'dd_per_order_fee', 750 ) ) : 0;

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
            'platform_fee'        => $dd_platform_fee,
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

        // Stamp platform fee — column declared in install.php, added by auto-migration guard
        $wpdb->update(
            $wpdb->prefix . 'dishdash_orders',
            [ 'platform_fee' => $dd_platform_fee ],
            [ 'id'           => $order_id ],
            [ '%d' ],
            [ '%d' ]
        );

        // Insert order items
        $this->insert_order_items( $order_id, $data['items'] );

        // Pass platform_fee in hook payload so listeners can read it
        $order_data['platform_fee'] = $dd_platform_fee;

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

        $now = current_time( 'mysql' );

        $timestamp_col = null;
        switch ( $new_status ) {
            case 'confirmed': $timestamp_col = 'confirmed_at'; break;
            case 'ready':     $timestamp_col = 'ready_at';     break;
            case 'delivered': $timestamp_col = 'delivered_at'; break;
            case 'cancelled': $timestamp_col = 'cancelled_at'; break;
        }

        $update_data   = [ 'status' => $new_status ];
        $update_format = [ '%s' ];

        if ( $timestamp_col ) {
            $update_data[ $timestamp_col ] = $now;
            $update_format[]               = '%s';
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'dishdash_orders',
            $update_data,
            [ 'id' => $order_id ],
            $update_format,
            [ '%d' ]
        );

        if ( $updated ) {
            do_action( 'dish_dash_order_status_changed', $order_id, $old_status, $new_status );

            if ( 'delivered' === $new_status ) {
                do_action( 'dish_dash_order_delivered', $order_id );
            }

            // Notify customer of status change
            $this->send_status_update( $order_id, $new_status );

            self::recalculate_fee_for_status_change( $order_id, $old_status, $new_status );
        }

        return (bool) $updated;
    }

    // ─────────────────────────────────────────
    //  FEE RECALCULATION
    // ─────────────────────────────────────────
    /**
     * Recalculate platform_fee when an order status changes.
     *
     * Policy:
     *  - Cancel: zero the fee
     *  - Revert from delivered: zero the fee
     *  - Re-deliver after revert (fee currently 0): restore from dd_per_order_fee
     *  - All other transitions: no-op
     *
     * Idempotent: if fee is already at the target value, skips the DB write.
     * WC post meta _dd_platform_fee is mirrored for orders with wc_order_id set.
     *
     * @param int    $order_id   dishdash_orders.id
     * @param string $old_status Status before transition
     * @param string $new_status Status after transition
     */
    public static function recalculate_fee_for_status_change( int $order_id, string $old_status, string $new_status ): void {
        global $wpdb;

        if ( $order_id <= 0 || $old_status === $new_status ) {
            return;
        }

        $table = $wpdb->prefix . 'dishdash_orders';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT platform_fee, wc_order_id FROM {$table} WHERE id = %d",
            $order_id
        ) );

        if ( ! $row ) {
            return;
        }

        $current_fee = (int) $row->platform_fee;
        $wc_order_id = $row->wc_order_id ? (int) $row->wc_order_id : 0;

        $target_fee = null;

        if ( $new_status === 'cancelled' ) {
            $target_fee = 0;
        } elseif ( $old_status === 'delivered' && $new_status !== 'delivered' ) {
            $target_fee = 0;
        } elseif ( $new_status === 'delivered' && $old_status !== 'delivered' && $current_fee === 0 ) {
            $fees_enabled = get_option( 'dd_fees_enabled', '1' ) === '1';
            $target_fee   = $fees_enabled ? (int) get_option( 'dd_per_order_fee', 750 ) : 0;
        }

        if ( $target_fee === null ) {
            return;
        }

        if ( $current_fee === $target_fee ) {
            return;
        }

        $wpdb->update(
            $table,
            [ 'platform_fee' => $target_fee ],
            [ 'id'           => $order_id ],
            [ '%d' ],
            [ '%d' ]
        );

        if ( $wc_order_id > 0 && function_exists( 'wc_get_order' ) ) {
            $wc_order = wc_get_order( $wc_order_id );
            if ( $wc_order ) {
                $wc_order->update_meta_data( '_dd_platform_fee', $target_fee );
                $wc_order->save();
            }
        }
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
        $dd_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}dishdash_orders WHERE wc_order_id = %d",
            $wc_order_id
        ) );
        $wpdb->update(
            $wpdb->prefix . 'dishdash_orders',
            [ 'payment_status' => 'unpaid', 'status' => 'cancelled' ],
            [ 'wc_order_id'    => $wc_order_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        if ( $dd_row ) {
            self::recalculate_fee_for_status_change( (int) $dd_row->id, $dd_row->status, 'cancelled' );
        }
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

        // ─── 4. Branch: MoMo / online / offline gateway ───────────────
        // Manual MoMo (scan & pay) is placed UP FRONT like COD. Treat it as an
        // offline (no-redirect) flow so it NEVER enters the online branch or the
        // Collections/PesaPal "Option B" transient-then-confirm path. It does not
        // touch DD_MoMo, dd_momo_check_status, or any transient payload.
        $is_online = ! in_array( $payment_method, self::OFFLINE_GATEWAYS, true )
                     && 'momo_manual' !== $payment_method;

        // ─── 4a. MTN MoMo — in-drawer, no WC redirect ─────────────────
        if ( 'mtn_momo' === $payment_method ) {
            $momo = new DD_MoMo();
            if ( ! $momo->is_configured() ) {
                wp_send_json_error( [ 'message' => 'MTN MoMo is not configured. Please contact the restaurant.' ] );
                return;
            }

            $phone = sanitize_text_field( $_POST['momo_phone'] ?? '' );
            if ( ! $phone ) {
                wp_send_json_error( [ 'message' => 'Please enter your MTN MoMo number.' ] );
                return;
            }

            // Calculate total without creating order yet
            $totals = $this->calculate_totals( $summary['items'], $delivery_fee );
            $total  = $totals['total'];

            // Send USSD prompt — use a temp reference key based on phone + time
            $temp_key    = md5( $phone . $whatsapp . microtime() );
            $momo_result = $momo->request_to_pay( $phone, (float) $total, $temp_key );

            if ( ! $momo_result['success'] ) {
                wp_send_json_error( [ 'message' => $momo_result['error'] ?? 'MoMo payment initiation failed.' ] );
                return;
            }

            // Store full order data in transient — order is NOT created in DB yet
            set_transient( 'dd_momo_pending_' . $momo_result['reference_id'], [
                'customer_name'    => $customer_name,
                'customer_phone'   => $whatsapp,
                'delivery_address' => $delivery_address,
                'items'            => $summary['items'],
                'delivery_fee'     => $delivery_fee,
                'total'            => $total,
            ], 30 * MINUTE_IN_SECONDS );

            wp_send_json_success( [
                'momo'         => true,
                'order_id'     => 0,
                'order_number' => '—',
                'reference_id' => $momo_result['reference_id'],
                'total'        => $total,
            ] );
            return;
        }

        // ─── 4b. IremboPay — in-drawer, no WC redirect ───────────────
        if ( 'irembopay' === $payment_method ) {
            if ( ! class_exists( 'IremboPay_API' ) ) {
                wp_send_json_error( [ 'message' => 'IremboPay plugin is not active.' ] );
                return;
            }

            $irembopay_settings = get_option( 'woocommerce_irembopay_settings', [] );
            $secret_key         = $irembopay_settings['secret_key']           ?? '';
            $public_key         = $irembopay_settings['public_key']           ?? '';
            $payment_identifier = $irembopay_settings['payment_identifier']   ?? '';
            $product_code       = $irembopay_settings['product_code']         ?? '';
            $expiry_hours       = (int) ( $irembopay_settings['invoice_expiry_hours'] ?? 24 );

            if ( empty( $secret_key ) || empty( $public_key ) ) {
                wp_send_json_error( [ 'message' => 'IremboPay is not configured. Please contact the restaurant.' ] );
                return;
            }

            // Create DD order first
            $result = $this->place_order( [
                'customer_name'    => $customer_name,
                'customer_phone'   => $whatsapp,
                'customer_email'   => '',
                'order_type'       => 'delivery',
                'items'            => $summary['items'],
                'delivery_fee'     => $delivery_fee,
                'payment_method'   => 'irembopay',
                'delivery_address' => $delivery_address,
            ] );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ] );
                return;
            }

            $order_id     = $result['order_id'];
            $order_number = $result['order_number'];
            $total        = $result['total'];

            // Build invoice line items from cart
            $invoice_items = [];
            foreach ( $summary['items'] as $item ) {
                $addon_total = array_sum( array_column( $item['addons'] ?? [], 'price' ) );
                $unit_amount = (int) round( (float) $item['price'] + $addon_total );
                $line        = [
                    'unitAmount' => $unit_amount,
                    'quantity'   => (int) ( $item['qty'] ?? 1 ),
                ];
                if ( ! empty( $product_code ) ) {
                    $line['code'] = $product_code;
                }
                $invoice_items[] = $line;
            }

            // Add delivery fee as a line item if applicable
            if ( $delivery_fee > 0 ) {
                $fee_line = [ 'unitAmount' => (int) round( $delivery_fee ), 'quantity' => 1 ];
                if ( ! empty( $product_code ) ) {
                    $fee_line['code'] = $product_code;
                }
                $invoice_items[] = $fee_line;
            }

            $expiry_at = ( new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) ) )
                ->modify( "+{$expiry_hours} hours" )
                ->format( DateTime::ATOM );

            $invoice_data = [
                'transactionId'            => sprintf( 'DD-%d-%s', $order_id, wp_generate_password( 8, false ) ),
                'paymentAccountIdentifier' => $payment_identifier,
                'customer'                 => [
                    'phoneNumber' => $whatsapp,
                    'name'        => $customer_name,
                ],
                'paymentItems' => $invoice_items,
                'description'  => 'Dish Dash order #' . $order_number,
                'language'     => 'EN',
                'expiryAt'     => $expiry_at,
            ];

            $api      = new IremboPay_API( $secret_key );
            $response = $api->create_invoice( $invoice_data );

            if ( empty( $response['success'] ) || empty( $response['data']['invoiceNumber'] ) ) {
                wp_send_json_error( [ 'message' => 'IremboPay invoice creation failed: ' . ( $response['message'] ?? 'Unknown error' ) ] );
                return;
            }

            $invoice_number = $response['data']['invoiceNumber'];
            set_transient( 'dd_irembopay_invoice_' . $order_id, $invoice_number, 2 * HOUR_IN_SECONDS );

            $cart->clear();
            DD_Customer_Manager::upsert( $whatsapp, $customer_name, $delivery_address, (float) $total );

            wp_send_json_success( [
                'irembopay'      => true,
                'order_id'       => $order_id,
                'order_number'   => $order_number,
                'total'          => $total,
                'invoice_number' => $invoice_number,
                'public_key'     => $public_key,
            ] );
            return;
        }

        // ─── 4d. PesaPal — in-drawer iframe, no WC redirect ──────────────
        if ( 'pesapal' === $payment_method ) {
            $pesapal = new DD_PesaPal();
            if ( ! $pesapal->is_configured() ) {
                wp_send_json_error( [ 'message' => 'PesaPal is not configured. Please contact the restaurant.' ] );
                return;
            }

            // Calculate total without creating order yet (Option B)
            $totals = $this->calculate_totals( $summary['items'], $delivery_fee );
            $total  = $totals['total'];
            $ref    = 'DD-' . strtoupper( substr( md5( $whatsapp . microtime() ), 0, 12 ) );

            $result = $pesapal->submit_order(
                (float) $total,
                get_woocommerce_currency(),
                $ref,
                'Food order from ' . get_option( 'dish_dash_restaurant_name', 'Restaurant' ),
                $whatsapp,
                $customer_name
            );

            if ( ! $result['success'] ) {
                wp_send_json_error( [ 'message' => $result['error'] ] );
                return;
            }

            // Store full order data in transient — no DB write yet
            set_transient( 'dd_pesapal_pending_' . $result['order_tracking_id'], [
                'customer_name'    => $customer_name,
                'customer_phone'   => $whatsapp,
                'delivery_address' => $delivery_address,
                'items'            => $summary['items'],
                'delivery_fee'     => $delivery_fee,
                'total'            => $total,
            ], 2 * HOUR_IN_SECONDS );

            wp_send_json_success( [
                'pesapal'           => true,
                'redirect_url'      => $result['redirect_url'],
                'order_tracking_id' => $result['order_tracking_id'],
                'total'             => $total,
            ] );
            return;
        }

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

        // Manual MoMo (scan & pay): stamp the claim-pending state up front.
        // payment_status is a free-text VARCHAR — no schema change. The customer
        // will confirm real receipt in a later release (R6 "I have paid").
        if ( 'momo_manual' === $payment_method ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'dishdash_orders',
                [ 'payment_status' => 'claimed_pending' ],
                [ 'id'             => $result['order_id'] ],
                [ '%s' ],
                [ '%d' ]
            );
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
                    'name'         => $item['name'],
                    'qty'          => (int) ( $item['qty'] ?? 1 ),
                    'price'        => (float) $item['price'],
                    'variation'    => $item['variation'] ?? '',
                    // Cart items store the note under 'note'; normalize to 'special_note'
                    // so the notification readers see the same key on both order paths.
                    'special_note' => $item['note'] ?? '',
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

        // Ownership gate — staff may read any order; a customer may read only their own.
        // Mirrors order_permission() below: dishdash_orders.customer_id stores the WP user ID.
        if ( ! current_user_can( 'dd_manage_orders' ) ) {
            $uid = get_current_user_id();
            if ( ! $uid || (int) $order->customer_id !== $uid ) {
                $this->json_error( __( 'You are not authorized to view this order.', 'dish-dash' ) );
            }
        }

        // Attach pre-decoded variation display lines per item so the modal renders
        // them directly (single source of truth = DD_Notifications::variation_lines(),
        // which stripslashes() the escaped column value before json_decode). Additive —
        // no existing field is changed.
        $items = $this->get_order_items( $order_id );
        foreach ( $items as $item ) {
            $item->variation_lines = DD_Notifications::variation_lines( $item->variation ?? '' );
            // Pre-clean (stripslashes) + HTML-escape the free-text note here so the modal
            // renders it directly and safely (orders.php's JS esc() only escapes quotes).
            $item->special_note = esc_html( DD_Notifications::clean_note( $item->special_note ?? '' ) );
        }

        $this->json_success( [
            'order' => $order,
            'items' => $items,
        ] );
    }

    public function ajax_momo_check_status(): void {
        DD_Ajax::verify_nonce();

        global $wpdb;

        $reference_id = sanitize_text_field( $_POST['reference_id'] ?? '' );

        if ( ! $reference_id ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ] );
            return;
        }

        // Verify pending transient exists for this reference
        if ( ! get_transient( 'dd_momo_pending_' . $reference_id ) ) {
            wp_send_json_error( [ 'message' => 'Payment session expired.' ] );
            return;
        }

        $momo   = new DD_MoMo();
        $status = $momo->get_status( $reference_id );

        if ( $status === 'SUCCESSFUL' ) {
            // Retrieve stored order data
            $pending = get_transient( 'dd_momo_pending_' . $reference_id );
            if ( ! $pending ) {
                wp_send_json_error( [ 'message' => 'Order data expired. Please contact the restaurant.' ] );
                return;
            }

            // Now create the order in DB
            $result = $this->place_order( [
                'customer_name'    => $pending['customer_name'],
                'customer_phone'   => $pending['customer_phone'],
                'customer_email'   => '',
                'order_type'       => 'delivery',
                'items'            => $pending['items'],
                'delivery_fee'     => $pending['delivery_fee'],
                'payment_method'   => 'mtn_momo',
                'delivery_address' => $pending['delivery_address'],
            ] );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ] );
                return;
            }

            $order_id     = $result['order_id'];
            $order_number = $result['order_number'];

            // Mark as paid immediately
            $wpdb->update(
                $wpdb->prefix . 'dishdash_orders',
                [ 'status' => 'pending', 'payment_status' => 'paid' ],
                [ 'id'     => $order_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );

            delete_transient( 'dd_momo_pending_' . $reference_id );
            DD_Customer_Manager::upsert( $pending['customer_phone'], $pending['customer_name'], $pending['delivery_address'], (float) $pending['total'] );

            wp_send_json_success( [
                'paid'         => true,
                'status'       => 'SUCCESSFUL',
                'order_number' => $order_number,
                'order_id'     => $order_id,
            ] );
            return;
        }

        if ( in_array( $status, [ 'FAILED', 'REJECTED', 'TIMEOUT' ], true ) ) {
            // No order was created — just clean up the transient
            delete_transient( 'dd_momo_pending_' . $reference_id );
            wp_send_json_success( [ 'paid' => false, 'status' => $status ] );
            return;
        }

        // Still PENDING
        wp_send_json_success( [ 'paid' => false, 'status' => 'PENDING' ] );
    }

    /**
     * Customer taps "I have paid" on the Scan-&-pay (momo_manual) screen.
     * Flips payment_status claimed_pending → claimed. This is a customer
     * ATTESTATION, not a verified settlement — the restaurant reconciles against
     * their MoMo statement using the order reference. Never sets 'paid'.
     *
     * Guard: the order must exist and be a momo_manual order. Idempotent — only
     * flips from claimed_pending, so a double-tap or replay is a harmless no-op.
     */
    public function ajax_momo_claim_paid(): void {
        DD_Ajax::verify_nonce();

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ] );
            return;
        }

        global $wpdb;
        $order = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, payment_method, payment_status
             FROM {$wpdb->prefix}dishdash_orders WHERE id = %d LIMIT 1",
            $order_id
        ) );

        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Order not found.' ] );
            return;
        }

        if ( $order->payment_method !== 'momo_manual' ) {
            wp_send_json_error( [ 'message' => 'This order cannot be claimed.' ] );
            return;
        }

        // Only advance from the up-front claimed_pending state.
        if ( $order->payment_status === 'claimed_pending' ) {
            $wpdb->update(
                $wpdb->prefix . 'dishdash_orders',
                [ 'payment_status' => 'claimed' ],
                [ 'id'             => $order_id ],
                [ '%s' ],
                [ '%d' ]
            );
        }

        wp_send_json_success( [ 'claimed' => true, 'order_id' => $order_id ] );
    }

    public function ajax_pesapal_check_status(): void {
        DD_Ajax::verify_nonce();

        $order_tracking_id = sanitize_text_field( $_POST['order_tracking_id'] ?? '' );
        if ( ! $order_tracking_id ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ] );
            return;
        }

        // Idempotency-first: if the IPN (or an earlier poll) already created the
        // order for this tracking id, return that order — never create a second one.
        $existing = $this->find_pesapal_order( $order_tracking_id );
        if ( $existing ) {
            wp_send_json_success( [
                'paid'         => true,
                'status'       => 'COMPLETED',
                'order_number' => $existing->order_number,
                'order_id'     => (int) $existing->id,
            ] );
            return;
        }

        $pending = get_transient( 'dd_pesapal_pending_' . $order_tracking_id );
        if ( ! $pending ) {
            wp_send_json_error( [ 'message' => 'Payment session expired. Please try again.' ] );
            return;
        }

        $pesapal = new DD_PesaPal();
        $status  = $pesapal->get_transaction_status( $order_tracking_id );

        if ( $status === 'COMPLETED' ) {
            // Shared idempotent creation routine — the same one the IPN uses.
            // If the IPN raced ahead between the check above and here, this
            // returns the existing order instead of duplicating it.
            $result = $this->create_pesapal_order_from_pending( $order_tracking_id, $pending );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ] );
                return;
            }

            wp_send_json_success( [
                'paid'         => true,
                'status'       => 'COMPLETED',
                'order_number' => $result['order_number'],
                'order_id'     => $result['order_id'],
            ] );
            return;
        }

        if ( in_array( $status, [ 'FAILED', 'INVALID' ], true ) ) {
            delete_transient( 'dd_pesapal_pending_' . $order_tracking_id );
            wp_send_json_success( [ 'paid' => false, 'status' => $status ] );
            return;
        }

        wp_send_json_success( [ 'paid' => false, 'status' => 'PENDING' ] );
    }

    /**
     * PesaPal server-to-server IPN handler (GET, ipn_notification_type='GET').
     *
     * Fired by WooCommerce for the /wc-api/wc_pesapal_gateway/ callback URL that
     * PesaPal calls on payment completion. Runs unauthenticated (server-to-server)
     * — trust is established by re-verifying the status against PesaPal's API
     * (get_transaction_status), never by trusting the request payload.
     *
     * This is the AUTHORITATIVE order-creation path; the client poll is a fast-path
     * backup. Both funnel through create_pesapal_order_from_pending(), which is
     * idempotent, so poll + IPN (+ PesaPal retries) never produce a duplicate.
     *
     * PesaPal keeps retrying until it receives HTTP 200, so we answer 200 for every
     * handled outcome (created / already-created / terminal / pending) and reserve
     * non-200 for "please retry" (missing id → 400, transient creation failure → 500).
     */
    public function handle_pesapal_ipn(): void {
        // PesaPal sends OrderTrackingId (GET). Accept POST too, defensively.
        $tracking_id = sanitize_text_field(
            $_REQUEST['OrderTrackingId'] ?? ( $_REQUEST['orderTrackingId'] ?? '' )
        );
        $merchant_ref = sanitize_text_field(
            $_REQUEST['OrderMerchantReference'] ?? ( $_REQUEST['orderMerchantReference'] ?? '' )
        );

        if ( ! $tracking_id ) {
            $this->pesapal_ipn_respond( 400, '', '' );
            return;
        }

        // Idempotency: order already created for this tracking id → acknowledge.
        if ( $this->find_pesapal_order( $tracking_id ) ) {
            $this->pesapal_ipn_respond( 200, $tracking_id, $merchant_ref );
            return;
        }

        $pending = get_transient( 'dd_pesapal_pending_' . $tracking_id );
        if ( ! $pending ) {
            // No order and no pending data — the transient expired (2h TTL) before
            // any confirmation landed. Nothing we can create; flag for manual
            // reconciliation and acknowledge so PesaPal stops retrying.
            error_log( 'DD PesaPal IPN: reconcile-needed — no order and no pending transient for tracking id ' . $tracking_id );
            $this->pesapal_ipn_respond( 200, $tracking_id, $merchant_ref );
            return;
        }

        // Verify the real status server-side. Never trust the IPN payload for this.
        $pesapal = new DD_PesaPal();
        $status  = $pesapal->get_transaction_status( $tracking_id );

        if ( $status === 'COMPLETED' ) {
            $result = $this->create_pesapal_order_from_pending( $tracking_id, $pending );
            if ( is_wp_error( $result ) ) {
                // Transient failure (lock contention / DB error). Answer non-200 so
                // PesaPal retries; a later attempt will find the order and 200.
                error_log( 'DD PesaPal IPN: creation failed for ' . $tracking_id . ' — ' . $result->get_error_message() );
                $this->pesapal_ipn_respond( 500, $tracking_id, $merchant_ref );
                return;
            }
            $this->pesapal_ipn_respond( 200, $tracking_id, $merchant_ref );
            return;
        }

        if ( in_array( $status, [ 'FAILED', 'INVALID' ], true ) ) {
            delete_transient( 'dd_pesapal_pending_' . $tracking_id );
            $this->pesapal_ipn_respond( 200, $tracking_id, $merchant_ref );
            return;
        }

        // PENDING / UNKNOWN — no order yet. Acknowledge; PesaPal will re-notify.
        $this->pesapal_ipn_respond( 200, $tracking_id, $merchant_ref );
    }

    /**
     * Emit the PesaPal IPN acknowledgement JSON with the given HTTP status.
     * PesaPal treats any 200 as "received"; the JSON body echoes the ids back.
     */
    private function pesapal_ipn_respond( int $http_status, string $tracking_id, string $merchant_ref ): void {
        status_header( $http_status );
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        echo wp_json_encode( [
            'orderNotificationType'  => 'IPNCHANGE',
            'orderTrackingId'        => $tracking_id,
            'orderMerchantReference' => $merchant_ref,
            'status'                 => $http_status,
        ] );
        exit;
    }

    /**
     * Return the dishdash_orders row already created for a PesaPal tracking id,
     * or null. The pesapal_tracking_id column is the idempotency key. If the
     * column has not been added yet (migration not run), this returns null so the
     * code degrades to the pre-idempotency behaviour without erroring.
     */
    private function find_pesapal_order( string $tracking_id ): ?object {
        if ( ! $tracking_id || ! $this->has_pesapal_tracking_column() ) {
            return null;
        }
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, order_number FROM {$wpdb->prefix}dishdash_orders
             WHERE pesapal_tracking_id = %s LIMIT 1",
            $tracking_id
        ) );
    }

    /**
     * Does dishdash_orders have the pesapal_tracking_id column yet?
     * Cached per-request. The column is added by the manual ALTER TABLE
     * documented in the release notes (dbDelta does not alter live tables).
     */
    private function has_pesapal_tracking_column(): bool {
        static $exists = null;
        if ( null !== $exists ) {
            return $exists;
        }
        global $wpdb;
        $col    = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$wpdb->prefix}dishdash_orders LIKE %s",
            'pesapal_tracking_id'
        ) );
        $exists = ! empty( $col );
        return $exists;
    }

    /**
     * Idempotently create a paid PesaPal order from its pending transient payload.
     * Shared by the IPN handler and the client status poll so the two can never
     * create duplicates regardless of which arrives first.
     *
     * Guards (in order):
     *   1. If a row already exists for this tracking id → return it (no create).
     *   2. Acquire a short-lived add_option() lock (atomic INSERT on the unique
     *      option_name) — a concurrent request re-checks (1) then bails.
     *   3. place_order() → stamp status=pending / payment_status=paid /
     *      pesapal_tracking_id (the UNIQUE index is the hard backstop against a
     *      duplicate stamp; a dup-key failure is treated as already-created).
     *   4. Customer upsert + fire DD_Notifications::on_order_created() (dashboard +
     *      email/WhatsApp — NOT via woocommerce_payment_complete, since no WC order
     *      exists on this Option-B path) + delete the pending transient.
     *
     * @return array{order_id:int,order_number:string}|WP_Error
     */
    private function create_pesapal_order_from_pending( string $tracking_id, array $pending ): array|WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_orders';

        // 1. Already created?
        $existing = $this->find_pesapal_order( $tracking_id );
        if ( $existing ) {
            return [ 'order_id' => (int) $existing->id, 'order_number' => $existing->order_number ];
        }

        // 2. Acquire creation lock (atomic — add_option fails if the row exists).
        $lock_key = 'dd_pesapal_lock_' . $tracking_id;
        if ( false === add_option( $lock_key, time(), '', 'no' ) ) {
            // Someone else is mid-create. Re-check for the finished row.
            $existing = $this->find_pesapal_order( $tracking_id );
            if ( $existing ) {
                return [ 'order_id' => (int) $existing->id, 'order_number' => $existing->order_number ];
            }
            return new WP_Error( 'dd_pesapal_locked', 'Order creation already in progress.' );
        }

        // From here we own the lock — always release it before returning.
        $result = $this->place_order( [
            'customer_name'    => $pending['customer_name'],
            'customer_phone'   => $pending['customer_phone'],
            'customer_email'   => '',
            'order_type'       => 'delivery',
            'items'            => $pending['items'],
            'delivery_fee'     => $pending['delivery_fee'],
            'payment_method'   => 'pesapal',
            'delivery_address' => $pending['delivery_address'],
        ] );

        if ( is_wp_error( $result ) ) {
            delete_option( $lock_key );
            return $result;
        }

        $order_id     = (int) $result['order_id'];
        $order_number = $result['order_number'];

        // 3. Stamp paid + the idempotency key. When the column exists the UNIQUE
        //    index makes a duplicate stamp fail — that failure means another path
        //    already created the canonical order, so we adopt it and drop this one.
        if ( $this->has_pesapal_tracking_column() ) {
            $stamped = $wpdb->update(
                $table,
                [ 'status' => 'pending', 'payment_status' => 'paid', 'pesapal_tracking_id' => $tracking_id ],
                [ 'id'     => $order_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );

            if ( false === $stamped ) {
                // Duplicate-key (or DB) failure: the tracking id is already stamped
                // on another row. Discard this just-created row and return the winner.
                $winner = $this->find_pesapal_order( $tracking_id );
                $wpdb->delete( $table, [ 'id' => $order_id ], [ '%d' ] );
                $wpdb->delete( $wpdb->prefix . 'dishdash_order_items', [ 'order_id' => $order_id ], [ '%d' ] );
                delete_option( $lock_key );
                if ( $winner ) {
                    return [ 'order_id' => (int) $winner->id, 'order_number' => $winner->order_number ];
                }
                return new WP_Error( 'dd_pesapal_stamp_failed', 'Could not finalize the PesaPal order.' );
            }
        } else {
            // Pre-migration: no idempotency column. Mark paid only (legacy behaviour).
            $wpdb->update(
                $table,
                [ 'status' => 'pending', 'payment_status' => 'paid' ],
                [ 'id'     => $order_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }

        // 4. Customer upsert.
        DD_Customer_Manager::upsert(
            $pending['customer_phone'],
            $pending['customer_name'],
            $pending['delivery_address'],
            (float) ( $pending['total'] ?? $result['total'] )
        );

        // Fire notifications (dashboard + email/WhatsApp). Build the payload the
        // same way the offline path does (mirrors ajax_place_order's section 6).
        $totals   = $this->calculate_totals( $pending['items'], (float) ( $pending['delivery_fee'] ?? 0 ) );
        $notification_data = [
            'order_number'     => $order_number,
            'customer_name'    => $pending['customer_name'],
            'customer_phone'   => $pending['customer_phone'],
            'delivery_address' => $pending['delivery_address'],
            'payment_method'   => 'pesapal',
            'subtotal'         => $totals['subtotal'],
            'delivery_fee'     => (float) ( $pending['delivery_fee'] ?? 0 ),
            'total'            => (float) $result['total'],
            'items'            => array_values( array_map( function ( $item ) {
                return [
                    'name'         => $item['name'],
                    'qty'          => (int) ( $item['qty'] ?? 1 ),
                    'price'        => (float) $item['price'],
                    'variation'    => $item['variation'] ?? '',
                    'special_note' => $item['note'] ?? '',
                ];
            }, $pending['items'] ) ),
        ];
        DD_Notifications::on_order_created( $notification_data );

        // Done — clear the pending transient and release the lock.
        delete_transient( 'dd_pesapal_pending_' . $tracking_id );
        delete_option( $lock_key );

        return [ 'order_id' => $order_id, 'order_number' => $order_number ];
    }

    public function ajax_irembopay_confirm(): void {
        DD_Ajax::verify_nonce();

        $order_id       = absint( $_POST['order_id']       ?? 0 );
        $invoice_number = sanitize_text_field( $_POST['invoice_number'] ?? '' );

        if ( ! $order_id || ! $invoice_number ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ] );
            return;
        }

        $stored = get_transient( 'dd_irembopay_invoice_' . $order_id );
        if ( $stored !== $invoice_number ) {
            wp_send_json_error( [ 'message' => 'Invoice mismatch.' ] );
            return;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'dishdash_orders',
            [ 'status' => 'confirmed', 'payment_status' => 'paid' ],
            [ 'id'     => $order_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        delete_transient( 'dd_irembopay_invoice_' . $order_id );
        do_action( 'dish_dash_order_confirmed', $order_id );

        wp_send_json_success( [ 'confirmed' => true ] );
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
     * Authoritative live worklist — ALL pending orders + ALL pending reservations.
     * Called every 30s by admin JS. Returns identical data for every staff member.
     */
    public function ajax_poll_notifications(): void {
        DD_Ajax::verify_nonce( 'nonce', 'dish_dash_admin' );

        if ( ! current_user_can( 'manage_options' ) ) {
            $this->json_error( 'Permission denied.', 403 );
        }

        // Dashboard notification alerts are gated by the Order Handling setting
        // (default '1' = on). When off, the bell/poll returns nothing so there is
        // no beep, browser alert, or badge. Backstops the enqueue-time JS guard in
        // case a stale/cached admin.js still polls. The Orders page, order data,
        // statuses, and the reservations admin's own system are unaffected.
        if ( get_option( 'dish_dash_order_notify_dashboard', '1' ) !== '1' ) {
            $this->json_success( [
                'pending_items' => [],
                'pending_count' => 0,
            ] );
            return;
        }

        global $wpdb;

        // ALL pending orders — actionable, restaurant-wide.
        $pending_orders = $wpdb->get_results(
            "SELECT id, order_number, customer_name, total, payment_method,
                    TIMESTAMPDIFF(SECOND, created_at, NOW()) AS seconds_ago
             FROM {$wpdb->prefix}dishdash_orders
             WHERE status = 'pending' AND is_test = 0
             ORDER BY id DESC",
            ARRAY_A
        );

        // ALL pending reservations — actionable, restaurant-wide.
        $pending_reservations = $wpdb->get_results(
            "SELECT id, name, date, time, guests,
                    TIMESTAMPDIFF(SECOND, created_at, NOW()) AS seconds_ago
             FROM {$wpdb->prefix}dishdash_reservations
             WHERE status = 'pending'
             ORDER BY id DESC",
            ARRAY_A
        );

        // Build one unified, ordered list (newest first across both types).
        $items = [];

        foreach ( $pending_orders as $o ) {
            $order_num = ! empty( $o['order_number'] )
                ? $o['order_number']
                : 'DD-' . str_pad( $o['id'], 5, '0', STR_PAD_LEFT );
            $items[] = [
                'type'        => 'order',
                'id'          => (int) $o['id'],
                'title'       => $order_num . ' · ' . $o['customer_name'],
                'meta'        => number_format( (float) $o['total'] ) . ' RWF · ' . dd_format_payment_method( $o['payment_method'] ),
                'seconds_ago' => (int) $o['seconds_ago'],
            ];
        }

        foreach ( $pending_reservations as $r ) {
            $guests = (int) $r['guests'] . ' guest' . ( (int) $r['guests'] !== 1 ? 's' : '' );
            $time   = is_string( $r['time'] ) ? substr( $r['time'], 0, 5 ) : '';
            $items[] = [
                'type'        => 'reservation',
                'id'          => (int) $r['id'],
                'title'       => $r['name'] . ' · ' . $r['date'] . ( $time ? ' at ' . $time : '' ),
                'meta'        => $guests,
                'seconds_ago' => (int) $r['seconds_ago'],
            ];
        }

        // Sort the unified list by most-recent arrival first.
        usort( $items, function ( $a, $b ) {
            return $a['seconds_ago'] <=> $b['seconds_ago'];
        } );

        $this->json_success( [
            'pending_items' => $items,
            'pending_count' => count( $items ),
        ] );
    }

    public function ajax_kitchen_queue(): void {
        DD_Ajax::verify_nonce( 'nonce', 'dish_dash_admin' );
        if ( ! current_user_can( 'manage_options' ) ) {
            $this->json_error( 'Permission denied.', 403 );
        }

        global $wpdb;

        $orders = $wpdb->get_results(
            "SELECT id, order_number, customer_name, total, payment_method,
                    order_type, confirmed_at
             FROM {$wpdb->prefix}dishdash_orders
             WHERE status = 'confirmed'
             AND is_test = 0
             ORDER BY confirmed_at ASC",
            ARRAY_A
        );

        $prep_time = (int) get_option( 'dd_kitchen_prep_time', 30 );

        $this->json_success( [
            'orders'    => $orders,
            'prep_time' => $prep_time,
        ] );
    }

    public function ajax_mark_notifications_read(): void {
        DD_Ajax::verify_nonce( 'nonce', 'dish_dash_admin' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $ids = isset( $_POST['order_ids'] ) ? array_map( 'intval', (array) $_POST['order_ids'] ) : [];

        if ( empty( $ids ) ) {
            wp_send_json_success();
            return;
        }

        $read = get_option( 'dd_notifications_read', [] );
        $read = array_unique( array_merge( $read, $ids ) );

        if ( count( $read ) > 200 ) {
            $read = array_slice( $read, -200 );
        }

        update_option( 'dd_notifications_read', array_values( $read ), false );
        wp_send_json_success();
    }

    /**
     * AJAX: mark a billing month as paid or unpaid.
     * Expects POST: month (Y-m), paid (1|0), nonce (dish_dash_admin)
     */
    public function ajax_mark_month_paid(): void {
        check_ajax_referer( 'dish_dash_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Access denied.' ] );
        }

        global $wpdb;

        $month = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
        $paid  = isset( $_POST['paid'] )  ? (int) $_POST['paid'] : 0;

        if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
            wp_send_json_error( [ 'message' => 'Invalid month format.' ] );
        }

        $paid    = $paid ? 1 : 0;
        $paid_at = $paid ? current_time( 'mysql' ) : null;
        $table   = $wpdb->prefix . 'dd_billing_payments';

        // Get current amount for this month from orders table
        $ot     = $wpdb->prefix . 'dishdash_orders';
        $amount = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(platform_fee),0) FROM `{$ot}`
             WHERE status = 'delivered' AND platform_fee > 0
             AND DATE_FORMAT(created_at, '%%Y-%%m') = %s AND is_test = 0",
            $month
        ) );

        // Upsert — insert or update
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE month = %s", $month
        ) );

        if ( $existing ) {
            $wpdb->update(
                $table,
                [ 'paid' => $paid, 'paid_at' => $paid_at, 'amount' => $amount ],
                [ 'month' => $month ],
                [ '%d', '%s', '%d' ],
                [ '%s' ]
            );
        } else {
            $wpdb->insert(
                $table,
                [ 'month' => $month, 'amount' => $amount, 'paid' => $paid, 'paid_at' => $paid_at ],
                [ '%s', '%d', '%d', '%s' ]
            );
        }

        wp_send_json_success( [
            'month'   => $month,
            'paid'    => $paid,
            'paid_at' => $paid_at,
            'amount'  => $amount,
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
        $account_url = function_exists( 'wc_get_account_url' )
            ? wc_get_account_url()
            : home_url( '/my-account/' );

        // v1 — logged-in only.
        if ( ! is_user_logged_in() ) {
            return $this->get_template( 'orders/track.php', [
                'state'       => 'guest',
                'order'       => null,
                'orders'      => [],
                'account_url' => $account_url,
            ] );
        }

        global $wpdb;
        $uid = get_current_user_id();

        // ── A specific order was requested (?order_id= or ?order=) — single-order live
        //    tracker. UNCHANGED from before R2: same fetch, same ownership gate, same poll. ──
        if ( isset( $_GET['order_id'] ) && is_numeric( $_GET['order_id'] ) ) {
            $order = $this->get_order( absint( $_GET['order_id'] ) );
            return $this->render_single_track( $order, $account_url, $uid );
        }
        if ( isset( $_GET['order'] ) && '' !== $_GET['order'] ) {
            $order_number = sanitize_text_field( wp_unslash( $_GET['order'] ) );
            $order        = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dishdash_orders WHERE order_number = %s LIMIT 1",
                $order_number
            ) );
            return $this->render_single_track( $order, $account_url, $uid );
        }

        // ── Default (R2) — phone-anchored snapshot list of this user's ACTIVE orders. ──
        // Reuses the R4b resolution: match by WP user id OR the user's canonical E.164
        // (customers.whatsapp). Empty phone set → customer_id-only (never emits IN ()).
        $customer = DD_Customer_Manager::get_customer_for_user( $uid );
        $phones   = array_values( array_filter( [ (string) ( $customer->whatsapp ?? '' ) ] ) );
        if ( $phones ) {
            $ph    = implode( ',', array_fill( 0, count( $phones ), '%s' ) );
            $where = "( customer_id = %d OR customer_phone IN ($ph) )";
            $args  = array_merge( [ $uid ], $phones );
        } else {
            $where = "customer_id = %d";
            $args  = [ $uid ];
        }

        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, order_number, status, created_at, customer_id
             FROM {$wpdb->prefix}dishdash_orders
             WHERE {$where}
               AND is_test = 0
               AND status IN ('pending','confirmed','ready')
             ORDER BY id DESC",
            $args
        ) );

        // Snapshot list — style only (no live poll on the list). Only rows the user OWNS
        // (customer_id = current user) deep-link to the single-order tracker; phone-only
        // rows (customer_id NULL / another id) render non-clickable so no one hits the
        // customer_id-only ownership gate → "Order not found". (Full fix: R4c.)
        $this->enqueue_style( 'order-tracking', 'order-tracking.css' );
        // Profile sidebar styling (R2 polish) — the track page reuses the account nav.
        $this->enqueue_style( 'profile', 'profile.css' );

        return $this->get_template( 'orders/track.php', [
            'state'           => 'list',
            'order'           => null,
            'orders'          => $orders,
            'current_user_id' => $uid,
            'account_url'     => $account_url,
        ] );
    }

    /**
     * Render the single-order live tracker for a specific requested order.
     * Behavior is identical to the pre-R2 ?order_id=/?order= path: ownership-gated
     * (staff read any, customer reads own only; a failed gate is indistinguishable
     * from "not found"), then enqueues the polling tracker on a live order.
     */
    private function render_single_track( $order, string $account_url, int $uid ): string {
        // Both the not-found and live views render inside the account sidebar layout.
        $this->enqueue_style( 'order-tracking', 'order-tracking.css' );
        $this->enqueue_style( 'profile', 'profile.css' );

        // Ownership gate — mirror ajax_get_order(). Do not leak existence.
        if ( $order && ! current_user_can( 'dd_manage_orders' ) && (int) $order->customer_id !== $uid ) {
            $order = null;
        }

        if ( ! $order ) {
            return $this->get_template( 'orders/track.php', [
                'state'       => 'notfound',
                'order'       => null,
                'orders'      => [],
                'account_url' => $account_url,
            ] );
        }

        // CSS already enqueued above; a live order additionally needs the polling JS.
        $this->enqueue_script( 'order-tracking', 'order-tracking.js', [], true );
        wp_localize_script( 'dish-dash-order-tracking', 'ddTrackConfig', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'dish_dash_frontend' ),
        ] );

        return $this->get_template( 'orders/track.php', [
            'state'       => 'ok',
            'order'       => $order,
            'orders'      => [],
            'account_url' => $account_url,
        ] );
    }

    public function shortcode_account( $atts ): string {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Please log in to view your orders.', 'dish-dash' ) . '</p>';
        }
        $orders = $this->get_orders( [ 'customer_id' => get_current_user_id(), 'limit' => 10 ] );
        return $this->get_template( 'orders/account.php', [ 'orders' => $orders ] );
    }
}
