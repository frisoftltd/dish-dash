<?php
/**
 * File:    dishdash-core/class-dd-helpers.php
 * Purpose: Global procedural helper functions available everywhere in the
 *          plugin — all wrapped in if(!function_exists()) guards.
 *
 * Dependencies (this file needs):
 *   - DD_Settings class (for dd_price currency lookup)
 *   - $wpdb global (for dd_get_branches, dd_get_branch)
 *   - ABSPATH (WordPress core)
 *
 * Dependents (files that need this):
 *   - Loaded by dishdash-core/class-dd-loader.php during boot
 *   - Used by modules/orders/class-dd-orders-module.php (dd_generate_order_number,
 *     dd_order_status_transitions, dd_price, dd_valid_order_type)
 *   - Used by modules/homepage/class-dd-homepage-module.php (dd_is_enabled)
 *   - Used by templates/page-dishdash.php (dd_cart_url, dd_menu_url etc.)
 *
 * Functions defined:
 *   dd_price(), dd_generate_order_number(), dd_get_branches(), dd_get_branch(),
 *   dd_get_current_branch_id(), dd_is_enabled(), dd_valid_order_type(),
 *   dd_order_status_transitions(), dd_order_status_label(), dd_log(),
 *   dd_menu_url(), dd_cart_url(), dd_checkout_url(), dd_track_url()
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Format a price value using plugin currency settings.
 */
function dd_price( float $amount ): string {
    $symbol   = get_option( 'dish_dash_currency_symbol', '$' );
    $position = get_option( 'dish_dash_currency_position', 'before' );
    $formatted = number_format( $amount, 2 );

    return 'before' === $position
        ? $symbol . $formatted
        : $formatted . $symbol;
}

/**
 * Generate a unique Dish Dash order number.
 * Format: DD-00001, DD-00002, …
 */
function dd_generate_order_number(): string {
    $prefix  = get_option( 'dish_dash_order_prefix', 'DD-' );
    $counter = (int) get_option( 'dish_dash_order_counter', 0 );
    $counter++;
    update_option( 'dish_dash_order_counter', $counter );
    return $prefix . str_pad( $counter, 5, '0', STR_PAD_LEFT );
}

/**
 * Get all active branches.
 *
 * @return array<int, object>
 */
function dd_get_branches(): array {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}dishdash_branches WHERE is_active = 1 ORDER BY name ASC"
    );
}

/**
 * Get a single branch by ID.
 */
function dd_get_branch( int $id ): ?object {
    global $wpdb;
    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dishdash_branches WHERE id = %d",
            $id
        )
    );
}

/**
 * Get the currently selected branch ID from session/cookie.
 * Defaults to branch 1 (main branch).
 */
function dd_get_current_branch_id(): int {
    if ( isset( $_COOKIE['dd_branch_id'] ) ) {
        return (int) $_COOKIE['dd_branch_id'];
    }
    return 1;
}

/**
 * Check if a feature module is enabled in Settings.
 */
function dd_is_enabled( string $feature ): bool {
    return '1' === get_option( "dish_dash_enable_{$feature}", '1' );
}

/**
 * Sanitize and validate an order type string.
 */
function dd_valid_order_type( string $type ): string {
    $valid = [ 'delivery', 'pickup', 'dine-in', 'pos' ];
    return in_array( $type, $valid, true ) ? $type : 'delivery';
}

/**
 * Get allowed order status transitions.
 * Returns the next valid statuses from a given status.
 */
function dd_order_status_transitions(): array {
    return [
        'pending'   => [ 'confirmed', 'cancelled' ],
        'confirmed' => [ 'ready', 'cancelled' ],
        'ready'     => [ 'delivered', 'cancelled' ],
        'delivered' => [],
        'cancelled' => [],
    ];
}

/**
 * Get human-readable label for an order status.
 */
function dd_order_status_label( string $status ): string {
    $labels = [
        'pending'   => __( 'Pending',   'dish-dash' ),
        'confirmed' => __( 'Confirmed', 'dish-dash' ),
        'ready'     => __( 'Ready',     'dish-dash' ),
        'delivered' => __( 'Delivered', 'dish-dash' ),
        'cancelled' => __( 'Cancelled', 'dish-dash' ),
    ];
    return $labels[ $status ] ?? ucfirst( $status );
}

/**
 * Log a debug message to the WP error log.
 * Only logs when WP_DEBUG is true.
 */
function dd_log( mixed $data, string $label = 'Dish Dash' ): void {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $message = is_array( $data ) || is_object( $data )
            ? wp_json_encode( $data )
            : (string) $data;
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        error_log( "[{$label}] {$message}" );
    }
}

/**
 * Get the Dish Dash menu page URL.
 */
function dd_menu_url(): string {
    $page_id = get_option( 'dish_dash_menu_page_id' );
    return $page_id ? get_permalink( $page_id ) : home_url( '/restaurant-menu/' );
}

/**
 * Get the Dish Dash cart page URL.
 */
function dd_cart_url(): string {
    $page_id = get_option( 'dish_dash_cart_page_id' );
    return $page_id ? get_permalink( $page_id ) : home_url( '/cart-dd/' );
}

/**
 * Get the Dish Dash checkout page URL.
 */
function dd_checkout_url(): string {
    $page_id = get_option( 'dish_dash_checkout_page_id' );
    return $page_id ? get_permalink( $page_id ) : home_url( '/checkout-dd/' );
}

/**
 * Get the order tracking page URL, optionally with an order number.
 */
function dd_track_url( string $order_number = '' ): string {
    $page_id = get_option( 'dish_dash_track_page_id' );
    $base    = $page_id ? get_permalink( $page_id ) : home_url( '/track-order/' );
    return $order_number
        ? add_query_arg( 'order', urlencode( $order_number ), $base )
        : $base;
}

/**
 * DD_Hours — Opening hours state engine.
 * Used by page-dishdash.php (banner) and dd_remind_me_open AJAX handler.
 */
class DD_Hours {

    /**
     * Returns current restaurant state.
     * @return string  'open' | 'closing_soon' | 'break' | 'closed'
     */
    public static function get_state() {
        $schedule = self::get_schedule();

        if ( empty( $schedule ) ) {
            return 'open'; // no hours configured = don't block orders
        }

        $today = self::get_today_data( $schedule );

        if ( empty( $today ) ) {
            return 'open'; // today not in schedule = don't block orders
        }

        if ( ! $today['open'] || empty( $today['sessions'] ) ) {
            return 'closed';
        }

        $now              = self::now();
        $closing_soon_min = (int) get_option( 'dd_closing_soon_minutes', 30 );
        $sessions         = $today['sessions'];

        foreach ( $sessions as $i => $session ) {
            $open  = self::to_datetime( $session[0] );
            $close = self::to_datetime( $session[1] );

            if ( $now >= $open && $now < $close ) {
                $diff_min = ( $close->getTimestamp() - $now->getTimestamp() ) / 60;
                return $diff_min <= $closing_soon_min ? 'closing_soon' : 'open';
            }
        }

        // Check if we are between sessions (mid-day break)
        if ( count( $sessions ) === 2 ) {
            $end_s1   = self::to_datetime( $sessions[0][1] );
            $start_s2 = self::to_datetime( $sessions[1][0] );
            if ( $now >= $end_s1 && $now < $start_s2 ) {
                return 'break';
            }
        }

        return 'closed';
    }

    /**
     * For 'closing_soon' — returns close time string e.g. "10:00 PM"
     */
    public static function get_current_close_time() {
        $schedule = self::get_schedule();
        $today    = self::get_today_data( $schedule );
        $now      = self::now();

        foreach ( $today['sessions'] ?? [] as $session ) {
            $open  = self::to_datetime( $session[0] );
            $close = self::to_datetime( $session[1] );
            if ( $now >= $open && $now < $close ) {
                return $close->format( 'g:i A' );
            }
        }
        return '';
    }

    /**
     * For 'break' — returns next session open time string e.g. "5:00 PM" and time remaining.
     */
    public static function get_break_info() {
        $schedule = self::get_schedule();
        $today    = self::get_today_data( $schedule );
        $now      = self::now();
        $sessions = $today['sessions'] ?? [];

        if ( count( $sessions ) >= 2 ) {
            $start_s2 = self::to_datetime( $sessions[1][0] );
            $diff     = $start_s2->getTimestamp() - $now->getTimestamp();
            return [
                'reopens_at' => $start_s2->format( 'g:i A' ),
                'countdown'  => self::format_diff( $diff ),
            ];
        }
        return [ 'reopens_at' => '', 'countdown' => '' ];
    }

    /**
     * For 'closed' — returns next open info: day label, time, and countdown.
     */
    public static function get_next_open_info() {
        $schedule = self::get_schedule();
        $tz       = new DateTimeZone( get_option( 'dd_timezone', 'Africa/Kigali' ) );
        $now      = new DateTime( 'now', $tz );
        $days     = [ 'monday','tuesday','wednesday','thursday','friday','saturday','sunday' ];

        // Look ahead up to 7 days
        for ( $i = 0; $i <= 7; $i++ ) {
            $check_dt = ( clone $now )->modify( "+{$i} days" );
            $day_name = strtolower( $check_dt->format( 'l' ) );
            $day_data = $schedule[ $day_name ] ?? [];

            if ( empty( $day_data['open'] ) || empty( $day_data['sessions'] ) ) {
                continue;
            }

            foreach ( $day_data['sessions'] as $session ) {
                $open_dt = DateTime::createFromFormat(
                    'Y-m-d H:i',
                    $check_dt->format( 'Y-m-d' ) . ' ' . $session[0],
                    $tz
                );
                if ( $open_dt > $now ) {
                    $diff = $open_dt->getTimestamp() - $now->getTimestamp();
                    return [
                        'day'       => $i === 0 ? 'Today' : ( $i === 1 ? 'Tomorrow' : ucfirst( $day_name ) ),
                        'time'      => $open_dt->format( 'g:i A' ),
                        'countdown' => self::format_diff( $diff ),
                    ];
                }
            }
        }

        return [ 'day' => '', 'time' => '', 'countdown' => '' ];
    }

    /**
     * Returns human-readable schedule summary for the closed banner body text.
     * Example: "Monday – Sunday  11:00 AM – 10:00 PM"
     */
    public static function get_hours_summary() {
        $schedule = self::get_schedule();
        $days     = [ 'monday','tuesday','wednesday','thursday','friday','saturday','sunday' ];
        $lines    = [];

        foreach ( $days as $day ) {
            $data = $schedule[ $day ] ?? [];
            if ( empty( $data['open'] ) || empty( $data['sessions'] ) ) {
                continue;
            }
            $s     = $data['sessions'][0];
            $open  = DateTime::createFromFormat( 'H:i', $s[0] );
            $close = DateTime::createFromFormat( 'H:i', $s[1] );
            $label = ucfirst( $day );
            $time  = $open->format( 'g:i A' ) . ' – ' . $close->format( 'g:i A' );
            $lines[] = $label . ': ' . $time;
        }

        // Simplify: if all days same hours, collapse to one line
        $unique = array_unique( array_column(
            array_map( fn($l) => [ 'time' => substr( $l, strpos($l,':') + 2 ) ], $lines ),
            'time'
        ) );

        if ( count( $unique ) === 1 && count( $lines ) === 7 ) {
            return 'Monday – Sunday  ' . trim( $unique[0] );
        }
        return implode( "\n", $lines );
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private static function get_schedule() {
        $raw = get_option( 'dd_opening_hours', '' );
        if ( empty( $raw ) ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private static function get_today_data( $schedule ) {
        $day = strtolower( self::now()->format( 'l' ) );
        return $schedule[ $day ] ?? [];
    }

    private static function now() {
        $tz = get_option( 'dd_timezone', 'Africa/Kigali' );
        return new DateTime( 'now', new DateTimeZone( $tz ) );
    }

    private static function to_datetime( $time_str ) {
        $tz  = get_option( 'dd_timezone', 'Africa/Kigali' );
        $now = new DateTime( 'now', new DateTimeZone( $tz ) );
        return DateTime::createFromFormat(
            'Y-m-d H:i',
            $now->format( 'Y-m-d' ) . ' ' . $time_str,
            new DateTimeZone( $tz )
        );
    }

    private static function format_diff( $seconds ) {
        $h = floor( $seconds / 3600 );
        $m = floor( ( $seconds % 3600 ) / 60 );
        if ( $h > 0 ) return "{$h}h {$m}m";
        return "{$m}m";
    }

    /**
     * Returns Unix timestamp of the end of the current active session.
     * Returns 0 if not currently in a session.
     */
    public static function get_current_close_ts() {
        $schedule = self::get_schedule();
        $today    = self::get_today_data( $schedule );
        $now      = self::now();

        foreach ( $today['sessions'] ?? [] as $session ) {
            $open  = self::to_datetime( $session[0] );
            $close = self::to_datetime( $session[1] );
            if ( $now >= $open && $now < $close ) {
                return $close->getTimestamp();
            }
        }
        return 0;
    }

    /**
     * Returns Unix timestamp of the next opening time.
     * Returns 0 if nothing found in the next 7 days.
     */
    public static function get_next_open_info_ts() {
        $schedule = self::get_schedule();
        $tz       = new DateTimeZone( get_option( 'dd_timezone', 'Africa/Kigali' ) );
        $now      = new DateTime( 'now', $tz );

        for ( $i = 0; $i <= 7; $i++ ) {
            $check_dt = ( clone $now )->modify( "+{$i} days" );
            $day_name = strtolower( $check_dt->format( 'l' ) );
            $day_data = $schedule[ $day_name ] ?? [];

            if ( empty( $day_data['open'] ) || empty( $day_data['sessions'] ) ) continue;

            foreach ( $day_data['sessions'] as $session ) {
                $open_dt = DateTime::createFromFormat(
                    'Y-m-d H:i',
                    $check_dt->format( 'Y-m-d' ) . ' ' . $session[0],
                    $tz
                );
                if ( $open_dt > $now ) {
                    return $open_dt->getTimestamp();
                }
            }
        }
        return 0;
    }
}
