<?php
/**
 * File:    admin/pages/orders.php
 * Purpose: Renders the Orders admin page — filterable order table with
 *          status tabs, order details columns, and inline AJAX status
 *          update buttons.
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core guard)
 *   - $wpdb global (queries dishdash_orders, dishdash_order_items)
 *   - jQuery (loaded by WordPress core in admin)
 *
 * Dependents (files that need this):
 *   - modules/orders/class-dd-orders-module.php (loaded via render_orders())
 *
 * AJAX actions called (from inline script):
 *   - dd_update_status (admin-only, requires dd_manage_orders capability)
 *
 * DB tables read:
 *   {prefix}dishdash_orders, {prefix}dishdash_order_items
 *
 * Status tabs:
 *   all, pending, confirmed, preparing, ready, out_for_delivery,
 *   delivered, cancelled
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$orders_module = DD_Loader::instance()->get_module( 'DD_Orders_Module' );
$status_filter = sanitize_text_field( $_GET['status'] ?? '' );
$orders        = $orders_module ? $orders_module->get_orders( [ 'status' => $status_filter, 'limit' => 50 ] ) : [];

$statuses = [ '', 'pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery', 'delivered', 'cancelled' ];
?>
<div class="wrap dd-admin-wrap">

    <div class="dd-admin-header">
        <div class="dd-admin-header__logo">
            <span class="dd-logo-icon">📦</span>
            <div>
                <h1><?php esc_html_e( 'Orders', 'dish-dash' ); ?></h1>
                <span class="dd-version"><?php echo esc_html( count( $orders ) ); ?> <?php esc_html_e( 'orders', 'dish-dash' ); ?></span>
            </div>
        </div>
    </div>

    <!-- Status filter tabs -->
    <div class="dd-status-tabs">
        <?php foreach ( $statuses as $s ) : ?>
        <a href="<?php echo esc_url( add_query_arg( 'status', $s, admin_url( 'admin.php?page=dish-dash-orders' ) ) ); ?>"
           class="dd-status-tab <?php echo $status_filter === $s ? 'dd-status-tab--active' : ''; ?>">
            <?php echo $s ? esc_html( dd_order_status_label( $s ) ) : esc_html__( 'All', 'dish-dash' ); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ( empty( $orders ) ) : ?>
    <div class="dd-coming-soon">
        <span>📭</span>
        <h2><?php esc_html_e( 'No orders yet', 'dish-dash' ); ?></h2>
        <p><?php esc_html_e( 'Orders will appear here once customers start placing them.', 'dish-dash' ); ?></p>
    </div>
    <?php else : ?>

    <table class="widefat dd-orders-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Order', 'dish-dash' ); ?></th>
                <th><?php esc_html_e( 'Customer', 'dish-dash' ); ?></th>
                <th><?php esc_html_e( 'Type', 'dish-dash' ); ?></th>
                <th><?php esc_html_e( 'Total', 'dish-dash' ); ?></th>
                <th><?php esc_html_e( 'Status', 'dish-dash' ); ?></th>
                <th><?php esc_html_e( 'Date', 'dish-dash' ); ?></th>
                <th><?php esc_html_e( 'Action', 'dish-dash' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $orders as $order ) :
            $next_statuses = dd_order_status_transitions()[ $order->status ] ?? [];
        ?>
            <tr>
                <td><strong><?php echo esc_html( $order->order_number ); ?></strong></td>
                <td>
                    <?php echo esc_html( $order->customer_name ); ?><br>
                    <small><?php echo esc_html( $order->customer_phone ); ?></small>
                </td>
                <td><span class="dd-type-badge dd-type-badge--<?php echo esc_attr( $order->order_type ); ?>"><?php echo esc_html( ucfirst( $order->order_type ) ); ?></span></td>
                <td><strong><?php echo esc_html( dd_price( (float) $order->total ) ); ?></strong></td>
                <td><span class="dd-status-badge dd-status-badge--<?php echo esc_attr( $order->status ); ?>"><?php echo esc_html( dd_order_status_label( $order->status ) ); ?></span></td>
                <td><?php echo esc_html( date_i18n( 'd M Y H:i', strtotime( $order->created_at ) ) ); ?></td>
                <td>
                    <?php foreach ( $next_statuses as $next ) : ?>
                    <button class="button button-small dd-update-status"
                        data-order="<?php echo esc_attr( $order->id ); ?>"
                        data-status="<?php echo esc_attr( $next ); ?>">
                        → <?php echo esc_html( dd_order_status_label( $next ) ); ?>
                    </button>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php endif; ?>
</div>

<style>
.dd-status-tabs { display:flex; gap:.5rem; margin-bottom:1.5rem; flex-wrap:wrap; }
.dd-status-tab { padding:.4rem 1rem; border:1px solid #ddd; border-radius:999px; text-decoration:none; font-size:.82rem; color:#555; background:#fff; }
.dd-status-tab--active { background:#1E3A5F; color:#fff; border-color:#1E3A5F; }
.dd-orders-table { margin-top:0 !important; }
.dd-orders-table td { vertical-align:middle; padding:10px 12px; }
.dd-status-badge { padding:.25rem .7rem; border-radius:999px; font-size:.75rem; font-weight:700; font-family:sans-serif; display:inline-block; }
.dd-status-badge--pending      { background:#fff3cd; color:#856404; }
.dd-status-badge--confirmed    { background:#cce5ff; color:#004085; }
.dd-status-badge--preparing    { background:#fff3e0; color:#e65100; }
.dd-status-badge--ready        { background:#d4edda; color:#155724; }
.dd-status-badge--out_for_delivery { background:#e2d9f3; color:#4a235a; }
.dd-status-badge--delivered    { background:#d1ecf1; color:#0c5460; }
.dd-status-badge--cancelled    { background:#f8d7da; color:#721c24; }
.dd-type-badge { padding:.2rem .6rem; border-radius:6px; font-size:.75rem; font-weight:600; background:#f0f0f0; color:#333; font-family:sans-serif; }
.dd-update-status { margin:2px !important; }
</style>

<script>
jQuery(function($){
    $(document).on('click', '.dd-update-status', function(){
        var btn = $(this);
        var orderId = btn.data('order');
        var status  = btn.data('status');
        btn.prop('disabled', true).text('Updating...');
        $.post(ajaxurl, {
            action: 'dd_update_status',
            order_id: orderId,
            status: status,
            nonce: dishDashAdmin.nonce
        }, function(res){
            if(res.success){ location.reload(); }
            else { alert(res.data?.message || 'Error'); btn.prop('disabled',false); }
        });
    });
});
</script>
