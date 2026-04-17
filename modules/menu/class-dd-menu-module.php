<?php
/**
 * File:    modules/menu/class-dd-menu-module.php
 * Module:  DD_Menu_Module (extends DD_Module)
 * Purpose: Registers all frontend shortcodes and the dd_menu_load_products
 *          AJAX handler for paginated product grid loading on the menu page.
 *          Does NOT own any CSS, JS, or DB tables.
 *
 * Dependencies (this file needs):
 *   - DD_Module base class
 *   - WooCommerce: product post type, product_cat taxonomy,
 *     wc_get_product(), WC_Product
 *   - templates/menu/grid.php       (rendered by [dish_dash_menu])
 *   - templates/cart/cart.php       (rendered by [dish_dash_cart])
 *   - templates/checkout/checkout.php (rendered by [dish_dash_checkout])
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php (instantiates this module)
 *
 * Shortcodes registered:
 *   [dish_dash_menu], [dish_dash_cart], [dish_dash_checkout],
 *   [dish_dash_reserve], [dish_dash_track] ⚠️, [dish_dash_account] ⚠️
 *   (⚠️ also registered by DD_Orders_Module — last one wins, see ARCHITECTURE.md)
 *
 * AJAX actions registered:
 *   dd_menu_load_products (public — paginated product grid for desktop menu page)
 *
 * Hooks fired:
 *   - dish_dash_before_menu_render(args)
 *   - apply_filters('dish_dash_menu_query_args', args)
 *
 * WP options read:
 *   dish_dash_menu_page_id, dish_dash_primary_color,
 *   dish_dash_dark_color, dish_dash_restaurant_name
 *
 * Depends on (modules): NONE — architecture rule
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Menu_Module extends DD_Module {

    protected string $id = 'menu';

    public function init(): void {
        add_shortcode( 'dish_dash_menu',     [ $this, 'shortcode' ] );
        add_shortcode( 'dish_dash_cart',     [ $this, 'shortcode_cart' ] );
        add_shortcode( 'dish_dash_checkout', [ $this, 'shortcode_checkout' ] );
        add_shortcode( 'dish_dash_reserve',  [ $this, 'shortcode_reserve' ] );
        add_shortcode( 'dish_dash_track',    [ $this, 'shortcode_track' ] );
        add_shortcode( 'dish_dash_account',  [ $this, 'shortcode_account' ] );

        // Desktop menu page: conditional asset enqueue
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_menu_assets' ] );

        // Desktop menu page: AJAX product loader
        add_action( 'wp_ajax_dd_menu_load_products',        [ $this, 'ajax_load_products' ] );
        add_action( 'wp_ajax_nopriv_dd_menu_load_products', [ $this, 'ajax_load_products' ] );

        // Prep Time meta field — product edit screen
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render_prep_time_field' ] );
        add_action( 'woocommerce_process_product_meta',                  [ $this, 'save_prep_time_field' ] );

        // Mobile: save favorites AJAX endpoint
        add_action( 'wp_ajax_dd_save_favorites',        [ $this, 'ajax_save_favorites' ] );
        add_action( 'wp_ajax_nopriv_dd_save_favorites', [ $this, 'ajax_save_favorites' ] );
    }

    // ─────────────────────────────────────────
    //  PREP TIME META FIELD
    // ─────────────────────────────────────────

    /**
     * Render the Prep Time input on the WooCommerce product General tab.
     * Stored as post meta under _dd_prep_time (minutes, integer).
     */
    public function render_prep_time_field(): void {
        global $post;
        $prep_time = get_post_meta( $post->ID, '_dd_prep_time', true );
        woocommerce_wp_text_input( [
            'id'          => '_dd_prep_time',
            'label'       => __( 'Prep Time (minutes)', 'dish-dash' ),
            'description' => __( 'Estimated preparation time shown on the mobile menu.', 'dish-dash' ),
            'desc_tip'    => true,
            'type'        => 'number',
            'value'       => $prep_time ? esc_attr( $prep_time ) : '',
            'custom_attributes' => [
                'min'  => '1',
                'step' => '1',
            ],
        ] );
    }

    /**
     * Save the Prep Time meta value when the product is saved.
     *
     * @param int $post_id  WooCommerce product post ID.
     */
    public function save_prep_time_field( int $post_id ): void {
        $prep_time = isset( $_POST['_dd_prep_time'] ) ? absint( $_POST['_dd_prep_time'] ) : 0;
        if ( $prep_time > 0 ) {
            update_post_meta( $post_id, '_dd_prep_time', $prep_time );
        } else {
            delete_post_meta( $post_id, '_dd_prep_time' );
        }
    }

    // ─────────────────────────────────────────
    //  MOBILE: SAVE FAVORITES AJAX
    // ─────────────────────────────────────────

    /**
     * AJAX handler for dd_save_favorites.
     * Persists the user's favorited product IDs to user meta (logged-in)
     * or a session transient (guest).
     * Accepts: nonce, favorites (JSON array of product IDs).
     */
    public function ajax_save_favorites(): void {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'dd_mobile_nonce' ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed' ], 403 );
        }

        $raw_favorites = isset( $_POST['favorites'] ) ? wp_unslash( $_POST['favorites'] ) : '[]';
        $favorites     = json_decode( $raw_favorites, true );

        if ( ! is_array( $favorites ) ) {
            wp_send_json_error( [ 'message' => 'Invalid favorites data' ], 400 );
        }

        // Sanitize: only allow positive integers
        $favorites = array_values( array_filter( array_map( 'absint', $favorites ) ) );

        if ( is_user_logged_in() ) {
            // Persist to user meta for logged-in users
            update_user_meta( get_current_user_id(), 'dd_favorites', $favorites );
        } else {
            // Persist to a session transient for guests (keyed by session cookie)
            $session_key = 'dd_fav_' . md5( $_COOKIE['dd_session'] ?? wp_generate_uuid4() );
            set_transient( $session_key, $favorites, DAY_IN_SECONDS );
        }

        wp_send_json_success( [ 'favorites' => $favorites ] );
    }

    // ─────────────────────────────────────────
    //  DESKTOP MENU PAGE — ASSET ENQUEUE
    // ─────────────────────────────────────────

    public function enqueue_menu_assets(): void {
        if ( ! $this->is_menu_page() ) {
            return;
        }

        wp_enqueue_style(
            'dd-menu-page',
            DD_ASSETS_URL . 'css/menu-page.css',
            [],
            DD_VERSION
        );

        wp_enqueue_script(
            'dd-menu-page',
            DD_ASSETS_URL . 'js/menu-page.js',
            [],
            DD_VERSION,
            true
        );

        wp_localize_script( 'dd-menu-page', 'DDMenu', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'dd_menu_nonce' ),
        ] );
    }

    private function is_menu_page(): bool {
        if ( ! is_page() ) return false;
        // Primary: stored page ID (most reliable)
        $page_id = get_option( 'dish_dash_menu_page_id' );
        if ( $page_id && is_page( (int) $page_id ) ) return true;
        // Fallback: page slug matches common menu slugs
        $slug = get_post_field( 'post_name' );
        return in_array( $slug, [ 'restaurant-menu', 'menu' ], true );
    }

    // ─────────────────────────────────────────
    //  DESKTOP MENU PAGE — AJAX PRODUCT LOADER
    // ─────────────────────────────────────────

    public function ajax_load_products(): void {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'dd_menu_nonce' ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed' ], 403 );
        }

        $cat_slug = isset( $_POST['cat_slug'] ) ? sanitize_title( wp_unslash( $_POST['cat_slug'] ) ) : '';
        $page     = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
        $per_page = 8;

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if ( $cat_slug !== '' ) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => $cat_slug,
                ],
            ];
        }

        $query = new WP_Query( $args );

        ob_start();
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $product = wc_get_product( get_the_ID() );
                if ( $product ) {
                    include DD_TEMPLATES_DIR . 'partials/product-card.php';
                }
            }
            wp_reset_postdata();
        }
        $html = ob_get_clean();

        $has_more = $page < $query->max_num_pages;

        wp_send_json_success( [
            'html'     => $html,
            'has_more' => $has_more,
            'page'     => $page,
        ] );
    }

    // ─────────────────────────────────────────
    //  SHORTCODES
    // ─────────────────────────────────────────

    public function shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'category'       => '',
            'columns'        => '3',
            'show_filter'    => 'yes',
            'show_search'    => 'yes',
            'items_per_page' => '-1',
        ], $atts, 'dish_dash_menu' );

        $uncategorized = get_term_by( 'slug', 'uncategorized', 'product_cat' );
        $exclude_ids   = $uncategorized ? [ $uncategorized->term_id ] : [];

        $categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'exclude'    => $exclude_ids,
            'orderby'    => 'name',
        ] );

        $query_args = [
            'post_type'      => 'product',
            'posts_per_page' => (int) $atts['items_per_page'],
            'post_status'    => 'publish',
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'tax_query'      => [[
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => [ 'uncategorized' ],
                'operator' => 'NOT IN',
            ]],
        ];

        if ( ! empty( $atts['category'] ) ) {
            $query_args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => sanitize_text_field( $atts['category'] ),
            ];
        }

        $query_args = apply_filters( 'dish_dash_menu_query_args', $query_args );
        $items      = new WP_Query( $query_args );

        // Build a product → categories map for filtering and tracking
        $product_cats = [];
        if ( $items->have_posts() ) {
            foreach ( $items->posts as $post ) {
                $terms = wp_get_post_terms( $post->ID, 'product_cat', [ 'fields' => 'all' ] );
                $product_cats[ $post->ID ] = is_wp_error( $terms ) ? [] : $terms;
            }
        }

        return $this->get_template( 'menu/grid.php', [
            'items'        => $items,
            'categories'   => $categories,
            'atts'         => $atts,
            'product_cats' => $product_cats,
        ] );
    }

    public function shortcode_cart( $atts ): string {
        return $this->get_template( 'cart/cart.php' );
    }

    public function shortcode_checkout( $atts ): string {
        return $this->get_template( 'checkout/checkout.php' );
    }

    public function shortcode_reserve( $atts ): string {
        ob_start();
        $dd_name = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );
        ?>
        <style>
        .dd-reserve-page {
            max-width: 600px;
            margin: 32px auto;
            padding: 0 16px;
            box-sizing: border-box;
            font-family: 'Inter', system-ui, sans-serif;
        }
        .dd-reserve-page h2 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2rem;
            color: #221B19;
            margin: 0 0 8px;
            line-height: 1.2;
        }
        .dd-reserve-page > p {
            color: #6E5B4C;
            font-size: 15px;
            margin: 0 0 24px;
            line-height: 1.6;
        }
        .dd-reserve__card {
            background: #fff;
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 8px 32px rgba(107,29,29,0.08);
        }
        .dd-reserve__grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .dd-reserve__full { grid-column: 1 / -1; }
        .dd-field-group { display: flex; flex-direction: column; gap: 6px; }
        .dd-field-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #888;
        }
        .dd-reserve__field {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #e8ddd2;
            border-radius: 12px;
            font-size: 15px;
            font-family: inherit;
            color: #221B19;
            background: #fdfaf7;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            -webkit-appearance: none;
            appearance: none;
        }
        .dd-reserve__field:focus {
            border-color: #6B1D1D;
            box-shadow: 0 0 0 3px rgba(107,29,29,0.08);
            background: #fff;
        }
        .dd-reserve__field::placeholder { color: #bbb; }
        .dd-reserve__btn {
            width: 100%;
            padding: 15px;
            background: #6B1D1D;
            color: #fff;
            border: 0;
            border-radius: 999px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 20px;
            font-family: inherit;
            transition: background 0.2s, transform 0.1s;
        }
        .dd-reserve__btn:hover { background: #5a1818; }
        .dd-reserve__btn:active { transform: scale(0.98); }
        .dd-reserve__msg {
            display: none;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            margin-top: 14px;
            text-align: center;
        }
        /* Mobile: stack all fields */
        @media (max-width: 480px) {
            .dd-reserve-page { padding: 0 12px; margin: 20px auto; }
            .dd-reserve__card { padding: 20px 16px; border-radius: 16px; }
            .dd-reserve__grid { grid-template-columns: 1fr; gap: 12px; }
            .dd-reserve__full { grid-column: 1; }
            .dd-reserve-page h2 { font-size: 1.6rem; }
        }
        </style>

        <div class="dd-reserve-page">
            <h2>Reserve a Table</h2>
            <p>Book your table at <?php echo esc_html( $dd_name ); ?> in seconds.</p>
            <div class="dd-reserve__card">
                <div class="dd-reserve__grid">
                    <div class="dd-field-group">
                        <label class="dd-field-label">&#128197; Date</label>
                        <input type="date" class="dd-reserve__field" id="ddResDate" min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="dd-field-group">
                        <label class="dd-field-label">&#128336; Time</label>
                        <input type="time" class="dd-reserve__field" id="ddResTime">
                    </div>
                    <div class="dd-field-group">
                        <label class="dd-field-label">&#128101; Guests</label>
                        <input type="number" class="dd-reserve__field" id="ddResGuests"
                            placeholder="Number of guests" min="1" max="20" autocomplete="off">
                    </div>
                    <div class="dd-field-group">
                        <label class="dd-field-label">&#128222; Phone</label>
                        <input type="tel" class="dd-reserve__field" id="ddResPhone"
                            placeholder="+250 000 000 000" autocomplete="tel">
                    </div>
                    <div class="dd-field-group dd-reserve__full">
                        <label class="dd-field-label">&#128172; Special Requests</label>
                        <textarea class="dd-reserve__field" id="ddResNotes"
                            rows="3" placeholder="Dietary needs, occasion, seating preference…"
                            style="resize:vertical;min-height:80px;"></textarea>
                    </div>
                </div>
                <button class="dd-reserve__btn" id="ddReserveBtn">Reserve now</button>
                <div class="dd-reserve__msg" id="ddReserveMsg"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }


    public function shortcode_track( $atts ): string {
        ob_start();
        $orders_url = function_exists( 'wc_get_account_url' )
            ? wc_get_account_url( 'orders' )
            : home_url( '/my-account/orders/' );
        ?>
        <div style="max-width:600px;margin:60px auto;padding:0 20px;text-align:center;">
            <div style="font-size:48px;margin-bottom:16px;">&#128666;</div>
            <h2 style="font-family:'Cormorant Garamond',serif;font-size:2rem;margin-bottom:12px;">Track Your Order</h2>
            <p style="color:#6E5B4C;margin-bottom:28px;">View your order history and track current orders.</p>
            <a href="<?php echo esc_url( $orders_url ); ?>" class="dd-btn dd-btn--brand" style="display:inline-flex;">View My Orders</a>
        </div>
        <?php
        return ob_get_clean();
    }

    public function shortcode_account( $atts ): string {
        ob_start();
        if ( function_exists( 'woocommerce_account_content' ) ) {
            woocommerce_account_content();
        } else {
            echo '<p>Please log in to view your account.</p>';
        }
        return ob_get_clean();
    }
}
