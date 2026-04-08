<?php
/**
 * Dish Dash – Homepage Settings Module
 *
 * Admin settings for all 7 homepage sections.
 * All data stored in wp_options, read by page-dishdash.php.
 *
 * @package DishDash
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Homepage_Module extends DD_Module {

    protected string $id = 'homepage';

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'register_admin_page' ] );
        add_action( 'admin_init',            [ $this, 'save_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // ── Google Reviews AJAX (public — no login required) ──
        DD_Ajax::register( 'dd_get_reviews', [ $this, 'ajax_get_reviews' ], false );
    }

    // ─────────────────────────────────────────
    //  ADMIN MENU
    // ─────────────────────────────────────────
    public function register_admin_page(): void {
        add_submenu_page(
            'dish-dash',
            __( 'Homepage Settings', 'dish-dash' ),
            __( '🏠 Homepage', 'dish-dash' ),
            'manage_options',
            'dish-dash-homepage',
            [ $this, 'render_admin_page' ]
        );
    }

    // ─────────────────────────────────────────
    //  ENQUEUE ASSETS
    // ─────────────────────────────────────────
    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'dish-dash-homepage' ) === false ) return;
        wp_enqueue_media();
    }

    // ─────────────────────────────────────────
    //  SAVE SETTINGS
    // ─────────────────────────────────────────
    public function save_settings(): void {
        if (
            ! isset( $_POST['dd_homepage_save'] ) ||
            ! check_admin_referer( 'dd_homepage_settings', 'dd_homepage_nonce' ) ||
            ! current_user_can( 'manage_options' )
        ) return;

        $fields = [
            // 1. Header
            'dd_header_show_track_order' => 'checkbox',
            'dd_header_show_cart'        => 'checkbox',

            // 2. Hero
            'dish_dash_hero_title'       => 'wp_kses_post',
            'dish_dash_hero_subtitle'    => 'sanitize_text_field',
            'dish_dash_hero_image'       => 'esc_url_raw',
            'dd_hero_bg_image'           => 'esc_url_raw',
            'dd_hero_overlay_color'      => 'sanitize_hex_color',
            'dd_hero_overlay_opacity'    => 'absint',
            'dd_hero_btn1_label'         => 'sanitize_text_field',
            'dd_hero_btn1_link'          => 'esc_url_raw',
            'dd_hero_btn2_label'         => 'sanitize_text_field',
            'dd_hero_btn2_link'          => 'esc_url_raw',
            'dd_hero_btn3_label'         => 'sanitize_text_field',
            'dd_hero_btn3_link'          => 'esc_url_raw',
            'dd_hero_show_chips'         => 'checkbox',
            'dd_hero_chip_1'             => 'sanitize_text_field',
            'dd_hero_chip_2'             => 'sanitize_text_field',
            'dd_hero_chip_3'             => 'sanitize_text_field',
            'dd_hero_chip_4'             => 'sanitize_text_field',

            // 3. Categories
            'dd_categories_show'         => 'checkbox',
            'dd_categories_title'        => 'sanitize_text_field',
            'dd_categories_count'        => 'absint',

            // 4. Featured
            'dd_featured_show'           => 'checkbox',
            'dd_featured_title'          => 'sanitize_text_field',
            'dd_featured_count'          => 'absint',
            'dd_featured_orderby'        => 'sanitize_text_field',
            'dd_featured_order'          => 'sanitize_text_field',
            'dd_featured_tag'            => 'sanitize_text_field',
            'dd_featured_show_chips'     => 'checkbox',

            // 5. Selected Category
            'dd_selcat_show'             => 'checkbox',
            'dd_selcat_title'            => 'sanitize_text_field',
            'dd_selcat_count'            => 'absint',
            'dd_selcat_default'          => 'sanitize_text_field',
            'dd_reserve_bg_image'        => 'esc_url_raw',

            // 6. Reviews
            'dd_reviews_show'            => 'checkbox',
            'dd_reviews_title'           => 'sanitize_text_field',
            'dd_reviews_source'          => 'sanitize_text_field',
            'dd_reviews_google_place_id' => 'sanitize_text_field',
            'dd_reviews_google_api_key'  => 'sanitize_text_field',
            'dd_reviews_count'           => 'absint',
            'dd_reviews_min_rating'      => 'absint',

            // 7. Footer
            'dd_footer_show_description' => 'checkbox',
            'dd_footer_description'      => 'sanitize_textarea_field',
            'dd_footer_show_social'      => 'checkbox',
            'dish_dash_opening_hours'    => 'sanitize_textarea_field',
            'dd_footer_show_explore'     => 'checkbox',
            'dd_footer_show_contact'     => 'checkbox',
            'dd_footer_show_hours'       => 'checkbox',
        ];

        foreach ( $fields as $key => $sanitizer ) {
            if ( $sanitizer === 'checkbox' ) {
                update_option( $key, isset( $_POST[ $key ] ) ? '1' : '0' );
            } elseif ( $sanitizer === 'absint' ) {
                update_option( $key, absint( $_POST[ $key ] ?? 0 ) );
            } else {
                update_option( $key, $sanitizer( $_POST[ $key ] ?? '' ) );
            }
        }

        // Handle manual reviews (JSON array)
        if ( isset( $_POST['dd_reviews_manual'] ) ) {
            $reviews = array_filter( array_map( 'sanitize_textarea_field', (array) $_POST['dd_reviews_manual'] ) );
            update_option( 'dd_reviews_manual', json_encode( array_values( $reviews ) ) );
        }

        // Handle filter chip tags (array of slugs)
        if ( isset( $_POST['dd_featured_chip_tags'] ) ) {
            $chip_tags = array_map( 'sanitize_text_field', (array) $_POST['dd_featured_chip_tags'] );
            $chip_tags = array_slice( $chip_tags, 0, 8 );
            update_option( 'dd_featured_chip_tags', $chip_tags );
        } else {
            update_option( 'dd_featured_chip_tags', [] );
        }

        // Handle selected category slugs
        if ( isset( $_POST['dd_selcat_slugs'] ) ) {
            $selcat_slugs = array_map( 'sanitize_text_field', (array) $_POST['dd_selcat_slugs'] );
            update_option( 'dd_selcat_slugs', $selcat_slugs );
        } else {
            update_option( 'dd_selcat_slugs', [] );
        }

        // Clear cached Google reviews so fresh data loads after saving
        delete_transient( 'dd_google_reviews_cache' );

        // Clear page cache transients so changes appear instantly
        delete_transient( 'dd_cats_0' );
        for ( $i = 1; $i <= 20; $i++ ) delete_transient( 'dd_cats_' . $i );

        wp_redirect( add_query_arg( [
            'page'  => 'dish-dash-homepage',
            'saved' => '1',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // ─────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────
    private function get( string $key, $default = '' ) {
        return get_option( $key, $default );
    }

    private function checked( string $key, string $default = '1' ): void {
        $val = get_option( $key, $default );
        checked( $val, '1' );
    }

    private function select( string $key, $value, $default = '' ): void {
        $saved = (string) get_option( $key, $default );
        selected( $saved, (string) $value );
    }

    private function field( string $key, string $type = 'text', string $placeholder = '', $default = '' ): void {
        $val = esc_attr( $this->get( $key, $default ) );
        echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $key ) . '" value="' . $val . '" placeholder="' . esc_attr( $placeholder ) . '" class="dd-hp-input">';
    }

    // ─────────────────────────────────────────
    //  AJAX HANDLER — dd_get_reviews
    //  Triggered by frontend.js on page load.
    //  Returns reviews array as JSON.
    // ─────────────────────────────────────────
    public function ajax_get_reviews(): void {
        $reviews = self::get_reviews();
        wp_send_json_success( $reviews );
    }

    // ─────────────────────────────────────────
    //  FETCH REVIEWS (static — reusable)
    //  Fetches from Google Places API or returns
    //  manual reviews. Caches for 12 hours.
    // ─────────────────────────────────────────
    public static function get_reviews(): array {
        $source     = get_option( 'dd_reviews_source', 'manual' );
        $count      = max( 1, (int) get_option( 'dd_reviews_count', 3 ) );
        $min_rating = max( 1, (int) get_option( 'dd_reviews_min_rating', 4 ) );

        // ── Manual reviews ───────────────────
        if ( $source !== 'google' ) {
            $manual = json_decode( get_option( 'dd_reviews_manual', '[]' ), true );
            if ( ! is_array( $manual ) ) $manual = [];
            $out = [];
            foreach ( array_filter( $manual ) as $text ) {
                $out[] = [
                    'author' => '',
                    'rating' => 5,
                    'text'   => $text,
                    'time'   => '',
                    'photo'  => '',
                ];
            }
            return array_slice( $out, 0, $count );
        }

        // ── Google Reviews ───────────────────
        $place_id = get_option( 'dd_reviews_google_place_id', '' );
        $api_key  = get_option( 'dd_reviews_google_api_key', '' );

        if ( ! $place_id || ! $api_key ) {
            return [];
        }

        // Return cached result if still fresh (12-hour cache)
        $cache_key = 'dd_google_reviews_cache';
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        // Call Google Places Details API
        $url = add_query_arg( [
            'place_id' => $place_id,
            'fields'   => 'reviews,rating',
            'key'      => $api_key,
            'language' => 'en',
        ], 'https://maps.googleapis.com/maps/api/place/details/json' );

        $response = wp_remote_get( $url, [ 'timeout' => 10 ] );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['result']['reviews'] ) ) {
            return [];
        }

        // Filter by minimum star rating and shape the data
        $reviews = [];
        foreach ( $body['result']['reviews'] as $r ) {
            if ( (int) ( $r['rating'] ?? 0 ) < $min_rating ) continue;
            $reviews[] = [
                'author' => $r['author_name']               ?? '',
                'rating' => (int) ( $r['rating']            ?? 5 ),
                'text'   => $r['text']                      ?? '',
                'time'   => $r['relative_time_description'] ?? '',
                'photo'  => $r['profile_photo_url']         ?? '',
            ];
        }

        // Limit to configured count
        $reviews = array_slice( $reviews, 0, $count );

        // Cache for 12 hours
        set_transient( $cache_key, $reviews, 12 * HOUR_IN_SECONDS );

        return $reviews;
    }

    // ─────────────────────────────────────────
    //  RENDER ADMIN PAGE
    // ─────────────────────────────────────────
    public function render_admin_page(): void {
        $saved = isset( $_GET['saved'] ) && '1' === $_GET['saved'];

        // Get product tags for featured section
        $product_tags = get_terms( [
            'taxonomy'   => 'product_tag',
            'hide_empty' => true,
        ] );
        if ( is_wp_error( $product_tags ) ) $product_tags = [];

        // Get categories for default category select
        $cats = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
        ] );
        if ( is_wp_error( $cats ) ) $cats = [];
        $cats = array_filter( $cats, fn( $c ) => $c->slug !== 'uncategorized' );
        ?>
        <div class="wrap dd-admin-wrap">

            <div class="dd-admin-header">
                <div class="dd-admin-header__logo">
                    <span class="dd-logo-icon">🏠</span>
                    <div>
                        <h1><?php esc_html_e( 'Homepage Settings', 'dish-dash' ); ?></h1>
                        <span class="dd-version"><?php esc_html_e( 'Control every section of your homepage', 'dish-dash' ); ?></span>
                    </div>
                </div>
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" class="button">
                    <?php esc_html_e( 'Preview Homepage', 'dish-dash' ); ?> ↗
                </a>
            </div>

            <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible" style="margin-top:0;border-left-color:#00a32a;">
                <p style="color:#00a32a;font-weight:700;">✅ <?php esc_html_e( 'Homepage settings saved!', 'dish-dash' ); ?></p>
            </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field( 'dd_homepage_settings', 'dd_homepage_nonce' ); ?>

                <div class="dd-hp-sections">

                    <!-- ═══ 1. HEADER ═══════════════════════════════════ -->
                    <div class="dd-hp-section">
                        <div class="dd-hp-section__header">
                            <span class="dd-hp-section__icon">🔝</span>
                            <h2><?php esc_html_e( '1. Header Section', 'dish-dash' ); ?></h2>
                        </div>
                        <div class="dd-hp-section__body">
                            <div class="dd-hp-row">
                                <label class="dd-hp-toggle">
                                    <input type="checkbox" name="dd_header_show_track_order" value="1" <?php $this->checked( 'dd_header_show_track_order' ); ?>>
                                    <span><?php esc_html_e( 'Show Track Order button', 'dish-dash' ); ?></span>
                                </label>
                                <label class="dd-hp-toggle">
                                    <input type="checkbox" name="dd_header_show_cart" value="1" <?php $this->checked( 'dd_header_show_cart' ); ?>>
                                    <span><?php esc_html_e( 'Show Cart button', 'dish-dash' ); ?></span>
                                </label>
                            </div>
                            <p class="dd-hp-note">Logo and navigation are managed in <a href="<?php echo admin_url('admin.php?page=dish-dash-template'); ?>">Template Settings</a> and <a href="<?php echo admin_url('nav-menus.php'); ?>">Menus</a>.</p>
                        </div>
                    </div>

                    <!-- ═══ 2. HERO ══════════════════════════════════════ -->
                    <div class="dd-hp-section">
                        <div class="dd-hp-section__header">
                            <span class="dd-hp-section__icon">🦸</span>
                            <h2><?php esc_html_e( '2. Hero Section', 'dish-dash' ); ?></h2>
                        </div>
                        <div class="dd-hp-section__body">
                            <div class="dd-hp-grid-2">
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Hero Title', 'dish-dash' ); ?></label>
                                    <input type="text" name="dish_dash_hero_title"
                                        value="<?php echo esc_attr( $this->get( 'dish_dash_hero_title' ) ); ?>"
                                        placeholder="Best Indian Flavor in Kigali"
                                        class="dd-hp-input dd-hp-input--wide">
                                    <span class="dd-hp-hint">You can use &lt;span class="dd-gold"&gt;text&lt;/span&gt; for gold color</span>
                                </div>
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Hero Subtitle', 'dish-dash' ); ?></label>
                                    <input type="text" name="dish_dash_hero_subtitle"
                                        value="<?php echo esc_attr( $this->get( 'dish_dash_hero_subtitle' ) ); ?>"
                                        placeholder="Discover our signature dishes..."
                                        class="dd-hp-input dd-hp-input--wide">
                                </div>
                            </div>

                            <div class="dd-hp-field" style="margin-top:16px;">
                                <label><?php esc_html_e( 'Hero Card Image (right side)', 'dish-dash' ); ?></label>
                                <div style="display:flex;gap:8px;">
                                    <input type="text" name="dish_dash_hero_image" id="dd_hero_image"
                                        value="<?php echo esc_attr( $this->get( 'dish_dash_hero_image' ) ); ?>"
                                        placeholder="https://..." class="dd-hp-input dd-hp-input--wide">
                                    <button type="button" class="button dd-upload-btn" data-target="dd_hero_image" data-preview="dd_hero_img_preview">
                                        <?php esc_html_e( 'Upload', 'dish-dash' ); ?>
                                    </button>
                                </div>
                                <?php $hero_img = $this->get( 'dish_dash_hero_image' ); ?>
                                <div style="margin-top:8px;width:100%;height:160px;background:#f0f0f0;border-radius:12px;overflow:hidden;border:1px solid #e0e0e0;<?php echo $hero_img ? '' : 'display:none'; ?>" id="dd_hero_img_wrap">
                                    <img id="dd_hero_img_preview"
                                        src="<?php echo esc_url( $hero_img ); ?>"
                                        style="width:100%;height:160px;object-fit:cover;object-position:center;display:block;">
                                </div>
                                <?php if ( ! $hero_img ) : ?>
                                    <div id="dd_hero_img_wrap" style="display:none;margin-top:8px;width:100%;height:160px;background:#f0f0f0;border-radius:12px;overflow:hidden;border:1px solid #e0e0e0;">
                                        <img id="dd_hero_img_preview" src="" style="width:100%;height:160px;object-fit:cover;display:block;">
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Hero Background Image -->
                            <div class="dd-hp-field" style="margin-top:16px;">
                                <label><?php esc_html_e( 'Hero Background Image (full section background)', 'dish-dash' ); ?></label>
                                <div style="display:flex;gap:8px;">
                                    <input type="text" name="dd_hero_bg_image" id="dd_hero_bg_image"
                                        value="<?php echo esc_attr( $this->get( 'dd_hero_bg_image' ) ); ?>"
                                        placeholder="https://... (leave empty for no background image)" class="dd-hp-input dd-hp-input--wide">
                                    <button type="button" class="button dd-upload-btn" data-target="dd_hero_bg_image" data-preview="dd_hero_bg_preview">
                                        <?php esc_html_e( 'Upload', 'dish-dash' ); ?>
                                    </button>
                                </div>
                                <?php $bg_img = $this->get( 'dd_hero_bg_image' ); ?>
                                <?php if ( $bg_img ) : ?>
                                <div style="margin-top:8px;width:100%;height:100px;border-radius:12px;overflow:hidden;border:1px solid #e0e0e0;">
                                    <img id="dd_hero_bg_preview" src="<?php echo esc_url( $bg_img ); ?>" style="width:100%;height:100px;object-fit:cover;display:block;">
                                </div>
                                <?php else : ?>
                                <div style="display:none;margin-top:8px;width:100%;height:100px;border-radius:12px;overflow:hidden;border:1px solid #e0e0e0;">
                                    <img id="dd_hero_bg_preview" src="" style="width:100%;height:100px;object-fit:cover;display:block;">
                                </div>
                                <?php endif; ?>
                                <span class="dd-hp-hint">This image shows behind the entire hero section with an overlay on top.</span>
                            </div>

                            <!-- Overlay Color + Opacity -->
                            <div class="dd-hp-grid-2" style="margin-top:16px;">
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Overlay Color', 'dish-dash' ); ?></label>
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <input type="color" name="dd_hero_overlay_color"
                                            value="<?php echo esc_attr( $this->get( 'dd_hero_overlay_color', '#6B1D1D' ) ); ?>"
                                            style="width:48px;height:40px;border:1.5px solid #e0e0e0;border-radius:8px;cursor:pointer;padding:2px;">
                                        <input type="text" name="dd_hero_overlay_color_text"
                                            value="<?php echo esc_attr( $this->get( 'dd_hero_overlay_color', '#6B1D1D' ) ); ?>"
                                            class="dd-hp-input" placeholder="#6B1D1D" style="flex:1;"
                                            id="dd_overlay_color_text">
                                    </div>
                                    <span class="dd-hp-hint">Color of the overlay gradient on top of the background image.</span>
                                </div>
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Overlay Opacity', 'dish-dash' ); ?> — <span id="dd_opacity_val"><?php echo esc_html( $this->get( 'dd_hero_overlay_opacity', '85' ) ); ?>%</span></label>
                                    <input type="range" name="dd_hero_overlay_opacity"
                                        value="<?php echo esc_attr( $this->get( 'dd_hero_overlay_opacity', '85' ) ); ?>"
                                        min="0" max="100" step="5"
                                        oninput="document.getElementById('dd_opacity_val').textContent = this.value + '%'"
                                        style="width:100%;accent-color:#6B1D1D;margin-top:10px;">
                                    <span class="dd-hp-hint">0% = fully transparent, 100% = fully opaque. Lower = more image shows through.</span>
                                </div>
                            </div>

                            <div class="dd-hp-subsection">
                                <h3><?php esc_html_e( 'CTA Buttons', 'dish-dash' ); ?></h3>
                                <div class="dd-hp-grid-3">
                                    <?php
                                    $btns = [
                                        [ 'dd_hero_btn1_label', 'dd_hero_btn1_link', 'Order Now', '#menu', 'Primary (Red)' ],
                                        [ 'dd_hero_btn2_label', 'dd_hero_btn2_link', 'Reserve Table', '#reserve', 'Secondary (Outline)' ],
                                        [ 'dd_hero_btn3_label', 'dd_hero_btn3_link', 'View Full Menu', '/shop/', 'Tertiary (Gold)' ],
                                    ];
                                    foreach ( $btns as $btn ) :
                                    ?>
                                    <div class="dd-hp-field">
                                        <label><?php echo esc_html( $btn[4] ); ?></label>
                                        <input type="text" name="<?php echo $btn[0]; ?>"
                                            value="<?php echo esc_attr( $this->get( $btn[0], $btn[2] ) ); ?>"
                                            placeholder="<?php echo esc_attr( $btn[2] ); ?>"
                                            class="dd-hp-input">
                                        <input type="text" name="<?php echo $btn[1]; ?>"
                                            value="<?php echo esc_attr( $this->get( $btn[1], $btn[3] ) ); ?>"
                                            placeholder="<?php echo esc_attr( $btn[3] ); ?>"
                                            class="dd-hp-input" style="margin-top:6px;">
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="dd-hp-subsection">
                                <h3>
                                    <?php esc_html_e( 'Feature Chips', 'dish-dash' ); ?>
                                    <label class="dd-hp-toggle dd-hp-toggle--inline">
                                        <input type="checkbox" name="dd_hero_show_chips" value="1" <?php $this->checked( 'dd_hero_show_chips' ); ?>>
                                        <span><?php esc_html_e( 'Show', 'dish-dash' ); ?></span>
                                    </label>
                                </h3>
                                <div class="dd-hp-grid-2">
                                    <?php
                                    $chip_defaults = [ 'Authentic Indian Flavors', 'Delivery & Pickup Available', 'Elegant Dine-In Experience', 'Freshly Prepared Daily' ];
                                    for ( $i = 1; $i <= 4; $i++ ) :
                                    ?>
                                    <div class="dd-hp-field">
                                        <label><?php printf( esc_html__( 'Chip %d', 'dish-dash' ), $i ); ?></label>
                                        <input type="text" name="dd_hero_chip_<?php echo $i; ?>"
                                            value="<?php echo esc_attr( $this->get( "dd_hero_chip_{$i}", $chip_defaults[$i-1] ) ); ?>"
                                            class="dd-hp-input">
                                    </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ 3. BROWSE BY CATEGORY ════════════════════════ -->
                    <div class="dd-hp-section">
                        <div class="dd-hp-section__header">
                            <span class="dd-hp-section__icon">🍽️</span>
                            <h2><?php esc_html_e( '3. Browse by Category Section', 'dish-dash' ); ?></h2>
                            <label class="dd-hp-toggle dd-hp-toggle--header">
                                <input type="checkbox" name="dd_categories_show" value="1" <?php $this->checked( 'dd_categories_show' ); ?>>
                                <span><?php esc_html_e( 'Show Section', 'dish-dash' ); ?></span>
                            </label>
                        </div>
                        <div class="dd-hp-section__body">
                            <div class="dd-hp-grid-2">
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Section Title', 'dish-dash' ); ?></label>
                                    <input type="text" name="dd_categories_title"
                                        value="<?php echo esc_attr( $this->get( 'dd_categories_title', 'Choose your craving' ) ); ?>"
                                        class="dd-hp-input">
                                </div>
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Max Categories to Show', 'dish-dash' ); ?></label>
                                    <select name="dd_categories_count" class="dd-hp-select">
                                        <?php foreach ( [ 4, 6, 8, 10, 0 ] as $n ) : ?>
                                            <option value="<?php echo $n; ?>" <?php $this->select( 'dd_categories_count', $n, 0 ); ?>>
                                                <?php echo $n === 0 ? esc_html__( 'Show All', 'dish-dash' ) : $n; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <p class="dd-hp-note">Each category circle links to its WooCommerce category page. Manage categories in <a href="<?php echo admin_url('edit-tags.php?taxonomy=product_cat&post_type=product'); ?>">Products → Categories</a>.</p>
                        </div>
                    </div>

                    <!-- ═══ 4. FEATURED DISHES ═══════════════════════════ -->
                    <div class="dd-hp-section">
                        <div class="dd-hp-section__header">
                            <span class="dd-hp-section__icon">⭐</span>
                            <h2><?php esc_html_e( '4. Featured Dishes Section', 'dish-dash' ); ?></h2>
                            <label class="dd-hp-toggle dd-hp-toggle--header">
                                <input type="checkbox" name="dd_featured_show" value="1" <?php $this->checked( 'dd_featured_show' ); ?>>
                                <span><?php esc_html_e( 'Show Section', 'dish-dash' ); ?></span>
                            </label>
                        </div>
                        <div class="dd-hp-section__body">
                            <div class="dd-hp-grid-3">
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Section Title', 'dish-dash' ); ?></label>
                                    <input type="text" name="dd_featured_title"
                                        value="<?php echo esc_attr( $this->get( 'dd_featured_title', 'Best sellers today' ) ); ?>"
                                        class="dd-hp-input">
                                </div>
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Number of Products', 'dish-dash' ); ?></label>
                                    <select name="dd_featured_count" class="dd-hp-select">
                                        <?php foreach ( [ 4, 6, 8, 12, 0 ] as $n ) : ?>
                                            <option value="<?php echo $n; ?>" <?php $this->select( 'dd_featured_count', $n, 8 ); ?>>
                                                <?php echo $n === 0 ? esc_html__( 'All Products', 'dish-dash' ) : $n; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Sort By', 'dish-dash' ); ?></label>
                                    <select name="dd_featured_orderby" class="dd-hp-select">
                                        <option value="popularity" <?php $this->select( 'dd_featured_orderby', 'popularity' ); ?>><?php esc_html_e( 'Most Popular', 'dish-dash' ); ?></option>
                                        <option value="date"       <?php $this->select( 'dd_featured_orderby', 'date' ); ?>><?php esc_html_e( 'Latest', 'dish-dash' ); ?></option>
                                        <option value="price"      <?php $this->select( 'dd_featured_orderby', 'price' ); ?>><?php esc_html_e( 'Price: Low to High', 'dish-dash' ); ?></option>
                                        <option value="price-desc" <?php $this->select( 'dd_featured_orderby', 'price-desc' ); ?>><?php esc_html_e( 'Price: High to Low', 'dish-dash' ); ?></option>
                                        <option value="rand"       <?php $this->select( 'dd_featured_orderby', 'rand' ); ?>><?php esc_html_e( 'Random', 'dish-dash' ); ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="dd-hp-grid-2" style="margin-top:16px;">
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Filter by Tag (default products to fetch)', 'dish-dash' ); ?></label>
                                    <select name="dd_featured_tag" class="dd-hp-select">
                                        <option value=""><?php esc_html_e( 'All Products', 'dish-dash' ); ?></option>
                                        <?php foreach ( $product_tags as $tag ) : ?>
                                            <option value="<?php echo esc_attr( $tag->slug ); ?>" <?php $this->select( 'dd_featured_tag', $tag->slug ); ?>>
                                                <?php echo esc_html( $tag->name ); ?> (<?php echo $tag->count; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="dd-hp-field">
                                    <label class="dd-hp-toggle">
                                        <input type="checkbox" name="dd_featured_show_chips" value="1" <?php $this->checked( 'dd_featured_show_chips' ); ?>>
                                        <span><?php esc_html_e( 'Show filter chips', 'dish-dash' ); ?></span>
                                    </label>
                                </div>
                            </div>

                            <!-- Dynamic Filter Chips from Product Tags -->
                            <?php
                            $saved_chip_tags = get_option( 'dd_featured_chip_tags', [] );
                            if ( is_string( $saved_chip_tags ) ) {
                                $saved_chip_tags = array_filter( explode( ',', $saved_chip_tags ) );
                            }
                            ?>
                            <div class="dd-hp-subsection" id="dd_chips_tags_wrap">
                                <h3>🏷️ <?php esc_html_e( 'Filter Chip Tags (max 8)', 'dish-dash' ); ?></h3>
                                <p class="dd-hp-hint" style="margin-bottom:12px;">"All" is always the first chip. Select up to 8 product tags to show as filter chips. Each chip filters products by that tag.</p>
                                <?php if ( empty( $product_tags ) ) : ?>
                                    <p class="dd-hp-note">No product tags found. <a href="<?php echo admin_url('edit-tags.php?taxonomy=product_tag&post_type=product'); ?>">Add tags to your products</a> first.</p>
                                <?php else : ?>
                                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;flex-wrap:wrap;">
                                    <?php foreach ( $product_tags as $tag ) : ?>
                                    <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid #e0e0e0;border-radius:10px;cursor:pointer;font-size:13px;font-weight:500;background:#fafafa;transition:all 0.2s;"
                                           class="dd-tag-chip-label <?php echo in_array( $tag->slug, (array) $saved_chip_tags ) ? 'dd-tag-selected' : ''; ?>">
                                        <input type="checkbox"
                                               name="dd_featured_chip_tags[]"
                                               value="<?php echo esc_attr( $tag->slug ); ?>"
                                               data-name="<?php echo esc_attr( $tag->name ); ?>"
                                               <?php checked( in_array( $tag->slug, (array) $saved_chip_tags ), true ); ?>
                                               style="accent-color:#6B1D1D;width:16px;height:16px;">
                                        <?php echo esc_html( $tag->name ); ?>
                                        <span style="margin-left:auto;color:#bbb;font-size:11px;"><?php echo $tag->count; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                    <!-- ═══ RESERVE TABLE ═══════════════════════════════ -->
                    <div class="dd-hp-section">
                        <div class="dd-hp-section__header">
                            <span class="dd-hp-section__icon">📅</span>
                            <h2><?php esc_html_e( 'Reserve Table Section', 'dish-dash' ); ?></h2>
                        </div>
                        <div class="dd-hp-section__body">
                            <div class="dd-hp-field">
                                <label><?php esc_html_e( 'Background Image', 'dish-dash' ); ?></label>
                                <div style="display:flex;gap:8px;">
                                    <input type="text" name="dd_reserve_bg_image" id="dd_reserve_bg_image"
                                        value="<?php echo esc_attr( $this->get( 'dd_reserve_bg_image' ) ); ?>"
                                        placeholder="https://... (leave empty for default restaurant image)"
                                        class="dd-hp-input dd-hp-input--wide">
                                    <button type="button" class="button dd-upload-btn"
                                        data-target="dd_reserve_bg_image"
                                        data-preview="dd_reserve_bg_preview">
                                        <?php esc_html_e( 'Upload', 'dish-dash' ); ?>
                                    </button>
                                </div>
                                <?php $reserve_bg = $this->get( 'dd_reserve_bg_image' ); ?>
                                <?php if ( $reserve_bg ) : ?>
                                <div style="margin-top:8px;width:100%;height:120px;border-radius:12px;overflow:hidden;border:1px solid #e0e0e0;">
                                    <img id="dd_reserve_bg_preview" src="<?php echo esc_url( $reserve_bg ); ?>"
                                        style="width:100%;height:120px;object-fit:cover;display:block;">
                                </div>
                                <?php else : ?>
                                <div style="display:none;margin-top:8px;width:100%;height:120px;border-radius:12px;overflow:hidden;border:1px solid #e0e0e0;">
                                    <img id="dd_reserve_bg_preview" src=""
                                        style="width:100%;height:120px;object-fit:cover;display:block;">
                                </div>
                                <?php endif; ?>
                                <span class="dd-hp-hint">This image shows as the background of the Reserve Table section. Recommended: wide restaurant/dining atmosphere photo.</span>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ 5. SELECTED CATEGORY ═════════════════════════ -->
                    <div class="dd-hp-section">
                        <div class="dd-hp-section__header">
                            <span class="dd-hp-section__icon">📂</span>
                            <h2><?php esc_html_e( '5. Selected Category Section', 'dish-dash' ); ?></h2>
                            <label class="dd-hp-toggle dd-hp-toggle--header">
                                <input type="checkbox" name="dd_selcat_show" value="1" <?php $this->checked( 'dd_selcat_show' ); ?>>
                                <span><?php esc_html_e( 'Show Section', 'dish-dash' ); ?></span>
                            </label>
                        </div>
                        <div class="dd-hp-section__body">
                            <div class="dd-hp-grid-2">
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Section Title', 'dish-dash' ); ?></label>
                                    <input type="text" name="dd_selcat_title"
                                        value="<?php echo esc_attr( $this->get( 'dd_selcat_title', 'Selected category' ) ); ?>"
                                        class="dd-hp-input">
                                </div>
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Products per Category', 'dish-dash' ); ?></label>
                                    <select name="dd_selcat_count" class="dd-hp-select">
                                        <?php foreach ( [ 4, 6, 8, 12, 0 ] as $n ) : ?>
                                            <option value="<?php echo $n; ?>" <?php $this->select( 'dd_selcat_count', $n, 8 ); ?>>
                                                <?php echo $n === 0 ? esc_html__( 'All Products', 'dish-dash' ) : $n; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Multi-select categories -->
                            <?php
                            $saved_selcats = get_option( 'dd_selcat_slugs', [] );
                            if ( is_string( $saved_selcats ) && ! empty( $saved_selcats ) ) {
                                $saved_selcats = array_filter( explode( ',', $saved_selcats ) );
                            }
                            if ( empty( $saved_selcats ) ) $saved_selcats = [];
                            ?>
                            <div class="dd-hp-subsection" style="margin-top:16px;">
                                <h3>📂 <?php esc_html_e( 'Categories to Show', 'dish-dash' ); ?></h3>
                                <p class="dd-hp-hint" style="margin-bottom:12px;">Select which categories appear in this section. Leave all unchecked to show ALL categories.</p>
                                <?php if ( empty( $cats ) ) : ?>
                                    <p class="dd-hp-note">No categories found. <a href="<?php echo admin_url('edit-tags.php?taxonomy=product_cat&post_type=product'); ?>">Add categories</a> first.</p>
                                <?php else : ?>
                                <div style="margin-bottom:10px;">
                                    <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid #e0e0e0;border-radius:10px;cursor:pointer;font-size:13px;font-weight:700;background:#f0f0f0;">
                                        <input type="checkbox" id="dd_selcat_all" style="accent-color:#6B1D1D;width:16px;height:16px;"
                                            <?php checked( empty( $saved_selcats ), true ); ?>>
                                        All Categories
                                    </label>
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;" id="dd_selcat_grid">
                                    <?php foreach ( $cats as $cat ) : ?>
                                    <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid #e0e0e0;border-radius:10px;cursor:pointer;font-size:13px;font-weight:500;background:#fafafa;">
                                        <input type="checkbox"
                                               name="dd_selcat_slugs[]"
                                               value="<?php echo esc_attr( $cat->slug ); ?>"
                                               <?php checked( in_array( $cat->slug, (array) $saved_selcats ), true ); ?>
                                               style="accent-color:#6B1D1D;width:16px;height:16px;">
                                        <?php echo esc_html( $cat->name ); ?>
                                        <span style="margin-left:auto;color:#bbb;font-size:11px;"><?php echo $cat->count; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ 6. GOOGLE REVIEWS ════════════════════════════ -->
                    <div class="dd-hp-section">
                        <div class="dd-hp-section__header">
                            <span class="dd-hp-section__icon">⭐</span>
                            <h2><?php esc_html_e( '6. Google Reviews Section', 'dish-dash' ); ?></h2>
                            <label class="dd-hp-toggle dd-hp-toggle--header">
                                <input type="checkbox" name="dd_reviews_show" value="1" <?php $this->checked( 'dd_reviews_show' ); ?>>
                                <span><?php esc_html_e( 'Show Section', 'dish-dash' ); ?></span>
                            </label>
                        </div>
                        <div class="dd-hp-section__body">
                            <div class="dd-hp-grid-2">
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Section Title', 'dish-dash' ); ?></label>
                                    <input type="text" name="dd_reviews_title"
                                        value="<?php echo esc_attr( $this->get( 'dd_reviews_title', 'What our customers say' ) ); ?>"
                                        class="dd-hp-input">
                                </div>
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Reviews Source', 'dish-dash' ); ?></label>
                                    <select name="dd_reviews_source" class="dd-hp-select" id="dd_reviews_source">
                                        <option value="manual" <?php $this->select( 'dd_reviews_source', 'manual' ); ?>><?php esc_html_e( 'Manual Reviews', 'dish-dash' ); ?></option>
                                        <option value="google" <?php $this->select( 'dd_reviews_source', 'google' ); ?>><?php esc_html_e( 'Google Reviews (API)', 'dish-dash' ); ?></option>
                                    </select>
                                </div>
                            </div>

                            <!-- Google API Settings -->
                            <div class="dd-hp-subsection" id="dd_google_settings" style="<?php echo $this->get('dd_reviews_source') === 'google' ? '' : 'display:none'; ?>">
                                <h3>🔑 <?php esc_html_e( 'Google Reviews API', 'dish-dash' ); ?></h3>
                                <div class="dd-hp-grid-2">
                                    <div class="dd-hp-field">
                                        <label><?php esc_html_e( 'Google Place ID', 'dish-dash' ); ?></label>
                                        <input type="text" name="dd_reviews_google_place_id"
                                            value="<?php echo esc_attr( $this->get( 'dd_reviews_google_place_id' ) ); ?>"
                                            placeholder="ChIJ..."
                                            class="dd-hp-input">
                                        <span class="dd-hp-hint">Find your Place ID at <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank">Google Place ID Finder</a></span>
                                    </div>
                                    <div class="dd-hp-field">
                                        <label><?php esc_html_e( 'Google API Key', 'dish-dash' ); ?></label>
                                        <input type="password" name="dd_reviews_google_api_key"
                                            value="<?php echo esc_attr( $this->get( 'dd_reviews_google_api_key' ) ); ?>"
                                            placeholder="AIza..."
                                            class="dd-hp-input">
                                        <span class="dd-hp-hint">Enable Places API in <a href="https://console.cloud.google.com" target="_blank">Google Cloud Console</a></span>
                                    </div>
                                </div>
                            </div>

                            <div class="dd-hp-grid-2" style="margin-top:16px;">
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Number of Reviews to Show', 'dish-dash' ); ?></label>
                                    <select name="dd_reviews_count" class="dd-hp-select">
                                        <?php foreach ( [ 3, 4, 5, 6 ] as $n ) : ?>
                                            <option value="<?php echo $n; ?>" <?php $this->select( 'dd_reviews_count', $n, 3 ); ?>><?php echo $n; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Minimum Star Rating', 'dish-dash' ); ?></label>
                                    <select name="dd_reviews_min_rating" class="dd-hp-select">
                                        <?php foreach ( [ 3, 4, 5 ] as $n ) : ?>
                                            <option value="<?php echo $n; ?>" <?php $this->select( 'dd_reviews_min_rating', $n, 4 ); ?>><?php echo $n; ?>+ ⭐</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Manual Reviews -->
                            <div class="dd-hp-subsection" id="dd_manual_reviews" style="<?php echo $this->get('dd_reviews_source', 'manual') !== 'google' ? '' : 'display:none'; ?>">
                                <h3><?php esc_html_e( 'Manual Reviews', 'dish-dash' ); ?></h3>
                                <?php
                                $manual = json_decode( $this->get( 'dd_reviews_manual', '[]' ), true ) ?: [];
                                while ( count( $manual ) < 3 ) $manual[] = '';
                                foreach ( $manual as $idx => $review ) :
                                ?>
                                <div class="dd-hp-field" style="margin-bottom:12px;">
                                    <label><?php printf( esc_html__( 'Review %d', 'dish-dash' ), $idx + 1 ); ?></label>
                                    <textarea name="dd_reviews_manual[]" rows="2" class="dd-hp-input dd-hp-input--wide" placeholder="<?php esc_attr_e( 'Enter review text...', 'dish-dash' ); ?>"><?php echo esc_textarea( $review ); ?></textarea>
                                </div>
                                <?php endforeach; ?>
                            </div>

                        </div>
                    </div>

                    <!-- ═══ 7. FOOTER ════════════════════════════════════ -->
                    <div class="dd-hp-section">
                        <div class="dd-hp-section__header">
                            <span class="dd-hp-section__icon">🦶</span>
                            <h2><?php esc_html_e( '7. Footer Section', 'dish-dash' ); ?></h2>
                        </div>
                        <div class="dd-hp-section__body">
                            <div class="dd-hp-grid-2">
                                <div class="dd-hp-field">
                                    <label class="dd-hp-toggle">
                                        <input type="checkbox" name="dd_footer_show_description" value="1" <?php $this->checked( 'dd_footer_show_description' ); ?>>
                                        <span><?php esc_html_e( 'Show Footer Description', 'dish-dash' ); ?></span>
                                    </label>
                                    <textarea name="dd_footer_description" rows="3" class="dd-hp-input dd-hp-input--wide" style="margin-top:8px;" placeholder="<?php esc_attr_e( 'Premium Indian dining and a refined digital ordering experience...', 'dish-dash' ); ?>"><?php echo esc_textarea( $this->get( 'dd_footer_description', 'Premium Indian dining and a refined digital ordering experience designed for smooth discovery, fast checkout, and repeat cravings.' ) ); ?></textarea>
                                </div>
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Opening Hours', 'dish-dash' ); ?></label>
                                    <textarea name="dish_dash_opening_hours" rows="3" class="dd-hp-input dd-hp-input--wide" placeholder="Mon - Fri: 10:00 - 22:00&#10;Sat - Sun: 09:00 - 23:00"><?php echo esc_textarea( $this->get( 'dish_dash_opening_hours' ) ); ?></textarea>
                                    <span class="dd-hp-hint">One line per entry</span>
                                </div>
                            </div>
                            <div class="dd-hp-row" style="margin-top:16px;">
                                <label class="dd-hp-toggle">
                                    <input type="checkbox" name="dd_footer_show_social" value="1" <?php $this->checked( 'dd_footer_show_social' ); ?>>
                                    <span><?php esc_html_e( 'Show Social Media Icons', 'dish-dash' ); ?></span>
                                </label>
                                <label class="dd-hp-toggle">
                                    <input type="checkbox" name="dd_footer_show_explore" value="1" <?php $this->checked( 'dd_footer_show_explore' ); ?>>
                                    <span><?php esc_html_e( 'Show Explore Column', 'dish-dash' ); ?></span>
                                </label>
                                <label class="dd-hp-toggle">
                                    <input type="checkbox" name="dd_footer_show_contact" value="1" <?php $this->checked( 'dd_footer_show_contact' ); ?>>
                                    <span><?php esc_html_e( 'Show Contact Column', 'dish-dash' ); ?></span>
                                </label>
                                <label class="dd-hp-toggle">
                                    <input type="checkbox" name="dd_footer_show_hours" value="1" <?php $this->checked( 'dd_footer_show_hours' ); ?>>
                                    <span><?php esc_html_e( 'Show Opening Hours Column', 'dish-dash' ); ?></span>
                                </label>
                            </div>
                            <p class="dd-hp-note">Social media links are managed in <a href="<?php echo admin_url('admin.php?page=dish-dash-template'); ?>">Template Settings</a>.</p>
                        </div>
                    </div>

                </div><!-- .dd-hp-sections -->

                <!-- Save Button -->
                <div class="dd-hp-save-bar">
                    <span><?php esc_html_e( 'Changes apply immediately on your homepage.', 'dish-dash' ); ?></span>
                    <?php submit_button( '💾 ' . __( 'Save All Homepage Settings', 'dish-dash' ), 'primary large', 'dd_homepage_save', false ); ?>
                </div>

            </form>
        </div>

        <style>
        .dd-hp-sections { display: grid; gap: 20px; margin-top: 20px; }
        .dd-hp-section { background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
        .dd-hp-section__header { display: flex; align-items: center; gap: 12px; padding: 16px 20px; background: #f8f8f8; border-bottom: 1px solid #e0e0e0; }
        .dd-hp-section__header h2 { margin: 0; font-size: 15px; font-weight: 700; flex: 1; }
        .dd-hp-section__icon { font-size: 20px; }
        .dd-hp-section__body { padding: 20px; }
        .dd-hp-subsection { background: #f9f9f9; border: 1px solid #ebebeb; border-radius: 8px; padding: 16px; margin-top: 16px; }
        .dd-hp-subsection h3 { margin: 0 0 14px; font-size: 13px; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: .05em; display: flex; align-items: center; gap: 8px; }
        .dd-hp-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .dd-hp-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        .dd-hp-field { display: flex; flex-direction: column; gap: 6px; }
        .dd-hp-field label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #888; }
        .dd-hp-input { border: 1.5px solid #e0e0e0; border-radius: 8px; padding: 8px 12px; font-size: 13px; width: 100%; transition: border-color .2s; }
        .dd-hp-input:focus { border-color: #6B1D1D; outline: none; box-shadow: 0 0 0 3px rgba(107,29,29,.1); }
        .dd-hp-input--wide { width: 100%; }
        .dd-hp-select { border: 1.5px solid #e0e0e0; border-radius: 8px; padding: 8px 12px; font-size: 13px; width: 100%; background: #fff; }
        .dd-hp-toggle { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 500; }
        .dd-hp-toggle input { width: 16px; height: 16px; cursor: pointer; accent-color: #6B1D1D; }
        .dd-hp-toggle--header { margin-left: auto; }
        .dd-hp-toggle--inline { margin-left: 12px; font-weight: 500; }
        .dd-hp-row { display: flex; flex-wrap: wrap; gap: 20px; align-items: center; }
        .dd-hp-hint { font-size: 11px; color: #999; line-height: 1.4; }
        .dd-hp-hint a { color: #6B1D1D; }
        .dd-hp-note { margin: 12px 0 0; font-size: 12px; color: #999; font-style: italic; }
        .dd-hp-note a { color: #6B1D1D; }
        .dd-hp-save-bar { margin-top: 20px; padding: 16px 20px; background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; display: flex; align-items: center; justify-content: space-between; }
        .dd-hp-save-bar span { color: #666; font-size: 13px; }
        .dd-tag-chip-label:hover {
            border-color: #6B1D1D !important;
            background: #fff5f5 !important;
        }
        .dd-tag-chip-label.dd-tag-selected,
        .dd-tag-chip-label:has(input:checked) {
            border-color: #6B1D1D !important;
            background: #fff5f5 !important;
            color: #6B1D1D !important;
            font-weight: 700 !important;
        }
        @media (max-width: 782px) {
            .dd-hp-grid-2, .dd-hp-grid-3 { grid-template-columns: 1fr; }
        }
        </style>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle Google/Manual reviews
            var sourceSelect = document.getElementById('dd_reviews_source');
            if ( sourceSelect ) {
                sourceSelect.addEventListener('change', function() {
                    var isGoogle = this.value === 'google';
                    document.getElementById('dd_google_settings').style.display = isGoogle ? '' : 'none';
                    document.getElementById('dd_manual_reviews').style.display  = isGoogle ? 'none' : '';
                });
            }

            // All Categories toggle
            var allCatsChk = document.getElementById('dd_selcat_all');
            var catGrid    = document.getElementById('dd_selcat_grid');
            if ( allCatsChk && catGrid ) {
                function toggleCatGrid() {
                    var disabled = allCatsChk.checked;
                    catGrid.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
                        cb.disabled = disabled;
                        cb.closest('label').style.opacity = disabled ? '0.4' : '1';
                    });
                }
                toggleCatGrid();
                allCatsChk.addEventListener('change', toggleCatGrid);
            }
            document.querySelectorAll('.dd-upload-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var target  = btn.dataset.target;
                    var preview = btn.dataset.preview;
                    var frame = wp.media({ title: 'Select Image', multiple: false });
                    frame.on('select', function() {
                        var att = frame.state().get('selection').first().toJSON();
                        document.getElementById(target).value = att.url;
                        var prev = document.getElementById(preview);
                        if (prev) { prev.src = att.url; prev.style.display = ''; }
                    });
                    frame.open();
                });
            });
        });
        </script>
        <?php
    }
}
