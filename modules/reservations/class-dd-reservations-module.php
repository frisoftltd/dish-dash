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
        add_action( 'admin_head', [ $this, 'hide_sidebar_links' ] );

        DD_Ajax::register( 'dd_submit_reservation',        [ $this, 'ajax_submit_reservation' ] );
        DD_Ajax::register( 'dd_reservation_availability',  [ $this, 'ajax_check_availability' ] );
        DD_Ajax::register( 'dd_reservation_claim_deposit', [ $this, 'ajax_claim_deposit'    ], true );
        DD_Ajax::register( 'dd_reservation_mark_deposit_paid', [ $this, 'ajax_mark_deposit_paid' ], false );
        DD_Ajax::register( 'dd_reservation_update_status', [ $this, 'ajax_update_status' ], false );
        DD_Ajax::register( 'dd_res_bulk_action',           [ $this, 'ajax_bulk_action'    ], false );

        add_action( 'dd_reservation_autocancel',    [ $this, 'run_autocancel' ], 10, 1 );
    }

    public function hide_sidebar_links(): void {
        ?>
        <style>
        .toplevel_page_dish-dash .wp-submenu a[href*="dd-tables"],
        .toplevel_page_dish-dash .wp-submenu a[href*="dd-sections"] {
            display: none !important;
        }
        </style>
        <?php
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

        // 4. Customer identity — delegated to customer domain via filter
        $customer_id = (int) apply_filters( 'dd_resolve_customer_id', 0, $whatsapp, $name );

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

        // 6. Deposit check — determines status and extra columns.
        // Fixed deposits only (percent has no base value at booking time). When a
        // deposit is required we store the real fixed amount and a 'pending'
        // deposit_status (convention for this column: none|pending|claimed|paid|failed).
        // Unpaid deposit bookings ('pending'/'claimed') are auto-cancelled after the
        // Auto-Cancel window (scheduled below in step 7B; see run_autocancel()).
        $deposit_enabled = get_option( 'dd_reservation_deposit_enabled', 0 ) ? 1 : 0;
        $deposit_amount  = $deposit_enabled ? $this->calculate_deposit_amount() : 0;
        $deposit_status  = $deposit_enabled ? 'pending' : 'none';
        $status          = 'pending';

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

        // 7B. Schedule auto-cancel for unpaid deposit bookings. Per-booking single
        // event, matching the run_autocancel( int $reservation_id ) hook signature: it
        // fires after the Auto-Cancel window (dd_reservation_autocancel_hours, default 2),
        // and run_autocancel() then cancels the booking UNLESS the restaurant has since
        // confirmed the deposit as paid. A customer's "I have paid" claim does NOT stop
        // this — only deposit_status='paid' is safe (see run_autocancel()).
        if ( $deposit_enabled ) {
            $autocancel_hours = (int) get_option( 'dd_reservation_autocancel_hours', 2 );
            wp_schedule_single_event(
                time() + ( $autocancel_hours * HOUR_IN_SECONDS ),
                'dd_reservation_autocancel',
                [ $reservation_id ]
            );
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

        // Footer attribution — same option the site footer copyright uses (v3.10.70).
        // Rendered strings live here, not the DB. 'none' drops the prefix AND the separator.
        $attrib        = get_option( 'dish_dash_footer_attribution', 'frisoft' );
        $attrib_prefix = '';
        if ( 'dishdash' === $attrib ) {
            $attrib_prefix = 'Dish Dash — ';
        } elseif ( 'none' !== $attrib ) {
            $attrib_prefix = 'Fri Soft Ltd — ';
        }

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
          <div style="color:#6E5B4C;font-size:12px;">' . $attrib_prefix . esc_html( $restaurant ) . ' reservation system</div>
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

        $old_status_row = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}dishdash_reservations WHERE id = %d",
            $id
        ) );
        $wpdb->update(
            $wpdb->prefix . 'dishdash_reservations',
            [ 'status' => $status ],
            [ 'id'     => $id ],
            [ '%s' ],
            [ '%d' ]
        );
        do_action( 'dish_dash_reservation_status_changed', $id, $old_status_row, $status );

        wp_send_json_success( [ 'status' => $status ] );
    }

    // ── AJAX: Admin — mark a deposit as PAID (restaurant-confirmed) ─────────
    // The ONLY state that stops auto-cancel (v3.10.63). A human at the restaurant
    // confirms real money landed (checked their MTN MoMo SMS against the booking
    // reference) — there is no API to verify it (the manual QR path exists to avoid
    // the Collections fee). Idempotent: only pending|claimed → paid; re-tap = no-op.
    // Does NOT unschedule the cron — run_autocancel() already skips 'paid', so the
    // event fires and harmlessly no-ops (the guard stays the single source of truth).
    public function ajax_mark_deposit_paid(): void {
        DD_Ajax::verify_nonce( 'nonce', 'dish_dash_admin' );

        if ( ! current_user_can( 'dd_manage_reservations' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
            return;
        }

        $id = intval( $_POST['id'] ?? 0 );
        if ( $id < 1 ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ] );
            return;
        }

        global $wpdb;
        $reservation = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, deposit_required, deposit_status
             FROM {$wpdb->prefix}dishdash_reservations WHERE id = %d LIMIT 1",
            $id
        ) );

        if ( ! $reservation ) {
            wp_send_json_error( [ 'message' => 'Booking not found.' ] );
            return;
        }

        if ( (int) $reservation->deposit_required !== 1 ) {
            wp_send_json_error( [ 'message' => 'This booking has no deposit.' ] );
            return;
        }

        // Only confirm from pending|claimed (idempotent; re-tap on 'paid' = no-op).
        if ( in_array( $reservation->deposit_status, [ 'pending', 'claimed' ], true ) ) {
            $wpdb->update(
                $wpdb->prefix . 'dishdash_reservations',
                [ 'deposit_status' => 'paid', 'deposit_paid_at' => current_time( 'mysql' ) ],
                [ 'id'             => (int) $reservation->id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );

            do_action( 'dd_track_event', 'deposit_confirmed_paid', null, null, [
                'reservation_id' => (int) $reservation->id,
            ] );
        }

        wp_send_json_success( [ 'deposit_status' => 'paid', 'id' => (int) $reservation->id ] );
    }

    // ── Deposit helpers ────────────────────────────────────────────────────

    private function calculate_deposit_amount(): int {
        $amount = (int) get_option( 'dd_reservation_deposit_amount', 2000 );
        // Percentage type reserved for future — needs a base order value not available at booking time
        return $amount;
    }

    // ── AJAX: Customer deposit claim ("I have paid") ───────────────────────
    // Records an UNVERIFIED customer attestation that they paid the deposit.
    // Flips deposit_status 'pending' → 'claimed'. Keyed on booking_ref (no
    // reservation id is available client-side). Idempotent (only advances from
    // 'pending'); NEVER sets 'paid' (that means restaurant-confirmed). This does
    // NOT stop auto-cancel — run_autocancel() still cancels 'claimed' (v3.10.63):
    // only a restaurant-confirmed 'paid' saves the booking.
    public function ajax_claim_deposit(): void {
        DD_Ajax::verify_nonce();

        $booking_ref = sanitize_text_field( wp_unslash( $_POST['booking_ref'] ?? '' ) );
        if ( '' === $booking_ref ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ] );
            return;
        }

        global $wpdb;
        $reservation = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, deposit_required, deposit_status
             FROM {$wpdb->prefix}dishdash_reservations WHERE booking_ref = %s LIMIT 1",
            $booking_ref
        ) );

        if ( ! $reservation ) {
            wp_send_json_error( [ 'message' => 'Booking not found.' ] );
            return;
        }

        if ( (int) $reservation->deposit_required !== 1 ) {
            wp_send_json_error( [ 'message' => 'This booking has no deposit to claim.' ] );
            return;
        }

        // Only advance from the up-front 'pending' state (idempotent; double-tap = no-op).
        if ( 'pending' === $reservation->deposit_status ) {
            $wpdb->update(
                $wpdb->prefix . 'dishdash_reservations',
                [ 'deposit_status' => 'claimed' ],
                [ 'id'             => (int) $reservation->id ],
                [ '%s' ],
                [ '%d' ]
            );

            do_action( 'dd_track_event', 'deposit_claimed', null, null, [
                'booking_ref' => $booking_ref,
            ] );
        }

        wp_send_json_success( [ 'claimed' => true, 'booking_ref' => $booking_ref ] );
    }

    // ── AJAX: Bulk action ──────────────────────────────────────────────────

    public function ajax_bulk_action(): void {
        check_ajax_referer( 'dish_dash_admin', 'nonce' );
        if ( ! current_user_can( 'dd_manage_reservations' ) ) wp_send_json_error( 'Unauthorized' );

        global $wpdb;
        $table  = $wpdb->prefix . 'dishdash_reservations';
        $action = sanitize_key( $_POST['bulk_action'] ?? '' );
        $ids    = array_map( 'absint', explode( ',', $_POST['ids'] ?? '' ) );
        $ids    = array_filter( $ids );

        if ( empty( $ids ) || ! $action ) {
            wp_send_json_error( 'Invalid request' );
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        if ( $action === 'mark_test' ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_test = 1 WHERE id IN ({$placeholders})", ...$ids ) );
            wp_send_json_success( count( $ids ) . ' reservation(s) marked as test' );
        } elseif ( $action === 'unmark_test' ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_test = 0 WHERE id IN ({$placeholders})", ...$ids ) );
            wp_send_json_success( count( $ids ) . ' test flag(s) removed' );
        } elseif ( in_array( $action, [ 'confirmed', 'cancelled', 'no_show' ], true ) ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET status = %s WHERE id IN ({$placeholders})",
                ...array_merge( [ $action ], $ids )
            ) );
            wp_send_json_success( count( $ids ) . ' reservation(s) updated' );
        } else {
            wp_send_json_error( 'Unknown action' );
        }
    }

    // ── Auto-cancel cron callback ──────────────────────────────────────────

    public function run_autocancel( int $reservation_id ): void {
        global $wpdb;

        // Cancel only if this booking still requires a deposit that is NOT restaurant-
        // confirmed. deposit_status IN ('pending','claimed') → cancel; 'none' (no deposit),
        // 'paid' (confirmed) and 'failed' (already cancelled) are safe. A customer claim
        // ('claimed') is an unverified attestation and therefore still cancels on schedule —
        // only 'paid' stops the timer. No time check needed: the single event's fire time
        // is the window (and reading the current window here would mis-handle a changed setting).
        $reservation = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dishdash_reservations
                  WHERE id = %d
                    AND deposit_required = 1
                    AND deposit_status IN ( 'pending', 'claimed' )",
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
