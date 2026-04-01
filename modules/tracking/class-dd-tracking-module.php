<?php
/**
 * Dish Dash – Tracking Module
 *
 * Records all user behavior events into dishdash_user_events
 * and keeps dishdash_user_profiles up to date.
 *
 * Completely independent — communicates only via hooks.
 * No UI, no admin page. Pure data layer.
 *
 * @package DishDash
 * @since   2.5.33
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Tracking_Module extends DD_Module {

    protected string $id = 'tracking';

    /** Cookie name for guest session tracking */
    const COOKIE_NAME = 'dd_session';

    /** Cookie lifetime — 90 days */
    const COOKIE_TTL = 90 * DAY_IN_SECONDS;

    public function init(): void {
        // Enqueue tracking.js on all frontend pages
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX — public (works for both logged-in and guests)
        DD_Ajax::register( 'dd_track_event',          [ $this, 'ajax_track_event' ],          false );
        DD_Ajax::register( 'dd_get_recent_searches',  [ $this, 'ajax_get_recent_searches' ],  false );
        DD_Ajax::register( 'dd_get_search_products',  [ $this, 'ajax_get_search_products' ],  false );
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

        $wpdb->insert(
            $wpdb->prefix . 'dishdash_user_events',
            [
                'user_id'     => $user_id,
                'session_id'  => $session_id,
                'event_type'  => $event_type,
                'product_id'  => $product_id,
                'category_id' => $category_id,
                'meta'        => $meta,
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%d', '%d', '%s', '%s' ]
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
        $meta_json  = ! empty( $meta ) ? wp_json_encode( $meta ) : null;

        $wpdb->insert(
            $wpdb->prefix . 'dishdash_user_events',
            [
                'user_id'     => $user_id,
                'session_id'  => $session_id,
                'event_type'  => $event_type,
                'product_id'  => $product_id,
                'category_id' => $category_id,
                'meta'        => $meta_json,
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );
    }
}
