<?php
/**
 * File:    templates/menu/grid.php
 * Purpose: Menu display template — desktop view shows a category carousel
 *          + AJAX-loaded product grid (v3.1.7+); mobile view shows a
 *          searchable, filterable product list. Rendered by [dish_dash_menu]
 *          shortcode.
 *          Theme override: /your-theme/dish-dash/menu/grid.php
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core guard)
 *   - WooCommerce: $items (WP_Query), wc_get_product(), WC_Product
 *   - assets/css/menu-page.css (desktop layout)
 *   - assets/css/menu.css      (product card styles)
 *   - assets/js/frontend.js    (category click → AJAX, product modal)
 *   - DD_Settings via window.dishDash (ajaxUrl, nonce, primary color)
 *
 * Dependents (files that need this):
 *   - modules/menu/class-dd-menu-module.php (shortcode renders this)
 *
 * Variables expected in scope:
 *   $items (WP_Query), $categories (WP_Term[]),
 *   $atts (shortcode attributes), $product_cats (product_id => WP_Term[])
 *
 * AJAX action consumed: dd_menu_load_products
 *
 * Key CSS classes:
 *   .dd-menu-page--desktop, .dd-menu-page--mobile,
 *   .dd-menu-container, .dd-menu-cats, .dd-menu-cat.is-active,
 *   .dd-menu-grid-section, .dd-menu-grid
 *
 * Last modified: v3.1.18
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$primary = get_option( 'dish_dash_primary_color', '#6B1D1D' );
$dark    = get_option( 'dish_dash_dark_color',    '#160F0D' );

$show_filter = $atts['show_filter'] !== 'no';
$show_search = $atts['show_search'] !== 'no';

$nonce = wp_create_nonce( 'dd_add_to_cart' );

// ─── Deep-link category filter from ?cat= URL param ───────────────────────
$dd_deeplink_slug = isset( $_GET['cat'] ) ? sanitize_title( wp_unslash( $_GET['cat'] ) ) : '';
$dd_deeplink_term = $dd_deeplink_slug
    ? get_term_by( 'slug', $dd_deeplink_slug, 'product_cat' )
    : false;
if ( ! $dd_deeplink_term || is_wp_error( $dd_deeplink_term ) ) {
    $dd_deeplink_slug = '';
    $dd_deeplink_term = false;
}
$dd_grid_title = $dd_deeplink_term ? esc_html( $dd_deeplink_term->name ) : 'All Dishes';
?>

<!-- ═══ DESKTOP LAYOUT (v3.1.7) ══════════════════════════════════════ -->
<div class="dd-menu-page dd-menu-page--desktop">
    <div class="dd-menu-container">

        <!-- Category circles carousel -->
        <section class="dd-menu-cats">
            <div class="dd-menu-cats__inner">
            <header class="dd-menu-cats__header">
                <div>
                    <div class="dd-menu-cats__eyebrow">Browse by category</div>
                    <h2 class="dd-menu-cats__title">Choose your craving</h2>
                </div>
                <div class="dd-menu-cats__arrows">
                    <button type="button" class="dd-menu-cats__arrow" id="ddMenuCatsPrev" aria-label="Previous categories">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                    </button>
                    <button type="button" class="dd-menu-cats__arrow" id="ddMenuCatsNext" aria-label="Next categories">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </button>
                </div>
            </header>

            <div class="dd-menu-cats__track" id="ddMenuCatsTrack">
                <!-- "All" pseudo-category -->
                <button type="button" class="dd-menu-cat dd-menu-cat--all<?php echo ! $dd_deeplink_slug ? ' is-active' : ''; ?>" data-cat-slug="">
                    <span class="dd-menu-cat__circle">
                        <span class="dd-menu-cat__all-label">All</span>
                    </span>
                    <span class="dd-menu-cat__name">All Dishes</span>
                </button>

                <?php
                $dd_menu_cats = get_terms( [
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => true,
                    'exclude'    => [ get_option( 'default_product_cat' ) ],
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                ] );
                if ( ! is_wp_error( $dd_menu_cats ) && ! empty( $dd_menu_cats ) ) :
                    foreach ( $dd_menu_cats as $cat ) :
                        $thumb_id  = get_term_meta( $cat->term_id, 'thumbnail_id', true );
                        $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';
                ?>
                    <button type="button" class="dd-menu-cat<?php echo $dd_deeplink_slug === $cat->slug ? ' is-active' : ''; ?>" data-cat-slug="<?php echo esc_attr( $cat->slug ); ?>">
                        <span class="dd-menu-cat__circle">
                            <?php if ( $thumb_url ) : ?>
                                <img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $cat->name ); ?>" loading="lazy">
                            <?php else : ?>
                                <span class="dd-menu-cat__initial"><?php echo esc_html( strtoupper( mb_substr( $cat->name, 0, 1 ) ) ); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="dd-menu-cat__name"><?php echo esc_html( $cat->name ); ?></span>
                    </button>
                <?php
                    endforeach;
                endif;
                ?>
            </div><!-- .dd-menu-cats__track -->
            </div><!-- .dd-menu-cats__inner -->
        </section>

        <!-- Products grid -->
        <section class="dd-menu-grid-section">
            <div class="dd-menu-grid-header">
                <span class="dd-menu-grid-eyebrow">Our Menu</span>
                <h2 class="dd-menu-grid-title" id="ddMenuGridTitle"><?php echo $dd_grid_title; ?></h2>
            </div>
            <div class="dd-menu-grid" id="ddMenuGrid" data-current-cat="<?php echo esc_attr( $dd_deeplink_slug ); ?>">
                <?php
                $dd_initial_args = [
                    'post_type'      => 'product',
                    'posts_per_page' => 8,
                    'post_status'    => 'publish',
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ];
                if ( $dd_deeplink_slug ) {
                    $dd_initial_args['tax_query'] = [ [
                        'taxonomy' => 'product_cat',
                        'field'    => 'slug',
                        'terms'    => $dd_deeplink_slug,
                    ] ];
                }
                $dd_initial_query = new WP_Query( $dd_initial_args );
                if ( $dd_initial_query->have_posts() ) {
                    while ( $dd_initial_query->have_posts() ) {
                        $dd_initial_query->the_post();
                        $product = wc_get_product( get_the_ID() );
                        if ( $product ) {
                            include DD_TEMPLATES_DIR . 'partials/product-card.php';
                        }
                    }
                    wp_reset_postdata();
                }
                $dd_initial_has_more = $dd_initial_query->max_num_pages > 1;
                ?>
            </div>

            <div class="dd-menu-loadmore-wrap"<?php echo $dd_initial_has_more ? '' : ' style="display:none;"'; ?>>
                <button type="button" class="dd-menu-loadmore" id="ddMenuLoadMore" data-page="1">
                    <span class="dd-menu-loadmore__text">Load more</span>
                    <span class="dd-menu-loadmore__spinner" aria-hidden="true"></span>
                </button>
            </div>
        </section>

    </div>
</div>

<!-- ═══ NEW MOBILE LAYOUT (3-screen app) ═══════════════════════════════ -->
<div class="dd-mobile-app" aria-hidden="true">

  <!-- SCREEN 1: Category List -->
  <div class="dd-mobile-screen dd-mobile-screen--categories is-active" role="main">

    <!-- Header -->
    <div class="dd-mobile-header">
      <span class="dd-mobile-header__title">Menu</span>
      <a href="/cart/" class="dd-mobile-header__cart-icon" aria-label="Cart">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        <span class="dd-mobile-cart-badge">0</span>
      </a>
    </div>

    <!-- Hero text -->
    <div class="dd-mobile-hero">
      <h1>Choose Your Food <span class="dd-mobile-hero__today">Today</span></h1>
    </div>

    <!-- Search bar -->
    <div class="dd-mobile-search">
      <input type="text" placeholder="Find your favourite food" class="dd-mobile-search__input" />
      <button class="dd-mobile-search__filter-btn" aria-label="Filter">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
      </button>
    </div>

    <!-- Category list -->
    <div class="dd-mobile-section-label">Food Category</div>
    <ul class="dd-mobile-category-list" id="dd-mobile-cat-list">
      <?php foreach ( $categories as $cat ) : 
        $thumb_id  = get_term_meta( $cat->term_id, 'thumbnail_id', true );
        $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';
      ?>
      <li class="dd-mobile-category-item" data-cat-id="<?php echo $cat->term_id; ?>" data-cat-slug="<?php echo $cat->slug; ?>">
        <div class="dd-mobile-category-item__image">
          <img src="<?php echo $thumb_url; ?>" alt="<?php echo $cat->name; ?>" loading="lazy" />
        </div>
        <div class="dd-mobile-category-item__info">
          <span class="dd-mobile-category-item__name"><?php echo $cat->name; ?></span>
          <span class="dd-mobile-category-item__count"><?php echo $cat->count; ?> Items</span>
        </div>
        <span class="dd-mobile-category-item__arrow">›</span>
      </li>
      <?php endforeach; ?>
    </ul>
  </div><!-- /screen--categories -->

  <!-- SCREEN 2: Product List -->
  <div class="dd-mobile-screen dd-mobile-screen--products" role="main" aria-hidden="true">
    <!-- Header -->
    <div class="dd-mobile-header">
      <button class="dd-mobile-header__back" id="dd-mobile-back-to-cats" aria-label="Back">‹</button>
      <span class="dd-mobile-header__title">Menu</span>
      <div class="dd-mobile-header__actions">
        <button class="dd-mobile-header__search-btn" aria-label="Search">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </button>
        <a href="/cart/" class="dd-mobile-header__cart-icon" aria-label="Cart">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
          <span class="dd-mobile-cart-badge">0</span>
        </a>
      </div>
    </div>

    <!-- Category pill tabs (horizontal scroll, no arrows) -->
    <div class="dd-mobile-cat-pills" id="dd-mobile-cat-pills">
      <?php foreach ( $categories as $i => $cat ) : ?>
        <button class="dd-mobile-cat-pill<?php echo $i === 0 ? ' is-active' : ''; ?>" data-cat-id="<?php echo $cat->term_id; ?>">
          <?php echo $cat->name; ?>
        </button>
      <?php endforeach; ?>
    </div>

    <!-- Product cards list -->
    <ul class="dd-mobile-product-list" id="dd-mobile-product-list">
      <!-- Rendered dynamically via JS from DD_API data -->
    </ul>
  </div><!-- /screen--products -->

  <!-- SCREEN 3: Single Product -->
  <div class="dd-mobile-screen dd-mobile-screen--single" role="main" aria-hidden="true">
    <!-- Hero image fills top ~45% of screen -->
    <div class="dd-mobile-single__hero">
      <img src="" alt="" id="dd-mobile-single-hero-img" />
      <button class="dd-mobile-single__back" id="dd-mobile-back-to-products" aria-label="Back">‹</button>
      <button class="dd-mobile-single__heart" id="dd-mobile-single-heart" aria-label="Favourite">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
      </button>
      <!-- Quantity selector overlaid bottom-right of image -->
      <div class="dd-mobile-single__qty">
        <button class="dd-mobile-single__qty-btn dd-mobile-single__qty-btn--minus" id="dd-mobile-qty-minus">−</button>
        <span class="dd-mobile-single__qty-count" id="dd-mobile-qty-count">1</span>
        <button class="dd-mobile-single__qty-btn dd-mobile-single__qty-btn--plus" id="dd-mobile-qty-plus">+</button>
      </div>
    </div>

    <!-- Content panel (scrollable) -->
    <div class="dd-mobile-single__content">
      <div class="dd-mobile-single__title-row">
        <div>
          <h2 class="dd-mobile-single__name" id="dd-mobile-single-name"></h2>
          <div class="dd-mobile-single__meta">
            <span class="dd-mobile-single__rating" id="dd-mobile-single-rating"></span>
            <span class="dd-mobile-single__prep-time" id="dd-mobile-single-prep"></span>
          </div>
        </div>
        <div class="dd-mobile-single__price" id="dd-mobile-single-price"></div>
      </div>

      <!-- WooCommerce Attributes (only renders if product has attributes) -->
      <div class="dd-mobile-single__attributes" id="dd-mobile-single-attrs"></div>

      <!-- Description -->
      <div class="dd-mobile-single__description-wrap">
        <h3 class="dd-mobile-single__section-label">Description</h3>
        <p class="dd-mobile-single__description" id="dd-mobile-single-desc"></p>
        <button class="dd-mobile-single__read-more" id="dd-mobile-read-more">Read more</button>
      </div>

      <!-- Add to Cart -->
      <button class="dd-mobile-single__add-to-cart" id="dd-mobile-add-to-cart">
        Add To Cart <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
      </button>
    </div>
  </div><!-- /screen--single -->

  <!-- Fixed Bottom Navigation (always visible on mobile) -->
  <nav class="dd-bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="/" class="dd-bottom-nav__item" data-page="home">
      <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      <span>Home</span>
    </a>
    <a href="/restaurant-menu/" class="dd-bottom-nav__item dd-bottom-nav__item--active" data-page="menu">
      <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      <span>Menu</span>
    </a>
    <a href="/cart/" class="dd-bottom-nav__item" data-page="cart">
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
      <span class="dd-bottom-nav__badge" id="dd-bottom-nav-cart-count">0</span>
      <span>My Cart</span>
    </a>
    <a href="/my-account/" class="dd-bottom-nav__item" data-page="profile">
      <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      <span>Profile</span>
    </a>
  </nav>

</div><!-- /dd-mobile-app -->

<?php
// ─── Pass data to JS ───────────────────────────────────────────────────────
$_dd_user_id        = get_current_user_id();
$_dd_is_logged_in   = is_user_logged_in();
$_dd_user_favorites = $_dd_is_logged_in
    ? (array) get_user_meta( $_dd_user_id, 'dd_favorites', true )
    : [];

wp_localize_script( 'dd-menu-page', 'DD_MOBILE_DATA', [
    'categories'     => DD_API::get_categories(),
    'products'       => DD_API::get_products( [ 'limit' => -1 ] ),
    'cart_url'       => wc_get_cart_url(),
    'account_url'    => get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ),
    'home_url'       => home_url( '/' ),
    'nonce'          => wp_create_nonce( 'dd_mobile_nonce' ),
    'ajax_url'       => admin_url( 'admin-ajax.php' ),
    'is_logged_in'   => $_dd_is_logged_in,
    'user_favorites' => array_values( array_filter( array_map( 'intval', $_dd_user_favorites ) ) ),
    'cart_count'     => function_exists( 'WC' ) ? (int) WC()->cart->get_cart_contents_count() : 0,
] );
