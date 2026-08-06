<?php
/**
 * File:    dishdash-core/class-dd-helpers.php
 * Purpose: Global procedural helper functions available everywhere in the
 *          plugin — all wrapped in if(!function_exists()) guards.
 *
 * Dependencies (this file needs):
 *   - DD_Settings class (for dd_price currency lookup)
 *   - $wpdb global (for dd_get_branches, dd_get_branch)
 *   - ABSPATH (WordPress core)
 *
 * Dependents (files that need this):
 *   - Loaded by dishdash-core/class-dd-loader.php during boot
 *   - Used by modules/orders/class-dd-orders-module.php (dd_generate_order_number,
 *     dd_order_status_transitions, dd_price, dd_valid_order_type)
 *   - Used by modules/homepage/class-dd-homepage-module.php (dd_is_enabled)
 *   - Used by templates/page-dishdash.php (dd_cart_url, dd_menu_url etc.)
 *
 * Functions defined:
 *   dd_price(), dd_generate_order_number(), dd_is_platform_admin(),
 *   dd_invoice_default_prefix(), dd_invoice_get_data(),
 *   dd_invoice_build_body_html(), dd_invoice_render_page(),
 *   dd_invoice_resolve_local_logo_path(), dd_invoice_stream_pdf(),
 *   dd_get_branches(), dd_get_branch(), dd_get_current_branch_id(),
 *   dd_is_enabled(), dd_valid_order_type(), dd_order_status_transitions(),
 *   dd_order_status_label(), dd_log(), dd_menu_url(), dd_cart_url(),
 *   dd_checkout_url(), dd_track_url()
 *
 * Last modified: v3.18.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Format a price value using plugin currency settings.
 */
function dd_price( float $amount ): string {
    $symbol   = get_option( 'dish_dash_currency_symbol', '$' );
    $position = get_option( 'dish_dash_currency_position', 'before' );
    $formatted = number_format( $amount, 2 );

    return 'before' === $position
        ? $symbol . $formatted
        : $formatted . $symbol;
}

/**
 * Generate a unique Dish Dash order number.
 * Format: DD-00001, DD-00002, …
 */
function dd_generate_order_number(): string {
    $prefix  = get_option( 'dish_dash_order_prefix', 'DD-' );
    $counter = (int) get_option( 'dish_dash_order_counter', 0 );
    $counter++;
    update_option( 'dish_dash_order_counter', $counter );
    return $prefix . str_pad( $counter, 5, '0', STR_PAD_LEFT );
}

/**
 * True only for platform-level admins (Fri Soft staff / WordPress
 * Administrator), never for dd_restaurant_owner or dd_restaurant_manager —
 * both of those DishDash roles are granted manage_options DIRECTLY
 * (install.php's register_roles()), so a plain current_user_can('manage_options')
 * check cannot tell a true site administrator apart from a restaurant owner/
 * manager. Role-exclusion first, capability second — same pattern already
 * proven by admin/pages/csv-menu-import.php's access gate. Use this for any
 * "admin-only, not owner/manager" gate (v3.18.0, billing actions).
 */
function dd_is_platform_admin(): bool {
    $user             = wp_get_current_user();
    $restaurant_roles = array_intersect( [ 'dd_restaurant_owner', 'dd_restaurant_manager' ], (array) $user->roles );
    return empty( $restaurant_roles ) && current_user_can( 'manage_options' );
}

/**
 * Default invoice-number prefix when dish_dash_invoice_prefix hasn't been
 * explicitly set — derived from the restaurant's own name (initials),
 * falling back to the plugin's generic "DD" if the name is empty. Mirrors
 * dish_dash_contact_email's existing pattern of a dynamically-computed
 * default (brand-identity.php) rather than a static one baked into
 * install.php's set_default_options() — the restaurant name may not be set
 * yet at install time, and this stays correct if it's changed later,
 * right up until someone explicitly overrides the prefix in Settings.
 */
function dd_invoice_default_prefix(): string {
    $name = trim( (string) get_option( 'dish_dash_restaurant_name', '' ) );
    if ( '' === $name ) {
        return 'DD';
    }
    $initials = '';
    foreach ( preg_split( '/\s+/', $name ) as $word ) {
        if ( '' !== $word ) {
            $initials .= strtoupper( mb_substr( $word, 0, 1 ) );
        }
    }
    $initials = substr( $initials, 0, 4 );
    return '' !== $initials ? $initials : 'DD';
}

// ─────────────────────────────────────────────────────────────────────────
//  INVOICE GENERATION (v3.18.0, relocated here v3.18.1)
//  Moved from admin/pages/billing.php so they're available at admin_init
//  time — DD_Admin::maybe_serve_invoice() (admin/class-dd-admin.php) needs
//  to intercept the invoice GET request BEFORE WordPress's admin-header.php
//  echoes any wp-admin chrome, which only admin_init fires early enough for.
//  Content unchanged from v3.18.0 — relocation only.
// ─────────────────────────────────────────────────────────────────────────

/**
 * Fetch (and persist-if-missing) ledger totals + invoice identity for one
 * billing month. Shared by the printable HTML view and the PDF download so
 * both always show identical numbers — computed once, here. Read-only
 * against the ledger; the only write is the one-time invoice_number
 * persistence described below.
 *
 * invoice_number is deterministic (INV-{prefix}-{month}) but persisted into
 * wp_dd_billing_payments on first generation and reused after, rather than
 * recomputed fresh every call — so it stays stable even if
 * dish_dash_invoice_prefix is changed later. ajax_mark_month_paid()'s own
 * UPDATE never references this column, so marking a month paid/unpaid never
 * clobbers an already-generated number.
 */
function dd_invoice_get_data( string $month ): array {
    global $wpdb;
    $lt = $wpdb->prefix . 'dishdash_billing_ledger';
    $bp = $wpdb->prefix . 'dd_billing_payments';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT source_type, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total
         FROM {$lt} WHERE billable_month = %s AND is_test = 0 GROUP BY source_type",
        $month
    ) );
    $line_items = [
        'order'       => [ 'label' => 'Orders',       'count' => 0, 'total' => 0.0 ],
        'reservation' => [ 'label' => 'Reservations',  'count' => 0, 'total' => 0.0 ],
    ];
    foreach ( $rows as $r ) {
        if ( isset( $line_items[ $r->source_type ] ) ) {
            $line_items[ $r->source_type ]['count'] = (int) $r->cnt;
            $line_items[ $r->source_type ]['total'] = (float) $r->total;
        }
    }
    $grand_total = $line_items['order']['total'] + $line_items['reservation']['total'];

    $payment_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, invoice_number, paid, paid_at FROM {$bp} WHERE month = %s", $month
    ) );

    if ( $payment_row && $payment_row->invoice_number ) {
        $invoice_number = $payment_row->invoice_number;
    } else {
        $prefix         = get_option( 'dish_dash_invoice_prefix', dd_invoice_default_prefix() );
        $invoice_number = "INV-{$prefix}-{$month}";
        if ( $payment_row ) {
            $wpdb->update( $bp, [ 'invoice_number' => $invoice_number ], [ 'id' => $payment_row->id ], [ '%s' ], [ '%d' ] );
        } else {
            // No row for this month yet — Generate Invoice was clicked before
            // Mark Paid ever was. Create one, paid=0/amount=0 default; Mark
            // Paid later will only ever touch paid/paid_at/amount, never this
            // column, so the number set here survives.
            $wpdb->insert( $bp, [
                'month'          => $month,
                'invoice_number' => $invoice_number,
                'paid'           => 0,
                'amount'         => 0,
            ], [ '%s', '%s', '%d', '%d' ] );
        }
    }

    return [
        'month'          => $month,
        'invoice_number' => $invoice_number,
        'line_items'     => $line_items,
        'grand_total'    => $grand_total,
        'is_paid'        => $payment_row ? (bool) $payment_row->paid : false,
        'paid_at'        => $payment_row ? $payment_row->paid_at : null,
    ];
}

/**
 * Build the invoice document body — a self-contained, table-based layout
 * (dompdf-safe: no flexbox/grid) so the exact same markup renders correctly
 * both on-screen and inside the PDF, satisfying "one shared template/data
 * source for both". $logo_src is passed in rather than resolved here — the
 * PDF path needs a local filesystem path (see dd_invoice_resolve_local_logo_path()),
 * the HTML view can just use the normal public logo URL.
 */
function dd_invoice_build_body_html( array $data, string $logo_src ): string {
    $restaurant_name = get_option( 'dish_dash_restaurant_name', get_bloginfo( 'name' ) );
    $address         = get_option( 'dish_dash_address', '' );
    $phone           = get_option( 'dish_dash_phone', '' );
    $email           = get_option( 'dish_dash_contact_email', get_option( 'admin_email' ) );
    $month_label     = date_i18n( 'F Y', strtotime( $data['month'] . '-01' ) );
    $generated_on    = date_i18n( 'd M Y' );
    $status_label    = $data['is_paid']
        ? 'Paid' . ( $data['paid_at'] ? ' — ' . date_i18n( 'd M Y', strtotime( $data['paid_at'] ) ) : '' )
        : 'Unpaid';

    ob_start();
    ?>
    <table style="width:100%;border-collapse:collapse;margin-bottom:24px;">
      <tr>
        <td style="vertical-align:top;">
          <?php if ( $logo_src ) : ?>
            <img src="<?php echo esc_attr( $logo_src ); ?>" style="max-height:56px;max-width:180px;margin-bottom:8px;" alt="">
          <?php endif; ?>
          <div style="font-size:11px;color:#6b7280;">Invoice from</div>
          <div style="font-size:13px;font-weight:700;">Fri Soft Ltd</div>
          <div style="font-size:11px;color:#6b7280;">https://frisoft.rw</div>
        </td>
        <td style="vertical-align:top;text-align:right;">
          <div style="font-size:20px;font-weight:800;color:#111827;">INVOICE</div>
          <div style="font-size:12px;color:#374151;margin-top:4px;"><?php echo esc_html( $data['invoice_number'] ); ?></div>
          <div style="font-size:11px;color:#6b7280;margin-top:2px;">Billing period: <?php echo esc_html( $month_label ); ?></div>
          <div style="font-size:11px;color:#6b7280;">Generated: <?php echo esc_html( $generated_on ); ?></div>
          <div style="font-size:11px;color:#6b7280;">Status: <?php echo esc_html( $status_label ); ?></div>
        </td>
      </tr>
    </table>

    <table style="width:100%;border-collapse:collapse;margin-bottom:24px;">
      <tr><td style="font-size:11px;color:#6b7280;">Billed to</td></tr>
      <tr><td style="font-size:13px;font-weight:700;padding-top:2px;"><?php echo esc_html( $restaurant_name ); ?></td></tr>
      <?php if ( $address ) : ?><tr><td style="font-size:12px;color:#374151;"><?php echo esc_html( $address ); ?></td></tr><?php endif; ?>
      <?php if ( $phone )   : ?><tr><td style="font-size:12px;color:#374151;"><?php echo esc_html( $phone ); ?></td></tr><?php endif; ?>
      <?php if ( $email )   : ?><tr><td style="font-size:12px;color:#374151;"><?php echo esc_html( $email ); ?></td></tr><?php endif; ?>
    </table>

    <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
      <thead>
        <tr style="border-bottom:2px solid #111827;">
          <th style="text-align:left;padding:8px 0;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;">Description</th>
          <th style="text-align:right;padding:8px 0;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;">Count</th>
          <th style="text-align:right;padding:8px 0;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;">Amount (RWF)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ( $data['line_items'] as $item ) : ?>
        <tr style="border-bottom:1px solid #e5e7eb;">
          <td style="padding:10px 0;font-size:13px;"><?php echo esc_html( $item['label'] ); ?> platform fee</td>
          <td style="padding:10px 0;font-size:13px;text-align:right;"><?php echo number_format( $item['count'] ); ?></td>
          <td style="padding:10px 0;font-size:13px;text-align:right;"><?php echo number_format( $item['total'] ); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2" style="padding:14px 0 0;font-size:14px;font-weight:700;text-align:right;">Total Due</td>
          <td style="padding:14px 0 0;font-size:14px;font-weight:700;text-align:right;">RWF <?php echo number_format( $data['grand_total'] ); ?></td>
        </tr>
      </tfoot>
    </table>

    <div style="font-size:10.5px;color:#9ca3af;margin-top:32px;">
      Platform fee invoice generated by Dish Dash. Line items reflect billable orders/reservations recorded for <?php echo esc_html( $month_label ); ?>, excluding test data.
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Full standalone HTML page for the "printable HTML view" — a Print button
 * (window.print(), no server round-trip) and a Download PDF link, then the
 * shared invoice body. Not wrapped in the normal wp-admin chrome — this is
 * meant to be printed/saved as-is.
 */
function dd_invoice_render_page( array $data ): void {
    $logo_url = get_option( 'dish_dash_logo_url', '' );
    $pdf_url  = wp_nonce_url(
        add_query_arg( [ 'page' => 'dish-dash-billing', 'dd_invoice_pdf' => $data['month'] ], admin_url( 'admin.php' ) ),
        'dd_invoice_' . $data['month']
    );
    ?>
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8">
      <title><?php echo esc_html( $data['invoice_number'] ); ?></title>
      <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif; max-width: 720px; margin: 40px auto; color: #111827; }
        .dd-invoice-actions { margin-bottom: 24px; }
        .dd-invoice-actions button, .dd-invoice-actions a {
            display: inline-block; font-size: 13px; font-weight: 600; padding: 8px 16px;
            border-radius: 8px; border: 1px solid #e5e7eb; background: #fff; color: #374151;
            text-decoration: none; cursor: pointer; margin-right: 8px; font-family: inherit;
        }
        @media print { .dd-invoice-actions { display: none; } }
      </style>
    </head>
    <body>
      <div class="dd-invoice-actions">
        <button onclick="window.print()">🖨 Print</button>
        <a href="<?php echo esc_url( $pdf_url ); ?>">⬇ Download PDF</a>
      </div>
      <?php echo dd_invoice_build_body_html( $data, $logo_url ); ?>
    </body>
    </html>
    <?php
}

/**
 * Resolve a WP-uploads logo URL to a local filesystem path, for embedding
 * into the PDF. dompdf's isRemoteEnabled is deliberately off (no outbound
 * HTTP fetch at render time — slow/unreliable on shared hosting, and can
 * silently fail if outbound requests are restricted on the host), so the
 * logo has to be handed to it as a real file path instead. Returns '' (no
 * logo rendered) if the URL can't be resolved to a local file — graceful
 * degradation, never fatal.
 */
function dd_invoice_resolve_local_logo_path( string $logo_url ): string {
    if ( '' === $logo_url ) return '';
    $upload_dir = wp_upload_dir();
    if ( 0 !== strpos( $logo_url, $upload_dir['baseurl'] ) ) {
        return ''; // Not a local upload (e.g. a fully external URL) — skip rather than risk a broken fetch.
    }
    $path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $logo_url );
    return file_exists( $path ) ? $path : '';
}

/**
 * Streams the invoice as a downloadable PDF via dompdf (vendored v3.18.0,
 * same composer-vendoring pattern already proven by libphonenumber —
 * dish-dash.php's vendor/autoload.php require already covers this, no
 * separate include needed here).
 */
function dd_invoice_stream_pdf( array $data ): void {
    if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
        wp_die( esc_html__( 'PDF library not available.', 'dish-dash' ) );
    }

    $logo_path = dd_invoice_resolve_local_logo_path( get_option( 'dish_dash_logo_url', '' ) );
    $body_html = dd_invoice_build_body_html( $data, $logo_path );
    $html      = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#111827;}</style></head><body>'
               . $body_html . '</body></html>';

    $options = new \Dompdf\Options();
    $options->set( 'isRemoteEnabled', false );
    $dompdf = new \Dompdf\Dompdf( $options );
    $dompdf->loadHtml( $html );
    $dompdf->setPaper( 'A4', 'portrait' );
    $dompdf->render();
    $dompdf->stream( $data['invoice_number'] . '.pdf', [ 'Attachment' => true ] );
}

/**
 * Get all active branches.
 *
 * @return array<int, object>
 */
function dd_get_branches(): array {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}dishdash_branches WHERE is_active = 1 ORDER BY name ASC"
    );
}

/**
 * Get a single branch by ID.
 */
function dd_get_branch( int $id ): ?object {
    global $wpdb;
    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dishdash_branches WHERE id = %d",
            $id
        )
    );
}

/**
 * Get the currently selected branch ID from session/cookie.
 * Defaults to branch 1 (main branch).
 */
function dd_get_current_branch_id(): int {
    if ( isset( $_COOKIE['dd_branch_id'] ) ) {
        return (int) $_COOKIE['dd_branch_id'];
    }
    return 1;
}

/**
 * Check if a feature module is enabled in Settings.
 */
function dd_is_enabled( string $feature ): bool {
    return '1' === get_option( "dish_dash_enable_{$feature}", '1' );
}

/**
 * Sanitize and validate an order type string.
 */
function dd_valid_order_type( string $type ): string {
    $valid = [ 'delivery', 'pickup', 'dine-in', 'pos' ];
    return in_array( $type, $valid, true ) ? $type : 'delivery';
}

/**
 * Get allowed order status transitions.
 * Returns the next valid statuses from a given status.
 */
function dd_order_status_transitions(): array {
    return [
        'pending'         => [ 'confirmed', 'cancelled' ],
        'confirmed'       => [ 'ready', 'cancelled' ],
        'ready'           => [ 'delivered', 'cancelled' ],
        'delivered'       => [ 'ready' ],
        'cancelled'       => [ 'pending' ],
        // v3.16.1 — fallback action buttons for the two orphan statuses
        // (investigation-pending-orders.md §6). pending_payment (PesaPal,
        // unpaid) only allows Cancel — never Confirm, that would bypass the
        // payment-status gate entirely. processing (WC-routed, already
        // payment_status='paid') allows the same pair 'pending' gets.
        'pending_payment' => [ 'cancelled' ],
        'processing'      => [ 'confirmed', 'cancelled' ],
    ];
}

/**
 * Get human-readable label for an order status.
 */
function dd_order_status_label( string $status ): string {
    $labels = [
        'pending'   => __( 'Pending',   'dish-dash' ),
        'confirmed' => __( 'Confirmed', 'dish-dash' ),
        'ready'     => __( 'Ready',     'dish-dash' ),
        'delivered' => __( 'Delivered', 'dish-dash' ),
        'cancelled' => __( 'Cancelled', 'dish-dash' ),
    ];
    return $labels[ $status ] ?? ucfirst( $status );
}

/**
 * Log a debug message to the WP error log.
 * Only logs when WP_DEBUG is true.
 */
function dd_log( mixed $data, string $label = 'Dish Dash' ): void {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $message = is_array( $data ) || is_object( $data )
            ? wp_json_encode( $data )
            : (string) $data;
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        error_log( "[{$label}] {$message}" );
    }
}

/**
 * Get the Dish Dash menu page URL.
 */
function dd_menu_url(): string {
    $page_id = get_option( 'dish_dash_menu_page_id' );
    return $page_id ? get_permalink( $page_id ) : home_url( '/restaurant-menu/' );
}

/**
 * Get the Dish Dash cart page URL.
 */
function dd_cart_url(): string {
    $page_id = get_option( 'dish_dash_cart_page_id' );
    return $page_id ? get_permalink( $page_id ) : home_url( '/cart-dd/' );
}

/**
 * Get the Dish Dash checkout page URL.
 */
function dd_checkout_url(): string {
    $page_id = get_option( 'dish_dash_checkout_page_id' );
    return $page_id ? get_permalink( $page_id ) : home_url( '/checkout-dd/' );
}

/**
 * Get the order tracking page URL, optionally with an order number.
 */
function dd_track_url( string $order_number = '' ): string {
    $page_id = get_option( 'dish_dash_track_page_id' );
    $base    = $page_id ? get_permalink( $page_id ) : home_url( '/track-order/' );
    return $order_number
        ? add_query_arg( 'order', urlencode( $order_number ), $base )
        : $base;
}

if ( ! function_exists( 'dd_momo_merchant_code' ) ) {
    /**
     * Sanitized MTN MoMo merchant code (digits only) from settings.
     * Shared by every payment surface (orders scan-&-pay, reservation deposits).
     */
    function dd_momo_merchant_code(): string {
        return preg_replace( '/\D/', '', (string) get_option( 'dish_dash_momo_merchant_code', '' ) );
    }
}

if ( ! function_exists( 'dd_momo_ussd_payload' ) ) {
    /**
     * Build the MTN MoMo merchant-payment USSD payload for a tel: link / QR.
     * SINGLE SOURCE OF TRUTH for the USSD string format across orders + reservations —
     * if this format ever changes it must change here, in one place, for both surfaces.
     * Format (tested live): tel:*182*8*1*{merchant}*{amount}%23  (# encoded as %23).
     *
     * @param int $amount Integer RWF (no decimals / commas).
     * @return string Full tel: payload, or '' when no merchant code is configured.
     */
    function dd_momo_ussd_payload( int $amount ): string {
        $merchant = dd_momo_merchant_code();
        if ( '' === $merchant ) {
            return '';
        }
        return 'tel:*182*8*1*' . $merchant . '*' . $amount . '%23';
    }
}

if ( ! function_exists( 'dd_format_payment_method' ) ) {
    function dd_format_payment_method( string $method ): string {
        $map = [
            'cod'                  => 'Cash on Delivery',
            'pay_on_delivery'      => 'Cash on Delivery',
            'mtn_momo'             => 'MTN Mobile Money',
            'momo'                 => 'MTN Mobile Money',
            'momo_manual'          => 'Scan and pay with MoMo',
            'irembopay'            => 'IremboPay',
            'pesapal'              => 'PesaPal',
            'pay_now'              => 'Card Payment',
            'bacs'                 => 'Bank Transfer',
            'cheque'               => 'Cheque',
            'alg_custom_gateway_1' => 'Cash on Delivery',
        ];
        return $map[ $method ] ?? ucwords( str_replace( '_', ' ', $method ) );
    }
}

/**
 * DD_Hours — Opening hours state engine.
 * Used by page-dishdash.php (banner) and dd_remind_me_open AJAX handler.
 */
class DD_Hours {

    /**
     * Returns current restaurant state.
     * @return string  'open' | 'closing_soon' | 'break' | 'closed'
     */
    public static function get_state() {
        $schedule = self::get_schedule();

        if ( empty( $schedule ) ) {
            return 'open'; // no hours configured = don't block orders
        }

        $today = self::get_today_data( $schedule );

        if ( empty( $today ) ) {
            return 'open'; // today not in schedule = don't block orders
        }

        if ( ! $today['open'] || empty( $today['sessions'] ) ) {
            return 'closed';
        }

        $now              = self::now();
        $closing_soon_min = (int) get_option( 'dd_closing_soon_minutes', 30 );
        $sessions         = $today['sessions'];

        foreach ( $sessions as $i => $session ) {
            $open  = self::to_datetime( $session[0] );
            $close = self::to_datetime( $session[1] );

            if ( $now >= $open && $now < $close ) {
                $diff_min = ( $close->getTimestamp() - $now->getTimestamp() ) / 60;
                return $diff_min <= $closing_soon_min ? 'closing_soon' : 'open';
            }
        }

        // Check if we are between sessions (mid-day break)
        if ( count( $sessions ) === 2 ) {
            $end_s1   = self::to_datetime( $sessions[0][1] );
            $start_s2 = self::to_datetime( $sessions[1][0] );
            if ( $now >= $end_s1 && $now < $start_s2 ) {
                return 'break';
            }
        }

        return 'closed';
    }

    /**
     * For 'closing_soon' — returns close time string e.g. "10:00 PM"
     */
    public static function get_current_close_time() {
        $schedule = self::get_schedule();
        $today    = self::get_today_data( $schedule );
        $now      = self::now();

        foreach ( $today['sessions'] ?? [] as $session ) {
            $open  = self::to_datetime( $session[0] );
            $close = self::to_datetime( $session[1] );
            if ( $now >= $open && $now < $close ) {
                return $close->format( 'g:i A' );
            }
        }
        return '';
    }

    /**
     * For 'break' — returns next session open time string e.g. "5:00 PM" and time remaining.
     */
    public static function get_break_info() {
        $schedule = self::get_schedule();
        $today    = self::get_today_data( $schedule );
        $now      = self::now();
        $sessions = $today['sessions'] ?? [];

        if ( count( $sessions ) >= 2 ) {
            $start_s2 = self::to_datetime( $sessions[1][0] );
            $diff     = $start_s2->getTimestamp() - $now->getTimestamp();
            return [
                'reopens_at' => $start_s2->format( 'g:i A' ),
                'countdown'  => self::format_diff( $diff ),
            ];
        }
        return [ 'reopens_at' => '', 'countdown' => '' ];
    }

    /**
     * For 'closed' — returns next open info: day label, time, and countdown.
     */
    public static function get_next_open_info() {
        $schedule = self::get_schedule();
        $tz       = new DateTimeZone( get_option( 'dd_timezone', 'Africa/Kigali' ) );
        $now      = new DateTime( 'now', $tz );
        $days     = [ 'monday','tuesday','wednesday','thursday','friday','saturday','sunday' ];

        // Look ahead up to 7 days
        for ( $i = 0; $i <= 7; $i++ ) {
            $check_dt = ( clone $now )->modify( "+{$i} days" );
            $day_name = strtolower( $check_dt->format( 'l' ) );
            $day_data = $schedule[ $day_name ] ?? [];

            if ( empty( $day_data['open'] ) || empty( $day_data['sessions'] ) ) {
                continue;
            }

            foreach ( $day_data['sessions'] as $session ) {
                $open_dt = DateTime::createFromFormat(
                    'Y-m-d H:i',
                    $check_dt->format( 'Y-m-d' ) . ' ' . $session[0],
                    $tz
                );
                if ( $open_dt > $now ) {
                    $diff = $open_dt->getTimestamp() - $now->getTimestamp();
                    return [
                        'day'       => $i === 0 ? 'Today' : ( $i === 1 ? 'Tomorrow' : ucfirst( $day_name ) ),
                        'time'      => $open_dt->format( 'g:i A' ),
                        'countdown' => self::format_diff( $diff ),
                    ];
                }
            }
        }

        return [ 'day' => '', 'time' => '', 'countdown' => '' ];
    }

    /**
     * Returns human-readable schedule summary for the closed banner body text.
     * Example: "Monday – Sunday  11:00 AM – 10:00 PM"
     */
    public static function get_hours_summary() {
        $schedule = self::get_schedule();
        $days     = [ 'monday','tuesday','wednesday','thursday','friday','saturday','sunday' ];
        $lines    = [];

        foreach ( $days as $day ) {
            $data = $schedule[ $day ] ?? [];
            if ( empty( $data['open'] ) || empty( $data['sessions'] ) ) {
                continue;
            }
            $s     = $data['sessions'][0];
            $open  = DateTime::createFromFormat( 'H:i', $s[0] );
            $close = DateTime::createFromFormat( 'H:i', $s[1] );
            $label = ucfirst( $day );
            $time  = $open->format( 'g:i A' ) . ' – ' . $close->format( 'g:i A' );
            $lines[] = $label . ': ' . $time;
        }

        // Simplify: if all days same hours, collapse to one line
        $unique = array_unique( array_column(
            array_map( fn($l) => [ 'time' => substr( $l, strpos($l,':') + 2 ) ], $lines ),
            'time'
        ) );

        if ( count( $unique ) === 1 && count( $lines ) === 7 ) {
            return 'Monday – Sunday  ' . trim( $unique[0] );
        }
        return implode( "\n", $lines );
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private static function get_schedule() {
        $raw = get_option( 'dd_opening_hours', '' );
        if ( empty( $raw ) ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private static function get_today_data( $schedule ) {
        $day = strtolower( self::now()->format( 'l' ) );
        return $schedule[ $day ] ?? [];
    }

    private static function now() {
        $tz = get_option( 'dd_timezone', 'Africa/Kigali' );
        return new DateTime( 'now', new DateTimeZone( $tz ) );
    }

    private static function to_datetime( $time_str ) {
        $tz  = get_option( 'dd_timezone', 'Africa/Kigali' );
        $now = new DateTime( 'now', new DateTimeZone( $tz ) );
        return DateTime::createFromFormat(
            'Y-m-d H:i',
            $now->format( 'Y-m-d' ) . ' ' . $time_str,
            new DateTimeZone( $tz )
        );
    }

    private static function format_diff( $seconds ) {
        $h = floor( $seconds / 3600 );
        $m = floor( ( $seconds % 3600 ) / 60 );
        if ( $h > 0 ) return "{$h}h {$m}m";
        return "{$m}m";
    }

    /**
     * Returns Unix timestamp of the end of the current active session.
     * Returns 0 if not currently in a session.
     */
    public static function get_current_close_ts() {
        $schedule = self::get_schedule();
        $today    = self::get_today_data( $schedule );
        $now      = self::now();

        foreach ( $today['sessions'] ?? [] as $session ) {
            $open  = self::to_datetime( $session[0] );
            $close = self::to_datetime( $session[1] );
            if ( $now >= $open && $now < $close ) {
                return $close->getTimestamp();
            }
        }
        return 0;
    }

    /**
     * Returns Unix timestamp of the next opening time.
     * Returns 0 if nothing found in the next 7 days.
     */
    public static function get_next_open_info_ts() {
        $schedule = self::get_schedule();
        $tz       = new DateTimeZone( get_option( 'dd_timezone', 'Africa/Kigali' ) );
        $now      = new DateTime( 'now', $tz );

        for ( $i = 0; $i <= 7; $i++ ) {
            $check_dt = ( clone $now )->modify( "+{$i} days" );
            $day_name = strtolower( $check_dt->format( 'l' ) );
            $day_data = $schedule[ $day_name ] ?? [];

            if ( empty( $day_data['open'] ) || empty( $day_data['sessions'] ) ) continue;

            foreach ( $day_data['sessions'] as $session ) {
                $open_dt = DateTime::createFromFormat(
                    'Y-m-d H:i',
                    $check_dt->format( 'Y-m-d' ) . ' ' . $session[0],
                    $tz
                );
                if ( $open_dt > $now ) {
                    return $open_dt->getTimestamp();
                }
            }
        }
        return 0;
    }
}
