<?php
/**
 * File: templates/orders/track.php
 * Purpose: Customer-facing order-status timeline (logged-in only, v3.10.30).
 *
 * Rendered by DD_Orders_Module::shortcode_track() via [dish_dash_track].
 * Server-renders the timeline on first paint; assets/js/order-tracking.js
 * then polls dd_get_order every 30s and re-renders authoritatively until the
 * status is terminal (delivered/cancelled).
 *
 * Provided vars (from get_template):
 *   string      $state           'guest' | 'notfound' | 'empty' | 'list' | 'ok'
 *   object|null $order            dishdash_orders row when $state === 'ok'
 *   array       $orders           active-order rows when $state === 'list' (R2)
 *   int         $current_user_id  logged-in user id — a list row is clickable only
 *                                 when its customer_id matches (R2 fix, v3.10.46)
 *   string      $account_url      my-account / login URL
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$state           = isset( $state ) ? $state : 'empty';
$orders          = isset( $orders ) && is_array( $orders ) ? $orders : [];
$current_user_id = isset( $current_user_id ) ? (int) $current_user_id : get_current_user_id();
$account_url     = isset( $account_url ) ? $account_url : home_url( '/my-account/' );

/**
 * Timeline steps. There is no `pending_at` column — `created_at` is the
 * "Placed" timestamp (order exists = placed/pending).
 */
$dd_track_steps = [
    'placed'    => [ 'label' => __( 'Placed', 'dish-dash' ),    'stamp' => 'created_at' ],
    'confirmed' => [ 'label' => __( 'Confirmed', 'dish-dash' ), 'stamp' => 'confirmed_at' ],
    'ready'     => [ 'label' => __( 'Ready', 'dish-dash' ),     'stamp' => 'ready_at' ],
    'delivered' => [ 'label' => __( 'Delivered', 'dish-dash' ), 'stamp' => 'delivered_at' ],
];

if ( ! function_exists( 'dd_track_fmt_time' ) ) {
    /**
     * Format a DATETIME string for display; empty string on null/invalid.
     */
    function dd_track_fmt_time( $ts ) {
        if ( empty( $ts ) ) return '';
        $t = strtotime( $ts );
        return $t ? date_i18n( 'M j, g:i A', $t ) : '';
    }
}
?>
<div class="dd-track-wrap">

<?php if ( 'guest' === $state ) : ?>

    <div class="dd-track dd-track--message">
        <h2 class="dd-track__title"><?php esc_html_e( 'Track your order', 'dish-dash' ); ?></h2>
        <p class="dd-track__lead"><?php esc_html_e( 'Please log in to track your order.', 'dish-dash' ); ?></p>
        <a class="dd-btn dd-btn--brand" href="<?php echo esc_url( $account_url ); ?>">
            <?php esc_html_e( 'Log in', 'dish-dash' ); ?>
        </a>
    </div>

<?php elseif ( 'notfound' === $state ) : ?>

    <div class="dd-track dd-track--message">
        <h2 class="dd-track__title"><?php esc_html_e( 'Order not found', 'dish-dash' ); ?></h2>
        <p class="dd-track__lead"><?php esc_html_e( 'We couldn’t find that order under your account.', 'dish-dash' ); ?></p>
        <a class="dd-btn dd-btn--brand" href="<?php echo esc_url( $account_url ); ?>">
            <?php esc_html_e( 'View my account', 'dish-dash' ); ?>
        </a>
    </div>

<?php elseif ( 'empty' === $state ) : ?>

    <div class="dd-track dd-track--message">
        <h2 class="dd-track__title"><?php esc_html_e( 'No orders yet', 'dish-dash' ); ?></h2>
        <p class="dd-track__lead"><?php esc_html_e( 'You haven’t placed any orders yet.', 'dish-dash' ); ?></p>
        <a class="dd-btn dd-btn--brand" href="<?php echo esc_url( home_url( '/restaurant-menu/' ) ); ?>">
            <?php esc_html_e( 'Browse the menu', 'dish-dash' ); ?>
        </a>
    </div>

<?php elseif ( 'list' === $state ) : ?>

    <div class="dd-track dd-track--list">
        <div class="dd-track__header">
            <h2 class="dd-track__title"><?php esc_html_e( 'Track your orders', 'dish-dash' ); ?></h2>
        </div>

        <?php if ( empty( $orders ) ) : ?>
            <div class="dd-track__body dd-track__body--empty">
                <p class="dd-track__lead"><?php esc_html_e( 'No active orders.', 'dish-dash' ); ?></p>
                <a class="dd-btn dd-btn--brand" href="<?php echo esc_url( home_url( '/restaurant-menu/' ) ); ?>">
                    <?php esc_html_e( 'Browse the menu', 'dish-dash' ); ?>
                </a>
            </div>
        <?php else : ?>
            <ul class="dd-track__orders">
            <?php foreach ( $orders as $o ) :
                $onum  = $o->order_number ? $o->order_number : ( 'DD-' . str_pad( (string) $o->id, 5, '0', STR_PAD_LEFT ) );
                $label = function_exists( 'dd_order_status_label' ) ? dd_order_status_label( (string) $o->status ) : ucfirst( (string) $o->status );
                $time  = dd_track_fmt_time( $o->created_at );
                // Clickable only for orders the current user OWNS (customer_id match) — the
                // per-order tracker's ownership gate is customer_id-only, so linking a
                // phone-only row would land on "Order not found". Render those inert.
                $owned = isset( $o->customer_id ) && (int) $o->customer_id === $current_user_id;
                $href  = function_exists( 'dd_track_url' )
                    ? add_query_arg( 'order_id', (int) $o->id, dd_track_url() )
                    : add_query_arg( 'order_id', (int) $o->id );
                ?>
                <li class="dd-track__order-row">
                <?php if ( $owned ) : ?>
                    <a class="dd-track__order-link" href="<?php echo esc_url( $href ); ?>">
                        <span class="dd-track__order-num"><?php echo esc_html( $onum ); ?></span>
                        <span class="dd-track__order-status dd-status--<?php echo esc_attr( $o->status ); ?>"><?php echo esc_html( $label ); ?></span>
                        <span class="dd-track__order-time"><?php echo esc_html( $time ); ?></span>
                    </a>
                <?php else : ?>
                    <div class="dd-track__order-link dd-track__order-link--static">
                        <span class="dd-track__order-num"><?php echo esc_html( $onum ); ?></span>
                        <span class="dd-track__order-status dd-status--<?php echo esc_attr( $o->status ); ?>"><?php echo esc_html( $label ); ?></span>
                        <span class="dd-track__order-time"><?php echo esc_html( $time ); ?></span>
                        <span class="dd-track__order-note"><?php esc_html_e( 'In progress', 'dish-dash' ); ?></span>
                    </div>
                <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

<?php else : // 'ok'
    $status     = (string) $order->status;
    $is_cancel  = ( 'cancelled' === $status ) || ! empty( $order->cancelled_at );
    $order_num  = $order->order_number ? $order->order_number : ( 'DD-' . str_pad( (string) $order->id, 5, '0', STR_PAD_LEFT ) );
    ?>
    <div class="dd-track"
         data-order-id="<?php echo esc_attr( (int) $order->id ); ?>"
         data-status="<?php echo esc_attr( $status ); ?>">

        <div class="dd-track__header">
            <h2 class="dd-track__title"><?php esc_html_e( 'Track your order', 'dish-dash' ); ?></h2>
            <span class="dd-track__num"><?php echo esc_html( $order_num ); ?></span>
        </div>

        <div class="dd-track__body">
        <?php if ( $is_cancel ) : ?>
            <div class="dd-track__cancelled">
                <span class="dd-track__cancelled-badge"><?php esc_html_e( 'Cancelled', 'dish-dash' ); ?></span>
                <?php if ( ! empty( $order->cancelled_at ) ) : ?>
                    <span class="dd-track__cancelled-time"><?php echo esc_html( dd_track_fmt_time( $order->cancelled_at ) ); ?></span>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <ol class="dd-track__timeline">
            <?php foreach ( $dd_track_steps as $key => $step ) :
                $stamp   = isset( $order->{$step['stamp']} ) ? $order->{$step['stamp']} : null;
                $done    = ! empty( $stamp );
                $classes = 'dd-track__step ' . ( $done ? 'is-done' : 'is-upcoming' );
                if ( $status === $key ) $classes .= ' is-current';
                ?>
                <li class="<?php echo esc_attr( $classes ); ?>">
                    <span class="dd-track__dot" aria-hidden="true"></span>
                    <span class="dd-track__label"><?php echo esc_html( $step['label'] ); ?></span>
                    <span class="dd-track__time">
                        <?php echo $done ? esc_html( dd_track_fmt_time( $stamp ) ) : esc_html__( 'Pending', 'dish-dash' ); ?>
                    </span>
                </li>
            <?php endforeach; ?>
            </ol>
        <?php endif; ?>
        </div>

    </div>

<?php endif; ?>

</div>
