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

        // Pagination
        $per_page_raw = isset( $_GET['per_page'] ) ? sanitize_text_field( wp_unslash( $_GET['per_page'] ) ) : '25';
        $per_page     = in_array( $per_page_raw, [ '25', '50', '75', 'all' ], true ) ? $per_page_raw : '25';
        $current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;

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

        // Total matching rows (for pagination)
        $count_sql  = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total_rows = $params
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
            : (int) $wpdb->get_var( $count_sql );

        // Build the page query
        $order_sql = " ORDER BY created_at DESC, id DESC";

        if ( $per_page === 'all' ) {
            $sql          = "SELECT * FROM {$table} WHERE {$where}{$order_sql}";
            $query_params = $params;
        } else {
            $pp           = (int) $per_page;
            $offset       = ( $current_page - 1 ) * $pp;
            $sql          = "SELECT * FROM {$table} WHERE {$where}{$order_sql} LIMIT %d OFFSET %d";
            $query_params = array_merge( $params, [ $pp, $offset ] );
        }

        $rows = ! empty( $query_params )
            ? $wpdb->get_results( $wpdb->prepare( $sql, $query_params ) )
            : $wpdb->get_results( $sql );

        // Row number offset for display
        $row_number_start = ( $per_page === 'all' ) ? 1 : ( ( $current_page - 1 ) * (int) $per_page ) + 1;
        $total_pages      = ( $per_page === 'all' || $total_rows === 0 ) ? 1 : (int) ceil( $total_rows / (int) $per_page );

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
                        <th style="width:40px">#</th>
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
                        <tr><td colspan="11">No reservations found.</td></tr>
                    <?php else : $row_num = $row_number_start; foreach ( $rows as $r ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row_num ); $row_num++; ?></td>
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
                                $session_fmt = ucfirst( $r->session );

                                $lines = [];

                                if ( $r->status === 'confirmed' ) {
                                    $lines[] = 'RESERVATION CONFIRMED ✅';
                                    $lines[] = $restaurant;
                                    $lines[] = '';
                                    $lines[] = "Hi {$r->name}, your table is booked! 🎉";
                                    $lines[] = '';
                                    $lines[] = "Ref: {$r->booking_ref}";
                                    $lines[] = "Date: {$date_fmt}";
                                    $lines[] = "Time: {$r->time} ({$session_fmt})";
                                    $lines[] = "Guests: {$r->guests} {$guest_word}";
                                    $lines[] = '';
                                    $lines[] = 'We look forward to welcoming you! 🍽️';
                                    if ( $admin_phone ) {
                                        $lines[] = '';
                                        $lines[] = "Need to change anything? Call us: {$admin_phone}";
                                    }

                                } elseif ( $r->status === 'cancelled' ) {
                                    $lines[] = 'RESERVATION CANCELLED ❌';
                                    $lines[] = $restaurant;
                                    $lines[] = '';
                                    $lines[] = "Hi {$r->name}, your reservation has been cancelled.";
                                    $lines[] = '';
                                    $lines[] = "Ref: {$r->booking_ref}";
                                    $lines[] = "Date: {$date_fmt}";
                                    $lines[] = "Time: {$r->time} ({$session_fmt})";
                                    $lines[] = '';
                                    $lines[] = "We're sorry for any inconvenience.";
                                    $lines[] = "We'd love to host you another time — book again whenever you're ready. 🙏";
                                    if ( $admin_phone ) {
                                        $lines[] = '';
                                        $lines[] = "Questions? Call us: {$admin_phone}";
                                    }

                                } elseif ( $r->status === 'no_show' ) {
                                    $lines[] = 'WE MISSED YOU 😔';
                                    $lines[] = $restaurant;
                                    $lines[] = '';
                                    $lines[] = "Hi {$r->name}, we had your table ready but didn't see you.";
                                    $lines[] = '';
                                    $lines[] = "Ref: {$r->booking_ref}";
                                    $lines[] = "Date: {$date_fmt}";
                                    $lines[] = "Time: {$r->time} ({$session_fmt})";
                                    $lines[] = '';
                                    $lines[] = 'We hope everything is okay.';
                                    $lines[] = "You're always welcome — book again anytime. 🍽️";
                                    if ( $admin_phone ) {
                                        $lines[] = '';
                                        $lines[] = "Call us: {$admin_phone}";
                                    }
                                }

                                if ( ! empty( $lines ) ) :
                                    $msg     = implode( "\n", $lines );
                                    $wa_link = 'https://wa.me/' . $cust_wa . '?text=' . rawurlencode( $msg );
                                    $btn_labels = [
                                        'confirmed' => '💬 Send Confirmation',
                                        'cancelled' => '💬 Send Cancellation',
                                        'no_show'   => '💬 Send Follow-up',
                                    ];
                                    $btn_label = $btn_labels[ $r->status ] ?? '💬 Notify Customer';
                                ?>
                                <a href="<?php echo esc_attr( $wa_link ); ?>"
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

            <?php
            // Preserve current filters in pagination links
            $pagination_base_args = [ 'page' => 'dd-reservations' ];
            if ( $filter_status ) { $pagination_base_args['status']   = $filter_status; }
            if ( $filter_date )   { $pagination_base_args['res_date'] = $filter_date; }
            if ( $search )        { $pagination_base_args['s']        = $search; }
            $pagination_base_args['per_page'] = $per_page;
            ?>
            <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">

                <div style="color:#50575e;font-size:13px;">
                    <?php
                    if ( $total_rows === 0 ) {
                        echo 'No reservations';
                    } elseif ( $per_page === 'all' ) {
                        echo 'Showing all ' . esc_html( $total_rows ) . ' reservations';
                    } else {
                        $showing_to = min( $row_number_start + count( $rows ) - 1, $total_rows );
                        echo 'Showing ' . esc_html( $row_number_start ) . '–' . esc_html( $showing_to )
                            . ' of ' . esc_html( $total_rows );
                    }
                    ?>
                </div>

                <div style="display:flex;align-items:center;gap:14px;">
                    <?php /* Per-page selector */ ?>
                    <form method="get" style="display:flex;align-items:center;gap:6px;margin:0;">
                        <input type="hidden" name="page" value="dd-reservations">
                        <?php if ( $filter_status ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $filter_status ); ?>"><?php endif; ?>
                        <?php if ( $filter_date ) : ?><input type="hidden" name="res_date" value="<?php echo esc_attr( $filter_date ); ?>"><?php endif; ?>
                        <?php if ( $search ) : ?><input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>"><?php endif; ?>
                        <label for="dd-per-page" style="font-size:13px;color:#50575e;">Per page:</label>
                        <select name="per_page" id="dd-per-page" onchange="this.form.submit()">
                            <?php foreach ( [ '25', '50', '75', 'all' ] as $opt ) : ?>
                                <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $per_page, $opt ); ?>>
                                    <?php echo $opt === 'all' ? 'All' : esc_html( $opt ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <?php /* Page navigation */ ?>
                    <?php if ( $per_page !== 'all' && $total_pages > 1 ) : ?>
                        <div style="display:flex;align-items:center;gap:4px;">
                            <?php
                            if ( $current_page > 1 ) {
                                $prev_url = add_query_arg( array_merge( $pagination_base_args, [ 'paged' => $current_page - 1 ] ), admin_url( 'admin.php' ) );
                                echo '<a class="button button-small" href="' . esc_url( $prev_url ) . '">‹ Prev</a>';
                            } else {
                                echo '<span class="button button-small" style="opacity:.5;pointer-events:none;">‹ Prev</span>';
                            }
                            ?>
                            <span style="font-size:13px;color:#50575e;padding:0 8px;">
                                Page <?php echo esc_html( $current_page ); ?> of <?php echo esc_html( $total_pages ); ?>
                            </span>
                            <?php
                            if ( $current_page < $total_pages ) {
                                $next_url = add_query_arg( array_merge( $pagination_base_args, [ 'paged' => $current_page + 1 ] ), admin_url( 'admin.php' ) );
                                echo '<a class="button button-small" href="' . esc_url( $next_url ) . '">Next ›</a>';
                            } else {
                                echo '<span class="button button-small" style="opacity:.5;pointer-events:none;">Next ›</span>';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php
    }
}
