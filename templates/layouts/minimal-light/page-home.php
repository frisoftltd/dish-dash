<?php
/**
 * File:    templates/layouts/minimal-light/page-home.php
 * Template Name: DishDash (same "page-dishdash.php" meta marker khana-khazana
 *                uses — see DD_Template_Module::load_page_template(), which
 *                resolves the actual file dynamically from the active-template
 *                registry, not from page meta. No WP page needs to change to
 *                pick this up once minimal-light is activated in Settings →
 *                Template).
 *
 * Purpose: "Minimal Light" homepage — desktop + mobile, driven entirely by
 *          the existing Homepage Settings admin page (modules/homepage/
 *          class-dd-homepage-module.php). Every option read below already
 *          exists and saves correctly; nothing here adds a new setting.
 *
 * Shared chrome (NOT rendered here — comes from wp_body_open()/wp_footer()
 * exactly like page-dishdash.php): global header, mobile bottom nav, cart
 * drawer, reservation modal, product modal, opening-hours banner, global
 * footer. Re-skinned via assets/css/layouts/minimal-light.css CSS-only
 * overrides — this file never duplicates that markup or touches the PHP
 * that renders it.
 *
 * Dependencies:
 *   - DD_Template_Module (page-template resolution, global chrome injection)
 *   - assets/css/theme.css (base, always loaded) + assets/css/layouts/minimal-light.css
 *     (template-registry-driven override layer, loaded after theme.css)
 *   - assets/js/frontend.js, assets/js/cart.js, assets/js/reservations.js —
 *     consumed via existing hooks/classes only (.dd-add-btn, .dd-dish-card,
 *     .js-open-reservation, #dd-open-reservation) — none of these files are
 *     modified by this template.
 *   - templates/partials/product-card.php — reused verbatim for every
 *     product card (guarantees add-to-cart/quick-view keep working)
 *   - WooCommerce: wc_get_products(), product_cat taxonomy
 *   - DD_API::get_all_categories() if available (mirrors page-dishdash.php's
 *     own fallback pattern for the mobile Food Category List)
 *   - DD_Homepage_Module::get_reviews() (existing, reusable, handles both
 *     manual and Google Places sources — see implementation notes below)
 *
 * CSS variables set dynamically (same convention as page-dishdash.php):
 *   --brand, --brand-dark (dish_dash_primary_color / dish_dash_dark_color)
 *
 * Last modified: v3.18.5
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wc_get_products' ) ) {
    wp_die( 'WooCommerce is required for this page template.' );
}

// ─── Safe WooCommerce URL helpers (same fallbacks page-dishdash.php uses) ──
if ( ! function_exists( 'dd_placeholder_img' ) ) {
    function dd_placeholder_img( $size = 'medium_large' ) {
        return function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( $size ) : '';
    }
}

// ─── Brand / restaurant identity ────────────────────────────────────────────
$dd_name    = get_option( 'dish_dash_restaurant_name', 'Restaurant' );
$dd_primary = get_option( 'dish_dash_primary_color', '#6B1D1D' );
$dd_dark    = get_option( 'dish_dash_dark_color', '#160F0D' );

// ─── Homepage Settings — Header ─────────────────────────────────────────────
// dd_header_show_track_order has no corresponding element anywhere in the
// shared global header (DD_Template_Module::render_global_header()) — no
// Track Order button exists there at all, for any template, today. Wiring
// it would mean adding new markup to that SHARED function (touches
// khana-khazana too — explicitly out of scope for this release). Flagged in
// the release report; not attempted here.
// dd_header_show_cart DOES correspond to a real, always-rendered element
// (#ddCartTopBtn / #ddBottomCartBtn) — hidden via a scoped CSS rule below
// when off, without touching the shared header PHP.
$dd_show_cart = get_option( 'dd_header_show_cart', '1' ) === '1';

// ─── Homepage Settings — Hero ───────────────────────────────────────────────
$dd_pill_show       = get_option( 'dd_hero_pill_show', '1' ) === '1';
$dd_pill_text       = get_option( 'dd_hero_pill_text', '' );
$dd_h_title         = get_option( 'dish_dash_hero_title', 'Best Flavor in Town' );
$dd_h_sub           = get_option( 'dish_dash_hero_subtitle', '' );
$dd_h_img           = get_option( 'dish_dash_hero_image', '' );
$dd_hero_bg         = get_option( 'dd_hero_bg_image', '' );
$dd_overlay_color   = get_option( 'dd_hero_overlay_color', '#6B1D1D' );
$dd_overlay_opacity = (int) get_option( 'dd_hero_overlay_opacity', 85 );
$dd_overlay_rgba    = 'rgba(' . implode( ',', array_map( 'hexdec', str_split( ltrim( $dd_overlay_color, '#' ), 2 ) ) ) . ',' . round( $dd_overlay_opacity / 100, 2 ) . ')';
$dd_btn1_label      = get_option( 'dd_hero_btn1_label', 'Order Now' );
$dd_btn1_link       = get_option( 'dd_hero_btn1_link', '#menu' );
$dd_btn2_label      = get_option( 'dd_hero_btn2_label', 'Reserve Table' );
$dd_btn2_link       = get_option( 'dd_hero_btn2_link', '#reserve' );
$dd_btn3_label      = get_option( 'dd_hero_btn3_label', 'View Full Menu' );
$dd_btn3_link       = get_option( 'dd_hero_btn3_link', '/shop/' );
$dd_show_chips      = get_option( 'dd_hero_show_chips', '1' ) === '1';
$dd_chips           = [
    get_option( 'dd_hero_chip_1', '' ),
    get_option( 'dd_hero_chip_2', '' ),
    get_option( 'dd_hero_chip_3', '' ),
    get_option( 'dd_hero_chip_4', '' ),
];

// ─── Homepage Settings — Browse by Category ─────────────────────────────────
$cats_desk     = get_option( 'dd_section_categories_desktop', '1' ) === '1';
$cats_mob      = get_option( 'dd_section_categories_mobile',  '0' ) === '1';
$cats_vis      = $cats_desk || $cats_mob;
$cats_class    = ( $cats_desk && ! $cats_mob ) ? 'dd-desktop-only' : ( ( ! $cats_desk && $cats_mob ) ? 'dd-mobile-only' : '' );
$dd_cats_title = get_option( 'dd_categories_title', 'Choose your craving' );
$dd_cats_count = (int) get_option( 'dd_categories_count', 0 );

// ─── Homepage Settings — Featured Dishes ────────────────────────────────────
$feat_desk       = get_option( 'dd_section_featured_desktop', '1' ) === '1';
$feat_mob        = get_option( 'dd_section_featured_mobile',  '0' ) === '1';
$feat_vis        = $feat_desk || $feat_mob;
$feat_class      = ( $feat_desk && ! $feat_mob ) ? 'dd-desktop-only' : ( ( ! $feat_desk && $feat_mob ) ? 'dd-mobile-only' : '' );
$dd_feat_title   = get_option( 'dd_featured_title', 'Best sellers today' );
$dd_feat_count   = (int) get_option( 'dd_featured_count', 8 );
$dd_feat_orderby = get_option( 'dd_featured_orderby', 'popularity' );
$dd_feat_tag     = get_option( 'dd_featured_tag', '' );
$dd_feat_chips   = get_option( 'dd_featured_show_chips', '1' ) === '1';
$dd_chip_tags    = get_option( 'dd_featured_chip_tags', [] );
if ( is_string( $dd_chip_tags ) ) $dd_chip_tags = array_filter( explode( ',', $dd_chip_tags ) );

// ─── Homepage Settings — Reserve Table ──────────────────────────────────────
$reserve_desk    = get_option( 'dd_section_reserve_desktop', '1' ) === '1';
$reserve_mob     = get_option( 'dd_section_reserve_mobile',  '1' ) === '1';
$reserve_vis     = $reserve_desk || $reserve_mob;
$reserve_class   = ( $reserve_desk && ! $reserve_mob ) ? 'dd-desktop-only' : ( ( ! $reserve_desk && $reserve_mob ) ? 'dd-mobile-only' : '' );
$dd_reserve_bg   = get_option( 'dd_reserve_bg_image', '' );

// ─── Homepage Settings — Selected Category ──────────────────────────────────
$selcat_desk       = get_option( 'dd_section_selcat_desktop', '1' ) === '1';
$selcat_mob        = get_option( 'dd_section_selcat_mobile',  '0' ) === '1';
$selcat_vis        = $selcat_desk || $selcat_mob;
$selcat_class      = ( $selcat_desk && ! $selcat_mob ) ? 'dd-desktop-only' : ( ( ! $selcat_desk && $selcat_mob ) ? 'dd-mobile-only' : '' );
$dd_selcat_title   = get_option( 'dd_selcat_title', 'Selected category' );
$dd_selcat_count   = (int) get_option( 'dd_selcat_count', 8 );
$dd_selcat_slugs   = get_option( 'dd_selcat_slugs', [] );
if ( is_string( $dd_selcat_slugs ) ) $dd_selcat_slugs = array_filter( explode( ',', $dd_selcat_slugs ) );

// ─── Homepage Settings — Google Reviews ─────────────────────────────────────
$reviews_desk     = get_option( 'dd_section_reviews_desktop', '1' ) === '1';
$reviews_mob      = get_option( 'dd_section_reviews_mobile',  '1' ) === '1';
$reviews_vis      = $reviews_desk || $reviews_mob;
$reviews_class    = ( $reviews_desk && ! $reviews_mob ) ? 'dd-desktop-only' : ( ( ! $reviews_desk && $reviews_mob ) ? 'dd-mobile-only' : '' );
$dd_reviews_title = get_option( 'dd_reviews_title', 'What our customers say' );

// ─── Categories (shared source for Browse-by-Category + Selected Category) ─
$raw_cats = get_terms( [
    'taxonomy'   => 'product_cat',
    'hide_empty' => false,
    'orderby'    => 'menu_order',
    'number'     => $dd_cats_count > 0 ? $dd_cats_count : 0,
] );
$dd_cats = [];
if ( ! is_wp_error( $raw_cats ) ) {
    foreach ( $raw_cats as $cat ) {
        if ( $cat->slug !== 'uncategorized' ) $dd_cats[] = $cat;
    }
}

// All categories, unfiltered by dd_categories_count, for the Selected
// Category slug lookup — mirrors page-dishdash.php's own approach of
// filtering the already-fetched $dd_cats list, but that list is capped by
// dd_categories_count; Selected Category has its own independent slug
// picker so it must not silently lose categories the Browse section hid.
$raw_cats_all = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'menu_order' ] );
$dd_cats_all  = [];
if ( ! is_wp_error( $raw_cats_all ) ) {
    foreach ( $raw_cats_all as $cat ) {
        if ( $cat->slug !== 'uncategorized' ) $dd_cats_all[] = $cat;
    }
}

if ( ! empty( $dd_selcat_slugs ) ) {
    $dd_selcat_cats = array_values( array_filter( $dd_cats_all, function ( $c ) use ( $dd_selcat_slugs ) {
        return in_array( $c->slug, (array) $dd_selcat_slugs, true );
    } ) );
} else {
    $dd_selcat_cats = $dd_cats_all;
}

// ─── Products per selected category ─────────────────────────────────────────
$dd_cat_products = [];
foreach ( $dd_selcat_cats as $cat ) {
    $prods = wc_get_products( [
        'category' => [ $cat->slug ],
        'limit'    => $dd_selcat_count > 0 ? $dd_selcat_count : -1,
        'status'   => 'publish',
    ] );
    $dd_cat_products[ $cat->slug ] = $prods ?: [];
}

/**
 * Featured/Best-sellers product fetch.
 *
 * 'popularity' uses REAL DishDash sales data — the same mechanism
 * admin/pages/analytics.php's "Top Menu Items" card uses (COUNT of
 * wp_dishdash_order_items rows joined to delivered, non-test orders),
 * resolved back to WC_Product objects via the order_items.menu_item_id
 * column — rather than WooCommerce's generic total_sales postmeta
 * (which is what wc_get_products('orderby'=>'popularity') would use, and
 * what khana-khazana's own homepage currently relies on). This is a
 * deliberate improvement scoped to this new file only — khana-khazana's
 * own query is untouched.
 */
function dd_ml_popular_products( int $limit, string $tag_slug = '' ): array {
    global $wpdb;
    $ot  = $wpdb->prefix . 'dishdash_orders';
    $oit = $wpdb->prefix . 'dishdash_order_items';

    $rows = $wpdb->get_results(
        "SELECT oi.menu_item_id, COUNT(*) AS cnt
         FROM {$oit} oi
         JOIN {$ot} o ON o.id = oi.order_id
         WHERE o.status = 'delivered' AND o.is_test = 0 AND oi.menu_item_id > 0
         GROUP BY oi.menu_item_id
         ORDER BY cnt DESC
         LIMIT 100"
    );

    $ids = array_map( fn( $r ) => (int) $r->menu_item_id, $rows );
    if ( empty( $ids ) ) {
        // No delivered-order history yet (new install) — fall back to
        // WooCommerce's own popularity signal rather than showing nothing.
        $args = [ 'limit' => $limit > 0 ? $limit : -1, 'orderby' => 'popularity', 'order' => 'DESC', 'status' => 'publish' ];
        if ( $tag_slug ) $args['tag'] = [ $tag_slug ];
        return wc_get_products( $args ) ?: [];
    }

    $products    = wc_get_products( [ 'include' => $ids, 'status' => 'publish', 'limit' => -1 ] );
    $by_id       = [];
    foreach ( $products as $p ) $by_id[ $p->get_id() ] = $p;

    $ordered = [];
    foreach ( $ids as $id ) {
        if ( ! isset( $by_id[ $id ] ) ) continue;
        $product = $by_id[ $id ];
        if ( $tag_slug ) {
            $tags = wp_get_post_terms( $id, 'product_tag', [ 'fields' => 'slugs' ] );
            if ( ! in_array( $tag_slug, (array) $tags, true ) ) continue;
        }
        $ordered[] = $product;
        if ( $limit > 0 && count( $ordered ) >= $limit ) break;
    }
    return $ordered;
}

$feat_args = [ 'limit' => $dd_feat_count > 0 ? $dd_feat_count : -1, 'status' => 'publish' ];
if ( $dd_feat_tag ) $feat_args['tag'] = [ $dd_feat_tag ];

switch ( $dd_feat_orderby ) {
    case 'date':
        $feat_args['orderby'] = 'date';
        $feat_args['order']   = 'DESC';
        $dd_best = wc_get_products( $feat_args ) ?: [];
        break;
    case 'price':
        $feat_args['orderby'] = 'price';
        $feat_args['order']   = 'ASC';
        $dd_best = wc_get_products( $feat_args ) ?: [];
        break;
    case 'price-desc':
        $feat_args['orderby'] = 'price';
        $feat_args['order']   = 'DESC';
        $dd_best = wc_get_products( $feat_args ) ?: [];
        break;
    case 'rand':
        $feat_args['orderby'] = 'rand';
        $dd_best = wc_get_products( $feat_args ) ?: [];
        break;
    case 'popularity':
    default:
        $dd_best = dd_ml_popular_products( $dd_feat_count, $dd_feat_tag );
        break;
}

$dd_cart_count  = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
$dd_hours_state = class_exists( 'DD_Hours' ) ? DD_Hours::get_state() : 'open';

// ─── Reviews — reuses the existing, reusable static method (handles both
// manual and Google sources with one consistent shape) rather than
// duplicating page-dishdash.php's own separate, more complex inline
// Google-pool implementation. Both are "existing mechanisms"; this one is
// the one built to be called from elsewhere. ─────────────────────────────
$dd_reviews = class_exists( 'DD_Homepage_Module' ) ? DD_Homepage_Module::get_reviews() : [];

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php the_title(); ?> &#8211; <?php bloginfo( 'name' ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<style>
:root {
    --brand:      <?php echo esc_attr( $dd_primary ); ?>;
    --brand-dark: <?php echo esc_attr( $dd_dark ); ?>;
}
</style>
<?php wp_head(); ?>
</head>
<?php
$dd_body_classes = [ 'dd-page', 'dd-tpl-minimal-light' ];
if ( ! $dd_show_cart ) $dd_body_classes[] = 'dd-hide-cart-btn';
?>
<body class="<?php echo esc_attr( implode( ' ', $dd_body_classes ) ); ?>" id="home">
<?php wp_body_open(); ?>

<?php if ( is_admin_bar_showing() ) : ?>
<div style="height:32px"></div>
<?php endif; ?>

<!-- Header injected globally by DD_Template_Module -->

<!-- ══ HERO ════════════════════════════════════════════════════════════════ -->
<?php
$dd_hero_has_bg = ! empty( $dd_hero_bg );
$hero_style     = '';
if ( $dd_hero_has_bg ) {
    $hero_style = '--ml-hero-bg-image: url(' . esc_url( $dd_hero_bg ) . '); --ml-hero-overlay-color: ' . esc_attr( $dd_overlay_rgba ) . ';';
}
?>
<section class="dd-ml-hero<?php echo $dd_hero_has_bg ? ' dd-ml-hero--has-bg' : ''; ?>" style="<?php echo esc_attr( $hero_style ); ?>">
    <?php if ( $dd_hero_has_bg ) : ?><div class="dd-ml-hero__overlay"></div><?php endif; ?>
    <div class="dd-container dd-ml-hero__grid">
        <div class="dd-ml-hero__content">
            <?php if ( $dd_pill_show && '' !== trim( (string) $dd_pill_text ) ) : ?>
            <span class="dd-ml-pill"><?php echo esc_html( $dd_pill_text ); ?></span>
            <?php endif; ?>
            <h1 class="dd-ml-hero__title"><?php echo wp_kses_post( $dd_h_title ); ?></h1>
            <?php if ( $dd_h_sub ) : ?>
            <p class="dd-ml-copy"><?php echo esc_html( $dd_h_sub ); ?></p>
            <?php endif; ?>
            <div class="dd-ml-hero__actions">
                <a href="<?php echo esc_url( $dd_btn1_link ); ?>" class="dd-ml-btn dd-ml-btn--primary"><?php echo esc_html( $dd_btn1_label ); ?></a>
                <a href="<?php echo esc_url( $dd_btn2_link ); ?>" class="dd-ml-btn dd-ml-btn--secondary js-open-reservation"><?php echo esc_html( $dd_btn2_label ); ?></a>
                <a href="<?php echo esc_url( $dd_btn3_link ); ?>" class="dd-ml-btn dd-ml-btn--tertiary"><?php echo esc_html( $dd_btn3_label ); ?></a>
            </div>
            <?php if ( $dd_show_chips && array_filter( $dd_chips ) ) : ?>
            <div class="dd-ml-hero__chips">
                <?php foreach ( $dd_chips as $chip ) : if ( ! $chip ) continue; ?>
                <div class="dd-ml-hero__chip"><?php echo esc_html( $chip ); ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="dd-ml-hero__card">
            <?php
            $hero_img     = $dd_h_img;
            $hero_product = null;
            if ( ! $hero_img && ! empty( $dd_best ) ) {
                $hero_product = $dd_best[0];
                $img_id       = $hero_product->get_image_id();
                $hero_img     = $img_id ? wp_get_attachment_image_url( $img_id, 'large' ) : dd_placeholder_img( 'large' );
            }
            ?>
            <?php if ( $hero_img ) : ?>
            <img src="<?php echo esc_url( $hero_img ); ?>"
                 alt="<?php echo $hero_product ? esc_attr( $hero_product->get_name() ) : esc_attr( $dd_name ); ?>"
                 class="dd-ml-hero__card-img">
            <?php if ( $hero_product ) :
                $h_desc = wp_trim_words( strip_tags( $hero_product->get_short_description() ?: $hero_product->get_description() ), 16, '...' );
            ?>
            <div class="dd-ml-hero__card-overlay">
                <span class="dd-ml-hero__card-badge">Chef's Pick</span>
                <h3 class="dd-ml-hero__card-name"><?php echo esc_html( $hero_product->get_name() ); ?></h3>
                <?php if ( $h_desc ) : ?><p class="dd-ml-hero__card-desc"><?php echo esc_html( $h_desc ); ?></p><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ══ FOOD CATEGORY LIST (MOBILE ONLY) ═══════════════════════════════════ -->
<?php
$food_cat_mob_on = get_option( 'dd_section_food_cat_list_mobile', '1' ) === '1';
if ( $food_cat_mob_on ) :
    if ( class_exists( 'DD_API' ) ) {
        $dd_all_cats = array_filter( DD_API::get_all_categories(), fn( $c ) => isset( $c['product_count'] ) && (int) $c['product_count'] > 0 );
    } else {
        $dd_all_cats = array_filter( $dd_cats_all, fn( $c ) => $c->count > 0 );
    }
?>
<?php if ( ! empty( $dd_all_cats ) ) : ?>
<section id="food-category-list" class="dd-ml-food-cat-list dd-mobile-only">
    <div class="dd-container">
        <span class="dd-ml-eyebrow">Food Category</span>
        <?php foreach ( $dd_all_cats as $cat ) :
            if ( is_array( $cat ) ) {
                $cat_slug  = $cat['slug'] ?? '';
                $cat_name  = $cat['name'] ?? '';
                $cat_count = $cat['product_count'] ?? 0;
                $cat_img   = $cat['image_url'] ?? '';
            } else {
                $cat_slug  = $cat->slug;
                $cat_name  = $cat->name;
                $cat_count = $cat->count;
                $tid       = get_term_meta( $cat->term_id, 'thumbnail_id', true );
                $cat_img   = $tid ? wp_get_attachment_image_url( $tid, 'thumbnail' ) : '';
            }
        ?>
        <a href="<?php echo esc_url( home_url( '/restaurant-menu/?cat=' . $cat_slug ) ); ?>" class="dd-ml-food-cat-row">
            <div class="dd-ml-food-cat-row__thumb">
                <?php if ( $cat_img ) : ?>
                <img src="<?php echo esc_url( $cat_img ); ?>" alt="<?php echo esc_attr( $cat_name ); ?>" loading="lazy">
                <?php else : ?>
                <span class="dd-ml-food-cat-row__initial"><?php echo esc_html( strtoupper( substr( $cat_name, 0, 1 ) ) ); ?></span>
                <?php endif; ?>
            </div>
            <div class="dd-ml-food-cat-row__info">
                <div class="dd-ml-food-cat-row__name"><?php echo esc_html( $cat_name ); ?></div>
                <div class="dd-ml-food-cat-row__count"><?php echo (int) $cat_count; ?> Items</div>
            </div>
            <svg class="dd-ml-food-cat-row__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<?php endif; ?>

<!-- ══ BROWSE BY CATEGORY ══════════════════════════════════════════════════ -->
<?php if ( $cats_vis && ! empty( $dd_cats ) ) : ?>
<section class="dd-ml-section <?php echo esc_attr( $cats_class ); ?>" id="categories">
    <div class="dd-container">
        <div class="dd-ml-top">
            <div>
                <div class="dd-ml-eyebrow">Browse by category</div>
                <h2 class="dd-ml-title"><?php echo esc_html( $dd_cats_title ); ?></h2>
            </div>
        </div>
        <div class="dd-ml-cat-row">
            <?php foreach ( $dd_cats as $cat ) :
                $thumb_id  = get_term_meta( $cat->term_id, 'thumbnail_id', true );
                $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';
            ?>
            <a class="dd-ml-cat" href="<?php echo esc_url( home_url( '/restaurant-menu/?cat=' . $cat->slug ) ); ?>">
                <span class="dd-ml-cat__circle">
                    <?php if ( $thumb_url ) : ?>
                    <img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $cat->name ); ?>">
                    <?php else : ?>
                    <span class="dd-ml-cat__initial"><?php echo esc_html( strtoupper( substr( $cat->name, 0, 1 ) ) ); ?></span>
                    <?php endif; ?>
                </span>
                <span class="dd-ml-cat__name"><?php echo esc_html( $cat->name ); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══ FEATURED DISHES ═════════════════════════════════════════════════════ -->
<?php if ( $feat_vis ) : ?>
<section class="dd-ml-section dd-ml-section--surface <?php echo esc_attr( $feat_class ); ?>" id="menu">
    <div class="dd-container">
        <div class="dd-ml-top">
            <div>
                <div class="dd-ml-eyebrow">Featured dishes</div>
                <h2 class="dd-ml-title"><?php echo esc_html( $dd_feat_title ); ?></h2>
            </div>
        </div>

        <?php if ( $dd_feat_chips && ! empty( $dd_chip_tags ) ) : ?>
        <div class="dd-ml-chips" id="ddMlFeatChips">
            <button type="button" class="dd-ml-chip active" data-filter="">All</button>
            <?php foreach ( $dd_chip_tags as $chip_slug ) :
                $chip_term = get_term_by( 'slug', $chip_slug, 'product_tag' );
                if ( ! $chip_term ) continue;
            ?>
            <button type="button" class="dd-ml-chip" data-filter="<?php echo esc_attr( $chip_slug ); ?>"><?php echo esc_html( $chip_term->name ); ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="dd-ml-dish-row" id="ddMlFeatRow">
            <?php
            if ( ! empty( $dd_best ) ) {
                foreach ( $dd_best as $product ) {
                    $tag = $product->is_featured() ? 'Best Seller' : 'Popular';
                    include DD_TEMPLATES_DIR . 'partials/product-card.php';
                }
            } else {
                echo '<p class="dd-ml-empty">No products found. Add products in WooCommerce.</p>';
            }
            ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══ RESERVE TABLE ═══════════════════════════════════════════════════════ -->
<?php if ( $reserve_vis ) :
    $reserve_style = $dd_reserve_bg ? '--ml-reserve-bg-image: url(' . esc_url( $dd_reserve_bg ) . ');' : '';
?>
<section class="dd-ml-reserve <?php echo esc_attr( $reserve_class ); ?>" id="reserve" style="<?php echo esc_attr( $reserve_style ); ?>">
    <div class="dd-container dd-ml-reserve__inner">
        <div class="dd-ml-eyebrow">Reserve your table</div>
        <h2 class="dd-ml-reserve__title">A dining experience that feels as rich as the food.</h2>
        <p class="dd-ml-reserve__copy">Whether you&#39;re planning a quiet dinner for two or a celebration with family — reserve your table in seconds.</p>
        <button type="button" class="dd-ml-reserve__cta" id="dd-open-reservation">📅 Reserve a Table</button>
    </div>
</section>
<?php endif; ?>

<!-- ══ SELECTED CATEGORY ═══════════════════════════════════════════════════ -->
<?php if ( $selcat_vis && ! empty( $dd_selcat_cats ) ) : ?>
<section class="dd-ml-section <?php echo esc_attr( $selcat_class ); ?>" id="category-dishes">
    <div class="dd-container">
        <div class="dd-ml-top">
            <div>
                <div class="dd-ml-eyebrow"><?php echo esc_html( $dd_selcat_title ); ?></div>
                <h2 class="dd-ml-title">Find Your <span class="dd-gold">Favorite</span> Dish</h2>
            </div>
        </div>

        <div class="dd-ml-tabs" id="ddMlSelcatTabs">
            <?php foreach ( $dd_selcat_cats as $i => $cat ) : ?>
            <button type="button" class="dd-ml-tab<?php echo $i === 0 ? ' active' : ''; ?>" data-slug="<?php echo esc_attr( $cat->slug ); ?>"><?php echo esc_html( $cat->name ); ?></button>
            <?php endforeach; ?>
        </div>

        <?php foreach ( $dd_selcat_cats as $i => $cat ) : ?>
        <div class="dd-ml-tab-panel" data-panel="<?php echo esc_attr( $cat->slug ); ?>" <?php echo $i !== 0 ? 'hidden' : ''; ?>>
            <?php
            if ( ! empty( $dd_cat_products[ $cat->slug ] ) ) {
                foreach ( $dd_cat_products[ $cat->slug ] as $product ) {
                    $tag = '';
                    include DD_TEMPLATES_DIR . 'partials/product-card.php';
                }
            } else {
                echo '<p class="dd-ml-empty">No dishes in this category yet.</p>';
            }
            ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- ══ REVIEWS ══════════════════════════════════════════════════════════════ -->
<?php if ( $reviews_vis && ! empty( $dd_reviews ) ) : ?>
<section class="dd-ml-section <?php echo esc_attr( $reviews_class ); ?>" id="reviews">
    <div class="dd-container">
        <div class="dd-ml-top">
            <div>
                <div class="dd-ml-eyebrow">Loved by guests</div>
                <h2 class="dd-ml-title"><?php echo esc_html( $dd_reviews_title ); ?></h2>
            </div>
        </div>
        <div class="dd-ml-reviews-row">
            <?php foreach ( $dd_reviews as $r ) :
                $author  = trim( (string) ( $r['author'] ?? '' ) ) ?: 'Guest';
                $initial = strtoupper( mb_substr( $author, 0, 1 ) );
                $rating  = max( 1, min( 5, (int) ( $r['rating'] ?? 5 ) ) );
                $text    = trim( (string) ( $r['text'] ?? '' ) );
            ?>
            <article class="dd-ml-review">
                <div class="dd-ml-review__head">
                    <?php if ( ! empty( $r['photo'] ) ) : ?>
                    <img src="<?php echo esc_url( $r['photo'] ); ?>" alt="<?php echo esc_attr( $author ); ?>" class="dd-ml-review__avatar" loading="lazy" referrerpolicy="no-referrer">
                    <?php else : ?>
                    <span class="dd-ml-review__avatar--letter"><?php echo esc_html( $initial ); ?></span>
                    <?php endif; ?>
                    <div>
                        <div class="dd-ml-review__name"><?php echo esc_html( $author ); ?></div>
                        <?php if ( ! empty( $r['time'] ) ) : ?><div class="dd-ml-review__time"><?php echo esc_html( $r['time'] ); ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="dd-ml-review__stars"><?php for ( $s = 0; $s < $rating; $s++ ) echo '&#9733;'; ?></div>
                <?php if ( $text ) : ?><p class="dd-ml-review__text"><?php echo nl2br( esc_html( $text ) ); ?></p><?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Footer + cart drawer + reservation modal + product modal injected globally by DD_Template_Module via wp_footer -->
<?php wp_footer(); ?>

<script>
(function () {
    // Featured chip filter — client-side against templates/partials/product-card.php's
    // own data-filter attribute (already-existing convention, no new AJAX endpoint).
    var chipsWrap = document.getElementById('ddMlFeatChips');
    var featRow    = document.getElementById('ddMlFeatRow');
    if (chipsWrap && featRow) {
        chipsWrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.dd-ml-chip');
            if (!btn) return;
            chipsWrap.querySelectorAll('.dd-ml-chip').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var filter = btn.dataset.filter || '';
            featRow.querySelectorAll('.dd-dish-card').forEach(function (card) {
                var tags = (card.dataset.filter || '').split(',');
                card.style.display = (!filter || tags.indexOf(filter) !== -1) ? '' : 'none';
            });
        });
    }

    // Selected-category tabs — same show/hide-panel pattern page-dishdash.php
    // already uses for its own tabs, reimplemented here against this file's
    // own markup (no shared JS touched).
    var tabsWrap = document.getElementById('ddMlSelcatTabs');
    if (tabsWrap) {
        tabsWrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.dd-ml-tab');
            if (!btn) return;
            var slug = btn.dataset.slug;
            tabsWrap.querySelectorAll('.dd-ml-tab').forEach(function (b) { b.classList.toggle('active', b === btn); });
            document.querySelectorAll('.dd-ml-tab-panel').forEach(function (panel) {
                panel.hidden = panel.dataset.panel !== slug;
            });
        });
    }
})();
</script>

</body>
</html>
