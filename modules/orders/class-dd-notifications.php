<?php
/**
 * File:    modules/orders/class-dd-notifications.php
 * Purpose: DD_Notifications — Handles all order notifications (email + WhatsApp)
 *
 * Called by:
 *   - ajax_place_order() for offline gateways (cod, bacs, cheque)
 *   - woocommerce_payment_complete hook for online gateways (Pesapal, DPO, etc.)
 *
 * To swap WhatsApp to API (Phase 3.5 Mode B):
 *   Replace build_admin_whatsapp_url() only — nothing else changes.
 *
 * @package DishDash
 * @since   3.2.48
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Notifications {

    /**
     * Called for offline gateways immediately on order creation.
     * Returns wa.me URL so JS can open it in the browser.
     *
     * @param array $order_data Normalized order data array
     * @return string wa.me URL for admin notification (empty if no phone configured)
     */
    public static function on_order_created( array $order_data ): string {
        self::notify_admin_email( $order_data );
        return self::build_admin_whatsapp_url( $order_data );
    }

    /**
     * Called by woocommerce_payment_complete hook for online gateways.
     * Fires after payment is confirmed — never before.
     *
     * @param int $wc_order_id WooCommerce order ID
     */
    public static function on_payment_complete( int $wc_order_id ): void {
        $order_data = self::build_from_wc_order( $wc_order_id );
        if ( ! $order_data ) return;
        self::notify_admin_email( $order_data );
        // Store wa.me URL for injection into order-received page (v3.2.49)
        update_option( 'dd_pending_whatsapp_' . $wc_order_id, self::build_admin_whatsapp_url( $order_data ) );
    }

    /**
     * Build normalized order_data from a WooCommerce order.
     * Used when payment_complete fires — maps WC order back to DD format.
     */
    private static function build_from_wc_order( int $wc_order_id ): ?array {
        global $wpdb;

        $wc_order = wc_get_order( $wc_order_id );
        if ( ! $wc_order ) return null;

        // Find matching DD order by wc_order_id
        $dd_order = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dishdash_orders WHERE wc_order_id = %d LIMIT 1",
            $wc_order_id
        ) );

        // Get order items from DD order items table (columns: item_name, quantity, unit_price)
        $items = [];
        if ( $dd_order ) {
            $raw_items = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dishdash_order_items WHERE order_id = %d",
                $dd_order->id
            ), ARRAY_A );
            foreach ( $raw_items as $item ) {
                $items[] = [
                    'name'  => $item['item_name'],
                    'qty'   => (int) $item['quantity'],
                    'price' => (float) $item['unit_price'],
                ];
            }
        }

        return [
            'order_number'     => $dd_order ? $dd_order->order_number : ( 'WC-' . $wc_order_id ),
            'customer_name'    => trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() ),
            'customer_phone'   => $dd_order ? $dd_order->customer_phone : $wc_order->get_billing_phone(),
            'delivery_address' => $dd_order ? $dd_order->delivery_address : $wc_order->get_formatted_shipping_address(),
            'payment_method'   => $wc_order->get_payment_method_title(),
            'subtotal'         => (float) ( $dd_order ? $dd_order->subtotal : $wc_order->get_subtotal() ),
            'delivery_fee'     => (float) ( $dd_order ? $dd_order->delivery_fee : $wc_order->get_shipping_total() ),
            'total'            => (float) ( $dd_order ? $dd_order->total : $wc_order->get_total() ),
            'items'            => $items,
        ];
    }

    /**
     * Send HTML email notification to admin.
     */
    private static function notify_admin_email( array $order ): void {
        $admin_email = get_option( 'dd_admin_email', get_option( 'admin_email' ) );
        if ( ! $admin_email ) return;

        $subject = sprintf( '[Khana Khazana] New Order %s — %s RWF',
            $order['order_number'],
            number_format( $order['total'] )
        );

        $items_html = '';
        foreach ( $order['items'] as $item ) {
            $line_total  = number_format( $item['price'] * $item['qty'] );
            $items_html .= sprintf(
                '<tr><td style="padding:6px 0;border-bottom:1px solid #f0e8e0;">%d&times; %s</td><td style="padding:6px 0;border-bottom:1px solid #f0e8e0;text-align:right;">%s RWF</td></tr>',
                (int) $item['qty'],
                esc_html( $item['name'] ),
                $line_total
            );
        }

        $delivery_row = $order['delivery_fee'] > 0
            ? '<tr><td style="padding:4px 0;color:#777;">Delivery</td><td style="padding:4px 0;text-align:right;color:#777;">' . number_format( $order['delivery_fee'] ) . ' RWF</td></tr>'
            : '<tr><td style="padding:4px 0;color:#27ae60;">Delivery</td><td style="padding:4px 0;text-align:right;color:#27ae60;">FREE</td></tr>';

        $body = '
        <div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;border:1px solid #eee;">
            <div style="background:#65040d;padding:20px 24px;">
                <h2 style="color:#fff;margin:0;font-size:18px;">&#128276; New Order ' . esc_html( $order['order_number'] ) . '</h2>
                <p style="color:rgba(255,255,255,0.8);margin:4px 0 0;font-size:13px;">Khana Khazana &mdash; ' . esc_html( date( 'D j M Y, H:i' ) ) . '</p>
            </div>
            <div style="padding:20px 24px;">
                <table style="width:100%;border-collapse:collapse;">
                    ' . $items_html . '
                    ' . $delivery_row . '
                    <tr>
                        <td style="padding:10px 0 4px;font-weight:bold;font-size:16px;">Total</td>
                        <td style="padding:10px 0 4px;text-align:right;font-weight:bold;font-size:16px;color:#65040d;">' . number_format( $order['total'] ) . ' RWF</td>
                    </tr>
                </table>
                <hr style="border:none;border-top:1px solid #f0e8e0;margin:16px 0;">
                <table style="width:100%;font-size:14px;">
                    <tr><td style="color:#777;padding:3px 0;">Payment</td><td style="text-align:right;">' . esc_html( $order['payment_method'] ) . '</td></tr>
                    <tr><td style="color:#777;padding:3px 0;">Customer</td><td style="text-align:right;">' . esc_html( $order['customer_name'] ) . '</td></tr>
                    <tr><td style="color:#777;padding:3px 0;">WhatsApp</td><td style="text-align:right;">' . esc_html( $order['customer_phone'] ) . '</td></tr>
                    <tr><td style="color:#777;padding:3px 0;">Address</td><td style="text-align:right;">' . esc_html( $order['delivery_address'] ) . '</td></tr>
                </table>
            </div>
            <div style="background:#f9f5f0;padding:12px 24px;text-align:center;font-size:12px;color:#aaa;">
                Dish Dash &mdash; Khana Khazana ordering system
            </div>
        </div>';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: Khana Khazana <' . get_option( 'woocommerce_email_from_address', $admin_email ) . '>',
        ];

        wp_mail( $admin_email, $subject, $body, $headers );
    }

    /**
     * Build admin wa.me URL with pre-filled order message.
     * Returns empty string if no admin WhatsApp number configured.
     *
     * Swap this method for WATI API call in Phase 3.5 Mode B.
     */
    public static function build_admin_whatsapp_url( array $order ): string {
        $admin_phone = preg_replace( '/[^0-9]/', '', get_option( 'dd_whatsapp_admin', '' ) );
        if ( ! $admin_phone ) return '';

        $items_text = implode( "\n", array_map( function ( $i ) {
            return $i['qty'] . '× ' . $i['name'];
        }, $order['items'] ) );

        $delivery_text = $order['delivery_fee'] > 0
            ? number_format( $order['delivery_fee'] ) . ' RWF'
            : 'FREE';

        $msg = implode( "\n", [
            '🔔 New Order ' . $order['order_number'] . ' — Khana Khazana',
            '──────────────────',
            $items_text,
            '──────────────────',
            'Total: '    . number_format( $order['total'] ) . ' RWF',
            'Delivery: ' . $delivery_text,
            'Payment: '  . $order['payment_method'],
            '📍 ' . $order['delivery_address'],
            '📞 ' . $order['customer_phone'],
            '👤 ' . $order['customer_name'],
        ] );

        return 'https://wa.me/' . $admin_phone . '?text=' . rawurlencode( $msg );
    }
}
