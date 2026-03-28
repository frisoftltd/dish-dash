
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
            'dd_header_show_track_order' => 'sanitize_text_field',
            'dd_header_show_cart'        => 'sanitize_text_field',

            // 2. Hero
            'dish_dash_hero_title'       => 'wp_kses_post',
            'dish_dash_hero_subtitle'    => 'sanitize_text_field',
            'dish_dash_hero_image'       => 'esc_url_raw',
            'dd_hero_btn1_label'         => 'sanitize_text_field',
            'dd_hero_btn1_link'          => 'esc_url_raw',
            'dd_hero_btn2_label'         => 'sanitize_text_field',
            'dd_hero_btn2_link'          => 'esc_url_raw',
            'dd_hero_btn3_label'         => 'sanitize_text_field',
            'dd_hero_btn3_link'          => 'esc_url_raw',
            'dd_hero_show_chips'         => 'sanitize_text_field',
            'dd_hero_chip_1'             => 'sanitize_text_field',
            'dd_hero_chip_2'             => 'sanitize_text_field',
            'dd_hero_chip_3'             => 'sanitize_text_field',
            'dd_hero_chip_4'             => 'sanitize_text_field',

            // 3. Categories
            'dd_categories_show'         => 'sanitize_text_field',
            'dd_categories_title'        => 'sanitize_text_field',
            'dd_categories_count'        => 'absint',

            // 4. Featured
            'dd_featured_show'           => 'sanitize_text_field',
            'dd_featured_title'          => 'sanitize_text_field',
            'dd_featured_count'          => 'absint',
            'dd_featured_orderby'        => 'sanitize_text_field',
            'dd_featured_order'          => 'sanitize_text_field',
            'dd_featured_tag'            => 'sanitize_text_field',
            'dd_featured_show_chips'     => 'sanitize_text_field',

            // 5. Selected Category
            'dd_selcat_show'             => 'sanitize_text_field',
            'dd_selcat_title'            => 'sanitize_text_field',
            'dd_selcat_count'            => 'absint',
            'dd_selcat_default'          => 'sanitize_text_field',

            // 6. Reviews
            'dd_reviews_show'            => 'sanitize_text_field',
            'dd_reviews_title'           => 'sanitize_text_field',
            'dd_reviews_source'          => 'sanitize_text_field',
            'dd_reviews_google_place_id' => 'sanitize_text_field',
            'dd_reviews_google_api_key'  => 'sanitize_text_field',
            'dd_reviews_count'           => 'absint',
            'dd_reviews_min_rating'      => 'absint',

            // 7. Footer
            'dd_footer_show_description' => 'sanitize_text_field',
            'dd_footer_description'      => 'sanitize_textarea_field',
            'dd_footer_show_social'      => 'sanitize_text_field',
            'dish_dash_opening_hours'    => 'sanitize_textarea_field',
            'dd_footer_show_explore'     => 'sanitize_text_field',
            'dd_footer_show_contact'     => 'sanitize_text_field',
            'dd_footer_show_hours'       => 'sanitize_text_field',
        ];

        foreach ( $fields as $key => $sanitizer ) {
            if ( $sanitizer === 'absint' ) {
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

    private function checked( string $key, string $default = '1' ): string {
        return checked( $this->get( $key, $default ), '1', false );
    }

    private function select( string $key, string $value ): string {
        return selected( $this->get( $key ), $value, false );
    }

    private function field( string $key, string $type = 'text', string $placeholder = '', $default = '' ): void {
        $val = esc_attr( $this->get( $key, $default ) );
        echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $key ) . '" value="' . $val . '" placeholder="' . esc_attr( $placeholder ) . '" class="dd-hp-input">';
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
            <div class="notice notice-success is-dismissible" style="margin-top:1rem">
                <p>✅ <strong><?php esc_html_e( 'Homepage settings saved!', 'dish-dash' ); ?></strong></p>
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
                                <label><?php esc_html_e( 'Hero Image URL', 'dish-dash' ); ?></label>
                                <div style="display:flex;gap:8px;">
                                    <input type="text" name="dish_dash_hero_image" id="dd_hero_image"
                                        value="<?php echo esc_attr( $this->get( 'dish_dash_hero_image' ) ); ?>"
                                        placeholder="https://..." class="dd-hp-input dd-hp-input--wide">
                                    <button type="button" class="button dd-upload-btn" data-target="dd_hero_image" data-preview="dd_hero_img_preview">
                                        <?php esc_html_e( 'Upload', 'dish-dash' ); ?>
                                    </button>
                                </div>
                                <?php $hero_img = $this->get( 'dish_dash_hero_image' ); ?>
                                <?php if ( $hero_img ) : ?>
                                    <img id="dd_hero_img_preview" src="<?php echo esc_url( $hero_img ); ?>" style="max-height:80px;margin-top:8px;border-radius:8px;">
                                <?php else : ?>
                                    <img id="dd_hero_img_preview" src="" style="max-height:80px;margin-top:8px;border-radius:8px;display:none;">
                                <?php endif; ?>
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
                                            <option value="<?php echo $n; ?>" <?php $this->select( 'dd_categories_count', $n ); ?>>
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
                                        <?php foreach ( [ 4, 6, 8, 12 ] as $n ) : ?>
                                            <option value="<?php echo $n; ?>" <?php selected( $this->get( 'dd_featured_count', 8 ), $n ); ?>><?php echo $n; ?></option>
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
                                    <label><?php esc_html_e( 'Filter by Tag', 'dish-dash' ); ?></label>
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
                                        <span><?php esc_html_e( 'Show filter chips (All / Featured / Veg / Popular)', 'dish-dash' ); ?></span>
                                    </label>
                                </div>
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
                            <div class="dd-hp-grid-3">
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Section Title', 'dish-dash' ); ?></label>
                                    <input type="text" name="dd_selcat_title"
                                        value="<?php echo esc_attr( $this->get( 'dd_selcat_title', 'Selected category' ) ); ?>"
                                        class="dd-hp-input">
                                </div>
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Products per Category', 'dish-dash' ); ?></label>
                                    <select name="dd_selcat_count" class="dd-hp-select">
                                        <?php foreach ( [ 4, 6, 8, 12 ] as $n ) : ?>
                                            <option value="<?php echo $n; ?>" <?php selected( $this->get( 'dd_selcat_count', 8 ), $n ); ?>><?php echo $n; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Default Category', 'dish-dash' ); ?></label>
                                    <select name="dd_selcat_default" class="dd-hp-select">
                                        <option value=""><?php esc_html_e( 'First Category', 'dish-dash' ); ?></option>
                                        <?php foreach ( $cats as $cat ) : ?>
                                            <option value="<?php echo esc_attr( $cat->slug ); ?>" <?php $this->select( 'dd_selcat_default', $cat->slug ); ?>>
                                                <?php echo esc_html( $cat->name ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
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
                                            <option value="<?php echo $n; ?>" <?php selected( $this->get( 'dd_reviews_count', 3 ), $n ); ?>><?php echo $n; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="dd-hp-field">
                                    <label><?php esc_html_e( 'Minimum Star Rating', 'dish-dash' ); ?></label>
                                    <select name="dd_reviews_min_rating" class="dd-hp-select">
                                        <?php foreach ( [ 3, 4, 5 ] as $n ) : ?>
                                            <option value="<?php echo $n; ?>" <?php selected( $this->get( 'dd_reviews_min_rating', 4 ), $n ); ?>><?php echo $n; ?>+ ⭐</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Manual Reviews -->
                            <div class="dd-hp-subsection" id="dd_manual_reviews" style="<?php echo $this->get('dd_reviews_source', 'manual') !== 'google' ? '' : 'display:none'; ?>">
                                <h3><?php esc_html_e( 'Manual Reviews', 'dish-dash' ); ?></h3>
                                <?php
                                $manual = json_decode( $this->get( 'dd_reviews_manual', '[]' ), true ) ?: [];
                                // Ensure at least 3 rows
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

            // Media uploader
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
