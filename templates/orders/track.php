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
 *   string      $state        'guest' | 'notfound' | 'empty' | 'ok'
 *   object|null $order         dishdash_orders row when $state === 'ok'
 *   string      $account_url   my-account / login URL
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$state       = isset( $state ) ? $state : 'empty';
$account_url = isset( $account_url ) ? $account_url : home_url( '/my-account/' );

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
