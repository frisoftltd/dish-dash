<?php
/**
 * Dish Dash – Menu Module
 *
 * Handles menu display shortcodes only.
 * Assets loaded by DD_Template_Module.
 * Tracking handled by DD_Tracking_Module.
 *
 * @package DishDash
 * @since   2.2.0
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
    }

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
        <div class="dd-reserve-page" style="max-width:600px;margin:40px auto;padding:0 20px;">
            <h2 style="font-family:'Cormorant Garamond',serif;font-size:2rem;margin-bottom:8px;">Reserve a Table</h2>
            <p style="color:#6E5B4C;margin-bottom:32px;">Book your table at <?php echo esc_html( $dd_name ); ?> in seconds.</p>
            <div class="dd-reserve__card" style="background:#fff;border-radius:20px;padding:32px;box-shadow:0 8px 32px rgba(107,29,29,0.08);">
                <div class="dd-reserve__fields" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="dd-field-group">
                        <label class="dd-field-label">&#128197; Date</label>
                        <input type="date" class="dd-field" style="width:100%;padding:12px;border:1.5px solid #e8ddd2;border-radius:10px;font-size:15px;">
                    </div>
                    <div class="dd-field-group">
                        <label class="dd-field-label">&#128336; Time</label>
                        <input type="time" class="dd-field" style="width:100%;padding:12px;border:1.5px solid #e8ddd2;border-radius:10px;font-size:15px;">
                    </div>
                    <div class="dd-field-group">
                        <label class="dd-field-label">&#128101; Guests</label>
                        <input type="number" class="dd-field" placeholder="Number of guests" min="1" max="20" style="width:100%;padding:12px;border:1.5px solid #e8ddd2;border-radius:10px;font-size:15px;">
                    </div>
                    <div class="dd-field-group">
                        <label class="dd-field-label">&#128222; Phone</label>
                        <input type="tel" class="dd-field" placeholder="+250 000 000 000" style="width:100%;padding:12px;border:1.5px solid #e8ddd2;border-radius:10px;font-size:15px;">
                    </div>
                    <div class="dd-field-group" style="grid-column:1/-1;">
                        <label class="dd-field-label">&#128172; Special Requests</label>
                        <textarea class="dd-field" rows="3" placeholder="Any special requests..." style="width:100%;padding:12px;border:1.5px solid #e8ddd2;border-radius:10px;font-size:15px;resize:vertical;"></textarea>
                    </div>
                </div>
                <button class="dd-btn dd-btn--brand dd-btn--block" style="margin-top:24px;height:52px;font-size:16px;">Reserve now</button>
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
