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
        add_action( 'admin_menu',            [ $this, 'register_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
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

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'dd-reservations' ) === false ) return;
        wp_enqueue_style(
            'dd-reservations-admin',
            plugin_dir_url( __FILE__ ) . '../../assets/css/reservations-admin.css',
            [],
            DD_VERSION
        );
    }

    public function render_page(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_reservations';

        // ── Status update action (POST fallback) ──────────────────────────
        if (
            isset( $_POST['dd_res_action'], $_POST['dd_res_id'], $_POST['_wpnonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dd_res_status' )
        ) {
            $allowed    = [ 'pending', 'confirmed', 'cancelled', 'no_show' ];
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

        // ── Pagination ────────────────────────────────────────────────────
        $per_page_raw = isset( $_GET['per_page'] ) ? sanitize_text_field( wp_unslash( $_GET['per_page'] ) ) : '25';
        $per_page     = in_array( $per_page_raw, [ '25', '50', '75', 'all' ], true ) ? $per_page_raw : '25';
        $current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;

        // ── WHERE clause ──────────────────────────────────────────────────
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

        // ── Total matching rows (for pagination) ──────────────────────────
        $count_sql  = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total_rows = $params
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
            : (int) $wpdb->get_var( $count_sql );

        // ── Fetch page ────────────────────────────────────────────────────
        $order_sql = ' ORDER BY created_at DESC, id DESC';

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

        $row_number_start = ( $per_page === 'all' ) ? 1 : ( ( $current_page - 1 ) * (int) $per_page ) + 1;
        $total_pages      = ( $per_page === 'all' || $total_rows === 0 ) ? 1 : (int) ceil( $total_rows / (int) $per_page );

        // ── Counts per status (unfiltered) for KPIs + tabs ───────────────
        $counts_raw = $wpdb->get_results(
            "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status",
            OBJECT_K
        );
        $counts = [];
        foreach ( (array) $counts_raw as $slug => $obj ) {
            $counts[ $slug ] = (int) $obj->n;
        }
        $kpi_total   = array_sum( $counts );
        $kpi_pending = $counts['pending'] ?? 0;

        // ── Today's confirmed bookings + covers ───────────────────────────
        $today_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS confirmed_today, COALESCE(SUM(guests), 0) AS guests_today
             FROM {$table} WHERE date = %s AND status = 'confirmed'",
            date( 'Y-m-d' )
        ) );
        $today_confirmed = (int) ( $today_row->confirmed_today ?? 0 );
        $today_guests    = (int) ( $today_row->guests_today    ?? 0 );

        // ── Status map for tabs and badges ────────────────────────────────
        $statuses = [
            'pending'         => 'Pending',
            'confirmed'       => 'Confirmed',
            'cancelled'       => 'Cancelled',
            'no_show'         => 'No-show',
            'pending_payment' => 'Awaiting Payment',
            'auto_cancelled'  => 'Auto-Cancelled',
        ];

        $base_url = admin_url( 'admin.php?page=dd-reservations' );
        ?>
        <div class="wrap dd-admin-wrap">
        <div class="dd-page-wrap">

            <!-- Header -->
            <div class="dd-res-header">
                <h1>📅 Reservations</h1>
                <p>Manage all table bookings for <?php echo esc_html( get_option( 'dish_dash_restaurant_name', 'your restaurant' ) ); ?></p>
            </div>

            <!-- KPI Cards -->
            <div class="dd-res-kpis">
                <div class="dd-res-kpi">
                    <div class="dd-res-kpi__label">Total Bookings</div>
                    <div class="dd-res-kpi__value"><?php echo number_format( $kpi_total ); ?></div>
                </div>
                <div class="dd-res-kpi">
                    <div class="dd-res-kpi__label">Pending</div>
                    <div class="dd-res-kpi__value"><?php echo number_format( $kpi_pending ); ?></div>
                    <div class="dd-res-kpi__sub">needs action</div>
                </div>
                <div class="dd-res-kpi">
                    <div class="dd-res-kpi__label">Confirmed Today</div>
                    <div class="dd-res-kpi__value"><?php echo number_format( $today_confirmed ); ?></div>
                    <div class="dd-res-kpi__sub"><?php echo esc_html( date( 'M j' ) ); ?></div>
                </div>
                <div class="dd-res-kpi">
                    <div class="dd-res-kpi__label">Today's Guests</div>
                    <div class="dd-res-kpi__value"><?php echo number_format( $today_guests ); ?></div>
                    <div class="dd-res-kpi__sub">confirmed covers</div>
                </div>
            </div>

            <!-- Status Tabs -->
            <div class="dd-res-tabs">
                <a href="<?php echo esc_url( $base_url ); ?>"
                   class="dd-res-tab <?php echo $filter_status === '' ? 'active' : ''; ?>">
                    All <span class="count">(<?php echo esc_html( $kpi_total ); ?>)</span>
                </a>
                <?php foreach ( $statuses as $slug => $label ) :
                    $cnt = $counts[ $slug ] ?? 0;
                ?>
                <a href="<?php echo esc_url( $base_url . '&status=' . $slug ); ?>"
                   class="dd-res-tab <?php echo $filter_status === $slug ? 'active' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                    <span class="count">(<?php echo esc_html( $cnt ); ?>)</span>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Filter Bar -->
            <div class="dd-res-filters">
                <form method="get" style="display:contents">
                    <input type="hidden" name="page" value="dd-reservations">
                    <?php if ( $filter_status ) : ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr( $filter_status ); ?>">
                    <?php endif; ?>
                    <input type="text" name="s"
                           placeholder="Name, WhatsApp or Ref…"
                           value="<?php echo esc_attr( $search ); ?>">
                    <input type="date" name="res_date"
                           value="<?php echo esc_attr( $filter_date ); ?>">
                    <button type="submit" class="button button-primary">Filter</button>
                    <?php if ( $search || $filter_date ) : ?>
                        <a href="<?php echo esc_url( $base_url . ( $filter_status ? '&status=' . $filter_status : '' ) ); ?>"
                           class="dd-clear-link">✕ Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Table -->
            <div class="dd-res-table-wrap">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Ref</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Session</th>
                            <th>Guests</th>
                            <th>Name</th>
                            <th>WhatsApp</th>
                            <th>Special Requests</th>
                            <th>Status</th>
                            <th>Deposit</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rows ) ) : ?>
                            <tr><td colspan="12" style="text-align:center;color:#6b7280;padding:24px;">No reservations found.</td></tr>
                        <?php else :
                            $row_num = $row_number_start;
                            foreach ( $rows as $r ) :
                                $wa_num = preg_replace( '/\D/', '', $r->whatsapp );
                        ?>
                            <tr>
                                <td><?php echo esc_html( $row_num ); $row_num++; ?></td>
                                <td><code><?php echo esc_html( $r->booking_ref ); ?></code></td>
                                <td><?php echo esc_html( $r->date ); ?></td>
                                <td><?php echo esc_html( $r->time ); ?></td>
                                <td><?php echo esc_html( ucfirst( $r->session ) ); ?></td>
                                <td><?php echo esc_html( $r->guests ); ?></td>
                                <td><?php echo esc_html( $r->name ); ?></td>
                                <td>
                                    <?php if ( $wa_num ) : ?>
                                        <a href="https://wa.me/<?php echo esc_attr( $wa_num ); ?>" target="_blank">
                                            <?php echo esc_html( $r->whatsapp ); ?>
                                        </a>
                                    <?php else : ?>
                                        <?php echo esc_html( $r->whatsapp ); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $r->special_requests ?? '' ); ?></td>
                                <td>
                                    <span class="dd-res-badge dd-res-badge--<?php echo esc_attr( $r->status ); ?>">
                                        <?php echo esc_html( $statuses[ $r->status ] ?? ucfirst( str_replace( '_', ' ', $r->status ) ) ); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ( ! empty( $r->deposit_required ) ) :
                                        $deposit_labels = [
                                            'none'     => '—',
                                            'pending'  => '⏳ Awaiting',
                                            'paid'     => '✅ Paid',
                                            'failed'   => '✗ Failed',
                                            'refunded' => '↩ Refunded',
                                        ];
                                        $dep_status = $r->deposit_status ?: 'none';
                                        echo esc_html( $deposit_labels[ $dep_status ] ?? $dep_status );
                                        echo '<br><small>' . esc_html( number_format( (int) $r->deposit_amount ) ) . ' RWF</small>';
                                    else : ?>
                                        <span style="color:#9ca3af">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="dd-res-actions">
                                        <?php
                                        // WhatsApp contextual message button
                                        $restaurant  = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );
                                        $admin_phone = get_option( 'dish_dash_phone', '' );
                                        $date_fmt    = date( 'D, d M Y', strtotime( $r->date ) );
                                        $guest_word  = ( (int) $r->guests === 1 ) ? 'guest' : 'guests';
                                        $session_fmt = ucfirst( $r->session );
                                        $lines       = [];

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

                                        if ( ! empty( $lines ) && $wa_num ) :
                                            $wa_msg  = implode( "\n", $lines );
                                            $wa_link = 'https://wa.me/' . $wa_num . '?text=' . rawurlencode( $wa_msg );
                                            $wa_labels = [
                                                'confirmed' => '💬 Send Confirmation',
                                                'cancelled' => '💬 Send Cancellation',
                                                'no_show'   => '💬 Send Follow-up',
                                            ];
                                        ?>
                                        <a href="<?php echo esc_attr( $wa_link ); ?>"
                                           target="_blank"
                                           class="dd-res-wa-btn">
                                           <?php echo esc_html( $wa_labels[ $r->status ] ?? '💬 Notify' ); ?>
                                        </a>
                                        <?php endif; ?>

                                        <?php if ( $r->status !== 'confirmed' ) : ?>
                                        <button class="dd-res-action-btn dd-res-action-btn--confirm dd-res-status-btn"
                                                data-id="<?php echo esc_attr( $r->id ); ?>"
                                                data-status="confirmed">Confirm</button>
                                        <?php endif; ?>
                                        <?php if ( $r->status !== 'cancelled' ) : ?>
                                        <button class="dd-res-action-btn dd-res-action-btn--cancel dd-res-status-btn"
                                                data-id="<?php echo esc_attr( $r->id ); ?>"
                                                data-status="cancelled">Cancel</button>
                                        <?php endif; ?>
                                        <?php if ( $r->status !== 'no_show' ) : ?>
                                        <button class="dd-res-action-btn dd-res-action-btn--noshow dd-res-status-btn"
                                                data-id="<?php echo esc_attr( $r->id ); ?>"
                                                data-status="no_show">No-show</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php
                $pagination_base_args = [ 'page' => 'dd-reservations' ];
                if ( $filter_status ) { $pagination_base_args['status']   = $filter_status; }
                if ( $filter_date )   { $pagination_base_args['res_date'] = $filter_date; }
                if ( $search )        { $pagination_base_args['s']        = $search; }
                $pagination_base_args['per_page'] = $per_page;
                ?>
                <div class="dd-res-pagination">
                    <div>
                        <?php
                        if ( $total_rows === 0 ) {
                            echo 'No reservations';
                        } elseif ( $per_page === 'all' ) {
                            echo 'Showing all ' . esc_html( $total_rows ) . ' reservations';
                        } else {
                            $showing_to = min( $row_number_start + count( $rows ) - 1, $total_rows );
                            echo 'Showing ' . esc_html( $row_number_start ) . '–'
                                . esc_html( $showing_to ) . ' of ' . esc_html( $total_rows );
                        }
                        ?>
                    </div>

                    <div style="display:flex;align-items:center;gap:14px;">
                        <!-- Per-page selector -->
                        <form method="get" style="display:flex;align-items:center;gap:6px;margin:0;">
                            <input type="hidden" name="page" value="dd-reservations">
                            <?php if ( $filter_status ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $filter_status ); ?>"><?php endif; ?>
                            <?php if ( $filter_date )   : ?><input type="hidden" name="res_date" value="<?php echo esc_attr( $filter_date ); ?>"><?php endif; ?>
                            <?php if ( $search )        : ?><input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>"><?php endif; ?>
                            <label for="dd-res-per-page" style="font-size:13px;color:#6b7280;">Per page:</label>
                            <select name="per_page" id="dd-res-per-page" onchange="this.form.submit()">
                                <?php foreach ( [ '25', '50', '75', 'all' ] as $opt ) : ?>
                                    <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $per_page, $opt ); ?>>
                                        <?php echo $opt === 'all' ? 'All' : esc_html( $opt ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>

                        <!-- Page navigation -->
                        <?php if ( $per_page !== 'all' && $total_pages > 1 ) : ?>
                        <div style="display:flex;align-items:center;gap:4px;">
                            <?php
                            if ( $current_page > 1 ) {
                                $prev_url = add_query_arg( array_merge( $pagination_base_args, [ 'paged' => $current_page - 1 ] ), admin_url( 'admin.php' ) );
                                echo '<a href="' . esc_url( $prev_url ) . '">‹ Prev</a>';
                            } else {
                                echo '<span class="dd-nav-disabled">‹ Prev</span>';
                            }
                            ?>
                            <span class="dd-page-current">Page <?php echo esc_html( $current_page ); ?> of <?php echo esc_html( $total_pages ); ?></span>
                            <?php
                            if ( $current_page < $total_pages ) {
                                $next_url = add_query_arg( array_merge( $pagination_base_args, [ 'paged' => $current_page + 1 ] ), admin_url( 'admin.php' ) );
                                echo '<a href="' . esc_url( $next_url ) . '">Next ›</a>';
                            } else {
                                echo '<span class="dd-nav-disabled">Next ›</span>';
                            }
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div><!-- /dd-res-table-wrap -->

        </div><!-- /dd-page-wrap -->
        </div><!-- /wrap -->

        <div id="dd-res-toast"></div>
        <script>
        (function () {
            var nonce = '<?php echo wp_create_nonce( 'dish_dash_admin' ); ?>';
            var toast = document.getElementById('dd-res-toast');
            var toastTimer;

            function showToast(msg, type) {
                toast.textContent = msg;
                toast.className   = 'show ' + type;
                clearTimeout(toastTimer);
                toastTimer = setTimeout(function () { toast.className = ''; }, 2400);
            }

            document.querySelectorAll('.dd-res-status-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id     = this.dataset.id;
                    var status = this.dataset.status;
                    var self   = this;
                    self.classList.add('loading');

                    fetch(ajaxurl, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body:    new URLSearchParams({
                            action: 'dd_reservation_update_status',
                            id:     id,
                            status: status,
                            nonce:  nonce
                        })
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            showToast('Status updated', 'success');
                            setTimeout(function () { location.reload(); }, 800);
                        } else {
                            var msg = (data.data && data.data.message) ? data.data.message : 'Error updating status';
                            showToast(msg, 'error');
                            self.classList.remove('loading');
                        }
                    })
                    .catch(function () {
                        showToast('Network error — please try again', 'error');
                        self.classList.remove('loading');
                    });
                });
            });
        })();
        </script>
        <?php
    }
}
