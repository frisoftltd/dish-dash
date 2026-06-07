<?php
/**
 * File:    admin/pages/billing.php
 * Purpose: Billing admin page — platform fee tracking per delivered order.
 *          Shows this-month, last-month, all-time fee totals, monthly history,
 *          and order status breakdown. Read-only reporting page.
 *
 * Dependencies:
 *   - wp_dishdash_orders table (platform_fee column, added v3.4.91)
 *   - dd_per_order_fee wp_option (set in Settings → Pricing & Fees)
 *   - admin.css (dd-kpi-card, dd-kpi-row, dd-section-title, dd-status-* classes)
 *
 * Last modified: v3.4.93
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Access denied.', 'dish-dash' ) );

global $wpdb;
$ot  = $wpdb->prefix . 'dishdash_orders';
$fee = (int) get_option( 'dd_per_order_fee', 750 );

// ── Date ranges ───────────────────────────────────────────────────────────────
$month_start  = current_time( 'Y-m-' ) . '01 00:00:00';
$last_month_s = date( 'Y-m-01 00:00:00', strtotime( 'first day of last month' ) );
$last_month_e = date( 'Y-m-t 23:59:59',  strtotime( 'last day of last month' ) );

// ── Queries ───────────────────────────────────────────────────────────────────

// This month
$this_month_orders = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$ot}`
     WHERE status = 'delivered' AND platform_fee > 0 AND created_at >= %s AND is_test = 0",
    $month_start
) );
$this_month_fees = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(platform_fee),0) FROM `{$ot}`
     WHERE status = 'delivered' AND platform_fee > 0 AND created_at >= %s AND is_test = 0",
    $month_start
) );

// Last month
$last_month_orders = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$ot}`
     WHERE status = 'delivered' AND platform_fee > 0 AND created_at BETWEEN %s AND %s AND is_test = 0",
    $last_month_s, $last_month_e
) );
$last_month_fees = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(platform_fee),0) FROM `{$ot}`
     WHERE status = 'delivered' AND platform_fee > 0 AND created_at BETWEEN %s AND %s AND is_test = 0",
    $last_month_s, $last_month_e
) );

// All time
$alltime_orders = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$ot}` WHERE status = 'delivered' AND platform_fee > 0 AND is_test = 0"
);
$alltime_fees = (int) $wpdb->get_var(
    "SELECT COALESCE(SUM(platform_fee),0) FROM `{$ot}` WHERE status = 'delivered' AND platform_fee > 0 AND is_test = 0"
);

// Monthly history — last 6 months
$monthly_history = $wpdb->get_results(
    "SELECT
         DATE_FORMAT(created_at, '%Y-%m') AS month,
         COUNT(*) AS orders,
         SUM(platform_fee) AS fees
     FROM `{$ot}`
     WHERE status = 'delivered' AND platform_fee > 0 AND is_test = 0
     GROUP BY DATE_FORMAT(created_at, '%Y-%m')
     ORDER BY month DESC
     LIMIT 6"
);

// Fetch paid status for each month
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

// Only delivered (billable) and cancelled (not billable)
$status_breakdown = $wpdb->get_results(
    "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(platform_fee),0) AS fees
     FROM `{$ot}`
     WHERE status IN ('delivered','cancelled') AND is_test = 0
     GROUP BY status
     ORDER BY FIELD(status,'delivered','cancelled')"
);
?>

<div class="dd-page-wrap">

  <div class="dd-page-header">
    <h1 class="dd-page-title">💳 Billing</h1>
    <p class="dd-page-subtitle">Platform fee tracking — RWF <?php echo number_format( $fee ); ?> per delivered order</p>
  </div>

  <?php
  $fees_enabled = get_option( 'dd_fees_enabled', '1' ) === '1';
  if ( ! $fees_enabled ) : ?>
  <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:16px 20px;margin-bottom:24px;display:flex;align-items:center;gap:12px">
    <span style="font-size:20px">⏸</span>
    <div>
      <div style="font-weight:600;color:#92400e;font-size:14px">Fee tracking is paused</div>
      <div style="color:#b45309;font-size:13px;margin-top:2px">
        No fees are being recorded on new orders.
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-settings' ) ); ?>" style="color:#92400e;text-decoration:underline">Enable in Settings → Pricing &amp; Fees</a>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── KPI row ───────────────────────────────────────────────────────────── -->
  <div class="dd-billing-kpis">

    <div class="dd-kpi-card" style="--kpi-accent:#0EA5E9">
      <div class="dd-kpi-top">
        <span class="dashicons dashicons-calendar-alt" style="color:#0EA5E9"></span>
      </div>
      <div class="dd-kpi-label">This Month</div>
      <div class="dd-kpi-value">RWF <?php echo number_format( $this_month_fees ); ?></div>
      <div class="dd-kpi-sub"><?php echo number_format( $this_month_orders ); ?> delivered orders</div>
    </div>

    <div class="dd-kpi-card" style="--kpi-accent:#8B5CF6">
      <div class="dd-kpi-top">
        <span class="dashicons dashicons-backup" style="color:#8B5CF6"></span>
      </div>
      <div class="dd-kpi-label">Last Month</div>
      <div class="dd-kpi-value">RWF <?php echo number_format( $last_month_fees ); ?></div>
      <div class="dd-kpi-sub"><?php echo number_format( $last_month_orders ); ?> delivered orders</div>
    </div>

    <div class="dd-kpi-card" style="--kpi-accent:#10B981">
      <div class="dd-kpi-top">
        <span class="dashicons dashicons-chart-line" style="color:#10B981"></span>
      </div>
      <div class="dd-kpi-label">All Time</div>
      <div class="dd-kpi-value">RWF <?php echo number_format( $alltime_fees ); ?></div>
      <div class="dd-kpi-sub"><?php echo number_format( $alltime_orders ); ?> delivered orders</div>
    </div>

    <div class="dd-kpi-card" style="--kpi-accent:#F59E0B">
      <div class="dd-kpi-top">
        <span class="dashicons dashicons-money-alt" style="color:#F59E0B"></span>
      </div>
      <div class="dd-kpi-label">Fee Per Order</div>
      <div class="dd-kpi-value">RWF <?php echo number_format( $fee ); ?></div>
      <div class="dd-kpi-sub">Flat rate — no percentage</div>
    </div>

  </div><!-- /.dd-billing-kpis -->

  <!-- ── Two-column row ───────────────────────────────────────────────────── -->
  <div class="dd-billing-grid">

    <!-- Monthly History -->
    <div class="dd-card">
      <h2 class="dd-section-title">Monthly History</h2>
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
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else : ?>
            <tr>
              <td colspan="5" style="text-align:center;color:#888;padding:24px">No billing data yet</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Status Breakdown -->
    <div class="dd-card">
      <h2 class="dd-section-title">Order Status Breakdown</h2>
      <p style="font-size:13px;color:#888;margin:0 0 16px">All time &middot; excludes test orders</p>
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
            <td colspan="4" style="text-align:center;color:#888;padding:24px">No data yet</td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>

      <div style="margin-top:24px;padding:16px;background:#f0fdf4;border-radius:8px;border-left:3px solid #10B981">
        <p style="margin:0;font-size:13px;color:#065f46">
          <strong>Billing policy:</strong> A flat fee is charged per delivered order.
          The fee is snapshotted at order placement time — if the rate changes,
          past orders keep their original fee. Cancelled orders are never billed.
          Pending, confirmed, and ready orders are excluded — their outcome is not yet final.
        </p>
      </div>
    </div>

  </div><!-- /.dd-billing-grid -->

</div><!-- /.dd-page-wrap -->

<style>
.dd-paid-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    font-weight: 600;
    color: #065f46;
    background: #d1fae5;
    border-radius: 4px;
    padding: 2px 8px;
}
.dd-unpaid-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    font-weight: 600;
    color: #92400e;
    background: #fef3c7;
    border-radius: 4px;
    padding: 2px 8px;
}
.dd-paid-date {
    font-size: 11px;
    color: #888;
    margin-left: 6px;
}
.dd-mark-paid-btn {
    font-size: 12px;
    padding: 4px 10px;
    border-radius: 4px;
    border: 1px solid;
    cursor: pointer;
    font-family: inherit;
    transition: opacity 0.15s;
}
.dd-mark-paid {
    background: #fff;
    color: #065f46;
    border-color: #6ee7b7;
}
.dd-mark-paid:hover { background: #d1fae5; }
.dd-mark-unpaid {
    background: #fff;
    color: #888;
    border-color: #e0e0e0;
}
.dd-mark-unpaid:hover { background: #f5f5f5; }
.dd-mark-paid-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
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
.dd-page-header { margin-bottom: 24px; }
.dd-page-subtitle { font-size: 13px; color: #888; margin: 4px 0 0; }

.dd-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 20px 24px;
  box-sizing: border-box;
}

.dd-card .dd-section-title {
  font-size: 14px;
  font-weight: 700;
  color: #111;
  margin: 0 0 16px;
}

.dd-billing-kpis {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 20px;
  margin-bottom: 24px;
}

.dd-billing-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
}

.dd-billing-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.dd-billing-table th {
  text-align: left;
  font-size: 11px;
  font-weight: 600;
  color: #888;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 0 12px 10px 0;
  border-bottom: 1px solid #f0f0f0;
}
.dd-billing-table td {
  padding: 10px 12px 10px 0;
  border-bottom: 1px solid #f8f8f8;
  color: #333;
}
.dd-billing-table tbody tr:last-child td { border-bottom: none; }

.dd-kpi-sub {
  font-size: 11px;
  color: #888;
  margin-top: 4px;
  line-height: 1.4;
}

/* Status badge base — colors come from admin.css dd-status-* rules */
.dd-status-badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
}

@media (max-width: 860px) {
  .dd-billing-kpis { grid-template-columns: 1fr 1fr; }
  .dd-billing-grid  { grid-template-columns: 1fr; }
}
</style>
