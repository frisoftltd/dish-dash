<?php
/**
 * Orders Admin Page
 *
 * @package DishDash
 * @since   3.4.46
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) return;

global $wpdb;
$ot = $wpdb->prefix . 'dishdash_orders';

// ── Status filter ─────────────────────────────────────────────────────────────
$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
$allowed = [ 'all', 'pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery', 'delivered', 'cancelled' ];
if ( ! in_array( $status_filter, $allowed, true ) ) $status_filter = 'all';

// ── Summary stats ─────────────────────────────────────────────────────────────
$total_orders  = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM `{$ot}`" );
$total_revenue = (float) $wpdb->get_var( "SELECT COALESCE(SUM(total),0) FROM `{$ot}`" );
$total_pending = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM `{$ot}` WHERE status IN ('pending','processing')" );
$total_today   = (int)   $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$ot}` WHERE DATE(created_at) = %s",
    current_time( 'Y-m-d' )
) );

// ── Orders query ──────────────────────────────────────────────────────────────
if ( $status_filter !== 'all' ) {
    $orders = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM `{$ot}` WHERE status = %s ORDER BY created_at DESC LIMIT 100",
        $status_filter
    ), ARRAY_A );
} else {
    $orders = $wpdb->get_results(
        "SELECT * FROM `{$ot}` ORDER BY created_at DESC LIMIT 100",
        ARRAY_A
    );
}

// ── Per-status counts for filter tabs ─────────────────────────────────────────
$status_counts = [];
$counts_raw = $wpdb->get_results(
    "SELECT status, COUNT(*) as cnt FROM `{$ot}` GROUP BY status",
    ARRAY_A
);
foreach ( $counts_raw as $row ) {
    $status_counts[ $row['status'] ] = (int) $row['cnt'];
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function dd_orders_status_badge( $status ) {
    $map = [
        'pending'          => [ 'Pending',          '#fef9c3', '#854d0e' ],
        'confirmed'        => [ 'Confirmed',        '#dbeafe', '#1e40af' ],
        'preparing'        => [ 'Preparing',        '#ede9fe', '#5b21b6' ],
        'ready'            => [ 'Ready',            '#dcfce7', '#166534' ],
        'out_for_delivery' => [ 'Out for Delivery', '#e0f2fe', '#0369a1' ],
        'delivered'        => [ 'Delivered',        '#dcfce7', '#166534' ],
        'cancelled'        => [ 'Cancelled',        '#fee2e2', '#991b1b' ],
        'processing'       => [ 'Processing',       '#dbeafe', '#1e40af' ],
    ];
    $s = $map[ $status ] ?? [ ucfirst( $status ), '#f3f4f6', '#374151' ];
    return sprintf(
        '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:500;background:%s;color:%s">%s</span>',
        esc_attr( $s[1] ), esc_attr( $s[2] ), esc_html( $s[0] )
    );
}

function dd_orders_format_rwf( $n ) {
    return number_format( (float) $n, 0, '.', ',' );
}

$current_url = admin_url( 'admin.php?page=dish-dash-orders' );

$filter_tabs = [
    'all'              => 'All',
    'pending'          => 'Pending',
    'confirmed'        => 'Confirmed',
    'preparing'        => 'Preparing',
    'ready'            => 'Ready',
    'out_for_delivery' => 'Out for Delivery',
    'delivered'        => 'Delivered',
    'cancelled'        => 'Cancelled',
];
?>

<div class="dd-orders-wrap">

  <!-- ── Page header ─────────────────────────────────────────────────────── -->
  <div class="dd-orders-header">
    <h1 class="dd-page-title">Orders</h1>
  </div>

  <!-- ── Summary stat cards ─────────────────────────────────────────────── -->
  <div class="dd-orders-stats">

    <div class="dd-stat-card" style="--stat-accent:#4F46E5">
      <div class="dd-stat-label">Total Orders</div>
      <div class="dd-stat-value"><?php echo number_format( $total_orders ); ?></div>
    </div>

    <div class="dd-stat-card" style="--stat-accent:#059669">
      <div class="dd-stat-label">Total Revenue</div>
      <div class="dd-stat-value"><?php echo dd_orders_format_rwf( $total_revenue ); ?> <small>RWF</small></div>
    </div>

    <div class="dd-stat-card" style="--stat-accent:#D97706">
      <div class="dd-stat-label">Pending</div>
      <div class="dd-stat-value"><?php echo $total_pending; ?></div>
    </div>

    <div class="dd-stat-card" style="--stat-accent:#2563EB">
      <div class="dd-stat-label">Today's Orders</div>
      <div class="dd-stat-value"><?php echo $total_today; ?></div>
    </div>

  </div>

  <!-- ── Filter tabs ────────────────────────────────────────────────────── -->
  <div class="dd-orders-filters">
    <?php foreach ( $filter_tabs as $key => $lbl ) :
      $active = $status_filter === $key ? 'dd-tab-active' : '';
      $url    = esc_url( add_query_arg( 'status', $key, $current_url ) );
      $count  = $key === 'all'
          ? $total_orders
          : ( $status_counts[ $key ] ?? 0 );
    ?>
      <a href="<?php echo $url; ?>" class="dd-tab-btn <?php echo $active; ?>">
        <?php echo esc_html( $lbl ); ?>
        <?php if ( $count > 0 ) : ?>
          <span class="dd-tab-count"><?php echo $count; ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- ── Orders table ───────────────────────────────────────────────────── -->
  <div class="dd-orders-card">
    <?php if ( empty( $orders ) ) : ?>
      <p class="dd-orders-empty">No orders found.</p>
    <?php else : ?>
      <table class="dd-orders-table">
        <thead>
          <tr>
            <th>Order</th>
            <th>Customer</th>
            <th>Type</th>
            <th>Total</th>
            <th>Status</th>
            <th>Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ( $orders as $o ) :
            $order_num = ! empty( $o['order_number'] ) ? $o['order_number'] : 'DD-' . str_pad( $o['id'], 5, '0', STR_PAD_LEFT );
          ?>
          <tr>
            <td class="dd-orders-col-id">
              <span class="dd-order-num"><?php echo esc_html( $order_num ); ?></span>
            </td>
            <td class="dd-orders-col-customer">
              <span class="dd-customer-name"><?php echo esc_html( $o['customer_name'] ); ?></span>
              <?php if ( ! empty( $o['customer_phone'] ) ) : ?>
                <span class="dd-customer-phone"><?php echo esc_html( $o['customer_phone'] ); ?></span>
              <?php endif; ?>
            </td>
            <td class="dd-orders-col-type">
              <span class="dd-type-pill"><?php echo esc_html( ucfirst( $o['order_type'] ?? 'delivery' ) ); ?></span>
            </td>
            <td class="dd-orders-col-total">
              <span class="dd-order-total"><?php echo dd_orders_format_rwf( $o['total'] ); ?></span>
              <span class="dd-order-currency">RWF</span>
            </td>
            <td class="dd-orders-col-status">
              <?php echo dd_orders_status_badge( $o['status'] ); ?>
            </td>
            <td class="dd-orders-col-date">
              <?php echo esc_html( date( 'd M Y H:i', strtotime( $o['created_at'] ) ) ); ?>
            </td>
            <td class="dd-orders-col-action">
              <?php if ( ! in_array( $o['status'], [ 'delivered', 'cancelled' ], true ) ) : ?>
                <?php
                $next_statuses = [
                    'pending'          => [ 'confirmed'        => 'Confirm' ],
                    'confirmed'        => [ 'preparing'        => 'Preparing' ],
                    'preparing'        => [ 'ready'            => 'Ready' ],
                    'ready'            => [ 'out_for_delivery' => 'Out for Delivery' ],
                    'out_for_delivery' => [ 'delivered'        => 'Delivered' ],
                ];
                $actions = $next_statuses[ $o['status'] ] ?? [];
                foreach ( $actions as $next_status => $next_label ) :
                    $action_url = wp_nonce_url(
                        add_query_arg([
                            'page'        => 'dish-dash-orders',
                            'action'      => 'update_status',
                            'order_id'    => $o['id'],
                            'new_status'  => $next_status,
                            'status'      => $status_filter,
                        ], admin_url( 'admin.php' ) ),
                        'dd_order_status_' . $o['id']
                    );
                ?>
                  <a href="<?php echo esc_url( $action_url ); ?>" class="dd-action-link dd-action-primary">
                    → <?php echo esc_html( $next_label ); ?>
                  </a>
                <?php endforeach; ?>
                <a href="<?php echo esc_url( wp_nonce_url(
                    add_query_arg([
                        'page'       => 'dish-dash-orders',
                        'action'     => 'update_status',
                        'order_id'   => $o['id'],
                        'new_status' => 'cancelled',
                        'status'     => $status_filter,
                    ], admin_url( 'admin.php' ) ),
                    'dd_order_status_' . $o['id']
                ) ); ?>" class="dd-action-link dd-action-cancel"
                   onclick="return confirm('Cancel this order?')">
                  → Cancel
                </a>
              <?php else : ?>
                <span class="dd-action-done">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div><!-- /.dd-orders-wrap -->
