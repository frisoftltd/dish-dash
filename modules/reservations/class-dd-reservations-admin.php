<?php
/**
 * DD_Reservations_Admin
 * WP Admin → Dish Dash → Reservations
 *
 * @package DishDash
 * @since   3.2.91
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Reservations_Admin {

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_submenu' ] );
    }

    public function register_submenu(): void {
        add_submenu_page(
            'dish-dash',
            __( 'Reservations', 'dish-dash' ),
            __( 'Reservations', 'dish-dash' ),
            'manage_options',
            'dd-reservations',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_reservations';

        // ── Status update action ──────────────────────────────────────────
        if (
            isset( $_POST['dd_res_action'], $_POST['dd_res_id'], $_POST['_wpnonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dd_res_status' )
        ) {
            $allowed = [ 'pending', 'confirmed', 'cancelled', 'no_show' ];
            $new_status = sanitize_text_field( wp_unslash( $_POST['dd_res_action'] ) );
            $res_id     = intval( $_POST['dd_res_id'] );
            if ( in_array( $new_status, $allowed, true ) && $res_id > 0 ) {
                $wpdb->update( $table, [ 'status' => $new_status ], [ 'id' => $res_id ], [ '%s' ], [ '%d' ] );
            }
        }

        // ── Filters ───────────────────────────────────────────────────────
        $filter_status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        $filter_date   = isset( $_GET['res_date'] ) ? sanitize_text_field( wp_unslash( $_GET['res_date'] ) ) : '';
        $search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

        $where  = '1=1';
        $params = [];

        if ( $filter_status ) {
            $where   .= ' AND status = %s';
            $params[] = $filter_status;
        }
        if ( $filter_date ) {
            $where   .= ' AND date = %s';
            $params[] = $filter_date;
        }
        if ( $search ) {
            $where   .= ' AND (name LIKE %s OR whatsapp LIKE %s OR booking_ref LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY date DESC, time DESC LIMIT 200";
        $rows = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) )
            : $wpdb->get_results( $sql );

        // ── Counts for filter tabs ────────────────────────────────────────
        $counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as n FROM {$table} GROUP BY status",
            OBJECT_K
        );
        $total = array_sum( array_column( (array) $counts, 'n' ) );

        $status_labels = [
            ''           => 'All (' . $total . ')',
            'pending'    => 'Pending ('    . ( $counts['pending']->n    ?? 0 ) . ')',
            'confirmed'  => 'Confirmed ('  . ( $counts['confirmed']->n  ?? 0 ) . ')',
            'cancelled'  => 'Cancelled ('  . ( $counts['cancelled']->n  ?? 0 ) . ')',
            'no_show'    => 'No-show ('    . ( $counts['no_show']->n    ?? 0 ) . ')',
        ];

        $base_url = admin_url( 'admin.php?page=dd-reservations' );
        ?>
        <div class="wrap">
            <h1>Reservations</h1>

            <?php /* Filter tabs */ ?>
            <ul class="subsubsub">
                <?php foreach ( $status_labels as $val => $label ) : ?>
                    <li>
                        <a href="<?php echo esc_url( $base_url . ( $val ? '&status=' . $val : '' ) ); ?>"
                           <?php echo $filter_status === $val ? 'style="font-weight:700"' : ''; ?>>
                            <?php echo esc_html( $label ); ?>
                        </a>
                        <?php echo $val !== array_key_last( $status_labels ) ? ' | ' : ''; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php /* Search + date filter */ ?>
            <form method="get" style="margin:12px 0; display:flex; gap:8px; flex-wrap:wrap;">
                <input type="hidden" name="page" value="dd-reservations">
                <?php if ( $filter_status ) : ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr( $filter_status ); ?>">
                <?php endif; ?>
                <input type="text" name="s" placeholder="Name, WhatsApp or Ref…"
                       value="<?php echo esc_attr( $search ); ?>" style="width:220px">
                <input type="date" name="res_date" value="<?php echo esc_attr( $filter_date ); ?>">
                <button type="submit" class="button">Filter</button>
                <?php if ( $search || $filter_date ) : ?>
                    <a href="<?php echo esc_url( $base_url . ( $filter_status ? '&status=' . $filter_status : '' ) ); ?>"
                       class="button">Clear</a>
                <?php endif; ?>
            </form>

            <?php /* Table */ ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:140px">Ref</th>
                        <th style="width:80px">Date</th>
                        <th style="width:60px">Time</th>
                        <th style="width:60px">Session</th>
                        <th style="width:70px">Guests</th>
                        <th>Name</th>
                        <th style="width:130px">WhatsApp</th>
                        <th>Special Requests</th>
                        <th style="width:90px">Status</th>
                        <th style="width:180px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="10">No reservations found.</td></tr>
                    <?php else : foreach ( $rows as $r ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $r->booking_ref ); ?></code></td>
                            <td><?php echo esc_html( $r->date ); ?></td>
                            <td><?php echo esc_html( $r->time ); ?></td>
                            <td><?php echo esc_html( ucfirst( $r->session ) ); ?></td>
                            <td><?php echo esc_html( $r->guests ); ?></td>
                            <td><?php echo esc_html( $r->name ); ?></td>
                            <td>
                                <?php
                                $wa_num = preg_replace( '/\D/', '', $r->whatsapp );
                                printf(
                                    '<a href="https://wa.me/%s" target="_blank">%s</a>',
                                    esc_attr( $wa_num ),
                                    esc_html( $r->whatsapp )
                                );
                                ?>
                            </td>
                            <td><?php echo esc_html( $r->special_requests ?? '' ); ?></td>
                            <td>
                                <?php
                                $badge_colors = [
                                    'pending'   => '#b45309',
                                    'confirmed' => '#15803d',
                                    'cancelled' => '#6b7280',
                                    'no_show'   => '#dc2626',
                                ];
                                $color = $badge_colors[ $r->status ] ?? '#6b7280';
                                printf(
                                    '<span style="color:%s;font-weight:600">%s</span>',
                                    esc_attr( $color ),
                                    esc_html( ucfirst( str_replace( '_', ' ', $r->status ) ) )
                                );
                                ?>
                            </td>
                            <td>
                                <?php
                                $cust_wa     = preg_replace( '/\D/', '', $r->whatsapp );
                                $restaurant  = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );
                                $admin_phone = get_option( 'dish_dash_phone', '' );
                                $date_fmt    = date( 'D, d M Y', strtotime( $r->date ) );
                                $guest_word  = ( (int) $r->guests === 1 ? 'guest' : 'guests' );

                                $msg = '';

                                if ( $r->status === 'confirmed' ) {
                                    $msg  = "*Reservation Confirmed* ✅\n";
                                    $msg .= "_{$restaurant}_\n\n";
                                    $msg .= "Hi {$r->name}, your table is booked! 🎉\n\n";
                                    $msg .= "📋 Ref: *{$r->booking_ref}*\n";
                                    $msg .= "📅 {$date_fmt}\n";
                                    $msg .= "🕐 {$r->time} · " . ucfirst( $r->session ) . "\n";
                                    $msg .= "👥 {$r->guests} {$guest_word}\n\n";
                                    $msg .= "We look forward to welcoming you! 🍽️";
                                    if ( $admin_phone ) {
                                        $msg .= "\n\nNeed to change anything? Call us: {$admin_phone}";
                                    }

                                } elseif ( $r->status === 'cancelled' ) {
                                    $msg  = "*Reservation Cancelled* ❌\n";
                                    $msg .= "_{$restaurant}_\n\n";
                                    $msg .= "Hi {$r->name}, your reservation has been cancelled.\n\n";
                                    $msg .= "📋 Ref: *{$r->booking_ref}*\n";
                                    $msg .= "📅 {$date_fmt}\n";
                                    $msg .= "🕐 {$r->time} · " . ucfirst( $r->session ) . "\n\n";
                                    $msg .= "We're sorry for any inconvenience. We'd love to host you another time — just book again whenever you're ready. 🙏";
                                    if ( $admin_phone ) {
                                        $msg .= "\n\nQuestions? Call us: {$admin_phone}";
                                    }

                                } elseif ( $r->status === 'no_show' ) {
                                    $msg  = "*We Missed You* 😔\n";
                                    $msg .= "_{$restaurant}_\n\n";
                                    $msg .= "Hi {$r->name}, we had your table ready but didn't see you.\n\n";
                                    $msg .= "📋 Ref: *{$r->booking_ref}*\n";
                                    $msg .= "📅 {$date_fmt}\n";
                                    $msg .= "🕐 {$r->time} · " . ucfirst( $r->session ) . "\n\n";
                                    $msg .= "We hope everything is okay. You're always welcome — book again anytime and we'll be glad to have you. 🍽️";
                                    if ( $admin_phone ) {
                                        $msg .= "\n\nCall us: {$admin_phone}";
                                    }
                                }

                                if ( $msg ) :
                                    $wa_link = 'https://wa.me/' . $cust_wa . '?text=' . rawurlencode( $msg );
                                    $btn_labels = [
                                        'confirmed' => '💬 Send Confirmation',
                                        'cancelled' => '💬 Send Cancellation',
                                        'no_show'   => '💬 Send Follow-up',
                                    ];
                                    $btn_label = $btn_labels[ $r->status ] ?? '💬 Notify Customer';
                                ?>
                                <a href="<?php echo esc_url( $wa_link ); ?>"
                                   target="_blank"
                                   class="button button-small"
                                   style="background:#25D366;color:#fff;border-color:#25D366;margin-bottom:4px;display:inline-block">
                                   <?php echo esc_html( $btn_label ); ?>
                                </a>
                                <br>
                                <?php endif; ?>
                                <?php
                                $actions = array_diff(
                                    [ 'confirmed', 'cancelled', 'no_show', 'pending' ],
                                    [ $r->status ]
                                );
                                foreach ( $actions as $action ) :
                                    $label = ucfirst( str_replace( '_', '-', $action ) );
                                ?>
                                    <form method="post" style="display:inline">
                                        <?php wp_nonce_field( 'dd_res_status' ); ?>
                                        <input type="hidden" name="dd_res_id" value="<?php echo esc_attr( $r->id ); ?>">
                                        <input type="hidden" name="dd_res_action" value="<?php echo esc_attr( $action ); ?>">
                                        <button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
                                    </form>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
