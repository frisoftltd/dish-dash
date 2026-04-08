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
