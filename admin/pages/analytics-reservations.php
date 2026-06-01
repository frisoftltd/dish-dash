<?php
/**
 * File:    admin/pages/analytics-reservations.php
 * Purpose: Reservations Analytics admin page.
 * Last modified: v3.4.87
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) return;

global $wpdb;
$rt = $wpdb->prefix . 'dishdash_reservations';
$ct = $wpdb->prefix . 'dishdash_customers';

// ── Date range ────────────────────────────────────────────────────────────────
$range   = isset( $_GET['dd_range'] ) ? sanitize_key( $_GET['dd_range'] ) : '30days';
$allowed = [ '7days', '30days', '90days', 'all' ];
if ( ! in_array( $range, $allowed, true ) ) $range = '30days';

$ts = current_time( 'timestamp' );
switch ( $range ) {
    case '7days':  $since = date('Y-m-d 00:00:00', strtotime('-6 days',$ts));  $label = 'Last 7 days';  break;
    case '90days': $since = date('Y-m-d 00:00:00', strtotime('-89 days',$ts)); $label = 'Last 90 days'; break;
    case 'all':    $since = '2000-01-01 00:00:00'; $label = 'All time'; break;
    default:       $since = date('Y-m-d 00:00:00', strtotime('-29 days',$ts)); $label = 'Last 30 days'; break;
}

// ── KPI queries ───────────────────────────────────────────────────────────────
$kpi_total = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$rt}` WHERE created_at>=%s", $since
) );
$kpi_confirmed = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$rt}` WHERE status='confirmed' AND created_at>=%s", $since
) );
$kpi_noshow = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$rt}` WHERE status='no_show' AND created_at>=%s", $since
) );
$kpi_avg_guests = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT AVG(guests) FROM `{$rt}` WHERE created_at>=%s", $since
) );
$confirm_rate = $kpi_total    > 0 ? round( ($kpi_confirmed / $kpi_total) * 100 )    : 0;
$noshow_rate  = $kpi_confirmed > 0 ? round( ($kpi_noshow / $kpi_confirmed) * 100 ) : 0;

// ── Bookings over time (daily) ────────────────────────────────────────────────
$bookings_over_time = $wpdb->get_results( $wpdb->prepare(
    "SELECT DATE(created_at) as day, COUNT(*) as cnt
     FROM `{$rt}` WHERE created_at>=%s
     GROUP BY DATE(created_at) ORDER BY day ASC", $since
), ARRAY_A );
$bot_labels = array_map( fn($r) => date('D d', strtotime($r['day'])), $bookings_over_time );
$bot_data   = array_map( fn($r) => (int)$r['cnt'], $bookings_over_time );

// ── Status breakdown ──────────────────────────────────────────────────────────
$status_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT status, COUNT(*) as cnt FROM `{$rt}` WHERE created_at>=%s GROUP BY status", $since
), ARRAY_A );
$status_map = [];
foreach ($status_rows as $r) $status_map[$r['status']] = (int)$r['cnt'];

// ── Session breakdown ─────────────────────────────────────────────────────────
$session_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT session, COUNT(*) as cnt FROM `{$rt}` WHERE created_at>=%s GROUP BY session ORDER BY cnt DESC", $since
), ARRAY_A );

// ── Peak booking days (day of week) ──────────────────────────────────────────
$dow_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT DAYOFWEEK(date) as dow, COUNT(*) as cnt
     FROM `{$rt}` WHERE created_at>=%s GROUP BY dow ORDER BY dow ASC", $since
), ARRAY_A );
$dow_names = [1=>'Sun',2=>'Mon',3=>'Tue',4=>'Wed',5=>'Thu',6=>'Fri',7=>'Sat'];
$dow_data  = array_fill(1, 7, 0);
foreach ($dow_rows as $r) $dow_data[(int)$r['dow']] = (int)$r['cnt'];

// ── Party size distribution ───────────────────────────────────────────────────
$party_buckets = [
    '1–2' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE guests BETWEEN 1 AND 2 AND created_at>=%s",$since)),
    '3–4' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE guests BETWEEN 3 AND 4 AND created_at>=%s",$since)),
    '5–6' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE guests BETWEEN 5 AND 6 AND created_at>=%s",$since)),
    '7+'  => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE guests >= 7 AND created_at>=%s",$since)),
];

// ── Advance booking window ────────────────────────────────────────────────────
$advance_buckets = [
    'Same day' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE DATEDIFF(date,DATE(created_at))=0 AND created_at>=%s",$since)),
    '1 day'    => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE DATEDIFF(date,DATE(created_at))=1 AND created_at>=%s",$since)),
    '2–3 days' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE DATEDIFF(date,DATE(created_at)) BETWEEN 2 AND 3 AND created_at>=%s",$since)),
    '4–7 days' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE DATEDIFF(date,DATE(created_at)) BETWEEN 4 AND 7 AND created_at>=%s",$since)),
    '1 week+'  => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$rt}` WHERE DATEDIFF(date,DATE(created_at)) > 7 AND created_at>=%s",$since)),
];
$avg_advance = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT AVG(DATEDIFF(date, DATE(created_at))) FROM `{$rt}` WHERE created_at>=%s", $since
) );

// ── Deposit analytics (conditional) ──────────────────────────────────────────
$deposit_total = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$rt}` WHERE deposit_required=1 AND created_at>=%s", $since
) );
$deposit_paid = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$rt}` WHERE deposit_required=1 AND deposit_status='paid' AND created_at>=%s", $since
) );
$deposit_autocancelled = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$rt}` WHERE status='auto_cancelled' AND created_at>=%s", $since
) );
$show_deposit = $deposit_total > 0;

// ── Reservations AI insights ──────────────────────────────────────────────────
$res_insights = [];

if ( $noshow_rate > 20 && $kpi_confirmed >= 5 ) {
    $res_insights[] = [
        'severity' => 'warning', 'icon' => '👻',
        'headline' => "{$noshow_rate}% of confirmed bookings are no-shows",
        'detail'   => 'Industry healthy rate is under 10%. Consider enabling a deposit requirement to reduce no-shows.',
    ];
}

if ( $show_deposit && $deposit_total >= 5 ) {
    $noshow_deposit = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$rt}` WHERE deposit_required=1 AND status='no_show' AND created_at>=%s",$since));
    $noshow_no_dep  = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$rt}` WHERE deposit_required=0 AND status='no_show' AND created_at>=%s",$since));
    $conf_deposit   = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$rt}` WHERE deposit_required=1 AND status='confirmed' AND created_at>=%s",$since));
    $conf_no_dep    = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$rt}` WHERE deposit_required=0 AND status='confirmed' AND created_at>=%s",$since));
    if ( $conf_deposit > 0 && $conf_no_dep > 0 ) {
        $ns_rate_dep    = round(($noshow_deposit/$conf_deposit)*100);
        $ns_rate_no_dep = round(($noshow_no_dep/$conf_no_dep)*100);
        if ( $ns_rate_dep < $ns_rate_no_dep - 10 ) {
            $res_insights[] = [
                'severity' => 'opportunity', 'icon' => '💳',
                'headline' => "Deposit bookings have {$ns_rate_dep}% no-show rate vs {$ns_rate_no_dep}% without",
                'detail'   => 'Your data confirms: deposits significantly reduce no-shows. Consider making deposits required for all bookings.',
            ];
        }
    }
}

$sameday_pct = $kpi_total > 0 ? round(($advance_buckets['Same day']/$kpi_total)*100) : 0;
if ( $sameday_pct > 50 ) {
    $res_insights[] = [
        'severity' => 'info', 'icon' => '📲',
        'headline' => "{$sameday_pct}% of bookings are made same-day",
        'detail'   => 'Customers are booking last-minute. Promoting your booking page earlier (social media morning posts) may improve table planning.',
    ];
}

$large_party_pct = $kpi_total > 0 ? round((($party_buckets['5–6']+$party_buckets['7+'])/$kpi_total)*100) : 0;
if ( $large_party_pct > 25 ) {
    $res_insights[] = [
        'severity' => 'opportunity', 'icon' => '👥',
        'headline' => "{$large_party_pct}% of bookings are large groups (5+ guests)",
        'detail'   => 'Strong large-group demand. Review your table configuration to ensure you can seat these groups comfortably.',
    ];
}

$base_url = admin_url('admin.php?page=dish-dash-analytics-reservations');
?>

<div class="dd-analytics-wrap">

  <!-- Header -->
  <div class="dd-analytics-header">
    <div class="dd-analytics-header-left">
      <h1 class="dd-page-title">📅 Reservations Analytics</h1>
      <p class="dd-page-subtitle">
        <?php echo esc_html(get_option('dish_dash_restaurant_name','Restaurant')); ?>
        &mdash; <?php echo esc_html($label); ?>
      </p>
    </div>
    <div class="dd-analytics-filters">
      <?php foreach (['7days'=>'7 Days','30days'=>'30 Days','90days'=>'90 Days','all'=>'All Time'] as $k=>$l): ?>
        <a href="<?php echo esc_url(add_query_arg('dd_range',$k,$base_url)); ?>"
           class="<?php echo $range===$k?'active':''; ?>"><?php echo esc_html($l); ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Sub-page tabs -->
  <div class="dd-analytics-tabs">
    <a href="<?php echo esc_url(admin_url('admin.php?page=dish-dash-analytics')); ?>" class="dd-analytics-tab">📦 Orders</a>
    <a href="<?php echo esc_url($base_url); ?>" class="dd-analytics-tab active">📅 Reservations</a>
  </div>

  <!-- AI Insights -->
  <?php if ($res_insights): ?>
  <div class="dd-insights-section">
    <div class="dd-insights-label">🤖 AI Insights</div>
    <div class="dd-insights-strip">
      <?php foreach ($res_insights as $ins):
        $sev_class = 'dd-insight--' . esc_attr($ins['severity']); ?>
      <div class="dd-insight-card <?php echo $sev_class; ?>">
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

  <!-- KPI Row -->
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

  <!-- Bookings over time -->
  <div class="dd-dash-card dd-chart-card">
    <div class="dd-card-header">
      <span class="dd-card-title">Bookings Over Time</span>
      <span class="dd-card-meta"><?php echo esc_html($label); ?></span>
    </div>
    <div class="dd-chart-wrap" style="height:220px"><canvas id="ddResBookingsChart"></canvas></div>
  </div>

  <!-- Status + Session -->
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

  <!-- Peak days + Party size -->
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

  <!-- Advance booking window -->
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

  <!-- Deposit analytics (conditional) -->
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

</div><!-- /.dd-analytics-wrap -->

<script>
window.ddResData = {
  bookings: { labels: <?php echo wp_json_encode(array_values($bot_labels)); ?>, data: <?php echo wp_json_encode(array_values($bot_data)); ?> },
  status:   { labels: <?php echo wp_json_encode(array_keys($status_map)); ?>, data: <?php echo wp_json_encode(array_values($status_map)); ?> },
  dow:      { labels: <?php echo wp_json_encode(array_values($dow_names)); ?>, data: <?php echo wp_json_encode(array_values($dow_data)); ?> },
  party:    { labels: <?php echo wp_json_encode(array_keys($party_buckets)); ?>, data: <?php echo wp_json_encode(array_values($party_buckets)); ?> }
};
window.ddAnalytics = { brandColor: '<?php echo esc_js(sanitize_hex_color(get_option('dish_dash_primary_color','#65040d'))); ?>' };
</script>
