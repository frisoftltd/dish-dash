<?php
/**
 * File:    admin/pages/billing.php
 * Purpose: Billing admin page — platform fee tracking for both orders and
 *          reservations (see PHP block below for the reservations queries,
 *          added v3.15.0). Shows this-month, last-month, all-time fee
 *          totals, monthly history, and status breakdown per section, plus
 *          a combined "Total This Month" hero. Read-only reporting page.
 *
 * Dependencies:
 *   - wp_dishdash_billing_ledger (v3.17.0, backfilled v3.17.2) — This Month /
 *     Last Month / All Time / Monthly History read from here as of v3.17.3,
 *     not live platform_fee queries. Append-only, snapshotted once at the
 *     moment an order/reservation became billable.
 *   - wp_dishdash_orders / wp_dishdash_reservations (platform_fee column —
 *     orders added v3.4.91, reservations added v3.15.0) — still used
 *     directly by the Status Breakdown cards only (full status distribution
 *     isn't something the ledger tracks) and by DD_Billing_Ledger_Module's
 *     own triggers, not by this file's totals anymore.
 *   - dd_per_order_fee / dd_per_reservation_fee wp_options (Settings →
 *     Pricing & Fees), dd_fees_enabled toggle shared by both — rate
 *     *displays* only now, not used to compute historical totals here.
 *   - admin.css's dd-status-* status-badge color rules (colors only — all
 *     structural classes below, dd-billing-*, are self-contained in this
 *     file's own <style> blocks, not admin.css)
 *   - --dd-brand / --dd-brand-rgb CSS variables (DD_Admin::get_admin_styles(),
 *     set on :root/body from the restaurant's own primary color) — the
 *     v3.15.3 visual redesign uses these exclusively, no hardcoded brand hex
 *   - dompdf/dompdf (v3.18.0, composer-vendored — vendor/autoload.php
 *     already required by dish-dash.php) for the "Generate Invoice" PDF
 *     download; the printable HTML view needs no library at all.
 *   - wp_dd_billing_payments.invoice_number (v3.18.0) — deterministic
 *     INV-{prefix}-{month}, persisted on first generation, reused after.
 *   - dish_dash_invoice_prefix option (Settings → mirrors dish_dash_order_prefix)
 *     and dish_dash_restaurant_name/logo_url/address/phone/contact_email
 *     (Brand Identity) for the invoice header.
 *   - dd_is_platform_admin() (class-dd-helpers.php) — gates both Mark Paid/
 *     Unpaid and Generate Invoice to true admins only, never
 *     dd_restaurant_owner/dd_restaurant_manager (both hold manage_options
 *     directly, so a plain capability check can't tell them apart).
 *
 * Last modified: v3.18.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'dd_view_billing' ) ) wp_die( __( 'Access denied.', 'dish-dash' ) );

// Invoice generation (v3.18.0) is handled entirely by DD_Admin::maybe_serve_invoice()
// on admin_init (admin/class-dd-admin.php) — v3.18.1 moved it there from here.
// Handling it inside this file was too late: by the time render_billing()
// include()s billing.php, WordPress has already echoed the full wp-admin
// chrome (admin-header.php — sidebar, admin bar), so the standalone invoice
// page was rendering nested inside that chrome instead of replacing it.
// admin_init fires before any of that output, so intercepting there lets the
// invoice response fully take over with exit — genuinely standalone, no
// wp-admin wrapper. dd_invoice_get_data()/dd_invoice_build_body_html()/
// dd_invoice_render_page()/dd_invoice_resolve_local_logo_path()/
// dd_invoice_stream_pdf() now live in dishdash-core/class-dd-helpers.php
// (loaded at plugin boot, so they're available by admin_init time) —
// unchanged in content, only relocated.

global $wpdb;
$ot  = $wpdb->prefix . 'dishdash_orders';
$ct  = $wpdb->prefix . 'dishdash_customers';
$rt  = $wpdb->prefix . 'dishdash_reservations';
$lt  = $wpdb->prefix . 'dishdash_billing_ledger';
$fee = (int) get_option( 'dd_per_order_fee', 750 );
$res_fee = (int) get_option( 'dd_per_reservation_fee', 750 );

// ── Test-customer exclusion (v3.15.6) — still needed below for the Status
// Breakdown cards, which stay live-querying the source tables (see note
// there). Not needed for the ledger queries — is_test is already snapshotted
// directly onto every ledger row, no join required.
$o_test_join  = "LEFT JOIN `{$ct}` c ON c.id = o.dd_customer_id";
$o_test_where = "o.is_test = 0 AND (c.is_test IS NULL OR c.is_test = 0)";
$r_test_join  = "LEFT JOIN `{$ct}` c ON c.id = r.customer_id";
$r_test_where = "r.is_test = 0 AND (c.is_test IS NULL OR c.is_test = 0)";
$res_billable_sql = "r.platform_fee > 0 AND (
        ( r.deposit_required = 1 AND r.deposit_status = 'paid' )
        OR ( r.deposit_required = 0 AND r.status = 'confirmed' )
    )";

$this_month_key = current_time( 'Y-m' );
$last_month_key = date( 'Y-m', strtotime( 'first day of last month' ) );

// ── This Month / Last Month / All Time / Monthly History — billing ledger
// (v3.17.3, switched from live platform_fee queries). The ledger is
// append-only and snapshotted once, at the moment an order/reservation
// actually became billable — immune to the two instability sources the live
// queries had: (1) a no-deposit reservation's billable status changing after
// the fact (a late no-show silently un-counting it on the next page load),
// and (2) platform_fee itself being zeroed by a later event (e.g. disabling
// the flat fee), which erased historical billing truth under the old live
// query. See investigation-billing-ledger.md and
// investigation-billing-page-vs-ledger.md for the full reasoning; the ledger
// was backfilled with pre-go-live history via scripts/dd-billing-backfill.php
// (v3.17.2) before this cutover.
$this_month_orders = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$lt}` WHERE source_type = 'order' AND is_test = 0 AND billable_month = %s",
    $this_month_key
) );
$this_month_fees = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM `{$lt}` WHERE source_type = 'order' AND is_test = 0 AND billable_month = %s",
    $this_month_key
) );

$last_month_orders = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$lt}` WHERE source_type = 'order' AND is_test = 0 AND billable_month = %s",
    $last_month_key
) );
$last_month_fees = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM `{$lt}` WHERE source_type = 'order' AND is_test = 0 AND billable_month = %s",
    $last_month_key
) );

$alltime_orders = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$lt}` WHERE source_type = 'order' AND is_test = 0"
);
$alltime_fees = (int) $wpdb->get_var(
    "SELECT COALESCE(SUM(amount),0) FROM `{$lt}` WHERE source_type = 'order' AND is_test = 0"
);

// Monthly history — last 6 months present in the ledger. COALESCE/COUNT
// naturally return 0 for a month with no rows (e.g. a pre-backfill gap), so
// this never errors — it just doesn't produce a row for that month.
$monthly_history = $wpdb->get_results(
    "SELECT billable_month AS month, COUNT(*) AS orders, COALESCE(SUM(amount),0) AS fees
     FROM `{$lt}`
     WHERE source_type = 'order' AND is_test = 0
     GROUP BY billable_month
     ORDER BY billable_month DESC
     LIMIT 6"
);

// Fetch paid status for each month. amount is intentionally NOT read here —
// it's a COMBINED orders+reservations figure (ajax_mark_month_paid() never
// stored a per-category split) and, now that Monthly History itself reads
// from the ledger (stable/append-only), each table's own ledger-sourced sum
// already IS the correctly-split, frozen-equivalent number for a Paid month
// — no separate frozen-amount workaround needed anymore (v3.17.1's is
// removed below). See "Mark Paid/Unpaid" note in the release brief.
$bp_table     = $wpdb->prefix . 'dd_billing_payments';
$paid_months  = [];
$payment_rows = $wpdb->get_results(
    "SELECT month, paid, paid_at FROM `{$bp_table}`"
);
foreach ( $payment_rows as $pr ) {
    $paid_months[ $pr->month ] = [
        'paid'    => (bool) $pr->paid,
        'paid_at' => $pr->paid_at,
    ];
}
$billing_nonce = wp_create_nonce( 'dish_dash_admin' );

// ── Status Breakdown cards — deliberately NOT switched to the ledger. The
// ledger only records billable events (one row per order/reservation that
// became billable); it has no concept of the full status distribution
// (pending/confirmed/ready/cancelled/etc.) these cards show. Stays live
// against the source tables, unchanged from before this release.
$status_breakdown = $wpdb->get_results(
    "SELECT o.status, COUNT(*) AS cnt, COALESCE(SUM(o.platform_fee),0) AS fees
     FROM `{$ot}` o {$o_test_join}
     WHERE o.status IN ('delivered','cancelled') AND {$o_test_where}
     GROUP BY o.status
     ORDER BY FIELD(o.status,'delivered','cancelled')"
);

$res_this_month_count = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$lt}` WHERE source_type = 'reservation' AND is_test = 0 AND billable_month = %s",
    $this_month_key
) );
$res_this_month_fees = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM `{$lt}` WHERE source_type = 'reservation' AND is_test = 0 AND billable_month = %s",
    $this_month_key
) );

$res_last_month_count = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$lt}` WHERE source_type = 'reservation' AND is_test = 0 AND billable_month = %s",
    $last_month_key
) );
$res_last_month_fees = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM `{$lt}` WHERE source_type = 'reservation' AND is_test = 0 AND billable_month = %s",
    $last_month_key
) );

$res_alltime_count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$lt}` WHERE source_type = 'reservation' AND is_test = 0"
);
$res_alltime_fees = (int) $wpdb->get_var(
    "SELECT COALESCE(SUM(amount),0) FROM `{$lt}` WHERE source_type = 'reservation' AND is_test = 0"
);

$res_monthly_history = $wpdb->get_results(
    "SELECT billable_month AS month, COUNT(*) AS bookings, COALESCE(SUM(amount),0) AS fees
     FROM `{$lt}`
     WHERE source_type = 'reservation' AND is_test = 0
     GROUP BY billable_month
     ORDER BY billable_month DESC
     LIMIT 6"
);

// Status breakdown — grouped by the booking status column (same shape as
// orders' breakdown), fees only counted where the combined billable test
// passes for that row (so e.g. a 'confirmed' deposit-required-but-unpaid
// row correctly shows RWF 0, not the snapshotted-but-not-yet-billable fee).
// Stays live against the source table — same reasoning as $status_breakdown
// above, the ledger doesn't track full status distribution.
$res_status_breakdown = $wpdb->get_results(
    "SELECT r.status, COUNT(*) AS cnt,
            COALESCE(SUM(CASE WHEN {$res_billable_sql} THEN r.platform_fee ELSE 0 END), 0) AS fees
     FROM `{$rt}` r {$r_test_join}
     WHERE {$r_test_where}
     GROUP BY r.status
     ORDER BY FIELD(r.status,'confirmed','pending','cancelled','no_show','auto_cancelled')"
);

// Combined total — orders + reservations, this month (v3.14.8).
$combined_this_month_fees = $this_month_fees + $res_this_month_fees;
?>

<div class="dd-page-wrap">

  <div class="dd-page-header">
    <h1 class="dd-page-title">💳 Billing</h1>
    <p class="dd-page-subtitle">Platform fee tracking — RWF <?php echo number_format( $fee ); ?> per delivered order, RWF <?php echo number_format( $res_fee ); ?> per billable reservation</p>
  </div>

  <?php
  $fees_enabled = get_option( 'dd_fees_enabled', '1' ) === '1';
  if ( ! $fees_enabled ) : ?>
  <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:16px 20px;margin-bottom:24px;display:flex;align-items:center;gap:12px">
    <span style="font-size:20px">⏸</span>
    <div>
      <div style="font-weight:600;color:#92400e;font-size:14px">Fee tracking is paused</div>
      <div style="color:#b45309;font-size:13px;margin-top:2px">
        No fees are being recorded on new orders or reservations.
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-settings' ) ); ?>" style="color:#92400e;text-decoration:underline">Enable in Settings → Pricing &amp; Fees</a>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Hero: combined total (redesigned) ───────────────────────────────── -->
  <div class="dd-billing-hero">
    <div class="dd-billing-hero__label">Total This Month — Orders + Reservations</div>
    <div class="dd-billing-hero__value">RWF <?php echo number_format( $combined_this_month_fees ); ?></div>
    <div class="dd-billing-hero__chips">
      <span class="dd-billing-chip">
        <span class="dd-billing-chip__dot" style="background:#2563eb"></span>
        🧾 Orders — <strong>RWF <?php echo number_format( $this_month_fees ); ?></strong> · <?php echo number_format( $this_month_orders ); ?> orders
      </span>
      <span class="dd-billing-chip">
        <span class="dd-billing-chip__dot" style="background:#7c3aed"></span>
        📅 Reservations — <strong>RWF <?php echo number_format( $res_this_month_fees ); ?></strong> · <?php echo number_format( $res_this_month_count ); ?> reservations
      </span>
    </div>
  </div>

  <!-- ═══ ORDERS ═══════════════════════════════════════════════════════════ -->
  <div class="dd-billing-section">
    <div class="dd-billing-section__header">
      <span class="dd-billing-section__icon">🧾</span>
      <span class="dd-billing-section__title">Orders</span>
    </div>

    <div class="dd-billing-kpis">
      <div class="dd-billing-kpi" style="--kpi-accent:#2563eb">
        <div class="dd-billing-kpi__label"><span class="dashicons dashicons-calendar-alt"></span>This Month</div>
        <div class="dd-billing-kpi__value">RWF <?php echo number_format( $this_month_fees ); ?></div>
        <div class="dd-billing-kpi__sub"><?php echo number_format( $this_month_orders ); ?> delivered orders</div>
      </div>
      <div class="dd-billing-kpi" style="--kpi-accent:#7c3aed">
        <div class="dd-billing-kpi__label"><span class="dashicons dashicons-backup"></span>Last Month</div>
        <div class="dd-billing-kpi__value">RWF <?php echo number_format( $last_month_fees ); ?></div>
        <div class="dd-billing-kpi__sub"><?php echo number_format( $last_month_orders ); ?> delivered orders</div>
      </div>
      <div class="dd-billing-kpi" style="--kpi-accent:#059669">
        <div class="dd-billing-kpi__label"><span class="dashicons dashicons-chart-line"></span>All Time</div>
        <div class="dd-billing-kpi__value">RWF <?php echo number_format( $alltime_fees ); ?></div>
        <div class="dd-billing-kpi__sub"><?php echo number_format( $alltime_orders ); ?> delivered orders</div>
      </div>
      <div class="dd-billing-kpi" style="--kpi-accent:var(--dd-brand)">
        <div class="dd-billing-kpi__label"><span class="dashicons dashicons-money-alt"></span>Fee Per Order</div>
        <div class="dd-billing-kpi__value">RWF <?php echo number_format( $fee ); ?></div>
        <div class="dd-billing-kpi__sub">Flat rate — no percentage</div>
      </div>
    </div>

    <div class="dd-billing-grid">

      <!-- Monthly History -->
      <div class="dd-billing-card">
        <h3 class="dd-billing-card__title">Monthly History</h3>
        <table class="dd-billing-table">
          <thead>
            <tr>
              <th>Month</th>
              <th>Delivered Orders</th>
              <th>Total Fees</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ( $monthly_history ) : ?>
              <?php foreach ( $monthly_history as $row ) : ?>
                <?php
                $month_key   = $row->month;
                $is_paid     = isset( $paid_months[ $month_key ] ) && $paid_months[ $month_key ]['paid'];
                $paid_at_val = $is_paid ? $paid_months[ $month_key ]['paid_at'] : null;
                ?>
                <tr data-month="<?php echo esc_attr( $month_key ); ?>">
                  <td><?php echo esc_html( date( 'F Y', strtotime( $row->month . '-01' ) ) ); ?></td>
                  <td><?php echo number_format( (int) $row->orders ); ?></td>
                  <td><strong>RWF <?php echo number_format( (int) $row->fees ); ?></strong></td>
                  <td>
                    <?php if ( $is_paid ) : ?>
                      <span class="dd-paid-badge">✅ Paid</span>
                      <?php if ( $paid_at_val ) : ?>
                        <span class="dd-paid-date"><?php echo esc_html( date( 'd M Y', strtotime( $paid_at_val ) ) ); ?></span>
                      <?php endif; ?>
                    <?php else : ?>
                      <span class="dd-unpaid-badge">⏳ Unpaid</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ( dd_is_platform_admin() ) : ?>
                      <?php if ( $is_paid ) : ?>
                        <button class="dd-mark-paid-btn dd-mark-unpaid"
                            data-month="<?php echo esc_attr( $month_key ); ?>"
                            data-paid="0"
                            data-nonce="<?php echo esc_attr( $billing_nonce ); ?>">
                            Mark Unpaid
                        </button>
                      <?php else : ?>
                        <button class="dd-mark-paid-btn dd-mark-paid"
                            data-month="<?php echo esc_attr( $month_key ); ?>"
                            data-paid="1"
                            data-nonce="<?php echo esc_attr( $billing_nonce ); ?>">
                            Mark as Paid
                        </button>
                      <?php endif; ?>
                      <?php
                      $dd_inv_link = wp_nonce_url(
                          add_query_arg( [ 'page' => 'dish-dash-billing', 'dd_invoice' => $month_key ], admin_url( 'admin.php' ) ),
                          'dd_invoice_' . $month_key
                      );
                      ?>
                      <a class="dd-generate-invoice-btn" href="<?php echo esc_url( $dd_inv_link ); ?>" target="_blank" rel="noopener">
                          🧾 Generate Invoice
                      </a>
                    <?php else : ?>
                      <span aria-hidden="true">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else : ?>
              <tr>
                <td colspan="5" class="dd-billing-empty">No billing data yet</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Status Breakdown -->
      <div class="dd-billing-card">
        <h3 class="dd-billing-card__title">Order Status Breakdown</h3>
        <p class="dd-billing-card__hint">All time &middot; excludes test orders</p>
        <table class="dd-billing-table">
          <thead>
            <tr>
              <th>Status</th>
              <th>Orders</th>
              <th>Total Fees</th>
              <th>Billable</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ( $status_breakdown as $row ) : ?>
            <tr>
              <td>
                <span class="dd-status-badge dd-status-<?php echo esc_attr( $row->status ); ?>">
                  <?php echo esc_html( ucfirst( $row->status ) ); ?>
                </span>
              </td>
              <td><?php echo number_format( (int) $row->cnt ); ?></td>
              <td>
                <?php echo $row->fees > 0
                    ? 'RWF ' . number_format( (int) $row->fees )
                    : '—';
                ?>
              </td>
              <td><?php echo $row->status === 'delivered' ? '✅ Yes' : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ( empty( $status_breakdown ) ) : ?>
            <tr>
              <td colspan="4" class="dd-billing-empty">No data yet</td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="dd-billing-note">
          <span class="dd-billing-note__icon">💡</span>
          <p><strong>Billing policy:</strong> a flat fee is charged per delivered order.
            The fee is snapshotted at order placement time — if the rate changes,
            past orders keep their original fee. Cancelled orders are never billed.
            Pending, confirmed, and ready orders are excluded — their outcome is not yet final.</p>
        </div>
      </div>

    </div><!-- /.dd-billing-grid -->
  </div><!-- /.dd-billing-section (Orders) -->

  <!-- ═══ RESERVATIONS ═════════════════════════════════════════════════════ -->
  <div class="dd-billing-section">
    <div class="dd-billing-section__header">
      <span class="dd-billing-section__icon">📅</span>
      <span class="dd-billing-section__title">Reservations</span>
    </div>

    <div class="dd-billing-kpis">
      <div class="dd-billing-kpi" style="--kpi-accent:#2563eb">
        <div class="dd-billing-kpi__label"><span class="dashicons dashicons-calendar-alt"></span>This Month</div>
        <div class="dd-billing-kpi__value">RWF <?php echo number_format( $res_this_month_fees ); ?></div>
        <div class="dd-billing-kpi__sub"><?php echo number_format( $res_this_month_count ); ?> billable reservations</div>
      </div>
      <div class="dd-billing-kpi" style="--kpi-accent:#7c3aed">
        <div class="dd-billing-kpi__label"><span class="dashicons dashicons-backup"></span>Last Month</div>
        <div class="dd-billing-kpi__value">RWF <?php echo number_format( $res_last_month_fees ); ?></div>
        <div class="dd-billing-kpi__sub"><?php echo number_format( $res_last_month_count ); ?> billable reservations</div>
      </div>
      <div class="dd-billing-kpi" style="--kpi-accent:#059669">
        <div class="dd-billing-kpi__label"><span class="dashicons dashicons-chart-line"></span>All Time</div>
        <div class="dd-billing-kpi__value">RWF <?php echo number_format( $res_alltime_fees ); ?></div>
        <div class="dd-billing-kpi__sub"><?php echo number_format( $res_alltime_count ); ?> billable reservations</div>
      </div>
      <div class="dd-billing-kpi" style="--kpi-accent:var(--dd-brand)">
        <div class="dd-billing-kpi__label"><span class="dashicons dashicons-money-alt"></span>Fee Per Reservation</div>
        <div class="dd-billing-kpi__value">RWF <?php echo number_format( $res_fee ); ?></div>
        <div class="dd-billing-kpi__sub">Flat rate — no percentage</div>
      </div>
    </div>

    <div class="dd-billing-grid">

      <!-- Monthly History -->
      <div class="dd-billing-card">
        <h3 class="dd-billing-card__title">Monthly History</h3>
        <p class="dd-billing-card__hint">Paid/Unpaid reflects one combined order+reservation payment record — toggle it from the Orders table above.</p>
        <table class="dd-billing-table">
          <thead>
            <tr>
              <th>Month</th>
              <th>Billable Reservations</th>
              <th>Total Fees</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if ( $res_monthly_history ) : ?>
              <?php foreach ( $res_monthly_history as $row ) : ?>
                <?php
                $res_month_key   = $row->month;
                $res_is_paid     = isset( $paid_months[ $res_month_key ] ) && $paid_months[ $res_month_key ]['paid'];
                $res_paid_at_val = $res_is_paid ? $paid_months[ $res_month_key ]['paid_at'] : null;
                ?>
                <tr>
                  <td><?php echo esc_html( date( 'F Y', strtotime( $row->month . '-01' ) ) ); ?></td>
                  <td><?php echo number_format( (int) $row->bookings ); ?></td>
                  <td><strong>RWF <?php echo number_format( (int) $row->fees ); ?></strong></td>
                  <td>
                    <?php if ( $res_is_paid ) : ?>
                      <span class="dd-paid-badge">✅ Paid</span>
                      <?php if ( $res_paid_at_val ) : ?>
                        <span class="dd-paid-date"><?php echo esc_html( date( 'd M Y', strtotime( $res_paid_at_val ) ) ); ?></span>
                      <?php endif; ?>
                    <?php else : ?>
                      <span class="dd-unpaid-badge">⏳ Unpaid</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else : ?>
              <tr>
                <td colspan="4" class="dd-billing-empty">No billing data yet</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Status Breakdown -->
      <div class="dd-billing-card">
        <h3 class="dd-billing-card__title">Reservation Status Breakdown</h3>
        <p class="dd-billing-card__hint">All time &middot; excludes test reservations</p>
        <table class="dd-billing-table">
          <thead>
            <tr>
              <th>Status</th>
              <th>Reservations</th>
              <th>Total Fees</th>
              <th>Billable</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ( $res_status_breakdown as $row ) : ?>
            <tr>
              <td>
                <span class="dd-status-badge dd-status-<?php echo esc_attr( $row->status ); ?>">
                  <?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ); ?>
                </span>
              </td>
              <td><?php echo number_format( (int) $row->cnt ); ?></td>
              <td>
                <?php echo $row->fees > 0
                    ? 'RWF ' . number_format( (int) $row->fees )
                    : '—';
                ?>
              </td>
              <td><?php echo $row->fees > 0 ? '✅ Yes' : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ( empty( $res_status_breakdown ) ) : ?>
            <tr>
              <td colspan="4" class="dd-billing-empty">No data yet</td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="dd-billing-note">
          <span class="dd-billing-note__icon">💡</span>
          <p><strong>Billing policy:</strong> a flat fee applies once a reservation is
            actually secured — deposit paid (deposit-required bookings) or confirmed
            (no-deposit bookings). The fee is snapshotted at booking time; if the
            rate changes, past reservations keep their original fee. Cancelled,
            no-show, and auto-cancelled reservations are never billed.</p>
        </div>
      </div>

    </div><!-- /.dd-billing-grid -->
  </div><!-- /.dd-billing-section (Reservations) -->

</div><!-- /.dd-page-wrap -->

<style>
/* Paid/Unpaid pills — sized to match .dd-res-badge's pill convention
   (reservations-admin.css) for cross-page consistency. Colors stay
   semantic (green=paid, amber=unpaid), same as deposit badges elsewhere. */
.dd-paid-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .02em;
    color: #065f46;
    background: #d1fae5;
    border-radius: 999px;
    padding: 3px 10px;
}
.dd-unpaid-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .02em;
    color: #92400e;
    background: #fef3c7;
    border-radius: 999px;
    padding: 3px 10px;
}
.dd-paid-date {
    font-size: 11px;
    color: #9ca3af;
    margin-left: 6px;
}
.dd-mark-paid-btn {
    font-size: 12px;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 999px;
    border: 1px solid;
    cursor: pointer;
    font-family: inherit;
    transition: background .15s, opacity .15s;
}
.dd-mark-paid {
    background: #fff;
    color: #065f46;
    border-color: #6ee7b7;
}
.dd-mark-paid:hover { background: #d1fae5; }
.dd-mark-unpaid {
    background: #fff;
    color: #9ca3af;
    border-color: #e5e7eb;
}
.dd-mark-unpaid:hover { background: #f9fafb; }
.dd-mark-paid-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.dd-generate-invoice-btn {
    display: inline-block;
    font-size: 12px;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 999px;
    border: 1px solid #e5e7eb;
    background: #fff;
    color: #374151;
    text-decoration: none !important;
    margin-left: 6px;
    transition: background .15s;
}
.dd-generate-invoice-btn:hover { background: #f9fafb; }
</style>

<script>
( function () {
    'use strict';

    var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

    document.querySelectorAll( '.dd-mark-paid-btn' ).forEach( function ( btn ) {
        btn.addEventListener( 'click', function () {
            var month = btn.dataset.month;
            var paid  = btn.dataset.paid;
            var nonce = btn.dataset.nonce;

            btn.disabled    = true;
            btn.textContent = 'Saving…';

            var body = new URLSearchParams();
            body.append( 'action', 'dd_mark_month_paid' );
            body.append( 'month',  month );
            body.append( 'paid',   paid );
            body.append( 'nonce',  nonce );

            fetch( ajaxUrl, { method: 'POST', body: body } )
                .then( function ( r ) { return r.json(); } )
                .then( function ( data ) {
                    if ( data.success ) {
                        window.location.reload();
                    } else {
                        alert( data.data && data.data.message ? data.data.message : 'Error saving.' );
                        btn.disabled    = false;
                        btn.textContent = paid === '1' ? 'Mark as Paid' : 'Mark Unpaid';
                    }
                } )
                .catch( function () {
                    alert( 'Network error. Please try again.' );
                    btn.disabled    = false;
                    btn.textContent = paid === '1' ? 'Mark as Paid' : 'Mark Unpaid';
                } );
        } );
    } );
}() );
</script>

<style>
.dd-page-wrap {
  padding: 24px 28px;
  box-sizing: border-box;
  width: 100%;
  font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif;
}
.dd-page-header { margin-bottom: 20px; }
.dd-page-subtitle { font-size: 13px; color: #888; margin: 4px 0 0; }

/* ── Hero: combined total ─────────────────────────────────────────────────
   Brand-tinted (--dd-brand / --dd-brand-rgb, both already set on :root/body
   by DD_Admin::get_admin_styles() from the restaurant's own primary color —
   no hardcoded hex here). Large number carries the "hero" weight the plain
   dark bar didn't; the chip row replaces the old subtext line so the order/
   reservation split reads as a glance, not a sentence to parse. */
.dd-billing-hero {
  background: linear-gradient(135deg, rgba(var(--dd-brand-rgb), .09), rgba(var(--dd-brand-rgb), .03));
  border: 1px solid rgba(var(--dd-brand-rgb), .18);
  border-radius: 16px;
  padding: 28px 32px;
  margin-bottom: 28px;
}
.dd-billing-hero__label {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--dd-brand);
}
.dd-billing-hero__value {
  font-size: clamp(30px, 4vw, 42px);
  font-weight: 800;
  color: #111827;
  line-height: 1.15;
  margin-top: 8px;
}
.dd-billing-hero__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 18px;
}
.dd-billing-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: #fff;
  border: 1px solid rgba(0,0,0,.06);
  border-radius: 999px;
  padding: 7px 16px;
  font-size: 13px;
  color: #4b5563;
}
.dd-billing-chip strong { color: #111827; }
.dd-billing-chip__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

/* ── Section grouping (Orders / Reservations as a matched pair) ─────────── */
.dd-billing-section { margin-bottom: 36px; }
.dd-billing-section:last-child { margin-bottom: 0; }
.dd-billing-section__header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid #eef0f2;
}
.dd-billing-section__icon {
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  background: rgba(var(--dd-brand-rgb), .12);
  font-size: 14px;
  flex-shrink: 0;
}
.dd-billing-section__title {
  font-size: 15px;
  font-weight: 700;
  color: #111827;
}

/* ── KPI cards — number-first, plain card + thin accent top border, same
   card shell (radius/shadow) as .dd-res-kpi in reservations-admin.css, so
   Billing reads as a sibling of the Reservations page rather than its own
   visual language. --kpi-accent stays a quick per-card differentiator
   (mirrors the dashboard's own multi-color KPI convention); the 4th card
   (Fee Rate) uses var(--dd-brand) itself since it IS the brand's rate. ── */
.dd-billing-kpis {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin-bottom: 20px;
}
.dd-billing-kpi {
  background: #fff;
  border: 1px solid #eef0f2;
  border-top: 3px solid var(--kpi-accent, var(--dd-brand));
  border-radius: 12px;
  padding: 18px 20px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.dd-billing-kpi__label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: #6b7280;
}
.dd-billing-kpi__label .dashicons {
  font-size: 14px;
  width: 14px;
  height: 14px;
  color: var(--kpi-accent, var(--dd-brand));
}
.dd-billing-kpi__value {
  font-size: clamp(19px, 2.4vw, 25px);
  font-weight: 700;
  color: #111827;
  margin-top: 8px;
  line-height: 1.2;
  word-break: break-word;
}
.dd-billing-kpi__sub {
  font-size: 11px;
  color: #9ca3af;
  margin-top: 4px;
  line-height: 1.4;
}

/* ── Cards (Monthly History / Status Breakdown) ──────────────────────────── */
.dd-billing-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}
.dd-billing-card {
  background: #fff;
  border: 1px solid #eef0f2;
  border-radius: 12px;
  padding: 20px 24px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.06);
  box-sizing: border-box;
}
.dd-billing-card__title {
  font-size: 13px;
  font-weight: 700;
  color: #111827;
  margin: 0 0 4px;
}
.dd-billing-card__hint {
  font-size: 12px;
  color: #9ca3af;
  margin: 0 0 16px;
}

/* ── Tables — larger row rhythm, less "raw data dump" ────────────────────── */
.dd-billing-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.dd-billing-table th {
  text-align: left;
  font-size: 11px;
  font-weight: 600;
  color: #9ca3af;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 0 12px 12px 0;
  border-bottom: 1px solid #f0f0f0;
}
.dd-billing-table td {
  padding: 13px 12px 13px 0;
  border-bottom: 1px solid #f8f8f8;
  color: #374151;
}
.dd-billing-table tbody tr:last-child td { border-bottom: none; }
.dd-billing-empty {
  text-align: center;
  color: #9ca3af;
  padding: 28px 0 !important;
}

/* Status badge base — colors come from admin.css dd-status-* rules.
   Pill sizing now matches .dd-res-badge (reservations-admin.css). */
.dd-status-badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .02em;
}

/* ── Billing policy note — softened from a bordered "warning" callout to a
   plain neutral note, so it reads as helpful context, not an alert. ────── */
.dd-billing-note {
  margin-top: 20px;
  padding: 14px 16px;
  background: #f9fafb;
  border-radius: 10px;
  display: flex;
  gap: 10px;
  align-items: flex-start;
}
.dd-billing-note__icon { font-size: 14px; line-height: 1.6; }
.dd-billing-note p {
  margin: 0;
  font-size: 12.5px;
  line-height: 1.65;
  color: #6b7280;
}
.dd-billing-note strong { color: #374151; }

@media (max-width: 860px) {
  .dd-billing-kpis { grid-template-columns: 1fr 1fr; }
  .dd-billing-grid  { grid-template-columns: 1fr; }
}
</style>
