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
        DD_Ajax::register( 'dd_reservation_pesapal_check_status', [ $this, 'ajax_pesapal_check_status' ], true );
        DD_Ajax::register( 'dd_reservation_get',             [ $this, 'ajax_get_reservation' ], false );
        DD_Ajax::register( 'dd_reservation_pesapal_request',  [ $this, 'ajax_pesapal_request_deposit' ], false );
        DD_Ajax::register( 'dd_reservation_pesapal_start',    [ $this, 'ajax_pesapal_start_customer' ], true );

        add_action( 'dd_reservation_autocancel',    [ $this, 'run_autocancel' ], 10, 1 );
        add_action( 'woocommerce_api_wc_pesapal_gateway', [ $this, 'handle_pesapal_ipn' ] );

        // Platform fee recalc (v3.14.8) — hooks onto the SAME action already
        // fired by both existing status-change entry points (ajax_update_status()
        // and the admin POST-fallback in class-dd-reservations-admin.php), so
        // neither needed to change. ajax_bulk_action() and run_autocancel() fire
        // it explicitly too (see those methods) since they update status directly
        // and never fired this hook before.
        add_action( 'dish_dash_reservation_status_changed', [ __CLASS__, 'recalculate_fee_for_reservation_status_change' ], 10, 3 );

        // Answers the orders module's billing-ledger filter (v3.14.8) — module
        // isolation: the orders module never queries wp_dishdash_reservations
        // directly, it asks via this filter instead.
        add_filter( 'dd_billing_reservation_fees_for_month', [ __CLASS__, 'filter_billing_fees_for_month' ], 10, 2 );
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
        // Fixed or per-person only (a percentage of order value has no base to
        // compute against at booking time — guests count, unlike order total, IS
        // known here, which is what makes per-person possible). When a deposit is
        // required we store the computed amount and a 'pending' deposit_status
        // (convention for this column: none|pending|claimed|paid|failed).
        // Unpaid deposit bookings ('pending'/'claimed') are auto-cancelled after the
        // Auto-Cancel window (scheduled below in step 7B; see run_autocancel()).
        $deposit_enabled = get_option( 'dd_reservation_deposit_enabled', 0 ) ? 1 : 0;
        $deposit_amount  = $deposit_enabled ? $this->calculate_deposit_amount( $guests ) : 0;
        $deposit_status  = $deposit_enabled ? 'pending' : 'none';
        $status          = 'pending';

        // Snapshot platform fee at booking time (v3.14.8) — mirrors
        // class-dd-orders-module.php's place_order() exactly: the full rate is
        // stored immediately regardless of current status/deposit state; the
        // billing query (not this column) decides what actually counts as
        // billable. Keeps past reservations' fees stable if the rate changes
        // later, same as orders.
        $fees_enabled = get_option( 'dd_fees_enabled', '1' ) === '1';
        $platform_fee = $fees_enabled ? absint( get_option( 'dd_per_reservation_fee', 750 ) ) : 0;

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
                'platform_fee'     => $platform_fee,
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d' ]
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
        $primary    = esc_attr( get_option( 'dish_dash_primary_color', '#65040d' ) );

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

            self::assign_reservation_fee_if_zero( (int) $reservation->id );

            do_action( 'dd_track_event', 'deposit_confirmed_paid', null, null, [
                'reservation_id' => (int) $reservation->id,
            ] );
        }

        wp_send_json_success( [ 'deposit_status' => 'paid', 'id' => (int) $reservation->id ] );
    }

    // ── Deposit helpers ────────────────────────────────────────────────────

    private function calculate_deposit_amount( int $guests ): int {
        $amount = (int) get_option( 'dd_reservation_deposit_amount', 2000 );
        $type   = get_option( 'dd_reservation_deposit_type', 'fixed' );

        if ( 'per_person' === $type ) {
            return $amount * $guests;
        }

        // Percentage-of-order type reserved for future — needs a base order value not available at booking time
        return $amount;
    }

    // ── PesaPal deposit orchestration ───────────────────────────────────────
    // Mirrors class-dd-orders-module.php's idempotent create-then-promote PesaPal
    // pattern, applied to wp_dishdash_reservations instead of wp_dishdash_orders.
    // Unlike orders, the reservation row always already exists by the time PesaPal
    // is involved (ajax_submit_reservation() inserts it up front regardless of
    // deposit method) — so there is no "create from pending transient" fallback
    // path here, only submit → stamp tracking id → promote on IPN/poll.
    //
    // Merchant reference prefix 'RES-' (not tracking id!) is what the shared IPN
    // uses to route between this module and the orders module. PesaPal itself
    // generates order_tracking_id (an opaque id on their side) — we cannot choose
    // its prefix. The merchant reference is the 'id' field WE send in
    // DD_PesaPal::submit_order(), and PesaPal echoes it back on both the IPN and
    // (if ever needed) the transaction status lookup. Orders use prefix 'DD-'
    // (class-dd-orders-module.php, ajax_place_order() pesapal branch) — confirmed
    // via source, not assumed — so 'RES-' cannot collide with it.

    /**
     * Does wp_dishdash_reservations have the pesapal_tracking_id column yet?
     * Cached per-request. Mirrors class-dd-orders-module.php's
     * has_pesapal_tracking_column() exactly, targeting this module's own table —
     * per the architecture rule that a module never queries another module's table,
     * this cannot be shared code even though the logic is identical.
     */
    private function has_pesapal_tracking_column(): bool {
        static $exists = null;
        if ( null !== $exists ) {
            return $exists;
        }
        global $wpdb;
        $col    = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$wpdb->prefix}dishdash_reservations LIKE %s",
            'pesapal_tracking_id'
        ) );
        $exists = ! empty( $col );
        return $exists;
    }

    /**
     * Return the dishdash_reservations row for a PesaPal tracking id, or null.
     * Mirrors find_pesapal_order() in the orders module.
     */
    private function find_pesapal_reservation( string $tracking_id ): ?object {
        if ( ! $tracking_id || ! $this->has_pesapal_tracking_column() ) {
            return null;
        }
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, booking_ref, whatsapp, name, deposit_status, deposit_amount
             FROM {$wpdb->prefix}dishdash_reservations
             WHERE pesapal_tracking_id = %s LIMIT 1",
            $tracking_id
        ) );
    }

    /**
     * Submit a reservation's deposit to PesaPal and stamp the returned tracking id
     * onto the row. Mirrors the orders module's checkout-time PesaPal branch
     * (class-dd-orders-module.php ajax_place_order(), 'pesapal' payment method) —
     * the create-then-persist half of the pattern, adapted since the reservation
     * row already exists (no "Option B" deferred-creation needed here).
     *
     * NOT YET CALLED BY ANYTHING — no AJAX action is registered for it in this
     * pass. It exists as ready plumbing for the staff-facing accept modal (Part 2),
     * which owns deciding when a deposit payment request is actually triggered.
     *
     * @return array{success:bool, redirect_url?:string, order_tracking_id?:string, error?:string}
     */
    private function submit_reservation_deposit_to_pesapal( int $reservation_id ): array {
        global $wpdb;
        $reservation = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dishdash_reservations WHERE id = %d LIMIT 1",
            $reservation_id
        ) );

        if ( ! $reservation ) {
            return [ 'success' => false, 'error' => 'Reservation not found.' ];
        }
        if ( (int) $reservation->deposit_required !== 1 ) {
            return [ 'success' => false, 'error' => 'This booking has no deposit.' ];
        }
        if ( 'paid' === $reservation->deposit_status ) {
            return [ 'success' => false, 'error' => 'Deposit already paid.' ];
        }
        if ( $this->has_pesapal_tracking_column() && ! empty( $reservation->pesapal_tracking_id ) ) {
            return [ 'success' => false, 'error' => 'A PesaPal payment has already been requested for this booking.' ];
        }

        $pesapal = new DD_PesaPal();
        if ( ! $pesapal->is_configured() ) {
            return [ 'success' => false, 'error' => 'PesaPal is not configured. Please contact the restaurant.' ];
        }

        $ref = 'RES-' . strtoupper( substr( md5( $reservation->whatsapp . microtime() ), 0, 12 ) );

        $result = $pesapal->submit_order(
            (float) $reservation->deposit_amount,
            get_woocommerce_currency(),
            $ref,
            'Reservation deposit — ' . $reservation->booking_ref,
            $reservation->whatsapp,
            $reservation->name
        );

        if ( ! $result['success'] ) {
            return [ 'success' => false, 'error' => $result['error'] ];
        }

        if ( $this->has_pesapal_tracking_column() ) {
            $wpdb->update(
                $wpdb->prefix . 'dishdash_reservations',
                [ 'pesapal_tracking_id' => $result['order_tracking_id'] ],
                [ 'id' => $reservation_id ],
                [ '%s' ],
                [ '%d' ]
            );
        }

        return [
            'success'           => true,
            'redirect_url'      => $result['redirect_url'],
            'order_tracking_id' => $result['order_tracking_id'],
        ];
    }

    /**
     * Promote a reservation's deposit from pending|claimed → paid exactly once.
     * Mirrors promote_pesapal_order() — the conditional UPDATE only ever affects a
     * row still in a not-yet-confirmed state, so a racing IPN + poll (or a PesaPal
     * retry) can never double-fire. Writes the SAME 'paid' value and stamps
     * deposit_paid_at exactly like the manual "Mark deposit paid" admin button
     * (ajax_mark_deposit_paid()), so run_autocancel() recognizes it identically —
     * this was the explicit risk flagged in the investigation: a parallel status
     * value here would let auto-cancel wrongly kill a PesaPal-paid booking.
     */
    private function promote_pesapal_reservation( object $reservation, string $tracking_id ): void {
        global $wpdb;

        $promoted = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}dishdash_reservations
             SET deposit_status = 'paid', deposit_paid_at = %s
             WHERE id = %d AND deposit_status IN ( 'pending', 'claimed' )",
            current_time( 'mysql' ),
            (int) $reservation->id
        ) );

        if ( $promoted ) {
            self::assign_reservation_fee_if_zero( (int) $reservation->id );

            do_action( 'dd_track_event', 'deposit_confirmed_paid', null, null, [
                'reservation_id' => (int) $reservation->id,
                'method'         => 'pesapal',
            ] );
            // Staff notification — mirrors the orders module's PesaPal promote path
            // (fire_pesapal_notifications(), the only channel that fires unattended
            // from a server-side IPN with no user gesture to hang a tap-only WhatsApp
            // link on). This module already owns its own admin-email sender
            // (send_admin_email(), used for "new reservation") rather than delegating
            // to DD_Notifications' order-shaped one — same channel, module-appropriate
            // implementation, per architecture rules.
            $this->send_deposit_paid_email( $reservation );
        }
        // $promoted === 0 → already paid (a racing caller won) or somehow already
        // past 'pending'/'claimed' — no-op, matches promote_pesapal_order()'s
        // "already paid / raced" no-re-notify behaviour (and correctly skips a
        // duplicate email on the losing side of a race).
    }

    /**
     * Notify the restaurant that a reservation deposit was just paid via PesaPal,
     * unattended (no staff click triggered this — the IPN or a customer's poll did).
     * Mirrors send_admin_email()'s template/option-read pattern exactly, new subject
     * and content for this different event.
     */
    private function send_deposit_paid_email( object $reservation ): void {
        $admin_email = get_option( 'dd_admin_email', get_option( 'admin_email' ) );
        if ( ! $admin_email || ! is_email( $admin_email ) ) {
            return;
        }

        $restaurant = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );
        $primary    = esc_attr( get_option( 'dish_dash_primary_color', '#65040d' ) );
        $subject    = sprintf( '[%s] Deposit Paid — %s', $restaurant, $reservation->booking_ref );

        $admin_link = add_query_arg(
            [ 'page' => 'dd-reservations', 's' => $reservation->booking_ref ],
            admin_url( 'admin.php' )
        );

        $body = '
<div style="background:#F5EFE6;padding:24px 0;font-family:\'Segoe UI\',Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center">
      <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="max-width:520px;width:100%;background:#FBF7F1;border-radius:12px;overflow:hidden;">

        <!-- Header -->
        <tr><td style="background:' . $primary . ';padding:24px 28px;">
          <div style="color:#fff;font-size:18px;font-weight:700;">💳 Deposit Paid via PesaPal</div>
          <div style="color:#E6C9CC;font-size:13px;margin-top:4px;">' . esc_html( $restaurant ) . '</div>
        </td></tr>

        <!-- Booking ref banner -->
        <tr><td style="padding:20px 28px 8px;">
          <div style="color:#6E5B4C;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Booking Reference</div>
          <div style="color:' . $primary . ';font-size:22px;font-weight:700;letter-spacing:1px;">' . esc_html( $reservation->booking_ref ) . '</div>
        </td></tr>

        <!-- Details -->
        <tr><td style="padding:8px 28px 4px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;border-top:1px solid #EADFCE;">
            <tr><td style="padding:10px 0 6px;color:#6E5B4C;">Customer</td>
                <td style="padding:10px 0 6px;text-align:right;font-weight:600;color:#221B19;">' . esc_html( $reservation->name ) . '</td></tr>
            <tr><td style="padding:6px 0;color:#6E5B4C;">Amount</td>
                <td style="padding:6px 0;text-align:right;font-weight:600;color:#221B19;">' . esc_html( number_format( (int) $reservation->deposit_amount ) ) . ' RWF</td></tr>
          </table>
        </td></tr>

        <!-- CTA button -->
        <tr><td style="padding:20px 28px 28px;" align="center">
          <a href="' . esc_url( $admin_link ) . '"
             style="display:inline-block;background:' . $primary . ';color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:14px 32px;border-radius:8px;">
             View Reservation →
          </a>
        </td></tr>

        <!-- Footer -->
        <tr><td style="background:#F0E7D8;padding:14px 28px;text-align:center;">
          <div style="color:#6E5B4C;font-size:12px;">' . esc_html( $restaurant ) . ' reservation system</div>
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

    // ── AJAX: Reservation-deposit PesaPal status poll (guest, unauthenticated) ──
    // Mirrors ajax_pesapal_check_status() in the orders module. nopriv=true —
    // reservation customers are never logged in, same reasoning as the order poll.

    public function ajax_pesapal_check_status(): void {
        DD_Ajax::verify_nonce();

        $tracking_id = sanitize_text_field( $_POST['order_tracking_id'] ?? '' );
        if ( ! $tracking_id ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ] );
            return;
        }

        $existing = $this->find_pesapal_reservation( $tracking_id );
        if ( ! $existing ) {
            wp_send_json_error( [ 'message' => 'Reservation not found for this payment.' ] );
            return;
        }

        if ( 'paid' === $existing->deposit_status ) {
            wp_send_json_success( [
                'paid'        => true,
                'status'      => 'COMPLETED',
                'booking_ref' => $existing->booking_ref,
            ] );
            return;
        }

        // Verify the real status server-side (authoritative numeric status_code) —
        // never trust the client for this.
        $pesapal = new DD_PesaPal();
        $status  = $pesapal->get_transaction_status( $tracking_id );

        if ( 'COMPLETED' === $status ) {
            $this->promote_pesapal_reservation( $existing, $tracking_id );
            wp_send_json_success( [
                'paid'        => true,
                'status'      => 'COMPLETED',
                'booking_ref' => $existing->booking_ref,
            ] );
            return;
        }

        if ( in_array( $status, [ 'FAILED', 'REVERSED' ], true ) ) {
            wp_send_json_success( [ 'paid' => false, 'status' => $status ] );
            return;
        }

        // INVALID / PENDING / UNKNOWN → not terminal: keep polling.
        wp_send_json_success( [ 'paid' => false, 'status' => 'PENDING' ] );
    }

    // ── PesaPal server-to-server IPN — reservation deposits only ────────────
    // Registered on the SAME 'woocommerce_api_wc_pesapal_gateway' action as the
    // orders module's handler (DD_PesaPal::submit_order() hardcodes one shared
    // callback_url for every caller — there is no way to give reservations their
    // own URL without changing that generic class). Both handlers can be
    // registered on one WP action; each independently no-ops (a plain `return`,
    // NOT an exit) when the merchant reference isn't theirs, so exactly one of
    // them ever reaches its own pesapal_ipn_respond() → exit. The orders module's
    // handle_pesapal_ipn() has the symmetric guard (skips 'RES-' merchant refs).

    public function handle_pesapal_ipn(): void {
        $tracking_id = sanitize_text_field(
            $_REQUEST['OrderTrackingId'] ?? ( $_REQUEST['orderTrackingId'] ?? '' )
        );
        $merchant_ref = sanitize_text_field(
            $_REQUEST['OrderMerchantReference'] ?? ( $_REQUEST['orderMerchantReference'] ?? '' )
        );

        if ( ! $merchant_ref || ! str_starts_with( $merchant_ref, 'RES-' ) ) {
            return; // Not a reservation deposit — the orders module's handler owns it.
        }

        if ( ! $tracking_id ) {
            $this->pesapal_ipn_respond( 400, '', $merchant_ref );
            return;
        }

        $existing = $this->find_pesapal_reservation( $tracking_id );

        if ( ! $existing ) {
            // Exactly the silent-failure risk flagged in the investigation: a
            // 'RES-' IPN with no matching row must be logged loudly, not just
            // acknowledged and dropped.
            error_log( 'DD PesaPal Reservation IPN: RES- merchant_ref=' . $merchant_ref . ' tracking_id=' . $tracking_id . ' — no matching reservation row. Needs manual reconciliation.' );
            $this->pesapal_ipn_respond( 200, $tracking_id, $merchant_ref );
            return;
        }

        if ( 'paid' === $existing->deposit_status ) {
            $this->pesapal_ipn_respond( 200, $tracking_id, $merchant_ref );
            return;
        }

        $pesapal = new DD_PesaPal();
        $status  = $pesapal->get_transaction_status( $tracking_id );

        if ( 'COMPLETED' === $status ) {
            $this->promote_pesapal_reservation( $existing, $tracking_id );
        } elseif ( in_array( $status, [ 'FAILED', 'REVERSED' ], true ) ) {
            global $wpdb;
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}dishdash_reservations
                 SET deposit_status = 'failed'
                 WHERE id = %d AND deposit_status IN ( 'pending', 'claimed' )",
                (int) $existing->id
            ) );
        }
        // PENDING / INVALID / UNKNOWN → leave as-is; PesaPal re-notifies.

        $this->pesapal_ipn_respond( 200, $tracking_id, $merchant_ref );
    }

    /**
     * Emit the PesaPal IPN acknowledgement JSON. Duplicated (not shared) from the
     * orders module's private method of the same purpose — matches this
     * codebase's own precedent for cross-module-identical-but-separate helpers
     * (see RELEASE.md v3.10.74, footers "kept DUPLICATED, no shared helper").
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

    // ── AJAX: Admin — fetch one reservation (accept modal) ──────────────────
    // Same auth pattern as ajax_mark_deposit_paid(): admin nonce + capability
    // check, registered nopriv=false — matches every other staff-only action in
    // this module.

    public function ajax_get_reservation(): void {
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
            "SELECT * FROM {$wpdb->prefix}dishdash_reservations WHERE id = %d LIMIT 1",
            $id
        ) );

        if ( ! $reservation ) {
            wp_send_json_error( [ 'message' => 'Reservation not found.' ] );
            return;
        }

        // Resolve the proof screenshot (if any) to a displayable URL for the
        // accept modal — the row itself only stores the attachment ID.
        $reservation->deposit_proof_url = $reservation->deposit_proof_attachment_id
            ? ( wp_get_attachment_image_url( (int) $reservation->deposit_proof_attachment_id, 'medium' ) ?: '' )
            : '';

        wp_send_json_success( [ 'reservation' => $reservation ] );
    }

    // ── AJAX: Admin — request PesaPal deposit payment (accept modal) ────────
    // Staff-triggered call into submit_reservation_deposit_to_pesapal() (Part 1,
    // previously unwired). Returns a ready wa.me URL (built here, server-side,
    // matching the rest of this module's inline-WhatsApp-URL convention — see
    // render_page()'s "Send Confirmation" button — rather than DD_Notifications,
    // which is orders-shaped) so staff can hand the PesaPal payment link to the
    // customer with one tap.

    public function ajax_pesapal_request_deposit(): void {
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

        $result = $this->submit_reservation_deposit_to_pesapal( $id );
        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
            return;
        }

        global $wpdb;
        $reservation = $wpdb->get_row( $wpdb->prepare(
            "SELECT booking_ref, name, whatsapp, deposit_amount
             FROM {$wpdb->prefix}dishdash_reservations WHERE id = %d LIMIT 1",
            $id
        ) );

        $whatsapp_url = '';
        $wa_num       = $reservation ? preg_replace( '/\D/', '', $reservation->whatsapp ) : '';
        if ( $reservation && $wa_num ) {
            $restaurant = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );
            $lines      = [
                'DEPOSIT PAYMENT LINK 💳',
                $restaurant,
                '',
                "Hi {$reservation->name}, please complete your deposit payment to secure your table:",
                '',
                "Ref: {$reservation->booking_ref}",
                'Amount: ' . number_format( (int) $reservation->deposit_amount ) . ' RWF',
                '',
                'Pay here: ' . $result['redirect_url'],
                '',
                'Your booking will be confirmed once payment is received.',
            ];
            $whatsapp_url = 'https://wa.me/' . $wa_num . '?text=' . rawurlencode( implode( "\n", $lines ) );
        }

        wp_send_json_success( [
            'redirect_url' => $result['redirect_url'],
            'whatsapp_url' => $whatsapp_url,
        ] );
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

        // Optional payment-proof screenshot (v3.14.2). Never blocks the claim —
        // an upload failure just means no proof gets stored; the claim itself
        // still succeeds exactly as it did before this existed.
        $attachment_id = $this->maybe_upload_deposit_proof();

        $update_fields  = [];
        $update_formats = [];

        // Only advance from the up-front 'pending' state (idempotent; double-tap = no-op).
        $was_pending = ( 'pending' === $reservation->deposit_status );
        if ( $was_pending ) {
            $update_fields['deposit_status'] = 'claimed';
            $update_formats[]                = '%s';
        }

        if ( $attachment_id ) {
            $update_fields['deposit_proof_attachment_id'] = $attachment_id;
            $update_formats[]                              = '%d';
        }

        if ( $update_fields ) {
            $wpdb->update(
                $wpdb->prefix . 'dishdash_reservations',
                $update_fields,
                [ 'id' => (int) $reservation->id ],
                $update_formats,
                [ '%d' ]
            );
        }

        if ( $was_pending ) {
            do_action( 'dd_track_event', 'deposit_claimed', null, null, [
                'booking_ref' => $booking_ref,
                'has_proof'   => (bool) $attachment_id,
            ] );
        }

        wp_send_json_success( [
            'claimed'        => true,
            'booking_ref'    => $booking_ref,
            'proof_uploaded' => (bool) $attachment_id,
        ] );
    }

    /**
     * Handle an optional deposit-proof screenshot upload via the WP Media
     * Library. Restricted to image mime types (checked by actual file
     * content, not the client-supplied extension/MIME header) since this is
     * a nopriv endpoint. Returns the attachment ID, or 0 if no file was
     * provided, the type isn't an allowed image, or the upload failed.
     */
    private function maybe_upload_deposit_proof(): int {
        if ( empty( $_FILES['deposit_proof']['tmp_name'] ) || ! is_uploaded_file( $_FILES['deposit_proof']['tmp_name'] ) ) {
            return 0;
        }

        $filetype = wp_check_filetype_and_ext( $_FILES['deposit_proof']['tmp_name'], $_FILES['deposit_proof']['name'] );
        $allowed  = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];
        if ( empty( $filetype['type'] ) || ! in_array( $filetype['type'], $allowed, true ) ) {
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'deposit_proof', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            error_log( 'DD reservation deposit proof upload failed: ' . $attachment_id->get_error_message() );
            return 0;
        }

        return (int) $attachment_id;
    }

    // ── AJAX: Customer-initiated PesaPal deposit request (guest, unauthenticated) ──
    // Customer-facing counterpart to ajax_pesapal_request_deposit() (admin-only,
    // staff-triggered). Keyed on booking_ref, same auth pattern as
    // ajax_claim_deposit() — the customer never has the numeric reservation id.
    // Delegates to the SAME submit_reservation_deposit_to_pesapal() the admin path
    // already uses; no duplicated PesaPal order-building logic.
    public function ajax_pesapal_start_customer(): void {
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
            wp_send_json_error( [ 'message' => 'This booking has no deposit.' ] );
            return;
        }

        if ( 'paid' === $reservation->deposit_status ) {
            wp_send_json_error( [ 'message' => 'Deposit already paid.' ] );
            return;
        }

        $result = $this->submit_reservation_deposit_to_pesapal( (int) $reservation->id );
        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
            return;
        }

        wp_send_json_success( [
            'redirect_url'      => $result['redirect_url'],
            'order_tracking_id' => $result['order_tracking_id'],
            'booking_ref'       => $booking_ref,
        ] );
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
            // Capture pre-update status per row so the fee recalc (fired below,
            // same hook the single-row status-change paths already use) has a
            // real old→new pair for each reservation — a multi-row UPDATE can't
            // give us that after the fact.
            $old_statuses = $wpdb->get_results(
                $wpdb->prepare( "SELECT id, status FROM {$table} WHERE id IN ({$placeholders})", ...$ids ),
                OBJECT_K
            );

            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET status = %s WHERE id IN ({$placeholders})",
                ...array_merge( [ $action ], $ids )
            ) );

            foreach ( $ids as $rid ) {
                $old_status = isset( $old_statuses[ $rid ] ) ? $old_statuses[ $rid ]->status : '';
                do_action( 'dish_dash_reservation_status_changed', $rid, $old_status, $action );
            }

            wp_send_json_success( count( $ids ) . ' reservation(s) updated' );
        } else {
            wp_send_json_error( 'Unknown action' );
        }
    }

    // ── Platform fee recalculation (v3.14.8, widened v3.15.1) ───────────────
    // Mirrors class-dd-orders-module.php's recalculate_fee_for_status_change()
    // exactly: same signature shape (id, old_status, new_status), same
    // idempotent skip-if-already-at-target, same "terminal state zeroes the
    // fee" rule (UNCHANGED below — this widening only ever touches WHEN a
    // zero fee gets assigned, never the zero-on-cancel logic itself).
    //
    // v3.15.1 gap fix: originally only assigned a fee when *reopening* from a
    // terminal state (cancelled/no_show/auto_cancelled). That left any
    // reservation created before v3.15.0 shipped — platform_fee=0 because it
    // predates the snapshot-at-insert logic, added by dbDelta() with
    // DEFAULT 0 and never backfilled — permanently stuck at fee=0 even after
    // a completely normal pending→confirmed transition, since that isn't
    // "leaving a terminal state." Now also assigns on a plain confirm for a
    // zero-fee, no-deposit row. The deposit-required equivalent
    // (deposit_status→'paid') can't be handled here — deposit_status changes
    // independently of status, and this function only ever sees status
    // transitions — see assign_reservation_fee_if_zero(), called directly
    // from ajax_mark_deposit_paid() and promote_pesapal_reservation().
    public static function recalculate_fee_for_reservation_status_change( int $reservation_id, string $old_status, string $new_status ): void {
        if ( $reservation_id <= 0 || $old_status === $new_status ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_reservations';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT platform_fee, deposit_required FROM {$table} WHERE id = %d",
            $reservation_id
        ) );

        if ( ! $row ) {
            return; // row not found
        }
        $current_fee = (int) $row->platform_fee;

        $terminal   = [ 'cancelled', 'no_show', 'auto_cancelled' ];
        $target_fee = null;

        if ( in_array( $new_status, $terminal, true ) ) {
            $target_fee = 0;
        } elseif ( $current_fee === 0 && (
            in_array( $old_status, $terminal, true )
            || ( 'confirmed' === $new_status && (int) $row->deposit_required === 0 )
        ) ) {
            // Reopened from a terminal state, OR a plain confirm on a
            // zero-fee no-deposit row (v3.15.1). Restore/assign at the
            // CURRENT rate. Whether it's actually billable yet is still
            // entirely the billing query's job (status='confirmed' or
            // deposit_status='paid', depending on deposit_required).
            $fees_enabled = get_option( 'dd_fees_enabled', '1' ) === '1';
            $target_fee   = $fees_enabled ? absint( get_option( 'dd_per_reservation_fee', 750 ) ) : 0;
        }

        if ( null === $target_fee || $current_fee === $target_fee ) {
            return;
        }

        $wpdb->update(
            $table,
            [ 'platform_fee' => $target_fee ],
            [ 'id'           => $reservation_id ],
            [ '%d' ],
            [ '%d' ]
        );
    }

    /**
     * Assign platform_fee at the current rate when it's currently 0 (v3.15.1).
     * Called directly from the two places deposit_status becomes 'paid'
     * (ajax_mark_deposit_paid(), promote_pesapal_reservation()) — deposit_status
     * changes independently of status, so recalculate_fee_for_reservation_status_change()
     * (hooked on the status-change action) never sees these transitions.
     * Idempotent: no-op if the fee is already anything but 0, so it's safe to
     * call unconditionally on every deposit-paid confirmation, including
     * ones that already had their fee snapshotted at booking time.
     */
    private static function assign_reservation_fee_if_zero( int $reservation_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_reservations';

        $current_fee = $wpdb->get_var( $wpdb->prepare(
            "SELECT platform_fee FROM {$table} WHERE id = %d",
            $reservation_id
        ) );
        if ( null === $current_fee || (int) $current_fee !== 0 ) {
            return;
        }

        $fees_enabled = get_option( 'dd_fees_enabled', '1' ) === '1';
        $rate         = $fees_enabled ? absint( get_option( 'dd_per_reservation_fee', 750 ) ) : 0;
        if ( $rate === 0 ) {
            return;
        }

        $wpdb->update(
            $table,
            [ 'platform_fee' => $rate ],
            [ 'id'           => $reservation_id ],
            [ '%d' ],
            [ '%d' ]
        );
    }

    /**
     * Answers 'dd_billing_reservation_fees_for_month' — sum of billable
     * reservation platform_fee for a given Y-m month. Same billable test
     * used in admin/pages/billing.php's reservations section. Called from
     * class-dd-orders-module.php's ajax_mark_month_paid() so the combined
     * monthly ledger includes reservation fees without that module querying
     * this one's table directly.
     */
    public static function filter_billing_fees_for_month( int $default, string $month ): int {
        if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
            return $default;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_reservations';
        $ct    = $wpdb->prefix . 'dishdash_customers';

        // Test-customer exclusion (v3.15.6): LEFT JOIN + NULL-safe WHERE, never
        // INNER, so orphan reservations (no resolvable customer link) still get
        // billed. See investigation-testflag.md §1.
        $amount = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(r.platform_fee),0) FROM `{$table}` r
             LEFT JOIN `{$ct}` c ON c.id = r.customer_id
             WHERE r.platform_fee > 0 AND (
                 ( r.deposit_required = 1 AND r.deposit_status = 'paid' )
                 OR ( r.deposit_required = 0 AND r.status = 'confirmed' )
             )
             AND DATE_FORMAT(r.created_at, '%%Y-%%m') = %s
             AND r.is_test = 0 AND (c.is_test IS NULL OR c.is_test = 0)",
            $month
        ) );

        return null === $amount ? $default : (int) $amount;
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

        // Fires the same hook ajax_update_status() and the admin POST-fallback
        // already fire — recalculate_fee_for_reservation_status_change() is
        // hooked onto it in init() and will zero the fee (auto_cancelled is a
        // terminal status).
        do_action( 'dish_dash_reservation_status_changed', $reservation_id, $reservation['status'], 'auto_cancelled' );

        do_action( 'dd_track_event', 'booking_auto_cancelled', null, null, [
            'booking_ref'   => $reservation['booking_ref'],
            'hours_elapsed' => (int) get_option( 'dd_reservation_autocancel_hours', 2 ),
        ] );
    }
}
