<?php
/**
 * DD_Module_Menu
 *
 * Handles everything related to the restaurant menu:
 *  - Registers the dd_menu_item Custom Post Type
 *  - Registers taxonomies (dd_menu_category, dd_menu_tag)
 *  - Adds admin meta boxes for price, badge, addons, nutrition
 *  - Provides the [dish_dash_menu] shortcode
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Module_Menu extends DD_Module {

    public function boot(): void {
        add_action( 'init',           [ $this, 'register_cpt'      ] );
        add_action( 'init',           [ $this, 'register_taxonomies'] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes'    ] );
        add_action( 'save_post_dd_menu_item', [ $this, 'save_meta' ], 10, 2 );
        add_shortcode( 'dish_dash_menu', [ $this, 'shortcode_menu' ] );
    }

    // ── CPT ───────────────────────────────────────────────────────────────────

    public function register_cpt(): void {
        register_post_type( 'dd_menu_item', [
            'labels' => [
                'name'               => __( 'Menu Items',        'dish-dash' ),
                'singular_name'      => __( 'Menu Item',         'dish-dash' ),
                'add_new'            => __( 'Add New Item',       'dish-dash' ),
                'add_new_item'       => __( 'Add New Menu Item',  'dish-dash' ),
                'edit_item'          => __( 'Edit Menu Item',     'dish-dash' ),
                'search_items'       => __( 'Search Menu Items',  'dish-dash' ),
                'not_found'          => __( 'No menu items found.','dish-dash' ),
                'menu_name'          => __( 'Menu Items',         'dish-dash' ),
            ],
            'public'        => true,
            'show_in_menu'  => 'dish-dash',   // nest under Dish Dash menu
            'menu_icon'     => 'dashicons-food',
            'supports'      => [ 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ],
            'has_archive'   => false,
            'rewrite'       => [ 'slug' => 'menu-item' ],
            'show_in_rest'  => true,
            'capability_type' => 'post',
        ] );
    }

    // ── Taxonomies ────────────────────────────────────────────────────────────

    public function register_taxonomies(): void {
        // Menu Categories (Starters, Mains, Desserts …)
        register_taxonomy( 'dd_menu_category', 'dd_menu_item', [
            'labels'        => [
                'name'          => __( 'Menu Categories',  'dish-dash' ),
                'singular_name' => __( 'Menu Category',   'dish-dash' ),
                'add_new_item'  => __( 'Add New Category', 'dish-dash' ),
                'menu_name'     => __( 'Categories',       'dish-dash' ),
            ],
            'hierarchical'  => true,
            'show_in_rest'  => true,
            'show_in_menu'  => true,
            'rewrite'       => [ 'slug' => 'menu-category' ],
        ] );

        // Dietary / attribute tags (Vegan, Spicy, Gluten-Free …)
        register_taxonomy( 'dd_menu_tag', 'dd_menu_item', [
            'labels'        => [
                'name'          => __( 'Menu Tags',    'dish-dash' ),
                'singular_name' => __( 'Menu Tag',    'dish-dash' ),
                'menu_name'     => __( 'Tags',         'dish-dash' ),
            ],
            'hierarchical'  => false,
            'show_in_rest'  => true,
            'rewrite'       => [ 'slug' => 'menu-tag' ],
        ] );
    }

    // ── Meta boxes ────────────────────────────────────────────────────────────

    public function add_meta_boxes(): void {
        add_meta_box(
            'dd_menu_details',
            __( 'Menu Item Details', 'dish-dash' ),
            [ $this, 'render_meta_box' ],
            'dd_menu_item',
            'normal',
            'high'
        );
    }

    public function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'dd_save_menu_item', 'dd_menu_nonce' );

        $price        = get_post_meta( $post->ID, '_dd_price',       true );
        $sale_price   = get_post_meta( $post->ID, '_dd_sale_price',  true );
        $badge        = get_post_meta( $post->ID, '_dd_badge',       true );
        $prep_time    = get_post_meta( $post->ID, '_dd_prep_time',   true );
        $allergens    = get_post_meta( $post->ID, '_dd_allergens',   true );
        $is_available = get_post_meta( $post->ID, '_dd_is_available', true );
        $is_available = $is_available === '' ? '1' : $is_available;
        ?>
        <style>
            .dd-meta-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:8px; }
            .dd-meta-grid label { display:block; font-weight:600; margin-bottom:4px; }
            .dd-meta-grid input, .dd-meta-grid select { width:100%; }
            .dd-meta-full { grid-column: 1 / -1; }
            .dd-meta-badges { display:flex; flex-wrap:wrap; gap:8px; margin-top:4px; }
            .dd-meta-badge-opt { display:flex; align-items:center; gap:4px; cursor:pointer; }
        </style>
        <div class="dd-meta-grid">

            <div>
                <label for="dd_price"><?php esc_html_e( 'Price', 'dish-dash' ); ?></label>
                <input type="number" step="0.01" min="0" id="dd_price" name="dd_price"
                       value="<?php echo esc_attr( $price ); ?>" placeholder="0.00" />
            </div>

            <div>
                <label for="dd_sale_price"><?php esc_html_e( 'Sale Price (optional)', 'dish-dash' ); ?></label>
                <input type="number" step="0.01" min="0" id="dd_sale_price" name="dd_sale_price"
                       value="<?php echo esc_attr( $sale_price ); ?>" placeholder="0.00" />
            </div>

            <div>
                <label for="dd_prep_time"><?php esc_html_e( 'Prep Time (minutes)', 'dish-dash' ); ?></label>
                <input type="number" min="0" id="dd_prep_time" name="dd_prep_time"
                       value="<?php echo esc_attr( $prep_time ); ?>" placeholder="30" />
            </div>

            <div>
                <label for="dd_is_available"><?php esc_html_e( 'Availability', 'dish-dash' ); ?></label>
                <select id="dd_is_available" name="dd_is_available">
                    <option value="1" <?php selected( $is_available, '1' ); ?>><?php esc_html_e( 'Available', 'dish-dash' ); ?></option>
                    <option value="0" <?php selected( $is_available, '0' ); ?>><?php esc_html_e( 'Unavailable', 'dish-dash' ); ?></option>
                </select>
            </div>

            <div class="dd-meta-full">
                <label for="dd_allergens"><?php esc_html_e( 'Allergens', 'dish-dash' ); ?></label>
                <input type="text" id="dd_allergens" name="dd_allergens"
                       value="<?php echo esc_attr( $allergens ); ?>"
                       placeholder="<?php esc_attr_e( 'e.g. nuts, dairy, gluten', 'dish-dash' ); ?>" />
            </div>

            <div class="dd-meta-full">
                <label><?php esc_html_e( 'Badge', 'dish-dash' ); ?></label>
                <div class="dd-meta-badges">
                    <?php
                    $badges = [
                        'new'          => __( '🆕 New',          'dish-dash' ),
                        'popular'      => __( '🔥 Popular',      'dish-dash' ),
                        'spicy'        => __( '🌶 Spicy',        'dish-dash' ),
                        'vegan'        => __( '🌱 Vegan',        'dish-dash' ),
                        'gluten-free'  => __( '🌾 Gluten-Free',  'dish-dash' ),
                        'chef-special' => __( '👨‍🍳 Chef\'s Special','dish-dash' ),
                    ];
                    foreach ( $badges as $val => $label ) :
                    ?>
                    <label class="dd-meta-badge-opt">
                        <input type="radio" name="dd_badge" value="<?php echo esc_attr( $val ); ?>"
                               <?php checked( $badge, $val ); ?> />
                        <?php echo esc_html( $label ); ?>
                    </label>
                    <?php endforeach; ?>
                    <label class="dd-meta-badge-opt">
                        <input type="radio" name="dd_badge" value="" <?php checked( $badge, '' ); ?> />
                        <?php esc_html_e( 'None', 'dish-dash' ); ?>
                    </label>
                </div>
            </div>

        </div>
        <?php
    }

    public function save_meta( int $post_id, WP_Post $post ): void {
        // Security checks
        if ( ! isset( $_POST['dd_menu_nonce'] ) ) return;
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dd_menu_nonce'] ) ), 'dd_save_menu_item' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $fields = [
            '_dd_price'        => 'dd_price',
            '_dd_sale_price'   => 'dd_sale_price',
            '_dd_badge'        => 'dd_badge',
            '_dd_prep_time'    => 'dd_prep_time',
            '_dd_allergens'    => 'dd_allergens',
            '_dd_is_available' => 'dd_is_available',
        ];

        foreach ( $fields as $meta_key => $post_key ) {
            if ( array_key_exists( $post_key, $_POST ) ) {
                update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
            }
        }
    }

    // ── Shortcode  [dish_dash_menu] ───────────────────────────────────────────

    public function shortcode_menu( array $atts ): string {
        $atts = shortcode_atts( [
            'category'    => '',
            'columns'     => 3,
            'show_filter' => 'yes',
            'limit'       => -1,
        ], $atts, 'dish_dash_menu' );

        $categories = get_terms( [
            'taxonomy'   => 'dd_menu_category',
            'hide_empty' => true,
        ] );

        $query_args = [
            'post_type'      => 'dd_menu_item',
            'posts_per_page' => (int) $atts['limit'],
            'post_status'    => 'publish',
            'meta_query'     => [ [
                'key'     => '_dd_is_available',
                'value'   => '0',
                'compare' => '!=',
            ] ],
            'orderby'   => 'menu_order',
            'order'     => 'ASC',
        ];

        if ( ! empty( $atts['category'] ) ) {
            $query_args['tax_query'] = [ [
                'taxonomy' => 'dd_menu_category',
                'field'    => 'slug',
                'terms'    => sanitize_text_field( $atts['category'] ),
            ] ];
        }

        $items = new WP_Query( $query_args );

        ob_start();
        DD_Frontend::get_template( 'menu/grid.php', [
            'items'      => $items,
            'atts'       => $atts,
            'categories' => $categories,
        ] );
        wp_reset_postdata();
        return ob_get_clean();
    }
}
