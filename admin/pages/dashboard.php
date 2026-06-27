<?php
/**
 * Dashboard Admin Page
 *
 * @package DishDash
 * @since   3.4.44
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'read' ) ) return;

global $wpdb;

// ── Bulk mark stale orders as Delivered ───────────────────────────────────────
if (
    isset( $_POST['dd_bulk_deliver_stale'] ) &&
    check_admin_referer( 'dd_bulk_deliver_stale' )
) {
    $stale_rows = $wpdb->get_results(
        "SELECT id, status FROM `{$wpdb->prefix}dishdash_orders`
         WHERE status NOT IN ('delivered','cancelled')
         AND is_test = 0
         AND updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    $wpdb->query(
        "UPDATE `{$wpdb->prefix}dishdash_orders`
         SET status = 'delivered', updated_at = NOW()
         WHERE status NOT IN ('delivered','cancelled')
         AND is_test = 0
         AND updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    if ( class_exists( 'DD_Orders_Module' ) ) {
        foreach ( $stale_rows as $row ) {
            DD_Orders_Module::recalculate_fee_for_status_change( (int) $row->id, $row->status, 'delivered' );
        }
    }
    wp_safe_redirect( admin_url( 'admin.php?page=dish-dash' ) );
    exit;
}

// ── Date range filter ─────────────────────────────────────────────────────────
$range   = isset( $_GET['dd_range'] ) ? sanitize_key( $_GET['dd_range'] ) : 'today';
$allowed = [ 'today', '7days', '30days', 'all' ];
if ( ! in_array( $range, $allowed, true ) ) $range = 'today';

$ts = current_time( 'timestamp' );
switch ( $range ) {
    case '7days':
        $since = date( 'Y-m-d 00:00:00', strtotime( '-6 days', $ts ) );
        $label = 'Last 7 days';
        break;
    case '30days':
        $since = date( 'Y-m-d 00:00:00', strtotime( '-29 days', $ts ) );
        $label = 'Last 30 days';
        break;
    case 'all':
        $since = '2000-01-01 00:00:00';
        $label = 'All time';
        break;
    default:
        $since = date( 'Y-m-d 00:00:00', $ts );
        $label = 'Today';
        break;
}

$ot = $wpdb->prefix . 'dishdash_orders';
$ct = $wpdb->prefix . 'dishdash_customers';
$rt = $wpdb->prefix . 'dishdash_reservations';

// ── KPI queries ───────────────────────────────────────────────────────────────
$kpi_orders   = (int)   $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$ot}` WHERE created_at >= %s AND is_test = 0", $since
) );
$kpi_revenue  = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(total),0) FROM `{$ot}` WHERE status = 'delivered' AND created_at >= %s AND is_test = 0", $since
) );
// Fees this month — always current month regardless of $range filter
$month_start_for_fees = current_time( 'Y-m-' ) . '01 00:00:00';
$kpi_fees = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(platform_fee),0) FROM `{$ot}` WHERE status = 'delivered' AND platform_fee > 0 AND created_at >= %s AND is_test = 0",
    $month_start_for_fees
) );
// Check if this month is marked as paid
$bp_table        = $wpdb->prefix . 'dd_billing_payments';
$current_month   = current_time( 'Y-m' );
$this_month_paid = false;
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$bp_table}'" ) === $bp_table ) {
    $month_paid_row  = $wpdb->get_row( $wpdb->prepare(
        "SELECT paid, paid_at FROM `{$bp_table}` WHERE month = %s",
        $current_month
    ) );
    $this_month_paid = $month_paid_row && (int) $month_paid_row->paid === 1;
}

$kpi_pending  = (int)   $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$ot}` WHERE status IN ('pending','confirmed','ready') AND is_test = 0"
);
$kpi_delivered = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$ot}` WHERE status = 'delivered' AND created_at >= %s AND is_test = 0", $since
) );
$kpi_aov = $kpi_delivered > 0 ? round( $kpi_revenue / $kpi_delivered ) : 0;
$kpi_new_cust = (int)   $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$ct}` WHERE first_order_at >= %s", $since
) );
$today_date   = date( 'Y-m-d', $ts );
$kpi_res      = (int)   $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$rt}` WHERE date = %s AND status IN ('confirmed','pending')", $today_date
) );
$fees_enabled = get_option( 'dd_fees_enabled', '1' ) === '1';

// ── Chart data ────────────────────────────────────────────────────────────────
if ( $range === 'today' ) {
    $chart_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT HOUR(created_at) as period, COALESCE(SUM(total),0) as revenue
         FROM `{$ot}` WHERE status = 'delivered' AND DATE(created_at) = %s AND is_test = 0
         GROUP BY HOUR(created_at) ORDER BY period ASC",
        $today_date
    ), ARRAY_A );
    $chart_labels  = array_map( function( $r ) { return sprintf( '%02d:00', $r['period'] ); }, $chart_rows );
} else {
    $chart_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT DATE(created_at) as period, COALESCE(SUM(total),0) as revenue
         FROM `{$ot}` WHERE status = 'delivered' AND created_at >= %s AND is_test = 0
         GROUP BY DATE(created_at) ORDER BY period ASC",
        $since
    ), ARRAY_A );
    $chart_labels = array_map( function( $r ) { return date( 'D d', strtotime( $r['period'] ) ); }, $chart_rows );
}
$chart_revenue = array_column( $chart_rows, 'revenue' );

// ── Recent orders ─────────────────────────────────────────────────────────────
$recent_orders = $wpdb->get_results(
    "SELECT id, customer_name, total, status, created_at
     FROM `{$ot}` WHERE is_test = 0 ORDER BY created_at DESC LIMIT 6",
    ARRAY_A
);

// ── Today's reservations ──────────────────────────────────────────────────────
$todays_res = $wpdb->get_results( $wpdb->prepare(
    "SELECT name, time, guests, status FROM `{$rt}`
     WHERE date = %s ORDER BY time ASC LIMIT 4",
    $today_date
), ARRAY_A );

// ── Top menu items ────────────────────────────────────────────────────────────
$items_table = $wpdb->prefix . 'dishdash_order_items';
$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$items_table}'" ) === $items_table;

if ( $table_exists ) {
    $top_items = $wpdb->get_results(
        "SELECT oi.item_name as product_name, COUNT(*) as cnt
         FROM `{$items_table}` oi
         JOIN `{$ot}` o ON o.id = oi.order_id
         WHERE o.is_test = 0
         GROUP BY oi.item_name
         ORDER BY cnt DESC LIMIT 5",
        ARRAY_A
    );
} else {
    $top_items = [];
}
$max_item_count = ! empty( $top_items ) ? (int) $top_items[0]['cnt'] : 1;

// ── Customer tiers ────────────────────────────────────────────────────────────
$tier_new      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$ct}` WHERE total_orders = 0" );
$tier_regular  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$ct}` WHERE total_orders >= 1 AND total_spent < 100000" );
$tier_vip      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$ct}` WHERE total_spent >= 100000 AND total_spent < 250000" );
$tier_champion = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$ct}` WHERE total_spent >= 250000 AND total_spent < 500000" );
$tier_diamond  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$ct}` WHERE total_spent >= 500000" );
$tier_total    = max( 1, $tier_new + $tier_regular + $tier_vip + $tier_champion + $tier_diamond );

// ── Stale orders (unchanged for 24+ hours, not in terminal status) ────────────
$stale_orders = $wpdb->get_results(
    "SELECT id, customer_name, status, updated_at
     FROM `{$ot}`
     WHERE status NOT IN ('delivered', 'cancelled')
     AND is_test = 0
     AND updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
     ORDER BY updated_at ASC
     LIMIT 10",
    ARRAY_A
);

// ── Open/closed status ────────────────────────────────────────────────────────
$is_open   = false;
$hours_raw = get_option( 'dd_opening_hours', '' );
if ( $hours_raw ) {
    $hours = json_decode( $hours_raw, true );
    $day   = strtolower( date( 'l', $ts ) );
    $now   = date( 'H:i', $ts );
    if ( ! empty( $hours[ $day ]['open'] ) ) {
        foreach ( $hours[ $day ]['sessions'] ?? [] as $session ) {
            if ( $now >= $session[0] && $now <= $session[1] ) {
                $is_open = true;
                break;
            }
        }
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function dd_dash_format_rwf( $n ) {
    if ( $n >= 1000000 ) return round( $n / 1000000, 1 ) . 'M';
    if ( $n >= 1000 )    return round( $n / 1000, 1 ) . 'K';
    return number_format( (int) $n );
}

function dd_dash_time_ago( $datetime ) {
    $diff = time() - strtotime( $datetime );
    if ( $diff < 60 )    return $diff . 's ago';
    if ( $diff < 3600 )  return round( $diff / 60 ) . 'm ago';
    if ( $diff < 86400 ) return round( $diff / 3600 ) . 'h ago';
    return round( $diff / 86400 ) . 'd ago';
}

function dd_dash_status_badge( $status ) {
    $map = [
        'completed'  => [ 'Completed',  '#dcfce7', '#166534' ],
        'pending'    => [ 'Pending',    '#fef9c3', '#854d0e' ],
        'processing' => [ 'Processing', '#dbeafe', '#1e40af' ],
        'cancelled'  => [ 'Cancelled',  '#fee2e2', '#991b1b' ],
        'confirmed'  => [ 'Accepted',   '#dbeafe', '#1e40af' ],
        'no-show'    => [ 'No-show',    '#f3f4f6', '#6b7280' ],
    ];
    $s = $map[ $status ] ?? [ ucfirst( $status ), '#f3f4f6', '#374151' ];
    return sprintf(
        '<span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:500;background:%s;color:%s">%s</span>',
        esc_attr( $s[1] ), esc_attr( $s[2] ), esc_html( $s[0] )
    );
}

$current_url = admin_url( 'admin.php?page=dish-dash' );
?>

<div class="dd-dash-wrap">

  <!-- ── Header ────────────────────────────────────────────────────────────── -->
  <div class="dd-dash-header">
    <div class="dd-dash-header-left">
      <h1 class="dd-dash-title">Dashboard</h1>
      <span class="dd-dash-status <?php echo $is_open ? 'dd-status-open' : 'dd-status-closed'; ?>">
        <span class="dd-status-dot"></span>
        <?php echo $is_open ? 'Open now' : 'Closed'; ?>
      </span>
    </div>
    <div class="dd-dash-filter">
      <?php
      $ranges = [
          'today'  => 'Today',
          '7days'  => '7 Days',
          '30days' => '30 Days',
          'all'    => 'All Time',
      ];
      foreach ( $ranges as $key => $lbl ) :
          $active = $range === $key ? 'dd-filter-active' : '';
          $url    = esc_url( add_query_arg( 'dd_range', $key, $current_url ) );
      ?>
        <a href="<?php echo $url; ?>" class="dd-filter-btn <?php echo $active; ?>"><?php echo $lbl; ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── KPI row ───────────────────────────────────────────────────────────── -->
  <div class="dd-kpi-row">

    <div class="dd-kpi-card" style="--kpi-accent:#4F46E5">
      <div class="dd-kpi-top">
        <span class="dashicons dashicons-cart" style="color:#4F46E5" aria-hidden="true"></span>
      </div>
      <div class="dd-kpi-label">Orders</div>
      <div class="dd-kpi-value"><?php echo number_format( $kpi_orders ); ?></div>
    </div>

    <?php if ( current_user_can( 'dd_view_billing' ) ) : ?>
    <div class="dd-kpi-card" style="--kpi-accent:#059669">
      <div class="dd-kpi-top">
        <span class="dashicons dashicons-money-alt" style="color:#059669" aria-hidden="true"></span>
      </div>
      <div class="dd-kpi-label">Revenue</div>
      <div class="dd-kpi-value"><?php echo dd_dash_format_rwf( $kpi_revenue ); ?> <small>RWF</small></div>
    </div>
    <?php endif; ?>

    <div class="dd-kpi-card" style="--kpi-accent:#D97706">
      <div class="dd-kpi-top">
        <span class="dashicons dashicons-clock" style="color:#D97706" aria-hidden="true"></span>
      </div>
      <div class="dd-kpi-label">Active Orders</div>
      <div class="dd-kpi-value"><?php echo $kpi_pending; ?></div>
    </div>

    <div class="dd-kpi-card" style="--kpi-accent:#2563EB">
      <div class="dd-kpi-top">
        <span class="dashicons dashicons-chart-line" style="color:#2563EB" aria-hidden="true"></span>
      </div>
      <div class="dd-kpi-label">Avg Order Value</div>
      <div class="dd-kpi-value"><?php echo dd_dash_format_rwf( $kpi_aov ); ?> <small>RWF</small></div>
    </div>

    <div class="dd-kpi-card" style="--kpi-accent:#7C3AED">
      <div class="dd-kpi-top">
        <span class="dashicons dashicons-groups" style="color:#7C3AED" aria-hidden="true"></span>
      </div>
      <div class="dd-kpi-label">New Customers</div>
      <div class="dd-kpi-value"><?php echo number_format( $kpi_new_cust ); ?></div>
    </div>

    <div class="dd-kpi-card" style="--kpi-accent:#E11D48">
      <div class="dd-kpi-top">
        <span class="dashicons dashicons-calendar-alt" style="color:#E11D48" aria-hidden="true"></span>
      </div>
      <div class="dd-kpi-label">Reservations Today</div>
      <div class="dd-kpi-value"><?php echo $kpi_res; ?></div>
    </div>


  </div><!-- /.dd-kpi-row -->

  <!-- ── Revenue chart ─────────────────────────────────────────────────────── -->
  <div class="dd-dash-card dd-chart-card">
    <div class="dd-card-header">
      <span class="dd-card-title">Revenue</span>
      <span class="dd-card-meta"><?php echo esc_html( $label ); ?> &middot; RWF</span>
    </div>
    <div class="dd-chart-wrap">
      <canvas id="dd-revenue-chart"></canvas>
    </div>
  </div>

  <?php if ( current_user_can( 'dd_view_billing' ) && $fees_enabled && $kpi_fees > 0 ) : ?>
  <div class="dd-fees-inline <?php echo $this_month_paid ? 'dd-fees-paid' : ''; ?>">
    <span class="dd-fees-inline-icon"><?php echo $this_month_paid ? '✅' : '💳'; ?></span>
    <span class="dd-fees-inline-text">
      Platform fees this month:
      <strong>RWF <?php echo number_format( $kpi_fees ); ?></strong>
      <span class="dd-fees-inline-sub">
        &middot; <?php
          $fee_per_order = (int) get_option( 'dd_per_order_fee', 750 );
          $fee_count     = $fee_per_order > 0 ? round( $kpi_fees / $fee_per_order ) : 0;
          echo number_format( $fee_count );
        ?> delivered orders
      </span>
      <?php if ( $this_month_paid ) : ?>
        <span class="dd-fees-inline-paid-badge">Paid ✓</span>
      <?php else : ?>
        <span class="dd-fees-inline-unpaid-badge">Unpaid</span>
      <?php endif; ?>
    </span>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-billing' ) ); ?>" class="dd-fees-inline-link">View Billing →</a>
  </div>
  <?php endif; ?>

  <!-- ── Kitchen Queue ───────────────────────────────────────────────────── -->
  <?php
  $kitchen_orders = $wpdb->get_results(
      "SELECT id, order_number, customer_name, total, order_type, confirmed_at
       FROM {$wpdb->prefix}dishdash_orders
       WHERE status = 'confirmed'
       AND is_test = 0
       ORDER BY confirmed_at ASC",
      ARRAY_A
  );
  $prep_time = (int) get_option( 'dd_kitchen_prep_time', 30 );
  ?>

  <?php if ( ! empty( $kitchen_orders ) ) : ?>
  <div class="dd-card dd-kitchen-queue">
      <div class="dd-kitchen-queue__header">
          <h2 class="dd-section-title">
              🍳 Kitchen Queue
              <span class="dd-kitchen-count"><?php echo count( $kitchen_orders ); ?></span>
          </h2>
          <span class="dd-kitchen-target">Target: <?php echo $prep_time; ?> min</span>
      </div>
      <div class="dd-kitchen-queue__list">
          <?php foreach ( $kitchen_orders as $ko ) :
              $confirmed_ts = $ko['confirmed_at'] ? strtotime( $ko['confirmed_at'] ) : 0;
          ?>
          <div class="dd-kitchen-item"
               data-confirmed="<?php echo $confirmed_ts; ?>"
               data-prep="<?php echo $prep_time; ?>"
               data-order-id="<?php echo (int) $ko['id']; ?>">
              <div class="dd-kitchen-item__left">
                  <span class="dd-kitchen-item__number">
                      <?php echo esc_html( $ko['order_number'] ); ?>
                  </span>
                  <span class="dd-kitchen-item__customer">
                      <?php echo esc_html( $ko['customer_name'] ); ?>
                  </span>
                  <span class="dd-kitchen-item__type">
                      <?php echo esc_html( ucfirst( $ko['order_type'] ) ); ?>
                  </span>
              </div>
              <div class="dd-kitchen-item__right">
                  <span class="dd-kitchen-item__total">
                      <?php echo number_format( (float) $ko['total'], 0 ); ?> RWF
                  </span>
                  <span class="dd-kitchen-item__timer" data-confirmed="<?php echo $confirmed_ts; ?>">
                      --:--
                  </span>
                  <button class="dd-kitchen-item__ready dd-btn dd-btn--sm dd-btn--brand"
                          onclick="ddMarkReady(<?php echo (int) $ko['id']; ?>)">
                      Mark Ready
                  </button>
              </div>
          </div>
          <?php endforeach; ?>
      </div>
  </div>
  <?php endif; ?>

  <!-- ── Two-column split ──────────────────────────────────────────────────── -->
  <div class="dd-dash-cols">

    <!-- Left column -->
    <div class="dd-col-left">

      <!-- Recent orders -->
      <div class="dd-dash-card">
        <div class="dd-card-header">
          <span class="dd-card-title">Recent Orders</span>
        </div>
        <?php if ( empty( $recent_orders ) ) : ?>
          <p class="dd-empty">No orders yet.</p>
        <?php else : ?>
          <table class="dd-list-table">
            <?php foreach ( $recent_orders as $o ) : ?>
            <tr style="cursor:pointer" onclick="window.location='<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-orders&status=' . $o['status'] ) ); ?>'">
              <td class="dd-td-primary">
                <span class="dd-list-name"><?php echo esc_html( $o['customer_name'] ); ?></span>
                <span class="dd-list-meta"><?php echo dd_dash_time_ago( $o['created_at'] ); ?> &middot; #<?php echo (int) $o['id']; ?></span>
              </td>
              <td class="dd-td-badge"><?php echo dd_dash_status_badge( $o['status'] ); ?></td>
              <td class="dd-td-amount"><?php echo number_format( (float) $o['total'] ); ?> <small>RWF</small></td>
            </tr>
            <?php endforeach; ?>
          </table>
          <div class="dd-card-footer">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-orders' ) ); ?>" class="dd-card-link">View all orders &rarr;</a>
          </div>
        <?php endif; ?>
      </div>

      <!-- Today's reservations -->
      <div class="dd-dash-card">
        <div class="dd-card-header">
          <span class="dd-card-title">Today's Reservations</span>
        </div>
        <?php if ( empty( $todays_res ) ) : ?>
          <p class="dd-empty">No reservations today.</p>
        <?php else : ?>
          <table class="dd-list-table">
            <?php foreach ( $todays_res as $r ) : ?>
            <tr>
              <td class="dd-td-time"><?php echo esc_html( substr( $r['time'], 0, 5 ) ); ?></td>
              <td class="dd-td-primary">
                <span class="dd-list-name"><?php echo esc_html( $r['name'] ); ?></span>
                <span class="dd-list-meta"><?php echo (int) $r['guests']; ?> guests</span>
              </td>
              <td class="dd-td-badge"><?php echo dd_dash_status_badge( $r['status'] ); ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
          <div class="dd-card-footer">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-reservations' ) ); ?>" class="dd-card-link">View all reservations &rarr;</a>
          </div>
        <?php endif; ?>
      </div>

    </div><!-- /.dd-col-left -->

    <!-- Right column -->
    <div class="dd-col-right">

      <!-- Top menu items -->
      <div class="dd-dash-card">
        <div class="dd-card-header">
          <span class="dd-card-title">Top Menu Items</span>
        </div>
        <?php if ( empty( $top_items ) ) : ?>
          <p class="dd-empty">No order data yet.</p>
        <?php else : ?>
          <div class="dd-top-items">
            <?php foreach ( $top_items as $i => $item ) :
              $pct = round( ( (int) $item['cnt'] / $max_item_count ) * 100 );
            ?>
            <div class="dd-top-item">
              <span class="dd-item-rank"><?php printf( '%02d', $i + 1 ); ?></span>
              <div class="dd-item-body">
                <div class="dd-item-name"><?php echo esc_html( $item['product_name'] ); ?></div>
                <div class="dd-item-bar-wrap">
                  <div class="dd-item-bar" style="width:<?php echo $pct; ?>%"></div>
                </div>
              </div>
              <span class="dd-item-count"><?php echo (int) $item['cnt']; ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="dd-card-footer">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-analytics' ) ); ?>" class="dd-card-link">View analytics &rarr;</a>
          </div>
        <?php endif; ?>
      </div>

      <!-- Customer tiers -->
      <div class="dd-dash-card">
        <div class="dd-card-header">
          <span class="dd-card-title">Customer Tiers</span>
        </div>
        <?php
        $tiers = [
            [ $tier_new,      '#94a3b8', 'New'      ],
            [ $tier_regular,  '#2563EB', 'Regular'  ],
            [ $tier_vip,      '#7C3AED', 'VIP'      ],
            [ $tier_champion, '#D97706', 'Champion' ],
            [ $tier_diamond,  '#E11D48', 'Diamond'  ],
        ];
        ?>
        <div class="dd-tier-bar">
          <?php foreach ( $tiers as $t ) :
            $w = round( ( $t[0] / $tier_total ) * 100 );
            if ( $w < 1 ) continue;
          ?>
            <div class="dd-tier-seg" style="width:<?php echo $w; ?>%;background:<?php echo esc_attr( $t[1] ); ?>" title="<?php echo esc_attr( $t[2] . ': ' . $t[0] ); ?>"></div>
          <?php endforeach; ?>
        </div>
        <div class="dd-tier-legend">
          <?php foreach ( $tiers as $t ) : ?>
          <div class="dd-tier-item">
            <span class="dd-tier-dot" style="background:<?php echo esc_attr( $t[1] ); ?>"></span>
            <span class="dd-tier-name"><?php echo esc_html( $t[2] ); ?></span>
            <span class="dd-tier-count"><?php echo number_format( $t[0] ); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="dd-card-footer">
          <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-customers' ) ); ?>" class="dd-card-link">View customers &rarr;</a>
        </div>
      </div>

    </div><!-- /.dd-col-right -->

  </div><!-- /.dd-dash-cols -->

  <!-- ── Quick actions ─────────────────────────────────────────────────────── -->
  <div class="dd-quick-actions">
    <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=product' ) ); ?>" class="dd-action-btn">
      <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> Add Menu Item
    </a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-orders' ) ); ?>" class="dd-action-btn">
      <span class="dashicons dashicons-list-view" aria-hidden="true"></span> View Orders
    </a>
    <a href="<?php echo esc_url( home_url( '/restaurant-menu/' ) ); ?>" class="dd-action-btn" target="_blank" rel="noopener">
      <span class="dashicons dashicons-external" aria-hidden="true"></span> Preview Menu
    </a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-settings' ) ); ?>" class="dd-action-btn">
      <span class="dashicons dashicons-admin-settings" aria-hidden="true"></span> Settings
    </a>
  </div>

<script>
(function() {
    var prepTime = <?php echo (int) get_option( 'dd_kitchen_prep_time', 30 ); ?> * 60; // seconds

    function updateTimers() {
        var now = Math.floor( Date.now() / 1000 );
        document.querySelectorAll( '.dd-kitchen-item__timer' ).forEach( function( el ) {
            var confirmed = parseInt( el.dataset.confirmed, 10 );
            if ( ! confirmed ) { el.textContent = '--:--'; return; }
            var elapsed = now - confirmed;
            var mins    = Math.floor( elapsed / 60 );
            var secs    = elapsed % 60;
            el.textContent = String( mins ).padStart( 2, '0' ) + ':' + String( secs ).padStart( 2, '0' );

            // Turn red when over prep time
            var item = el.closest( '.dd-kitchen-item' );
            if ( elapsed > prepTime ) {
                item.classList.add( 'dd-kitchen-item--overdue' );
            } else {
                item.classList.remove( 'dd-kitchen-item--overdue' );
            }
        });
    }

    updateTimers();
    setInterval( updateTimers, 1000 );

    // Mark Ready button
    window.ddMarkReady = function( orderId ) {
        window.location.href = '<?php echo admin_url( 'admin.php?page=dish-dash-orders&open_order=' ); ?>' + orderId;
    };
})();
</script>

</div><!-- /.dd-dash-wrap -->

<style>
.dd-fees-inline {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 8px;
    padding: 10px 16px;
    margin-top: 12px;
    font-size: 13px;
    color: #0369a1;
}
.dd-fees-inline-icon { font-size: 16px; flex-shrink: 0; }
.dd-fees-inline-text { flex: 1; color: #0c4a6e; }
.dd-fees-inline-sub  { color: #0369a1; font-weight: 400; }
.dd-fees-inline-link {
    font-size: 12px;
    color: #0369a1;
    text-decoration: none;
    font-weight: 600;
    flex-shrink: 0;
    white-space: nowrap;
}
.dd-fees-inline-link:hover { text-decoration: underline; }
.dd-fees-inline-paid-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 600;
    color: #065f46;
    background: #d1fae5;
    border-radius: 3px;
    padding: 1px 6px;
    margin-left: 6px;
}
.dd-fees-inline-unpaid-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 600;
    color: #92400e;
    background: #fef3c7;
    border-radius: 3px;
    padding: 1px 6px;
    margin-left: 6px;
}
.dd-fees-inline.dd-fees-paid {
    background: #f0fdf4;
    border-color: #bbf7d0;
}
.dd-tier-bar {
    display: flex;
    height: 10px;
    border-radius: 6px;
    overflow: hidden;
    margin: 4px 0 18px;
    background: #f1f5f9;
}
.dd-tier-seg {
    height: 100%;
    transition: width 0.3s ease;
}
.dd-tier-legend {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px 24px;
}
.dd-tier-item {
    display: flex;
    align-items: center;
    gap: 10px;
}
.dd-tier-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}
.dd-tier-name {
    font-size: 14px;
    color: #64748b;
    flex: 1;
}
.dd-tier-count {
    font-size: 14px;
    font-weight: 600;
    color: #1a1a1a;
}
</style>

<!-- Chart data for JS -->
<script>
window.ddChartData = {
  labels:  <?php echo wp_json_encode( array_values( $chart_labels ) ); ?>,
  revenue: <?php echo wp_json_encode( array_values( array_map( 'floatval', $chart_revenue ) ) ); ?>
};
</script>
