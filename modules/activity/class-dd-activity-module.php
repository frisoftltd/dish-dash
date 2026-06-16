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
