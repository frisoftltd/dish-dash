<?php
/**
 * Template Name: DishDash
 * Template Post Type: page
 *
 * Full-page restaurant template — bypasses Astra header/footer.
 *
 * @package DishDash
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wc_get_products' ) ) {
    wp_die( 'WooCommerce is required for this page template.' );
}

// ─── Safe WooCommerce URL helpers ─────────────────────────────────────────
if ( ! function_exists( 'dd_account_url' ) ) {
    function dd_account_url( $endpoint = '' ) {
        if ( function_exists( 'wc_get_account_url' ) ) {
            return wc_get_account_url( $endpoint );
        }
        $page_id = get_option( 'woocommerce_myaccount_page_id' );
        $base    = $page_id ? get_permalink( $page_id ) : home_url( '/my-account/' );
        return $endpoint ? trailingslashit( $base ) . $endpoint . '/' : $base;
    }
}

if ( ! function_exists( 'dd_checkout_url' ) ) {
    function dd_checkout_url() {
        if ( function_exists( 'wc_get_checkout_url' ) ) {
            return wc_get_checkout_url();
        }
        $page_id = get_option( 'woocommerce_checkout_page_id' );
        return $page_id ? get_permalink( $page_id ) : home_url( '/checkout/' );
    }
}

if ( ! function_exists( 'dd_shop_url' ) ) {
    function dd_shop_url() {
        if ( function_exists( 'wc_get_page_permalink' ) ) {
            return wc_get_page_permalink( 'shop' );
        }
        $page_id = get_option( 'woocommerce_shop_page_id' );
        return $page_id ? get_permalink( $page_id ) : home_url( '/shop/' );
    }
}

if ( ! function_exists( 'dd_placeholder_img' ) ) {
    function dd_placeholder_img( $size = 'medium_large' ) {
        if ( function_exists( 'wc_placeholder_img_src' ) ) {
            return wc_placeholder_img_src( $size );
        }
        return '';
    }
}

// ─── Settings ──────────────────────────────────────────────────────────────
$dd_name    = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );
$dd_logo    = get_option( 'dish_dash_logo_url', '' );
$dd_primary = get_option( 'dish_dash_primary_color', '#6B1D1D' );
$dd_dark    = get_option( 'dish_dash_dark_color', '#160F0D' );
$dd_addr    = get_option( 'dish_dash_address', 'Kigali, Rwanda' );
$dd_phone   = get_option( 'dish_dash_phone', '' );
$dd_email   = get_option( 'dish_dash_contact_email', '' );
$dd_fb      = get_option( 'dish_dash_facebook', '' );
$dd_ig      = get_option( 'dish_dash_instagram', '' );
$dd_wa      = get_option( 'dish_dash_whatsapp', '' );

// ─── Homepage Settings ─────────────────────────────────────────────────────

// 1. Header
$dd_show_track_order = get_option( 'dd_header_show_track_order', '1' ) === '1';
$dd_show_cart        = get_option( 'dd_header_show_cart', '1' ) === '1';

// 2. Hero
$dd_h_title    = get_option( 'dish_dash_hero_title', 'Best Indian Flavor in Kigali' );
$dd_h_sub      = get_option( 'dish_dash_hero_subtitle', 'Come as customer Leave as family !' );
$dd_h_img      = get_option( 'dish_dash_hero_image', '' );
$dd_hero_bg    = get_option( 'dd_hero_bg_image', '' );
$dd_overlay_color   = get_option( 'dd_hero_overlay_color', '#6B1D1D' );
$dd_overlay_opacity = (int) get_option( 'dd_hero_overlay_opacity', 85 );
$dd_overlay_rgba    = 'rgba(' . implode( ',', array_map( 'hexdec', str_split( ltrim( $dd_overlay_color, '#' ), 2 ) ) ) . ',' . round( $dd_overlay_opacity / 100, 2 ) . ')';
$dd_btn1_label = get_option( 'dd_hero_btn1_label', 'Order Now' );
$dd_btn1_link  = get_option( 'dd_hero_btn1_link', '#menu' );
$dd_btn2_label = get_option( 'dd_hero_btn2_label', 'Reserve Table' );
$dd_btn2_link  = get_option( 'dd_hero_btn2_link', '#reserve' );
$dd_btn3_label = get_option( 'dd_hero_btn3_label', 'View Full Menu' );
$dd_btn3_link  = get_option( 'dd_hero_btn3_link', '/shop/' );
$dd_show_chips = get_option( 'dd_hero_show_chips', '1' ) === '1';
$dd_chip_1     = get_option( 'dd_hero_chip_1', 'Authentic Indian Flavors' );
$dd_chip_2     = get_option( 'dd_hero_chip_2', 'Delivery & Pickup Available' );
$dd_chip_3     = get_option( 'dd_hero_chip_3', 'Elegant Dine-In Experience' );
$dd_chip_4     = get_option( 'dd_hero_chip_4', 'Freshly Prepared Daily' );

// 3. Categories
$dd_cats_show  = get_option( 'dd_categories_show', '1' ) === '1';
$dd_cats_title = get_option( 'dd_categories_title', 'Choose your craving' );
$dd_cats_count = (int) get_option( 'dd_categories_count', 0 );

// 4. Featured
$dd_feat_show    = get_option( 'dd_featured_show', '1' ) === '1';
$dd_feat_title   = get_option( 'dd_featured_title', 'Best sellers today' );
$dd_feat_count   = (int) get_option( 'dd_featured_count', 8 );
$dd_feat_orderby = get_option( 'dd_featured_orderby', 'popularity' );
$dd_feat_tag     = get_option( 'dd_featured_tag', '' );
$dd_feat_chips   = get_option( 'dd_featured_show_chips', '1' ) === '1';

// 5. Selected Category
$dd_selcat_show    = get_option( 'dd_selcat_show', '1' ) === '1';
$dd_selcat_title   = get_option( 'dd_selcat_title', 'Selected category' );
$dd_selcat_count   = (int) get_option( 'dd_selcat_count', 8 );
$dd_selcat_default = get_option( 'dd_selcat_default', '' );

// 6. Reviews
$dd_reviews_show  = get_option( 'dd_reviews_show', '1' ) === '1';
$dd_reviews_title = get_option( 'dd_reviews_title', 'What our customers say' );

// 7. Footer
$dd_footer_show_desc    = get_option( 'dd_footer_show_description', '1' ) === '1';
$dd_footer_desc         = get_option( 'dd_footer_description', 'Premium Indian dining and a refined digital ordering experience designed for smooth discovery, fast checkout, and repeat cravings.' );
$dd_footer_show_social  = get_option( 'dd_footer_show_social', '1' ) === '1';
$dd_footer_show_explore = get_option( 'dd_footer_show_explore', '1' ) === '1';
$dd_footer_show_contact = get_option( 'dd_footer_show_contact', '1' ) === '1';
$dd_footer_show_hours   = get_option( 'dd_footer_show_hours', '1' ) === '1';
$dd_hours               = get_option( 'dish_dash_opening_hours', "Mon - Fri: 10AM - 10PM\nSat - Sun: 9AM - 11PM" );
$dd_tiktok              = get_option( 'dish_dash_tiktok', '' );

// ─── Categories ────────────────────────────────────────────────────────────
$cat_cache_key = 'dd_cats_' . $dd_cats_count;
$raw_cats      = get_transient( $cat_cache_key );
if ( false === $raw_cats ) {
    $raw_cats = get_terms( array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'orderby'    => 'menu_order',
        'number'     => $dd_cats_count > 0 ? $dd_cats_count : 0,
    ) );
    set_transient( $cat_cache_key, $raw_cats, 5 * MINUTE_IN_SECONDS );
}

$dd_cats = array();
if ( ! is_wp_error( $raw_cats ) && ! empty( $raw_cats ) ) {
    foreach ( $raw_cats as $cat ) {
        if ( $cat->slug !== 'uncategorized' ) {
            $dd_cats[] = $cat;
        }
    }
}

// ─── Selected category section ─────────────────────────────────────────────
$dd_selcat_slugs = get_option( 'dd_selcat_slugs', [] );
if ( is_string( $dd_selcat_slugs ) ) $dd_selcat_slugs = array_filter( explode( ',', $dd_selcat_slugs ) );

if ( ! empty( $dd_selcat_slugs ) ) {
    $filtered = [];
    foreach ( $dd_cats as $c ) {
        if ( in_array( $c->slug, (array) $dd_selcat_slugs ) ) {
            $filtered[] = $c;
        }
    }
    $dd_selcat_cats = $filtered;
} else {
    $dd_selcat_cats = array_values( $dd_cats );
}

// ─── Best sellers / Featured ───────────────────────────────────────────────
$feat_args = array(
    'limit'   => $dd_feat_count > 0 ? $dd_feat_count : -1,
    'orderby' => in_array( $dd_feat_orderby, [ 'popularity', 'date', 'price', 'rand' ] ) ? $dd_feat_orderby : 'popularity',
    'order'   => $dd_feat_orderby === 'price-desc' ? 'DESC' : 'DESC',
    'status'  => 'publish',
);
if ( $dd_feat_tag ) {
    $feat_args['tag'] = [ $dd_feat_tag ];
}
$dd_best = wc_get_products( $feat_args );
if ( ! $dd_best ) $dd_best = array();

// ─── Products per category ─────────────────────────────────────────────────
$dd_cat_products = array();
foreach ( $dd_selcat_cats as $cat ) {
    $prods = wc_get_products( array(
        'category' => array( $cat->slug ),
        'limit'    => $dd_selcat_count > 0 ? $dd_selcat_count : -1,
        'status'   => 'publish',
    ) );
    $dd_cat_products[ $cat->slug ] = $prods ? $prods : array();
}

// ─── Misc ──────────────────────────────────────────────────────────────────
$dd_hours_lines = array_filter( array_map( 'trim', explode( "\n", $dd_hours ) ) );
$dd_cart_count  = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
$dd_initials    = strtoupper( substr( $dd_name, 0, 2 ) );

if ( ! $dd_selcat_default && ! empty( $dd_cats ) ) {
    $dd_selcat_default = $dd_cats[0]->slug;
}

// ─── Dish card helper ──────────────────────────────────────────────────────
if ( ! function_exists( 'dd_render_dish_card' ) ) {
    function dd_render_dish_card( $product, $tag = '' ) {
        $img_id  = $product->get_image_id();
        $img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'medium_large' ) : '';
        if ( ! $img_url ) $img_url = dd_placeholder_img( 'medium_large' );

        if ( ! $tag ) $tag = $product->is_featured() ? 'Best Seller' : 'Popular';

        $raw_price = (float) $product->get_price();
        $price     = $raw_price ? 'RWF ' . number_format( $raw_price, 0, '.', ',' ) : '';

        $short = $product->get_short_description();
        $long  = $product->get_description();
        $desc  = wp_trim_words( strip_tags( $short ? $short : $long ), 14, '...' );

        $id    = $product->get_id();
        $nonce = wp_create_nonce( 'dd_add_to_cart' );

        $filter_parts = array( strtolower( $tag ) );
        if ( $product->is_featured() ) $filter_parts[] = 'featured';
        $wc_tags = wp_get_post_terms( $id, 'product_tag', array( 'fields' => 'all' ) );
        if ( ! is_wp_error( $wc_tags ) && ! empty( $wc_tags ) ) {
            foreach ( $wc_tags as $wt ) {
                $filter_parts[] = $wt->slug;
                $filter_parts[] = strtolower( $wt->name );
            }
        }
        $filter_str = implode( ',', array_unique( $filter_parts ) );

        ob_start(); ?>
        <article class="dd-dish-card"
                 data-id="<?php echo esc_attr( $id ); ?>"
                 data-filter="<?php echo esc_attr( $filter_str ); ?>"
                 data-img="<?php echo esc_attr( $img_url ); ?>"
                 data-price="<?php echo esc_attr( $price ); ?>"
                 data-desc="<?php echo esc_attr( $desc ); ?>">
            <div class="dd-dish-card__media">
                <img src="<?php echo esc_url( $img_url ); ?>"
                     alt="<?php echo esc_attr( $product->get_name() ); ?>" loading="lazy">
            </div>
            <div class="dd-dish-card__body">
                <h3 class="dd-dish-card__title dd-serif"><?php echo esc_html( $product->get_name() ); ?></h3>
                <p class="dd-dish-card__desc"><?php echo esc_html( $desc ); ?></p>
                <div class="dd-dish-card__footer">
                    <span class="dd-price"><?php echo esc_html( $price ); ?></span>
                    <button class="dd-btn dd-btn--brand dd-btn--sm dd-add-btn"
                            data-id="<?php echo esc_attr( $id ); ?>"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        Add to cart
                    </button>
                </div>
            </div>
        </article>
        <?php return ob_get_clean();
    }
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php the_title(); ?> &#8211; <?php bloginfo( 'name' ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --brand:      <?php echo esc_attr( $dd_primary ); ?>;
    --brand-dark: <?php echo esc_attr( $dd_dark ); ?>;
}
</style>
<?php wp_head(); ?>
</head>
<body class="dd-page" id="home">
<?php wp_body_open(); ?>

<?php if ( is_admin_bar_showing() ) : ?>
<div style="height:32px"></div>
<?php endif; ?>

<!-- Header injected globally by DD_Template_Module via wp_body_open -->

<!-- ══ HERO ════════════════════════════════════════════════════════════════ -->
<?php
$hero_bg_style = '';
if ( $dd_hero_bg ) {
    $hero_bg_style = '--dd-hero-bg: url(\'' . esc_url( $dd_hero_bg ) . '\');';
} elseif ( $dd_h_img ) {
    $hero_bg_style = '--dd-hero-bg: url(\'' . esc_url( $dd_h_img ) . '\');';
}
$hero_bg_style .= '--dd-overlay-color: ' . esc_attr( $dd_overlay_rgba ) . ';';
?>
<section class="dd-hero" style="<?php echo $hero_bg_style; ?>">
    <div class="dd-container dd-hero__grid">

        <div class="dd-hero__content">
            <span class="dd-pill">Authentic Indian Dining</span>
            <h1 class="dd-hero__title dd-serif"><?php echo wp_kses_post( $dd_h_title ); ?></h1>
            <p class="dd-hero__copy"><?php echo esc_html( $dd_h_sub ); ?></p>
            <div class="dd-hero__actions">
                <a href="<?php echo esc_url( $dd_btn1_link ); ?>" class="dd-btn dd-btn--brand"><?php echo esc_html( $dd_btn1_label ); ?></a>
                <a href="<?php echo esc_url( $dd_btn2_link ); ?>" class="dd-btn dd-btn--outline"><?php echo esc_html( $dd_btn2_label ); ?></a>
                <a href="<?php echo esc_url( $dd_btn3_link ); ?>" class="dd-btn dd-btn--soft"><?php echo esc_html( $dd_btn3_label ); ?></a>
            </div>
            <?php if ( $dd_show_chips ) : ?>
            <div class="dd-hero__chips">
                <?php if ( $dd_chip_1 ) : ?><div class="dd-hero__chip"><?php echo esc_html( $dd_chip_1 ); ?></div><?php endif; ?>
                <?php if ( $dd_chip_2 ) : ?><div class="dd-hero__chip"><?php echo esc_html( $dd_chip_2 ); ?></div><?php endif; ?>
                <?php if ( $dd_chip_3 ) : ?><div class="dd-hero__chip"><?php echo esc_html( $dd_chip_3 ); ?></div><?php endif; ?>
                <?php if ( $dd_chip_4 ) : ?><div class="dd-hero__chip"><?php echo esc_html( $dd_chip_4 ); ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="dd-hero__card">
            <?php
            $hero_img     = $dd_h_img;
            $hero_product = null;

            if ( ! $hero_img && ! empty( $dd_best ) ) {
                $hero_product = $dd_best[0];
                $img_id       = $hero_product->get_image_id();
                $hero_img     = $img_id
                    ? wp_get_attachment_image_url( $img_id, 'large' )
                    : dd_placeholder_img( 'large' );
            }
            if ( ! $hero_img ) {
                $hero_img = 'https://images.unsplash.com/photo-1603893662172-99ed0cea2a08?auto=format&fit=crop&w=900&q=80';
            }
            ?>
            <img src="<?php echo esc_url( $hero_img ); ?>"
                 alt="<?php echo $hero_product ? esc_attr( $hero_product->get_name() ) : esc_attr( $dd_name ); ?>"
                 class="dd-hero__img">
            <?php if ( $hero_product ) :
                $h_short = $hero_product->get_short_description();
                $h_long  = $hero_product->get_description();
                $h_desc  = wp_trim_words( strip_tags( $h_short ? $h_short : $h_long ), 18, '...' );
            ?>
                <div class="dd-hero__overlay">
                    <div class="dd-hero__overlay-top">
                        <span class="dd-hero__overlay-badge">Chef's Pick</span>
                        <span>RWF <?php echo number_format( (float) $hero_product->get_price(), 0, '.', ',' ); ?></span>
                    </div>
                    <h3 class="dd-serif"><?php echo esc_html( $hero_product->get_name() ); ?></h3>
                    <p><?php echo esc_html( $h_desc ); ?></p>
                </div>
            <?php endif; ?>
        </div>

    </div>
</section>



<!-- ══ CATEGORIES ══════════════════════════════════════════════════════════ -->
<?php if ( $dd_cats_show && ! empty( $dd_cats ) ) : ?>
<section class="dd-section" id="categories">
    <div class="dd-container">
        <div class="dd-section__top">
            <div>
                <div class="dd-section__label">Browse by category</div>
                <h2 class="dd-section__title dd-serif"><?php echo esc_html( $dd_cats_title ); ?></h2>
            </div>
            <div class="dd-arrows">
                <button class="dd-arrow-btn" id="ddCatPrev">&#8592;</button>
                <button class="dd-arrow-btn" id="ddCatNext">&#8594;</button>
            </div>
        </div>
        <div class="dd-scroll-row dd-hide-scrollbar" id="ddCatScrollRow">
            <?php foreach ( $dd_cats as $i => $cat ) :
                $thumb_id  = get_term_meta( $cat->term_id, 'thumbnail_id', true );
                $thumb_url = $thumb_id
                    ? wp_get_attachment_image_url( $thumb_id, 'medium' )
                    : '';  // No fallback — show initials placeholder via CSS
                $cat_url   = get_term_link( $cat );
            ?>
                <button class="dd-cat-card<?php echo $i === 0 ? ' active' : ''; ?>"
                        data-slug="<?php echo esc_attr( $cat->slug ); ?>"
                        data-name="<?php echo esc_attr( $cat->name ); ?>"
                        data-url="<?php echo esc_url( is_wp_error( $cat_url ) ? '#' : $cat_url ); ?>">
                    <div class="dd-cat-card__circle">
                        <?php if ( $thumb_url ) : ?>
                        <img src="<?php echo esc_url( $thumb_url ); ?>"
                             alt="<?php echo esc_attr( $cat->name ); ?>">
                        <?php else : ?>
                        <span class="dd-cat-card__initial"><?php echo esc_html( strtoupper( substr( $cat->name, 0, 1 ) ) ); ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="dd-cat-card__name"><?php echo esc_html( $cat->name ); ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══ MENU ════════════════════════════════════════════════════════════════ -->
<?php if ( $dd_feat_show ) : ?>
<section class="dd-section" id="menu">
    <div class="dd-container">

        <?php
        $dd_chip_tags = get_option( 'dd_featured_chip_tags', [] );
        if ( is_string( $dd_chip_tags ) ) $dd_chip_tags = array_filter( explode( ',', $dd_chip_tags ) );
        if ( $dd_feat_chips && ! empty( $dd_chip_tags ) ) :
        ?>
        <div class="dd-chips">
            <button class="dd-chip active" data-filter="">All</button>
            <?php foreach ( $dd_chip_tags as $chip_slug ) :
                $chip_term = get_term_by( 'slug', $chip_slug, 'product_tag' );
                if ( ! $chip_term ) continue;
            ?>
            <button class="dd-chip" data-filter="<?php echo esc_attr( $chip_slug ); ?>">
                <?php echo esc_html( $chip_term->name ); ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php elseif ( $dd_feat_chips ) : ?>
        <div class="dd-chips">
            <button class="dd-chip active" data-filter="">All</button>
        </div>
        <?php endif; ?>

        <div class="dd-section__top">
            <div>
                <div class="dd-section__label">Featured dishes</div>
                <h2 class="dd-section__title dd-serif"><?php echo esc_html( $dd_feat_title ); ?></h2>
            </div>
            <div class="dd-arrows">
                <button class="dd-arrow-btn" id="ddFeatPrev">&#8592;</button>
                <button class="dd-arrow-btn" id="ddFeatNext">&#8594;</button>
            </div>
        </div>
        <div class="dd-scroll-row dd-hide-scrollbar" id="ddFeatRow">
            <?php
            if ( ! empty( $dd_best ) ) {
                foreach ( $dd_best as $product ) {
                    $tag = $product->is_featured() ? 'Best Seller' : 'Popular';
                    echo dd_render_dish_card( $product, $tag );
                }
            } else {
                echo '<p class="dd-empty">No products found. Add products in WooCommerce.</p>';
            }
            ?>
        </div>

    </div>
</section>
<?php endif; ?>

<!-- ══ RESERVATION ═════════════════════════════════════════════════════════ -->
<?php
$dd_reserve_bg = get_option( 'dd_reserve_bg_image', '' );
$reserve_style = $dd_reserve_bg ? 'style="--dd-reserve-bg: url(\'' . esc_url( $dd_reserve_bg ) . '\')"' : '';
?>
<section class="dd-reserve" id="reserve" <?php echo $reserve_style; ?>>
    <div class="dd-container dd-reserve__grid">
        <div>
            <div class="dd-section__label">Reserve your table</div>
            <h2 class="dd-reserve__title dd-serif">A dining experience that feels as rich as the food.</h2>
            <p class="dd-reserve__copy">Whether you're planning a relaxed dinner, a business lunch, or a family gathering — reserve your table in seconds and enjoy <?php echo esc_html( $dd_name ); ?> with comfort and style.</p>
        </div>
        <div class="dd-reserve__card">
            <div class="dd-reserve__fields">
                <div class="dd-field-group">
                    <label class="dd-field-label">&#128197; Date</label>
                    <input type="date" class="dd-field">
                </div>
                <div class="dd-field-group">
                    <label class="dd-field-label">&#128336; Time</label>
                    <input type="time" class="dd-field">
                </div>
                <div class="dd-field-group">
                    <label class="dd-field-label">&#128101; Guests</label>
                    <input type="number" class="dd-field" placeholder="Number of guests" min="1" max="20">
                </div>
                <div class="dd-field-group">
                    <label class="dd-field-label">&#128222; Phone Number</label>
                    <input type="tel" class="dd-field" placeholder="+250 000 000 000">
                </div>
                <div class="dd-field-group dd-field-group--full">
                    <label class="dd-field-label">&#128172; Special Requests</label>
                    <textarea class="dd-field" rows="3" placeholder="Any special requests or dietary requirements..."></textarea>
                </div>
            </div>
            <button class="dd-btn dd-btn--brand dd-btn--block" style="margin-top:20px;">Reserve now</button>
        </div>
    </div>
</section>

<!-- ══ SELECTED CATEGORY ════════════════════════════════════════════════════ -->
<?php if ( $dd_selcat_show && ! empty( $dd_selcat_cats ) ) : ?>
<section class="dd-section" id="category-dishes">
    <div class="dd-container">

        <div class="dd-selcat__heading">
            <span class="dd-section__label"><?php echo esc_html( $dd_selcat_title ); ?></span>
            <h2 class="dd-section__title dd-serif dd-selcat__title">Find Your <span class="dd-gold">Favorite</span> Dish</h2>
        </div>

        <div class="dd-selcat__tabs dd-scroll-row dd-hide-scrollbar" id="ddSelCatTabs">
            <?php foreach ( $dd_selcat_cats as $i => $cat ) : ?>
            <button class="dd-selcat__tab <?php echo $i === 0 ? 'active' : ''; ?>"
                    data-slug="<?php echo esc_attr( $cat->slug ); ?>"
                    data-name="<?php echo esc_attr( $cat->name ); ?>">
                <?php echo esc_html( $cat->name ); ?>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="dd-selcat__scroll-hint">
            <button class="dd-arrow-btn" id="ddSelCatPrev">&#8592;</button>
            <button class="dd-arrow-btn" id="ddSelCatNext">&#8594;</button>
        </div>

        <div class="dd-cat-rows-wrap">
            <?php foreach ( $dd_selcat_cats as $i => $cat ) : ?>
                <div class="dd-cat-row dd-scroll-row dd-hide-scrollbar"
                     id="ddCatRow-<?php echo esc_attr( $cat->slug ); ?>"
                     data-slug="<?php echo esc_attr( $cat->slug ); ?>"
                     <?php echo $i !== 0 ? 'hidden' : ''; ?>>
                    <?php
                    if ( ! empty( $dd_cat_products[ $cat->slug ] ) ) {
                        foreach ( $dd_cat_products[ $cat->slug ] as $product ) {
                            echo dd_render_dish_card( $product );
                        }
                    } else {
                        echo '<p class="dd-empty">No dishes in this category yet.</p>';
                    }
                    ?>
                </div>
            <?php endforeach; ?>
        </div>

    </div>
</section>
<?php endif; ?>

<!-- ══ REVIEWS ═════════════════════════════════════════════════════════════ -->
<?php if ( $dd_reviews_show ) :
    // ─── Build normalized review array ────────────────────────────────────
    $dd_review_source   = get_option( 'dd_reviews_source', 'manual' );
    $dd_review_count    = max( 1, (int) get_option( 'dd_reviews_count', 6 ) );
    $dd_review_min_rate = max( 1, (int) get_option( 'dd_reviews_min_rating', 4 ) );
    $dd_review_items    = array(); // [{author, photo, time, rating, text, star_only}]
    $dd_review_debug    = array(); // diagnostic info for HTML comment

    // ─── Helper: deep cast any value (incl. nested stdClass) to plain assoc array
    if ( ! function_exists( 'dd_to_array' ) ) {
        function dd_to_array( $value ) {
            if ( is_object( $value ) ) {
                $value = (array) $value;
            }
            if ( is_array( $value ) ) {
                foreach ( $value as $k => $v ) {
                    $value[ $k ] = dd_to_array( $v );
                }
            }
            return $value;
        }
    }

    // ─── Helper: pull a string text out of any known field shape
    if ( ! function_exists( 'dd_extract_review_text' ) ) {
        function dd_extract_review_text( $r ) {
            // Direct string fields the various Google APIs use
            $string_fields = array( 'text', 'review_text', 'comment', 'original_text', 'originalText' );
            foreach ( $string_fields as $f ) {
                if ( isset( $r[ $f ] ) && is_string( $r[ $f ] ) && trim( $r[ $f ] ) !== '' ) {
                    return trim( $r[ $f ] );
                }
            }
            // Object/array fields with a nested text key
            $object_fields = array( 'text', 'originalText', 'original_text' );
            foreach ( $object_fields as $f ) {
                if ( isset( $r[ $f ] ) && is_array( $r[ $f ] ) ) {
                    foreach ( array( 'text', 'originalText', 'value' ) as $sub ) {
                        if ( isset( $r[ $f ][ $sub ] ) && is_string( $r[ $f ][ $sub ] ) && trim( $r[ $f ][ $sub ] ) !== '' ) {
                            return trim( $r[ $f ][ $sub ] );
                        }
                    }
                }
            }
            return '';
        }
    }

    // ─── 1. Try Google Places API ─────────────────────────────────────────
    if ( $dd_review_source === 'google' ) {
        $place_id = trim( get_option( 'dd_reviews_google_place_id', '' ) );
        $api_key  = trim( get_option( 'dd_reviews_google_api_key', '' ) );

        $dd_review_debug['source']      = 'google';
        $dd_review_debug['has_place']   = $place_id ? 'yes' : 'no';
        $dd_review_debug['has_api_key'] = $api_key ? 'yes' : 'no';

        if ( $place_id && $api_key ) {
            // v3.1.4: bumped cache key to v5 to bust v4 cache (which had
            // text-extraction issues with nested stdClass objects).
            $cache_key = 'dd_grev_v5_' . md5( $place_id . '|' . $api_key );

            // Manual cache buster — append ?dd_refresh_reviews=1 to homepage URL
            if ( isset( $_GET['dd_refresh_reviews'] ) && current_user_can( 'manage_options' ) ) {
                delete_transient( $cache_key );
                $dd_review_debug['cache_busted'] = 'yes';
            }

            $cached = get_transient( $cache_key );

            if ( $cached === false ) {
                $url = add_query_arg( array(
                    'place_id'                => $place_id,
                    'fields'                  => 'reviews',
                    'reviews_sort'            => 'newest',
                    'reviews_no_translations' => 'true',
                    'language'                => 'en',
                    'key'                     => $api_key,
                ), 'https://maps.googleapis.com/maps/api/place/details/json' );

                $resp = wp_remote_get( $url, array( 'timeout' => 10 ) );

                if ( is_wp_error( $resp ) ) {
                    $dd_review_debug['fetch'] = 'wp_error: ' . $resp->get_error_message();
                } else {
                    $dd_review_debug['http_code'] = wp_remote_retrieve_response_code( $resp );
                    if ( 200 === (int) $dd_review_debug['http_code'] ) {
                        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
                        $dd_review_debug['api_status'] = isset( $body['status'] ) ? $body['status'] : 'no_status';
                        $dd_review_debug['review_cnt'] = isset( $body['result']['reviews'] ) ? count( $body['result']['reviews'] ) : 0;

                        if ( ! empty( $body['status'] ) && $body['status'] === 'OK' && ! empty( $body['result']['reviews'] ) ) {
                            $cached = $body['result']['reviews'];
                            set_transient( $cache_key, $cached, 12 * HOUR_IN_SECONDS );
                            $dd_review_debug['cache'] = 'fresh';
                        } elseif ( isset( $body['error_message'] ) ) {
                            $dd_review_debug['api_error'] = $body['error_message'];
                        }
                    }
                }
            } else {
                $dd_review_debug['cache'] = 'hit';
            }

            if ( is_array( $cached ) ) {
                $dd_review_debug['cached_items']  = count( $cached );
                $dd_review_debug['skipped_rate'] = 0;
                $dd_review_debug['star_only']    = 0;

                $first_dump_done = false;
                foreach ( $cached as $r ) {
                    // Deep-cast: convert ALL nested stdClass objects to assoc arrays.
                    // This is the key fix — (array) only casts the outer level.
                    $r = dd_to_array( $r );
                    if ( ! is_array( $r ) ) continue;

                    // Diagnostic: dump first review's structure for inspection
                    if ( ! $first_dump_done ) {
                        $dd_review_debug['first_keys'] = array_keys( $r );
                        $dd_review_debug['first_text_type'] = isset( $r['text'] ) ? gettype( $r['text'] ) : 'missing';
                        $first_dump_done = true;
                    }

                    // Rating — handle multiple field names
                    $rating = (int) ( $r['rating'] ?? $r['starRating'] ?? $r['star_rating'] ?? 0 );
                    if ( $rating > 0 && $rating < $dd_review_min_rate ) {
                        $dd_review_debug['skipped_rate']++;
                        continue;
                    }

                    // Text — try every known field shape
                    $text = dd_extract_review_text( $r );

                    // Author — handle every known field name
                    $author = '';
                    if ( ! empty( $r['author_name'] ) && is_string( $r['author_name'] ) ) {
                        $author = $r['author_name'];
                    } elseif ( ! empty( $r['authorAttribution']['displayName'] ) ) {
                        $author = $r['authorAttribution']['displayName'];
                    } elseif ( ! empty( $r['author']['displayName'] ) ) {
                        $author = $r['author']['displayName'];
                    } elseif ( ! empty( $r['author'] ) && is_string( $r['author'] ) ) {
                        $author = $r['author'];
                    }
                    if ( ! $author ) $author = 'Google User';

                    // Photo
                    $photo = '';
                    if ( ! empty( $r['profile_photo_url'] ) && is_string( $r['profile_photo_url'] ) ) {
                        $photo = $r['profile_photo_url'];
                    } elseif ( ! empty( $r['authorAttribution']['photoUri'] ) ) {
                        $photo = $r['authorAttribution']['photoUri'];
                    } elseif ( ! empty( $r['author']['photoUri'] ) ) {
                        $photo = $r['author']['photoUri'];
                    }

                    // Time ago
                    $time = '';
                    if ( ! empty( $r['relative_time_description'] ) ) {
                        $time = $r['relative_time_description'];
                    } elseif ( ! empty( $r['relativePublishTimeDescription'] ) ) {
                        $time = $r['relativePublishTimeDescription'];
                    }

                    if ( $text === '' ) {
                        $dd_review_debug['star_only']++;
                    }

                    // ALLOW star-only reviews — they still show name, rating, and Google branding.
                    // Better than fake "Happy Customer" reviews.
                    $dd_review_items[] = array(
                        'author'    => $author,
                        'photo'     => $photo,
                        'time'      => $time,
                        'rating'    => $rating > 0 ? $rating : 5,
                        'text'      => $text,
                        'star_only' => $text === '',
                    );
                }
                $dd_review_debug['normalized_items'] = count( $dd_review_items );
            }
        }
    }

    // ─── 2. Fallback: manual reviews — ONLY if source is not google ───────
    if ( empty( $dd_review_items ) && $dd_review_source !== 'google' ) {
        $manual_reviews = json_decode( get_option( 'dd_reviews_manual', '[]' ), true );
        if ( ! empty( $manual_reviews ) && is_array( $manual_reviews ) ) {
            foreach ( array_filter( $manual_reviews ) as $txt ) {
                $dd_review_items[] = array(
                    'author'    => 'Happy Customer',
                    'photo'     => '',
                    'time'      => '',
                    'rating'    => 5,
                    'text'      => $txt,
                    'star_only' => false,
                );
            }
        }
    }

    // ─── 3. Final default fallback — ONLY if source is not google ─────────
    if ( empty( $dd_review_items ) && $dd_review_source !== 'google' ) {
        $dd_review_items = array(
            array( 'author' => 'Amina K.', 'photo' => '', 'time' => '', 'rating' => 5,
                   'text' => 'The menu feels elegant and very easy to browse. Ordering was fast and smooth.', 'star_only' => false ),
            array( 'author' => 'David M.', 'photo' => '', 'time' => '', 'rating' => 5,
                   'text' => 'The categories make it simple to find dishes without feeling overwhelmed by choice.', 'star_only' => false ),
            array( 'author' => 'Priya S.', 'photo' => '', 'time' => '', 'rating' => 5,
                   'text' => 'Beautiful visuals, premium feel, and the checkout is clear and easy to trust.', 'star_only' => false ),
        );
    }

    $dd_review_items = array_slice( $dd_review_items, 0, $dd_review_count );
    $dd_review_debug['final_count'] = count( $dd_review_items );

    // If google was selected but we got nothing at all, hide the section silently
    $dd_render_reviews = ! empty( $dd_review_items );
?>
<!-- DD Reviews Debug: <?php echo esc_html( wp_json_encode( $dd_review_debug ) ); ?> -->
<?php if ( $dd_render_reviews ) : ?>
<section class="dd-section dd-greviews-section" id="reviews">
    <div class="dd-container">
        <div class="dd-greviews-header">
            <div class="dd-greviews-eyebrow">Loved by guests</div>
            <h2 class="dd-greviews-title"><?php echo esc_html( $dd_reviews_title ); ?></h2>
        </div>

        <div class="dd-greviews-wrap">
            <?php if ( count( $dd_review_items ) > 1 ) : ?>
            <button type="button" class="dd-greviews-arrow dd-greviews-arrow--prev" id="ddGrevPrev" aria-label="Previous reviews">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
            </button>
            <button type="button" class="dd-greviews-arrow dd-greviews-arrow--next" id="ddGrevNext" aria-label="Next reviews">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>
            <?php endif; ?>

            <div class="dd-greviews-track" id="ddGrevTrack">
                <?php foreach ( $dd_review_items as $r ) :
                    $author  = trim( (string) $r['author'] );
                    if ( $author === '' ) { $author = 'Google User'; }
                    $initial = strtoupper( mb_substr( $author, 0, 1 ) );
                    $rating  = max( 1, min( 5, (int) $r['rating'] ) );
                    $text    = trim( (string) $r['text'] );
                    $is_long = mb_strlen( $text ) > 160;
                ?>
                <article class="dd-greview-card">
                    <div class="dd-greview-card__head">
                        <?php if ( ! empty( $r['photo'] ) ) : ?>
                            <img src="<?php echo esc_url( $r['photo'] ); ?>"
                                 alt="<?php echo esc_attr( $author ); ?>"
                                 class="dd-greview-card__avatar"
                                 loading="lazy"
                                 referrerpolicy="no-referrer"
                                 onerror="this.outerHTML='<div class=\'dd-greview-card__avatar dd-greview-card__avatar--letter\'><?php echo esc_js( $initial ); ?></div>';">
                        <?php else : ?>
                            <div class="dd-greview-card__avatar dd-greview-card__avatar--letter"><?php echo esc_html( $initial ); ?></div>
                        <?php endif; ?>

                        <div class="dd-greview-card__meta">
                            <div class="dd-greview-card__name"><?php echo esc_html( $author ); ?></div>
                            <?php if ( ! empty( $r['time'] ) ) : ?>
                                <div class="dd-greview-card__time"><?php echo esc_html( $r['time'] ); ?></div>
                            <?php endif; ?>
                        </div>

                        <svg class="dd-greview-card__google" viewBox="0 0 48 48" aria-label="Google review">
                            <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3c-1.6 4.7-6.1 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/>
                            <path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 16 19 13 24 13c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
                            <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
                            <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.3-2.3 4.3-4.1 5.7l6.2 5.2C41 35.5 44 30.2 44 24c0-1.3-.1-2.4-.4-3.5z"/>
                        </svg>
                    </div>

                    <div class="dd-greview-card__stars" aria-label="<?php echo esc_attr( $rating ); ?> stars">
                        <?php for ( $s = 0; $s < $rating; $s++ ) echo '<span>&#9733;</span>'; ?>
                    </div>

                    <?php if ( $text !== '' ) : ?>
                    <div class="dd-greview-card__text<?php echo $is_long ? ' is-collapsible' : ''; ?>" data-collapsed="1">
                        <?php echo nl2br( esc_html( $text ) ); ?>
                    </div>
                    <?php if ( $is_long ) : ?>
                        <button type="button" class="dd-greview-card__more">Read more</button>
                    <?php endif; ?>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; /* dd_render_reviews */ ?>

<style>
/* ══ GOOGLE REVIEWS — scoped + defensive ════════════════════════════════ */
.dd-greviews-section { padding: 60px 0; background: transparent; }
.dd-greviews-header { text-align: center; margin-bottom: 32px; padding: 0 16px; }
.dd-greviews-eyebrow {
    color: #8a7a66; font-size: 12px; letter-spacing: .18em;
    text-transform: uppercase; font-weight: 600; margin-bottom: 10px;
}
.dd-greviews-title {
    font-family: 'Cormorant Garamond', Georgia, serif !important;
    font-size: clamp(28px, 4vw, 44px) !important;
    margin: 0 !important; color: #1a0f08 !important;
    font-weight: 600 !important; line-height: 1.15 !important;
}

.dd-greviews-wrap { position: relative; }

.dd-greviews-track {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    gap: 20px !important;
    overflow-x: auto;
    overflow-y: hidden;
    scroll-snap-type: x mandatory;
    scroll-behavior: smooth;
    padding: 8px 4px 24px;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    list-style: none;
    margin: 0;
}
.dd-greviews-track::-webkit-scrollbar { display: none; }

.dd-greview-card {
    flex: 0 0 300px !important;
    width: 300px !important;
    max-width: 300px !important;
    scroll-snap-align: start;
    background: #ffffff !important;
    border: 1px solid #EADfCE !important;
    border-radius: 16px !important;
    padding: 20px !important;
    box-shadow: 0 2px 10px rgba(43, 29, 18, .05) !important;
    display: flex !important;
    flex-direction: column !important;
    min-height: 230px;
    box-sizing: border-box !important;
    transition: box-shadow .2s, transform .2s;
}
.dd-greview-card:hover {
    box-shadow: 0 8px 22px rgba(43, 29, 18, .1) !important;
    transform: translateY(-2px);
}

.dd-greview-card__head {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 12px !important;
    margin-bottom: 14px !important;
    width: 100%;
}
.dd-greview-card__avatar {
    width: 46px !important;
    height: 46px !important;
    border-radius: 50% !important;
    object-fit: cover;
    background: #e8e8e8;
    flex: 0 0 46px !important;
    display: block;
    margin: 0 !important;
}
.dd-greview-card__avatar--letter {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    background: var(--brand, #6B1D1D) !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    font-size: 18px !important;
    line-height: 1 !important;
}
.dd-greview-card__meta {
    flex: 1 1 auto !important;
    min-width: 0 !important;
    overflow: hidden;
}
.dd-greview-card__name {
    font-weight: 700 !important;
    color: #2b1d12 !important;
    font-size: 15px !important;
    line-height: 1.2 !important;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    margin: 0 !important;
}
.dd-greview-card__time {
    font-size: 12px !important;
    color: #8a7a66 !important;
    margin-top: 3px !important;
    line-height: 1.2 !important;
}
.dd-greview-card__google {
    width: 22px !important;
    height: 22px !important;
    flex: 0 0 22px !important;
    display: block !important;
}

.dd-greview-card__stars {
    color: #FBBC04 !important;
    font-size: 16px;
    letter-spacing: 2px;
    margin: 0 0 10px 0 !important;
    line-height: 1;
    display: block !important;
}
.dd-greview-card__stars span { display: inline-block; }

.dd-greview-card__text {
    color: #3a2e25 !important;
    font-size: 14px !important;
    line-height: 1.55 !important;
    flex: 1 1 auto !important;
    margin: 0 !important;
    display: block;
}
.dd-greview-card__text.is-collapsible[data-collapsed="1"] {
    display: -webkit-box !important;
    -webkit-line-clamp: 4;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.dd-greview-card__more {
    margin-top: 10px;
    background: none;
    border: none;
    padding: 0;
    color: var(--brand, #6B1D1D);
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    align-self: flex-start;
}
.dd-greview-card__more:hover { text-decoration: underline; }

/* Side arrows like the reference design */
.dd-greviews-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 5;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    border: 1px solid #EADfCE;
    background: #ffffff;
    color: #2b1d12;
    cursor: pointer;
    display: flex !important;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 14px rgba(0, 0, 0, .08);
    transition: all .2s;
    padding: 0;
}
.dd-greviews-arrow:hover:not(:disabled) {
    background: var(--brand, #6B1D1D);
    color: #ffffff;
    border-color: var(--brand, #6B1D1D);
}
.dd-greviews-arrow:disabled { opacity: .35; cursor: not-allowed; }
.dd-greviews-arrow--prev { left: -10px; }
.dd-greviews-arrow--next { right: -10px; }

@media (max-width: 900px) {
    .dd-greviews-arrow--prev { left: 0; }
    .dd-greviews-arrow--next { right: 0; }
}
@media (max-width: 640px) {
    .dd-greviews-section { padding: 40px 0; }
    .dd-greview-card { flex: 0 0 84% !important; width: 84% !important; max-width: 84% !important; }
    .dd-greviews-arrow { display: none !important; }
    .dd-greviews-header { text-align: left; }
}
</style>

<script>
(function () {
    var track = document.getElementById('ddGrevTrack');
    if (!track) return;

    track.addEventListener('click', function (e) {
        var btn = e.target.closest('.dd-greview-card__more');
        if (!btn) return;
        var card = btn.closest('.dd-greview-card');
        var txt  = card.querySelector('.dd-greview-card__text');
        var collapsed = txt.getAttribute('data-collapsed') === '1';
        txt.setAttribute('data-collapsed', collapsed ? '0' : '1');
        btn.textContent = collapsed ? 'Show less' : 'Read more';
    });

    var prev = document.getElementById('ddGrevPrev');
    var next = document.getElementById('ddGrevNext');

    function scrollByCard(dir) {
        var card = track.querySelector('.dd-greview-card');
        if (!card) return;
        var step = card.offsetWidth + 20;
        track.scrollBy({ left: dir * step, behavior: 'smooth' });
    }
    if (prev) prev.addEventListener('click', function () { scrollByCard(-1); });
    if (next) next.addEventListener('click', function () { scrollByCard(1); });

    function updateArrows() {
        if (!prev || !next) return;
        prev.disabled = track.scrollLeft <= 2;
        next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
    }
    track.addEventListener('scroll', updateArrows, { passive: true });
    window.addEventListener('resize', updateArrows);
    setTimeout(updateArrows, 100);
})();
</script>
<?php endif; ?>

<!-- Footer injected globally by DD_Template_Module -->

<!-- ══ CART DRAWER ═════════════════════════════════════════════════════════ -->
<div class="dd-cart-overlay" id="ddCartOverlay"></div>
<aside class="dd-cart-drawer" id="ddCartDrawer" aria-label="Shopping cart">
    <div class="dd-cart-drawer__header">
        <span class="dd-cart-drawer__title">Your cart</span>
        <button class="dd-cart-drawer__close" id="ddCloseCart">Close &#10005;</button>
    </div>
    <div class="dd-cart-drawer__body" id="ddDrawerBody">
        <div class="dd-cart-drawer__empty">Your cart is empty.</div>
    </div>
    <div class="dd-cart-drawer__footer">
        <div class="dd-cart-drawer__totals">
            <div class="dd-cart-drawer__row"><span>Subtotal</span><span id="ddDrawerSubtotal">RWF 0</span></div>
            <div class="dd-cart-drawer__row"><span>Delivery</span><span id="ddDrawerDelivery">RWF 2,000</span></div>
            <div class="dd-cart-drawer__row dd-cart-drawer__row--main"><span>Total</span><span id="ddDrawerTotal">RWF 0</span></div>
        </div>
        <a href="<?php echo esc_url( dd_checkout_url() ); ?>"
           class="dd-btn dd-btn--brand dd-btn--block" style="margin-top:20px;">Checkout now</a>
    </div>
</aside>

<!-- ══ FLOATING CART ═══════════════════════════════════════════════════════ -->
<button class="dd-floating-cart" id="ddFloatingCart" aria-label="Open cart">
    <span>&#128722;</span>
    <span class="dd-floating-cart__text">Cart</span>
    <span class="dd-cart-badge" id="ddFloatingCount"><?php echo esc_html( $dd_cart_count ); ?></span>
</button>

<!-- ══ MOBILE BOTTOM NAV ═══════════════════════════════════════════════════ -->
<nav class="dd-bottom-nav" id="ddBottomNav">
    <a href="#home"    class="dd-bottom-link active" data-target="home">
        <span class="dd-bottom-link__icon">&#127968;</span><span>Home</span>
    </a>
    <a href="#menu"    class="dd-bottom-link" data-target="menu">
        <span class="dd-bottom-link__icon">&#127859;</span><span>Menu</span>
    </a>
    <a href="#reserve" class="dd-bottom-link" data-target="reserve">
        <span class="dd-bottom-link__icon">&#127860;</span><span>Reserve</span>
    </a>
    <button class="dd-bottom-link" id="ddBottomCartBtn" type="button">
        <span class="dd-bottom-link__icon">&#128722;</span>
        <span>Cart</span>
        <span class="dd-bottom-badge" id="ddBottomBadge"><?php echo esc_html( $dd_cart_count ); ?></span>
    </button>
</nav>

<!-- ══ PRODUCT MODAL ══════════════════════════════════════════════════════ -->
<div class="dd-product-modal" id="ddProductModal" role="dialog" aria-modal="true" aria-label="Product details">
    <div class="dd-product-modal__overlay" id="ddProductModalOverlay"></div>
    <div class="dd-product-modal__wrap">
        <button class="dd-product-modal__close" id="ddProductModalClose" aria-label="Close" onclick="if(window.ddCloseModal)window.ddCloseModal();return false;">&#10005;</button>
        <div class="dd-product-modal__content" id="ddProductModalContent"></div>
    </div>
</div>

<!-- ══ JS DATA BRIDGE ══════════════════════════════════════════════════════ -->
<script>
window.DD = {
    ajaxUrl:     '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
    nonce:       '<?php echo esc_js( wp_create_nonce( 'dd_nonce' ) ); ?>',
    checkoutUrl: '<?php echo esc_url( dd_checkout_url() ); ?>',
    deliveryFee: <?php echo (int) get_option( 'dish_dash_delivery_fee', 2000 ); ?>,
    cartCount:   <?php echo (int) $dd_cart_count; ?>,
    firstCat:    '<?php echo ! empty( $dd_cats ) ? esc_js( $dd_cats[0]->slug ) : ''; ?>'
};
</script>

<?php wp_footer(); ?>
</body>
</html>
