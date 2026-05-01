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
            SUM(total_spent >= 200000)      AS champions,
            SUM(total_spent >= 50000 AND total_spent < 200000) AS vips
            FROM {$table}";

        $stats = $wpdb->get_row( $stats_sql );

        // New this month
        $new_this_month = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE MONTH(created_at) = MONTH(NOW())
             AND YEAR(created_at) = YEAR(NOW())"
        );

        // --- Customer rows ---
        $sql = "SELECT * FROM {$table} {$where}
                ORDER BY created_at DESC
                LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;

        $customers = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_results( $sql );

        $total_rows = (int) $wpdb->get_var(
            $params
                ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...array_slice( $params, 0, -2 ) )
                : "SELECT COUNT(*) FROM {$table} {$where}"
        );

        // --- Status helper ---
        $get_status = function( float $spent, int $orders ): array {
            if ( $orders === 0 )       return [ 'New',      '🌱', '#27ae60' ];
            if ( $spent >= 200000 )    return [ 'Champion', '🏆', '#C9A24A' ];
            if ( $spent >= 50000  )    return [ 'VIP',      '💎', '#8e44ad' ];
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
                    [ 'icon' => '🏆', 'value' => number_format( $stats->champions ), 'label' => 'Champions' ],
                    [ 'icon' => '💎', 'value' => number_format( $stats->vips ),      'label' => 'VIP Customers' ],
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
                <span class="dd-legend-item" style="color:#8e44ad">💎 VIP — RWF 50,000+</span>
                <span class="dd-legend-item" style="color:#C9A24A">🏆 Champion — RWF 200,000+</span>
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
        /* ── Stats grid ── */
        .dd-cust-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            margin: 24px 0;
        }
        .dd-cust-stat {
            background: #fff;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            border: 1px solid #e0e0e0;
            box-shadow: 0 2px 8px rgba(0,0,0,.04);
        }
        .dd-cust-stat__icon { font-size: 28px; margin-bottom: 8px; }
        .dd-cust-stat__val  { font-size: 22px; font-weight: 800; color: #221B19; line-height: 1.2; }
        .dd-cust-stat__label{ font-size: 12px; color: #888; margin-top: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
        .dd-cust-stat--blue   { border-top: 3px solid #3498db; }
        .dd-cust-stat--green  { border-top: 3px solid #27ae60; }
        .dd-cust-stat--orange { border-top: 3px solid #e67e22; }
        .dd-cust-stat--purple { border-top: 3px solid #9b59b6; }
        .dd-cust-stat--gold   { border-top: 3px solid #f39c12; }
        .dd-cust-stat--silver { border-top: 3px solid #95a5a6; }

        /* ── Legend ── */
        .dd-cust-legend {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 13px;
            margin-bottom: 16px;
        }

        /* ── Status badges ── */
        .dd-status {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .dd-status--new      { background: #e8f5e9; color: #2e7d32; }
        .dd-status--regular  { background: #fff3e0; color: #e65100; }
        .dd-status--vip      { background: #ede7f6; color: #512da8; }
        .dd-status--champion { background: #fff8e1; color: #f57f17; }

        /* ── Filters ── */
        .dd-cust-filters {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 16px;
        }

        /* ── Table ── */
        .dd-cust-table-wrap {
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e0e0e0;
            box-shadow: 0 2px 8px rgba(0,0,0,.04);
        }
        .dd-cust-table { border: 0 !important; }
        .dd-cust-table thead th {
            background: #f9f6f2 !important;
            font-weight: 700 !important;
            font-size: 12px !important;
            text-transform: uppercase !important;
            letter-spacing: .05em !important;
            color: #888 !important;
            padding: 14px 16px !important;
            border-bottom: 2px solid #ede6db !important;
        }
        .dd-cust-table tbody td {
            padding: 14px 16px !important;
            border-bottom: 1px solid #f5f0ea !important;
            vertical-align: middle !important;
        }
        .dd-cust-row:hover td { background: #fdfaf7 !important; }

        /* ── Avatar ── */
        .dd-cust-avatar {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: #6B1D1D;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 700;
            flex-shrink: 0;
        }
        ';
    }
}
