<?php
/**
 * File:    modules/customers/class-dd-customers-module.php
 * Module:  DD_Customers_Module (extends DD_Module)
 * Purpose: Customer CRM dashboard — lists all customers from the
 *          dishdash_customers table with spend data, order count,
 *          and tier status (New / Regular / VIP / Champion / Diamond).
 *
 * Dependencies (this file needs):
 *   - DD_Module base class
 *   - WordPress: global $wpdb
 *   - {prefix}dishdash_customers table (read-only)
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php (instantiates this module)
 *
 * Hooks registered:
 *   - admin_menu, admin_enqueue_scripts
 *   - wp_ajax_dd_save_customer (admin only)
 *
 * AJAX actions registered:
 *   dd_save_customer (admin only — updates dd_phone, dd_address, dd_birthday)
 *
 * Admin page: dish-dash-customers
 *
 * Customer tiers (RWF thresholds):
 *   New (0 orders), Regular (<100K), VIP (≥100K), Champion (≥250K), Diamond (≥500K)
 *
 * Depends on (modules): NONE — architecture rule
 *
 * Last modified: v3.5.03
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( class_exists( 'DD_Customers_Module' ) ) return;

class DD_Customers_Module extends DD_Module {

    protected string $id = 'customers';

    const TIER_DIAMOND  = 500000;
    const TIER_CHAMPION = 250000;
    const TIER_VIP      = 100000;

    public function init(): void {
        add_action( 'admin_menu',               [ $this, 'register_admin_page' ] );
        add_action( 'admin_enqueue_scripts',    [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_dd_save_customer', [ $this, 'ajax_save_customer' ] );
    }

    // ─────────────────────────────────────────
    //  ADMIN MENU
    // ─────────────────────────────────────────
    public function register_admin_page(): void {
        add_submenu_page(
            'dish-dash',
            __( 'Customers', 'dish-dash' ),
            __( '👥 Customers', 'dish-dash' ),
            'manage_options',
            'dish-dash-customers',
            [ $this, 'render_admin_page' ]
        );
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'dish-dash-customers' ) === false ) return;
        wp_add_inline_style( 'dish-dash-admin', $this->admin_css() );
    }

    // ─────────────────────────────────────────
    //  TIER HELPER
    // ─────────────────────────────────────────
    private function get_tier( float $spent, int $orders ): array {
        if ( $orders === 0 )                return [ 'New',      '🌱', 'new' ];
        if ( $spent >= self::TIER_DIAMOND ) return [ 'Diamond',  '💎', 'diamond' ];
        if ( $spent >= self::TIER_CHAMPION )return [ 'Champion', '🏆', 'champion' ];
        if ( $spent >= self::TIER_VIP )     return [ 'VIP',      '👑', 'vip' ];
        return                                     [ 'Regular',  '🧡', 'regular' ];
    }

    // ─────────────────────────────────────────
    //  RENDER
    // ─────────────────────────────────────────
    public function render_admin_page(): void {
        if ( isset( $_GET['export'] ) && $_GET['export'] === 'csv' ) {
            $this->export_csv();
            exit;
        }
        $this->render_page();
    }

    private function render_page(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_customers';

        $search      = sanitize_text_field( $_GET['s']    ?? '' );
        $date_from   = sanitize_text_field( $_GET['from'] ?? '' );
        $date_to     = sanitize_text_field( $_GET['to']   ?? '' );
        $tier_filter = sanitize_text_field( $_GET['tier'] ?? '' );
        $per_page_options = [ 25, 50, 75 ];
        $per_page_raw     = isset( $_GET['per_page'] ) ? (int) $_GET['per_page'] : 25;
        $per_page         = in_array( $per_page_raw, array_merge( $per_page_options, [ 99999 ] ), true )
                            ? $per_page_raw : 25;
        $page             = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
        $offset           = ( $page - 1 ) * $per_page;
        $active_tab       = ( isset( $_GET['tab'] ) && $_GET['tab'] === 'reservations' ) ? 'reservations' : 'orders';

        // ── Stats ──────────────────────────────────────
        $res_table = $wpdb->prefix . 'dishdash_reservations';

        if ( $active_tab === 'orders' ) {

            $stats = $wpdb->get_row(
                "SELECT
                    COUNT(*)                        AS total_customers,
                    COALESCE(SUM(total_spent), 0)   AS total_revenue,
                    COALESCE(AVG(total_spent), 0)   AS avg_spend
                 FROM {$table}
                 WHERE total_orders > 0"
            );

            $new_this_month = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table}
                 WHERE total_orders > 0
                 AND MONTH(created_at) = MONTH(NOW())
                 AND YEAR(created_at) = YEAR(NOW())"
            );

        } else {

            $stats = $wpdb->get_row(
                "SELECT
                    COUNT(DISTINCT c.id)                                        AS total_customers,
                    COUNT(r.id)                                                 AS total_reservations,
                    SUM(r.date >= CURDATE() AND r.status != 'cancelled')        AS upcoming,
                    COALESCE(AVG(r.guests), 0)                                  AS avg_party
                 FROM {$table} c
                 LEFT JOIN {$res_table} r ON r.customer_id = c.id
                 WHERE c.total_orders = 0 AND r.id IS NOT NULL"
            );

            $new_this_month = null; // not used on reservations tab

        }

        // ── WHERE clause ───────────────────────────────
        $where  = 'WHERE 1=1';
        $params = [];

        if ( $search ) {
            $where   .= ' AND (c.name LIKE %s OR c.whatsapp LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ( $date_from ) {
            $where   .= ' AND DATE(c.created_at) >= %s';
            $params[] = $date_from;
        }
        if ( $date_to ) {
            $where   .= ' AND DATE(c.created_at) <= %s';
            $params[] = $date_to;
        }
        if ( $tier_filter ) {
            switch ( $tier_filter ) {
                case 'new':      $where .= ' AND c.total_orders = 0'; break;
                case 'regular':  $where .= ' AND c.total_orders > 0 AND c.total_spent < 100000'; break;
                case 'vip':      $where .= ' AND c.total_spent >= 100000 AND c.total_spent < 250000'; break;
                case 'champion': $where .= ' AND c.total_spent >= 250000 AND c.total_spent < 500000'; break;
                case 'diamond':  $where .= ' AND c.total_spent >= 500000'; break;
            }
        }

        // ── Tab filter ─────────────────────────────────
        if ( $active_tab === 'orders' ) {
            $where_tab   = 'AND c.total_orders > 0';
            $from_clause = "{$table} c";
        } else {
            $where_tab   = 'AND r.id IS NOT NULL AND c.total_orders = 0';
            $from_clause = "{$table} c
                LEFT JOIN {$wpdb->prefix}dishdash_reservations r ON r.customer_id = c.id";
        }

        // ── Rows ───────────────────────────────────────
        $row_params = array_merge( $params, [ $per_page, $offset ] );
        $customers  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT c.* FROM {$from_clause} {$where} {$where_tab}
                 ORDER BY c.total_spent DESC, c.created_at DESC LIMIT %d OFFSET %d",
                ...$row_params
            )
        );

        if ( $params ) {
            $total_rows = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT c.id) FROM {$from_clause} {$where} {$where_tab}",
                    ...$params
                )
            );
        } else {
            $total_rows = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT c.id) FROM {$from_clause} {$where} {$where_tab}"
            );
        }

        $export_url = add_query_arg(
            [ 'page' => 'dish-dash-customers', 'export' => 'csv' ],
            admin_url( 'admin.php' )
        );
        ?>
        <div class="wrap dd-admin-wrap">
        <div class="dd-page-wrap">

            <div class="dd-page-header">
                <div>
                    <h1 class="dd-page-title">Customers</h1>
                    <p class="dd-page-subtitle">Know your guests, grow your restaurant</p>
                </div>
                <div class="dd-page-header-actions">
                    <a href="<?php echo esc_url( $export_url ); ?>" class="dd-btn dd-btn-secondary">
                        ⬇ Export CSV
                    </a>
                </div>
            </div>

            <!-- Tabs -->
            <?php
            $tab_base = add_query_arg( array_filter( [
                's'        => $_GET['s']        ?? '',
                'from'     => $_GET['from']     ?? '',
                'to'       => $_GET['to']       ?? '',
                'per_page' => $per_page !== 25 ? $per_page : '',
            ] ), admin_url( 'admin.php?page=dish-dash-customers' ) );

            $orders_url       = add_query_arg( [ 'tab' => 'orders',       'paged' => 1 ], $tab_base );
            $reservations_url = add_query_arg( [ 'tab' => 'reservations', 'paged' => 1 ], $tab_base );
            ?>
            <div class="dd-tabs">
                <a href="<?php echo esc_url( $orders_url ); ?>"
                   class="dd-tab<?php echo $active_tab === 'orders' ? ' dd-tab--active' : ''; ?>">
                    🛒 Ordering Customers
                </a>
                <a href="<?php echo esc_url( $reservations_url ); ?>"
                   class="dd-tab<?php echo $active_tab === 'reservations' ? ' dd-tab--active' : ''; ?>">
                    🪑 Reservation Guests
                </a>
            </div>

            <!-- KPI Cards (per-tab) -->
            <?php if ( $active_tab === 'orders' ) : ?>
            <div class="dd-kpi-grid dd-kpi-grid--4">
                <div class="dd-kpi-card">
                    <div class="dd-kpi-label">Ordering Customers</div>
                    <div class="dd-kpi-value"><?php echo number_format( (int) $stats->total_customers ); ?></div>
                </div>
                <div class="dd-kpi-card">
                    <div class="dd-kpi-label">Total Revenue</div>
                    <div class="dd-kpi-value">RWF <?php echo number_format( (float) $stats->total_revenue ); ?></div>
                </div>
                <div class="dd-kpi-card">
                    <div class="dd-kpi-label">Avg Spend / Customer</div>
                    <div class="dd-kpi-value">RWF <?php echo number_format( (float) $stats->avg_spend ); ?></div>
                </div>
                <div class="dd-kpi-card">
                    <div class="dd-kpi-label">New This Month</div>
                    <div class="dd-kpi-value"><?php echo number_format( $new_this_month ); ?></div>
                </div>
            </div>
            <?php else : ?>
            <div class="dd-kpi-grid dd-kpi-grid--4">
                <div class="dd-kpi-card">
                    <div class="dd-kpi-label">Reservation Guests</div>
                    <div class="dd-kpi-value"><?php echo number_format( (int) $stats->total_customers ); ?></div>
                </div>
                <div class="dd-kpi-card">
                    <div class="dd-kpi-label">Total Reservations</div>
                    <div class="dd-kpi-value"><?php echo number_format( (int) $stats->total_reservations ); ?></div>
                </div>
                <div class="dd-kpi-card">
                    <div class="dd-kpi-label">Upcoming</div>
                    <div class="dd-kpi-value"><?php echo number_format( (int) $stats->upcoming ); ?></div>
                </div>
                <div class="dd-kpi-card">
                    <div class="dd-kpi-label">Avg Party Size</div>
                    <div class="dd-kpi-value"><?php echo number_format( (float) $stats->avg_party, 1 ); ?></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tier Breakdown (clickable filter chips — orders tab only) -->
            <?php if ( $active_tab === 'orders' ) : ?>
            <div class="dd-card dd-card--tiers">
                <div class="dd-card-label">Customer Tiers</div>
                <div class="dd-cust-tiers-row">
                    <?php
                    $tier_defs = [
                        [ 'slug' => 'new',      'icon' => '🌱', 'label' => __( 'New',      'dish-dash' ), 'count' => (int) $stats->tier_new ],
                        [ 'slug' => 'regular',  'icon' => '🧡', 'label' => __( 'Regular',  'dish-dash' ), 'count' => (int) $stats->tier_regular ],
                        [ 'slug' => 'vip',      'icon' => '👑', 'label' => __( 'VIP',      'dish-dash' ), 'count' => (int) $stats->tier_vip ],
                        [ 'slug' => 'champion', 'icon' => '🏆', 'label' => __( 'Champion', 'dish-dash' ), 'count' => (int) $stats->tier_champion ],
                        [ 'slug' => 'diamond',  'icon' => '💎', 'label' => __( 'Diamond',  'dish-dash' ), 'count' => (int) $stats->tier_diamond ],
                    ];
                    foreach ( $tier_defs as $t ) :
                        $is_active = $tier_filter === $t['slug'];
                        $href = add_query_arg(
                            [ 'page' => 'dish-dash-customers', 'tier' => $t['slug'] ],
                            admin_url( 'admin.php' )
                        );
                    ?>
                    <a href="<?php echo esc_url( $href ); ?>"
                       class="dd-cust-tier-chip<?php echo $is_active ? ' dd-cust-tier-chip--active' : ''; ?>">
                        <span class="dd-tier-badge dd-tier-badge--<?php echo esc_attr( $t['slug'] ); ?>">
                            <?php echo $t['icon'] . ' ' . esc_html( $t['label'] ); ?>
                        </span>
                        <span class="dd-cust-tier-count"><?php echo number_format( $t['count'] ); ?></span>
                    </a>
                    <?php endforeach; ?>
                    <?php if ( $tier_filter ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-customers' ) ); ?>"
                       class="dd-cust-tier-clear">✕ <?php esc_html_e( 'Clear filter', 'dish-dash' ); ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="dd-card dd-card--filters">
                <form method="get" class="dd-filters-row">
                    <input type="hidden" name="page" value="dish-dash-customers">
                    <?php if ( $tier_filter ) : ?>
                    <input type="hidden" name="tier" value="<?php echo esc_attr( $tier_filter ); ?>">
                    <?php endif; ?>
                    <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>"
                           placeholder="<?php esc_attr_e( 'Search name or WhatsApp…', 'dish-dash' ); ?>"
                           class="dd-cust-search">
                    <label class="dd-cust-date-label">
                        <?php esc_html_e( 'From', 'dish-dash' ); ?>
                        <input type="date" name="from" value="<?php echo esc_attr( $date_from ); ?>">
                    </label>
                    <label class="dd-cust-date-label">
                        <?php esc_html_e( 'To', 'dish-dash' ); ?>
                        <input type="date" name="to" value="<?php echo esc_attr( $date_to ); ?>">
                    </label>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'dish-dash' ); ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-customers' ) ); ?>"
                       class="button"><?php esc_html_e( 'Reset', 'dish-dash' ); ?></a>
                </form>
            </div>

            <!-- Table -->
            <div class="dd-table-controls">
                <div class="dd-per-page">
                    <?php
                    $base_url = add_query_arg( array_filter( [
                        's'    => $_GET['s']    ?? '',
                        'from' => $_GET['from'] ?? '',
                        'to'   => $_GET['to']   ?? '',
                        'tier' => $_GET['tier'] ?? '',
                        'tab'  => $active_tab,
                    ] ), admin_url( 'admin.php?page=dish-dash-customers' ) );

                    foreach ( [ 25, 50, 75 ] as $opt ) :
                        $url    = add_query_arg( [ 'per_page' => $opt, 'paged' => 1 ], $base_url );
                        $active = ( $per_page === $opt ) ? ' active' : '';
                        echo '<a href="' . esc_url( $url ) . '" class="dd-per-page-btn' . $active . '">' . $opt . '</a>';
                    endforeach;

                    $url_all    = add_query_arg( [ 'per_page' => 99999, 'paged' => 1 ], $base_url );
                    $active_all = ( $per_page === 99999 ) ? ' active' : '';
                    echo '<a href="' . esc_url( $url_all ) . '" class="dd-per-page-btn' . $active_all . '">All</a>';
                    ?>
                </div>
                <div class="dd-table-info">
                    Showing <?php echo number_format( min( $offset + 1, $total_rows ) ); ?>–<?php echo number_format( min( $offset + $per_page, $total_rows ) ); ?> of <?php echo number_format( $total_rows ); ?> customers
                </div>
            </div>

            <div class="dd-card dd-card--table">
                <?php if ( empty( $customers ) ) : ?>
                <div class="dd-coming-soon">
                    <span>👥</span>
                    <h2><?php esc_html_e( 'No customers found', 'dish-dash' ); ?></h2>
                    <p><?php esc_html_e( 'Customer records are created automatically when orders are placed.', 'dish-dash' ); ?></p>
                </div>
                <?php else : ?>
                <table class="dd-cust-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Customer', 'dish-dash' ); ?></th>
                            <th><?php esc_html_e( 'WhatsApp', 'dish-dash' ); ?></th>
                            <th><?php esc_html_e( 'Birthday', 'dish-dash' ); ?></th>
                            <th><?php esc_html_e( 'Orders', 'dish-dash' ); ?></th>
                            <th><?php esc_html_e( 'Total Spend', 'dish-dash' ); ?></th>
                            <th><?php esc_html_e( 'Tier', 'dish-dash' ); ?></th>
                            <th><?php esc_html_e( 'Joined', 'dish-dash' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $customers as $c ) :
                        [ $tier_label, $tier_icon, $tier_slug ] = $this->get_tier(
                            (float) $c->total_spent,
                            (int)   $c->total_orders
                        );
                        $birthday = $c->birthday
                            ? date_i18n( 'M j', strtotime( $c->birthday ) )
                            : '—';
                        $address = $c->delivery_address
                            ? wp_trim_words( $c->delivery_address, 6, '…' )
                            : '';
                        $initial = mb_strtoupper( mb_substr( trim( $c->name ), 0, 1 ) );
                    ?>
                    <tr>
                        <td>
                            <div class="dd-cust-name-cell">
                                <div class="dd-cust-avatar"><?php echo esc_html( $initial ); ?></div>
                                <div>
                                    <strong><?php echo esc_html( $c->name ); ?></strong>
                                    <?php if ( $address ) : ?>
                                    <small><?php echo esc_html( $address ); ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="dd-cust-phone"><?php echo esc_html( $c->whatsapp ); ?></td>
                        <td><?php echo esc_html( $birthday ); ?></td>
                        <td class="dd-cust-orders"><?php echo (int) $c->total_orders; ?></td>
                        <td class="dd-cust-spend">RWF <?php echo number_format( (float) $c->total_spent ); ?></td>
                        <td>
                            <span class="dd-tier-badge dd-tier-badge--<?php echo esc_attr( $tier_slug ); ?>">
                                <?php echo $tier_icon . ' ' . esc_html( $tier_label ); ?>
                            </span>
                        </td>
                        <td class="dd-cust-joined"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $c->created_at ) ) ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ( $total_rows > $per_page ) :
                $total_pages = ceil( $total_rows / $per_page );
            ?>
            <div class="dd-cust-pagination">
                <?php
                echo paginate_links( [
                    'base'      => add_query_arg( [ 'paged' => '%#%', 'per_page' => $per_page, 'tab' => $active_tab ] ),
                    'format'    => '',
                    'total'     => $total_pages,
                    'current'   => $page,
                    'prev_text' => '&larr; ' . __( 'Prev', 'dish-dash' ),
                    'next_text' => __( 'Next', 'dish-dash' ) . ' &rarr;',
                ] );
                ?>
            </div>
            <?php endif; ?>

        </div><!-- /dd-page-wrap -->
        </div><!-- /wrap dd-admin-wrap -->
        <?php
    }

    // ─────────────────────────────────────────
    //  CSV EXPORT
    // ─────────────────────────────────────────
    private function export_csv(): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT name, whatsapp, delivery_address, total_orders, total_spent, birthday, created_at
             FROM {$wpdb->prefix}dishdash_customers
             ORDER BY total_spent DESC",
            ARRAY_A
        );

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="customers-' . date( 'Y-m-d' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'Name', 'WhatsApp', 'Address', 'Orders', 'Total Spent (RWF)', 'Tier', 'Birthday', 'Joined' ] );
        foreach ( $rows as $row ) {
            [ $tier_label ] = $this->get_tier( (float) $row['total_spent'], (int) $row['total_orders'] );
            $row['birthday']   = $row['birthday']   ? date( 'M j', strtotime( $row['birthday'] ) )    : '';
            $row['created_at'] = $row['created_at'] ? date( 'Y-m-d', strtotime( $row['created_at'] ) ) : '';
            fputcsv( $out, [
                $row['name'],
                $row['whatsapp'],
                $row['delivery_address'],
                $row['total_orders'],
                $row['total_spent'],
                $tier_label,
                $row['birthday'],
                $row['created_at'],
            ] );
        }
        fclose( $out );
    }

    // ─────────────────────────────────────────
    //  SAVE CUSTOMER META (AJAX)
    // ─────────────────────────────────────────
    public function ajax_save_customer(): void {
        check_ajax_referer( 'dd_save_customer', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $user_id  = (int) ( $_POST['user_id'] ?? 0 );
        $phone    = sanitize_text_field( $_POST['phone']    ?? '' );
        $address  = sanitize_text_field( $_POST['address']  ?? '' );
        $birthday = sanitize_text_field( $_POST['birthday'] ?? '' );

        if ( ! $user_id ) wp_send_json_error( 'Invalid user.' );

        update_user_meta( $user_id, 'dd_phone',    $phone );
        update_user_meta( $user_id, 'dd_address',  $address );
        update_user_meta( $user_id, 'dd_birthday', $birthday );

        if ( $phone )   update_user_meta( $user_id, 'billing_phone',     $phone );
        if ( $address ) update_user_meta( $user_id, 'billing_address_1', $address );

        wp_send_json_success();
    }

    // ─────────────────────────────────────────
    //  ADMIN CSS
    // ─────────────────────────────────────────
    private function admin_css(): string {
        $brand = esc_attr( get_option( 'dish_dash_primary_color', '#65040d' ) );

        return "
        /* ── Customers page layout ── */
        .dd-page-wrap { max-width: 100%; }

        .dd-page-subtitle {
            font-size: 13px;
            color: #888;
            margin: 2px 0 0;
        }

        .dd-page-header-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        /* ── KPI grid (4-up) ── */
        .dd-kpi-grid--4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }

        @media (max-width: 900px) {
            .dd-kpi-grid--4 { grid-template-columns: repeat(2, 1fr); }
        }

        /* ── Cards ── */
        .dd-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 16px;
        }

        .dd-card-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #999;
            margin-bottom: 12px;
        }

        /* ── Tier chips ── */
        .dd-cust-tiers-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .dd-cust-tier-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            border: 2px solid transparent;
            cursor: pointer;
            text-decoration: none;
            color: #555;
            background: #f5f5f5;
            transition: border-color 0.15s;
        }

        .dd-cust-tier-chip--active {
            border-color: {$brand} !important;
            box-shadow: 0 0 0 3px color-mix(in srgb, {$brand} 12%, transparent) !important;
        }

        /* ── Filter bar ── */
        .dd-card--filters .dd-filters-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
        }

        /* ── Table ── */
        .dd-cust-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .dd-cust-table th {
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #999;
            padding: 8px 12px;
            border-bottom: 1px solid #e5e7eb;
        }

        .dd-cust-table td {
            padding: 12px 12px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .dd-cust-table tr:last-child td { border-bottom: none; }
        .dd-cust-table tr:hover td { background: #fafafa; }

        /* ── Status badges ── */
        .dd-cust-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .dd-cust-badge--new      { background: #e8f5e9; color: #2e7d32; }
        .dd-cust-badge--regular  { background: #e3f2fd; color: #1565c0; }
        .dd-cust-badge--vip      { background: #f3e5f5; color: #6a1b9a; }
        .dd-cust-badge--champion { background: #fff8e1; color: #e65100; }
        .dd-cust-badge--diamond  { background: #e8eaf6; color: #283593; }

        /* ── Pagination ── */
        .dd-cust-pagination {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 6px;
            padding-top: 12px;
            font-size: 13px;
            color: #666;
        }

        /* ── Tier badges (chips + table rows) ── */
        .dd-tier-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.2px;
            white-space: nowrap;
        }
        .dd-tier-badge--new      { background: #e8f5e9; color: #2e7d32; }
        .dd-tier-badge--regular  { background: #fff3e0; color: #e65100; }
        .dd-tier-badge--vip      { background: #f3e5f5; color: #7b1fa2; }
        .dd-tier-badge--champion { background: #fff8e1; color: #f57f17; }
        .dd-tier-badge--diamond  { background: #e0f7fa; color: #00838f; }

        /* ── Customer name cell ── */
        .dd-cust-name-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dd-cust-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: {$brand};
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .dd-cust-name-cell strong { display: block; line-height: 1.3; }
        .dd-cust-name-cell small  { display: block; color: #aaa; font-size: 11px; margin-top: 1px; }
        .dd-cust-spend  { font-weight: 700; color: #111; }
        .dd-cust-orders { font-weight: 600; color: #444; text-align: center; }
        .dd-cust-phone  { font-family: monospace; font-size: 12px; color: #555; }
        .dd-cust-joined { color: #888; font-size: 12px; }

        /* ── Tier chip count + clear ── */
        .dd-cust-tier-count {
            font-size: 17px;
            font-weight: 700;
            color: #111;
            line-height: 1;
        }
        .dd-cust-tier-clear {
            font-size: 12px;
            color: #aaa;
            text-decoration: none !important;
            padding: 6px 10px;
            border: 1px dashed #ddd;
            border-radius: 8px;
            transition: color 0.2s, border-color 0.2s;
        }
        .dd-cust-tier-clear:hover { color: #c00 !important; border-color: #c00; }

        /* ── Filter inputs ── */
        .dd-cust-search {
            min-width: 220px !important;
            border: 1.5px solid #e8e8e8 !important;
            border-radius: 8px !important;
            padding: 7px 12px !important;
            font-size: 13px !important;
        }
        .dd-cust-date-label {
            font-size: 13px;
            color: #666;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .dd-cust-date-label input {
            border: 1.5px solid #e8e8e8 !important;
            border-radius: 8px !important;
            padding: 6px 10px !important;
            font-size: 13px !important;
        }
        .dd-cust-count-note {
            margin-left: auto;
            font-size: 12px;
            color: #888;
        }

        /* ── Pagination links ── */
        .dd-cust-pagination .page-numbers {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border: 1.5px solid #e8e8e8;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #444;
            text-decoration: none;
            background: #fff;
            transition: border-color 0.15s, color 0.15s;
        }
        .dd-cust-pagination .page-numbers:hover {
            border-color: {$brand};
            color: {$brand};
        }
        .dd-cust-pagination .page-numbers.current {
            background: {$brand};
            border-color: {$brand};
            color: #fff;
        }
        .dd-cust-pagination .page-numbers.dots {
            border: none;
            background: transparent;
            color: #aaa;
        }

        /* ── Tabs ── */
        .dd-tabs {
            display: flex;
            gap: 4px;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 20px;
        }

        .dd-tab {
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 500;
            color: #666;
            text-decoration: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            border-radius: 4px 4px 0 0;
            transition: color 0.15s, border-color 0.15s;
        }

        .dd-tab:hover { color: {$brand}; text-decoration: none; }

        .dd-tab--active {
            color: {$brand};
            border-bottom-color: {$brand};
            font-weight: 600;
        }
        ";
    }
}
