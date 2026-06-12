<?php
/**
 * File:    admin/pages/analytics.php
 * Purpose: Analytics page — Orders tab + Reservations tab in one page.
 * Last modified: v3.4.90
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'dd_view_analytics' ) ) return;

require_once DD_PLUGIN_DIR . 'dishdash-core/class-dd-insights.php';

global $wpdb;
$ot  = $wpdb->prefix . 'dishdash_orders';
$oit = $wpdb->prefix . 'dishdash_order_items';
$ct  = $wpdb->prefix . 'dishdash_customers';
$rt  = $wpdb->prefix . 'dishdash_reservations';

// ── Active tab ────────────────────────────────────────────────────────────────
$active_tab = ( isset( $_GET['dd_tab'] ) && $_GET['dd_tab'] === 'reservations' ) ? 'reservations' : 'orders';
$tab_base   = admin_url( 'admin.php?page=dish-dash-analytics' );

// ── Date range ────────────────────────────────────────────────────────────────
$range = isset( $_GET['dd_range'] ) ? sanitize_key( $_GET['dd_range'] ) : '30days';
if ( $active_tab === 'orders' ) {
    $allowed = [ 'today', '7days', '30days', 'all' ];
    if ( ! in_array( $range, $allowed, true ) ) $range = '30days';
} else {
    $allowed = [ '7days', '30days', '90days', 'all' ];
    if ( ! in_array( $range, $allowed, true ) ) $range = '30days';
}

$ts = current_time( 'timestamp' );
switch ( $range ) {
    case 'today':
        $since = date('Y-m-d 00:00:00', $ts);
        $since_prior = date('Y-m-d 00:00:00', strtotime('-1 day', $ts));
        $until_prior = $since;
        $label = 'Today';
        break;
    case '7days':
        $since = date('Y-m-d 00:00:00', strtotime('-6 days', $ts));
        $since_prior = date('Y-m-d 00:00:00', strtotime('-13 days', $ts));
        $until_prior = $since;
        $label = 'Last 7 days';
        break;
    case '90days':
        $since = date('Y-m-d 00:00:00', strtotime('-89 days', $ts));
        $since_prior = null;
        $until_prior = null;
        $label = 'Last 90 days';
        break;
    case 'all':
        $since = '2000-01-01 00:00:00';
        $since_prior = null;
        $until_prior = null;
        $label = 'All time';
        break;
    default:
        $since = date('Y-m-d 00:00:00', strtotime('-29 days', $ts));
        $since_prior = date('Y-m-d 00:00:00', strtotime('-59 days', $ts));
        $until_prior = $since;
        $label = 'Last 30 days';
        break;
}

// ── Shared helpers ────────────────────────────────────────────────────────────
function dd_an_delta( $current, $prior ) {
    if ( $prior <= 0 ) return '';
    $pct = round( ( ( $current - $prior ) / $prior ) * 100 );
    $cls = $pct >= 0 ? 'dd-delta--up' : 'dd-delta--down';
    $sym = $pct >= 0 ? '↑' : '↓';
    return "<span class=\"dd-delta {$cls}\">{$sym} " . abs($pct) . "%</span>";
}
function dd_an_rwf( $n ): string {
    return number_format( (int) round( $n ) ) . ' RWF';
}
function dd_fmt_duration( float $seconds ): string {
    if ( $seconds <= 0 ) return '—';
    $m = round( $seconds / 60 );
    if ( $m >= 60 ) return floor($m/60) . 'h ' . ($m%60) . 'm';
    return $m . ' min';
}

// ── ORDERS TAB — queries ──────────────────────────────────────────────────────
if ( $active_tab === 'orders' ) {

    $kpi_revenue = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(total),0) FROM `{$ot}` WHERE status='delivered' AND is_test=0 AND created_at>=%s", $since
    ) );
    $fees_enabled = get_option( 'dd_fees_enabled', '1' ) === '1';
    $kpi_fees_analytics = $fees_enabled ? (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(platform_fee),0) FROM `{$ot}`
         WHERE status = 'delivered' AND platform_fee > 0
         AND created_at >= %s AND is_test = 0",
        $since
    ) ) : 0;
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
    $kpi_return_rate = $total_customers > 0 ? round( ($returning/$total_customers)*100 ) : 0;

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

    $today_date = date('Y-m-d', $ts);
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
    $chart_revenue = array_map( 'floatval', array_column($chart_rows, 'revenue') );

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

    $speed_trend_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT DATE(created_at) as day, AVG(TIMESTAMPDIFF(MINUTE, created_at, delivered_at)) as avg_min
         FROM `{$ot}` WHERE delivered_at IS NOT NULL AND is_test=0 AND created_at>=%s
         GROUP BY DATE(created_at) ORDER BY day ASC", $since
    ), ARRAY_A );
    $speed_trend_labels = array_map( fn($r) => date('D d', strtotime($r['day'])), $speed_trend_rows );
    $speed_trend_data   = array_map( fn($r) => round((float)$r['avg_min'],1), $speed_trend_rows );

    $slowest_orders = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, customer_name, created_at, TIMESTAMPDIFF(MINUTE, created_at, delivered_at) as mins
         FROM `{$ot}` WHERE delivered_at IS NOT NULL AND is_test=0 AND created_at>=%s
         ORDER BY mins DESC LIMIT 5", $since
    ), ARRAY_A );

    $speed_tips = [];
    $confirm_min_avg = $speed_confirm > 0 ? round($speed_confirm/60,1) : 0;
    $cook_min_avg    = $speed_cook    > 0 ? round($speed_cook/60,1)    : 0;
    $total_min_avg   = $speed_total   > 0 ? round($speed_total/60,1)   : 0;
    if ( $confirm_min_avg > 5 ) {
        $speed_tips[] = [ 'n'=>1, 'head'=>"Confirmation taking {$confirm_min_avg} min — industry best is under 2 min",
            'body'=>'Every minute before confirmation is a minute where the customer is uncertain their order was received. Enable audio alerts or keep the orders tab open on a dedicated device.' ];
    }
    if ( $cook_min_avg > 25 ) {
        $speed_tips[] = [ 'n'=>count($speed_tips)+1, 'head'=>"Kitchen prep averaging {$cook_min_avg} min",
            'body'=>'Pre-prepping your most-ordered items before peak hours and batching similar orders can reduce cook time by 20–30%.' ];
    }
    if ( $total_min_avg > 45 ) {
        $speed_tips[] = [ 'n'=>count($speed_tips)+1, 'head'=>"End-to-end time of {$total_min_avg} min is above the 45-min threshold",
            'body'=>'Customers who wait over 45 minutes are significantly less likely to reorder. Identify the slowest stage above and tackle it first.' ];
    }

    $status_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT status, COUNT(*) as cnt FROM `{$ot}` WHERE is_test=0 AND created_at>=%s GROUP BY status ORDER BY cnt DESC", $since
    ), ARRAY_A );
    $status_map = [];
    foreach ( $status_rows as $r ) $status_map[$r['status']] = (int)$r['cnt'];

    $peak_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM `{$ot}` WHERE is_test=0 AND created_at>=%s GROUP BY HOUR(created_at)", $since
    ), ARRAY_A );
    $peak_by_hour = array_fill(0, 24, 0);
    foreach ( $peak_rows as $r ) $peak_by_hour[(int)$r['hr']] = (int)$r['cnt'];

    $new_customers = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT o.customer_id) FROM `{$ot}` o
         JOIN `{$ct}` c ON c.id=o.customer_id WHERE o.is_test=0 AND o.created_at>=%s AND c.total_orders=1", $since
    ) );
    $returning_customers = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT o.customer_id) FROM `{$ot}` o
         JOIN `{$ct}` c ON c.id=o.customer_id WHERE o.is_test=0 AND o.created_at>=%s AND c.total_orders>1", $since
    ) );

    $top_items = $wpdb->get_results( $wpdb->prepare(
        "SELECT oi.item_name, COUNT(*) as cnt, COALESCE(SUM(oi.line_total),0) as revenue
         FROM `{$oit}` oi JOIN `{$ot}` o ON o.id=oi.order_id
         WHERE o.status='delivered' AND o.is_test=0 AND o.created_at>=%s
         GROUP BY oi.item_name ORDER BY cnt DESC LIMIT 10", $since
    ), ARRAY_A );
    $top_item_max = !empty($top_items) ? (int)$top_items[0]['cnt'] : 1;

    $tier_new      = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ct}` WHERE total_orders=0");
    $tier_regular  = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ct}` WHERE total_orders>=1 AND total_spent<100000");
    $tier_vip      = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ct}` WHERE total_spent>=100000 AND total_spent<250000");
    $tier_champion = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ct}` WHERE total_spent>=250000 AND total_spent<500000");
    $tier_diamond  = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ct}` WHERE total_spent>=500000");

    $payment_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT payment_method, COUNT(*) as cnt FROM `{$ot}` WHERE is_test=0 AND created_at>=%s GROUP BY payment_method ORDER BY cnt DESC", $since
    ), ARRAY_A );
    $type_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT order_type, COUNT(*) as cnt FROM `{$ot}` WHERE is_test=0 AND created_at>=%s GROUP BY order_type ORDER BY cnt DESC", $since
    ), ARRAY_A );

    $insights_engine = new DD_Insights();
    $maturity        = $insights_engine->get_maturity();
    $insights        = $insights_engine->get_insights();
}

// ── RESERVATIONS TAB — queries ────────────────────────────────────────────────
if ( $active_tab === 'reservations' ) {

    $kpi_total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$rt}` WHERE created_at>=%s AND is_test=0", $since
    ) );
    $kpi_confirmed = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$rt}` WHERE status='confirmed' AND created_at>=%s AND is_test=0", $since
    ) );
    $kpi_noshow = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$rt}` WHERE status='no_show' AND created_at>=%s AND is_test=0", $since
    ) );
    $kpi_avg_guests = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT AVG(guests) FROM `{$rt}` WHERE created_at>=%s AND is_test=0", $since
    ) );
    $confirm_rate = $kpi_total    > 0 ? round(($kpi_confirmed/$kpi_total)*100)    : 0;
    $noshow_rate  = $kpi_confirmed > 0 ? round(($kpi_noshow/$kpi_confirmed)*100)  : 0;

    $bookings_over_time = $wpdb->get_results( $wpdb->prepare(
        "SELECT DATE(created_at) as day, COUNT(*) as cnt FROM `{$rt}` WHERE created_at>=%s AND is_test=0
         GROUP BY DATE(created_at) ORDER BY day ASC", $since
    ), ARRAY_A );
    $bot_labels = array_map( fn($r) => date('D d', strtotime($r['day'])), $bookings_over_time );
    $bot_data   = array_map( fn($r) => (int)$r['cnt'], $bookings_over_time );

    $res_status_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT status, COUNT(*) as cnt FROM `{$rt}` WHERE created_at>=%s AND is_test=0 GROUP BY status", $since
    ), ARRAY_A );
    $res_status_map = [];
    foreach ($res_status_rows as $r) $res_status_map[$r['status']] = (int)$r['cnt'];

    $session_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT session, COUNT(*) as cnt FROM `{$rt}` WHERE created_at>=%s AND is_test=0 GROUP BY session ORDER BY cnt DESC", $since
    ), ARRAY_A );

    $dow_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT DAYOFWEEK(date) as dow, COUNT(*) as cnt FROM `{$rt}` WHERE created_at>=%s AND is_test=0 GROUP BY dow ORDER BY dow ASC", $since
    ), ARRAY_A );
    $dow_names = [1=>'Sun',2=>'Mon',3=>'Tue',4=>'Wed',5=>'Thu',6=>'Fri',7=>'Sat'];
    $dow_data  = array_fill(1, 7, 0);
    foreach ($dow_rows as $r) $dow_data[(int)$r['dow']] = (int)$r['cnt'];

    $party_buckets = [
        '1–2' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE guests BETWEEN 1 AND 2 AND created_at>=%s AND is_test=0",$since)),
        '3–4' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE guests BETWEEN 3 AND 4 AND created_at>=%s AND is_test=0",$since)),
        '5–6' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE guests BETWEEN 5 AND 6 AND created_at>=%s AND is_test=0",$since)),
        '7+'  => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE guests >= 7 AND created_at>=%s AND is_test=0",$since)),
    ];

    $advance_buckets = [
        'Same day' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE DATEDIFF(date,DATE(created_at))=0 AND created_at>=%s AND is_test=0",$since)),
        '1 day'    => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE DATEDIFF(date,DATE(created_at))=1 AND created_at>=%s AND is_test=0",$since)),
        '2–3 days' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE DATEDIFF(date,DATE(created_at)) BETWEEN 2 AND 3 AND created_at>=%s AND is_test=0",$since)),
        '4–7 days' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE DATEDIFF(date,DATE(created_at)) BETWEEN 4 AND 7 AND created_at>=%s AND is_test=0",$since)),
        '1 week+'  => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE DATEDIFF(date,DATE(created_at))>7 AND created_at>=%s AND is_test=0",$since)),
    ];
    $avg_advance = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT AVG(DATEDIFF(date,DATE(created_at))) FROM `{$rt}` WHERE created_at>=%s AND is_test=0", $since
    ) );

    $deposit_total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$rt}` WHERE deposit_required=1 AND created_at>=%s AND is_test=0", $since
    ) );
    $deposit_paid = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$rt}` WHERE deposit_required=1 AND deposit_status='paid' AND created_at>=%s AND is_test=0", $since
    ) );
    $deposit_autocancelled = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$rt}` WHERE status='auto_cancelled' AND created_at>=%s AND is_test=0", $since
    ) );
    $show_deposit = $deposit_total > 0;

    $res_insights = [];
    if ( $noshow_rate > 20 && $kpi_confirmed >= 5 ) {
        $res_insights[] = [ 'severity'=>'warning','icon'=>'👻',
            'headline'=>"{$noshow_rate}% of confirmed bookings are no-shows",
            'detail'=>'Industry healthy rate is under 10%. Consider enabling a deposit requirement to reduce no-shows.' ];
    }
    $sameday_pct = $kpi_total > 0 ? round(($advance_buckets['Same day']/$kpi_total)*100) : 0;
    if ( $sameday_pct > 50 ) {
        $res_insights[] = [ 'severity'=>'info','icon'=>'📲',
            'headline'=>"{$sameday_pct}% of bookings are made same-day",
            'detail'=>'Customers are booking last-minute. Promoting your booking page earlier (social media morning posts) may improve table planning.' ];
    }
    $large_party_pct = $kpi_total > 0 ? round((($party_buckets['5–6']+$party_buckets['7+'])/$kpi_total)*100) : 0;
    if ( $large_party_pct > 25 ) {
        $res_insights[] = [ 'severity'=>'opportunity','icon'=>'👥',
            'headline'=>"{$large_party_pct}% of bookings are large groups (5+ guests)",
            'detail'=>'Strong large-group demand. Review your table configuration to ensure you can seat these groups comfortably.' ];
    }
}
?>

<div class="dd-analytics-wrap">

  <!-- ── Shared Header ── -->
  <div class="dd-analytics-header">
    <div class="dd-analytics-header-left">
      <h1 class="dd-page-title">📈 Analytics</h1>
      <p class="dd-page-subtitle">
        <?php echo esc_html(get_option('dish_dash_restaurant_name','Restaurant')); ?>
        &mdash; <?php echo esc_html($label); ?>
      </p>
    </div>
    <div class="dd-analytics-filters">
      <?php if ( $active_tab === 'orders' ) :
        foreach (['today'=>'Today','7days'=>'7 Days','30days'=>'30 Days','all'=>'All Time'] as $k=>$l) : ?>
          <a href="<?php echo esc_url(add_query_arg(['dd_tab'=>'orders','dd_range'=>$k],$tab_base)); ?>"
             class="<?php echo $range===$k?'active':''; ?>"><?php echo esc_html($l); ?></a>
        <?php endforeach;
      else :
        foreach (['7days'=>'7 Days','30days'=>'30 Days','90days'=>'90 Days','all'=>'All Time'] as $k=>$l) : ?>
          <a href="<?php echo esc_url(add_query_arg(['dd_tab'=>'reservations','dd_range'=>$k],$tab_base)); ?>"
             class="<?php echo $range===$k?'active':''; ?>"><?php echo esc_html($l); ?></a>
        <?php endforeach;
      endif; ?>
    </div>
  </div>

  <!-- ── Tabs ── -->
  <div class="dd-analytics-tabs">
    <a href="<?php echo esc_url(add_query_arg('dd_tab','orders',$tab_base)); ?>"
       class="dd-analytics-tab <?php echo $active_tab==='orders'?'active':''; ?>">📦 Orders</a>
    <a href="<?php echo esc_url(add_query_arg('dd_tab','reservations',$tab_base)); ?>"
       class="dd-analytics-tab <?php echo $active_tab==='reservations'?'active':''; ?>">📅 Reservations</a>
  </div>

<?php if ( $active_tab === 'orders' ) : ?>

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
  <?php elseif ( ! empty($insights) ) : ?>
  <div class="dd-insights-section">
    <div class="dd-insights-label">
      🤖 AI Insights
      <?php if ($maturity['state']==='growing'): ?>
        <span class="dd-insights-confidence">Based on <?php echo (int)$maturity['count']; ?> orders — improves as more data flows in</span>
      <?php endif; ?>
    </div>
    <div class="dd-insights-strip">
      <?php foreach ($insights as $ins): ?>
      <div class="dd-insight-card dd-insight--<?php echo esc_attr($ins['severity']); ?>">
        <div class="dd-insight-icon"><?php echo esc_html($ins['icon']); ?></div>
        <div class="dd-insight-body">
          <div class="dd-insight-headline"><?php echo esc_html($ins['headline']); ?></div>
          <div class="dd-insight-detail"><?php echo esc_html($ins['detail']); ?></div>
          <?php if ($ins['action_url']): ?>
            <a href="<?php echo esc_url($ins['action_url']); ?>" class="dd-insight-action"><?php echo esc_html($ins['action_label']); ?> →</a>
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
      <?php echo dd_an_delta($kpi_revenue,$prior_revenue); ?>
    </div>
    <div class="dd-kpi-card" style="--kpi-accent:#4F46E5">
      <div class="dd-kpi-top"><span class="dd-kpi-icon">🛒</span></div>
      <div class="dd-kpi-label">Total Orders</div>
      <div class="dd-kpi-value"><?php echo number_format($kpi_orders); ?></div>
      <?php echo dd_an_delta($kpi_orders,$prior_orders); ?>
    </div>
    <div class="dd-kpi-card" style="--kpi-accent:#2563EB">
      <div class="dd-kpi-top"><span class="dd-kpi-icon">📊</span></div>
      <div class="dd-kpi-label">Avg Order Value</div>
      <div class="dd-kpi-value"><?php echo dd_an_rwf($kpi_aov); ?></div>
      <?php echo dd_an_delta($kpi_aov,$prior_aov); ?>
    </div>
    <div class="dd-kpi-card" style="--kpi-accent:#7C3AED">
      <div class="dd-kpi-top"><span class="dd-kpi-icon">🔁</span></div>
      <div class="dd-kpi-label">Repeat Customer Rate</div>
      <div class="dd-kpi-value"><?php echo $kpi_return_rate; ?>%</div>
    </div>
    <?php if ( $fees_enabled ) : ?>
    <div class="dd-kpi-card" style="--kpi-accent:#0EA5E9">
      <div class="dd-kpi-top"><span class="dd-kpi-icon">💳</span></div>
      <div class="dd-kpi-label">Platform Fees</div>
      <div class="dd-kpi-value"><?php echo dd_an_rwf( $kpi_fees_analytics ); ?></div>
      <div class="dd-kpi-delta" style="font-size:11px;color:#888;margin-top:4px">
        <?php
        $fee_per = (int) get_option( 'dd_per_order_fee', 750 );
        $fee_cnt = $fee_per > 0 ? round( $kpi_fees_analytics / $fee_per ) : 0;
        echo number_format( $fee_cnt ) . ' orders &times; RWF ' . number_format( $fee_per );
        ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Revenue Chart ── -->
  <div class="dd-dash-card dd-chart-card">
    <div class="dd-card-header">
      <span class="dd-card-title">Revenue Over Time</span>
      <span class="dd-card-meta"><?php echo esc_html($label); ?></span>
    </div>
    <div class="dd-chart-wrap" style="height:260px"><canvas id="ddRevenueChart"></canvas></div>
  </div>

  <!-- ── Order Speed ── -->
  <div class="dd-dash-card">
    <div class="dd-card-header">
      <span class="dd-card-title">⚡ Order Speed</span>
      <span class="dd-card-meta"><?php echo esc_html($label); ?> — delivered orders only</span>
    </div>
    <?php if (!$has_speed_data): ?>
      <p class="dd-empty-state">No completed deliveries in this period yet.</p>
    <?php else: ?>
    <div class="dd-speed-kpi-row">
      <div class="dd-speed-kpi">
        <div class="dd-speed-kpi-label">Avg Confirm Time</div>
        <div class="dd-speed-kpi-value"><?php echo dd_fmt_duration($speed_confirm); ?></div>
        <div class="dd-speed-kpi-sub">Order placed → confirmed</div>
        <?php if ($speed_confirm>0&&$speed_confirm/60<=2): ?><span class="dd-speed-badge dd-speed-badge--good">✓ Excellent</span>
        <?php elseif ($speed_confirm>0&&$speed_confirm/60<=5): ?><span class="dd-speed-badge dd-speed-badge--ok">~ Acceptable</span>
        <?php elseif ($speed_confirm>0): ?><span class="dd-speed-badge dd-speed-badge--warn">⚠ Too slow</span><?php endif; ?>
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
    $total_min=$speed_total>0?round($speed_total/60):0;
    $bench_pct=$total_min>0?min(100,round((30/max($total_min,30))*100)):0;
    $bench_color=$total_min<=20?'#059669':($total_min<=30?'#D97706':'#DC2626');
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
    <?php if (count($speed_trend_rows)>1): ?>
    <div class="dd-chart-wrap" style="height:180px;margin-top:20px"><canvas id="ddSpeedTrendChart"></canvas></div>
    <?php endif; ?>
    <?php if ($slowest_orders): ?>
    <div class="dd-speed-slowest">
      <div class="dd-section-title" style="margin:20px 0 10px">Slowest orders this period</div>
      <table class="dd-speed-table">
        <thead><tr><th>Order</th><th>Customer</th><th>Duration</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($slowest_orders as $so): ?>
          <tr>
            <td><a href="<?php echo esc_url(admin_url('admin.php?page=dish-dash-orders&open_order='.(int)$so['id'])); ?>">#<?php echo (int)$so['id']; ?></a></td>
            <td><?php echo esc_html($so['customer_name']?:'—'); ?></td>
            <td><strong><?php echo (int)$so['mins']; ?> min</strong></td>
            <td><?php echo esc_html(date('D d M, g:ia',strtotime($so['created_at']))); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if ($speed_tips): ?>
    <div class="dd-speed-tips">
      <div class="dd-speed-tips-title">💡 Where time is being lost — and how to fix it</div>
      <?php foreach ($speed_tips as $tip): ?>
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
    <?php endif; ?>
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
        <?php if ($top_items): ?>
        <ul class="dd-top-items-list">
          <?php foreach ($top_items as $i=>$item):
            $bar_pct=$top_item_max>0?round(((int)$item['cnt']/$top_item_max)*100):0; ?>
          <li>
            <span class="dd-top-item-rank"><?php echo $i+1; ?></span>
            <span class="dd-top-item-name"><?php echo esc_html($item['item_name']); ?></span>
            <div class="dd-top-item-bar-wrap"><div class="dd-top-item-bar" style="width:<?php echo $bar_pct; ?>%"></div></div>
            <div class="dd-top-item-meta">
              <span class="dd-top-item-count"><?php echo (int)$item['cnt']; ?> orders</span>
              <span class="dd-top-item-rev"><?php echo dd_an_rwf($item['revenue']); ?></span>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?><p class="dd-empty-state">No delivered orders in this period.</p><?php endif; ?>
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
      $payment_labels=['cod'=>'Cash on Delivery','momo'=>'MoMo Pay','card'=>'Card (Pesapal)','online'=>'Online'];
      $pay_total=array_sum(array_column($payment_rows,'cnt'))?:1;
      foreach ($payment_rows as $pr):
        $pct=round(((int)$pr['cnt']/$pay_total)*100);
        $lbl=$payment_labels[$pr['payment_method']]??ucfirst($pr['payment_method']); ?>
      <div class="dd-hbar-row">
        <div class="dd-hbar-label"><?php echo esc_html($lbl); ?></div>
        <div class="dd-hbar-track"><div class="dd-hbar-fill" style="width:<?php echo $pct; ?>%"></div></div>
        <div class="dd-hbar-val"><?php echo (int)$pr['cnt']; ?> (<?php echo $pct; ?>%)</div>
      </div>
      <?php endforeach;
      if (!$payment_rows) echo '<p class="dd-empty-state">No data yet.</p>'; ?>
    </div>
    <div class="dd-dash-card">
      <div class="dd-card-header"><span class="dd-card-title">Order Type</span></div>
      <?php
      $type_labels=['delivery'=>'🛵 Delivery','pickup'=>'🏃 Pickup','dine-in'=>'🍽 Dine-in','pos'=>'🖥 POS'];
      $type_total=array_sum(array_column($type_rows,'cnt'))?:1;
      foreach ($type_rows as $tr):
        $pct=round(((int)$tr['cnt']/$type_total)*100);
        $lbl=$type_labels[$tr['order_type']]??ucfirst($tr['order_type']); ?>
      <div class="dd-hbar-row">
        <div class="dd-hbar-label"><?php echo esc_html($lbl); ?></div>
        <div class="dd-hbar-track"><div class="dd-hbar-fill" style="width:<?php echo $pct; ?>%"></div></div>
        <div class="dd-hbar-val"><?php echo (int)$tr['cnt']; ?> (<?php echo $pct; ?>%)</div>
      </div>
      <?php endforeach;
      if (!$type_rows) echo '<p class="dd-empty-state">No data yet.</p>'; ?>
    </div>
  </div>

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

<?php endif; // orders tab ?>

<?php if ( $active_tab === 'reservations' ) : ?>

  <!-- ── Reservations AI Insights ── -->
  <?php if ($res_insights): ?>
  <div class="dd-insights-section">
    <div class="dd-insights-label">🤖 AI Insights</div>
    <div class="dd-insights-strip">
      <?php foreach ($res_insights as $ins): ?>
      <div class="dd-insight-card dd-insight--<?php echo esc_attr($ins['severity']); ?>">
        <div class="dd-insight-icon"><?php echo esc_html($ins['icon']); ?></div>
        <div class="dd-insight-body">
          <div class="dd-insight-headline"><?php echo esc_html($ins['headline']); ?></div>
          <div class="dd-insight-detail"><?php echo esc_html($ins['detail']); ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── KPI Row ── -->
  <div class="dd-kpi-row">
    <div class="dd-kpi-card" style="--kpi-accent:#4F46E5">
      <div class="dd-kpi-top"><span class="dd-kpi-icon">📅</span></div>
      <div class="dd-kpi-label">Total Bookings</div>
      <div class="dd-kpi-value"><?php echo number_format($kpi_total); ?></div>
    </div>
    <div class="dd-kpi-card" style="--kpi-accent:#059669">
      <div class="dd-kpi-top"><span class="dd-kpi-icon">✅</span></div>
      <div class="dd-kpi-label">Confirmation Rate</div>
      <div class="dd-kpi-value"><?php echo $confirm_rate; ?>%</div>
    </div>
    <div class="dd-kpi-card" style="--kpi-accent:#DC2626">
      <div class="dd-kpi-top"><span class="dd-kpi-icon">👻</span></div>
      <div class="dd-kpi-label">No-Show Rate</div>
      <div class="dd-kpi-value"><?php echo $noshow_rate; ?>%</div>
    </div>
    <div class="dd-kpi-card" style="--kpi-accent:#D97706">
      <div class="dd-kpi-top"><span class="dd-kpi-icon">👥</span></div>
      <div class="dd-kpi-label">Avg Party Size</div>
      <div class="dd-kpi-value"><?php echo round($kpi_avg_guests,1); ?></div>
    </div>
  </div>

  <!-- ── Bookings Over Time ── -->
  <div class="dd-dash-card dd-chart-card">
    <div class="dd-card-header">
      <span class="dd-card-title">Bookings Over Time</span>
      <span class="dd-card-meta"><?php echo esc_html($label); ?></span>
    </div>
    <div class="dd-chart-wrap" style="height:220px"><canvas id="ddResBookingsChart"></canvas></div>
  </div>

  <!-- ── Status + Session ── -->
  <div class="dd-cols-50">
    <div class="dd-dash-card">
      <div class="dd-card-header"><span class="dd-card-title">Bookings by Status</span></div>
      <div class="dd-chart-wrap" style="height:220px"><canvas id="ddResStatusChart"></canvas></div>
    </div>
    <div class="dd-dash-card">
      <div class="dd-card-header"><span class="dd-card-title">Bookings by Session</span></div>
      <?php
      $sess_total = array_sum(array_column($session_rows,'cnt')) ?: 1;
      foreach ($session_rows as $sr):
        $pct = round(((int)$sr['cnt']/$sess_total)*100);
      ?>
      <div class="dd-hbar-row">
        <div class="dd-hbar-label"><?php echo esc_html(ucfirst($sr['session'])); ?></div>
        <div class="dd-hbar-track"><div class="dd-hbar-fill" style="width:<?php echo $pct; ?>%"></div></div>
        <div class="dd-hbar-val"><?php echo (int)$sr['cnt']; ?> (<?php echo $pct; ?>%)</div>
      </div>
      <?php endforeach;
      if (!$session_rows) echo '<p class="dd-empty-state">No data yet.</p>'; ?>
    </div>
  </div>

  <!-- ── Peak Days + Party Size ── -->
  <div class="dd-cols-50">
    <div class="dd-dash-card">
      <div class="dd-card-header"><span class="dd-card-title">Peak Booking Days</span></div>
      <div class="dd-chart-wrap" style="height:200px"><canvas id="ddResDowChart"></canvas></div>
    </div>
    <div class="dd-dash-card">
      <div class="dd-card-header"><span class="dd-card-title">Party Size Distribution</span></div>
      <div class="dd-chart-wrap" style="height:200px"><canvas id="ddResPartyChart"></canvas></div>
    </div>
  </div>

  <!-- ── Advance Booking Window ── -->
  <div class="dd-dash-card">
    <div class="dd-card-header">
      <span class="dd-card-title">How Far in Advance People Book</span>
      <span class="dd-card-meta">Avg: <?php echo round($avg_advance,1); ?> days in advance</span>
    </div>
    <?php
    $adv_total = array_sum($advance_buckets) ?: 1;
    foreach ($advance_buckets as $lbl => $cnt):
      $pct = round(($cnt/$adv_total)*100);
    ?>
    <div class="dd-hbar-row">
      <div class="dd-hbar-label" style="width:90px"><?php echo esc_html($lbl); ?></div>
      <div class="dd-hbar-track"><div class="dd-hbar-fill" style="width:<?php echo $pct; ?>%"></div></div>
      <div class="dd-hbar-val"><?php echo $cnt; ?> (<?php echo $pct; ?>%)</div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Deposit Analytics (conditional) ── -->
  <?php if ($show_deposit): ?>
  <div class="dd-dash-card" style="margin-bottom:40px">
    <div class="dd-card-header"><span class="dd-card-title">Deposit Analytics</span></div>
    <div class="dd-kpi-row" style="margin-top:16px">
      <div class="dd-kpi-card" style="--kpi-accent:#059669">
        <div class="dd-kpi-label">Deposit Bookings</div>
        <div class="dd-kpi-value"><?php echo number_format($deposit_total); ?></div>
      </div>
      <div class="dd-kpi-card" style="--kpi-accent:#2563EB">
        <div class="dd-kpi-label">Deposits Paid</div>
        <div class="dd-kpi-value"><?php echo $deposit_total > 0 ? round(($deposit_paid/$deposit_total)*100) : 0; ?>%</div>
      </div>
      <div class="dd-kpi-card" style="--kpi-accent:#DC2626">
        <div class="dd-kpi-label">Auto-Cancelled</div>
        <div class="dd-kpi-value"><?php echo number_format($deposit_autocancelled); ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <script>
  window.ddResData = {
    bookings: { labels: <?php echo wp_json_encode(array_values($bot_labels)); ?>, data: <?php echo wp_json_encode(array_values($bot_data)); ?> },
    status:   { labels: <?php echo wp_json_encode(array_keys($res_status_map)); ?>, data: <?php echo wp_json_encode(array_values($res_status_map)); ?> },
    dow:      { labels: <?php echo wp_json_encode(array_values($dow_names)); ?>, data: <?php echo wp_json_encode(array_values($dow_data)); ?> },
    party:    { labels: <?php echo wp_json_encode(array_keys($party_buckets)); ?>, data: <?php echo wp_json_encode(array_values($party_buckets)); ?> }
  };
  window.ddAnalytics = { brandColor: '<?php echo esc_js(sanitize_hex_color(get_option('dish_dash_primary_color','#65040d'))); ?>' };
  </script>

<?php endif; // reservations tab ?>

</div><!-- /.dd-analytics-wrap -->
