<?php
/**
 * File:    modules/activity/class-dd-activity-module.php
 * Module:  DD_Activity_Module (extends DD_Module)
 * Purpose: Activity log — records staff actions (admin, owner, manager) to
 *          wp_dd_activity_log. Exposes a central hook so any module can log
 *          without a direct dependency on this class.
 *
 * Dependencies (this file needs):
 *   - DD_Module base class
 *   - WordPress: global $wpdb, wp_get_current_user(), get_user_by()
 *   - wp_dd_activity_log table (created via self::install())
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php (instantiates this module)
 *
 * Hooks registered:
 *   - dd_log_activity → self::log()
 *
 * Usage from any other module (fully decoupled):
 *   do_action( 'dd_log_activity', [
 *       'action'      => 'order_confirmed',
 *       'object_type' => 'order',
 *       'object_id'   => 123,
 *       'details'     => [ 'status' => 'confirmed' ],
 *   ] );
 *
 * Viewer page: admin-only (Fri Soft oversight) — added in Brief 3.
 *
 * Last modified: v3.8.9
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( class_exists( 'DD_Activity_Module' ) ) return;

class DD_Activity_Module extends DD_Module {

    protected string $id = 'activity';

    public function init(): void {
        add_action( 'dd_log_activity', [ __CLASS__, 'log' ], 10, 1 );

        // ── Login / Logout ──────────────────────────────────────────
        add_action( 'wp_login',  [ __CLASS__, 'on_login' ],  10, 2 );
        add_action( 'wp_logout', [ __CLASS__, 'on_logout' ], 10, 1 );

        // ── Orders (listen to existing Dish Dash order hooks) ───────
        add_action( 'dish_dash_order_status_changed', [ __CLASS__, 'on_order_status_changed' ], 10, 3 );
        add_action( 'dish_dash_order_confirmed',      [ __CLASS__, 'on_order_confirmed' ],      10, 1 );

        // ── Reservations (new hook fired in reservations admin/module) ──
        add_action( 'dish_dash_reservation_status_changed', [ __CLASS__, 'on_reservation_status_changed' ], 10, 3 );

        // ── Admin viewer (registered last — appears at bottom of Dish Dash menu) ──
        add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ], 100 );
    }

    // ─────────────────────────────────────────
    //  ADMIN VIEWER
    // ─────────────────────────────────────────

    public static function register_admin_page(): void {
        add_submenu_page(
            'dish-dash',
            __( 'Activity Log', 'dish-dash' ),
            __( '📋 Activity Log', 'dish-dash' ),
            'manage_options',
            'dish-dash-activity-log',
            [ __CLASS__, 'render_admin_page' ]
        );
    }

    /**
     * Render the admin-only Activity Log viewer.
     * Gated on manage_options. Hidden from Owner/Manager via menu lockdown.
     */
    public static function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'dish-dash' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dd_activity_log';

        // ── Filters ─────────────────────────────────────────────
        $f_user   = isset( $_GET['f_user'] )   ? absint( $_GET['f_user'] ) : 0;
        $f_action = isset( $_GET['f_action'] ) ? sanitize_key( $_GET['f_action'] ) : '';
        $f_from   = isset( $_GET['f_from'] )   ? sanitize_text_field( wp_unslash( $_GET['f_from'] ) ) : '';
        $f_to     = isset( $_GET['f_to'] )     ? sanitize_text_field( wp_unslash( $_GET['f_to'] ) )   : '';

        $paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page = 50;
        $offset   = ( $paged - 1 ) * $per_page;

        // ── Build WHERE ─────────────────────────────────────────
        $where  = 'WHERE 1=1';
        $params = [];
        if ( $f_user )   { $where .= ' AND user_id = %d'; $params[] = $f_user; }
        if ( $f_action ) { $where .= ' AND action = %s';  $params[] = $f_action; }
        if ( $f_from )   { $where .= ' AND created_at >= %s'; $params[] = $f_from . ' 00:00:00'; }
        if ( $f_to )     { $where .= ' AND created_at <= %s'; $params[] = $f_to . ' 23:59:59'; }

        // Total for pagination
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
        $total = $params
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
            : (int) $wpdb->get_var( $count_sql );

        // Page rows
        $rows_sql    = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows_params = array_merge( $params, [ $per_page, $offset ] );
        $rows        = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ) );

        $total_pages = max( 1, (int) ceil( $total / $per_page ) );

        // Distinct actions for the filter dropdown
        $actions = $wpdb->get_col( "SELECT DISTINCT action FROM {$table} ORDER BY action ASC" );

        // Staff users for the filter dropdown
        $staff = get_users( [
            'role__in' => [ 'administrator', 'dd_restaurant_owner', 'dd_restaurant_manager' ],
            'orderby'  => 'display_name',
        ] );

        $base_url = admin_url( 'admin.php?page=dish-dash-activity-log' );
        ?>
        <div class="dd-page-wrap">
          <div class="dd-page-header">
            <h1 class="dd-page-title">📋 Activity Log</h1>
            <p style="color:#6b7280;margin-top:4px;">Staff actions across the system — admin oversight only.</p>
          </div>

          <form method="get" class="dd-activity-filters" style="background:#fff;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.08);margin-bottom:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
            <input type="hidden" name="page" value="dish-dash-activity-log" />
            <label style="display:flex;flex-direction:column;font-size:12px;color:#374151;gap:4px;">User
              <select name="f_user" style="min-width:160px;padding:6px;">
                <option value="0">All users</option>
                <?php foreach ( $staff as $u ) : ?>
                  <option value="<?php echo (int) $u->ID; ?>" <?php selected( $f_user, $u->ID ); ?>>
                    <?php echo esc_html( $u->display_name ); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <label style="display:flex;flex-direction:column;font-size:12px;color:#374151;gap:4px;">Action
              <select name="f_action" style="min-width:160px;padding:6px;">
                <option value="">All actions</option>
                <?php foreach ( $actions as $a ) : ?>
                  <option value="<?php echo esc_attr( $a ); ?>" <?php selected( $f_action, $a ); ?>>
                    <?php echo esc_html( self::action_label( $a ) ); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <label style="display:flex;flex-direction:column;font-size:12px;color:#374151;gap:4px;">From
              <input type="date" name="f_from" value="<?php echo esc_attr( $f_from ); ?>" style="padding:6px;" />
            </label>
            <label style="display:flex;flex-direction:column;font-size:12px;color:#374151;gap:4px;">To
              <input type="date" name="f_to" value="<?php echo esc_attr( $f_to ); ?>" style="padding:6px;" />
            </label>
            <button type="submit" class="button button-primary">Filter</button>
            <a href="<?php echo esc_url( $base_url ); ?>" class="button">Reset</a>
          </form>

          <div style="background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.08);overflow:hidden;">
            <table class="widefat striped" style="border:none;">
              <thead>
                <tr>
                  <th style="width:170px;">When</th>
                  <th>Activity</th>
                  <th style="width:140px;">User</th>
                  <th style="width:120px;">IP</th>
                </tr>
              </thead>
              <tbody>
                <?php if ( ! $rows ) : ?>
                  <tr><td colspan="4" style="padding:24px;text-align:center;color:#9ca3af;">No activity found.</td></tr>
                <?php else : foreach ( $rows as $r ) : ?>
                  <tr>
                    <td><?php echo esc_html( mysql2date( 'M j, Y g:i a', $r->created_at ) ); ?></td>
                    <td><?php echo esc_html( self::describe( $r ) ); ?></td>
                    <td>
                      <?php echo esc_html( $r->user_name ); ?><br>
                      <span style="font-size:11px;color:#9ca3af;"><?php echo esc_html( self::role_label( $r->user_role ) ); ?></span>
                    </td>
                    <td style="font-size:12px;color:#6b7280;"><?php echo esc_html( $r->ip_address ); ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

          <?php if ( $total_pages > 1 ) : ?>
            <div style="margin-top:16px;display:flex;gap:6px;justify-content:center;align-items:center;">
              <?php
              $qs = array_filter( [ 'f_user' => $f_user ?: null, 'f_action' => $f_action ?: null, 'f_from' => $f_from ?: null, 'f_to' => $f_to ?: null ] );
              for ( $p = 1; $p <= $total_pages; $p++ ) :
                  $link = add_query_arg( array_merge( $qs, [ 'paged' => $p ] ), $base_url );
                  if ( $p === $paged ) : ?>
                    <span style="padding:6px 12px;background:var(--dd-brand,#65040d);color:#fff;border-radius:6px;"><?php echo (int) $p; ?></span>
                  <?php else : ?>
                    <a href="<?php echo esc_url( $link ); ?>" style="padding:6px 12px;background:#fff;border-radius:6px;border:1px solid #e5e7eb;text-decoration:none;color:#374151;"><?php echo (int) $p; ?></a>
                  <?php endif;
              endfor; ?>
            </div>
            <p style="text-align:center;color:#9ca3af;font-size:12px;margin-top:8px;"><?php echo (int) $total; ?> total entries</p>
          <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Human-readable sentence for a log row.
     */
    private static function describe( object $r ): string {
        $details = $r->details ? json_decode( $r->details, true ) : [];
        switch ( $r->action ) {
            case 'login':
                return 'Logged in';
            case 'logout':
                return 'Logged out';
            case 'order_confirmed':
                return sprintf( 'Confirmed order #%s', $r->object_id );
            case 'order_status_changed':
                return sprintf( 'Changed order #%s status (%s → %s)', $r->object_id, $details['from'] ?? '?', $details['to'] ?? '?' );
            case 'reservation_status_changed':
                return sprintf( 'Changed reservation #%s (%s → %s)', $r->object_id, $details['from'] ?? '?', $details['to'] ?? '?' );
            case 'settings_updated':
                $what = $r->object_type === 'template' ? 'Template settings'
                      : ( $r->object_type === 'homepage' ? 'Homepage settings'
                      : 'General settings' );
                return sprintf( 'Updated %s', $what );
            default:
                return ucwords( str_replace( '_', ' ', $r->action ) );
        }
    }

    /**
     * Friendly label for an action (filter dropdown).
     */
    private static function action_label( string $action ): string {
        return ucwords( str_replace( '_', ' ', $action ) );
    }

    /**
     * Friendly label for a role.
     */
    private static function role_label( string $role ): string {
        $map = [
            'administrator'         => 'Administrator',
            'dd_restaurant_owner'   => 'Owner',
            'dd_restaurant_manager' => 'Manager',
        ];
        return $map[ $role ] ?? $role;
    }

    // ─────────────────────────────────────────
    //  TABLE INSTALL
    // ─────────────────────────────────────────

    /**
     * Create the activity log table.
     * Called manually after deploy (zip updates don't run dbDelta automatically).
     *
     * WP-CLI: wp eval 'DD_Activity_Module::install();'
     */
    public static function install(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table           = $wpdb->prefix . 'dd_activity_log';

        dbDelta( "
            CREATE TABLE {$table} (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id     BIGINT UNSIGNED NOT NULL,
                user_name   VARCHAR(191)    NOT NULL DEFAULT '',
                user_role   VARCHAR(64)     NOT NULL DEFAULT '',
                action      VARCHAR(64)     NOT NULL DEFAULT '',
                object_type VARCHAR(64)     NULL,
                object_id   VARCHAR(64)     NULL,
                details     TEXT            NULL,
                ip_address  VARCHAR(45)     NULL,
                created_at  DATETIME        NOT NULL,
                PRIMARY KEY  (id),
                KEY          user_id    (user_id),
                KEY          action     (action),
                KEY          created_at (created_at)
            ) {$charset_collate};
        " );
    }

    // ─────────────────────────────────────────
    //  LOGGER
    // ─────────────────────────────────────────

    /**
     * Record an activity log entry.
     *
     * Usage from any module (decoupled):
     *   do_action( 'dd_log_activity', [
     *       'action'      => 'order_confirmed',
     *       'object_type' => 'order',
     *       'object_id'   => 123,
     *       'details'     => [ 'status' => 'confirmed' ],
     *   ] );
     *
     * @param array $args Activity data.
     */
    public static function log( array $args ): void {
        global $wpdb;

        // Only log staff actions — admin, owner, manager. Never customers.
        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            // Allow explicit user_id passing (e.g. login/logout hooks).
            $user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
            if ( ! $user_id ) return;
            $user = get_user_by( 'id', $user_id );
            if ( ! $user ) return;
        }

        $staff_roles = [ 'administrator', 'dd_restaurant_owner', 'dd_restaurant_manager' ];
        if ( ! array_intersect( $staff_roles, (array) $user->roles ) ) {
            return; // Not staff — skip (customers handled later in AI/events phase).
        }

        $role = '';
        foreach ( $staff_roles as $r ) {
            if ( in_array( $r, (array) $user->roles, true ) ) { $role = $r; break; }
        }

        $details = isset( $args['details'] ) ? wp_json_encode( $args['details'] ) : null;

        $wpdb->insert(
            $wpdb->prefix . 'dd_activity_log',
            [
                'user_id'     => $user->ID,
                'user_name'   => $user->display_name,
                'user_role'   => $role,
                'action'      => sanitize_key( $args['action'] ?? 'unknown' ),
                'object_type' => isset( $args['object_type'] ) ? sanitize_key( $args['object_type'] ) : null,
                'object_id'   => isset( $args['object_id'] ) ? (string) $args['object_id'] : null,
                'details'     => $details,
                'ip_address'  => self::get_ip(),
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    // ─────────────────────────────────────────
    //  CAPTURE HANDLERS
    // ─────────────────────────────────────────

    /**
     * Log a successful login. Fired by WordPress core wp_login.
     */
    public static function on_login( $user_login, $user = null ): void {
        if ( ! $user instanceof WP_User ) {
            $user = get_user_by( 'login', $user_login );
        }
        if ( ! $user ) return;
        do_action( 'dd_log_activity', [
            'user_id'     => $user->ID,
            'action'      => 'login',
            'object_type' => 'session',
            'object_id'   => $user->ID,
        ] );
    }

    /**
     * Log a logout. wp_logout passes the user ID (WP 5.5+).
     */
    public static function on_logout( $user_id = 0 ): void {
        if ( ! $user_id ) $user_id = get_current_user_id();
        if ( ! $user_id ) return;
        do_action( 'dd_log_activity', [
            'user_id'     => $user_id,
            'action'      => 'logout',
            'object_type' => 'session',
            'object_id'   => $user_id,
        ] );
    }

    /**
     * Log an order status change.
     */
    public static function on_order_status_changed( $order_id, $old_status, $new_status ): void {
        do_action( 'dd_log_activity', [
            'action'      => 'order_status_changed',
            'object_type' => 'order',
            'object_id'   => $order_id,
            'details'     => [ 'from' => $old_status, 'to' => $new_status ],
        ] );
    }

    /**
     * Log an explicit order confirmation.
     */
    public static function on_order_confirmed( $order_id ): void {
        do_action( 'dd_log_activity', [
            'action'      => 'order_confirmed',
            'object_type' => 'order',
            'object_id'   => $order_id,
        ] );
    }

    /**
     * Log a reservation status change.
     */
    public static function on_reservation_status_changed( $res_id, $old_status, $new_status ): void {
        do_action( 'dd_log_activity', [
            'action'      => 'reservation_status_changed',
            'object_type' => 'reservation',
            'object_id'   => $res_id,
            'details'     => [ 'from' => $old_status, 'to' => $new_status ],
        ] );
    }

    /**
     * Get the client IP address safely.
     */
    private static function get_ip(): string {
        $keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
        foreach ( $keys as $k ) {
            if ( ! empty( $_SERVER[ $k ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
                // X-Forwarded-For can be a list; take the first.
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                return substr( $ip, 0, 45 );
            }
        }
        return '';
    }
}
