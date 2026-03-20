<?php
/**
 * Dish Dash – Full Page Template
 *
 * Standalone page template with branded header,
 * hero section, menu content, and footer.
 *
 * Usage: Edit page → Page Attributes → Template
 * → select "Dish Dash Full Page"
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Settings ─────────────────────────────────
$restaurant_name = get_option( 'dish_dash_restaurant_name', get_bloginfo( 'name' ) );
$restaurant_logo = get_option( 'dish_dash_logo_url', '' );
$primary_color   = get_option( 'dish_dash_primary_color', '#E8832A' );
$dark_color      = get_option( 'dish_dash_dark_color', '#1E3A5F' );
$hero_title      = get_option( 'dish_dash_hero_title', 'Hello Dear,' );
$hero_subtitle   = get_option( 'dish_dash_hero_subtitle', "Hungry? You're in the right place..." );
$hero_image      = get_option( 'dish_dash_hero_image', '' );
$address         = get_option( 'dish_dash_address', '' );
$phone           = get_option( 'dish_dash_phone', '' );
$email_contact   = get_option( 'dish_dash_contact_email', get_option( 'admin_email' ) );
$opening_hours   = get_option( 'dish_dash_opening_hours', '' );
$facebook        = get_option( 'dish_dash_facebook', '' );
$instagram       = get_option( 'dish_dash_instagram', '' );
$whatsapp        = get_option( 'dish_dash_whatsapp', '' );

// ── Fallback nav pages ────────────────────────
$nav_pages = [ dd_menu_url() => __( 'Menu', 'dish-dash' ), dd_track_url() => __( 'Track Order', 'dish-dash' ) ];
if ( dd_is_enabled( 'reservations' ) ) {
    $reserve_page = get_option( 'dish_dash_reserve_page_id' );
    if ( $reserve_page ) $nav_pages[ get_permalink( $reserve_page ) ] = __( 'Reserve', 'dish-dash' );
}

// ── Tell template module NOT to inject cart
// ── because this template handles it directly
define( 'DD_FULLPAGE_TEMPLATE', true );

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php wp_title( '|', true, 'right' ); ?><?php bloginfo( 'name' ); ?></title>
    <?php wp_head(); ?>
    <style>
        :root {
            --dd-primary: <?php echo esc_attr( $primary_color ); ?>;
            --dd-dark:    <?php echo esc_attr( $dark_color ); ?>;
        }
        body.dd-fullpage { margin:0; padding:0; background:#f8f5f0; }
        /* Hide theme header/footer — Dish Dash owns the layout */
        body.dd-fullpage .site-header,
        body.dd-fullpage .site-footer,
        body.dd-fullpage #masthead,
        body.dd-fullpage #colophon,
        body.dd-fullpage .ast-above-header,
        body.dd-fullpage .ast-below-header,
        body.dd-fullpage .ast-primary-header-bar { display:none !important; }
    </style>
</head>
<body <?php body_class( 'dd-fullpage' ); ?>>
<?php wp_body_open(); ?>

<!-- ═══════ HEADER ══════════════════════════ -->
<header class="dd-header">
    <div class="dd-header__inner">

        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="dd-header__logo">
            <?php if ( $restaurant_logo ) : ?>
                <img src="<?php echo esc_url( $restaurant_logo ); ?>"
                     alt="<?php echo esc_attr( $restaurant_name ); ?>"
                     class="dd-header__logo-img">
            <?php else : ?>
                <span class="dd-header__logo-text"><?php echo esc_html( $restaurant_name ); ?></span>
            <?php endif; ?>
        </a>

        <?php if ( has_nav_menu( 'dd-primary' ) ) : ?>
        <nav class="dd-header__nav" aria-label="Main navigation">
            <?php wp_nav_menu( [
                'theme_location' => 'dd-primary',
                'menu_class'     => 'dd-nav-list',
                'container'      => false,
                'depth'          => 1,
                'fallback_cb'    => false,
                'items_wrap'     => '%3$s',
            ] ); ?>
        </nav>
        <?php else : ?>
        <nav class="dd-header__nav" aria-label="Main navigation">
            <?php foreach ( $nav_pages as $url => $label ) : ?>
            <a href="<?php echo esc_url( $url ); ?>" class="dd-header__nav-link">
                <?php echo esc_html( $label ); ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>

        <div class="dd-header__actions">
            <?php if ( is_user_logged_in() ) : ?>
            <a href="<?php echo esc_url( get_permalink( get_option( 'dish_dash_account_page_id' ) ) ); ?>"
               class="dd-header__account" aria-label="My Account">👤</a>
            <?php else : ?>
            <a href="<?php echo esc_url( wp_login_url() ); ?>"
               class="dd-header__account" aria-label="Login">👤</a>
            <?php endif; ?>

            <button class="dd-cart-trigger dd-header__cart" aria-label="Cart">
                🛒 <span class="dd-header__cart-label"><?php esc_html_e( 'Cart', 'dish-dash' ); ?></span>
                <span class="dd-cart-count" style="display:none">0</span>
            </button>
        </div>

        <button class="dd-header__mobile-toggle" aria-label="Menu"
                onclick="this.closest('.dd-header').classList.toggle('dd-header--open')">
            <span></span><span></span><span></span>
        </button>
    </div>

    <div class="dd-header__mobile-nav">
        <?php if ( has_nav_menu( 'dd-primary' ) ) : ?>
            <?php wp_nav_menu( [
                'theme_location' => 'dd-primary',
                'menu_class'     => 'dd-mobile-nav-list',
                'container'      => false,
                'depth'          => 1,
                'fallback_cb'    => false,
                'items_wrap'     => '%3$s',
            ] ); ?>
        <?php else : ?>
            <?php foreach ( $nav_pages as $url => $label ) : ?>
            <a href="<?php echo esc_url( $url ); ?>" class="dd-header__mobile-link">
                <?php echo esc_html( $label ); ?>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</header>

<!-- ═══════ HERO ════════════════════════════ -->
<section class="dd-hero">
    <div class="dd-hero__inner">
        <div class="dd-hero__content">
            <h1 class="dd-hero__title"><?php echo esc_html( $hero_title ); ?></h1>
            <p class="dd-hero__subtitle"><?php echo esc_html( $hero_subtitle ); ?></p>
            <div class="dd-hero__search">
                <input type="search"
                       class="dd-hero__search-input"
                       id="dd-search-input"
                       placeholder="<?php esc_attr_e( 'Search your favourite food…', 'dish-dash' ); ?>"
                       autocomplete="off" />
                <button class="dd-hero__search-btn" aria-label="Search">🔍</button>
            </div>
        </div>
        <?php if ( $hero_image ) : ?>
        <div class="dd-hero__image">
            <img src="<?php echo esc_url( $hero_image ); ?>"
                 alt="<?php esc_attr_e( 'Food banner', 'dish-dash' ); ?>">
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ═══════ CART SIDEBAR (once, here only) ══ -->
<div class="dd-cart-overlay"></div>
<aside class="dd-cart-sidebar" aria-label="<?php esc_attr_e( 'Shopping cart', 'dish-dash' ); ?>">
    <div class="dd-cart-sidebar__header">
        <h3>🛒 <?php esc_html_e( 'Your Cart', 'dish-dash' ); ?></h3>
        <button class="dd-cart-close" aria-label="Close">✕</button>
    </div>
    <div class="dd-cart-items">
        <p class="dd-cart-empty"><?php esc_html_e( 'Your cart is empty.', 'dish-dash' ); ?></p>
    </div>
    <div class="dd-cart-summary" style="display:none">
        <div class="dd-cart-summary__row">
            <span><?php esc_html_e( 'Subtotal', 'dish-dash' ); ?></span>
            <span class="dd-cart-subtotal">—</span>
        </div>
        <div class="dd-cart-summary__row dd-cart-summary__row--total">
            <span><?php esc_html_e( 'Total', 'dish-dash' ); ?></span>
            <span class="dd-cart-total">—</span>
        </div>
        <button class="dd-cart-checkout-btn">
            <?php esc_html_e( 'Proceed to Checkout', 'dish-dash' ); ?> →
        </button>
    </div>
</aside>

<!-- ═══════ MAIN CONTENT ════════════════════ -->
<main class="dd-main">
    <div class="dd-main__inner">
        <?php the_content(); ?>
    </div>
</main>

<!-- ═══════ FOOTER ══════════════════════════ -->
<footer class="dd-footer">
    <div class="dd-footer__inner">

        <div class="dd-footer__col">
            <h4><?php esc_html_e( 'Contact Us', 'dish-dash' ); ?></h4>
            <?php if ( $address )       : ?><p>📍 <?php echo esc_html( $address ); ?></p><?php endif; ?>
            <?php if ( $phone )         : ?><p>📞 <a href="tel:<?php echo esc_attr( $phone ); ?>"><?php echo esc_html( $phone ); ?></a></p><?php endif; ?>
            <?php if ( $email_contact ) : ?><p>✉️ <a href="mailto:<?php echo esc_attr( $email_contact ); ?>"><?php echo esc_html( $email_contact ); ?></a></p><?php endif; ?>
            <?php if ( $opening_hours ) : ?><p>🕐 <?php echo esc_html( $opening_hours ); ?></p><?php endif; ?>
        </div>

        <div class="dd-footer__col">
            <h4><?php esc_html_e( 'Quick Links', 'dish-dash' ); ?></h4>
            <?php if ( has_nav_menu( 'dd-footer' ) ) : ?>
                <?php wp_nav_menu( [ 'theme_location' => 'dd-footer', 'container' => false, 'depth' => 1, 'fallback_cb' => false, 'items_wrap' => '<ul>%3$s</ul>' ] ); ?>
            <?php else : ?>
            <ul>
                <li><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'dish-dash' ); ?></a></li>
                <li><a href="<?php echo esc_url( dd_menu_url() ); ?>"><?php esc_html_e( 'Our Menu', 'dish-dash' ); ?></a></li>
                <li><a href="<?php echo esc_url( dd_track_url() ); ?>"><?php esc_html_e( 'Track Order', 'dish-dash' ); ?></a></li>
            </ul>
            <?php endif; ?>
        </div>

        <div class="dd-footer__col">
            <h4><?php esc_html_e( 'Follow Us', 'dish-dash' ); ?></h4>
            <div class="dd-footer__social">
                <?php if ( $facebook )  : ?><a href="<?php echo esc_url( $facebook ); ?>" target="_blank" class="dd-social-btn dd-social-btn--fb">f</a><?php endif; ?>
                <?php if ( $instagram ) : ?><a href="<?php echo esc_url( $instagram ); ?>" target="_blank" class="dd-social-btn dd-social-btn--ig">📷</a><?php endif; ?>
                <?php if ( $whatsapp )  : ?><a href="https://wa.me/<?php echo esc_attr( $whatsapp ); ?>" target="_blank" class="dd-social-btn dd-social-btn--wa">💬</a><?php endif; ?>
            </div>
        </div>

        <div class="dd-footer__col">
            <div class="dd-footer__logo"><?php echo esc_html( $restaurant_name ); ?></div>
            <p class="dd-footer__tagline"><?php esc_html_e( 'Powered by Dish Dash', 'dish-dash' ); ?></p>
        </div>

    </div>
    <div class="dd-footer__bottom">
        <p>© <?php echo date( 'Y' ); ?> <?php echo esc_html( $restaurant_name ); ?>. <?php esc_html_e( 'All rights reserved.', 'dish-dash' ); ?></p>
    </div>
</footer>

<?php wp_footer(); ?>

<script>
(function(){
    // Sticky header scroll effect
    var header = document.querySelector('.dd-header');
    if (!header) return;
    window.addEventListener('scroll', function(){
        header.classList.toggle('dd-header--scrolled', window.scrollY > 20);
    }, { passive: true });
})();
</script>
</body>
</html>
