<?php
/**
 * File:    admin/pages/analytics.php
 * Purpose: Orders Analytics admin page — revenue, speed, menu, customers.
 * Last modified: v3.4.87
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) return;

require_once DD_PLUGIN_DIR . 'dishdash-core/class-dd-insights.php';

global $wpdb;
$ot  = $wpdb->prefix . 'dishdash_orders';
$oit = $wpdb->prefix . 'dishdash_order_items';
$ct  = $wpdb->prefix . 'dishdash_customers';

// ── Date range ────────────────────────────────────────────────────────────────
$range   = isset( $_GET['dd_range'] ) ? sanitize_key( $_GET['dd_range'] ) : '30days';
$allowed = [ 'today', '7days', '30days', 'all' ];
if ( ! in_array( $range, $allowed, true ) ) $range = '30days';

$ts = current_time( 'timestamp' );
switch ( $range ) {
    case 'today':
        $since       = date( 'Y-m-d 00:00:00', $ts );
        $since_prior = date( 'Y-m-d 00:00:00', strtotime( '-1 day', $ts ) );
        $until_prior = $since;
        $label       = 'Today';
        break;
    case '7days':
        $since       = date( 'Y-m-d 00:00:00', strtotime( '-6 days', $ts ) );
        $since_prior = date( 'Y-m-d 00:00:00', strtotime( '-13 days', $ts ) );
        $until_prior = $since;
        $label       = 'Last 7 days';
        break;
    case 'all':
        $since       = '2000-01-01 00:00:00';
        $since_prior = null;
        $until_prior = null;
        $label       = 'All time';
        break;
    default: // 30days
        $since       = date( 'Y-m-d 00:00:00', strtotime( '-29 days', $ts ) );
        $since_prior = date( 'Y-m-d 00:00:00', strtotime( '-59 days', $ts ) );
        $until_prior = $since;
        $label       = 'Last 30 days';
        break;
}

// ── Helper: delta badge ───────────────────────────────────────────────────────
function dd_an_delta( $current, $prior ) {
    if ( $prior <= 0 ) return '';
    $pct = round( ( ( $current - $prior ) / $prior ) * 100 );
    $cls = $pct >= 0 ? 'dd-delta--up' : 'dd-delta--down';
    $sym = $pct >= 0 ? '↑' : '↓';
    return "<span class=\"dd-delta {$cls}\">{$sym} " . abs($pct) . "%</span>";
}

// ── KPI queries ───────────────────────────────────────────────────────────────
$kpi_revenue = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(total),0) FROM `{$ot}` WHERE status='delivered' AND is_test=0 AND created_at>=%s", $since
) );
$kpi_orders = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$ot}` WHERE is_test=0 AND created_at>=%s", $since
) );
$kpi_delivered = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$ot}` WHERE status='delivered' AND is_test=0 AND created_at>=%s", $since
) );
$kpi_aov = $kpi_delivered > 0 ? round( $kpi_revenue / $kpi_delivered ) : 0;

$total_customers = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(DISTINCT customer_id) FROM `{$ot}` WHERE is_test=0 AND created_at>=%s AND customer_id IS NOT NULL", $since
) );
$returning = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(DISTINCT o.customer_id) FROM `{$ot}` o
     JOIN `{$ct}` c ON c.id=o.customer_id
     WHERE o.is_test=0 AND o.created_at>=%s AND c.total_orders>1", $since
) );
$kpi_return_rate = $total_customers > 0 ? round( ( $returning / $total_customers ) * 100 ) : 0;

// Prior period for deltas
$prior_revenue = $prior_orders = $prior_aov = 0;
if ( $since_prior ) {
    $prior_revenue = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(total),0) FROM `{$ot}` WHERE status='delivered' AND is_test=0 AND created_at>=%s AND created_at<%s",
        $since_prior, $until_prior
    ) );
    $prior_orders = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$ot}` WHERE is_test=0 AND created_at>=%s AND created_at<%s",
        $since_prior, $until_prior
    ) );
    $prior_delivered = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$ot}` WHERE status='delivered' AND is_test=0 AND created_at>=%s AND created_at<%s",
        $since_prior, $until_prior
    ) );
    $prior_aov = $prior_delivered > 0 ? round( $prior_revenue / $prior_delivered ) : 0;
}

// ── Revenue chart ─────────────────────────────────────────────────────────────
$today_date = date( 'Y-m-d', $ts );
if ( $range === 'today' ) {
    $chart_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT HOUR(created_at) as period, COALESCE(SUM(total),0) as revenue
         FROM `{$ot}` WHERE status='delivered' AND DATE(created_at)=%s AND is_test=0
         GROUP BY HOUR(created_at) ORDER BY period ASC", $today_date
    ), ARRAY_A );
    $chart_labels = array_map( fn($r) => sprintf('%02d:00', $r['period']), $chart_rows );
} elseif ( $range === 'all' ) {
    $chart_rows = $wpdb->get_results(
        "SELECT DATE_FORMAT(created_at,'%Y-%m') as period, COALESCE(SUM(total),0) as revenue
         FROM `{$ot}` WHERE status='delivered' AND is_test=0
         GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY period ASC", ARRAY_A
    );
    $chart_labels = array_map( fn($r) => $r['period'], $chart_rows );
} else {
    $chart_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT DATE(created_at) as period, COALESCE(SUM(total),0) as revenue
         FROM `{$ot}` WHERE status='delivered' AND created_at>=%s AND is_test=0
         GROUP BY DATE(created_at) ORDER BY period ASC", $since
    ), ARRAY_A );
    $chart_labels = array_map( fn($r) => date('D d', strtotime($r['period'])), $chart_rows );
}
$chart_revenue = array_map( 'floatval', array_column( $chart_rows, 'revenue' ) );

// ── Order Speed ───────────────────────────────────────────────────────────────
$speed_confirm = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, confirmed_at))
     FROM `{$ot}` WHERE confirmed_at IS NOT NULL AND is_test=0 AND created_at>=%s", $since
) );
$speed_cook = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT AVG(TIMESTAMPDIFF(SECOND, confirmed_at, ready_at))
     FROM `{$ot}` WHERE confirmed_at IS NOT NULL AND ready_at IS NOT NULL AND is_test=0 AND created_at>=%s", $since
) );
$speed_delivery = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT AVG(TIMESTAMPDIFF(SECOND, ready_at, delivered_at))
     FROM `{$ot}` WHERE ready_at IS NOT NULL AND delivered_at IS NOT NULL AND is_test=0 AND created_at>=%s", $since
) );
$speed_total = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, delivered_at))
     FROM `{$ot}` WHERE delivered_at IS NOT NULL AND is_test=0 AND created_at>=%s", $since
) );
$has_speed_data = $speed_total > 0;

$prior_speed_total = $since_prior ? (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, delivered_at))
     FROM `{$ot}` WHERE delivered_at IS NOT NULL AND is_test=0 AND created_at>=%s AND created_at<%s",
    $since_prior, $until_prior
) ) : 0;

function dd_fmt_duration( float $seconds ): string {
    if ( $seconds <= 0 ) return '—';
    $m = round( $seconds / 60 );
    if ( $m >= 60 ) return floor($m/60) . 'h ' . ($m%60) . 'm';
    return $m . ' min';
}

// Speed trend chart (daily avg fulfillment time)
$speed_trend_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT DATE(created_at) as day,
            AVG(TIMESTAMPDIFF(MINUTE, created_at, delivered_at)) as avg_min
     FROM `{$ot}` WHERE delivered_at IS NOT NULL AND is_test=0 AND created_at>=%s
     GROUP BY DATE(created_at) ORDER BY day ASC", $since
), ARRAY_A );
$speed_trend_labels = array_map( fn($r) => date('D d', strtotime($r['day'])), $speed_trend_rows );
$speed_trend_data   = array_map( fn($r) => round((float)$r['avg_min'], 1), $speed_trend_rows );

// Slowest 5 orders
$slowest_orders = $wpdb->get_results( $wpdb->prepare(
    "SELECT id, customer_name, created_at,
            TIMESTAMPDIFF(MINUTE, created_at, delivered_at) as mins
     FROM `{$ot}` WHERE delivered_at IS NOT NULL AND is_test=0 AND created_at>=%s
     ORDER BY mins DESC LIMIT 5", $since
), ARRAY_A );

// Dynamic speed tips
$speed_tips = [];
$confirm_min_avg = $speed_confirm > 0 ? round($speed_confirm/60,1) : 0;
$cook_min_avg    = $speed_cook    > 0 ? round($speed_cook/60,1) : 0;
$total_min_avg   = $speed_total   > 0 ? round($speed_total/60,1) : 0;

if ( $confirm_min_avg > 5 ) {
    $speed_tips[] = [
        'n'    => 1,
        'head' => "Confirmation taking {$confirm_min_avg} min — industry best is under 2 min",
        'body' => 'Every minute before confirmation is a minute where the customer is uncertain their order was received. Enable audio alerts or keep the orders tab open on a dedicated device.',
    ];
}
if ( $cook_min_avg > 25 ) {
    $speed_tips[] = [
        'n'    => count($speed_tips) + 1,
        'head' => "Kitchen prep averaging {$cook_min_avg} min",
        'body' => 'Pre-prepping your most-ordered items before peak hours and batching similar orders can reduce cook time by 20–30%.',
    ];
}
if ( $total_min_avg > 45 ) {
    $speed_tips[] = [
        'n'    => count($speed_tips) + 1,
        'head' => "End-to-end time of {$total_min_avg} min is above the 45-min threshold",
        'body' => 'Customers who wait over 45 minutes are significantly less likely to reorder. Identify the slowest stage above and tackle it first.',
    ];
}

// ── Status map ────────────────────────────────────────────────────────────────
$status_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT status, COUNT(*) as cnt FROM `{$ot}` WHERE is_test=0 AND created_at>=%s GROUP BY status ORDER BY cnt DESC", $since
), ARRAY_A );
$status_map = [];
foreach ( $status_rows as $r ) $status_map[$r['status']] = (int)$r['cnt'];

// ── Peak orders by hour ───────────────────────────────────────────────────────
$peak_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM `{$ot}` WHERE is_test=0 AND created_at>=%s GROUP BY HOUR(created_at)", $since
), ARRAY_A );
$peak_by_hour = array_fill(0, 24, 0);
foreach ( $peak_rows as $r ) $peak_by_hour[(int)$r['hr']] = (int)$r['cnt'];

// ── Customer new vs returning ─────────────────────────────────────────────────
$new_customers = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(DISTINCT o.customer_id) FROM `{$ot}` o
     JOIN `{$ct}` c ON c.id=o.customer_id
     WHERE o.is_test=0 AND o.created_at>=%s AND c.total_orders=1", $since
) );
$returning_customers = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(DISTINCT o.customer_id) FROM `{$ot}` o
     JOIN `{$ct}` c ON c.id=o.customer_id
     WHERE o.is_test=0 AND o.created_at>=%s AND c.total_orders>1", $since
) );

// ── Top menu items (by order count + revenue) ─────────────────────────────────
$top_items = $wpdb->get_results( $wpdb->prepare(
    "SELECT oi.item_name, COUNT(*) as cnt, COALESCE(SUM(oi.price * oi.quantity),0) as revenue
     FROM `{$oit}` oi
     JOIN `{$ot}` o ON o.id=oi.order_id
     WHERE o.status='delivered' AND o.is_test=0 AND o.created_at>=%s
     GROUP BY oi.item_name ORDER BY cnt DESC LIMIT 10", $since
), ARRAY_A );
$top_item_max = !empty($top_items) ? (int)$top_items[0]['cnt'] : 1;

// ── Customer tiers ────────────────────────────────────────────────────────────
$tier_new      = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ct}` WHERE total_orders = 0");
$tier_regular  = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ct}` WHERE total_orders >= 1 AND total_spent < 100000");
$tier_vip      = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ct}` WHERE total_spent >= 100000 AND total_spent < 250000");
$tier_champion = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ct}` WHERE total_spent >= 250000 AND total_spent < 500000");
$tier_diamond  = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ct}` WHERE total_spent >= 500000");

// ── Payment methods ───────────────────────────────────────────────────────────
$payment_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT payment_method, COUNT(*) as cnt FROM `{$ot}`
     WHERE is_test=0 AND created_at>=%s GROUP BY payment_method ORDER BY cnt DESC", $since
), ARRAY_A );

// ── Order type split ──────────────────────────────────────────────────────────
$type_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT order_type, COUNT(*) as cnt FROM `{$ot}`
     WHERE is_test=0 AND created_at>=%s GROUP BY order_type ORDER BY cnt DESC", $since
), ARRAY_A );

// ── AI Insights ───────────────────────────────────────────────────────────────
$insights_engine = new DD_Insights();
$maturity        = $insights_engine->get_maturity();
$insights        = $insights_engine->get_insights();

function dd_an_rwf( $n ): string {
    return number_format( (int) round( $n ) ) . ' RWF';
}

$base_url = admin_url( 'admin.php?page=dish-dash-analytics' );
?>

<div class="dd-analytics-wrap">

  <!-- Header -->
  <div class="dd-analytics-header">
    <div class="dd-analytics-header-left">
      <h1 class="dd-page-title">📦 Orders Analytics</h1>
      <p class="dd-page-subtitle">
        <?php echo esc_html( get_option('dish_dash_restaurant_name','Restaurant') ); ?>
        &mdash; <?php echo esc_html( $label ); ?>
      </p>
    </div>
    <div class="dd-analytics-filters">
      <?php foreach ([
        'today'  => 'Today',
        '7days'  => '7 Days',
        '30days' => '30 Days',
        'all'    => 'All Time',
      ] as $key => $lbl ) : ?>
        <a href="<?php echo esc_url( add_query_arg('dd_range',$key,$base_url) ); ?>"
           class="<?php echo $range === $key ? 'active' : ''; ?>">
          <?php echo esc_html($lbl); ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Sub-page tabs -->
  <div class="dd-analytics-tabs">
    <a href="<?php echo esc_url($base_url); ?>" class="dd-analytics-tab active">📦 Orders</a>
    <a href="<?php echo esc_url(admin_url('admin.php?page=dish-dash-analytics-reservations')); ?>" class="dd-analytics-tab">📅 Reservations</a>
  </div>

  <!-- ── AI Insights ── -->
  <?php if ( $maturity['state'] === 'seed' ) : ?>
  <div class="dd-dash-card dd-insights-seed">
    <div class="dd-insights-seed-icon">🌱</div>
    <div class="dd-insights-seed-text">
      <strong>DishDash AI is learning your restaurant…</strong>
      <span><?php echo (int)$maturity['count']; ?> orders collected. Insights appear after 50 orders.</span>
    </div>
    <div class="dd-insights-seed-bar">
      <div class="dd-insights-seed-fill" style="width:<?php echo (int)$maturity['pct']; ?>%"></div>
    </div>
    <div class="dd-insights-seed-pct"><?php echo (int)$maturity['pct']; ?>% complete</div>
  </div>

  <?php elseif ( ! empty( $insights ) ) : ?>
  <div class="dd-insights-section">
    <div class="dd-insights-label">
      🤖 AI Insights
      <?php if ( $maturity['state'] === 'growing' ) : ?>
        <span class="dd-insights-confidence">Based on <?php echo (int)$maturity['count']; ?> orders — improves as more data flows in</span>
      <?php endif; ?>
    </div>
    <div class="dd-insights-strip" id="ddInsightsStrip">
      <?php foreach ( $insights as $ins ) :
        $sev_class = 'dd-insight--' . esc_attr( $ins['severity'] );
      ?>
      <div class="dd-insight-card <?php echo $sev_class; ?>">
        <div class="dd-insight-icon"><?php echo esc_html( $ins['icon'] ); ?></div>
        <div class="dd-insight-body">
          <div class="dd-insight-headline"><?php echo esc_html( $ins['headline'] ); ?></div>
          <div class="dd-insight-detail"><?php echo esc_html( $ins['detail'] ); ?></div>
          <?php if ( $ins['action_url'] ) : ?>
            <a href="<?php echo esc_url($ins['action_url']); ?>" class="dd-insight-action">
              <?php echo esc_html($ins['action_label']); ?> →
            </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── KPI Row ── -->
  <div class="dd-kpi-row">
    <div class="dd-kpi-card" style="--kpi-accent:#059669">
      <div class="dd-kpi-top"><span class="dd-kpi-icon">💰</span></div>
      <div class="dd-kpi-label">Total Revenue</div>
      <div class="dd-kpi-value"><?php echo dd_an_rwf($kpi_revenue); ?></div>
      <?php echo dd_an_delta($kpi_revenue, $prior_revenue); ?>
    </div>
    <div class="dd-kpi-card" style="--kpi-accent:#4F46E5">
      <div class="dd-kpi-top"><span class="dd-kpi-icon">🛒</span></div>
      <div class="dd-kpi-label">Total Orders</div>
      <div class="dd-kpi-value"><?php echo number_format($kpi_orders); ?></div>
      <?php echo dd_an_delta($kpi_orders, $prior_orders); ?>
    </div>
    <div class="dd-kpi-card" style="--kpi-accent:#2563EB">
      <div class="dd-kpi-top"><span class="dd-kpi-icon">📊</span></div>
      <div class="dd-kpi-label">Avg Order Value</div>
      <div class="dd-kpi-value"><?php echo dd_an_rwf($kpi_aov); ?></div>
      <?php echo dd_an_delta($kpi_aov, $prior_aov); ?>
    </div>
    <div class="dd-kpi-card" style="--kpi-accent:#7C3AED">
      <div class="dd-kpi-top"><span class="dd-kpi-icon">🔁</span></div>
      <div class="dd-kpi-label">Repeat Customer Rate</div>
      <div class="dd-kpi-value"><?php echo $kpi_return_rate; ?>%</div>
    </div>
  </div>

  <!-- ── Revenue Chart ── -->
  <div class="dd-dash-card dd-chart-card">
    <div class="dd-card-header">
      <span class="dd-card-title">Revenue Over Time</span>
      <span class="dd-card-meta"><?php echo esc_html($label); ?></span>
    </div>
    <div class="dd-chart-wrap" style="height:260px">
      <canvas id="ddRevenueChart"></canvas>
    </div>
  </div>

  <!-- ── Order Speed ── -->
  <div class="dd-dash-card">
    <div class="dd-card-header">
      <span class="dd-card-title">⚡ Order Speed</span>
      <span class="dd-card-meta"><?php echo esc_html($label); ?> &mdash; delivered orders only</span>
    </div>

    <?php if ( ! $has_speed_data ) : ?>
      <p class="dd-empty-state">No completed deliveries in this period yet. Speed metrics appear once orders are marked Delivered.</p>
    <?php else : ?>

    <div class="dd-speed-kpi-row">
      <div class="dd-speed-kpi">
        <div class="dd-speed-kpi-label">Avg Confirm Time</div>
        <div class="dd-speed-kpi-value"><?php echo dd_fmt_duration($speed_confirm); ?></div>
        <div class="dd-speed-kpi-sub">Order placed → confirmed</div>
        <?php if ($speed_confirm > 0 && $speed_confirm/60 <= 2): ?>
          <span class="dd-speed-badge dd-speed-badge--good">✓ Excellent</span>
        <?php elseif ($speed_confirm > 0 && $speed_confirm/60 <= 5): ?>
          <span class="dd-speed-badge dd-speed-badge--ok">~ Acceptable</span>
        <?php elseif ($speed_confirm > 0): ?>
          <span class="dd-speed-badge dd-speed-badge--warn">⚠ Too slow</span>
        <?php endif; ?>
      </div>
      <div class="dd-speed-kpi">
        <div class="dd-speed-kpi-label">Avg Cook Time</div>
        <div class="dd-speed-kpi-value"><?php echo dd_fmt_duration($speed_cook); ?></div>
        <div class="dd-speed-kpi-sub">Confirmed → ready</div>
      </div>
      <div class="dd-speed-kpi">
        <div class="dd-speed-kpi-label">Avg Delivery Time</div>
        <div class="dd-speed-kpi-value"><?php echo dd_fmt_duration($speed_delivery); ?></div>
        <div class="dd-speed-kpi-sub">Ready → delivered</div>
      </div>
      <div class="dd-speed-kpi">
        <div class="dd-speed-kpi-label">Total End-to-End</div>
        <div class="dd-speed-kpi-value dd-speed-kpi-value--total"><?php echo dd_fmt_duration($speed_total); ?></div>
        <div class="dd-speed-kpi-sub">Order placed → delivered</div>
      </div>
    </div>

    <?php
    $total_min    = $speed_total > 0 ? round($speed_total/60) : 0;
    $bench_target = 30;
    $bench_pct    = $total_min > 0 ? min(100, round(($bench_target / max($total_min,$bench_target))*100)) : 0;
    $bench_color  = $total_min <= 20 ? '#059669' : ($total_min <= 30 ? '#D97706' : '#DC2626');
    ?>
    <div class="dd-speed-benchmark">
      <div class="dd-speed-benchmark-label">
        Speed vs industry target
        <strong style="color:<?php echo $bench_color ?>"><?php echo $total_min; ?> min</strong>
        <span class="dd-speed-benchmark-target">target: &lt;30 min</span>
      </div>
      <div class="dd-speed-benchmark-bar">
        <div class="dd-speed-benchmark-fill" style="width:<?php echo $bench_pct; ?>%;background:<?php echo $bench_color ?>"></div>
      </div>
    </div>

    <?php if ( count($speed_trend_rows) > 1 ) : ?>
    <div class="dd-chart-wrap" style="height:180px;margin-top:20px">
      <canvas id="ddSpeedTrendChart"></canvas>
    </div>
    <?php endif; ?>

    <?php if ( $slowest_orders ) : ?>
    <div class="dd-speed-slowest">
      <div class="dd-section-title" style="margin:20px 0 10px">Slowest orders this period</div>
      <table class="dd-speed-table">
        <thead><tr><th>Order</th><th>Customer</th><th>Duration</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($slowest_orders as $so) : ?>
          <tr>
            <td><a href="<?php echo esc_url(admin_url('admin.php?page=dish-dash-orders&open_order='.(int)$so['id'])); ?>">#<?php echo (int)$so['id']; ?></a></td>
            <td><?php echo esc_html($so['customer_name'] ?: '—'); ?></td>
            <td><strong><?php echo (int)$so['mins']; ?> min</strong></td>
            <td><?php echo esc_html(date('D d M, g:ia', strtotime($so['created_at']))); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if ( $speed_tips ) : ?>
    <div class="dd-speed-tips">
      <div class="dd-speed-tips-title">💡 Where time is being lost — and how to fix it</div>
      <?php foreach ($speed_tips as $tip) : ?>
      <div class="dd-speed-tip">
        <div class="dd-speed-tip-n"><?php echo (int)$tip['n']; ?></div>
        <div class="dd-speed-tip-body">
          <strong><?php echo esc_html($tip['head']); ?></strong>
          <p><?php echo esc_html($tip['body']); ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php endif; // has_speed_data ?>
  </div>

  <!-- ── Status + Top Items ── -->
  <div class="dd-dash-cols">
    <div class="dd-col-left">
      <div class="dd-dash-card">
        <div class="dd-card-header"><span class="dd-card-title">Orders by Status</span></div>
        <div class="dd-chart-wrap" style="height:240px"><canvas id="ddStatusChart"></canvas></div>
      </div>
    </div>
    <div class="dd-col-right">
      <div class="dd-dash-card">
        <div class="dd-card-header"><span class="dd-card-title">Top Menu Items</span></div>
        <?php if ($top_items) : ?>
        <ul class="dd-top-items-list">
          <?php foreach ($top_items as $i => $item) :
            $bar_pct = $top_item_max > 0 ? round(((int)$item['cnt']/$top_item_max)*100) : 0;
          ?>
          <li>
            <span class="dd-top-item-rank"><?php echo $i+1; ?></span>
            <span class="dd-top-item-name"><?php echo esc_html($item['item_name']); ?></span>
            <div class="dd-top-item-bar-wrap">
              <div class="dd-top-item-bar" style="width:<?php echo $bar_pct; ?>%"></div>
            </div>
            <div class="dd-top-item-meta">
              <span class="dd-top-item-count"><?php echo (int)$item['cnt']; ?> orders</span>
              <span class="dd-top-item-rev"><?php echo dd_an_rwf($item['revenue']); ?></span>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
          <p class="dd-empty-state">No delivered orders in this period.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Peak Hours + Customer Breakdown ── -->
  <div class="dd-cols-50">
    <div class="dd-dash-card">
      <div class="dd-card-header"><span class="dd-card-title">Peak Order Hours</span></div>
      <div class="dd-chart-wrap" style="height:220px"><canvas id="ddPeakChart"></canvas></div>
    </div>
    <div class="dd-dash-card">
      <div class="dd-card-header"><span class="dd-card-title">Customer Breakdown</span></div>
      <div class="dd-chart-wrap" style="height:160px"><canvas id="ddCustomerChart"></canvas></div>
      <table class="dd-tier-table">
        <thead><tr><th>Tier</th><th>Customers</th></tr></thead>
        <tbody>
          <tr><td>🆕 New (0 orders)</td><td><?php echo number_format($tier_new); ?></td></tr>
          <tr><td>👤 Regular</td><td><?php echo number_format($tier_regular); ?></td></tr>
          <tr><td>⭐ VIP</td><td><?php echo number_format($tier_vip); ?></td></tr>
          <tr><td>🏆 Champion</td><td><?php echo number_format($tier_champion); ?></td></tr>
          <tr><td>💎 Diamond</td><td><?php echo number_format($tier_diamond); ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Payment Methods + Order Types ── -->
  <div class="dd-cols-50" style="margin-bottom:40px">
    <div class="dd-dash-card">
      <div class="dd-card-header"><span class="dd-card-title">Payment Methods</span></div>
      <?php
      $payment_labels = [ 'cod'=>'Cash on Delivery', 'momo'=>'MoMo Pay', 'card'=>'Card (Pesapal)', 'online'=>'Online' ];
      $pay_total = array_sum(array_column($payment_rows,'cnt')) ?: 1;
      foreach ($payment_rows as $pr) :
        $pct = round(((int)$pr['cnt']/$pay_total)*100);
        $lbl = $payment_labels[$pr['payment_method']] ?? ucfirst($pr['payment_method']);
      ?>
      <div class="dd-hbar-row">
        <div class="dd-hbar-label"><?php echo esc_html($lbl); ?></div>
        <div class="dd-hbar-track"><div class="dd-hbar-fill" style="width:<?php echo $pct; ?>%"></div></div>
        <div class="dd-hbar-val"><?php echo (int)$pr['cnt']; ?> (<?php echo $pct; ?>%)</div>
      </div>
      <?php endforeach; ?>
      <?php if (!$payment_rows): ?><p class="dd-empty-state">No data yet.</p><?php endif; ?>
    </div>
    <div class="dd-dash-card">
      <div class="dd-card-header"><span class="dd-card-title">Order Type</span></div>
      <?php
      $type_labels = ['delivery'=>'🛵 Delivery','pickup'=>'🏃 Pickup','dine-in'=>'🍽 Dine-in','pos'=>'🖥 POS'];
      $type_total = array_sum(array_column($type_rows,'cnt')) ?: 1;
      foreach ($type_rows as $tr) :
        $pct = round(((int)$tr['cnt']/$type_total)*100);
        $lbl = $type_labels[$tr['order_type']] ?? ucfirst($tr['order_type']);
      ?>
      <div class="dd-hbar-row">
        <div class="dd-hbar-label"><?php echo esc_html($lbl); ?></div>
        <div class="dd-hbar-track"><div class="dd-hbar-fill" style="width:<?php echo $pct; ?>%"></div></div>
        <div class="dd-hbar-val"><?php echo (int)$tr['cnt']; ?> (<?php echo $pct; ?>%)</div>
      </div>
      <?php endforeach; ?>
      <?php if (!$type_rows): ?><p class="dd-empty-state">No data yet.</p><?php endif; ?>
    </div>
  </div>

</div><!-- /.dd-analytics-wrap -->

<script>
window.ddAnalyticsData = {
  revenue:    { labels: <?php echo wp_json_encode(array_values($chart_labels)); ?>, data: <?php echo wp_json_encode(array_values($chart_revenue)); ?> },
  status:     { labels: <?php echo wp_json_encode(array_keys($status_map)); ?>, data: <?php echo wp_json_encode(array_values($status_map)); ?> },
  peak:       { data: <?php echo wp_json_encode(array_values($peak_by_hour)); ?> },
  customer:   { new: <?php echo (int)$new_customers; ?>, returning: <?php echo (int)$returning_customers; ?> },
  speedTrend: { labels: <?php echo wp_json_encode(array_values($speed_trend_labels)); ?>, data: <?php echo wp_json_encode(array_values($speed_trend_data)); ?> }
};
window.ddAnalytics = { brandColor: '<?php echo esc_js(sanitize_hex_color(get_option('dish_dash_primary_color','#65040d'))); ?>' };
</script>
