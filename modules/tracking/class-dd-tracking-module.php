<?php
/**
 * File:    modules/tracking/class-dd-tracking-module.php
 * Module:  DD_Tracking_Module (extends DD_Module)
 * Purpose: Records user behaviour events (product views, searches, cart
 *          actions, orders) into dishdash_user_events and maintains
 *          per-session profiles in dishdash_user_profiles.
 *          Provides AJAX endpoints consumed by assets/js/tracking.js.
 *
 * Dependencies (this file needs):
 *   - DD_Module base class
 *   - DD_Ajax::register() for AJAX handlers
 *   - $wpdb global (dishdash_user_events, dishdash_user_profiles tables)
 *   - assets/js/tracking.js (enqueued by this module)
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php (instantiates this module)
 *
 * Hooks registered:
 *   - wp_enqueue_scripts → enqueue_assets() (frontend only)
 *
 * AJAX actions registered:
 *   dd_track_event (public), dd_get_recent_searches (public),
 *   dd_get_search_products (public)
 *
 * Allowed event types:
 *   view_product, view_category, search, add_to_cart, remove_from_cart,
 *   order, reorder, page_view
 *
 * DB tables owned:
 *   {prefix}dishdash_user_events, {prefix}dishdash_user_profiles
 *
 * Session cookie: dd_session (90-day TTL)
 *
 * Localized data (window.DDTrackConfig):
 *   ajaxUrl, nonce, sessionId
 *
 * Depends on (modules): NONE — architecture rule
 *
 * Validation:
 *   validate_event_metadata() checks meta against schemas at write-time.
 *   Controlled by DD_EVENT_VALIDATION_MODE constant (warn|strict|disabled).
 *   health_check() runs a 24h diagnostic for the admin Tools page.
 *
 * Last modified: v3.1.16
 *
 * Event schemas loaded at runtime from:
 *   modules/tracking/event-schemas.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Tracking_Module extends DD_Module {

    protected string $id = 'tracking';

    /** Cookie name for guest session tracking */
    const COOKIE_NAME = 'dd_session';

    /** Cookie lifetime — 90 days */
    const COOKIE_TTL = 90 * DAY_IN_SECONDS;

    /**
     * Loaded once on init — holds the event metadata schema definitions
     * from modules/tracking/event-schemas.php.
     * v3.1.16 will use this to enforce schemas at write-time.
     *
     * @var array<string, array>
     */
    private static array $event_schemas = [];

    public function init(): void {
        // Load event schema definitions — enforced as of v3.1.16.
        if ( empty( self::$event_schemas ) ) {
            self::$event_schemas = require __DIR__ . '/event-schemas.php';
        }

        // Run idempotent schema upgrade for existing installs.
        $this->maybe_upgrade_schema();

        // Enqueue tracking.js on all frontend pages
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX — public (works for both logged-in and guests)
        DD_Ajax::register( 'dd_track_event',          [ $this, 'ajax_track_event' ]          );
        DD_Ajax::register( 'dd_get_recent_searches',  [ $this, 'ajax_get_recent_searches' ]  );
        DD_Ajax::register( 'dd_get_search_products',  [ $this, 'ajax_get_search_products' ]  );
    }

    // ─────────────────────────────────────────
    //  ENQUEUE ASSETS
    // ─────────────────────────────────────────
    public function enqueue_assets(): void {
        if ( is_admin() ) return;

        $plugin_url = plugins_url( 'dish-dash' );

        wp_enqueue_script(
            'dd-tracking',
            $plugin_url . '/assets/js/tracking.js',
            [],
            DD_VERSION,
            true
        );

        wp_localize_script( 'dd-tracking', 'DDTrackConfig', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'dd_track' ),
            'sessionId' => $this->get_session_id(),
        ] );
    }

    // ─────────────────────────────────────────
    //  AJAX — TRACK EVENT
    // ─────────────────────────────────────────
    public function ajax_track_event(): void {
        if ( ! check_ajax_referer( 'dd_track', 'nonce', false ) ) {
            wp_send_json_success();
            return;
        }

        $event_type  = sanitize_text_field( $_POST['event_type']  ?? '' );
        $product_id  = absint( $_POST['product_id']  ?? 0 ) ?: null;
        $category_id = absint( $_POST['category_id'] ?? 0 ) ?: null;
        $meta_raw    = $_POST['meta'] ?? '';
        $meta        = null;

        $allowed = [
            'view_product', 'view_category', 'search',
            'add_to_cart', 'remove_from_cart', 'order', 'reorder', 'page_view',
        ];
        if ( ! in_array( $event_type, $allowed, true ) ) {
            wp_send_json_success();
            return;
        }

        if ( $meta_raw ) {
            $decoded = json_decode( stripslashes( $meta_raw ), true );
            if ( is_array( $decoded ) ) {
                $meta = wp_json_encode( $decoded );
            }
        }

        $user_id    = get_current_user_id() ?: null;
        $session_id = $this->get_session_id();

        $this->record_event( $user_id, $session_id, $event_type, $product_id, $category_id, $meta );
        $this->update_profile( $user_id, $session_id, $event_type, $product_id, $category_id );

        wp_send_json_success();
    }

    // ─────────────────────────────────────────
    //  AJAX — GET RECENT SEARCHES
    //  Returns last 5 unique search queries
    //  for this user/session from the DB.
    //  Used by the smart search dropdown.
    // ─────────────────────────────────────────
    public function ajax_get_recent_searches(): void {
        global $wpdb;

        $user_id    = get_current_user_id() ?: null;
        $session_id = self::get_session_id();
        $table      = $wpdb->prefix . 'dishdash_user_events';

        if ( $user_id ) {
            // Logged-in: merge their session + user history
            $rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT JSON_UNQUOTE( JSON_EXTRACT( meta, '$.query' ) )
                 FROM {$table}
                 WHERE event_type = 'search'
                   AND ( user_id = %d OR session_id = %s )
                   AND meta IS NOT NULL
                 ORDER BY created_at DESC
                 LIMIT 20",
                $user_id,
                $session_id
            ) );
        } else {
            // Guest: session only
            $rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT JSON_UNQUOTE( JSON_EXTRACT( meta, '$.query' ) )
                 FROM {$table}
                 WHERE event_type = 'search'
                   AND session_id = %s
                   AND meta IS NOT NULL
                 ORDER BY created_at DESC
                 LIMIT 20",
                $session_id
            ) );
        }

        // Deduplicate, remove nulls/empty, limit to 5
        $seen    = [];
        $unique  = [];
        foreach ( $rows as $q ) {
            $q = trim( (string) $q );
            if ( ! $q || $q === 'null' ) continue;
            $lower = strtolower( $q );
            if ( isset( $seen[ $lower ] ) ) continue;
            $seen[ $lower ] = true;
            $unique[]        = $q;
            if ( count( $unique ) >= 5 ) break;
        }

        wp_send_json_success( $unique );
    }

    // ─────────────────────────────────────────
    //  AJAX — GET SEARCH PRODUCTS
    //  Returns all published products for search
    //  suggestions on pages with no dish cards in DOM.
    // ─────────────────────────────────────────
    public function ajax_get_search_products(): void {
        if ( ! function_exists( 'wc_get_products' ) ) {
            wp_send_json_success( [] );
            return;
        }

        $products = wc_get_products( [
            'limit'   => -1,
            'status'  => 'publish',
            'orderby' => 'popularity',
        ] );

        $data = [];
        foreach ( $products as $product ) {
            $img_id  = $product->get_image_id();
            $img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '';
            $price   = (float) $product->get_price();

            $data[] = [
                'id'    => (string) $product->get_id(),
                'name'  => $product->get_name(),
                'price' => $price ? 'RWF ' . number_format( $price, 0, '.', ',' ) : '',
                'desc'  => wp_trim_words( strip_tags( $product->get_short_description() ?: $product->get_description() ), 12, '...' ),
                'img'   => $img_url ?: '',
                'nonce' => wp_create_nonce( 'dd_add_to_cart' ),
            ];
        }

        wp_send_json_success( $data );
    }

    // ─────────────────────────────────────────
    //  RECORD EVENT
    //  $meta arrives as a JSON string (or null).
    //  Decoded to array for validation, then the
    //  cleaned shape is re-encoded for INSERT.
    // ─────────────────────────────────────────
    private function record_event(
        ?int $user_id,
        string $session_id,
        string $event_type,
        ?int $product_id,
        ?int $category_id,
        ?string $meta
    ): void {
        global $wpdb;

        $mode = defined( 'DD_EVENT_VALIDATION_MODE' ) ? DD_EVENT_VALIDATION_MODE : 'warn';

        if ( $mode !== 'disabled' ) {
            // Decode JSON string → array for validation.
            $meta_array = [];
            if ( $meta !== null ) {
                $decoded    = json_decode( $meta, true );
                $meta_array = is_array( $decoded ) ? $decoded : [];
            }

            $validation = self::validate_event_metadata( $event_type, $meta_array );

            if ( ! $validation['valid'] ) {
                error_log( sprintf(
                    'DD_Tracking: metadata validation failed for [%s] — errors: %s — meta: %s',
                    $event_type,
                    implode( '; ', $validation['errors'] ),
                    wp_json_encode( $meta_array )
                ) );

                if ( $mode === 'strict' ) {
                    return; // Drop the event; never surfaces to the user.
                }
                // 'warn': log and insert original meta unchanged.
            } else {
                // Re-encode only the allowed keys (strips unexpected fields).
                $meta = ! empty( $validation['cleaned_meta'] )
                    ? wp_json_encode( $validation['cleaned_meta'] )
                    : null;
            }
        }

        $wpdb->insert(
            $wpdb->prefix . 'dishdash_user_events',
            [
                'user_id'        => $user_id,
                'session_id'     => $session_id,
                'event_type'     => $event_type,
                'product_id'     => $product_id,
                'category_id'    => $category_id,
                'meta'           => $meta,
                'schema_version' => self::schema_version_for( $event_type ),
                'created_at'     => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s' ]
        );
    }

    // ─────────────────────────────────────────
    //  UPDATE USER PROFILE
    // ─────────────────────────────────────────
    private function update_profile(
        ?int $user_id,
        string $session_id,
        string $event_type,
        ?int $product_id,
        ?int $category_id
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'dishdash_user_profiles';

        if ( $user_id ) {
            $profile = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d", $user_id
            ) );
        } else {
            $profile = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE session_id = %s AND user_id IS NULL", $session_id
            ) );
        }

        $fav_items       = json_decode( $profile->favorite_items      ?? '{}', true ) ?: [];
        $fav_categories  = json_decode( $profile->favorite_categories ?? '{}', true ) ?: [];
        $order_times     = json_decode( $profile->order_times         ?? '[]', true ) ?: [];
        $last_orders     = json_decode( $profile->last_orders         ?? '[]', true ) ?: [];
        $order_count     = (int)   ( $profile->order_count      ?? 0 );
        $avg_order_value = (float) ( $profile->avg_order_value  ?? 0 );

        if ( $product_id && in_array( $event_type, [ 'view_product', 'add_to_cart' ], true ) ) {
            $key = (string) $product_id;
            $fav_items[ $key ] = ( $fav_items[ $key ] ?? 0 ) + 1;
            arsort( $fav_items );
            $fav_items = array_slice( $fav_items, 0, 20, true );
        }

        if ( $category_id && in_array( $event_type, [ 'view_category', 'view_product' ], true ) ) {
            $key = (string) $category_id;
            $fav_categories[ $key ] = ( $fav_categories[ $key ] ?? 0 ) + 1;
            arsort( $fav_categories );
            $fav_categories = array_slice( $fav_categories, 0, 10, true );
        }

        if ( $event_type === 'order' ) {
            $hour          = (int) current_time( 'H' );
            $order_times[] = $hour;
            $order_times   = array_slice( $order_times, -50 );
            $order_count++;
        }

        $data = [
            'session_id'          => $session_id,
            'favorite_items'      => wp_json_encode( $fav_items ),
            'favorite_categories' => wp_json_encode( $fav_categories ),
            'order_count'         => $order_count,
            'avg_order_value'     => $avg_order_value,
            'order_times'         => wp_json_encode( $order_times ),
            'last_orders'         => wp_json_encode( $last_orders ),
            'last_seen'           => current_time( 'mysql' ),
        ];

        if ( $user_id ) {
            $data['user_id'] = $user_id;
        }

        if ( $profile ) {
            $where = $user_id
                ? [ 'user_id' => $user_id ]
                : [ 'session_id' => $session_id ];
            $wpdb->update( $table, $data, $where );
        } else {
            $wpdb->insert( $table, $data );
        }
    }

    // ─────────────────────────────────────────
    //  SESSION ID
    // ─────────────────────────────────────────
    public static function get_session_id(): string {
        if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return sanitize_text_field( $_COOKIE[ self::COOKIE_NAME ] );
        }

        $session_id = wp_generate_uuid4();

        if ( ! headers_sent() ) {
            setcookie(
                self::COOKIE_NAME,
                $session_id,
                time() + self::COOKIE_TTL,
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                true
            );
        }

        return $session_id;
    }

    // ─────────────────────────────────────────
    //  PUBLIC STATIC API
    //  Call from other modules via PHP:
    //  DD_Tracking_Module::track('order', null, null, ['order_id' => 123]);
    //  $meta arrives as a raw PHP array — validated directly (no decode step).
    // ─────────────────────────────────────────
    public static function track(
        string $event_type,
        ?int $product_id   = null,
        ?int $category_id  = null,
        array $meta        = []
    ): void {
        global $wpdb;

        $user_id    = get_current_user_id() ?: null;
        $session_id = self::get_session_id();

        $mode           = defined( 'DD_EVENT_VALIDATION_MODE' ) ? DD_EVENT_VALIDATION_MODE : 'warn';
        $meta_to_insert = $meta;

        if ( $mode !== 'disabled' ) {
            $validation = self::validate_event_metadata( $event_type, $meta );

            if ( ! $validation['valid'] ) {
                error_log( sprintf(
                    'DD_Tracking: metadata validation failed for [%s] — errors: %s — meta: %s',
                    $event_type,
                    implode( '; ', $validation['errors'] ),
                    wp_json_encode( $meta )
                ) );

                if ( $mode === 'strict' ) {
                    return; // Drop the event; never surfaces to the user.
                }
                // 'warn': log and insert original meta unchanged.
            } else {
                // Insert only the allowed keys (strips unexpected fields).
                $meta_to_insert = $validation['cleaned_meta'];
            }
        }

        $meta_json = ! empty( $meta_to_insert ) ? wp_json_encode( $meta_to_insert ) : null;

        $wpdb->insert(
            $wpdb->prefix . 'dishdash_user_events',
            [
                'user_id'        => $user_id,
                'session_id'     => $session_id,
                'event_type'     => $event_type,
                'product_id'     => $product_id,
                'category_id'    => $category_id,
                'meta'           => $meta_json,
                'schema_version' => self::schema_version_for( $event_type ),
                'created_at'     => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s' ]
        );
    }

    // ─────────────────────────────────────────
    //  VALIDATE EVENT METADATA
    //
    //  Validates $meta (PHP array) against the
    //  schema for $event_type loaded from
    //  modules/tracking/event-schemas.php.
    //
    //  Returns:
    //    valid        — bool
    //    errors       — string[] (empty on success)
    //    cleaned_meta — array stripped to allowed keys
    //
    //  Fail-open rule: if $event_schemas is empty
    //  (file missing / load error), logs a warning
    //  and returns valid=true so tracking never
    //  silently breaks due to a schemas file bug.
    // ─────────────────────────────────────────
    private static function validate_event_metadata(
        string $event_type,
        array $meta
    ): array {
        // Fail-open: schemas not loaded — allow event unchanged.
        if ( empty( self::$event_schemas ) ) {
            error_log(
                'DD_Tracking: event-schemas not loaded — skipping validation for [' . $event_type . ']'
            );
            return [ 'valid' => true, 'errors' => [], 'cleaned_meta' => $meta ];
        }

        // Reject unknown event types.
        if ( ! isset( self::$event_schemas[ $event_type ] ) ) {
            return [
                'valid'        => false,
                'errors'       => [ "Unknown event type: {$event_type}" ],
                'cleaned_meta' => [],
            ];
        }

        $schema   = self::$event_schemas[ $event_type ]['metadata_schema'];
        $required = $schema['required'] ?? [];
        $optional = $schema['optional'] ?? [];
        $allowed  = array_merge( $required, $optional );
        $errors   = [];

        // Check required fields are present in meta.
        foreach ( $required as $field ) {
            if ( ! array_key_exists( $field, $meta ) ) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // Strip any keys not declared in required or optional.
        $cleaned_meta = array_intersect_key( $meta, array_flip( $allowed ) );

        return [
            'valid'        => empty( $errors ),
            'errors'       => $errors,
            'cleaned_meta' => $cleaned_meta,
        ];
    }

    // ─────────────────────────────────────────
    //  HEALTH CHECK  (called by admin Tools page)
    //
    //  Queries the last 24 hours of events and
    //  returns five diagnostic metrics:
    //    total               — total event count
    //    by_type             — count per event_type
    //    schema_mismatches   — rows with wrong schema_version
    //    validation_failures — rows failing strict validation
    //    top_errors          — top 5 error messages by count
    //    sample_size         — how many rows were validated
    // ─────────────────────────────────────────
    public static function health_check(): array {
        global $wpdb;

        // Lazy-load schemas so health_check() works even when called
        // before init() (e.g. directly from an admin page request).
        if ( empty( self::$event_schemas ) ) {
            $file = __DIR__ . '/event-schemas.php';
            if ( file_exists( $file ) ) {
                self::$event_schemas = require $file;
            }
        }

        $table = $wpdb->prefix . 'dishdash_user_events';
        $since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

        // ── 1. Total events ───────────────────
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
            $since
        ) );

        // ── 2. Events by type ─────────────────
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $type_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT event_type, COUNT(*) AS cnt
             FROM   {$table}
             WHERE  created_at >= %s
             GROUP  BY event_type
             ORDER  BY cnt DESC",
            $since
        ) );
        $by_type = [];
        foreach ( $type_rows ?: [] as $row ) {
            $by_type[ $row->event_type ] = (int) $row->cnt;
        }

        // ── 3. Schema version mismatches ─────
        $version_map = [
            'view_product'     => DISHDASH_SCHEMA_VIEW_EVENT,
            'view_category'    => DISHDASH_SCHEMA_VIEW_EVENT,
            'page_view'        => DISHDASH_SCHEMA_VIEW_EVENT,
            'search'           => DISHDASH_SCHEMA_SEARCH_EVENT,
            'add_to_cart'      => DISHDASH_SCHEMA_CART_EVENT,
            'remove_from_cart' => DISHDASH_SCHEMA_CART_EVENT,
            'order'            => DISHDASH_SCHEMA_ORDER_EVENT,
            'reorder'          => DISHDASH_SCHEMA_ORDER_EVENT,
        ];
        $schema_mismatches = 0;
        foreach ( $version_map as $etype => $expected_v ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $schema_mismatches += (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE  created_at  >= %s
                   AND  event_type  =  %s
                   AND  schema_version != %d",
                $since,
                $etype,
                $expected_v
            ) );
        }

        // ── 4 & 5. Validation sample ─────────
        // Cap at 500 rows to stay well within request time budget.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sample = $wpdb->get_results( $wpdb->prepare(
            "SELECT event_type, meta FROM {$table}
             WHERE  created_at >= %s
             LIMIT  500",
            $since
        ) );

        $validation_failures = 0;
        $error_tally         = [];

        foreach ( $sample ?: [] as $row ) {
            $meta_array = [];
            if ( ! empty( $row->meta ) ) {
                $decoded    = json_decode( $row->meta, true );
                $meta_array = is_array( $decoded ) ? $decoded : [];
            }

            $result = self::validate_event_metadata( $row->event_type, $meta_array );
            if ( ! $result['valid'] ) {
                $validation_failures++;
                foreach ( $result['errors'] as $err ) {
                    $error_tally[ $err ] = ( $error_tally[ $err ] ?? 0 ) + 1;
                }
            }
        }

        arsort( $error_tally );
        $top_errors = array_slice( $error_tally, 0, 5, true );

        return [
            'total'               => $total,
            'by_type'             => $by_type,
            'schema_mismatches'   => $schema_mismatches,
            'validation_failures' => $validation_failures,
            'sample_size'         => count( $sample ?: [] ),
            'top_errors'          => $top_errors,
        ];
    }

    // ─────────────────────────────────────────
    //  SCHEMA VERSION HELPER
    //  Maps event_type → DISHDASH_SCHEMA_* constant.
    //  Returns 1 as a safe fallback for any unknown type.
    // ─────────────────────────────────────────
    private static function schema_version_for( string $event_type ): int {
        static $map = null;
        if ( $map === null ) {
            $map = [
                'view_product'     => DISHDASH_SCHEMA_VIEW_EVENT,
                'view_category'    => DISHDASH_SCHEMA_VIEW_EVENT,
                'page_view'        => DISHDASH_SCHEMA_VIEW_EVENT,
                'search'           => DISHDASH_SCHEMA_SEARCH_EVENT,
                'add_to_cart'      => DISHDASH_SCHEMA_CART_EVENT,
                'remove_from_cart' => DISHDASH_SCHEMA_CART_EVENT,
                'order'            => DISHDASH_SCHEMA_ORDER_EVENT,
                'reorder'          => DISHDASH_SCHEMA_ORDER_EVENT,
            ];
        }
        return $map[ $event_type ] ?? 1;
    }

    // ─────────────────────────────────────────
    //  IDEMPOTENT SCHEMA MIGRATION
    //  Adds schema_version column + composite index
    //  to existing installs that pre-date this column.
    //  Safe to call on every boot — fast-bails via
    //  wp_options after the first successful run.
    // ─────────────────────────────────────────
    private function maybe_upgrade_schema(): void {
        global $wpdb;

        // Fast bail — option is set once the column is confirmed present.
        if ( get_option( 'dd_uev_has_schema_version' ) ) {
            return;
        }

        $table = $wpdb->prefix . 'dishdash_user_events';

        // Bail if the table doesn't exist yet — fresh install will get the
        // column via the CREATE TABLE in install.php (dbDelta).
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // Column already present (e.g. re-activation after fresh install)?
        // Warm the option cache and bail.
        if ( $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'schema_version'" ) ) {
            update_option( 'dd_uev_has_schema_version', '1' );
            return;
        }

        // Add the column immediately after meta.
        $wpdb->query(
            "ALTER TABLE `{$table}`
             ADD COLUMN `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `meta`"
        );

        // Add the composite index if it doesn't exist yet.
        if ( ! $wpdb->get_row( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_event_type_schema'" ) ) {
            $wpdb->query(
                "ALTER TABLE `{$table}`
                 ADD KEY `idx_event_type_schema` (`event_type`, `schema_version`)"
            );
        }

        update_option( 'dd_uev_has_schema_version', '1' );
    }
}
