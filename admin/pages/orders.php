<?php
/**
 * Orders Admin Page
 *
 * @package DishDash
 * @since   3.4.46
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'dd_manage_orders' ) ) return;

global $wpdb;
$ot = $wpdb->prefix . 'dishdash_orders';

// ── Handle status update POST ─────────────────────────────────────────────────
if (
    isset( $_POST['dd_update_order_status'] ) &&
    isset( $_POST['order_id'] ) &&
    isset( $_POST['new_status'] ) &&
    check_admin_referer( 'dd_order_status_' . (int) $_POST['order_id'] )
) {
    $order_id         = (int) $_POST['order_id'];
    $new_status       = sanitize_key( $_POST['new_status'] );
    $allowed_statuses = [ 'pending', 'confirmed', 'ready', 'delivered', 'cancelled' ];

    $old_status = $wpdb->get_var( $wpdb->prepare(
        "SELECT status FROM {$wpdb->prefix}dishdash_orders WHERE id = %d",
        $order_id
    ) );

    if ( in_array( $new_status, $allowed_statuses, true ) ) {
        $wpdb->update(
            $wpdb->prefix . 'dishdash_orders',
            [ 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $order_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        if ( $old_status && class_exists( 'DD_Orders_Module' ) ) {
            DD_Orders_Module::recalculate_fee_for_status_change( (int) $order_id, $old_status, $new_status );
        }
    }

    $redirect = add_query_arg(
        'status',
        sanitize_key( $_POST['current_status_filter'] ?? 'all' ),
        admin_url( 'admin.php?page=dish-dash-orders' )
    );
    wp_safe_redirect( $redirect );
    exit;
}

// Show error if nonce failed — helps diagnose silently-failing submissions
if ( isset( $_POST['dd_update_order_status'] ) && ! isset( $_POST['_wpnonce'] ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>Order status update failed — missing nonce. Please refresh the page and try again.</p></div>';
    } );
}

// ── Handle bulk status + test flag updates ────────────────────────────────────
if (
    isset( $_POST['dd_bulk_action'] ) &&
    isset( $_POST['dd_bulk_order_ids'] ) &&
    check_admin_referer( 'dd_bulk_orders' )
) {
    $action    = sanitize_key( $_POST['dd_bulk_action'] );
    $order_ids = array_map( 'absint', (array) $_POST['dd_bulk_order_ids'] );
    $order_ids = array_filter( $order_ids );

    if ( ! empty( $order_ids ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

        if ( $action === 'mark_test' ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE `{$wpdb->prefix}dishdash_orders` SET is_test = 1, updated_at = NOW() WHERE id IN ({$placeholders})",
                ...$order_ids
            ) );
        } elseif ( $action === 'unmark_test' ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE `{$wpdb->prefix}dishdash_orders` SET is_test = 0, updated_at = NOW() WHERE id IN ({$placeholders})",
                ...$order_ids
            ) );
        } else {
            $ids_in   = implode( ',', array_map( 'intval', $order_ids ) );
            $pre_rows = $wpdb->get_results(
                "SELECT id, status FROM `{$wpdb->prefix}dishdash_orders` WHERE id IN ({$ids_in})"
            );
            $allowed = [ 'pending', 'confirmed', 'ready', 'delivered', 'cancelled' ];
            if ( in_array( $action, $allowed, true ) ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE `{$wpdb->prefix}dishdash_orders` SET status = %s, updated_at = NOW() WHERE id IN ({$placeholders})",
                    $action, ...$order_ids
                ) );
                if ( class_exists( 'DD_Orders_Module' ) ) {
                    foreach ( $pre_rows as $row ) {
                        DD_Orders_Module::recalculate_fee_for_status_change( (int) $row->id, $row->status, $action );
                    }
                }
            }
        }
    }

    $redirect = add_query_arg(
        'status',
        sanitize_key( $_POST['current_status_filter'] ?? 'all' ),
        admin_url( 'admin.php?page=dish-dash-orders' )
    );
    wp_safe_redirect( $redirect );
    exit;
}

// ── Status filter ─────────────────────────────────────────────────────────────
$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
$allowed = [ 'all', 'pending', 'confirmed', 'ready', 'delivered', 'cancelled', 'test' ];
if ( ! in_array( $status_filter, $allowed, true ) ) $status_filter = 'all';

// ── Pagination params ─────────────────────────────────────────────────────────
$per_page_options = [ 25, 50, 75 ];
$per_page_raw     = isset( $_GET['per_page'] ) ? (int) $_GET['per_page'] : 25;
$per_page         = in_array( $per_page_raw, array_merge( $per_page_options, [ 99999 ] ), true )
                    ? $per_page_raw
                    : 25;
$paged            = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$offset           = ( $paged - 1 ) * $per_page;

// ── Search and filter params ──────────────────────────────────────────────────
$search_query     = isset( $_GET['dd_search'] )    ? sanitize_text_field( wp_unslash( $_GET['dd_search'] ) ) : '';
$filter_date_from = isset( $_GET['dd_date_from'] ) ? sanitize_text_field( $_GET['dd_date_from'] ) : '';
$filter_date_to   = isset( $_GET['dd_date_to'] )   ? sanitize_text_field( $_GET['dd_date_to'] ) : '';
$filter_payment   = isset( $_GET['dd_payment'] )   ? sanitize_key( $_GET['dd_payment'] ) : '';

// ── Summary stats ─────────────────────────────────────────────────────────────
$total_orders  = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM `{$ot}` WHERE is_test = 0" );
$total_revenue = (float) $wpdb->get_var( "SELECT COALESCE(SUM(total),0) FROM `{$ot}` WHERE is_test = 0" );
$total_pending = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM `{$ot}` WHERE status IN ('pending','processing') AND is_test = 0" );
$total_today   = (int)   $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$ot}` WHERE DATE(created_at) = %s",
    current_time( 'Y-m-d' )
) );

// ── Orders query ──────────────────────────────────────────────────────────────
$where_clauses = [];
$where_values  = [];

// Test / non-test base filter (always first — ensures $where_clauses is never empty)
if ( $status_filter === 'test' ) {
    $where_clauses[] = 'is_test = 1';
} else {
    $where_clauses[] = 'is_test = 0';
    if ( $status_filter !== 'all' ) {
        $where_clauses[] = 'status = %s';
        $where_values[]  = $status_filter;
    }
}

// Search: order number, customer name, customer phone
if ( $search_query !== '' ) {
    $like            = '%' . $wpdb->esc_like( $search_query ) . '%';
    $where_clauses[] = 'order_number LIKE %s';
    $where_values[]  = $like;
}

// Date from
if ( $filter_date_from !== '' ) {
    $where_clauses[] = 'created_at >= %s';
    $where_values[]  = $filter_date_from . ' 00:00:00';
}

// Date to
if ( $filter_date_to !== '' ) {
    $where_clauses[] = 'created_at <= %s';
    $where_values[]  = $filter_date_to . ' 23:59:59';
}

// Payment method
if ( $filter_payment !== '' ) {
    $where_clauses[] = 'payment_method = %s';
    $where_values[]  = $filter_payment;
}

$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );

$count_sql              = "SELECT COUNT(*) FROM `{$ot}` {$where_sql}";
$list_sql               = "SELECT * FROM `{$ot}` {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
$where_values_paginated = array_merge( $where_values, [ $per_page, $offset ] );

$paginated_total = ! empty( $where_values )
    ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $where_values ) )
    : (int) $wpdb->get_var( $count_sql );

$orders = $wpdb->get_results( $wpdb->prepare( $list_sql, $where_values_paginated ), ARRAY_A );

$total_pages = ( $paginated_total > 0 && $per_page > 0 ) ? (int) ceil( $paginated_total / $per_page ) : 1;

// ── Per-status counts for filter tabs ─────────────────────────────────────────
$status_counts = [];
$counts_raw = $wpdb->get_results(
    "SELECT status, COUNT(*) as cnt FROM `{$ot}` WHERE is_test = 0 GROUP BY status",
    ARRAY_A
);
foreach ( $counts_raw as $row ) {
    $status_counts[ $row['status'] ] = (int) $row['cnt'];
}
$test_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$ot}` WHERE is_test = 1" );

// ── Payment methods for filter dropdown ───────────────────────────────────────
$payment_methods = $wpdb->get_col(
    "SELECT DISTINCT payment_method FROM `{$ot}` WHERE is_test = 0 AND payment_method != '' ORDER BY payment_method ASC"
);

// ── Base query args for pagination links (preserves active filters) ───────────
$base_query_args = [ 'page' => 'dish-dash-orders', 'status' => $status_filter ];
if ( $search_query     !== '' ) $base_query_args['dd_search']    = $search_query;
if ( $filter_date_from !== '' ) $base_query_args['dd_date_from'] = $filter_date_from;
if ( $filter_date_to   !== '' ) $base_query_args['dd_date_to']   = $filter_date_to;
if ( $filter_payment   !== '' ) $base_query_args['dd_payment']   = $filter_payment;
if ( $per_page         !== 25 ) $base_query_args['per_page']     = $per_page;

// ── Helpers ───────────────────────────────────────────────────────────────────
function dd_orders_status_badge( $status ) {
    $map = [
        'pending'   => [ 'Pending',   '#fef9c3', '#854d0e' ],
        'confirmed' => [ 'Confirmed', '#dbeafe', '#1e40af' ],
        'ready'     => [ 'Ready',     '#dcfce7', '#166534' ],
        'delivered' => [ 'Delivered', '#dcfce7', '#166534' ],
        'cancelled' => [ 'Cancelled', '#fee2e2', '#991b1b' ],
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
    'all'       => 'All',
    'pending'   => 'Pending',
    'confirmed' => 'Confirmed',
    'ready'     => 'Ready',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled',
    'test'      => 'Test',
];

// Load riders for Ready → Notify Rider buttons
$riders = json_decode( get_option( 'dd_riders', '[]' ), true );
if ( ! is_array( $riders ) ) $riders = [];

// Build per-order WhatsApp URLs for modal
$order_wa_urls = [];
foreach ( $orders as $o ) {
    $kitchen_url  = DD_Notifications::build_kitchen_whatsapp_url( $o );
    $customer_url = DD_Notifications::build_customer_ontheway_url( $o );
    $rider_urls   = [];
    foreach ( $riders as $rider ) {
        $url = DD_Notifications::build_rider_whatsapp_url( $o, $rider['whatsapp'] );
        if ( $url ) {
            $rider_urls[] = [
                'name' => $rider['name'],
                'url'  => $url,
            ];
        }
    }
    $order_wa_urls[ $o['id'] ] = [
        'kitchen'  => $kitchen_url,
        'customer' => $customer_url,
        'riders'   => $rider_urls,
    ];
}
?>

<style>
.dd-orders-search-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.dd-search-field {
    position: relative;
    flex: 1;
    min-width: 200px;
}
.dd-search-field input[type="text"] {
    width: 100%;
    padding: 8px 32px 8px 12px;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    font-size: 13px;
    font-family: inherit;
    color: #111;
    background: #fff;
    box-sizing: border-box;
}
.dd-search-field input[type="text"]:focus {
    outline: none;
    border-color: var(--dd-brand);
    box-shadow: 0 0 0 2px var(--dd-brand-light);
}
.dd-search-clear {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: #888;
    font-size: 12px;
}
.dd-filter-group {
    display: flex;
    align-items: center;
    gap: 6px;
}
.dd-filter-group input[type="date"],
.dd-filter-group select {
    padding: 8px 10px;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    font-size: 13px;
    font-family: inherit;
    color: #111;
    background: #fff;
    cursor: pointer;
}
.dd-filter-group select:focus,
.dd-filter-group input[type="date"]:focus {
    outline: none;
    border-color: var(--dd-brand);
}
.dd-filter-clear-all {
    font-size: 12px;
    color: #888;
    text-decoration: none;
    white-space: nowrap;
    padding: 4px 8px;
    border-radius: 4px;
    border: 1px solid #e0e0e0;
}
.dd-filter-clear-all:hover {
    color: #333;
    border-color: #ccc;
}
.dd-reopen-locked {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #888;
    background: #f5f5f5;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 6px 12px;
    cursor: not-allowed;
}
</style>

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
      $count = $key === 'all'  ? $total_orders
             : ( $key === 'test' ? $test_count
             : ( $status_counts[ $key ] ?? 0 ) );
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
  <form method="POST" action="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-orders' ) ); ?>" id="dd-bulk-form">
    <?php wp_nonce_field( 'dd_bulk_orders' ); ?>
    <input type="hidden" name="current_status_filter" value="<?php echo esc_attr( $status_filter ); ?>">
    <input type="hidden" name="dd_bulk_action" id="dd-bulk-action-input" value="">

    <!-- Bulk action bar (hidden until rows selected) -->
    <div class="dd-bulk-bar" id="dd-bulk-bar" style="display:none">
        <span class="dd-bulk-count" id="dd-bulk-count">0 selected</span>
        <select class="dd-bulk-select" id="dd-bulk-select">
            <option value="">Change status to...</option>
            <option value="confirmed">Confirmed</option>
            <option value="ready">Ready</option>
            <option value="delivered">Delivered</option>
            <option value="cancelled">Cancelled</option>
            <option value="mark_test">Mark as Test</option>
            <option value="unmark_test">Remove Test flag</option>
        </select>
        <button type="button" class="dd-bulk-apply" id="dd-bulk-apply">Apply</button>
        <button type="button" class="dd-bulk-clear" id="dd-bulk-clear">Clear</button>
    </div>

    <div class="dd-orders-search-bar" id="dd-search-bar">
        <div class="dd-search-field">
            <input
                type="text"
                id="dd-search-input"
                placeholder="Search by order number (e.g. DD-00092 or 92)…"
                value="<?php echo esc_attr( $search_query ); ?>"
                autocomplete="off"
            >
            <?php if ( $search_query !== '' ) : ?>
                <span class="dd-search-clear" id="dd-search-clear" title="Clear search">✕</span>
            <?php endif; ?>
        </div>

        <div class="dd-filter-group">
            <input
                type="date"
                id="dd-date-from"
                value="<?php echo esc_attr( $filter_date_from ); ?>"
                title="From date"
            >
            <span style="color:#888;font-size:12px">→</span>
            <input
                type="date"
                id="dd-date-to"
                value="<?php echo esc_attr( $filter_date_to ); ?>"
                title="To date"
            >
        </div>

        <div class="dd-filter-group">
            <select id="dd-payment-filter">
                <option value="">All payment methods</option>
                <?php foreach ( $payment_methods as $pm ) : ?>
                    <option value="<?php echo esc_attr( $pm ); ?>"
                        <?php selected( $filter_payment, $pm ); ?>>
                        <?php echo esc_html( ucwords( str_replace( '_', ' ', $pm ) ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ( $search_query !== '' || $filter_date_from !== '' || $filter_date_to !== '' || $filter_payment !== '' ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-orders&status=' . $status_filter ) ); ?>"
               class="dd-filter-clear-all">Clear all filters</a>
        <?php endif; ?>
    </div>

    <div class="dd-table-controls">
        <div class="dd-per-page">
            <span class="dd-label">Show:</span>
            <?php
            $pp_base = add_query_arg( $base_query_args, admin_url( 'admin.php' ) );
            foreach ( $per_page_options as $opt ) :
                $pp_url = add_query_arg( [ 'per_page' => $opt, 'paged' => 1 ], $pp_base );
            ?>
            <a href="<?php echo esc_url( $pp_url ); ?>"
               class="dd-per-page-btn <?php echo $per_page === $opt ? 'active' : ''; ?>">
                <?php echo $opt; ?>
            </a>
            <?php endforeach; ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'per_page' => 99999, 'paged' => 1 ], $pp_base ) ); ?>"
               class="dd-per-page-btn <?php echo $per_page === 99999 ? 'active' : ''; ?>">
                All
            </a>
        </div>
        <div class="dd-table-info">
            <?php
            $from = $paginated_total > 0 ? $offset + 1 : 0;
            $to   = min( $offset + $per_page, $paginated_total );
            echo "Showing {$from}–{$to} of {$paginated_total} orders";
            ?>
        </div>
    </div>

    <div class="dd-orders-card">
    <?php if ( empty( $orders ) ) : ?>
      <p class="dd-orders-empty">No orders found.</p>
    <?php else : ?>
      <table class="dd-orders-table">
        <thead>
          <tr>
            <th class="dd-col-check">
                <input type="checkbox" id="dd-check-all" class="dd-check">
            </th>
            <th>Order</th>
            <th>Customer</th>
            <th>Payment</th>
            <th>Total</th>
            <th>Status</th>
            <th>Date</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ( $orders as $o ) :
            $order_num = ! empty( $o['order_number'] ) ? $o['order_number'] : 'DD-' . str_pad( $o['id'], 5, '0', STR_PAD_LEFT );
          ?>
          <tr data-order-id="<?php echo (int) $o['id']; ?>" style="cursor:pointer" class="dd-order-row">
            <td class="dd-col-check" onclick="event.stopPropagation()">
              <input type="checkbox" name="dd_bulk_order_ids[]"
                value="<?php echo (int) $o['id']; ?>"
                class="dd-row-check dd-check">
            </td>
            <td class="dd-orders-col-id">
              <span class="dd-order-num"><?php echo esc_html( $order_num ); ?></span>
            </td>
            <td class="dd-orders-col-customer">
              <span class="dd-customer-name"><?php echo esc_html( $o['customer_name'] ); ?></span>
              <?php if ( ! empty( $o['customer_phone'] ) ) : ?>
                <span class="dd-customer-phone"><?php echo esc_html( $o['customer_phone'] ); ?></span>
              <?php endif; ?>
            </td>
            <td><?php echo esc_html( dd_format_payment_method( $o['payment_method'] ) ); ?></td>
            <td class="dd-orders-col-total">
              <span class="dd-order-total"><?php echo dd_orders_format_rwf( $o['total'] ); ?></span>
              <span class="dd-order-currency">RWF</span>
            </td>
            <td class="dd-orders-col-status dd-status-badge-cell">
              <?php echo dd_orders_status_badge( $o['status'] ); ?>
              <?php if ( ! empty( $o['is_test'] ) ) : ?>
                <span class="dd-test-badge">Test</span>
              <?php endif; ?>
            </td>
            <td class="dd-orders-col-date">
              <?php echo esc_html( date( 'd M Y H:i', strtotime( $o['created_at'] ) ) ); ?>
            </td>
            <td class="dd-orders-col-view">
              <button type="button" class="dd-btn-view" onclick="event.stopPropagation(); openModal('<?php echo (int) $o['id']; ?>')">View →</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ( $total_pages > 1 ) :
          $paged_base = add_query_arg( $base_query_args, admin_url( 'admin.php' ) );
      ?>
      <div class="dd-pagination">
          <?php if ( $paged > 1 ) : ?>
              <a href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $paged_base ) ); ?>"
                 class="dd-page-btn">← Prev</a>
          <?php endif; ?>

          <?php
          $start = max( 1, $paged - 3 );
          $end   = min( $total_pages, $paged + 3 );
          if ( $start > 1 ) echo '<a href="' . esc_url( add_query_arg( 'paged', 1, $paged_base ) ) . '" class="dd-page-btn">1</a>';
          if ( $start > 2 ) echo '<span class="dd-page-ellipsis">…</span>';
          for ( $i = $start; $i <= $end; $i++ ) :
              $cls = $i === $paged ? 'dd-page-btn active' : 'dd-page-btn';
          ?>
              <a href="<?php echo esc_url( add_query_arg( 'paged', $i, $paged_base ) ); ?>"
                 class="<?php echo $cls; ?>"><?php echo $i; ?></a>
          <?php endfor;
          if ( $end < $total_pages - 1 ) echo '<span class="dd-page-ellipsis">…</span>';
          if ( $end < $total_pages ) echo '<a href="' . esc_url( add_query_arg( 'paged', $total_pages, $paged_base ) ) . '" class="dd-page-btn">' . $total_pages . '</a>';
          ?>

          <?php if ( $paged < $total_pages ) : ?>
              <a href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $paged_base ) ); ?>"
                 class="dd-page-btn">Next →</a>
          <?php endif; ?>
      </div>
      <?php endif; ?>

    <?php endif; ?>
    </div><!-- /.dd-orders-card -->
  </form>

<script>
window.ddOrdersData = {
    ajaxUrl:      <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
    nonce:        <?php echo wp_json_encode( wp_create_nonce( 'dish_dash_frontend' ) ); ?>,
    adminNonce:   <?php echo wp_json_encode( wp_create_nonce( 'dish_dash_admin' ) ); ?>,
    waUrls:       <?php echo wp_json_encode( $order_wa_urls ); ?>,
    kitchenPhone: <?php echo wp_json_encode( get_option( 'dd_whatsapp_kitchen', '' ) ); ?>,
    adminPhone:   <?php echo wp_json_encode( get_option( 'dd_whatsapp_admin', '' ) ); ?>,
    statusLabels: <?php echo wp_json_encode( [
        'pending'   => 'Pending',
        'confirmed' => 'Confirmed',
        'ready'     => 'Ready',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ] ); ?>
};
</script>

<!-- Live Search + Filter JS -->
<script>
( function () {
    'use strict';

    var searchInput   = document.getElementById( 'dd-search-input' );
    var dateFrom      = document.getElementById( 'dd-date-from' );
    var dateTo        = document.getElementById( 'dd-date-to' );
    var paymentFilter = document.getElementById( 'dd-payment-filter' );
    var clearBtn      = document.getElementById( 'dd-search-clear' );
    var debounceTimer = null;

    function buildUrl() {
        var url    = new URL( window.location.href );
        var params = url.searchParams;

        var search = searchInput   ? searchInput.value.trim() : '';
        var from   = dateFrom      ? dateFrom.value           : '';
        var to     = dateTo        ? dateTo.value             : '';
        var pay    = paymentFilter ? paymentFilter.value      : '';

        if ( search ) { params.set( 'dd_search', search ); }
        else           { params.delete( 'dd_search' ); }

        if ( from ) { params.set( 'dd_date_from', from ); }
        else         { params.delete( 'dd_date_from' ); }

        if ( to ) { params.set( 'dd_date_to', to ); }
        else       { params.delete( 'dd_date_to' ); }

        if ( pay ) { params.set( 'dd_payment', pay ); }
        else        { params.delete( 'dd_payment' ); }

        // Reset to page 1 on any filter change
        params.delete( 'paged' );

        return url.toString();
    }

    function applyFilters() {
        window.location.href = buildUrl();
    }

    function debounce( fn, delay ) {
        clearTimeout( debounceTimer );
        debounceTimer = setTimeout( fn, delay );
    }

    // Live search — 350ms debounce
    if ( searchInput ) {
        searchInput.addEventListener( 'input', function () {
            debounce( applyFilters, 800 );
        } );
    }

    // Clear search button
    if ( clearBtn ) {
        clearBtn.addEventListener( 'click', function () {
            if ( searchInput ) { searchInput.value = ''; }
            applyFilters();
        } );
    }

    // Date filters — apply immediately on change
    if ( dateFrom ) { dateFrom.addEventListener( 'change', applyFilters ); }
    if ( dateTo )   { dateTo.addEventListener( 'change', applyFilters ); }

    // Payment filter — apply immediately on change
    if ( paymentFilter ) { paymentFilter.addEventListener( 'change', applyFilters ); }

    // Auto-focus search input on page load if search is active
    if ( searchInput && searchInput.value !== '' ) {
        searchInput.focus();
        var val = searchInput.value;
        searchInput.value = '';
        searchInput.value = val;
    }

}() );
</script>

<!-- Order Detail Modal -->
<div id="dd-order-modal" class="dd-modal-overlay" style="display:none">
    <div class="dd-modal">
        <div class="dd-modal-header">
            <div>
                <span class="dd-modal-order-num"></span>
                <span class="dd-modal-date"></span>
            </div>
            <button class="dd-modal-close" id="dd-modal-close">✕</button>
        </div>
        <div class="dd-modal-body">
            <div class="dd-modal-section">
                <div class="dd-modal-label">CUSTOMER</div>
                <div class="dd-modal-customer-name"></div>
                <div class="dd-modal-customer-phone"></div>
                <div class="dd-modal-customer-address"></div>
            </div>
            <div class="dd-modal-section">
                <div class="dd-modal-label">ORDER ITEMS</div>
                <div class="dd-modal-items"></div>
                <div class="dd-modal-totals"></div>
            </div>
            <div class="dd-modal-section dd-modal-status-section">
                <div class="dd-modal-label">STATUS</div>
                <div class="dd-modal-status-badge"></div>
            </div>
        </div>
        <div class="dd-modal-footer" id="dd-modal-actions"></div>
        <div class="dd-modal-loading" id="dd-modal-loading" style="display:none">
            <span>Updating...</span>
        </div>
    </div>
</div>

<!-- Modal JS -->
<script>
( function () {
    'use strict';

    var modal        = document.getElementById( 'dd-order-modal' );
    var modalActions = document.getElementById( 'dd-modal-actions' );
    var modalLoading = document.getElementById( 'dd-modal-loading' );
    var currentOrderId = null;
    var currentOrder   = null;
    var currentItems   = [];
    var LS_KITCHEN   = 'dd_kitchen_notified_';
    var LS_RIDER     = 'dd_rider_notified_';

    // Open modal on row click
    document.querySelectorAll( '.dd-order-row' ).forEach( function ( row ) {
        row.addEventListener( 'click', function () {
            var id = this.dataset.orderId;
            if ( ! id ) return;
            currentOrderId = id;
            openModal( id );
        } );
    } );

    // Close modal
    document.getElementById( 'dd-modal-close' ).addEventListener( 'click', closeModal );
    modal.addEventListener( 'click', function ( e ) {
        if ( e.target === modal ) closeModal();
    } );
    document.addEventListener( 'keydown', function ( e ) {
        if ( e.key === 'Escape' ) closeModal();
    } );

    function openModal( orderId ) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        fetchOrder( orderId );
    }

    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        currentOrderId = null;
    }

    function fetchOrder( orderId ) {
        setLoading( true );
        var data = new FormData();
        data.append( 'action',   'dd_get_order' );
        data.append( 'order_id', orderId );
        data.append( 'nonce',    window.ddOrdersData.nonce );

        fetch( window.ddOrdersData.ajaxUrl, { method: 'POST', body: data } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                setLoading( false );
                if ( res.success ) {
                    var payload = res.data.data || res.data;
                    renderModal( payload.order, payload.items );
                }
            } )
            .catch( function () { setLoading( false ); } );
    }

    function buildWaUrls( order ) {
        var phone   = ( order.customer_phone || '' ).replace( /\D/g, '' );
        var kitchen = ( window.ddOrdersData.kitchenPhone || '' ).replace( /\D/g, '' );
        var adminWa = ( window.ddOrdersData.adminPhone   || '' ).replace( /\D/g, '' );

        var summary = ( order.order_number || ( 'DD-' + order.id ) )
                    + ' · ' + ( order.customer_name || '' )
                    + ' · ' + Number( order.total ).toLocaleString( 'en-US', { maximumFractionDigits: 0 } ) + ' RWF';

        var kitchenMsg  = encodeURIComponent( '🍳 Kitchen Order\n' + summary );
        var customerMsg = encodeURIComponent( '✅ Your order ' + summary + ' is ready!' );

        return {
            kitchen:  kitchen ? 'https://wa.me/' + kitchen + '?text=' + kitchenMsg  : '',
            customer: phone   ? 'https://wa.me/' + phone   + '?text=' + customerMsg : '',
            admin:    adminWa ? 'https://wa.me/' + adminWa                           : '',
        };
    }

    function renderModal( order, items ) {
        currentOrder = order;
        currentItems = items;
        var id      = order.id;
        var orderNum = order.order_number || ( 'DD-' + String( id ).padStart( 5, '0' ) );
        var date    = new Date( order.created_at ).toLocaleDateString( 'en-GB', { day: '2-digit', month: 'short', year: 'numeric' } );
        var status  = order.status;
        var labels  = window.ddOrdersData.statusLabels;
        var waUrls  = window.ddOrdersData.waUrls[ id ] || buildWaUrls( order );

        // Header
        modal.querySelector( '.dd-modal-order-num' ).textContent = orderNum;
        modal.querySelector( '.dd-modal-date' ).textContent      = date + ' · ' + ucfirst( order.order_type || 'delivery' );

        // Customer
        modal.querySelector( '.dd-modal-customer-name' ).textContent    = order.customer_name || '';
        modal.querySelector( '.dd-modal-customer-phone' ).textContent   = order.customer_phone || '';
        modal.querySelector( '.dd-modal-customer-address' ).textContent = order.delivery_address || '';

        // Items
        var itemsHtml = '';
        var itemTotal = 0;
        items.forEach( function ( item ) {
            var lineTotal = parseFloat( item.line_total ) || 0;
            itemTotal += lineTotal;
            itemsHtml += '<div class="dd-modal-item">'
                + '<span class="dd-modal-item-qty">' + item.quantity + '×</span>'
                + '<span class="dd-modal-item-name">' + item.item_name + '</span>'
                + '<span class="dd-modal-item-price">' + formatRwf( lineTotal ) + '</span>'
                + '</div>';
            ( item.variation_lines || [] ).forEach( function ( vl ) {
                itemsHtml += '<div style="padding-left:16px;color:#777;font-size:12px;margin:-2px 0 4px;">' + vl + '</div>';
            } );
        } );
        modal.querySelector( '.dd-modal-items' ).innerHTML = itemsHtml;

        var method = order.payment_method === 'cod' ? 'Cash on Delivery' : ucfirst( order.payment_method || 'cod' );
        modal.querySelector( '.dd-modal-totals' ).innerHTML =
            '<div class="dd-modal-total-row"><span>Total</span><strong>' + formatRwf( parseFloat( order.total ) ) + ' RWF</strong></div>'
            + '<div class="dd-modal-total-row dd-modal-payment"><span>Payment</span><span>' + method + '</span></div>';

        // Status badge
        modal.querySelector( '.dd-modal-status-badge' ).innerHTML =
            '<span class="dd-modal-status dd-status-' + status + '">' + ( labels[ status ] || ucfirst( status ) ) + '</span>';

        // Action buttons
        var actionsHtml = '';

        if ( status === 'pending' ) {
            actionsHtml += btn( 'confirmed', '✓ Confirm', 'dd-btn-primary', id );
            actionsHtml += btn( 'cancelled', '✗ Cancel', 'dd-btn-cancel', id );
        }

        if ( status === 'confirmed' ) {
            var kitchenNotified = localStorage.getItem( LS_KITCHEN + String( id ) ) === '1';
            if ( waUrls.kitchen ) {
                actionsHtml += '<a href="' + esc( waUrls.kitchen ) + '" target="_blank" class="dd-btn dd-btn-whatsapp dd-modal-notify-kitchen" data-order-id="' + id + '">📲 Notify Kitchen</a>';
            }
            var readyDisabled = ( kitchenNotified || ! waUrls.kitchen ) ? '' : ' disabled';
            actionsHtml += '<button class="dd-btn dd-btn-primary dd-modal-status-btn dd-requires-kitchen" data-status="ready" data-order-id="' + id + '"' + readyDisabled + '>✓ Mark Ready</button>';
            actionsHtml += btn( 'cancelled', '✗ Cancel', 'dd-btn-cancel', id );
        }

        if ( status === 'ready' ) {
            var riderNotified = localStorage.getItem( LS_RIDER + String( id ) ) === '1';
            ( waUrls.riders || [] ).forEach( function ( rider ) {
                actionsHtml += '<a href="' + esc( rider.url ) + '" target="_blank" class="dd-btn dd-btn-whatsapp dd-modal-notify-rider" data-order-id="' + id + '">🛵 ' + rider.name + '</a>';
            } );
            if ( waUrls.customer ) {
                actionsHtml += '<a href="' + esc( waUrls.customer ) + '" target="_blank" class="dd-btn dd-btn-whatsapp">📲 Customer</a>';
            }
            var deliveredDisabled = riderNotified ? '' : ' disabled';
            actionsHtml += '<button class="dd-btn dd-btn-delivered dd-modal-status-btn dd-requires-rider" data-status="delivered" data-order-id="' + id + '"' + deliveredDisabled + '>✓ Delivered</button>';
            actionsHtml += btn( 'cancelled', '✗ Cancel', 'dd-btn-cancel', id );
        }

        if ( status === 'delivered' ) {
            var canReopen = true;
            if ( order.delivered_at ) {
                var deliveredAt = new Date( order.delivered_at.replace( ' ', 'T' ) );
                var hoursSince  = ( Date.now() - deliveredAt.getTime() ) / 1000 / 3600;
                if ( hoursSince > 24 ) {
                    canReopen = false;
                }
            }

            if ( canReopen ) {
                actionsHtml += '<button class="dd-btn dd-btn-reopen dd-modal-status-btn" '
                             + 'data-status="ready" data-order-id="' + id + '">'
                             + '↩ Reopen as Ready</button>';
            } else {
                actionsHtml += '<span class="dd-reopen-locked">'
                             + '🔒 Cannot reopen — delivered over 24h ago'
                             + '</span>';
            }
        }

        if ( status === 'cancelled' ) {
            actionsHtml += '<button class="dd-btn dd-btn-reopen dd-modal-status-btn" '
                         + 'data-status="pending" data-order-id="' + id + '">'
                         + '↩ Reopen as Pending</button>';
        }

        modalActions.innerHTML = actionsHtml;

        // Wire action buttons
        modalActions.querySelectorAll( '.dd-modal-status-btn' ).forEach( function ( b ) {
            b.addEventListener( 'click', function () {
                var newStatus = this.dataset.status;
                var oid      = this.dataset.orderId;
                if ( newStatus === 'cancelled' && ! confirm( 'Cancel this order?' ) ) return;
                updateStatus( oid, newStatus );
            } );
        } );

        // Kitchen notified → unlock Mark Ready
        modalActions.querySelectorAll( '.dd-modal-notify-kitchen' ).forEach( function ( a ) {
            a.addEventListener( 'click', function () {
                var oid = this.dataset.orderId;
                localStorage.setItem( LS_KITCHEN + String( oid ), '1' );
                modalActions.querySelectorAll( '.dd-requires-kitchen' ).forEach( function ( b ) {
                    b.disabled = false;
                } );
            } );
        } );

        // Rider notified → unlock Delivered
        modalActions.querySelectorAll( '.dd-modal-notify-rider' ).forEach( function ( a ) {
            a.addEventListener( 'click', function () {
                var oid = this.dataset.orderId;
                localStorage.setItem( LS_RIDER + String( oid ), '1' );
                modalActions.querySelectorAll( '.dd-requires-rider' ).forEach( function ( b ) {
                    b.disabled = false;
                } );
            } );
        } );
    }

    function updateStatus( orderId, newStatus ) {
        setLoading( true );
        var data = new FormData();
        data.append( 'action',     'dd_update_status' );
        data.append( 'order_id',   orderId );
        data.append( 'status', newStatus );
        data.append( 'nonce',      window.ddOrdersData.adminNonce );

        fetch( window.ddOrdersData.ajaxUrl, { method: 'POST', body: data } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                setLoading( false );
                if ( res.success ) {
                    // Update the row badge in the table without reload
                    var row = document.querySelector( 'tr[data-order-id="' + orderId + '"]' );
                    if ( row ) {
                        var badge = row.querySelector( '.dd-status-badge-cell' );
                        if ( badge ) {
                            badge.innerHTML = renderBadge( newStatus );
                        }
                    }
                    if ( newStatus === 'delivered' || newStatus === 'cancelled' ) {
                        localStorage.removeItem( LS_KITCHEN + orderId );
                        localStorage.removeItem( LS_RIDER + orderId );
                        currentOrder.status = newStatus;
                        renderModal( currentOrder, currentItems );
                        // Reload the background table row without closing modal
                        var row2 = document.querySelector( 'tr[data-order-id="' + orderId + '"]' );
                        if ( row2 ) {
                            var badge2 = row2.querySelector( '.dd-status-badge-cell' );
                            if ( badge2 ) badge2.innerHTML = renderBadge( newStatus );
                        }
                    } else {
                        // Update local order object and re-render without re-fetching
                        currentOrder.status = newStatus;
                        renderModal( currentOrder, currentItems );
                    }
                }
            } )
            .catch( function () { setLoading( false ); } );
    }

    function btn( status, label, cls, orderId ) {
        return '<button class="dd-btn ' + cls + ' dd-modal-status-btn" data-status="' + status + '" data-order-id="' + orderId + '">' + label + '</button>';
    }

    function setLoading( show ) {
        modalLoading.style.display = show ? 'flex' : 'none';
    }

    function ucfirst( s ) {
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
    }

    function formatRwf( n ) {
        return Number( n ).toLocaleString( 'en-US', { maximumFractionDigits: 0 } );
    }

    function esc( s ) {
        return s ? s.replace( /"/g, '&quot;' ) : '';
    }

    function renderBadge( status ) {
        var map = {
            pending:   ['Pending',   '#fef9c3','#854d0e'],
            confirmed: ['Confirmed', '#dbeafe','#1e40af'],
            ready:     ['Ready',     '#dcfce7','#166534'],
            delivered: ['Delivered', '#dcfce7','#166534'],
            cancelled: ['Cancelled', '#fee2e2','#991b1b'],
        };
        var s = map[status] || [status,'#f3f4f6','#374151'];
        return '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:500;background:' + s[1] + ';color:' + s[2] + '">' + s[0] + '</span>';
    }

    window.openModal = openModal;

    // Auto-open modal if open_order param in URL
    ( function () {
        var params   = new URLSearchParams( window.location.search );
        var openId   = params.get( 'open_order' );
        if ( openId && typeof window.openModal === 'function' ) {
            // Small delay to let page fully render
            setTimeout( function () {
                window.openModal( openId );
                // Clean URL without reloading
                var cleanUrl = window.location.href.replace( /[?&]open_order=\d+/, '' )
                    .replace( /\?$/, '' ).replace( /&$/, '' );
                window.history.replaceState( {}, '', cleanUrl );
            }, 300 );
        }
    } )();
} )();
</script>

<script>
( function () {
    var checkAll  = document.getElementById( 'dd-check-all' );
    var bulkBar   = document.getElementById( 'dd-bulk-bar' );
    var bulkCount = document.getElementById( 'dd-bulk-count' );
    var bulkApply = document.getElementById( 'dd-bulk-apply' );
    var bulkClear = document.getElementById( 'dd-bulk-clear' );
    var bulkSel   = document.getElementById( 'dd-bulk-select' );
    var actionInp = document.getElementById( 'dd-bulk-action-input' );
    var form      = document.getElementById( 'dd-bulk-form' );

    function getChecked() {
        return document.querySelectorAll( '.dd-row-check:checked' );
    }

    function updateBar() {
        var checked = getChecked().length;
        bulkBar.style.display = checked > 0 ? 'flex' : 'none';
        bulkCount.textContent = checked + ' order' + ( checked !== 1 ? 's' : '' ) + ' selected';
        if ( checkAll ) checkAll.indeterminate = checked > 0 && checked < document.querySelectorAll( '.dd-row-check' ).length;
        if ( checkAll ) checkAll.checked = checked > 0 && checked === document.querySelectorAll( '.dd-row-check' ).length;
    }

    if ( checkAll ) {
        checkAll.addEventListener( 'change', function () {
            document.querySelectorAll( '.dd-row-check' ).forEach( function ( cb ) {
                cb.checked = checkAll.checked;
            } );
            updateBar();
        } );
    }

    document.querySelectorAll( '.dd-row-check' ).forEach( function ( cb ) {
        cb.addEventListener( 'change', updateBar );
    } );

    if ( bulkApply ) {
        bulkApply.addEventListener( 'click', function () {
            var action = bulkSel.value;
            if ( ! action ) { alert( 'Please select an action.' ); return; }
            if ( getChecked().length === 0 ) { alert( 'Please select at least one order.' ); return; }
            if ( ! confirm( 'Apply "' + bulkSel.options[ bulkSel.selectedIndex ].text + '" to ' + getChecked().length + ' orders?' ) ) return;
            actionInp.value = action;
            form.submit();
        } );
    }

    if ( bulkClear ) {
        bulkClear.addEventListener( 'click', function () {
            document.querySelectorAll( '.dd-row-check' ).forEach( function ( cb ) { cb.checked = false; } );
            if ( checkAll ) checkAll.checked = false;
            updateBar();
        } );
    }
} )();
</script>

</div><!-- /.dd-orders-wrap -->

