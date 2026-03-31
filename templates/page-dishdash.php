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
// These exist because wc_get_account_url() was added in WC 2.6 and some
// installs may have an older version or WC not fully bootstrapped yet.
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
$raw_cats = get_terms( array(
    'taxonomy'   => 'product_cat',
    'hide_empty' => true,
    'parent'     => 0,
    'orderby'    => 'menu_order',
    'number'     => $dd_cats_count > 0 ? $dd_cats_count : 0,
) );

$dd_cats = array();
if ( ! is_wp_error( $raw_cats ) && ! empty( $raw_cats ) ) {
    foreach ( $raw_cats as $cat ) {
        if ( $cat->slug !== 'uncategorized' ) {
            $dd_cats[] = $cat;
        }
    }
}

// ─── Selected category section — filter by chosen slugs ───────────────────
$dd_selcat_slugs = get_option( 'dd_selcat_slugs', [] );
if ( is_string( $dd_selcat_slugs ) ) $dd_selcat_slugs = array_filter( explode( ',', $dd_selcat_slugs ) );

// If specific slugs chosen, filter — reindex with array_values to fix $i !== 0 check
if ( ! empty( $dd_selcat_slugs ) ) {
    $filtered = [];
    foreach ( $dd_cats as $c ) {
        if ( in_array( $c->slug, (array) $dd_selcat_slugs ) ) {
            $filtered[] = $c;
        }
    }
    $dd_selcat_cats = $filtered;
} else {
    $dd_selcat_cats = array_values( $dd_cats ); // all categories, reindexed
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

// Default first category if none set
if ( ! $dd_selcat_default && ! empty( $dd_cats ) ) {
    $dd_selcat_default = $dd_cats[0]->slug;
}

// ─── Dish card helper — function_exists prevents fatal redeclaration ────────
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

        // Build filter string from tag + featured status + product tags
        $filter_parts = array( strtolower( $tag ) );
        if ( $product->is_featured() ) $filter_parts[] = 'featured';
        // Use slugs so they match chip data-filter values
        $wc_tags = wp_get_post_terms( $id, 'product_tag', array( 'fields' => 'all' ) );
        if ( ! is_wp_error( $wc_tags ) && ! empty( $wc_tags ) ) {
            foreach ( $wc_tags as $wt ) {
                $filter_parts[] = $wt->slug;           // slug for chip matching
                $filter_parts[] = strtolower( $wt->name ); // name as fallback
            }
        }
        $filter_str = implode( ',', array_unique( $filter_parts ) );

        ob_start(); ?>
        <article class="dd-dish-card" data-id="<?php echo esc_attr( $id ); ?>" data-filter="<?php echo esc_attr( $filter_str ); ?>">
            <div class="dd-dish-card__media">
                <img src="<?php echo esc_url( $img_url ); ?>"
                     alt="<?php echo esc_attr( $product->get_name() ); ?>" loading="lazy">
                <span class="dd-dish-card__tag"><?php echo esc_html( $tag ); ?></span>
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

<?php if ( is_admin_bar_showing() ) : ?>
<div style="height:32px"></div>
<?php endif; ?>

<!-- ══ HEADER ══════════════════════════════════════════════════════════════ -->
<header class="dd-header">
    <div class="dd-container dd-header__inner">

        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="dd-brand">
            <?php if ( $dd_logo ) : ?>
                <img src="<?php echo esc_url( $dd_logo ); ?>"
                     alt="<?php echo esc_attr( $dd_name ); ?>"
                     class="dd-brand__logo">
            <?php else : ?>
                <span class="dd-brand__badge"><?php echo esc_html( $dd_initials ); ?></span>
                <div>
                    <div class="dd-brand__name"><?php echo esc_html( $dd_name ); ?></div>
                    <div class="dd-brand__sub">Restaurant</div>
                </div>
            <?php endif; ?>
        </a>

        <button class="dd-mobile-toggle" id="ddMobileToggle" aria-label="Open menu">&#9776;</button>

        <nav class="dd-nav" id="ddMainNav">
            <?php
            $nav_html = wp_nav_menu( array(
                'theme_location' => 'dd-primary',
                'container'      => false,
                'items_wrap'     => '%3$s',
                'fallback_cb'    => false,
                'echo'           => false,
            ) );
            if ( $nav_html ) {
                echo $nav_html;
            } else {
                echo '<a href="#home">Home</a>';
                echo '<a href="#menu">Menu</a>';
                echo '<a href="#reserve">Reserve</a>';
                echo '<a href="#reviews">Reviews</a>';
            }
            ?>
        </nav>

        <div class="dd-header__actions">
            <?php if ( $dd_show_track_order ) : ?>
            <a href="<?php echo esc_url( dd_account_url( 'orders' ) ); ?>"
               class="dd-btn dd-btn--light dd-btn--sm">Track Order</a>
            <?php endif; ?>
            <?php if ( $dd_show_cart ) : ?>
            <button class="dd-cart-top" id="ddCartTopBtn" aria-label="Open cart">
                <span class="dd-cart-top__label">Cart</span>
                <span class="dd-cart-badge" id="ddCartCount"><?php echo esc_html( $dd_cart_count ); ?></span>
            </button>
            <?php endif; ?>
        </div>

    </div>
</header>

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
        </div><!-- /.dd-hero__content -->

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

<!-- ══ QUICK ORDER BAR ═════════════════════════════════════════════════════ -->
<section class="dd-quick">
    <div class="dd-container">
        <div class="dd-quick__card">
            <div class="dd-panel">
                <div class="dd-panel__label">Mode</div>
                <div class="dd-mode-btns">
                    <button class="dd-mode-btn active" data-mode="delivery">Delivery</button>
                    <button class="dd-mode-btn" data-mode="pickup">Pickup</button>
                </div>
            </div>
            <div class="dd-panel">
                <div class="dd-panel__label">Search dishes</div>
                <div class="dd-search">
                    <span>&#128269;</span>
                    <input type="text" id="ddSearch"
                           placeholder="Search butter chicken, biryani, naan...">
                    <span class="dd-search__tag">Fast search</span>
                </div>
            </div>
            <a href="<?php echo esc_url( dd_shop_url() ); ?>"
               class="dd-btn dd-btn--light" target="_blank">View Full Menu</a>
            <a href="#menu" class="dd-btn dd-btn--gold" id="ddStartOrder">Start Order</a>
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
                    : 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=400&q=80';
                $cat_url   = get_term_link( $cat );
            ?>
                <button class="dd-cat-card<?php echo $i === 0 ? ' active' : ''; ?>"
                        data-slug="<?php echo esc_attr( $cat->slug ); ?>"
                        data-name="<?php echo esc_attr( $cat->name ); ?>"
                        data-url="<?php echo esc_url( is_wp_error( $cat_url ) ? '#' : $cat_url ); ?>">
                    <div class="dd-cat-card__circle">
                        <img src="<?php echo esc_url( $thumb_url ); ?>"
                             alt="<?php echo esc_attr( $cat->name ); ?>">
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
<section class="dd-reserve" id="reserve">
    <div class="dd-container dd-reserve__grid">
        <div>
            <div class="dd-section__label">Reserve your table</div>
            <h2 class="dd-section__title dd-serif">A dining experience that feels as rich as the food.</h2>
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
        <div class="dd-section__top">
            <div>
                <div class="dd-section__label"><?php echo esc_html( $dd_selcat_title ); ?></div>
            </div>
            <div class="dd-arrows">
                <button class="dd-arrow-btn" id="ddSelPrev">&#8592;</button>
                <button class="dd-arrow-btn" id="ddSelNext">&#8594;</button>
            </div>
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
<?php if ( $dd_reviews_show ) : ?>
<section class="dd-section" id="reviews">
    <div class="dd-container">
        <div class="dd-section__top" style="margin-bottom:24px;">
            <div>
                <div class="dd-section__label">Loved by guests</div>
                <h2 class="dd-section__title dd-serif"><?php echo esc_html( $dd_reviews_title ); ?></h2>
            </div>
        </div>
        <div class="dd-reviews">
            <?php
            $manual_reviews = json_decode( get_option( 'dd_reviews_manual', '[]' ), true );
            if ( ! empty( $manual_reviews ) && is_array( $manual_reviews ) ) {
                $count = (int) get_option( 'dd_reviews_count', 3 );
                $shown = array_slice( array_filter( $manual_reviews ), 0, $count );
                foreach ( $shown as $review_text ) :
            ?>
                <div class="dd-review-card">
                    <div class="dd-review-card__stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                    <p><?php echo esc_html( $review_text ); ?></p>
                </div>
            <?php
                endforeach;
            } else {
                // Default fallback reviews
                $dd_reviews = array(
                    array( 'text' => 'The menu feels elegant and very easy to browse. Ordering was fast and smooth.',         'author' => 'Amina K.' ),
                    array( 'text' => 'The categories make it simple to find dishes without feeling overwhelmed by choice.',   'author' => 'David M.' ),
                    array( 'text' => 'Beautiful visuals, premium feel, and the checkout is clear and easy to trust.',         'author' => 'Priya S.' ),
                );
                foreach ( $dd_reviews as $r ) :
            ?>
                <div class="dd-review-card">
                    <div class="dd-review-card__stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                    <p><?php echo esc_html( $r['text'] ); ?></p>
                    <div class="dd-review-card__author"><?php echo esc_html( $r['author'] ); ?></div>
                </div>
            <?php
                endforeach;
            }
            ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══ FOOTER ══════════════════════════════════════════════════════════════ -->
<footer class="dd-footer">
    <div class="dd-container dd-footer__grid">

        <!-- Brand column -->
        <div class="dd-footer__col-brand">
            <div class="dd-footer__brand">
                <?php if ( $dd_logo ) : ?>
                    <img src="<?php echo esc_url( $dd_logo ); ?>"
                         alt="<?php echo esc_attr( $dd_name ); ?>"
                         class="dd-footer__logo">
                <?php else : ?>
                    <div class="dd-footer__brand-badge"><?php echo esc_html( $dd_initials ); ?></div>
                    <span class="dd-footer__brand-name"><?php echo esc_html( $dd_name ); ?></span>
                <?php endif; ?>
            </div>
            <?php if ( $dd_footer_show_desc && $dd_footer_desc ) : ?>
            <p class="dd-footer__copy"><?php echo esc_html( $dd_footer_desc ); ?></p>
            <?php endif; ?>
            <?php if ( $dd_footer_show_social ) : ?>
            <div class="dd-footer__social">
                <?php if ( $dd_fb ) : ?>
                    <a href="<?php echo esc_url( $dd_fb ); ?>" target="_blank" rel="noopener" class="dd-footer__social-link" aria-label="Facebook">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                    </a>
                <?php endif; ?>
                <?php if ( $dd_ig ) : ?>
                    <a href="<?php echo esc_url( $dd_ig ); ?>" target="_blank" rel="noopener" class="dd-footer__social-link" aria-label="Instagram">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
                    </a>
                <?php endif; ?>
                <?php if ( $dd_wa ) : ?>
                    <a href="https://wa.me/<?php echo esc_attr( preg_replace( '/\D/', '', $dd_wa ) ); ?>" target="_blank" rel="noopener" class="dd-footer__social-link" aria-label="WhatsApp">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.116 1.522 5.849L0 24l6.335-1.498A11.95 11.95 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.587-.504-5.078-1.38l-.36-.214-3.762.889.928-3.667-.235-.374A9.96 9.96 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>
                    </a>
                <?php endif; ?>
                <?php if ( $dd_tiktok ) : ?>
                    <a href="<?php echo esc_url( $dd_tiktok ); ?>" target="_blank" rel="noopener" class="dd-footer__social-link" aria-label="TikTok">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.18 8.18 0 0 0 4.78 1.52V6.75a4.85 4.85 0 0 1-1.01-.06z"/></svg>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Explore -->
        <?php if ( $dd_footer_show_explore ) : ?>
        <div>
            <div class="dd-footer__heading">Explore</div>
            <ul class="dd-footer__list">
                <li><a href="#home">Home</a></li>
                <li><a href="#menu">Menu</a></li>
                <li><a href="#reserve">Reserve Table</a></li>
                <li><a href="<?php echo esc_url( dd_account_url( 'orders' ) ); ?>">Track Order</a></li>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Contact -->
        <?php if ( $dd_footer_show_contact ) : ?>
        <div>
            <div class="dd-footer__heading">Contact</div>
            <ul class="dd-footer__list">
                <?php if ( $dd_addr )  echo '<li>📍 ' . esc_html( $dd_addr ) . '</li>'; ?>
                <?php if ( $dd_phone ) echo '<li><a href="tel:' . esc_attr( preg_replace( '/\s/', '', $dd_phone ) ) . '">📞 ' . esc_html( $dd_phone ) . '</a></li>'; ?>
                <?php if ( $dd_email ) echo '<li><a href="mailto:' . esc_attr( $dd_email ) . '">✉️ ' . esc_html( $dd_email ) . '</a></li>'; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Opening Hours -->
        <?php if ( $dd_footer_show_hours ) : ?>
        <div>
            <div class="dd-footer__heading">Opening Hours</div>
            <ul class="dd-footer__list">
                <?php if ( ! empty( $dd_hours_lines ) ) : ?>
                    <?php foreach ( $dd_hours_lines as $line ) echo '<li>⏰ ' . esc_html( $line ) . '</li>'; ?>
                <?php else : ?>
                    <li>Monday – Friday: 10AM – 10PM</li>
                    <li>Saturday – Sunday: 9AM – 11PM</li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

    </div>
    <div class="dd-footer__bottom">
        <div class="dd-container">
            <p>&copy; <?php echo date( 'Y' ); ?> <?php echo esc_html( $dd_name ); ?>. Powered by <strong>Dish Dash</strong> by Fri Soft Ltd</p>
        </div>
    </div>
</footer>

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
