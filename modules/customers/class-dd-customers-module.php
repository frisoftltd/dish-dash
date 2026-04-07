<?php
/**
 * Dish Dash – Customers Module
 *
 * Full customer management dashboard:
 * - Dashboard stats (total customers, revenue, new this month, avg spend)
 * - Customer list with status, spend, orders, reservations
 * - Status system: New / Regular / VIP / Champion
 *
 * @package DishDash
 * @since   2.5.81
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

        // Date filter
        $date_from = sanitize_text_field( $_GET['date_from'] ?? '' );
        $date_to   = sanitize_text_field( $_GET['date_to']   ?? '' );
        $search    = sanitize_text_field( $_GET['s']         ?? '' );

        // Get all customers
        $all_users    = $this->get_customers();
        $total_users  = count( $all_users );

        // Stats
        $total_spend     = 0;
        $new_this_month  = 0;
        $month_start     = date( 'Y-m-01' );
        $champion_count  = 0;
        $vip_count       = 0;

        foreach ( $all_users as $user ) {
            $spend = $this->get_user_spend( $user->ID );
            $total_spend += $spend['total'];
            if ( $user->user_registered >= $month_start ) $new_this_month++;
            $status = $this->get_status( $spend['total'], $spend['count'] );
            if ( $status['label'] === 'Champion' ) $champion_count++;
            if ( $status['label'] === 'VIP' )      $vip_count++;
        }

        $avg_spend = $total_users > 0 ? $total_spend / $total_users : 0;

        // Filtered list for table
        $filtered_users = $date_from || $date_to ? $this->get_customers( $date_from, $date_to ) : $all_users;

        // Search filter
        if ( $search ) {
            $filtered_users = array_filter( $filtered_users, function( $u ) use ( $search ) {
                $s = strtolower( $search );
                return strpos( strtolower( $u->display_name ), $s ) !== false
                    || strpos( strtolower( $u->user_email ), $s ) !== false
                    || strpos( strtolower( get_user_meta( $u->ID, 'billing_phone', true ) ), $s ) !== false;
            } );
        }

        ?>
        <div class="wrap dd-admin-wrap">

            <!-- ── Header ───────────────────────────────── -->
            <div class="dd-admin-header">
                <div class="dd-admin-header__logo">
                    <span class="dd-logo-icon">👥</span>
                    <div>
                        <h1>Customers</h1>
                        <span class="dd-version">Know your guests, grow your restaurant</span>
                    </div>
                </div>
                <a href="<?php echo esc_url( admin_url('admin.php?page=dish-dash-customers&export=csv&_wpnonce=' . wp_create_nonce('dd_export_customers')) ); ?>" class="button">
                    ⬇ Export CSV
                </a>
            </div>

            <!-- ── Dashboard Stats ──────────────────────── -->
            <div class="dd-cust-stats">
                <div class="dd-cust-stat dd-cust-stat--blue">
                    <div class="dd-cust-stat__icon">👥</div>
                    <div class="dd-cust-stat__val"><?php echo esc_html( $total_users ); ?></div>
                    <div class="dd-cust-stat__label">Total Customers</div>
                </div>
                <div class="dd-cust-stat dd-cust-stat--green">
                    <div class="dd-cust-stat__icon">💰</div>
                    <div class="dd-cust-stat__val"><?php echo esc_html( $this->fmt( $total_spend ) ); ?></div>
                    <div class="dd-cust-stat__label">Total Revenue</div>
                </div>
                <div class="dd-cust-stat dd-cust-stat--orange">
                    <div class="dd-cust-stat__icon">🆕</div>
                    <div class="dd-cust-stat__val"><?php echo esc_html( $new_this_month ); ?></div>
                    <div class="dd-cust-stat__label">New This Month</div>
                </div>
                <div class="dd-cust-stat dd-cust-stat--purple">
                    <div class="dd-cust-stat__icon">📊</div>
                    <div class="dd-cust-stat__val"><?php echo esc_html( $this->fmt( $avg_spend ) ); ?></div>
                    <div class="dd-cust-stat__label">Avg. Spend / Customer</div>
                </div>
                <div class="dd-cust-stat dd-cust-stat--gold">
                    <div class="dd-cust-stat__icon">🥇</div>
                    <div class="dd-cust-stat__val"><?php echo esc_html( $champion_count ); ?></div>
                    <div class="dd-cust-stat__label">Champions</div>
                </div>
                <div class="dd-cust-stat dd-cust-stat--silver">
                    <div class="dd-cust-stat__icon">🥈</div>
                    <div class="dd-cust-stat__val"><?php echo esc_html( $vip_count ); ?></div>
                    <div class="dd-cust-stat__label">VIP Customers</div>
                </div>
            </div>

            <!-- ── Status Legend ────────────────────────── -->
            <div class="dd-cust-legend">
                <strong>Customer Status:</strong>
                <span class="dd-status dd-status--new">🌱 New — No orders yet</span>
                <span class="dd-status dd-status--regular">🥉 Regular — Has ordered</span>
                <span class="dd-status dd-status--vip">🥈 VIP — RWF 50,000+</span>
                <span class="dd-status dd-status--champion">🥇 Champion — RWF 200,000+</span>
            </div>

            <!-- ── Filters ───────────────────────────────── -->
            <div class="dd-cust-filters">
                <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="page" value="dish-dash-customers">
                    <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>"
                        placeholder="Search name, email, phone…"
                        style="padding:8px 12px;border:1.5px solid #ddd;border-radius:8px;font-size:13px;min-width:220px;">
                    <label style="font-size:13px;font-weight:600;color:#666;">From</label>
                    <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>"
                        style="padding:8px 12px;border:1.5px solid #ddd;border-radius:8px;font-size:13px;">
                    <label style="font-size:13px;font-weight:600;color:#666;">To</label>
                    <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>"
                        style="padding:8px 12px;border:1.5px solid #ddd;border-radius:8px;font-size:13px;">
                    <button type="submit" class="button button-primary">Filter</button>
                    <a href="<?php echo esc_url( admin_url('admin.php?page=dish-dash-customers') ); ?>" class="button">Reset</a>
                    <span style="font-size:13px;color:#888;margin-left:auto;">
                        Showing <?php echo count( $filtered_users ); ?> of <?php echo $total_users; ?> customers
                    </span>
                </form>
            </div>

            <!-- ── Customers Table ──────────────────────── -->
            <div class="dd-cust-table-wrap">
                <table class="dd-cust-table widefat">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Birthday</th>
                            <th>Orders</th>
                            <th>Reservations</th>
                            <th>Total Spend</th>
                            <th>Status</th>
                            <th>Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $filtered_users ) ) : ?>
                        <tr><td colspan="8" style="text-align:center;padding:40px;color:#888;">
                            No customers found.
                        </td></tr>
                    <?php else : ?>
                        <?php foreach ( $filtered_users as $user ) :
                            $spend   = $this->get_user_spend( $user->ID );
                            $status  = $this->get_status( $spend['total'], $spend['count'] );
                            $reserv  = $this->get_user_reservations( $user->user_email );
                            $phone   = get_user_meta( $user->ID, 'billing_phone', true )
                                    ?: get_user_meta( $user->ID, 'dd_phone', true );
                            $address = get_user_meta( $user->ID, 'billing_address_1', true )
                                    ?: get_user_meta( $user->ID, 'dd_address', true );
                            $birthday = get_user_meta( $user->ID, 'dd_birthday', true );
                            $initials = strtoupper( substr( $user->display_name, 0, 1 ) );
                        ?>
                        <tr class="dd-cust-row">
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <div class="dd-cust-avatar"><?php echo esc_html( $initials ); ?></div>
                                    <div>
                                        <div style="font-weight:700;color:#221B19;"><?php echo esc_html( $user->display_name ); ?></div>
                                        <div style="font-size:12px;color:#aaa;"><?php echo esc_html( $user->user_email ); ?></div>
                                        <?php if ( $address ) : ?>
                                        <div style="font-size:11px;color:#bbb;">📍 <?php echo esc_html( $address ); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ( $phone ) : ?>
                                <a href="tel:<?php echo esc_attr( preg_replace('/\s/', '', $phone) ); ?>"
                                   style="font-size:13px;color:#6B1D1D;font-weight:600;text-decoration:none;">
                                    📞 <?php echo esc_html( $phone ); ?>
                                </a>
                                <?php else : ?>
                                <span style="color:#ccc;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $birthday ) :
                                    $bday = date_create( $birthday );
                                    $today = date_create( date('Y') . '-' . date_format( $bday, 'm-d' ) );
                                    $is_today = $today && $today->format('m-d') === date('m-d');
                                ?>
                                <span <?php if ( $is_today ) echo 'style="color:#e74c3c;font-weight:700;"'; ?>>
                                    <?php echo esc_html( date_format( $bday, 'M j' ) ); ?>
                                    <?php if ( $is_today ) echo ' 🎂'; ?>
                                </span>
                                <?php else : ?>
                                <span style="color:#ccc;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-weight:700;color:#221B19;"><?php echo esc_html( $spend['count'] ); ?></span>
                            </td>
                            <td>
                                <span style="font-weight:700;color:#221B19;"><?php echo esc_html( $reserv ); ?></span>
                            </td>
                            <td>
                                <span style="font-weight:700;color:#6B1D1D;font-size:13px;">
                                    <?php echo esc_html( $this->fmt( $spend['total'] ) ); ?>
                                </span>
                            </td>
                            <td>
                                <span class="dd-status <?php echo esc_attr( $status['class'] ); ?>">
                                    <?php echo esc_html( $status['icon'] . ' ' . $status['label'] ); ?>
                                </span>
                            </td>
                            <td style="font-size:12px;color:#888;">
                                <?php echo esc_html( date( 'M j, Y', strtotime( $user->user_registered ) ) ); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
        <?php
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
