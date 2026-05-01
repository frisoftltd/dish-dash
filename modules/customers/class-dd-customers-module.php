<?php
/**
 * File:    modules/customers/class-dd-customers-module.php
 * Module:  DD_Customers_Module (extends DD_Module)
 * Purpose: Customer CRM dashboard — lists all WP users with calculated
 *          spend, order count, reservation count, and tier status
 *          (New / Regular / VIP / Champion). Allows editing customer
 *          profile meta from the admin.
 *
 * Dependencies (this file needs):
 *   - DD_Module base class
 *   - WordPress: get_users(), get_user_meta()
 *   - WooCommerce: wc_get_orders() (optional, degrades gracefully)
 *   - {prefix}dishdash_reservations table (read-only)
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
 * Customer tiers:
 *   New (0 orders), Regular (≥1), VIP (≥50,000 RWF), Champion (≥200,000 RWF)
 *
 * Depends on (modules): NONE — architecture rule
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( class_exists( 'DD_Customers_Module' ) ) return;

class DD_Customers_Module extends DD_Module {

    protected string $id = 'customers';

    // ── Status thresholds (RWF) ──────────────────────
    const STATUS_CHAMPION = 200000;
    const STATUS_VIP      = 50000;
    const STATUS_REGULAR  = 1;   // at least 1 order

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'register_admin_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        // Save extra profile fields from our customer edit
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
        wp_add_inline_style( 'wp-admin', $this->admin_css() );
    }

    // ─────────────────────────────────────────
    //  HELPERS — get customer data
    // ─────────────────────────────────────────

    /** Get all WP users who are customers (not admins) */
    private function get_customers( string $date_from = '', string $date_to = '' ): array {
        $args = [
            'role__not_in' => [ 'administrator', 'editor', 'author', 'contributor' ],
            'orderby'      => 'registered',
            'order'        => 'DESC',
            'number'       => -1,
        ];

        if ( $date_from ) $args['date_query'][] = [ 'after' => $date_from, 'column' => 'user_registered' ];
        if ( $date_to )   $args['date_query'][] = [ 'before' => $date_to, 'column' => 'user_registered', 'inclusive' => true ];

        return get_users( $args );
    }

    /** Get total spent + order count for a user */
    private function get_user_spend( int $user_id ): array {
        if ( ! function_exists( 'wc_get_orders' ) ) return [ 'total' => 0, 'count' => 0 ];

        $orders = wc_get_orders( [
            'customer_id' => $user_id,
            'status'      => [ 'completed', 'processing', 'on-hold' ],
            'limit'       => -1,
            'return'      => 'ids',
        ] );

        $total = 0;
        foreach ( $orders as $order_id ) {
            $order  = wc_get_order( $order_id );
            if ( $order ) $total += (float) $order->get_total();
        }

        return [ 'total' => $total, 'count' => count( $orders ) ];
    }

    /** Get reservation count for a user (by email) */
    private function get_user_reservations( string $email ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_reservations';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) return 0;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE customer_email = %s", $email
        ) );
    }

    /** Determine customer status from total spend */
    private function get_status( float $total, int $orders ): array {
        if ( $orders === 0 ) return [ 'label' => 'New',      'icon' => '🌱', 'class' => 'dd-status--new' ];
        if ( $total >= self::STATUS_CHAMPION ) return [ 'label' => 'Champion', 'icon' => '🥇', 'class' => 'dd-status--champion' ];
        if ( $total >= self::STATUS_VIP      ) return [ 'label' => 'VIP',      'icon' => '🥈', 'class' => 'dd-status--vip' ];
        return [ 'label' => 'Regular', 'icon' => '🥉', 'class' => 'dd-status--regular' ];
    }

    /** Format RWF amount */
    private function fmt( float $n ): string {
        return 'RWF ' . number_format( $n, 0, '.', ',' );
    }

    // ─────────────────────────────────────────
    //  RENDER ADMIN PAGE
    // ─────────────────────────────────────────
    public function render_admin_page(): void {

        // CSV export
        if ( isset( $_GET['export'] ) && $_GET['export'] === 'csv' ) {
            $this->export_csv();
            exit;
        }

        $this->render_page();
    }

    private function render_page(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_customers';

        // --- Filters ---
        $search    = sanitize_text_field( $_GET['s']    ?? '' );
        $date_from = sanitize_text_field( $_GET['from'] ?? '' );
        $date_to   = sanitize_text_field( $_GET['to']   ?? '' );
        $per_page  = 20;
        $page      = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $offset    = ( $page - 1 ) * $per_page;

        // --- Build WHERE ---
        $where  = 'WHERE 1=1';
        $params = [];

        if ( $search ) {
            $where   .= ' AND (name LIKE %s OR whatsapp LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ( $date_from ) {
            $where   .= ' AND DATE(created_at) >= %s';
            $params[] = $date_from;
        }
        if ( $date_to ) {
            $where   .= ' AND DATE(created_at) <= %s';
            $params[] = $date_to;
        }

        // --- Stats ---
        $stats_sql = "SELECT
            COUNT(*)                        AS total_customers,
            COALESCE(SUM(total_spent), 0)   AS total_revenue,
            COALESCE(AVG(total_spent), 0)   AS avg_spend,
            SUM(total_spent >= 500000)                              AS diamonds,
            SUM(total_spent >= 250000 AND total_spent < 500000)     AS champions,
            SUM(total_spent >= 100000 AND total_spent < 250000)     AS vips
            FROM {$table}";

        $stats = $wpdb->get_row( $stats_sql );

        // New this month
        $new_this_month = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE MONTH(created_at) = MONTH(NOW())
             AND YEAR(created_at) = YEAR(NOW())"
        );

        // --- Customer rows ---
        // $params holds only filter values (search, dates). Keep separate from pagination.
        $sql        = "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $row_params = array_merge( $params, [ $per_page, $offset ] );
        $customers  = $wpdb->get_results( $wpdb->prepare( $sql, ...$row_params ) );

        // COUNT — only use prepare when filter params exist (avoids prepare-without-placeholders notice)
        if ( $params ) {
            $total_rows = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...$params )
            );
        } else {
            $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        }

        // --- Status helper ---
        $get_status = function( float $spent, int $orders ): array {
            if ( $orders === 0 )       return [ 'New',      '🌱', '#27ae60' ];
            if ( $spent >= 500000 )    return [ 'Diamond',  '💎', '#00bcd4' ];
            if ( $spent >= 250000 )    return [ 'Champion', '🏆', '#C9A24A' ];
            if ( $spent >= 100000 )    return [ 'VIP',      '👑', '#8e44ad' ];
            return                            [ 'Regular',  '🧡', '#E8832A' ];
        };

        // --- Render ---
        ?>
        <div class="wrap dd-customers-wrap">

            <!-- Header -->
            <div class="dd-customers-header">
                <div class="dd-customers-header__info">
                    <span class="dashicons dashicons-groups"></span>
                    <div>
                        <h1>Customers</h1>
                        <p>Know your guests, grow your restaurant</p>
                    </div>
                </div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-customers&export=csv' ) ); ?>"
                   class="button button-secondary">⬇ Export CSV</a>
            </div>

            <!-- Stats -->
            <div class="dd-customers-stats">
                <?php
                $stat_cards = [
                    [ 'icon' => '👥', 'value' => number_format( $stats->total_customers ), 'label' => 'Total Customers' ],
                    [ 'icon' => '💰', 'value' => 'RWF ' . number_format( $stats->total_revenue ), 'label' => 'Total Revenue' ],
                    [ 'icon' => '🆕', 'value' => $new_this_month, 'label' => 'New This Month' ],
                    [ 'icon' => '📊', 'value' => 'RWF ' . number_format( $stats->avg_spend ), 'label' => 'Avg Spend / Customer' ],
                    [ 'icon' => '💎', 'value' => number_format( $stats->diamonds ?? 0 ), 'label' => 'Diamond' ],
                    [ 'icon' => '🏆', 'value' => number_format( $stats->champions ),     'label' => 'Champions' ],
                    [ 'icon' => '👑', 'value' => number_format( $stats->vips ),          'label' => 'VIP Customers' ],
                ];
                foreach ( $stat_cards as $card ) :
                ?>
                <div class="dd-stat-card">
                    <div class="dd-stat-card__icon"><?php echo $card['icon']; ?></div>
                    <div class="dd-stat-card__value"><?php echo esc_html( $card['value'] ); ?></div>
                    <div class="dd-stat-card__label"><?php echo esc_html( $card['label'] ); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Status legend -->
            <div class="dd-customers-legend">
                <strong>Customer Status:</strong>
                <span class="dd-legend-item" style="color:#27ae60">🌱 New — No orders yet</span>
                <span class="dd-legend-item" style="color:#E8832A">🧡 Regular — Has ordered</span>
                <span class="dd-legend-item" style="color:#8e44ad">👑 VIP — RWF 100,000+</span>
                <span class="dd-legend-item" style="color:#C9A24A">🏆 Champion — RWF 250,000+</span>
                <span class="dd-legend-item" style="color:#00bcd4">💎 Diamond — RWF 500,000+</span>
            </div>

            <!-- Filters -->
            <form method="get" class="dd-customers-filters">
                <input type="hidden" name="page" value="dish-dash-customers">
                <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>"
                       placeholder="Search name or WhatsApp..." class="regular-text">
                <label>From <input type="date" name="from" value="<?php echo esc_attr( $date_from ); ?>"></label>
                <label>To   <input type="date" name="to"   value="<?php echo esc_attr( $date_to );   ?>"></label>
                <button type="submit" class="button button-primary">Filter</button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-customers' ) ); ?>"
                   class="button">Reset</a>
                <span style="float:right;line-height:30px;color:#666;">
                    Showing <?php echo count( $customers ); ?> of <?php echo $total_rows; ?> customers
                </span>
            </form>

            <!-- Table -->
            <table class="widefat dd-customers-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>WhatsApp</th>
                        <th>Birthday</th>
                        <th>Orders</th>
                        <th>Total Spend</th>
                        <th>Status</th>
                        <th>Joined</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $customers ) ) : ?>
                    <tr><td colspan="7" style="text-align:center;padding:40px;color:#aaa;">
                        No customers yet. Orders will create customer records automatically.
                    </td></tr>
                <?php else : ?>
                    <?php foreach ( $customers as $c ) :
                        [ $status_label, $status_icon, $status_color ] = $get_status(
                            (float) $c->total_spent,
                            (int)   $c->total_orders
                        );
                        $birthday = $c->birthday
                            ? date( 'M j', strtotime( $c->birthday ) )
                            : '—';
                        $address = $c->delivery_address
                            ? wp_trim_words( $c->delivery_address, 6, '…' )
                            : '—';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $c->name ); ?></strong><br>
                            <small style="color:#888;"><?php echo esc_html( $address ); ?></small>
                        </td>
                        <td><?php echo esc_html( '+' . ltrim( $c->whatsapp, '0' ) ); ?></td>
                        <td><?php echo esc_html( $birthday ); ?></td>
                        <td><?php echo (int) $c->total_orders; ?></td>
                        <td><strong style="color:#65040d;">
                            RWF <?php echo number_format( $c->total_spent ); ?>
                        </strong></td>
                        <td><span style="color:<?php echo $status_color; ?>;font-weight:600;">
                            <?php echo $status_icon . ' ' . $status_label; ?>
                        </span></td>
                        <td><?php echo esc_html( date( 'M j, Y', strtotime( $c->created_at ) ) ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ( $total_rows > $per_page ) :
                $total_pages = ceil( $total_rows / $per_page );
            ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( [
                        'base'    => add_query_arg( 'paged', '%#%' ),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $total_pages,
                    ] );
                    ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
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
             ORDER BY created_at DESC",
            ARRAY_A
        );

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="customers-' . date( 'Y-m-d' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'Name', 'WhatsApp', 'Address', 'Orders', 'Total Spent (RWF)', 'Birthday', 'Joined' ] );
        foreach ( $rows as $row ) {
            $row['birthday']   = $row['birthday']   ? date( 'M j', strtotime( $row['birthday'] ) )   : '';
            $row['created_at'] = $row['created_at'] ? date( 'Y-m-d', strtotime( $row['created_at'] ) ) : '';
            fputcsv( $out, array_values( $row ) );
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

        // Also sync to WooCommerce billing fields
        if ( $phone )   update_user_meta( $user_id, 'billing_phone',     $phone );
        if ( $address ) update_user_meta( $user_id, 'billing_address_1', $address );

        wp_send_json_success();
    }

    // ─────────────────────────────────────────
    //  ADMIN CSS
    // ─────────────────────────────────────────
    private function admin_css(): string {
        return '
        .dd-customers-wrap { max-width: 1200px; }

        /* ── Header ── */
        .dd-customers-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #65040d;
            border-radius: 12px;
            padding: 20px 28px;
            margin-bottom: 24px;
            color: #fff;
        }
        .dd-customers-header__info {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .dd-customers-header .dashicons {
            font-size: 40px;
            width: 40px;
            height: 40px;
            color: rgba(255,255,255,0.7);
        }
        .dd-customers-header h1 {
            color: #fff;
            margin: 0;
            font-size: 22px;
        }
        .dd-customers-header p {
            color: rgba(255,255,255,0.75);
            margin: 2px 0 0;
            font-size: 13px;
        }

        /* ── Stats grid ── */
        .dd-customers-stats {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        @media (max-width: 1100px) {
            .dd-customers-stats { grid-template-columns: repeat(3, 1fr); }
        }
        .dd-stat-card {
            background: #fff;
            border: 1px solid #e8e0d8;
            border-radius: 10px;
            padding: 16px 12px;
            text-align: center;
        }
        .dd-stat-card__icon { font-size: 24px; margin-bottom: 6px; }
        .dd-stat-card__value {
            font-size: 18px;
            font-weight: 700;
            color: #65040d;
            line-height: 1.2;
            word-break: break-word;
        }
        .dd-stat-card__label {
            font-size: 11px;
            color: #888;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        /* ── Legend ── */
        .dd-customers-legend {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            font-size: 13px;
        }
        .dd-legend-item { font-weight: 500; }

        /* ── Filters ── */
        .dd-customers-filters {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            padding: 12px 16px;
            background: #f9f5f0;
            border-radius: 8px;
        }
        .dd-customers-filters input[type="text"] { min-width: 220px; }
        .dd-customers-filters label { font-size: 13px; }

        /* ── Table ── */
        .dd-customers-table th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
            background: #fafafa;
        }
        .dd-customers-table td { padding: 12px 10px; vertical-align: middle; }
        .dd-customers-table tr:hover td { background: #fdf9f5; }
        ';
    }
}
