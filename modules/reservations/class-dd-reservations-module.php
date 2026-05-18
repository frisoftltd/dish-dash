<?php
/**
 * DD_Reservations_Module
 *
 * @package DishDash
 * @since   3.2.90
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-dd-reservations-admin.php';
require_once __DIR__ . '/class-dd-tables-admin.php';
require_once __DIR__ . '/class-dd-sections-admin.php';
require_once DD_PLUGIN_DIR . 'modules/orders/class-dd-notifications.php';

class DD_Reservations_Module extends DD_Module {

    protected string $id = 'reservations';

    public function init(): void {
        ( new DD_Reservations_Admin() )->init();
        ( new DD_Tables_Admin() )->init();
        ( new DD_Sections_Admin() )->init();

        DD_Ajax::register( 'dd_submit_reservation',        [ $this, 'ajax_submit_reservation' ] );
        DD_Ajax::register( 'dd_reservation_availability',  [ $this, 'ajax_check_availability' ] );
        DD_Ajax::register( 'dd_reservation_update_status', [ $this, 'ajax_update_status' ], false );

        add_action( 'woocommerce_payment_complete', [ $this, 'on_deposit_payment_complete' ] );
        add_action( 'dd_reservation_autocancel',    [ $this, 'run_autocancel' ], 10, 1 );
    }

    // ── AJAX: Submit reservation ───────────────────────────────────────────

    public function ajax_submit_reservation(): void {
        DD_Ajax::verify_nonce();

        global $wpdb;

        // 1. Sanitize inputs
        $name     = sanitize_text_field( wp_unslash( $_POST['name']     ?? '' ) );
        $whatsapp = sanitize_text_field( wp_unslash( $_POST['whatsapp'] ?? '' ) );
        $date     = sanitize_text_field( wp_unslash( $_POST['date']     ?? '' ) );
        $time     = sanitize_text_field( wp_unslash( $_POST['time']     ?? '' ) );
        $session  = sanitize_text_field( wp_unslash( $_POST['session']  ?? '' ) );
        $guests   = intval( $_POST['guests'] ?? 0 );
        $table_pref = sanitize_text_field( wp_unslash( $_POST['table']    ?? '' ) );
        $requests = sanitize_textarea_field( wp_unslash( $_POST['requests'] ?? '' ) );
        $source   = sanitize_text_field( wp_unslash( $_POST['source']   ?? 'homepage' ) );

        // 2. Validate required fields
        if ( ! $name || ! $whatsapp || ! $date || ! $time || ! $session || $guests < 1 ) {
            wp_send_json_error( [ 'message' => 'Missing required fields.' ] );
        }

        // 3. Validate date format and that it is not in the past
        $tz           = new \DateTimeZone( get_option( 'dd_timezone', 'Africa/Kigali' ) );
        $booking_date = \DateTime::createFromFormat( 'Y-m-d', $date, $tz );
        if ( ! $booking_date ) {
            wp_send_json_error( [ 'message' => 'Invalid date.' ] );
        }
        $booking_date->setTime( 0, 0, 0 );
        $today = new \DateTime( 'today', $tz );
        if ( $booking_date < $today ) {
            wp_send_json_error( [ 'message' => 'That date has already passed.' ] );
        }

        // 4. Customer identity — WhatsApp is primary key
        $customers_table = $wpdb->prefix . 'dishdash_customers';
        $customer = $wpdb->get_row(
            $wpdb->prepare( "SELECT id FROM {$customers_table} WHERE whatsapp = %s LIMIT 1", $whatsapp )
        );
        if ( $customer ) {
            $customer_id = (int) $customer->id;
        } else {
            $wpdb->insert(
                $customers_table,
                [
                    'whatsapp'   => $whatsapp,
                    'name'       => $name,
                    'created_at' => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%s' ]
            );
            $customer_id = (int) $wpdb->insert_id;
        }

        // 5. Generate unique booking ref: RES-YYYYMMDD-XXXX
        $res_table   = $wpdb->prefix . 'dishdash_reservations';
        $date_part   = date( 'Ymd', strtotime( $date ) );
        $booking_ref = '';
        $attempts    = 0;
        do {
            $suffix      = strtoupper( substr( md5( uniqid( '', true ) ), 0, 4 ) );
            $booking_ref = "RES-{$date_part}-{$suffix}";
            $exists      = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$res_table} WHERE booking_ref = %s", $booking_ref )
            );
            $attempts++;
        } while ( $exists && $attempts < 10 );

        if ( $exists ) {
            wp_send_json_error( [ 'message' => 'Could not generate booking reference. Please try again.' ] );
        }

        // 6. Deposit check — determines status and extra columns
        $deposit_enabled = (int) get_option( 'dd_reservation_deposit_enabled', 0 );
        $deposit_amount  = 0;
        $deposit_status  = 'none';
        $status          = 'pending';

        if ( $deposit_enabled ) {
            $deposit_amount = $this->calculate_deposit_amount();
            $deposit_status = 'pending';
            $status         = 'pending_payment';
        }

        // 7. Insert reservation
        $inserted = $wpdb->insert(
            $res_table,
            [
                'booking_ref'      => $booking_ref,
                'customer_id'      => $customer_id,
                'date'             => $date,
                'time'             => $time,
                'session'          => $session,
                'guests'           => $guests,
                'name'             => $name,
                'whatsapp'         => $whatsapp,
                'special_requests' => $requests ?: null,
                'source'           => $source,
                'status'           => $status,
                'deposit_required' => $deposit_enabled ? 1 : 0,
                'deposit_amount'   => $deposit_amount,
                'deposit_status'   => $deposit_status,
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ]
        );

        if ( ! $inserted ) {
            wp_send_json_error( [
                'message'  => 'Could not save reservation. Please try again.',
                'db_error' => $wpdb->last_error,
            ] );
            return;
        }

        $reservation_id = (int) $wpdb->insert_id;

        // 7B. Deposit path — create WC order and return payment URL
        if ( $deposit_enabled ) {
            $wc_order = $this->create_deposit_wc_order(
                [ 'id' => $reservation_id, 'booking_ref' => $booking_ref, 'name' => $name, 'whatsapp' => $whatsapp ],
                (float) $deposit_amount
            );

            if ( is_wp_error( $wc_order ) ) {
                $wpdb->delete( $res_table, [ 'id' => $reservation_id ], [ '%d' ] );
                wp_send_json_error( [ 'message' => 'Payment setup failed. Please try again.' ] );
                return;
            }

            $autocancel_hours = (int) get_option( 'dd_reservation_autocancel_hours', 2 );
            wp_schedule_single_event(
                time() + ( $autocancel_hours * HOUR_IN_SECONDS ),
                'dd_reservation_autocancel',
                [ $reservation_id ]
            );

            do_action( 'dd_track_event', 'deposit_initiated', null, null, [
                'booking_ref'  => $booking_ref,
                'amount'       => $deposit_amount,
                'deposit_type' => get_option( 'dd_reservation_deposit_type', 'fixed' ),
                'wc_order_id'  => $wc_order->get_id(),
            ] );

            wp_send_json_success( [
                'requires_payment' => true,
                'payment_url'      => $wc_order->get_checkout_payment_url(),
                'booking_ref'      => $booking_ref,
                'deposit_amount'   => $deposit_amount,
            ] );
            return;
        }

        // 8. Build WhatsApp notification URLs (free booking path)
        $wa_urls = DD_Notifications::on_reservation_created( [
            'booking_ref'      => $booking_ref,
            'name'             => $name,
            'whatsapp'         => $whatsapp,
            'date'             => $date,
            'time'             => $time,
            'session'          => ucfirst( $session ),
            'guests'           => $guests,
            'table_pref'       => $table_pref,
            'special_requests' => $requests,
        ] );

        // 9. Email admin
        $this->send_admin_email( [
            'booking_ref'      => $booking_ref,
            'date'             => $date,
            'time'             => $time,
            'session'          => $session,
            'guests'           => $guests,
            'table_pref'       => $table_pref,
            'name'             => $name,
            'whatsapp'         => $whatsapp,
            'special_requests' => $requests,
        ] );

        // 9. Return success
        wp_send_json_success( [
            'booking_ref'  => $booking_ref,
            'admin_url'    => $wa_urls['admin_url'],
            'customer_url' => $wa_urls['customer_url'],
        ] );
    }

    /**
     * Email the admin when a new reservation is submitted.
     */
    private function send_admin_email( array $res ): void {
        $admin_email = get_option( 'dd_admin_email', get_option( 'admin_email' ) );
        if ( ! $admin_email || ! is_email( $admin_email ) ) {
            return;
        }

        $restaurant = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );
        $date_fmt   = date( 'l, d M Y', strtotime( $res['date'] ) );
        $guest_word = ( (int) $res['guests'] === 1 ? 'guest' : 'guests' );
        $primary    = '#65040d';

        $subject = sprintf( '[%s] New Reservation — %s', $restaurant, $res['booking_ref'] );

        $admin_link = add_query_arg(
            [
                'page' => 'dd-reservations',
                's'    => $res['booking_ref'],
            ],
            admin_url( 'admin.php' )
        );

        $table_row = '';
        if ( ! empty( $res['table_pref'] ) ) {
            $table_row = '<tr><td style="padding:6px 0;color:#6E5B4C;">Table preference</td>'
                . '<td style="padding:6px 0;text-align:right;font-weight:600;color:#221B19;">'
                . esc_html( ucfirst( $res['table_pref'] ) ) . '</td></tr>';
        }
        $requests_row = '';
        if ( ! empty( $res['special_requests'] ) ) {
            $requests_row = '<tr><td style="padding:6px 0;color:#6E5B4C;">Special requests</td>'
                . '<td style="padding:6px 0;text-align:right;font-weight:600;color:#221B19;">'
                . esc_html( $res['special_requests'] ) . '</td></tr>';
        }

        $body = '
<div style="background:#F5EFE6;padding:24px 0;font-family:\'Segoe UI\',Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center">
      <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="max-width:520px;width:100%;background:#FBF7F1;border-radius:12px;overflow:hidden;">

        <!-- Header -->
        <tr><td style="background:' . $primary . ';padding:24px 28px;">
          <div style="color:#fff;font-size:18px;font-weight:700;">🔔 New Table Reservation</div>
          <div style="color:#E6C9CC;font-size:13px;margin-top:4px;">' . esc_html( $restaurant ) . '</div>
        </td></tr>

        <!-- Booking ref banner -->
        <tr><td style="padding:20px 28px 8px;">
          <div style="color:#6E5B4C;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Booking Reference</div>
          <div style="color:' . $primary . ';font-size:22px;font-weight:700;letter-spacing:1px;">' . esc_html( $res['booking_ref'] ) . '</div>
        </td></tr>

        <!-- Details -->
        <tr><td style="padding:8px 28px 4px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;border-top:1px solid #EADFCE;">
            <tr><td style="padding:10px 0 6px;color:#6E5B4C;">Date</td>
                <td style="padding:10px 0 6px;text-align:right;font-weight:600;color:#221B19;">' . esc_html( $date_fmt ) . '</td></tr>
            <tr><td style="padding:6px 0;color:#6E5B4C;">Time</td>
                <td style="padding:6px 0;text-align:right;font-weight:600;color:#221B19;">' . esc_html( $res['time'] ) . ' (' . esc_html( ucfirst( $res['session'] ) ) . ')</td></tr>
            <tr><td style="padding:6px 0;color:#6E5B4C;">Guests</td>
                <td style="padding:6px 0;text-align:right;font-weight:600;color:#221B19;">' . esc_html( $res['guests'] ) . ' ' . $guest_word . '</td></tr>
            ' . $table_row . '
          </table>
        </td></tr>

        <!-- Customer -->
        <tr><td style="padding:4px 28px 8px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;border-top:1px solid #EADFCE;">
            <tr><td style="padding:10px 0 6px;color:#6E5B4C;">Customer</td>
                <td style="padding:10px 0 6px;text-align:right;font-weight:600;color:#221B19;">' . esc_html( $res['name'] ) . '</td></tr>
            <tr><td style="padding:6px 0;color:#6E5B4C;">WhatsApp</td>
                <td style="padding:6px 0;text-align:right;font-weight:600;color:#221B19;">' . esc_html( $res['whatsapp'] ) . '</td></tr>
            ' . $requests_row . '
          </table>
        </td></tr>

        <!-- Status pill -->
        <tr><td style="padding:8px 28px 4px;">
          <span style="display:inline-block;background:#FBE8C8;color:#b45309;font-size:12px;font-weight:700;padding:5px 12px;border-radius:20px;">PENDING — NEEDS REVIEW</span>
        </td></tr>

        <!-- CTA button -->
        <tr><td style="padding:20px 28px 28px;" align="center">
          <a href="' . esc_url( $admin_link ) . '"
             style="display:inline-block;background:' . $primary . ';color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:14px 32px;border-radius:8px;">
             Review &amp; Confirm Reservation →
          </a>
        </td></tr>

        <!-- Footer -->
        <tr><td style="background:#F0E7D8;padding:14px 28px;text-align:center;">
          <div style="color:#6E5B4C;font-size:12px;">Dish Dash — ' . esc_html( $restaurant ) . ' reservation system</div>
        </td></tr>

      </table>
    </td></tr>
  </table>
</div>';

        $from_address = get_option( 'woocommerce_email_from_address', $admin_email );
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $restaurant . ' <' . $from_address . '>',
        ];
        wp_mail( $admin_email, $subject, $body, $headers );
    }

    // ── Section helpers ────────────────────────────────────────────────────

    /**
     * Get the configured reservation sections.
     * Returns an array of ['name' => string, 'active' => bool].
     */
    public static function get_sections(): array {
        $raw = get_option( 'dd_reservation_sections', '' );
        if ( empty( $raw ) ) {
            // First-run default
            return [
                [ 'name' => 'Indoor',  'active' => true ],
                [ 'name' => 'Outdoor', 'active' => true ],
                [ 'name' => 'Private', 'active' => true ],
            ];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Get only active section names, for the customer dropdown.
     */
    public static function get_active_section_names(): array {
        $out = [];
        foreach ( self::get_sections() as $s ) {
            if ( ! empty( $s['active'] ) && ! empty( $s['name'] ) ) {
                $out[] = $s['name'];
            }
        }
        return $out;
    }

    // ── AJAX: Availability check (stub — Phase 4C) ─────────────────────────

    public function ajax_check_availability(): void {
        DD_Ajax::verify_nonce();
        wp_send_json_success( [ 'available' => true ] );
    }

    // ── AJAX: Admin status update ──────────────────────────────────────────

    public function ajax_update_status(): void {
        DD_Ajax::verify_nonce( 'nonce', 'dish_dash_admin' );

        global $wpdb;

        $allowed = [ 'pending', 'confirmed', 'cancelled', 'no_show' ];
        $status  = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
        $id      = intval( $_POST['id'] ?? 0 );

        if ( ! in_array( $status, $allowed, true ) || $id < 1 ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ] );
        }

        $wpdb->update(
            $wpdb->prefix . 'dishdash_reservations',
            [ 'status' => $status ],
            [ 'id'     => $id ],
            [ '%s' ],
            [ '%d' ]
        );

        wp_send_json_success( [ 'status' => $status ] );
    }

    // ── Deposit helpers ────────────────────────────────────────────────────

    private function calculate_deposit_amount(): int {
        $amount = (int) get_option( 'dd_reservation_deposit_amount', 2000 );
        // Percentage type reserved for future — needs a base order value not available at booking time
        return $amount;
    }

    private function create_deposit_wc_order( array $reservation, float $deposit_amount ): WC_Order|WP_Error {
        $order = wc_create_order();
        if ( is_wp_error( $order ) ) return $order;

        $fee = new WC_Order_Item_Fee();
        $fee->set_name( 'Table Reservation Deposit — ' . $reservation['booking_ref'] );
        $fee->set_amount( $deposit_amount );
        $fee->set_total( $deposit_amount );
        $fee->set_tax_status( 'none' );
        $order->add_item( $fee );

        $order->set_billing_first_name( $reservation['name'] );
        $order->set_billing_phone( $reservation['whatsapp'] );
        $order->set_billing_email( get_option( 'admin_email' ) );

        $order->set_payment_method( 'pesapal' );
        $order->calculate_totals();
        $order->set_status( 'pending' );

        $order->update_meta_data( '_dd_reservation_id', $reservation['id'] );
        $order->update_meta_data( '_dd_booking_ref',    $reservation['booking_ref'] );
        $order->update_meta_data( '_dd_is_deposit',     1 );

        $order->save();
        return $order;
    }

    // ── Deposit payment complete ───────────────────────────────────────────

    public function on_deposit_payment_complete( int $wc_order_id ): void {
        $order = wc_get_order( $wc_order_id );
        if ( ! $order ) return;

        $is_deposit     = $order->get_meta( '_dd_is_deposit' );
        $reservation_id = (int) $order->get_meta( '_dd_reservation_id' );
        if ( ! $is_deposit || ! $reservation_id ) return;

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'dishdash_reservations',
            [
                'deposit_status'  => 'paid',
                'deposit_paid_at' => current_time( 'mysql' ),
                'payment_ref'     => (string) $wc_order_id,
                'status'          => 'confirmed',
            ],
            [ 'id' => $reservation_id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        $reservation = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dishdash_reservations WHERE id = %d",
                $reservation_id
            ),
            ARRAY_A
        );
        if ( ! $reservation ) return;

        do_action( 'dd_track_event', 'deposit_paid', null, null, [
            'booking_ref' => $reservation['booking_ref'],
            'wc_order_id' => $wc_order_id,
        ] );

        $this->send_admin_email( [
            'booking_ref'      => $reservation['booking_ref'],
            'date'             => $reservation['date'],
            'time'             => $reservation['time'],
            'session'          => $reservation['session'],
            'guests'           => $reservation['guests'],
            'table_pref'       => '',
            'name'             => $reservation['name'],
            'whatsapp'         => $reservation['whatsapp'],
            'special_requests' => $reservation['special_requests'] ?? '',
        ] );
    }

    // ── Auto-cancel cron callback ──────────────────────────────────────────

    public function run_autocancel( int $reservation_id ): void {
        global $wpdb;

        $reservation = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dishdash_reservations WHERE id = %d AND status = 'pending_payment'",
                $reservation_id
            ),
            ARRAY_A
        );

        if ( ! $reservation ) return;

        $wpdb->update(
            $wpdb->prefix . 'dishdash_reservations',
            [ 'status' => 'auto_cancelled', 'deposit_status' => 'failed' ],
            [ 'id' => $reservation_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        do_action( 'dd_track_event', 'booking_auto_cancelled', null, null, [
            'booking_ref'   => $reservation['booking_ref'],
            'hours_elapsed' => (int) get_option( 'dd_reservation_autocancel_hours', 2 ),
        ] );
    }
}
