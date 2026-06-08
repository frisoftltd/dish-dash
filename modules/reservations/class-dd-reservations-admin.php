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
            '📅 Reservations',
            'manage_options',
            'dd-reservations',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'dd-reservations' ) === false ) return;
        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style(
            'dd-reservations-admin',
            plugin_dir_url( __FILE__ ) . '../../assets/css/reservations-admin.css',
            [ 'dashicons' ],
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

        // ── Filter variables ──────────────────────────────────────────────
        $filter_status = isset( $_GET['status'] )     ? sanitize_text_field( wp_unslash( $_GET['status'] ) )     : '';
        $status_filter = $filter_status; // alias used in filter bar HTML
        $search        = isset( $_GET['s'] )          ? sanitize_text_field( wp_unslash( $_GET['s'] ) )           : '';
        $s             = $search; // alias used in filter bar HTML
        $date_range    = isset( $_GET['date_range'] ) ? sanitize_key( wp_unslash( $_GET['date_range'] ) )         : '';
        $res_date      = isset( $_GET['res_date'] )   ? sanitize_text_field( wp_unslash( $_GET['res_date'] ) )    : '';

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

        // Date range filter
        if ( $date_range === 'today' ) {
            $where .= ' AND date = CURDATE()';
        } elseif ( $date_range === '7' ) {
            $where .= ' AND date >= DATE_SUB( CURDATE(), INTERVAL 7 DAY )';
        } elseif ( $date_range === '30' ) {
            $where .= ' AND date >= DATE_SUB( CURDATE(), INTERVAL 30 DAY )';
        } elseif ( $date_range === '90' ) {
            $where .= ' AND date >= DATE_SUB( CURDATE(), INTERVAL 90 DAY )';
        } elseif ( $date_range === 'custom' && $res_date ) {
            $where   .= ' AND date = %s';
            $params[] = $res_date;
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

        // ── Status map ────────────────────────────────────────────────────
        $statuses = [
            'pending'         => 'Pending',
            'confirmed'       => 'Confirmed',
            'cancelled'       => 'Cancelled',
            'no_show'         => 'No-show',
            'pending_payment' => 'Awaiting Payment',
            'auto_cancelled'  => 'Auto-Cancelled',
        ];

        $base_url = admin_url( 'admin.php?page=dd-reservations' );

        // ── Range pills config ────────────────────────────────────────────
        $range_pills = [
            ''       => 'All',
            'today'  => 'Today',
            '7'      => '7 Days',
            '30'     => '30 Days',
            '90'     => '90 Days',
            'custom' => 'Custom',
        ];

        // ── Page tabs config ──────────────────────────────────────────────
        $page_tabs = [
            'dd-reservations' => [ 'label' => 'Reservations',    'icon' => 'dashicons-calendar-alt' ],
            'dd-tables'       => [ 'label' => 'Tables',           'icon' => 'dashicons-grid-view'    ],
            'dd-sections'     => [ 'label' => 'Seating Sections', 'icon' => 'dashicons-layout'       ],
        ];
        $current_page_tab = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'dd-reservations';
        ?>
        <div class="wrap dd-admin-wrap">
        <div class="dd-page-wrap">

            <!-- Header -->
            <div class="dd-res-header">
                <h1>
                    <span class="dashicons dashicons-calendar-alt"
                          style="font-size:26px;width:26px;height:26px;margin-right:8px;vertical-align:middle;"></span>
                    Reservations
                </h1>
                <p>Manage all table bookings for <?php echo esc_html( get_option( 'dish_dash_restaurant_name', 'your restaurant' ) ); ?></p>
            </div>

            <!-- Page-level tabs -->
            <div class="dd-res-page-tabs">
                <?php foreach ( $page_tabs as $slug => $tab ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
                   class="dd-res-page-tab <?php echo $current_page_tab === $slug ? 'active' : ''; ?>">
                    <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
                    <?php echo esc_html( $tab['label'] ); ?>
                </a>
                <?php endforeach; ?>
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
                <form method="get" id="dd-res-filter-form" style="display:contents">
                    <input type="hidden" name="page" value="dd-reservations">
                    <?php if ( $status_filter ) : ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">
                    <?php endif; ?>

                    <input type="text" name="s" value="<?php echo esc_attr( $s ); ?>"
                           placeholder="Name, WhatsApp or Ref…" class="dd-res-search-input">

                    <div class="dd-res-range-pills">
                        <?php foreach ( $range_pills as $val => $label ) : ?>
                        <button type="submit" name="date_range" value="<?php echo esc_attr( $val ); ?>"
                                class="dd-res-range-pill <?php echo $date_range === $val ? 'active' : ''; ?>">
                            <?php echo esc_html( $label ); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="dd-res-custom-date <?php echo $date_range === 'custom' ? 'visible' : ''; ?>"
                         id="dd-res-custom-date">
                        <input type="date" name="res_date" value="<?php echo esc_attr( $res_date ); ?>">
                    </div>

                    <button type="submit" name="date_range"
                            value="<?php echo esc_attr( $date_range ); ?>"
                            class="button button-primary">Filter</button>

                    <?php if ( $s || $date_range || $res_date ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=dd-reservations' . ( $status_filter ? '&status=' . $status_filter : '' ) ) ); ?>"
                       class="dd-clear-link">Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Table -->
            <div class="dd-res-table-wrap">
                <table class="wp-list-table widefat fixed striped" style="table-layout:fixed;width:100%;">
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th style="width:160px">Ref</th>
                            <th style="width:100px">Date</th>
                            <th style="width:60px">Guests</th>
                            <th style="width:140px">Name</th>
                            <th style="width:120px">WhatsApp</th>
                            <th style="width:140px">Special Requests</th>
                            <th style="width:100px">Status</th>
                            <th style="width:80px">Deposit</th>
                            <th style="width:160px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rows ) ) : ?>
                            <tr><td colspan="10" style="text-align:center;color:#6b7280;padding:24px;">No reservations found.</td></tr>
                        <?php else :
                            $row_num = $row_number_start;
                            foreach ( $rows as $r ) :
                                $wa_num = preg_replace( '/\D/', '', $r->whatsapp );
                        ?>
                            <tr data-reservation-id="<?= esc_attr( $r->id ) ?>">
                                <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $row_num ); $row_num++; ?></td>
                                <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><code><?php echo esc_html( $r->booking_ref ); ?></code></td>
                                <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $r->date ); ?></td>
                                <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $r->guests ); ?></td>
                                <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $r->name ); ?></td>
                                <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?php if ( $wa_num ) : ?>
                                        <a href="https://wa.me/<?php echo esc_attr( $wa_num ); ?>" target="_blank">
                                            <?php echo esc_html( $r->whatsapp ); ?>
                                        </a>
                                    <?php else : ?>
                                        <?php echo esc_html( $r->whatsapp ); ?>
                                    <?php endif; ?>
                                </td>
                                <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $r->special_requests ?? '' ); ?></td>
                                <td style="overflow:hidden;">
                                    <span class="dd-res-badge dd-res-badge--<?php echo esc_attr( $r->status ); ?>">
                                        <?php echo esc_html( $statuses[ $r->status ] ?? ucfirst( str_replace( '_', ' ', $r->status ) ) ); ?>
                                    </span>
                                </td>
                                <td style="overflow:hidden;">
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
                                <td style="overflow:hidden;">
                                    <div class="dd-res-actions">
                                        <?php
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
                if ( $filter_status ) { $pagination_base_args['status']     = $filter_status; }
                if ( $date_range )    { $pagination_base_args['date_range'] = $date_range; }
                if ( $res_date )      { $pagination_base_args['res_date']   = $res_date; }
                if ( $search )        { $pagination_base_args['s']          = $search; }
                $pagination_base_args['per_page'] = $per_page;
                ?>
                <div class="dd-res-pagination">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <!-- Per-page pills -->
                        <div class="dd-res-perpage-pills">
                            <?php foreach ( [ '25', '50', '75', 'all' ] as $opt ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( [ 'per_page' => $opt, 'paged' => 1 ] ) ); ?>"
                               class="dd-res-perpage-pill <?php echo $per_page === $opt ? 'active' : ''; ?>">
                                <?php echo esc_html( strtoupper( $opt ) ); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>

                        <span style="font-size:13px;color:#9ca3af;">|</span>

                        <div style="font-size:13px;color:#6b7280;">
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
                    </div>

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
            </div><!-- /dd-res-table-wrap -->

        </div><!-- /dd-page-wrap -->
        </div><!-- /wrap -->

        <div id="dd-res-toast"></div>
        <script>
        (function () {
            // Show/hide custom date input based on range pill selection
            document.querySelectorAll('.dd-res-range-pill').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var isCustom = this.value === 'custom';
                    document.getElementById('dd-res-custom-date').classList.toggle('visible', isCustom);
                });
            });

            // AJAX status updates
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

        <?php if ( ! empty( $_GET['open_reservation'] ) ) :
            $open_id = absint( $_GET['open_reservation'] );
        ?>
        <script>
        (function() {
            var targetId = <?= $open_id ?>;
            var row = document.querySelector('tr[data-reservation-id="' + targetId + '"]');
            if ( row ) {
                row.style.transition = 'background .3s';
                row.style.background = '#fef9c3';
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(function() { row.style.background = ''; }, 3000);
            }
        })();
        </script>
        <?php endif; ?>
        <?php
    }
}
