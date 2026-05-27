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

    if ( in_array( $new_status, $allowed_statuses, true ) ) {
        $wpdb->update(
            $wpdb->prefix . 'dishdash_orders',
            [ 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $order_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
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
            $allowed = [ 'pending', 'confirmed', 'ready', 'delivered', 'cancelled' ];
            if ( in_array( $action, $allowed, true ) ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE `{$wpdb->prefix}dishdash_orders` SET status = %s, updated_at = NOW() WHERE id IN ({$placeholders})",
                    $action, ...$order_ids
                ) );
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

// ── Summary stats ─────────────────────────────────────────────────────────────
$total_orders  = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM `{$ot}` WHERE is_test = 0" );
$total_revenue = (float) $wpdb->get_var( "SELECT COALESCE(SUM(total),0) FROM `{$ot}` WHERE is_test = 0" );
$total_pending = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM `{$ot}` WHERE status IN ('pending','processing') AND is_test = 0" );
$total_today   = (int)   $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$ot}` WHERE DATE(created_at) = %s",
    current_time( 'Y-m-d' )
) );

// ── Orders query ──────────────────────────────────────────────────────────────
if ( $status_filter === 'test' ) {
    $orders = $wpdb->get_results(
        "SELECT * FROM `{$ot}` WHERE is_test = 1
         ORDER BY FIELD(status,'pending','confirmed','ready','out_for_delivery','delivered','cancelled') ASC, created_at ASC
         LIMIT 100",
        ARRAY_A
    );
} elseif ( $status_filter !== 'all' ) {
    $orders = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM `{$ot}` WHERE status = %s AND is_test = 0
         ORDER BY FIELD(status,'pending','confirmed','ready','out_for_delivery','delivered','cancelled') ASC, created_at ASC
         LIMIT 100",
        $status_filter
    ), ARRAY_A );
} else {
    $orders = $wpdb->get_results(
        "SELECT * FROM `{$ot}` WHERE is_test = 0
         ORDER BY FIELD(status,'pending','confirmed','ready','out_for_delivery','delivered','cancelled') ASC, created_at ASC
         LIMIT 100",
        ARRAY_A
    );
}

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
            <th>Type</th>
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
            <td class="dd-orders-col-type">
              <span class="dd-type-pill"><?php echo esc_html( ucfirst( $o['order_type'] ?? 'delivery' ) ); ?></span>
            </td>
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
              <button type="button" class="dd-btn-view" onclick="event.stopPropagation()">View →</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    </div><!-- /.dd-orders-card -->
  </form>

<script>
window.ddOrdersData = {
    ajaxUrl:   <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
    nonce:      <?php echo wp_json_encode( wp_create_nonce( 'dish_dash_frontend' ) ); ?>,
    adminNonce: <?php echo wp_json_encode( wp_create_nonce( 'dish_dash_admin' ) ); ?>,
    waUrls:    <?php echo wp_json_encode( $order_wa_urls ); ?>,
    statusLabels: <?php echo wp_json_encode( [
        'pending'   => 'Pending',
        'confirmed' => 'Confirmed',
        'ready'     => 'Ready',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ] ); ?>
};
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

    function renderModal( order, items ) {
        var id      = order.id;
        var orderNum = order.order_number || ( 'DD-' + String( id ).padStart( 5, '0' ) );
        var date    = new Date( order.created_at ).toLocaleDateString( 'en-GB', { day: '2-digit', month: 'short', year: 'numeric' } );
        var status  = order.status;
        var labels  = window.ddOrdersData.statusLabels;
        var waUrls  = window.ddOrdersData.waUrls[ id ] || {};

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
            var readyDisabled = kitchenNotified ? '' : ' disabled';
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
                        closeModal();
                        window.location.reload();
                    } else {
                        // Re-fetch and re-render modal with new status
                        fetchOrder( currentOrderId );
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

