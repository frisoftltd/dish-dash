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
            'dd_manage_reservations',
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
        $ct    = $wpdb->prefix . 'dishdash_customers';

        // ── Test-customer exclusion (v3.15.6) ───────────────────────────────
        // customer_id is nullable (orphan reservations with no resolvable
        // customer link) — LEFT JOIN + NULL-safe WHERE, never INNER JOIN, so
        // orphans stay counted as non-test. See investigation-testflag.md §1.
        // The reservation-level "Test" filter tab (is_test = 1) is untouched —
        // this only layers onto the non-test views.
        $r_test_join  = "LEFT JOIN {$ct} c ON c.id = r.customer_id";
        $r_test_where = "r.is_test = 0 AND (c.is_test IS NULL OR c.is_test = 0)";

        // ── Status update action (POST fallback) ──────────────────────────
        if (
            isset( $_POST['dd_res_action'], $_POST['dd_res_id'], $_POST['_wpnonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dd_res_status' )
        ) {
            $allowed    = [ 'pending', 'confirmed', 'cancelled', 'no_show' ];
            $new_status = sanitize_text_field( wp_unslash( $_POST['dd_res_action'] ) );
            $res_id     = intval( $_POST['dd_res_id'] );
            if ( in_array( $new_status, $allowed, true ) && $res_id > 0 ) {
                $old_status_row = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $res_id ) );
                $wpdb->update( $table, [ 'status' => $new_status ], [ 'id' => $res_id ], [ '%s' ], [ '%d' ] );
                do_action( 'dish_dash_reservation_status_changed', $res_id, $old_status_row, $new_status );
            }
        }

        // ── Focused single-reservation view ───────────────────────────────
        $open_reservation_id = isset( $_GET['open_reservation'] ) ? absint( $_GET['open_reservation'] ) : 0;

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

        if ( $filter_status === 'test' ) {
            $where .= ' AND r.is_test = 1';
        } elseif ( $filter_status === 'awaiting_deposit' ) {
            // Deposit required, customer hasn't paid or claimed yet. Deliberately
            // excludes 'claimed' (MoMo "I have paid", unverified) — those still need
            // staff eyes before auto-cancel and stay visible on their normal status
            // tab; this tab is only for bookings nobody has acted on at all.
            $where .= " AND {$r_test_where} AND r.deposit_required = 1 AND r.deposit_status = 'pending'";
        } else {
            $where .= " AND {$r_test_where}";
            if ( $filter_status ) {
                $where   .= ' AND r.status = %s';
                $params[] = $filter_status;
            } else {
                // Bare "All" view only — hide deposit-required bookings still
                // awaiting customer payment (visible via the "Awaiting Payment"
                // tab instead). Explicit status tabs (Pending/Confirmed/etc.)
                // are untouched and still include these rows if they match.
                $where .= " AND NOT ( r.deposit_required = 1 AND r.deposit_status = 'pending' )";
            }
        }

        // Date range filter
        if ( $date_range === 'today' ) {
            $where .= ' AND r.date = CURDATE()';
        } elseif ( $date_range === '7' ) {
            $where .= ' AND r.date >= DATE_SUB( CURDATE(), INTERVAL 7 DAY )';
        } elseif ( $date_range === '30' ) {
            $where .= ' AND r.date >= DATE_SUB( CURDATE(), INTERVAL 30 DAY )';
        } elseif ( $date_range === '90' ) {
            $where .= ' AND r.date >= DATE_SUB( CURDATE(), INTERVAL 90 DAY )';
        } elseif ( $date_range === 'custom' && $res_date ) {
            $where   .= ' AND r.date = %s';
            $params[] = $res_date;
        }

        if ( $search ) {
            $where   .= ' AND (r.name LIKE %s OR r.whatsapp LIKE %s OR r.booking_ref LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // ── Total matching rows (for pagination) ──────────────────────────
        $count_sql  = "SELECT COUNT(*) FROM {$table} r {$r_test_join} WHERE {$where}";
        $total_rows = $params
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
            : (int) $wpdb->get_var( $count_sql );

        // ── Fetch page ────────────────────────────────────────────────────
        // SELECT r.* (not *) — the LEFT JOIN pulls in `c` columns that overlap
        // reservation columns by name (id, created_at, updated_at, is_test,
        // name, whatsapp); a bare SELECT * would let customer columns silently
        // clobber reservation columns in the object result keyed by column name.
        $order_sql = ' ORDER BY r.created_at DESC, r.id DESC';

        if ( $per_page === 'all' ) {
            $sql          = "SELECT r.* FROM {$table} r {$r_test_join} WHERE {$where}{$order_sql}";
            $query_params = $params;
        } else {
            $pp           = (int) $per_page;
            $offset       = ( $current_page - 1 ) * $pp;
            $sql          = "SELECT r.* FROM {$table} r {$r_test_join} WHERE {$where}{$order_sql} LIMIT %d OFFSET %d";
            $query_params = array_merge( $params, [ $pp, $offset ] );
        }

        $rows = ! empty( $query_params )
            ? $wpdb->get_results( $wpdb->prepare( $sql, $query_params ) )
            : $wpdb->get_results( $sql );

        // Focused view — override all filters, show only the targeted reservation
        if ( $open_reservation_id ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
                $open_reservation_id
            ) );
        }

        $row_number_start = ( $per_page === 'all' ) ? 1 : ( ( $current_page - 1 ) * (int) $per_page ) + 1;
        $total_pages      = ( $per_page === 'all' || $total_rows === 0 ) ? 1 : (int) ceil( $total_rows / (int) $per_page );

        // ── Counts per status (unfiltered) for KPIs + tabs ───────────────
        $counts_raw = $wpdb->get_results(
            "SELECT r.status, COUNT(*) AS n FROM {$table} r {$r_test_join} WHERE {$r_test_where} GROUP BY r.status",
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
            "SELECT COUNT(*) AS confirmed_today, COALESCE(SUM(r.guests), 0) AS guests_today
             FROM {$table} r {$r_test_join} WHERE r.date = %s AND r.status = 'confirmed' AND {$r_test_where}",
            date( 'Y-m-d' )
        ) );
        $today_confirmed = (int) ( $today_row->confirmed_today ?? 0 );
        $today_guests    = (int) ( $today_row->guests_today    ?? 0 );

        // ── Status map ────────────────────────────────────────────────────
        // 'pending_payment' removed (v3.14.3) — dead label for reservations,
        // never written to this table's `status` column (real, active status
        // for orders only). Reservation deposit state lives in the separate
        // deposit_status column, surfaced via the 'awaiting_deposit' tab below,
        // not through this status map.
        $statuses = [
            'pending'        => 'Pending',
            'confirmed'      => 'Confirmed',
            'cancelled'      => 'Cancelled',
            'no_show'        => 'No-show',
            'auto_cancelled' => 'Auto-Cancelled',
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

            <?php if ( ! $open_reservation_id ) : ?>
            <!-- Status Tabs -->
            <?php
            $test_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_test = 1" );
            // Standalone count, same pattern as $test_count above — deposit_status
            // is a separate column from `status`, so it isn't part of $counts
            // (which is GROUP BY status).
            $awaiting_deposit_count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} r {$r_test_join} WHERE {$r_test_where} AND r.deposit_required = 1 AND r.deposit_status = 'pending'"
            );
            // "All" badge must reflect what the All view actually shows now that
            // it excludes awaiting-deposit rows — $kpi_total (the KPI card above)
            // stays the true overall total on purpose, unaffected.
            $all_tab_count = $kpi_total - $awaiting_deposit_count;
            ?>
            <div class="dd-res-tabs">
                <a href="<?php echo esc_url( $base_url ); ?>"
                   class="dd-res-tab <?php echo $filter_status === '' ? 'active' : ''; ?>">
                    All <span class="count">(<?php echo esc_html( $all_tab_count ); ?>)</span>
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
                <a href="<?php echo esc_url( $base_url . '&status=awaiting_deposit' ); ?>"
                   class="dd-res-tab <?php echo $filter_status === 'awaiting_deposit' ? 'active' : ''; ?>">
                    💳 Awaiting Payment <span class="count">(<?php echo esc_html( $awaiting_deposit_count ); ?>)</span>
                </a>
                <a href="<?php echo esc_url( $base_url . '&status=test' ); ?>"
                   class="dd-res-tab <?php echo $filter_status === 'test' ? 'active' : ''; ?>">
                    🧪 Test <span class="count">(<?php echo esc_html( $test_count ); ?>)</span>
                </a>
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
            <?php endif; ?>

            <!-- Bulk action bar -->
            <div class="dd-res-bulk-bar" id="dd-res-bulk-bar" style="display:none;">
                <span class="dd-res-bulk-count" id="dd-res-bulk-count">0 selected</span>
                <select id="dd-res-bulk-select" class="dd-res-bulk-select">
                    <option value="">— Bulk action —</option>
                    <option value="confirmed">Confirm</option>
                    <option value="cancelled">Cancel</option>
                    <option value="no_show">Mark No-show</option>
                    <option value="mark_test">Mark as Test</option>
                    <option value="unmark_test">Remove Test flag</option>
                </select>
                <button id="dd-res-bulk-apply" class="dd-res-bulk-apply">Apply</button>
                <button id="dd-res-bulk-cancel" class="dd-res-bulk-cancel">✕ Deselect all</button>
            </div>

            <!-- Back banner (focused view) -->
            <?php if ( $open_reservation_id ) : ?>
            <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:8px;padding:10px 16px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;font-size:13px;">
                <span>📌 Showing reservation from notification</span>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=dd-reservations' ) ); ?>"
                   style="font-weight:600;color:#374151;text-decoration:none;">← View all reservations</a>
            </div>
            <?php endif; ?>

            <!-- Table -->
            <div class="dd-res-table-wrap">
                <table class="wp-list-table widefat fixed striped" style="table-layout:fixed;width:100%;">
                    <thead>
                        <tr>
                            <th style="width:40px;text-align:center;">
                                <input type="checkbox" id="dd-res-select-all" style="cursor:pointer;">
                            </th>
                            <th style="width:160px">Ref</th>
                            <th style="width:100px">Date</th>
                            <th style="width:60px">Guests</th>
                            <th style="width:140px">Name</th>
                            <th style="width:120px">WhatsApp</th>
                            <th style="width:100px">Status</th>
                            <th style="width:80px">Deposit</th>
                            <th style="width:100px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rows ) ) : ?>
                            <tr><td colspan="9" style="text-align:center;color:#6b7280;padding:24px;">No reservations found.</td></tr>
                        <?php else :
                            foreach ( $rows as $r ) :
                                $wa_num = preg_replace( '/\D/', '', $r->whatsapp );
                        ?>
                            <tr class="dd-res-row" data-reservation-id="<?= esc_attr( $r->id ) ?>" style="cursor:pointer;<?php if ( $open_reservation_id ) echo 'background:#fef9c3;'; ?>">
                                <td style="text-align:center;" onclick="event.stopPropagation()">
                                    <input type="checkbox" class="dd-res-row-check" value="<?= esc_attr( $r->id ) ?>" style="cursor:pointer;">
                                </td>
                                <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><code><?php echo esc_html( $r->booking_ref ); ?></code></td>
                                <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $r->date ); ?></td>
                                <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $r->guests ); ?></td>
                                <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $r->name ); ?></td>
                                <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" onclick="event.stopPropagation()">
                                    <?php if ( $wa_num ) : ?>
                                        <a href="https://wa.me/<?php echo esc_attr( $wa_num ); ?>" target="_blank">
                                            <?php echo esc_html( $r->whatsapp ); ?>
                                        </a>
                                    <?php else : ?>
                                        <?php echo esc_html( $r->whatsapp ); ?>
                                    <?php endif; ?>
                                </td>
                                <td style="overflow:hidden;">
                                    <?php
                                    $badge_mod   = $r->status;
                                    $badge_label = $statuses[ $r->status ] ?? ucfirst( str_replace( '_', ' ', $r->status ) );
                                    // A confirmed booking whose required deposit is NOT restaurant-confirmed
                                    // ('paid') must not read as secured green. Reuse the amber "attention"
                                    // treatment and make the money state explicit in the label. Display only —
                                    // no data/status change; auto-cancel still keys on deposit_status.
                                    if ( 'confirmed' === $r->status
                                         && ! empty( $r->deposit_required )
                                         && 'paid' !== $r->deposit_status ) {
                                        $badge_mod   = 'pending';
                                        $badge_label = 'Confirmed — deposit unpaid';
                                    }
                                    ?>
                                    <span class="dd-res-badge dd-res-badge--<?php echo esc_attr( $badge_mod ); ?>">
                                        <?php echo esc_html( $badge_label ); ?>
                                    </span>
                                    <?php if ( ! empty( $r->is_test ) ) : ?>
                                    <span class="dd-res-badge dd-res-badge--test">Test</span>
                                    <?php endif; ?>
                                </td>
                                <td style="overflow:hidden;">
                                    <?php if ( ! empty( $r->deposit_required ) ) :
                                        $deposit_labels = [
                                            'none'     => '—',
                                            'pending'  => '⏳ Awaiting',
                                            'claimed'  => '🙋 Claimed (unverified)',
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
                                <td style="overflow:hidden;text-align:center;" onclick="event.stopPropagation()">
                                    <button type="button" class="dd-res-action-btn dd-res-action-btn--confirm dd-res-open-modal-btn"
                                            data-id="<?php echo esc_attr( $r->id ); ?>">View →</button>
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

        <!-- Reservation Accept Modal — shell mirrors admin/pages/orders.php's
             #dd-order-modal exactly (.dd-modal-overlay/.dd-modal/.dd-modal-header/
             .dd-modal-body/.dd-modal-footer), styled by the already-loaded
             assets/css/admin.css (enqueued on every "dish-dash" admin page —
             no new stylesheet needed). Reservation-specific status/deposit badges
             reuse this file's OWN .dd-res-badge classes (reservations-admin.css),
             not orders' .dd-status-* (those don't cover no_show/pending_payment/
             auto_cancelled). -->
        <div id="dd-res-modal" class="dd-modal-overlay" style="display:none">
            <div class="dd-modal">
                <div class="dd-modal-header">
                    <div>
                        <span class="dd-res-modal-ref"></span>
                        <span class="dd-res-modal-date"></span>
                    </div>
                    <button class="dd-modal-close" id="dd-res-modal-close">✕</button>
                </div>
                <div class="dd-modal-body">
                    <div class="dd-modal-section">
                        <div class="dd-modal-label">CUSTOMER</div>
                        <div class="dd-res-modal-name"></div>
                        <div class="dd-res-modal-whatsapp"></div>
                    </div>
                    <div class="dd-modal-section">
                        <div class="dd-modal-label">BOOKING</div>
                        <div class="dd-res-modal-details"></div>
                    </div>
                    <div class="dd-modal-section dd-modal-status-section">
                        <div class="dd-modal-label">STATUS</div>
                        <div class="dd-res-modal-status-badge"></div>
                    </div>
                    <div class="dd-modal-section dd-res-modal-deposit-section" style="display:none">
                        <div class="dd-modal-label">DEPOSIT</div>
                        <div class="dd-res-modal-deposit-info"></div>
                    </div>
                </div>
                <div class="dd-modal-footer" id="dd-res-modal-actions"></div>
                <div class="dd-modal-loading" id="dd-res-modal-loading" style="display:none">
                    <span>Loading…</span>
                </div>
            </div>
        </div>

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
            // Needed client-side only for building the WhatsApp notify message
            // inside the modal (moved from the row's PHP $lines cascade, v3.14.6).
            var resRestaurantName = <?php echo wp_json_encode( get_option( 'dish_dash_restaurant_name', 'Khana Khazana' ) ); ?>;
            var resAdminPhone     = <?php echo wp_json_encode( get_option( 'dish_dash_phone', '' ) ); ?>;
            var toast = document.getElementById('dd-res-toast');
            var toastTimer;

            function showToast(msg, type) {
                toast.textContent = msg;
                toast.className   = 'show ' + type;
                clearTimeout(toastTimer);
                toastTimer = setTimeout(function () { toast.className = ''; }, 2400);
            }

            // Cancel/No-show/Mark-deposit-paid moved into the accept modal
            // (v3.14.6) — see updateStatusFromModal() / markDepositPaidFromModal(),
            // wired fresh inside renderResModal() on every render, same pattern
            // already proven for Confirm/PesaPal. No page-load-once binding here
            // anymore since these buttons no longer exist in the row.

            // ── Bulk actions ───────────────────────────────────────────────────
            var bulkBar    = document.getElementById('dd-res-bulk-bar');
            var bulkCount  = document.getElementById('dd-res-bulk-count');
            var bulkSelect = document.getElementById('dd-res-bulk-select');
            var bulkApply  = document.getElementById('dd-res-bulk-apply');
            var bulkCancel = document.getElementById('dd-res-bulk-cancel');
            var selectAll  = document.getElementById('dd-res-select-all');

            function syncBulkBar() {
                var checked = document.querySelectorAll('.dd-res-row-check:checked');
                if (checked.length > 0) {
                    bulkBar.style.display = 'flex';
                    bulkCount.textContent = checked.length + ' selected';
                } else {
                    bulkBar.style.display = 'none';
                }
            }

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    document.querySelectorAll('.dd-res-row-check').forEach(function (cb) {
                        cb.checked = selectAll.checked;
                    });
                    syncBulkBar();
                });
            }

            document.querySelectorAll('.dd-res-row-check').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    var all     = document.querySelectorAll('.dd-res-row-check');
                    var checked = document.querySelectorAll('.dd-res-row-check:checked');
                    if (selectAll) selectAll.checked = all.length === checked.length;
                    syncBulkBar();
                });
            });

            if (bulkCancel) {
                bulkCancel.addEventListener('click', function () {
                    document.querySelectorAll('.dd-res-row-check').forEach(function (cb) { cb.checked = false; });
                    if (selectAll) selectAll.checked = false;
                    syncBulkBar();
                });
            }

            if (bulkApply) {
                bulkApply.addEventListener('click', function () {
                    var action = bulkSelect ? bulkSelect.value : '';
                    if (!action) { showToast('Select an action', 'error'); return; }
                    var ids = [];
                    document.querySelectorAll('.dd-res-row-check:checked').forEach(function (cb) {
                        ids.push(cb.value);
                    });
                    if (ids.length === 0) return;
                    bulkApply.disabled = true;
                    fetch(ajaxurl, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body:    new URLSearchParams({
                            action:      'dd_res_bulk_action',
                            bulk_action: action,
                            ids:         ids.join(','),
                            nonce:       nonce
                        })
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            showToast(data.data, 'success');
                            setTimeout(function () { location.reload(); }, 800);
                        } else {
                            showToast(data.data || 'Error', 'error');
                            bulkApply.disabled = false;
                        }
                    })
                    .catch(function () {
                        showToast('Network error', 'error');
                        bulkApply.disabled = false;
                    });
                });
            }

            // ── Accept modal — reuses this same IIFE's `nonce`/`showToast` ──────
            var resModal        = document.getElementById('dd-res-modal');
            var resModalActions = document.getElementById('dd-res-modal-actions');
            var resModalLoading = document.getElementById('dd-res-modal-loading');

            var RES_STATUS_LABELS = {
                pending: 'Pending', confirmed: 'Confirmed', cancelled: 'Cancelled',
                no_show: 'No-show', pending_payment: 'Awaiting Payment', auto_cancelled: 'Auto-Cancelled'
            };
            var RES_DEPOSIT_LABELS = {
                none: '—', pending: '⏳ Awaiting', claimed: '🙋 Claimed (unverified)',
                paid: '✅ Paid', failed: '✗ Failed', refunded: '↩ Refunded'
            };

            document.querySelectorAll('.dd-res-open-modal-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    openResModal(this.dataset.id);
                });
            });

            // Whole row is clickable too (mirrors orders' .dd-order-row pattern).
            // Clicking the View button itself only fires the button's own listener
            // above — its <td> has onclick="event.stopPropagation()" (matching the
            // checkbox and WhatsApp cells), so this row listener only fires for
            // clicks elsewhere on the row.
            document.querySelectorAll('.dd-res-row').forEach(function (row) {
                row.addEventListener('click', function () {
                    var id = this.dataset.reservationId;
                    if (!id) return;
                    openResModal(id);
                });
            });

            document.getElementById('dd-res-modal-close').addEventListener('click', closeResModal);
            resModal.addEventListener('click', function (e) {
                if (e.target === resModal) closeResModal();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                var lightbox = document.getElementById('dd-res-proof-lightbox');
                if (lightbox && lightbox.style.display !== 'none') {
                    closeProofLightbox();
                } else if (resModal.style.display !== 'none') {
                    closeResModal();
                }
            });

            function openResModal(id) {
                resModal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
                fetchReservation(id);
            }

            function closeResModal() {
                resModal.style.display = 'none';
                document.body.style.overflow = '';
            }

            // ── Payment-proof lightbox — reuses the .dd-modal-overlay pattern
            // (same CSS class the accept modal itself uses, already loaded via
            // admin.css) rather than a new library. Lazily created on first use,
            // appended to <body> so it always paints above the accept modal.
            function openProofLightbox(url) {
                var lb = document.getElementById('dd-res-proof-lightbox');
                if (!lb) {
                    lb = document.createElement('div');
                    lb.id = 'dd-res-proof-lightbox';
                    lb.className = 'dd-modal-overlay';
                    lb.style.display = 'none';
                    lb.innerHTML =
                        '<img id="dd-res-proof-lightbox-img" alt="Payment proof screenshot" '
                        + 'style="max-width:90vw;max-height:90vh;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.4);">'
                        + '<button type="button" id="dd-res-proof-lightbox-close" aria-label="Close" '
                        + 'style="position:absolute;top:20px;right:20px;background:rgba(0,0,0,0.6);color:#fff;'
                        + 'border:none;border-radius:50%;width:40px;height:40px;font-size:18px;line-height:1;cursor:pointer;">✕</button>';
                    document.body.appendChild(lb);
                    lb.addEventListener('click', function (e) {
                        if (e.target === lb || e.target.id === 'dd-res-proof-lightbox-close') closeProofLightbox();
                    });
                }
                document.getElementById('dd-res-proof-lightbox-img').src = url;
                lb.style.display = 'flex';
            }

            function closeProofLightbox() {
                var lb = document.getElementById('dd-res-proof-lightbox');
                if (lb) lb.style.display = 'none';
            }

            function setResLoading(on) {
                resModalLoading.style.display = on ? 'flex' : 'none';
            }

            function ucfirstRes(s) {
                return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
            }

            function escRes(s) {
                var d = document.createElement('div');
                d.textContent = s;
                return d.innerHTML;
            }

            function fetchReservation(id) {
                setResLoading(true);
                fetch(ajaxurl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    new URLSearchParams({ action: 'dd_reservation_get', id: id, nonce: nonce })
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    setResLoading(false);
                    if (res.success) {
                        renderResModal(res.data.reservation);
                    } else {
                        showToast((res.data && res.data.message) || 'Could not load reservation', 'error');
                        closeResModal();
                    }
                })
                .catch(function () {
                    setResLoading(false);
                    showToast('Network error — please try again', 'error');
                    closeResModal();
                });
            }

            function renderResModal(r) {
                resModal.querySelector('.dd-res-modal-ref').textContent  = r.booking_ref;
                resModal.querySelector('.dd-res-modal-date').textContent = r.date + ' · ' + r.time + ' (' + ucfirstRes(r.session) + ')';

                resModal.querySelector('.dd-res-modal-name').textContent     = r.name;
                resModal.querySelector('.dd-res-modal-whatsapp').textContent = r.whatsapp;

                var detailsHtml = '<div class="dd-modal-total-row"><span>Guests</span><strong>' + r.guests + '</strong></div>';
                if (r.special_requests) {
                    detailsHtml += '<div class="dd-modal-total-row"><span>Requests</span><span>' + escRes(r.special_requests) + '</span></div>';
                }
                resModal.querySelector('.dd-res-modal-details').innerHTML = detailsHtml;

                var badgeMod   = r.status;
                var badgeLabel = RES_STATUS_LABELS[r.status] || r.status;
                if (r.status === 'confirmed' && Number(r.deposit_required) === 1 && r.deposit_status !== 'paid') {
                    badgeMod   = 'pending';
                    badgeLabel = 'Confirmed — deposit unpaid';
                }
                resModal.querySelector('.dd-res-modal-status-badge').innerHTML =
                    '<span class="dd-res-badge dd-res-badge--' + badgeMod + '">' + badgeLabel + '</span>';

                var depositSection = resModal.querySelector('.dd-res-modal-deposit-section');
                if (Number(r.deposit_required) === 1) {
                    depositSection.style.display = '';
                    var depositInfoHtml =
                        '<div class="dd-modal-total-row"><span>' + (RES_DEPOSIT_LABELS[r.deposit_status] || r.deposit_status) + '</span>'
                        + '<strong>' + Number(r.deposit_amount).toLocaleString('en-US') + ' RWF</strong></div>';
                    // Customer-attached MoMo payment screenshot (v3.14.2) — optional,
                    // shown only when present so staff can visually verify before
                    // clicking "Mark deposit paid". No proof → no change from before.
                    if (r.deposit_proof_url) {
                        depositInfoHtml +=
                            '<div style="margin-top:10px;">'
                            + '<div class="dd-modal-label" style="margin-bottom:6px;">PAYMENT PROOF</div>'
                            + '<img src="' + r.deposit_proof_url + '" alt="Payment proof screenshot — click to enlarge" '
                            + 'class="dd-res-proof-thumb" '
                            + 'style="max-width:100%;border-radius:8px;border:1px solid #e5e7eb;display:block;cursor:zoom-in;">'
                            + '</div>';
                    }
                    resModal.querySelector('.dd-res-modal-deposit-info').innerHTML = depositInfoHtml;

                    // Click-to-zoom (Part A) — same overlay pattern as the modal itself.
                    var proofThumb = resModal.querySelector('.dd-res-proof-thumb');
                    if (proofThumb) {
                        proofThumb.addEventListener('click', function () {
                            openProofLightbox(proofThumb.src);
                        });
                    }
                } else {
                    depositSection.style.display = 'none';
                }

                // Footer actions — Cancel/No-show/Mark-deposit-paid/WhatsApp Notify
                // moved in from the row (v3.14.6), same rebuild-fresh-every-render
                // pattern already proven here for Confirm/PesaPal.
                var actionsHtml = '';
                if (r.status !== 'confirmed') {
                    actionsHtml += '<button class="dd-btn dd-btn-primary dd-res-modal-confirm-btn" data-id="' + r.id + '">✓ Confirm</button>';
                }
                if (r.status !== 'cancelled') {
                    actionsHtml += '<button class="dd-btn dd-res-action-btn--cancel dd-res-modal-cancel-btn" data-id="' + r.id + '">✗ Cancel</button>';
                }
                if (r.status !== 'no_show') {
                    actionsHtml += '<button class="dd-btn dd-res-action-btn--noshow dd-res-modal-noshow-btn" data-id="' + r.id + '">No-show</button>';
                }
                if (Number(r.deposit_required) === 1 && ( r.deposit_status === 'pending' || r.deposit_status === 'claimed' )) {
                    actionsHtml += '<button class="dd-btn dd-res-action-btn--deposit dd-res-modal-deposit-paid-btn" data-id="' + r.id + '">✅ Mark deposit paid</button>';
                }
                if (Number(r.deposit_required) === 1 && r.deposit_status !== 'paid') {
                    if (r.pesapal_tracking_id) {
                        actionsHtml += '<span style="font-size:12px;color:#6b7280;padding:8px 4px;">PesaPal payment already requested — awaiting customer.</span>';
                    } else {
                        actionsHtml += '<button class="dd-btn dd-res-action-btn--deposit dd-res-modal-pesapal-btn" data-id="' + r.id + '">🏦 Request PesaPal Payment</button>';
                    }
                }
                var waInfo = buildResWhatsAppLink(r);
                if (waInfo) {
                    actionsHtml += '<a href="' + waInfo.url + '" target="_blank" rel="noopener noreferrer" class="dd-res-wa-btn">' + waInfo.label + '</a>';
                }
                resModalActions.innerHTML = actionsHtml;

                var confirmBtn = resModalActions.querySelector('.dd-res-modal-confirm-btn');
                if (confirmBtn) {
                    confirmBtn.addEventListener('click', function () {
                        updateStatusFromModal(r.id, 'confirmed', 'Reservation confirmed');
                    });
                }
                var cancelBtn = resModalActions.querySelector('.dd-res-modal-cancel-btn');
                if (cancelBtn) {
                    cancelBtn.addEventListener('click', function () {
                        if (!confirm('Cancel this reservation?')) return;
                        updateStatusFromModal(r.id, 'cancelled', 'Reservation cancelled');
                    });
                }
                var noshowBtn = resModalActions.querySelector('.dd-res-modal-noshow-btn');
                if (noshowBtn) {
                    noshowBtn.addEventListener('click', function () {
                        updateStatusFromModal(r.id, 'no_show', 'Marked as no-show');
                    });
                }
                var depositPaidBtn = resModalActions.querySelector('.dd-res-modal-deposit-paid-btn');
                if (depositPaidBtn) {
                    depositPaidBtn.addEventListener('click', function () {
                        markDepositPaidFromModal(r.id, depositPaidBtn);
                    });
                }
                var pesapalBtn = resModalActions.querySelector('.dd-res-modal-pesapal-btn');
                if (pesapalBtn) {
                    pesapalBtn.addEventListener('click', function () {
                        requestPesapalDeposit(r.id, pesapalBtn);
                    });
                }
            }

            // ── WhatsApp notify message — ported verbatim from the row's old PHP
            // $lines cascade (v3.14.6). Text content unchanged; only the runtime
            // moved from PHP (render time) to JS (modal render time). Needs the
            // restaurant name / admin phone client-side since those aren't part
            // of the reservation row itself — see resRestaurantName/resAdminPhone.
            function formatResDate(dateStr) {
                var d = new Date(dateStr + 'T00:00:00');
                if (isNaN(d.getTime())) return dateStr;
                var days   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                var dd     = String(d.getDate()).padStart(2, '0');
                return days[d.getDay()] + ', ' + dd + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
            }

            function buildResWhatsAppLink(r) {
                var waNum = (r.whatsapp || '').replace(/\D/g, '');
                if (!waNum) return null;

                var dateFmt    = formatResDate(r.date);
                var guestWord  = (Number(r.guests) === 1) ? 'guest' : 'guests';
                var sessionFmt = ucfirstRes(r.session);
                var lines      = [];

                if (r.status === 'confirmed' && Number(r.deposit_required) === 1 && r.deposit_status !== 'paid') {
                    lines.push('RESERVATION HELD — DEPOSIT PENDING ⏳', resRestaurantName, '',
                        'Hi ' + r.name + ", we've reserved your table — it's held pending your deposit.", '',
                        'Ref: ' + r.booking_ref, 'Date: ' + dateFmt, 'Time: ' + r.time + ' (' + sessionFmt + ')',
                        'Guests: ' + r.guests + ' ' + guestWord, '',
                        'Deposit required: ' + Number(r.deposit_amount).toLocaleString('en-US') + ' RWF',
                        'Your booking is secured once we receive it. Until then, the table may be released.');
                    if (resAdminPhone) lines.push('', 'Questions? Call us: ' + resAdminPhone);
                } else if (r.status === 'confirmed') {
                    lines.push('RESERVATION CONFIRMED ✅', resRestaurantName, '',
                        'Hi ' + r.name + ', your table is booked! 🎉', '',
                        'Ref: ' + r.booking_ref, 'Date: ' + dateFmt, 'Time: ' + r.time + ' (' + sessionFmt + ')',
                        'Guests: ' + r.guests + ' ' + guestWord, '',
                        'We look forward to welcoming you! 🍽️');
                    if (resAdminPhone) lines.push('', 'Need to change anything? Call us: ' + resAdminPhone);
                } else if (r.status === 'cancelled') {
                    lines.push('RESERVATION CANCELLED ❌', resRestaurantName, '',
                        'Hi ' + r.name + ', your reservation has been cancelled.', '',
                        'Ref: ' + r.booking_ref, 'Date: ' + dateFmt, 'Time: ' + r.time + ' (' + sessionFmt + ')', '',
                        "We're sorry for any inconvenience.",
                        "We'd love to host you another time — book again whenever you're ready. 🙏");
                    if (resAdminPhone) lines.push('', 'Questions? Call us: ' + resAdminPhone);
                } else if (r.status === 'no_show') {
                    lines.push('WE MISSED YOU 😔', resRestaurantName, '',
                        'Hi ' + r.name + ", we had your table ready but didn't see you.", '',
                        'Ref: ' + r.booking_ref, 'Date: ' + dateFmt, 'Time: ' + r.time + ' (' + sessionFmt + ')', '',
                        'We hope everything is okay.',
                        "You're always welcome — book again anytime. 🍽️");
                    if (resAdminPhone) lines.push('', 'Call us: ' + resAdminPhone);
                }

                if (!lines.length) return null;

                var waLabels = { confirmed: '💬 Send Confirmation', cancelled: '💬 Send Cancellation', no_show: '💬 Send Follow-up' };
                return {
                    url:   'https://wa.me/' + waNum + '?text=' + encodeURIComponent(lines.join('\n')),
                    label: waLabels[r.status] || '💬 Notify'
                };
            }

            function updateStatusFromModal(id, status, successMsg) {
                setResLoading(true);
                fetch(ajaxurl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    new URLSearchParams({ action: 'dd_reservation_update_status', id: id, status: status, nonce: nonce })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    setResLoading(false);
                    if (data.success) {
                        showToast(successMsg, 'success');
                        setTimeout(function () { location.reload(); }, 800);
                    } else {
                        showToast((data.data && data.data.message) || 'Error updating status', 'error');
                    }
                })
                .catch(function () {
                    setResLoading(false);
                    showToast('Network error — please try again', 'error');
                });
            }

            function markDepositPaidFromModal(id, btn) {
                if (btn) { btn.disabled = true; btn.textContent = 'Marking…'; }
                fetch(ajaxurl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    new URLSearchParams({ action: 'dd_reservation_mark_deposit_paid', id: id, nonce: nonce })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        showToast('Deposit marked paid', 'success');
                        setTimeout(function () { location.reload(); }, 800);
                    } else {
                        showToast((data.data && data.data.message) || 'Error marking deposit paid', 'error');
                        if (btn) { btn.disabled = false; btn.textContent = '✅ Mark deposit paid'; }
                    }
                })
                .catch(function () {
                    showToast('Network error — please try again', 'error');
                    if (btn) { btn.disabled = false; btn.textContent = '✅ Mark deposit paid'; }
                });
            }

            function requestPesapalDeposit(id, btn) {
                btn.disabled = true;
                btn.textContent = 'Requesting…';
                fetch(ajaxurl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    new URLSearchParams({ action: 'dd_reservation_pesapal_request', id: id, nonce: nonce })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        showToast('Payment link created', 'success');
                        if (data.data.whatsapp_url) {
                            var link = document.createElement('a');
                            link.href = data.data.whatsapp_url;
                            link.target = '_blank';
                            link.className = 'dd-btn dd-btn-whatsapp';
                            link.textContent = '💬 Send Payment Link on WhatsApp';
                            btn.replaceWith(link);
                        } else {
                            btn.textContent = '✓ Payment link created (no WhatsApp number on file)';
                        }
                    } else {
                        showToast((data.data && data.data.message) || 'Error requesting payment', 'error');
                        btn.disabled = false;
                        btn.textContent = '🏦 Request PesaPal Payment';
                    }
                })
                .catch(function () {
                    showToast('Network error — please try again', 'error');
                    btn.disabled = false;
                    btn.textContent = '🏦 Request PesaPal Payment';
                });
            }
        })();
        </script>

        <?php
    }
}
