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
 * v3.18.16: Reviews now fetched via DD_Homepage_Module::get_reviews_with_debug()
 * — the shared pipeline extracted from Khana Khazana's page-dishdash.php
 * (dual sort-order fetch, pooled/deduped, 24h refresh, debug diagnostics),
 * replacing the previous simpler single-sort/12h-cache call.
 *
 * v3.18.44: Header/hero redesign. Header gained a desktop-only Home/Menu/
 * Reservation nav (.dd-ml-nav, still hamburger+drawer at every breakpoint
 * underneath), a search icon wired to the existing site-wide AJAX search
 * (search.js's initMobile(), reusing its exact #ddMobileSearchTrigger/
 * #ddMobileSearchPanel markup), and a desktop-only Log in/My Profile button
 * (#ddOpenLogin, mirrors Khana Khazana's own shared-header pattern — see
 * render_global_header()). Hero rebuilt: dashed-circle photo frame +
 * floating "Best Seller" spotlight card (sourced from $dd_best, the same
 * popularity-ranked list "From the Kitchen" already uses — no new field),
 * an open-hours line (dd_get_hours_state AJAX, same cache-safe pattern as
 * frontend.js's setupHoursBanner()), and the 3 configured CTA fields now
 * render as solid/outline buttons instead of text-links (first = solid,
 * rest = outline). --ml-accent now sources dish_dash_accent_color instead
 * of the shared --brand — see minimal-light.css and
 * investigation-ml-header-hero-redesign.md §6.
 *
 * v3.18.45: Nav hover investigated live — confirmed correct code, tenant
 * data issue (dish_dash_accent_color stored as white on the demo site), no
 * code change here — see investigation-ml-nav-hover-bg.md §1 and the
 * release report. dd_hero_bg_image now wired as a real full-section hero
 * background (previously only a foreground-image fallback — kept, unchanged,
 * for that job too) with a soft light wash from dd_hero_overlay_color/
 * _opacity (previously unused). H1 typography pass — see minimal-light.css.
 *
 * Last modified: v3.18.45
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

// v3.18.44: hero floating spotlight card — reuses $dd_best (already fetched
// above for "From the Kitchen", same popularity ranking Analytics' own "Top
// Menu Items" card uses), computed unconditionally so it's available for the
// card regardless of whether a hero image is configured (previously this
// same first-best-seller lookup only ran as an image FALLBACK, inside the
// hero-image-resolution block below — now factored out so both uses share
// one source instead of two separate reads of $dd_best[0]).
$dd_hero_spotlight = ! empty( $dd_best ) ? $dd_best[0] : null;

// v3.18.44: hero open-hours line — PHP only renders a same-wording
// placeholder for first paint; an inline script below re-fetches
// dd_get_hours_state (same cache-safe AJAX pattern frontend.js's
// setupHoursBanner() already uses, see v3.18.41/43 notes) and fills in the
// exact close/next-open time client-side, since window.DD.hours_schedule
// isn't populated on this page (only render_global_header()/
// render_minimal_light_header() emit that bridge, and neither fires here).
$dd_hours_labels = [
    'open'         => 'Open now',
    'closing_soon' => 'Closing soon',
    'break'        => 'On a break',
    'closed'       => 'Closed now',
];
$dd_hours_initial_text  = $dd_hours_labels[ $dd_hours_state ] ?? 'Open now';
$dd_hours_initial_class = $dd_hours_state === 'open'
    ? 'is-open'
    : ( in_array( $dd_hours_state, [ 'closing_soon', 'break' ], true ) ? 'is-soon' : 'is-closed' );

// v3.18.45: hero SECTION background — $dd_hero_bg (dd_hero_bg_image) was
// previously only read as a fallback source for the circular foreground
// photo (see below); it's now ALSO applied as the actual full-section
// background image, same option, two different jobs — the circular photo
// (dish_dash_hero_image, or this same field as ITS fallback) stays a
// distinct, separately-sourced element on top. dd_hero_overlay_color/
// _opacity are the same fields Khana Khazana's dark full-bleed hero uses
// (templates/page-dishdash.php) — reused here (no new fields), but
// reinterpreted for a soft light wash instead of a dark scrim: the stored
// opacity (0-100, meant as "how strong/opaque" for KK's near-opaque dark
// treatment) is scaled down to a 0-25% range here, so even the admin's
// maximum setting stays subtle enough to let the photo show through behind
// the white text column rather than obscuring it.
$dd_hero_overlay_color   = get_option( 'dd_hero_overlay_color', '#6B1D1D' ) ?: '#6B1D1D';
$dd_hero_overlay_opacity = (int) get_option( 'dd_hero_overlay_opacity', 85 );
$dd_hero_tint_alpha      = round( ( $dd_hero_overlay_opacity / 100 ) * 0.25, 3 );
$dd_hero_tint_rgb        = implode( ',', array_map( 'hexdec', str_split( ltrim( $dd_hero_overlay_color, '#' ), 2 ) ) );
$dd_hero_section_style   = '';
if ( $dd_hero_bg ) {
    $dd_hero_section_style .= '--ml-hero-bg-image: url(' . esc_url( $dd_hero_bg ) . ');';
    $dd_hero_section_style .= '--ml-hero-bg-tint: rgba(' . esc_attr( $dd_hero_tint_rgb ) . ',' . esc_attr( $dd_hero_tint_alpha ) . ');';
}

// Shared Google Reviews pipeline (dual sort-order fetch, pooled/deduped,
// 24h refresh, debug diagnostics) — extracted from Khana Khazana's
// page-dishdash.php in v3.18.16, where it was originally built and
// proven. Replaces the previous simpler single-sort/12h-cache call.
$dd_reviews_result = class_exists( 'DD_Homepage_Module' ) ? DD_Homepage_Module::get_reviews_with_debug() : [ 'items' => [], 'debug' => [] ];
$dd_reviews         = $dd_reviews_result['items'];
$dd_reviews_debug   = $dd_reviews_result['debug'];

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

        <!-- v3.18.44: Home / Menu / Reservation — desktop-only, hamburger+
             drawer stays the nav's mobile fallback (see .dd-desktop-only,
             theme.css:2922-2929, same utility class the shared Khana
             Khazana header already uses for this exact purpose). -->
        <nav class="dd-ml-nav dd-desktop-only" aria-label="Primary">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="dd-ml-nav__link">Home</a>
            <a href="<?php echo esc_url( home_url( '/restaurant-menu/' ) ); ?>" class="dd-ml-nav__link">Menu</a>
            <a href="#reserve" class="dd-ml-nav__link js-open-reservation">Reservation</a>
        </nav>

        <div class="dd-ml-header__actions">
            <?php if ( $dd_maps_url ) : ?>
            <a href="<?php echo esc_url( $dd_maps_url ); ?>" target="_blank" rel="noopener" class="dd-ml-icon-btn" aria-label="Find us">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
            </a>
            <?php endif; ?>
            <!-- v3.18.44: search icon — id-only (no .dd-mobile-search-trigger
                 class, see minimal-light.css) so it's styled purely as a
                 .dd-ml-icon-btn, wired to search.js's existing initMobile()
                 via the #ddMobileSearchTrigger id, zero JS changes. -->
            <button type="button" class="dd-ml-icon-btn" id="ddMobileSearchTrigger" aria-label="Search dishes">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </button>
            <?php if ( is_user_logged_in() ) :
                $dd_account_url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'my-profile' ) : home_url( '/my-account/my-profile/' );
            ?>
            <a href="<?php echo esc_url( $dd_account_url ); ?>" class="dd-ml-btn dd-ml-btn--outline dd-desktop-only">My Profile</a>
            <?php else : ?>
            <button type="button" id="ddOpenLogin" class="dd-ml-btn dd-ml-btn--outline dd-desktop-only">Log in</button>
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

    <!-- v3.18.44: search expand panel — exact markup/IDs search.js's
         initMobile() already expects (#ddMobileSearchPanel/#ddMobileSearch/
         #ddMobileSearchDropdown/#ddMobileSearchClose), reused verbatim from
         the shared Khana Khazana header (class-dd-template-module.php's
         render_global_header()) so the AJAX-backed search (dd_get_search_products)
         works with zero JS changes. minimal-light.css overrides theme.css's
         desktop-hide for this panel specifically (icon-driven at every
         breakpoint, not just mobile). -->
    <div class="dd-mobile-search-panel" id="ddMobileSearchPanel" aria-hidden="true">
        <div class="dd-mobile-search-panel__inner">
            <div class="dd-ss__bar dd-ss__bar--mobile-expand">
                <span class="dd-ss__icon">&#128269;</span>
                <input type="search"
                       id="ddMobileSearch"
                       name="dd_search_mobile"
                       class="dd-ss__input"
                       placeholder="Search dishes&hellip;"
                       autocomplete="off"
                       autocorrect="off"
                       autocapitalize="off"
                       spellcheck="false"
                       aria-label="Search dishes">
                <button class="dd-mobile-search-close" id="ddMobileSearchClose" aria-label="Close search">Cancel</button>
            </div>
            <div class="dd-ss__dropdown dd-ss__dropdown--mobile" id="ddMobileSearchDropdown" role="listbox"></div>
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
    <!-- v3.18.44: unchanged — the header's new Log in/My Profile button
         (#ddOpenLogin / My Profile link) is desktop-only (.dd-desktop-only,
         matching Khana Khazana's shared-header precedent exactly, see
         render_global_header()), so the drawer stays the mobile fallback
         for account access at every breakpoint, same as before. Duplicate
         #ddOpenLogin/#ddOpenRegister ids across header+drawer at desktop
         width are safe — the auth module binds both via event delegation
         (e.target.closest('#id')), not getElementById, and Khana Khazana's
         own shared header already ships this exact duplicate-id pattern
         today. -->
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
<section class="dd-ml-hero" id="top" style="<?php echo esc_attr( $dd_hero_section_style ); ?>">
    <div class="dd-container dd-ml-hero__grid">
        <div class="dd-ml-hero__content">
            <?php if ( $dd_pill_show && '' !== trim( (string) $dd_pill_text ) ) : ?>
            <span class="dd-ml-pill"><?php echo esc_html( $dd_pill_text ); ?></span>
            <?php endif; ?>
            <h1 class="dd-ml-hero__title"><?php echo wp_kses_post( $dd_h_title ); ?></h1>
            <?php if ( $dd_h_sub ) : ?>
            <p class="dd-ml-copy"><?php echo esc_html( $dd_h_sub ); ?></p>
            <?php endif; ?>

            <div class="dd-ml-hours-line <?php echo esc_attr( $dd_hours_initial_class ); ?>" id="ddMlHoursLine">
                <span class="dd-ml-hours-dot"></span>
                <span class="dd-ml-hours-text"><?php echo esc_html( $dd_hours_initial_text ); ?></span>
            </div>

            <?php
            // v3.18.44: same 3 configured CTA fields as before (dd_hero_btn1/2/3)
            // — only the visual treatment changed, from text-links to buttons.
            // First configured CTA renders solid/primary, every CTA after it
            // renders outlined/secondary — "which one is solid" follows
            // Settings order (fully admin-editable), not a hardcoded field.
            $dd_hero_links = [
                [ $dd_btn1_label, $dd_btn1_link, false ],
                [ $dd_btn2_label, $dd_btn2_link, true ],  // js-open-reservation, matches existing "#reserve" default convention
                [ $dd_btn3_label, $dd_btn3_link, false ],
            ];
            $dd_hero_links = array_values( array_filter( $dd_hero_links, fn( $l ) => trim( (string) $l[0] ) !== '' ) );
            ?>
            <?php if ( $dd_hero_links ) : ?>
            <div class="dd-ml-hero__ctas">
                <?php foreach ( $dd_hero_links as $i => $link ) :
                    $dd_cta_style = $i === 0 ? 'dd-ml-btn--solid' : 'dd-ml-btn--outline';
                ?>
                <a href="<?php echo esc_url( $link[1] ); ?>" class="dd-ml-btn <?php echo esc_attr( $dd_cta_style ); ?><?php echo $link[2] ? ' js-open-reservation' : ''; ?>"><?php echo esc_html( $link[0] ); ?></a>
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
        if ( ! $hero_img && $dd_hero_spotlight ) {
            $hero_product = $dd_hero_spotlight;
            $img_id       = $hero_product->get_image_id();
            $hero_img     = $img_id ? wp_get_attachment_image_url( $img_id, 'large' ) : dd_placeholder_img( 'large' );
        }
        ?>
        <?php if ( $hero_img ) : ?>
        <div class="dd-ml-hero__media">
            <div class="dd-ml-hero__frame">
                <img src="<?php echo esc_url( $hero_img ); ?>"
                     alt="<?php echo $hero_product ? esc_attr( $hero_product->get_name() ) : esc_attr( $dd_name ); ?>"
                     class="dd-ml-hero__photo">
            </div>
            <?php if ( $dd_hero_spotlight ) :
                $dd_spot_img_id = $dd_hero_spotlight->get_image_id();
                $dd_spot_img    = $dd_spot_img_id ? wp_get_attachment_image_url( $dd_spot_img_id, 'thumbnail' ) : dd_placeholder_img( 'thumbnail' );
                $dd_spot_price  = (float) $dd_hero_spotlight->get_price();
                $dd_spot_price  = $dd_spot_price ? 'RWF ' . number_format( $dd_spot_price, 0, '.', ',' ) : '';
            ?>
            <div class="dd-ml-hero__card">
                <span class="dd-ml-hero__card-img"><img src="<?php echo esc_url( $dd_spot_img ); ?>" alt="" loading="lazy"></span>
                <span class="dd-ml-hero__card-body">
                    <span class="dd-ml-hero__card-tag">Best Seller</span>
                    <span class="dd-ml-hero__card-name"><?php echo esc_html( $dd_hero_spotlight->get_name() ); ?></span>
                    <?php if ( $dd_spot_price ) : ?><span class="dd-ml-hero__card-price"><?php echo esc_html( $dd_spot_price ); ?></span><?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
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
        <!-- v3.18.24: same circular 4-column grid component as the Menu
             page's mobile Screen 1 (grid.php) — same classes, same CSS
             (assets/css/layouts/minimal-light.css), so both stay visually
             identical from one shared ruleset. Menu page's version uses
             <li data-cat-id> (JS click delegate, menu-page.js); this one
             uses <a href> for direct navigation, since there's no JS
             screen-switch here — the only structural difference the two
             contexts actually require. Data prep above this section
             (dd_all_cats derivation) is unchanged — replacing markup only. -->
        <div class="dd-mobile-category-list">
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
        <a href="<?php echo esc_url( home_url( '/restaurant-menu/?cat=' . $cat_slug ) ); ?>" class="dd-mobile-category-item">
            <span class="dd-mobile-cat-tile__photo">
                <?php if ( $cat_img ) : ?>
                <img src="<?php echo esc_url( $cat_img ); ?>" alt="<?php echo esc_attr( $cat_name ); ?>" loading="lazy">
                <?php else : ?>
                <span class="dd-mobile-cat-tile__initial"><?php echo esc_html( strtoupper( substr( $cat_name, 0, 1 ) ) ); ?></span>
                <?php endif; ?>
            </span>
            <span class="dd-mobile-category-item__name"><?php echo esc_html( $cat_name ); ?></span>
            <span class="dd-mobile-cat-tile__count"><?php echo (int) $cat_count; ?></span>
        </a>
        <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
<?php endif; ?>

<!-- ══ "BROWSE BY CATEGORY" — paginated photo-tile grid ════════════════════
     v3.18.17 — replaces the old bordered text-table index with photo
     tiles, per approved mockup. Uses WooCommerce's existing category
     Thumbnail field (thumbnail_id term meta — same mechanism already
     read by DD_API::normalize_category() and this file's own mobile
     Food Category List below), with the mobile list's proven
     initial-letter fallback reused verbatim for categories without one.
     $dd_cats itself is untouched — still the same get_terms() call with
     menu_order ordering from the DATA PREP section above; this only
     adds a per-category thumbnail_id lookup inside the existing loop.
     v3.18.18 — was a horizontal-scroll strip; now a static 4x2 grid
     (8 tiles/page), all pages pre-rendered and toggled via [hidden] (same
     show/hide-panel technique Selected Category's tabs already use below),
     arrows paginate instead of scrollBy and disable at the first/last page. -->
<?php if ( $cats_vis && ! empty( $dd_cats ) ) :
    $dd_cats_pages      = array_chunk( $dd_cats, 8 );
    $dd_cats_page_count = count( $dd_cats_pages );
?>
<section class="dd-ml-section <?php echo esc_attr( $cats_class ); ?>" id="categories">
    <div class="dd-container">
        <div class="dd-ml-top">
            <div>
                <div class="dd-ml-eyebrow">Browse by category</div>
                <h2 class="dd-ml-title"><?php echo esc_html( $dd_cats_title ); ?></h2>
            </div>
            <div class="dd-ml-cat-controls">
                <a href="<?php echo esc_url( home_url( '/restaurant-menu/' ) ); ?>" class="dd-ml-text-link">See all (<?php echo count( $dd_cats ); ?>)</a>
                <?php if ( $dd_cats_page_count > 1 ) : ?>
                <div class="dd-ml-arrows">
                    <button type="button" class="dd-ml-arrow-btn" id="ddMlCatsPrev" aria-label="Previous categories" disabled>&#8592;</button>
                    <button type="button" class="dd-ml-arrow-btn" id="ddMlCatsNext" aria-label="Next categories">&#8594;</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="dd-ml-cat-pages" id="ddMlCatsPages">
            <?php foreach ( $dd_cats_pages as $page_i => $page_cats ) : ?>
            <div class="dd-ml-cat-grid" data-page="<?php echo (int) $page_i; ?>" <?php echo $page_i !== 0 ? 'hidden' : ''; ?>>
                <?php foreach ( $page_cats as $cat ) :
                    $cat_tid = get_term_meta( $cat->term_id, 'thumbnail_id', true );
                    $cat_img = $cat_tid ? wp_get_attachment_image_url( $cat_tid, 'medium' ) : '';
                ?>
                <a class="dd-ml-cat-tile" href="<?php echo esc_url( home_url( '/restaurant-menu/?cat=' . $cat->slug ) ); ?>">
                    <div class="dd-ml-cat-tile__photo">
                        <?php if ( $cat_img ) : ?>
                        <img src="<?php echo esc_url( $cat_img ); ?>" alt="<?php echo esc_attr( $cat->name ); ?>" loading="lazy">
                        <?php else : ?>
                        <span class="dd-ml-cat-tile__initial"><?php echo esc_html( strtoupper( substr( $cat->name, 0, 1 ) ) ); ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="dd-ml-cat-tile__name"><?php echo esc_html( $cat->name ); ?></span>
                    <span class="dd-ml-cat-tile__count"><?php echo (int) $cat->count; ?> dishes</span>
                </a>
                <?php endforeach; ?>
            </div>
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
<?php if ( $reviews_vis ) : ?>
<!-- DD Reviews Debug: <?php echo esc_html( wp_json_encode( $dd_reviews_debug ) ); ?> -->
<?php endif; ?>
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

    // Category grid — paginated (8/page), not a scroll strip, so arrows
    // advance/retreat a page index and disable at the first/last page
    // instead of scrollBy-ing (mirrors the disable-at-boundary approach
    // already used for arrow buttons elsewhere on this page, e.g. Khana
    // Khazana's own dd-greviews-arrow[disabled] pattern) rather than the
    // always-visible scrollBy convention the other strips use.
    (function setupMlCatsPagination() {
        var wrap = document.getElementById('ddMlCatsPages');
        var prev = document.getElementById('ddMlCatsPrev');
        var next = document.getElementById('ddMlCatsNext');
        if (!wrap || !prev || !next) return;

        var pages = Array.prototype.slice.call(wrap.querySelectorAll('.dd-ml-cat-grid[data-page]'));
        if (pages.length < 2) return;

        var current = 0;

        function render() {
            pages.forEach(function (page, i) { page.hidden = i !== current; });
            prev.disabled = current === 0;
            next.disabled = current === pages.length - 1;
        }

        prev.addEventListener('click', function () {
            if (current === 0) return;
            current -= 1;
            render();
        });
        next.addEventListener('click', function () {
            if (current === pages.length - 1) return;
            current += 1;
            render();
        });

        render();
    })();

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

    // Hero open-hours line (v3.18.44) — re-fetches dd_get_hours_state (same
    // cache-safe AJAX endpoint frontend.js's setupHoursBanner() already
    // uses) rather than trusting window.DD.hours_state, since window.DD
    // isn't populated on this page at all (only render_global_header()/
    // render_minimal_light_header() emit that bridge — neither fires here).
    // PHP already rendered a same-wording placeholder for first paint; this
    // just fills in the exact close/next-open time once it loads.
    (function setupMlHoursLine() {
        var el = document.getElementById('ddMlHoursLine');
        if (!el) return;
        var textEl = el.querySelector('.dd-ml-hours-text');
        var ajaxUrl = (window.DD && window.DD.ajaxUrl) || '/wp-admin/admin-ajax.php';

        function fmtTime(ts) {
            if (!ts) return '';
            try {
                return new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }).format(new Date(ts * 1000));
            } catch (e) { return ''; }
        }

        function render(state, nextOpenTs, closeTs) {
            var text, cls;
            if (state === 'open') {
                var t1 = fmtTime(closeTs);
                text = t1 ? ('Open now · Closes ' + t1) : 'Open now';
                cls = 'is-open';
            } else if (state === 'closing_soon') {
                var t2 = fmtTime(closeTs);
                text = t2 ? ('Closing soon · ' + t2) : 'Closing soon';
                cls = 'is-soon';
            } else if (state === 'break') {
                var t3 = fmtTime(nextOpenTs);
                text = t3 ? ('On a break · Back ' + t3) : 'On a break';
                cls = 'is-soon';
            } else {
                var t4 = fmtTime(nextOpenTs);
                text = t4 ? ('Closed now · Opens ' + t4) : 'Closed now';
                cls = 'is-closed';
            }
            if (textEl) textEl.textContent = text;
            el.classList.remove('is-open', 'is-soon', 'is-closed');
            el.classList.add(cls);
        }

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'dd_get_hours_state' })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success || !res.data) return;
            render(res.data.hours_state || 'open', parseInt(res.data.next_open_ts || 0, 10), parseInt(res.data.close_ts || 0, 10));
        })
        .catch(function () {});
    })();
})();
</script>

</body>
</html>
