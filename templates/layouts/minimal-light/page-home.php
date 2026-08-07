<?php
/**
 * File:    templates/layouts/minimal-light/page-home.php
 * Template Name: DishDash (same "page-dishdash.php" meta marker khana-khazana
 *                uses — DD_Template_Module::load_page_template() resolves the
 *                actual file dynamically from the active-template registry).
 *
 * Purpose: "Minimal Light" homepage — v3.18.6 structural rewrite. v3.18.5
 *          shipped the same section order/shapes as Khana Khazana with only
 *          different tokens; this version genuinely restructures the layout
 *          per the approved mockups (icon-driven header with no nav bar,
 *          split-screen hero, horizontal-scroll strips instead of grids, a
 *          bordered table-style category index, a full-width dark reserve
 *          band). ALL data-fetching below is unchanged from v3.18.5 —
 *          reused verbatim per the release brief, not re-investigated.
 *          (v3.18.6 also added an "Our Story" section reusing
 *          dd_footer_description — removed in v3.18.10, see that version's
 *          release notes: it wasn't a real Homepage Settings field, just a
 *          footer field borrowed for content it was never meant to hold.)
 *
 * Structural departure from v3.18.5 / khana-khazana: this file does NOT
 * call wp_body_open() (which fires DD_Template_Module::inject_global_header()
 * — a header with a visible nav bar, search, and login/signup buttons that
 * this design explicitly must not have). It renders its own header markup
 * instead, reusing the SAME element IDs/classes the shared header uses for
 * the hamburger + nav-drawer + cart badge (#ddMenuToggle, #ddNavDrawer,
 * #ddDrawerOverlay, #ddCartTopBtn, #ddCartCount, plus the .dd-menu-toggle/
 * .dd-nav-drawer/.dd-drawer-overlay classes theme.css already animates) so
 * the existing frontend.js/cart.js handlers keep working with zero JS
 * changes. wp_footer() is still called unchanged — footer, cart drawer,
 * mobile bottom nav, reservation modal, and product modal are all still
 * the real shared chrome, untouched, restyled via CSS only (see
 * minimal-light.css). Khana Khazana's own page-dishdash.php is not touched
 * and still gets the full shared header as before.
 *
 * Dependencies: same as v3.18.5 — see that version's docblock history in
 * git log for the full list (DD_Template_Module, DD_API, DD_Homepage_Module,
 * templates/partials/product-card.php, WooCommerce).
 *
 * Last modified: v3.18.12
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wc_get_products' ) ) {
    wp_die( 'WooCommerce is required for this page template.' );
}

if ( ! function_exists( 'dd_placeholder_img' ) ) {
    function dd_placeholder_img( $size = 'medium_large' ) {
        return function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( $size ) : '';
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  DATA PREP — unchanged from v3.18.5, reused verbatim (see release brief:
//  "reuse all data-fetching/wiring logic already built... this is a
//  structural/markup/CSS rewrite, not a re-investigation of data sources").
// ═══════════════════════════════════════════════════════════════════════════

$dd_name    = get_option( 'dish_dash_restaurant_name', 'Restaurant' );
$dd_primary = get_option( 'dish_dash_primary_color', '#6B1D1D' );
$dd_dark    = get_option( 'dish_dash_dark_color', '#160F0D' );
$dd_logo    = get_option( 'dish_dash_logo_url', '' );
$dd_addr    = get_option( 'dish_dash_address', '' );

$dd_show_cart = get_option( 'dd_header_show_cart', '1' ) === '1';
// dd_header_show_track_order has no corresponding element anywhere in this
// design or the shared header (no Track Order button exists site-wide) —
// see the release report. Not fabricated here.

$dd_pill_show       = get_option( 'dd_hero_pill_show', '1' ) === '1';
$dd_pill_text       = get_option( 'dd_hero_pill_text', '' );
$dd_h_title         = get_option( 'dish_dash_hero_title', 'Best Flavor in Town' );
$dd_h_sub           = get_option( 'dish_dash_hero_subtitle', '' );
$dd_h_img           = get_option( 'dish_dash_hero_image', '' );
$dd_hero_bg         = get_option( 'dd_hero_bg_image', '' );
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

$cats_desk     = get_option( 'dd_section_categories_desktop', '1' ) === '1';
$cats_mob      = get_option( 'dd_section_categories_mobile',  '0' ) === '1';
$cats_vis      = $cats_desk || $cats_mob;
$cats_class    = ( $cats_desk && ! $cats_mob ) ? 'dd-desktop-only' : ( ( ! $cats_desk && $cats_mob ) ? 'dd-mobile-only' : '' );
// Default fallback string only, changed to match the mockup's default copy
// ("The Menu") — dd_categories_title itself is still fully respected
// whenever an admin has configured it.
$dd_cats_title = get_option( 'dd_categories_title', 'The Menu' );
$dd_cats_count = (int) get_option( 'dd_categories_count', 0 );

$feat_desk       = get_option( 'dd_section_featured_desktop', '1' ) === '1';
$feat_mob        = get_option( 'dd_section_featured_mobile',  '0' ) === '1';
$feat_vis        = $feat_desk || $feat_mob;
$feat_class      = ( $feat_desk && ! $feat_mob ) ? 'dd-desktop-only' : ( ( ! $feat_desk && $feat_mob ) ? 'dd-mobile-only' : '' );
$dd_feat_title   = get_option( 'dd_featured_title', 'From the Kitchen' );
$dd_feat_count   = (int) get_option( 'dd_featured_count', 8 );
$dd_feat_orderby = get_option( 'dd_featured_orderby', 'popularity' );
$dd_feat_tag     = get_option( 'dd_featured_tag', '' );
$dd_feat_chips   = get_option( 'dd_featured_show_chips', '1' ) === '1';
$dd_chip_tags    = get_option( 'dd_featured_chip_tags', [] );
if ( is_string( $dd_chip_tags ) ) $dd_chip_tags = array_filter( explode( ',', $dd_chip_tags ) );

$reserve_desk    = get_option( 'dd_section_reserve_desktop', '1' ) === '1';
$reserve_mob     = get_option( 'dd_section_reserve_mobile',  '1' ) === '1';
$reserve_vis     = $reserve_desk || $reserve_mob;
$reserve_class   = ( $reserve_desk && ! $reserve_mob ) ? 'dd-desktop-only' : ( ( ! $reserve_desk && $reserve_mob ) ? 'dd-mobile-only' : '' );
$dd_reserve_bg   = get_option( 'dd_reserve_bg_image', '' );

$selcat_desk       = get_option( 'dd_section_selcat_desktop', '1' ) === '1';
$selcat_mob        = get_option( 'dd_section_selcat_mobile',  '0' ) === '1';
$selcat_vis        = $selcat_desk || $selcat_mob;
$selcat_class      = ( $selcat_desk && ! $selcat_mob ) ? 'dd-desktop-only' : ( ( ! $selcat_desk && $selcat_mob ) ? 'dd-mobile-only' : '' );
// Default fallback string changed to match the mockup's "Chef's Selection"
// copy — still just the prefix; dd_selcat_title remains fully admin-editable.
$dd_selcat_title   = get_option( 'dd_selcat_title', "Chef's Selection" );
$dd_selcat_count   = (int) get_option( 'dd_selcat_count', 8 );
$dd_selcat_slugs   = get_option( 'dd_selcat_slugs', [] );
if ( is_string( $dd_selcat_slugs ) ) $dd_selcat_slugs = array_filter( explode( ',', $dd_selcat_slugs ) );

$reviews_desk     = get_option( 'dd_section_reviews_desktop', '1' ) === '1';
$reviews_mob      = get_option( 'dd_section_reviews_mobile',  '1' ) === '1';
$reviews_vis      = $reviews_desk || $reviews_mob;
$reviews_class    = ( $reviews_desk && ! $reviews_mob ) ? 'dd-desktop-only' : ( ( ! $reviews_desk && $reviews_mob ) ? 'dd-mobile-only' : '' );
$dd_reviews_title = get_option( 'dd_reviews_title', 'What People Say' );

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
 * Featured/Best-sellers product fetch — unchanged from v3.18.5. 'popularity'
 * uses real DishDash sales data (same mechanism as Analytics' "Top Menu
 * Items" card: wp_dishdash_order_items grouped/counted on delivered,
 * non-test orders), resolved back to WC_Product objects — not WooCommerce's
 * generic total_sales popularity meta.
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
        $args = [ 'limit' => $limit > 0 ? $limit : -1, 'orderby' => 'popularity', 'order' => 'DESC', 'status' => 'publish' ];
        if ( $tag_slug ) $args['tag'] = [ $tag_slug ];
        return wc_get_products( $args ) ?: [];
    }

    $products = wc_get_products( [ 'include' => $ids, 'status' => 'publish', 'limit' => -1 ] );
    $by_id    = [];
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
        $feat_args['orderby'] = 'date'; $feat_args['order'] = 'DESC';
        $dd_best = wc_get_products( $feat_args ) ?: [];
        break;
    case 'price':
        $feat_args['orderby'] = 'price'; $feat_args['order'] = 'ASC';
        $dd_best = wc_get_products( $feat_args ) ?: [];
        break;
    case 'price-desc':
        $feat_args['orderby'] = 'price'; $feat_args['order'] = 'DESC';
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
$dd_reviews     = class_exists( 'DD_Homepage_Module' ) ? DD_Homepage_Module::get_reviews() : [];

$dd_maps_url = $dd_addr ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $dd_addr ) : '';

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

<?php if ( is_admin_bar_showing() ) : ?>
<div style="height:32px"></div>
<?php endif; ?>

<!-- ══ HEADER (own markup — icon-driven, no nav bar) ══════════════════════
     Reuses the shared hamburger/drawer/cart-badge IDs+classes so existing
     frontend.js/cart.js keeps working unmodified. wp_body_open() is NOT
     called here (see file docblock) — the shared global header never fires
     on this template. -->
<header class="dd-ml-header">
    <div class="dd-container dd-ml-header__inner">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="dd-ml-header__logo">
            <?php if ( $dd_logo ) : ?>
            <img src="<?php echo esc_url( $dd_logo ); ?>" alt="<?php echo esc_attr( $dd_name ); ?>" class="dd-ml-header__logo-img">
            <?php else : ?>
            <span class="dd-ml-header__logo-badge"><?php echo esc_html( strtoupper( substr( $dd_name, 0, 2 ) ) ); ?></span>
            <span class="dd-ml-header__logo-name"><?php echo esc_html( $dd_name ); ?></span>
            <?php endif; ?>
        </a>
        <div class="dd-ml-header__actions">
            <?php if ( $dd_maps_url ) : ?>
            <a href="<?php echo esc_url( $dd_maps_url ); ?>" target="_blank" rel="noopener" class="dd-ml-icon-btn" aria-label="Find us">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
            </a>
            <?php endif; ?>
            <button type="button" class="dd-ml-icon-btn dd-ml-header__cart" id="ddCartTopBtn" aria-label="Open cart">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                <span class="dd-cart-badge" id="ddCartCount" style="<?php echo $dd_cart_count > 0 ? '' : 'display:none'; ?>"><?php echo esc_html( $dd_cart_count ); ?></span>
            </button>
            <button class="dd-menu-toggle" id="ddMenuToggle" aria-label="Open menu" aria-expanded="false">
                <span class="dd-menu-toggle__bar"></span>
                <span class="dd-menu-toggle__bar"></span>
                <span class="dd-menu-toggle__bar"></span>
            </button>
        </div>
    </div>
</header>

<div class="dd-drawer-overlay" id="ddDrawerOverlay"></div>
<aside class="dd-nav-drawer" id="ddNavDrawer" aria-label="Navigation">
    <div class="dd-nav-drawer__header">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="dd-brand">
            <?php if ( $dd_logo ) : ?>
            <img src="<?php echo esc_url( $dd_logo ); ?>" alt="<?php echo esc_attr( $dd_name ); ?>" class="dd-brand__logo">
            <?php else : ?>
            <span class="dd-brand__badge"><?php echo esc_html( strtoupper( substr( $dd_name, 0, 2 ) ) ); ?></span>
            <div class="dd-brand__name"><?php echo esc_html( $dd_name ); ?></div>
            <?php endif; ?>
        </a>
        <button class="dd-nav-drawer__close" id="ddDrawerClose" aria-label="Close">&#10005;</button>
    </div>
    <nav class="dd-nav-drawer__nav">
        <?php
        $dd_nav_html = wp_nav_menu( [
            'theme_location' => 'dd-primary',
            'container'      => false,
            'items_wrap'     => '%3$s',
            'fallback_cb'    => false,
            'echo'           => false,
        ] );
        if ( $dd_nav_html ) {
            echo $dd_nav_html; // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped — wp_nav_menu returns safe markup
        } else {
            echo '<a href="' . esc_url( home_url( '/' ) ) . '">Home</a>';
            echo '<a href="' . esc_url( home_url( '/restaurant-menu/' ) ) . '">Our Menu</a>';
            echo '<a href="#reserve" class="js-open-reservation">Reserve a Table</a>';
        }
        ?>
    </nav>
    <div class="dd-nav-drawer__footer">
        <?php if ( is_user_logged_in() ) :
            $account_url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'my-profile' ) : home_url( '/my-account/my-profile/' );
        ?>
        <a href="<?php echo esc_url( $account_url ); ?>" class="dd-btn dd-btn--light dd-btn--block">&#128100; My Profile</a>
        <button id="ddLogoutBtn" class="dd-nav-drawer__logout">Log out</button>
        <?php else : ?>
        <button id="ddOpenRegister" class="dd-btn dd-btn--brand dd-btn--block" style="margin-bottom:10px;">&#128100; Create Account</button>
        <button id="ddOpenLogin" class="dd-btn dd-btn--light dd-btn--block">Log in</button>
        <?php endif; ?>
    </div>
</aside>

<!-- ══ HERO (split-screen) ═════════════════════════════════════════════════ -->
<section class="dd-ml-hero" id="top">
    <div class="dd-container dd-ml-hero__grid">
        <div class="dd-ml-hero__content">
            <?php if ( $dd_pill_show && '' !== trim( (string) $dd_pill_text ) ) : ?>
            <span class="dd-ml-pill"><?php echo esc_html( $dd_pill_text ); ?></span>
            <?php endif; ?>
            <h1 class="dd-ml-hero__title"><?php echo wp_kses_post( $dd_h_title ); ?></h1>
            <?php if ( $dd_h_sub ) : ?>
            <p class="dd-ml-copy"><?php echo esc_html( $dd_h_sub ); ?></p>
            <?php endif; ?>

            <?php
            // All 3 configured CTAs render as understated text-links, in
            // configured order, separated by " · " — confirmed with the
            // developer (2026-08-07): keep every Settings field visible
            // rather than silently dropping btn3 to match a literal 2-link
            // mockup count.
            $dd_hero_links = [
                [ $dd_btn1_label, $dd_btn1_link, false ],
                [ $dd_btn2_label, $dd_btn2_link, true ],  // js-open-reservation, matches existing "#reserve" default convention
                [ $dd_btn3_label, $dd_btn3_link, false ],
            ];
            $dd_hero_links = array_values( array_filter( $dd_hero_links, fn( $l ) => trim( (string) $l[0] ) !== '' ) );
            ?>
            <?php if ( $dd_hero_links ) : ?>
            <div class="dd-ml-hero__links">
                <?php foreach ( $dd_hero_links as $i => $link ) : ?>
                <?php if ( $i > 0 ) : ?><span class="dd-ml-hero__links-sep">&middot;</span><?php endif; ?>
                <a href="<?php echo esc_url( $link[1] ); ?>" class="dd-ml-text-link<?php echo $link[2] ? ' js-open-reservation' : ''; ?>"><?php echo esc_html( $link[0] ); ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ( $dd_show_chips && array_filter( $dd_chips ) ) : ?>
            <div class="dd-ml-hero__chips">
                <?php foreach ( $dd_chips as $chip ) : if ( ! $chip ) continue; ?>
                <div class="dd-ml-hero__chip"><?php echo esc_html( $chip ); ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php
        $hero_img     = $dd_h_img ?: $dd_hero_bg;
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
             class="dd-ml-hero__photo">
        <?php endif; ?>
    </div>
</section>

<!-- ══ FOOD CATEGORY LIST (MOBILE ONLY — unchanged concept) ═══════════════ -->
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

<!-- ══ "THE MENU" — bordered table-style category index ═══════════════════ -->
<?php if ( $cats_vis && ! empty( $dd_cats ) ) : ?>
<section class="dd-ml-section <?php echo esc_attr( $cats_class ); ?>" id="categories">
    <div class="dd-container">
        <div class="dd-ml-top">
            <div>
                <div class="dd-ml-eyebrow">Browse by category</div>
                <h2 class="dd-ml-title"><?php echo esc_html( $dd_cats_title ); ?></h2>
            </div>
        </div>
        <div class="dd-ml-index">
            <?php foreach ( $dd_cats as $i => $cat ) : ?>
            <a class="dd-ml-index__cell" href="<?php echo esc_url( home_url( '/restaurant-menu/?cat=' . $cat->slug ) ); ?>">
                <span class="dd-ml-index__num"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></span>
                <span>
                    <span class="dd-ml-index__name"><?php echo esc_html( $cat->name ); ?></span>
                    <span class="dd-ml-index__count"><?php echo (int) $cat->count; ?> dishes</span>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══ "FROM THE KITCHEN" — Featured Dishes, horizontal strip ═════════════ -->
<?php if ( $feat_vis ) : ?>
<section class="dd-ml-section dd-ml-section--surface <?php echo esc_attr( $feat_class ); ?>" id="menu">
    <div class="dd-container">
        <div class="dd-ml-top">
            <div>
                <div class="dd-ml-eyebrow">Featured dishes</div>
                <h2 class="dd-ml-title"><?php echo esc_html( $dd_feat_title ); ?></h2>
            </div>
            <?php if ( ! empty( $dd_best ) && count( $dd_best ) > 1 ) : ?>
            <div class="dd-ml-arrows">
                <button type="button" class="dd-ml-arrow-btn" id="ddMlFeatPrev" aria-label="Scroll left">&#8592;</button>
                <button type="button" class="dd-ml-arrow-btn" id="ddMlFeatNext" aria-label="Scroll right">&#8594;</button>
            </div>
            <?php endif; ?>
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

        <div class="dd-ml-strip" id="ddMlFeatRow">
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

<!-- ══ RESERVE BAND (full-width dark contrast moment) ═════════════════════ -->
<?php if ( $reserve_vis ) :
    $dd_reserve_has_img = ! empty( $dd_reserve_bg );
    $reserve_style       = $dd_reserve_has_img ? '--ml-reserve-bg-image: url(' . esc_url( $dd_reserve_bg ) . ');' : '';
?>
<section class="dd-ml-reserve<?php echo $dd_reserve_has_img ? ' dd-ml-reserve--has-image' : ''; ?> <?php echo esc_attr( $reserve_class ); ?>" id="reserve" style="<?php echo esc_attr( $reserve_style ); ?>">
    <div class="dd-container dd-ml-reserve__inner">
        <div class="dd-ml-eyebrow">Reserve your table</div>
        <h2 class="dd-ml-reserve__title">A dining experience that feels as rich as the food.</h2>
        <button type="button" class="dd-ml-reserve__link" id="dd-open-reservation">Book now &rarr;</button>
    </div>
</section>
<?php endif; ?>

<!-- ══ "CHEF'S SELECTION — [category]" — Selected Category, horizontal strip ═ -->
<?php if ( $selcat_vis && ! empty( $dd_selcat_cats ) ) : ?>
<section class="dd-ml-section <?php echo esc_attr( $selcat_class ); ?>" id="category-dishes">
    <div class="dd-container">
        <div class="dd-ml-top">
            <div>
                <div class="dd-ml-eyebrow">Chef's picks</div>
                <h2 class="dd-ml-title"><?php echo esc_html( $dd_selcat_title ); ?> &mdash; <span class="dd-gold" id="ddMlSelcatActiveCat"><?php echo esc_html( $dd_selcat_cats[0]->name ); ?></span></h2>
            </div>
            <div class="dd-ml-arrows">
                <button type="button" class="dd-ml-arrow-btn" id="ddMlSelcatPrev" aria-label="Scroll left">&#8592;</button>
                <button type="button" class="dd-ml-arrow-btn" id="ddMlSelcatNext" aria-label="Scroll right">&#8594;</button>
            </div>
        </div>

        <div class="dd-ml-tabs-top">
            <div class="dd-ml-arrows">
                <button type="button" class="dd-ml-arrow-btn" id="ddMlSelcatTabsPrev" aria-label="Scroll categories left">&#8592;</button>
                <button type="button" class="dd-ml-arrow-btn" id="ddMlSelcatTabsNext" aria-label="Scroll categories right">&#8594;</button>
            </div>
        </div>
        <div class="dd-ml-tabs" id="ddMlSelcatTabs">
            <?php foreach ( $dd_selcat_cats as $i => $cat ) : ?>
            <button type="button" class="dd-ml-tab<?php echo $i === 0 ? ' active' : ''; ?>" data-slug="<?php echo esc_attr( $cat->slug ); ?>" data-name="<?php echo esc_attr( $cat->name ); ?>"><?php echo esc_html( $cat->name ); ?></button>
            <?php endforeach; ?>
        </div>

        <?php foreach ( $dd_selcat_cats as $i => $cat ) : ?>
        <div class="dd-ml-strip" id="ddMlSelcatPanel-<?php echo esc_attr( $cat->slug ); ?>" data-panel="<?php echo esc_attr( $cat->slug ); ?>" <?php echo $i !== 0 ? 'hidden' : ''; ?>>
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

<!-- ══ "WHAT PEOPLE SAY" — Reviews, horizontal strip ═══════════════════════ -->
<?php if ( $reviews_vis && ! empty( $dd_reviews ) ) : ?>
<section class="dd-ml-section dd-ml-section--surface <?php echo esc_attr( $reviews_class ); ?>" id="reviews">
    <div class="dd-container">
        <div class="dd-ml-top">
            <div>
                <div class="dd-ml-eyebrow">Loved by guests</div>
                <h2 class="dd-ml-title"><?php echo esc_html( $dd_reviews_title ); ?></h2>
            </div>
            <?php if ( count( $dd_reviews ) > 1 ) : ?>
            <div class="dd-ml-arrows">
                <button type="button" class="dd-ml-arrow-btn" id="ddMlReviewsPrev" aria-label="Scroll left">&#8592;</button>
                <button type="button" class="dd-ml-arrow-btn" id="ddMlReviewsNext" aria-label="Scroll right">&#8594;</button>
            </div>
            <?php endif; ?>
        </div>
        <div class="dd-ml-strip" id="ddMlReviewsRow">
            <?php foreach ( $dd_reviews as $r ) :
                $author  = trim( (string) ( $r['author'] ?? '' ) ) ?: 'Guest';
                $initial = strtoupper( mb_substr( $author, 0, 1 ) );
                $rating  = max( 1, min( 5, (int) ( $r['rating'] ?? 5 ) ) );
                $text    = trim( (string) ( $r['text'] ?? '' ) );
                // Same 160-char threshold + line-clamp/"Read more" pattern
                // Khana Khazana's own reviews section already uses
                // (templates/page-dishdash.php) — reused here rather than
                // inventing a new truncation approach, keeps card height
                // consistent regardless of review length.
                $is_long = mb_strlen( $text ) > 160;
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
                <?php if ( $text ) : ?>
                <p class="dd-ml-review__text<?php echo $is_long ? ' is-collapsible' : ''; ?>" data-collapsed="1"><?php echo nl2br( esc_html( $text ) ); ?></p>
                <?php if ( $is_long ) : ?>
                <button type="button" class="dd-ml-review__more">Read more</button>
                <?php endif; ?>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Footer + cart drawer + mobile bottom nav + reservation modal + product
     modal injected globally by DD_Template_Module via wp_footer (unchanged
     shared chrome — see minimal-light.css for the restyling-only overrides). -->
<?php wp_footer(); ?>

<script>
(function () {
    // Scroll arrows — same simple, always-visible, fixed-distance pattern
    // as Khana Khazana's own category-row arrows (assets/js/frontend.js's
    // setupArrows(): scrollBy 300px, no hide/disable-at-boundary logic).
    // Reused verbatim here across all three strips for consistency, not a
    // new/different behavior invented for Minimal Light.
    function setupMlArrows(prevId, nextId, rowId) {
        var row  = document.getElementById(rowId);
        var prev = document.getElementById(prevId);
        var next = document.getElementById(nextId);
        if (!row || !prev || !next) return;
        if (prev.dataset.bound === rowId) return;
        prev.dataset.bound = rowId;
        next.dataset.bound = rowId;
        prev.addEventListener('click', function () { row.scrollBy({ left: -300, behavior: 'smooth' }); });
        next.addEventListener('click', function () { row.scrollBy({ left: 300, behavior: 'smooth' }); });
    }
    setupMlArrows('ddMlFeatPrev', 'ddMlFeatNext', 'ddMlFeatRow');
    setupMlArrows('ddMlReviewsPrev', 'ddMlReviewsNext', 'ddMlReviewsRow');
    setupMlArrows('ddMlSelcatTabsPrev', 'ddMlSelcatTabsNext', 'ddMlSelcatTabs');

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

    var tabsWrap  = document.getElementById('ddMlSelcatTabs');
    var activeCat = document.getElementById('ddMlSelcatActiveCat');
    if (tabsWrap) {
        // Bind arrows to whichever panel is active at load (mirrors
        // frontend.js's own activeSelCatRow handling for Khana Khazana's
        // Selected Category tabs).
        var firstPanel = document.querySelector('.dd-ml-strip[data-panel]:not([hidden])');
        if (firstPanel) setupMlArrows('ddMlSelcatPrev', 'ddMlSelcatNext', firstPanel.id);

        tabsWrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.dd-ml-tab');
            if (!btn) return;
            var slug = btn.dataset.slug;
            tabsWrap.querySelectorAll('.dd-ml-tab').forEach(function (b) { b.classList.toggle('active', b === btn); });
            document.querySelectorAll('.dd-ml-strip[data-panel]').forEach(function (panel) {
                panel.hidden = panel.dataset.panel !== slug;
            });
            if (activeCat && btn.dataset.name) activeCat.textContent = btn.dataset.name;
            // Re-bind arrows to the newly-visible panel.
            setupMlArrows('ddMlSelcatPrev', 'ddMlSelcatNext', 'ddMlSelcatPanel-' + slug);
        });
    }

    // Review "Read more" — same collapsed/expanded toggle pattern as
    // Khana Khazana's own reviews section (templates/page-dishdash.php),
    // reimplemented against this file's own markup (no shared JS touched).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.dd-ml-review__more');
        if (!btn) return;
        var card = btn.closest('.dd-ml-review');
        var txt  = card && card.querySelector('.dd-ml-review__text');
        if (!txt) return;
        var collapsed = txt.getAttribute('data-collapsed') === '1';
        txt.setAttribute('data-collapsed', collapsed ? '0' : '1');
        btn.textContent = collapsed ? 'Show less' : 'Read more';
    });
})();
</script>

</body>
</html>
