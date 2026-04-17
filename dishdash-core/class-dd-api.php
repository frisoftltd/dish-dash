<?php
/**
 * File:    dishdash-core/class-dd-api.php
 * Module:  DD_API (static facade class)
 * Purpose: Unified data-access layer — wraps all WooCommerce, $wpdb, and
 *          wp_options calls behind a consistent, normalized API.
 *          New features use DD_API exclusively. Existing code migrates
 *          gradually as files are touched for other reasons.
 *          The Python AI microservice will consume these same normalized
 *          shapes via the REST layer added in a future release.
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core)
 *   - DD_Settings::get(), DD_Settings::get_public_settings()
 *   - wc_get_product(), wc_get_products(), get_terms() (WooCommerce)
 *   - $wpdb global (dishdash_user_events table)
 *   - WordPress transients API (wp_options-backed caching)
 *
 * Dependents (files that need this):
 *   - Any new module or feature that needs product, category, event,
 *     or settings data (use DD_API instead of direct WC / wpdb calls)
 *   - Future REST layer — DD_REST endpoints will call DD_API internally
 *
 * Static methods:
 *   Products:   get_product, get_products, get_products_by_category,
 *               get_popular_products
 *   Categories: get_category, get_all_categories, get_category_by_slug
 *   Events:     get_user_events, get_session_events,
 *               get_event_counts_by_type
 *   Settings:   get_setting (pass-through), get_all_settings (pass-through)
 *   Cache:      clear_cache, register_hooks
 *
 * ── NORMALIZED DATA SHAPES ───────────────────────────────────────────────────
 *
 * Product:
 *   [
 *     'id'                   => int,
 *     'name'                 => string,
 *     'slug'                 => string,
 *     'price'                => float,        // current selling price
 *     'regular_price'        => float,
 *     'currency'             => string,       // e.g. 'RWF'
 *     'description'          => string,       // stripped of HTML
 *     'short_description'    => string,       // stripped of HTML
 *     'image_url'            => string,
 *     'image_thumbnail_url'  => string,
 *     'gallery_urls'         => string[],
 *     'categories'           => [
 *                                 ['id' => int, 'slug' => string, 'name' => string],
 *                                 ...
 *                               ],
 *     'in_stock'             => bool,
 *     'permalink'            => string,
 *     'created_at'           => string,       // ISO 8601
 *     'updated_at'           => string,       // ISO 8601
 *   ]
 *
 * Category:
 *   [
 *     'id'            => int,
 *     'slug'          => string,
 *     'name'          => string,
 *     'description'   => string,
 *     'image_url'     => string,
 *     'product_count' => int,
 *     'parent_id'     => int|null,
 *   ]
 *
 * Event (mirrors wp_dishdash_user_events row):
 *   [
 *     'id'             => int,
 *     'user_id'        => int|null,
 *     'session_id'     => string,
 *     'event_type'     => string,
 *     'product_id'     => int|null,
 *     'category_id'    => int|null,
 *     'meta'           => array,      // JSON-decoded
 *     'schema_version' => int,
 *     'created_at'     => string,     // ISO 8601
 *   ]
 *
 * ── CACHING ──────────────────────────────────────────────────────────────────
 *
 *   Transient-based, 5-minute TTL.
 *   Key format: dd_api_{method_name}_{md5(serialize($args))}
 *   Cleared automatically on save_post_product / woocommerce_update_product.
 *   Manually via DD_API::clear_cache().
 *
 * Last modified: v3.1.15
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_API {

    // ── No instantiation ──────────────────────────────────────────────────────

    private function __construct() {}

    // ─────────────────────────────────────────────────────────────────────────
    //  PRODUCT METHODS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch a single product by ID and return a normalized array.
     *
     * @param  int        $id  WooCommerce product ID.
     * @return array|null      Normalized product array, or null if not found.
     *
     * @example
     *   $product = DD_API::get_product( 42 );
     *   echo $product['name'];   // 'Butter Chicken'
     *   echo $product['price'];  // 8500.0
     */
    public static function get_product( int $id ): ?array {
        $cache_key = 'dd_api_get_product_' . md5( serialize( [ 'id' => $id ] ) );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        if ( ! function_exists( 'wc_get_product' ) ) return null;

        $product = wc_get_product( $id );
        if ( ! $product instanceof WC_Product ) return null;

        $result = self::normalize_product( $product );
        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

        return $result;
    }

    /**
     * Fetch multiple products matching WooCommerce query args.
     * Returns an array of normalized product arrays (never WC_Product objects).
     *
     * @param  array $args  wc_get_products() compatible args.
     *                      Defaults: ['limit' => 10, 'status' => 'publish'].
     * @return array        Array of normalized product arrays.
     *
     * @example
     *   $products = DD_API::get_products( ['limit' => 4, 'orderby' => 'popularity'] );
     *   foreach ( $products as $p ) { echo $p['name']; }
     */
    public static function get_products( array $args = [] ): array {
        $cache_key = 'dd_api_get_products_' . md5( serialize( $args ) );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        if ( ! function_exists( 'wc_get_products' ) ) return [];

        $query_args = wp_parse_args( $args, [ 'limit' => 10, 'status' => 'publish' ] );
        $products   = wc_get_products( $query_args );
        $result     = array_values( array_map( [ __CLASS__, 'normalize_product' ], $products ) );

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

        return $result;
    }

    /**
     * Fetch products belonging to a specific WooCommerce category slug.
     * Convenience wrapper around get_products().
     *
     * @param  string $cat_slug  WooCommerce product category slug.
     * @param  int    $limit     Max products to return. -1 = all.
     * @return array             Array of normalized product arrays.
     *
     * @example
     *   $starters = DD_API::get_products_by_category( 'starters', 8 );
     */
    public static function get_products_by_category( string $cat_slug, int $limit = -1 ): array {
        return self::get_products( [
            'limit'    => $limit,
            'category' => [ $cat_slug ],
        ] );
    }

    /**
     * Fetch the most-viewed products, ranked by view_product event count.
     * Queries dishdash_user_events for event counts, then fetches each product.
     * Result is cached for 5 minutes (heavier query).
     *
     * @param  int   $limit  Max products to return. Default 10.
     * @return array         Array of normalized product arrays, popularity order.
     *
     * @example
     *   $popular = DD_API::get_popular_products( 6 );
     */
    public static function get_popular_products( int $limit = 10 ): array {
        global $wpdb;

        $cache_key = 'dd_api_get_popular_products_' . md5( serialize( [ 'limit' => $limit ] ) );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $table = $wpdb->prefix . 'dishdash_user_events';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $product_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT product_id
             FROM   {$table}
             WHERE  event_type  = 'view_product'
               AND  product_id  IS NOT NULL
             GROUP  BY product_id
             ORDER  BY COUNT(*) DESC
             LIMIT  %d",
            $limit
        ) );

        $products = [];
        foreach ( $product_ids as $id ) {
            $product = self::get_product( (int) $id );
            if ( $product !== null ) {
                $products[] = $product;
            }
        }

        set_transient( $cache_key, $products, 5 * MINUTE_IN_SECONDS );

        return $products;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  CATEGORY METHODS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch a single WooCommerce product category by ID or slug.
     *
     * @param  int|string $slug_or_id  Numeric term ID or category slug string.
     * @return array|null              Normalized category array, or null.
     *
     * @example
     *   $cat = DD_API::get_category( 'mains' );
     *   echo $cat['product_count'];  // 14
     */
    public static function get_category( int|string $slug_or_id ): ?array {
        $cache_key = 'dd_api_get_category_' . md5( serialize( [ 'id' => $slug_or_id ] ) );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        if ( is_int( $slug_or_id ) ) {
            $term = get_term( $slug_or_id, 'product_cat' );
        } else {
            $term = get_term_by( 'slug', $slug_or_id, 'product_cat' );
        }

        if ( ! $term instanceof WP_Term ) return null;

        $result = self::normalize_category( $term );
        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

        return $result;
    }

    /**
     * Fetch all WooCommerce product categories (excluding 'uncategorized').
     *
     * @return array  Array of normalized category arrays.
     *
     * @example
     *   $cats = DD_API::get_all_categories();
     *   foreach ( $cats as $c ) { echo $c['name'] . ': ' . $c['product_count']; }
     */
    public static function get_all_categories(): array {
        $cache_key = 'dd_api_get_all_categories_' . md5( 'all' );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $uncategorized = get_term_by( 'slug', 'uncategorized', 'product_cat' );
        $exclude       = $uncategorized instanceof WP_Term ? [ $uncategorized->term_id ] : [];

        $terms = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'exclude'    => $exclude,
        ] );

        if ( is_wp_error( $terms ) ) return [];

        $result = array_values( array_map( [ __CLASS__, 'normalize_category' ], $terms ) );
        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

        return $result;
    }

    /**
     * Fetch a single WooCommerce product category by slug.
     * Explicit slug-only convenience alias for get_category().
     *
     * @param  string     $slug  WooCommerce category slug.
     * @return array|null        Normalized category array, or null.
     *
     * @example
     *   $drinks = DD_API::get_category_by_slug( 'drinks' );
     */
    public static function get_category_by_slug( string $slug ): ?array {
        return self::get_category( $slug );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  EVENT METHODS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch recent events for a logged-in user from dishdash_user_events.
     * Results include all event_types ordered newest-first.
     *
     * @param  int   $user_id  WordPress user ID.
     * @param  int   $limit    Max rows. Default 100.
     * @return array           Array of normalized event arrays.
     *
     * @example
     *   $events = DD_API::get_user_events( get_current_user_id(), 20 );
     */
    public static function get_user_events( int $user_id, int $limit = 100 ): array {
        global $wpdb;

        $cache_key = 'dd_api_get_user_events_' . md5( serialize( [ 'uid' => $user_id, 'limit' => $limit ] ) );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $table = $wpdb->prefix . 'dishdash_user_events';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE  user_id = %d
             ORDER  BY created_at DESC
             LIMIT  %d",
            $user_id,
            $limit
        ) );

        $result = array_map( [ __CLASS__, 'normalize_event' ], $rows ?: [] );
        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

        return $result;
    }

    /**
     * Fetch recent events for a guest session from dishdash_user_events.
     *
     * @param  string $session_id  DD session UUID (from dd_session cookie).
     * @param  int    $limit       Max rows. Default 100.
     * @return array               Array of normalized event arrays.
     *
     * @example
     *   $session_id = DD_Tracking_Module::get_session_id();
     *   $events     = DD_API::get_session_events( $session_id, 50 );
     */
    public static function get_session_events( string $session_id, int $limit = 100 ): array {
        global $wpdb;

        $cache_key = 'dd_api_get_session_events_' . md5( serialize( [ 'sid' => $session_id, 'limit' => $limit ] ) );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $table = $wpdb->prefix . 'dishdash_user_events';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE  session_id = %s
             ORDER  BY created_at DESC
             LIMIT  %d",
            $session_id,
            $limit
        ) );

        $result = array_map( [ __CLASS__, 'normalize_event' ], $rows ?: [] );
        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

        return $result;
    }

    /**
     * Return a summary of event counts grouped by event_type.
     * Optionally filtered to events since a given datetime string.
     * Useful for analytics dashboards and the Python microservice.
     *
     * @param  string|null $since  MySQL datetime string 'YYYY-MM-DD HH:MM:SS',
     *                             or null for all-time counts.
     * @return array               Associative array: [ 'event_type' => int count ].
     *
     * @example
     *   $all_time = DD_API::get_event_counts_by_type();
     *   // [ 'view_product' => 4821, 'add_to_cart' => 390, ... ]
     *
     *   $today = DD_API::get_event_counts_by_type( date('Y-m-d') . ' 00:00:00' );
     */
    public static function get_event_counts_by_type( ?string $since = null ): array {
        global $wpdb;

        $cache_key = 'dd_api_get_event_counts_by_type_' . md5( serialize( [ 'since' => $since ] ) );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $table = $wpdb->prefix . 'dishdash_user_events';

        if ( $since !== null ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT event_type, COUNT(*) AS cnt
                 FROM   {$table}
                 WHERE  created_at >= %s
                 GROUP  BY event_type
                 ORDER  BY cnt DESC",
                $since
            ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                "SELECT event_type, COUNT(*) AS cnt
                 FROM   {$wpdb->prefix}dishdash_user_events
                 GROUP  BY event_type
                 ORDER  BY cnt DESC"
            );
        }

        $result = [];
        foreach ( $rows ?: [] as $row ) {
            $result[ $row->event_type ] = (int) $row->cnt;
        }

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  SETTINGS METHODS  (pass-through delegation to DD_Settings)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get a single Dish Dash setting value.
     * Delegates to DD_Settings::get() — key is auto-prefixed with dish_dash_.
     *
     * @param  string $key      Setting key without the dish_dash_ prefix.
     * @param  mixed  $default  Fallback value if option is not set.
     * @return mixed            The stored value or $default.
     *
     * @example
     *   $color = DD_API::get_setting( 'primary_color', '#6B1D1D' );
     *   $name  = DD_API::get_setting( 'restaurant_name', 'Restaurant' );
     */
    public static function get_setting( string $key, mixed $default = null ): mixed {
        return DD_Settings::get( $key, $default );
    }

    /**
     * Get all public Dish Dash settings as an associative array.
     * Delegates to DD_Settings::get_public_settings().
     * Includes currency, tax, feature flags, and page URLs.
     *
     * @return array  Associative array of all public settings.
     *
     * @example
     *   $settings = DD_API::get_all_settings();
     *   echo $settings['currency_symbol'];  // 'RWF'
     *   echo $settings['enable_delivery'];  // true
     */
    public static function get_all_settings(): array {
        return DD_Settings::get_public_settings();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  CACHE MANAGEMENT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Delete all dd_api_* transients from the options table.
     * Called automatically when products are saved/updated.
     * Can also be called manually after bulk imports or data migrations.
     *
     * @return void
     *
     * @example
     *   DD_API::clear_cache();
     */
    public static function clear_cache(): void {
        global $wpdb;

        $like_value   = $wpdb->esc_like( '_transient_dd_api_' ) . '%';
        $like_timeout = $wpdb->esc_like( '_transient_timeout_dd_api_' ) . '%';

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE  option_name LIKE %s
                OR  option_name LIKE %s",
            $like_value,
            $like_timeout
        ) );
    }

    /**
     * Register WordPress hooks for automatic cache invalidation.
     * Called once from the plugins_loaded bootstrap in dish-dash.php.
     * Hooks save_post_product and woocommerce_update_product to clear_cache().
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'save_post_product',          [ __CLASS__, 'clear_cache' ] );
        add_action( 'woocommerce_update_product', [ __CLASS__, 'clear_cache' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PRIVATE NORMALIZERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert a WC_Product object into a normalized array.
     * This is the single place that defines what "a product" looks like
     * throughout the entire Dish Dash data layer.
     *
     * @param  WC_Product $product
     * @return array
     */
    private static function normalize_product( WC_Product $product ): array {
        $image_id            = $product->get_image_id();
        $image_url           = $image_id ? (string) wp_get_attachment_image_url( $image_id, 'full' ) : '';
        $image_thumbnail_url = $image_id ? (string) wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';

        $gallery_urls = array_values( array_filter( array_map(
            fn( int $gid ) => (string) wp_get_attachment_image_url( $gid, 'large' ),
            $product->get_gallery_image_ids()
        ) ) );

        $raw_terms    = get_the_terms( $product->get_id(), 'product_cat' ) ?: [];
        $categories   = array_values( array_map(
            fn( WP_Term $t ) => [ 'id' => $t->term_id, 'slug' => $t->slug, 'name' => $t->name ],
            $raw_terms
        ) );
        $category_ids = array_values( array_map( fn( WP_Term $t ) => $t->term_id, $raw_terms ) );

        $currency   = DD_Settings::get( 'currency_symbol', 'RWF' );
        $created_dt = $product->get_date_created();
        $updated_dt = $product->get_date_modified();

        // Prep time — stored as custom post meta by DD_Menu_Module.
        $prep_time = (int) get_post_meta( $product->get_id(), '_dd_prep_time', true );

        // Rating — WooCommerce average rating (string → float).
        $rating = (float) $product->get_average_rating();

        // is_simple — true when product type is 'simple' (no variant selection required).
        $is_simple = $product->is_type( 'simple' );

        // Attributes — normalized for mobile UI variant pickers.
        $raw_attributes = $product->get_attributes();
        $attributes     = [];
        foreach ( $raw_attributes as $attr ) {
            if ( ! $attr->get_visible() ) continue;
            $options = $attr->is_taxonomy()
                ? wc_get_product_terms( $product->get_id(), $attr->get_name(), [ 'fields' => 'names' ] )
                : $attr->get_options();
            $attributes[] = [
                'name'    => wc_attribute_label( $attr->get_name(), $product ),
                'options' => array_values( (array) $options ),
            ];
        }

        return [
            'id'                  => $product->get_id(),
            'name'                => $product->get_name(),
            'slug'                => $product->get_slug(),
            'price'               => (float) $product->get_price(),
            'regular_price'       => (float) $product->get_regular_price(),
            'currency'            => $currency,
            'description'         => wp_strip_all_tags( $product->get_description() ),
            'short_description'   => wp_strip_all_tags( $product->get_short_description() ),
            'image_url'           => $image_url,
            'image_thumbnail_url' => $image_thumbnail_url,
            'gallery_urls'        => $gallery_urls,
            'categories'          => $categories,
            'category_ids'        => $category_ids,
            'in_stock'            => $product->is_in_stock(),
            'permalink'           => (string) get_permalink( $product->get_id() ),
            'created_at'          => $created_dt ? $created_dt->format( 'c' ) : '',
            'updated_at'          => $updated_dt ? $updated_dt->format( 'c' ) : '',
            'prep_time'           => $prep_time ?: null,
            'rating'              => $rating,
            'is_simple'           => $is_simple,
            'attributes'          => $attributes,
        ];
    }

    /**
     * Convert a WP_Term (product_cat) into a normalized category array.
     *
     * @param  WP_Term $term
     * @return array
     */
    private static function normalize_category( WP_Term $term ): array {
        $thumbnail_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
        $image_url    = $thumbnail_id ? (string) wp_get_attachment_image_url( $thumbnail_id, 'full' ) : '';

        return [
            'id'            => $term->term_id,
            'slug'          => $term->slug,
            'name'          => $term->name,
            'description'   => $term->description,
            'image_url'     => $image_url,
            'product_count' => (int) $term->count,
            'parent_id'     => $term->parent ?: null,
        ];
    }

    /**
     * Convert a $wpdb result row from dishdash_user_events into a
     * normalized event array with a decoded meta field and ISO 8601 timestamp.
     *
     * @param  object $row  stdClass row from $wpdb->get_results().
     * @return array
     */
    private static function normalize_event( object $row ): array {
        $meta = null;
        if ( ! empty( $row->meta ) ) {
            $decoded = json_decode( $row->meta, true );
            $meta    = is_array( $decoded ) ? $decoded : [];
        }

        // Convert MySQL datetime to ISO 8601 (Python datetime.fromisoformat-compatible).
        try {
            $dt         = new DateTime( $row->created_at );
            $created_at = $dt->format( 'c' );
        } catch ( \Exception $e ) {
            $created_at = $row->created_at ?? '';
        }

        return [
            'id'             => (int) $row->id,
            'user_id'        => isset( $row->user_id ) && $row->user_id !== null ? (int) $row->user_id : null,
            'session_id'     => (string) $row->session_id,
            'event_type'     => (string) $row->event_type,
            'product_id'     => isset( $row->product_id ) && $row->product_id !== null ? (int) $row->product_id : null,
            'category_id'    => isset( $row->category_id ) && $row->category_id !== null ? (int) $row->category_id : null,
            'meta'           => $meta ?? [],
            'schema_version' => (int) ( $row->schema_version ?? 1 ),
            'created_at'     => $created_at,
        ];
    }
}
