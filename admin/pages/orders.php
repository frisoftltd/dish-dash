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

// ── Status filter ─────────────────────────────────────────────────────────────
$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
$allowed = [ 'all', 'pending', 'confirmed', 'ready', 'delivered', 'cancelled' ];
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
];

// Load riders for Ready → Notify Rider buttons
$riders = json_decode( get_option( 'dd_riders', '[]' ), true );
if ( ! is_array( $riders ) ) $riders = [];
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
          <tr data-order-id="<?php echo (int) $o['id']; ?>">
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
              <?php
              $status = $o['status'];
              $id     = (int) $o['id'];

              if ( in_array( $status, [ 'delivered', 'cancelled' ], true ) ) : ?>

                <span class="dd-action-done">—</span>

              <?php elseif ( $status === 'pending' ) : ?>

                <div class="dd-action-row">
                  <form method="POST" style="display:inline">
                    <?php wp_nonce_field( 'dd_order_status_' . $id ); ?>
                    <input type="hidden" name="dd_update_order_status" value="1">
                    <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="new_status" value="confirmed">
                    <input type="hidden" name="current_status_filter" value="<?php echo esc_attr( $status_filter ); ?>">
                    <button type="submit" class="dd-btn dd-btn-primary">✓ Confirm</button>
                  </form>
                  <form method="POST" style="display:inline">
                    <?php wp_nonce_field( 'dd_order_status_' . $id ); ?>
                    <input type="hidden" name="dd_update_order_status" value="1">
                    <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="new_status" value="cancelled">
                    <input type="hidden" name="current_status_filter" value="<?php echo esc_attr( $status_filter ); ?>">
                    <button type="submit" class="dd-btn dd-btn-cancel" onclick="return confirm('Cancel this order?')">✗ Cancel</button>
                  </form>
                </div>

              <?php elseif ( $status === 'confirmed' ) :
                $kitchen_url = DD_Notifications::build_kitchen_whatsapp_url( $o );
              ?>

                <div class="dd-action-row">
                  <?php if ( $kitchen_url ) : ?>
                  <a href="<?php echo esc_attr( $kitchen_url ); ?>"
                     target="_blank"
                     class="dd-btn dd-btn-whatsapp dd-notify-kitchen"
                     data-order-id="<?php echo (int) $o['id']; ?>">
                    📲 Notify Kitchen
                  </a>
                  <?php endif; ?>
                  <form method="POST" style="display:inline">
                    <?php wp_nonce_field( 'dd_order_status_' . $id ); ?>
                    <input type="hidden" name="dd_update_order_status" value="1">
                    <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="new_status" value="ready">
                    <input type="hidden" name="current_status_filter" value="<?php echo esc_attr( $status_filter ); ?>">
                    <button type="submit"
                            class="dd-btn dd-btn-primary dd-requires-kitchen"
                            data-order-id="<?php echo (int) $o['id']; ?>"
                            disabled>✓ Mark Ready</button>
                  </form>
                  <form method="POST" style="display:inline">
                    <?php wp_nonce_field( 'dd_order_status_' . $id ); ?>
                    <input type="hidden" name="dd_update_order_status" value="1">
                    <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="new_status" value="cancelled">
                    <input type="hidden" name="current_status_filter" value="<?php echo esc_attr( $status_filter ); ?>">
                    <button type="submit" class="dd-btn dd-btn-cancel" onclick="return confirm('Cancel this order?')">✗ Cancel</button>
                  </form>
                </div>

              <?php elseif ( $status === 'ready' ) :
                $customer_url = DD_Notifications::build_customer_ontheway_url( $o );
              ?>

                <div class="dd-action-row">
                  <?php if ( ! empty( $riders ) ) :
                    foreach ( $riders as $rider ) :
                      $rider_url = DD_Notifications::build_rider_whatsapp_url( $o, $rider['whatsapp'] );
                      if ( ! $rider_url ) continue;
                  ?>
                    <a href="<?php echo esc_attr( $rider_url ); ?>"
                       target="_blank"
                       class="dd-btn dd-btn-whatsapp dd-notify-rider"
                       data-order-id="<?php echo (int) $o['id']; ?>">
                      🛵 <?php echo esc_html( $rider['name'] ); ?>
                    </a>
                  <?php endforeach; endif; ?>
                  <?php if ( $customer_url ) : ?>
                  <a href="<?php echo esc_attr( $customer_url ); ?>" target="_blank" class="dd-btn dd-btn-whatsapp">
                    📲 Customer
                  </a>
                  <?php endif; ?>
                  <form method="POST" style="display:inline">
                    <?php wp_nonce_field( 'dd_order_status_' . $id ); ?>
                    <input type="hidden" name="dd_update_order_status" value="1">
                    <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="new_status" value="delivered">
                    <input type="hidden" name="current_status_filter" value="<?php echo esc_attr( $status_filter ); ?>">
                    <button type="submit"
                            class="dd-btn dd-btn-delivered dd-requires-rider"
                            data-order-id="<?php echo (int) $o['id']; ?>"
                            disabled>✓ Delivered</button>
                  </form>
                  <form method="POST" style="display:inline">
                    <?php wp_nonce_field( 'dd_order_status_' . $id ); ?>
                    <input type="hidden" name="dd_update_order_status" value="1">
                    <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="new_status" value="cancelled">
                    <input type="hidden" name="current_status_filter" value="<?php echo esc_attr( $status_filter ); ?>">
                    <button type="submit" class="dd-btn dd-btn-cancel" onclick="return confirm('Cancel this order?')">✗ Cancel</button>
                  </form>
                </div>

              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div><!-- /.dd-orders-wrap -->

<script>
( function () {
    var LS_KITCHEN = 'dd_kitchen_notified_';
    var LS_RIDER   = 'dd_rider_notified_';

    // On page load — restore enabled state from localStorage
    document.querySelectorAll( '.dd-requires-kitchen' ).forEach( function ( btn ) {
        var id = btn.dataset.orderId;
        if ( localStorage.getItem( LS_KITCHEN + id ) === '1' ) {
            btn.disabled = false;
        }
    } );

    document.querySelectorAll( '.dd-requires-rider' ).forEach( function ( btn ) {
        var id = btn.dataset.orderId;
        if ( localStorage.getItem( LS_RIDER + id ) === '1' ) {
            btn.disabled = false;
        }
    } );

    // Notify Kitchen clicked — enable Mark Ready for this order
    document.querySelectorAll( '.dd-notify-kitchen' ).forEach( function ( link ) {
        link.addEventListener( 'click', function () {
            var id = this.dataset.orderId;
            localStorage.setItem( LS_KITCHEN + id, '1' );
            document.querySelectorAll(
                '.dd-requires-kitchen[data-order-id="' + id + '"]'
            ).forEach( function ( btn ) {
                btn.disabled = false;
            } );
        } );
    } );

    // Notify Rider clicked — enable Mark Delivered for this order
    document.querySelectorAll( '.dd-notify-rider' ).forEach( function ( link ) {
        link.addEventListener( 'click', function () {
            var id = this.dataset.orderId;
            localStorage.setItem( LS_RIDER + id, '1' );
            document.querySelectorAll(
                '.dd-requires-rider[data-order-id="' + id + '"]'
            ).forEach( function ( btn ) {
                btn.disabled = false;
            } );
        } );
    } );

    // Clean up localStorage when order is marked delivered or cancelled
    document.querySelectorAll( '.dd-btn-delivered, .dd-btn-cancel' ).forEach( function ( btn ) {
        btn.addEventListener( 'click', function () {
            var id = this.dataset.orderId;
            if ( id ) {
                localStorage.removeItem( LS_KITCHEN + id );
                localStorage.removeItem( LS_RIDER + id );
            }
        } );
    } );
} )();
</script>

