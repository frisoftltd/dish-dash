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
require_once DD_PLUGIN_DIR . 'modules/orders/class-dd-notifications.php';

class DD_Reservations_Module extends DD_Module {

    protected string $id = 'reservations';

    public function init(): void {
        ( new DD_Reservations_Admin() )->init();
        ( new DD_Tables_Admin() )->init();

        DD_Ajax::register( 'dd_submit_reservation',        [ $this, 'ajax_submit_reservation' ] );
        DD_Ajax::register( 'dd_reservation_availability',  [ $this, 'ajax_check_availability' ] );
        DD_Ajax::register( 'dd_reservation_update_status', [ $this, 'ajax_update_status' ], false );
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

        // 6. Insert reservation
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
                'status'           => 'pending',
                'source'           => $source,
                'created_at'       => current_time( 'mysql' ),
                'updated_at'       => current_time( 'mysql' ),
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            wp_send_json_error( [
                'message'  => 'Could not save reservation. Please try again.',
                'db_error' => $wpdb->last_error,
            ] );
        }

        // 7. Build WhatsApp notification URLs
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

        // 8. Email admin
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

        $admin_link = admin_url( 'admin.php?page=dd-reservations' );

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
}
