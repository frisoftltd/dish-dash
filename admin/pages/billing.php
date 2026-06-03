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

// Status breakdown (all time)
$status_breakdown = $wpdb->get_results(
    "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(platform_fee),0) AS fees
     FROM `{$ot}`
     WHERE is_test = 0
     GROUP BY status
     ORDER BY FIELD(status,'delivered','ready','confirmed','pending','cancelled')"
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
            <th>Fee per Order</th>
            <th>Total Fees</th>
          </tr>
        </thead>
        <tbody>
          <?php if ( $monthly_history ) : ?>
            <?php foreach ( $monthly_history as $row ) : ?>
            <tr>
              <td><?php echo esc_html( date( 'F Y', strtotime( $row->month . '-01' ) ) ); ?></td>
              <td><?php echo number_format( $row->orders ); ?></td>
              <td>RWF <?php echo number_format( $fee ); ?></td>
              <td><strong>RWF <?php echo number_format( $row->fees ); ?></strong></td>
            </tr>
            <?php endforeach; ?>
          <?php else : ?>
            <tr><td colspan="4" style="text-align:center;color:#888;padding:24px">No billing data yet</td></tr>
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
            <th>Fees</th>
            <th>Billable</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $billable_statuses = [ 'delivered' ];
          foreach ( $status_breakdown as $row ) :
              $billable = in_array( $row->status, $billable_statuses, true ) ? '✅ Yes' : '—';
          ?>
          <tr>
            <td><span class="dd-status-badge dd-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( ucfirst( $row->status ) ); ?></span></td>
            <td><?php echo number_format( $row->cnt ); ?></td>
            <td><?php echo $row->fees > 0 ? 'RWF ' . number_format( $row->fees ) : '—'; ?></td>
            <td><?php echo $billable; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div style="margin-top:24px;padding:16px;background:#f0fdf4;border-radius:8px;border-left:3px solid #10B981">
        <p style="margin:0;font-size:13px;color:#065f46">
          <strong>Billing policy:</strong> RWF <?php echo number_format( $fee ); ?> is charged per delivered order.
          Cancelled, pending, confirmed, and ready orders are not billed.
          Fee is snapshotted at order placement time — rate changes do not affect past orders.
        </p>
      </div>
    </div>

  </div><!-- /.dd-billing-grid -->

</div><!-- /.dd-page-wrap -->

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
