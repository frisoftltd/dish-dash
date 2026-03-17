<?php
/**
 * Dish Dash – Menu Module
 *
 * Handles the dd_menu_item Custom Post Type, taxonomy
 * registration, admin meta boxes, and the [dish_dash_menu]
 * shortcode with category filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DD_Menu_Module extends DD_Module {

    protected string $id = 'menu';

    public function init(): void {
        // CPT & Taxonomy
        add_action( 'init', [ $this, 'register_post_type' ] );
        add_action( 'init', [ $this, 'register_taxonomies' ] );

        // Admin meta boxes
        add_action( 'add_meta_boxes',  [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post',       [ $this, 'save_meta' ] );

        // Frontend assets
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );

        // Shortcode
        add_shortcode( 'dish_dash_menu', [ $this, 'shortcode' ] );
    }

    // ─────────────────────────────────────────
    //  CUSTOM POST TYPE: dd_menu_item
    // ─────────────────────────────────────────
    public function register_post_type(): void {
        register_post_type( 'dd_menu_item', [
            'labels' => [
                'name'               => __( 'Menu Items',        'dish-dash' ),
                'singular_name'      => __( 'Menu Item',         'dish-dash' ),
                'add_new'            => __( 'Add New',           'dish-dash' ),
                'add_new_item'       => __( 'Add New Menu Item', 'dish-dash' ),
                'edit_item'          => __( 'Edit Menu Item',    'dish-dash' ),
                'view_item'          => __( 'View Menu Item',    'dish-dash' ),
                'search_items'       => __( 'Search Menu Items', 'dish-dash' ),
                'not_found'          => __( 'No menu items found.', 'dish-dash' ),
                'menu_name'          => __( 'Menu Items',        'dish-dash' ),
            ],
            'public'            => true,
            'show_ui'           => true,
            'show_in_menu'      => 'dish-dash',
            'show_in_rest'      => true,
            'supports'          => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
            'has_archive'       => false,
            'rewrite'           => [ 'slug' => 'menu-item' ],
            'menu_icon'         => 'dashicons-food',
            'capability_type'   => 'post',
        ] );
    }

    // ─────────────────────────────────────────
    //  TAXONOMIES
    // ─────────────────────────────────────────
    public function register_taxonomies(): void {
        // Menu Category (hierarchical — like categories)
        register_taxonomy( 'dd_menu_category', 'dd_menu_item', [
            'labels' => [
                'name'          => __( 'Menu Categories', 'dish-dash' ),
                'singular_name' => __( 'Menu Category',  'dish-dash' ),
                'add_new_item'  => __( 'Add Category',   'dish-dash' ),
            ],
            'hierarchical'  => true,
            'show_in_rest'  => true,
            'rewrite'       => [ 'slug' => 'menu-category' ],
            'show_admin_column' => true,
        ] );

        // Menu Tag (flat — dietary labels, attributes)
        register_taxonomy( 'dd_menu_tag', 'dd_menu_item', [
            'labels' => [
                'name'          => __( 'Menu Tags',  'dish-dash' ),
                'singular_name' => __( 'Menu Tag',   'dish-dash' ),
                'add_new_item'  => __( 'Add Tag',    'dish-dash' ),
            ],
            'hierarchical'  => false,
            'show_in_rest'  => true,
            'rewrite'       => [ 'slug' => 'menu-tag' ],
        ] );
    }

    // ─────────────────────────────────────────
    //  ADMIN META BOXES
    // ─────────────────────────────────────────
    public function add_meta_boxes(): void {
        add_meta_box(
            'dd_menu_item_details',
            __( 'Menu Item Details', 'dish-dash' ),
            [ $this, 'render_meta_box' ],
            'dd_menu_item',
            'normal',
            'high'
        );
    }

    public function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'dd_menu_meta_save', 'dd_menu_nonce' );

        $price      = get_post_meta( $post->ID, '_dd_price',       true );
        $sale_price = get_post_meta( $post->ID, '_dd_sale_price',  true );
        $badge      = get_post_meta( $post->ID, '_dd_badge',       true );
        $prep_time  = get_post_meta( $post->ID, '_dd_prep_time',   true );
        $is_avail   = get_post_meta( $post->ID, '_dd_is_available', true );
        $calories   = get_post_meta( $post->ID, '_dd_calories',    true );
        $allergens  = get_post_meta( $post->ID, '_dd_allergens',   true );

        // Default available to true for new posts
        if ( '' === $is_avail ) $is_avail = '1';
        ?>
        <style>
            .dd-meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
            .dd-meta-grid label{display:block;font-weight:600;margin-bottom:4px}
            .dd-meta-grid input,.dd-meta-grid select{width:100%}
            .dd-meta-full{grid-column:1/-1}
            .dd-meta-section{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:16px;margin-top:12px}
        </style>

        <div class="dd-meta-grid">
            <div>
                <label for="dd_price"><?php esc_html_e( 'Regular Price', 'dish-dash' ); ?></label>
                <input type="number" id="dd_price" name="_dd_price" value="<?php echo esc_attr( $price ); ?>" step="0.01" min="0" placeholder="0.00" />
            </div>
            <div>
                <label for="dd_sale_price"><?php esc_html_e( 'Sale Price (optional)', 'dish-dash' ); ?></label>
                <input type="number" id="dd_sale_price" name="_dd_sale_price" value="<?php echo esc_attr( $sale_price ); ?>" step="0.01" min="0" placeholder="0.00" />
            </div>
            <div>
                <label for="dd_badge"><?php esc_html_e( 'Badge', 'dish-dash' ); ?></label>
                <select id="dd_badge" name="_dd_badge">
                    <option value=""><?php esc_html_e( 'None', 'dish-dash' ); ?></option>
                    <?php
                    $badges = [
                        'new'          => __( 'New',          'dish-dash' ),
                        'popular'      => __( 'Popular',      'dish-dash' ),
                        'spicy'        => __( 'Spicy',        'dish-dash' ),
                        'vegan'        => __( 'Vegan',        'dish-dash' ),
                        'gluten-free'  => __( 'Gluten-Free',  'dish-dash' ),
                        'on-sale'      => __( 'On Sale',      'dish-dash' ),
                        'chef-special' => __( "Chef's Special", 'dish-dash' ),
                    ];
                    foreach ( $badges as $val => $label ) {
                        printf(
                            '<option value="%s"%s>%s</option>',
                            esc_attr( $val ),
                            selected( $badge, $val, false ),
                            esc_html( $label )
                        );
                    }
                    ?>
                </select>
            </div>
            <div>
                <label for="dd_prep_time"><?php esc_html_e( 'Prep Time (minutes)', 'dish-dash' ); ?></label>
                <input type="number" id="dd_prep_time" name="_dd_prep_time" value="<?php echo esc_attr( $prep_time ); ?>" min="0" placeholder="15" />
            </div>
            <div class="dd-meta-full">
                <label for="dd_allergens"><?php esc_html_e( 'Allergens', 'dish-dash' ); ?></label>
                <input type="text" id="dd_allergens" name="_dd_allergens" value="<?php echo esc_attr( $allergens ); ?>" placeholder="<?php esc_attr_e( 'e.g. nuts, dairy, gluten', 'dish-dash' ); ?>" />
            </div>
        </div>

        <div class="dd-meta-section">
            <strong><?php esc_html_e( 'Nutrition (optional)', 'dish-dash' ); ?></strong>
            <div class="dd-meta-grid" style="margin-top:10px">
                <div>
                    <label><?php esc_html_e( 'Calories (kcal)', 'dish-dash' ); ?></label>
                    <input type="number" name="_dd_calories" value="<?php echo esc_attr( $calories ); ?>" min="0" />
                </div>
            </div>
        </div>

        <div style="margin-top:12px">
            <label>
                <input type="checkbox" name="_dd_is_available" value="1" <?php checked( '1', $is_avail ); ?> />
                <?php esc_html_e( 'Available for ordering', 'dish-dash' ); ?>
            </label>
        </div>
        <?php
    }

    public function save_meta( int $post_id ): void {
        if ( ! isset( $_POST['dd_menu_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['dd_menu_nonce'], 'dd_menu_meta_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $fields = [
            '_dd_price'        => 'sanitize_text_field',
            '_dd_sale_price'   => 'sanitize_text_field',
            '_dd_badge'        => 'sanitize_text_field',
            '_dd_prep_time'    => 'absint',
            '_dd_allergens'    => 'sanitize_text_field',
            '_dd_calories'     => 'absint',
        ];

        foreach ( $fields as $meta_key => $sanitizer ) {
            if ( isset( $_POST[ $meta_key ] ) ) {
                update_post_meta( $post_id, $meta_key, $sanitizer( $_POST[ $meta_key ] ) );
            }
        }

        // Checkbox — absent = 0, present = 1
        update_post_meta( $post_id, '_dd_is_available', isset( $_POST['_dd_is_available'] ) ? '1' : '0' );
    }

    // ─────────────────────────────────────────
    //  FRONTEND ASSETS
    // ─────────────────────────────────────────
    public function enqueue_frontend_assets(): void {
        $this->enqueue_style( 'menu', 'menu.css' );
        $this->enqueue_script( 'menu', 'menu.js', [], true );

        // Pass settings to JavaScript
        wp_localize_script( 'dish-dash-menu', 'dishDash', DD_Settings::get_public_settings() );
    }

    // ─────────────────────────────────────────
    //  SHORTCODE  [dish_dash_menu]
    //  Atts: category, columns, show_filter,
    //        show_search, items_per_page
    // ─────────────────────────────────────────
    public function shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'category'      => '',
            'columns'       => '3',
            'show_filter'   => 'yes',
            'show_search'   => 'yes',
            'items_per_page' => '-1',
        ], $atts, 'dish_dash_menu' );

        // Fetch categories for filter bar
        $categories = get_terms( [
            'taxonomy'   => 'dd_menu_category',
            'hide_empty' => true,
        ] );

        // Build query
        $query_args = [
            'post_type'      => 'dd_menu_item',
            'posts_per_page' => (int) $atts['items_per_page'],
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => '_dd_is_available',
                    'value'   => '1',
                    'compare' => '=',
                ],
            ],
        ];

        if ( ! empty( $atts['category'] ) ) {
            $query_args['tax_query'] = [[
                'taxonomy' => 'dd_menu_category',
                'field'    => 'slug',
                'terms'    => sanitize_text_field( $atts['category'] ),
            ]];
        }

        $query_args = apply_filters( 'dish_dash_menu_query_args', $query_args );
        $items      = new WP_Query( $query_args );

        return $this->get_template( 'menu/grid.php', [
            'items'      => $items,
            'categories' => $categories,
            'atts'       => $atts,
        ] );
    }
}
