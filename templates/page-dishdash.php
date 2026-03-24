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

// ─── Settings ──────────────────────────────────────────────────────────────
$dd_name    = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );
$dd_logo    = get_option( 'dish_dash_logo_url', '' );
$dd_primary = get_option( 'dish_dash_primary_color', '#6B1D1D' );
$dd_dark    = get_option( 'dish_dash_dark_color', '#160F0D' );
$dd_h_title = get_option( 'dish_dash_hero_title', 'Elegant food ordering for <span class="dd-gold">Kigali\'s Indian favorite</span>.' );
$dd_h_sub   = get_option( 'dish_dash_hero_subtitle', 'Discover signature curries, fragrant biryanis, fresh breads, and a seamless online ordering experience.' );
$dd_h_img   = get_option( 'dish_dash_hero_image', '' );
$dd_addr    = get_option( 'dish_dash_address', 'Kigali, Rwanda' );
$dd_phone   = get_option( 'dish_dash_phone', '' );
$dd_email   = get_option( 'dish_dash_contact_email', '' );
$dd_hours   = get_option( 'dish_dash_opening_hours', "Mon - Fri: 10:00 - 22:00\nSat - Sun: 09:00 - 23:00" );
$dd_fb      = get_option( 'dish_dash_facebook', '' );
$dd_ig      = get_option( 'dish_dash_instagram', '' );
$dd_wa      = get_option( 'dish_dash_whatsapp', '' );

// ─── Categories ────────────────────────────────────────────────────────────
$raw_cats = get_terms( array(
    'taxonomy'   => 'product_cat',
    'hide_empty' => true,
    'parent'     => 0,
    'orderby'    => 'menu_order',
) );

$dd_cats = array();
if ( ! is_wp_error( $raw_cats ) && ! empty( $raw_cats ) ) {
    foreach ( $raw_cats as $cat ) {
        if ( $cat->slug !== 'uncategorized' ) {
            $dd_cats[] = $cat;
        }
    }
}

// ─── Best sellers ──────────────────────────────────────────────────────────
$dd_best = wc_get_products( array(
    'limit'   => 8,
    'orderby' => 'popularity',
    'order'   => 'DESC',
    'status'  => 'publish',
) );
if ( ! $dd_best ) $dd_best = array();

// ─── Products per category ─────────────────────────────────────────────────
$dd_cat_products = array();
foreach ( $dd_cats as $cat ) {
    $prods = wc_get_products( array(
        'category' => array( $cat->slug ),
        'limit'    => 8,
        'status'   => 'publish',
    ) );
    $dd_cat_products[ $cat->slug ] = $prods ? $prods : array();
}

// ─── Misc ──────────────────────────────────────────────────────────────────
$dd_hours_lines = array_filter( array_map( 'trim', explode( "\n", $dd_hours ) ) );
$dd_cart_count  = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
$dd_initials    = strtoupper( substr( $dd_name, 0, 2 ) );

// ─── Dish card helper — function_exists prevents fatal redeclaration ────────
if ( ! function_exists( 'dd_render_dish_card' ) ) {
    function dd_render_dish_card( $product, $tag = '' ) {
        $img_id  = $product->get_image_id();
        $img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'medium_large' ) : '';
        if ( ! $img_url ) $img_url = wc_placeholder_img_src( 'medium_large' );

        if ( ! $tag ) $tag = $product->is_featured() ? 'Best Seller' : 'Popular';

        $raw_price = (float) $product->get_price();
        $price     = $raw_price ? 'RWF ' . number_format( $raw_price, 0, '.', ',' ) : '';

        $short = $product->get_short_description();
        $long  = $product->get_description();
        $desc  = wp_trim_words( strip_tags( $short ? $short : $long ), 14, '...' );

        $id    = $product->get_id();
        $nonce = wp_create_nonce( 'dd_add_to_cart' );

        ob_start(); ?>
        <article class="dd-dish-card" data-id="<?php echo esc_attr( $id ); ?>">
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
            $nav_ok = wp_nav_menu( array(
                'theme_location' => 'dd-primary',
                'container'      => false,
                'items_wrap'     => '%3$s',
                'fallback_cb'    => false,
                'echo'           => true,
            ) );
            if ( ! $nav_ok ) {
                echo '<a href="#home">Home</a>';
                echo '<a href="#menu">Menu</a>';
                echo '<a href="#reserve">Reserve</a>';
                echo '<a href="#reviews">Reviews</a>';
            }
            ?>
        </nav>

        <div class="dd-header__actions">
            <a href="<?php echo esc_url( wc_get_account_url( 'orders' ) ); ?>"
               class="dd-btn dd-btn--light dd-btn--sm">Track Order</a>
            <button class="dd-cart-top" id="ddCartTopBtn" aria-label="Open cart">
                <span class="dd-cart-top__label">Cart</span>
                <span class="dd-cart-badge" id="ddCartCount"><?php echo esc_html( $dd_cart_count ); ?></span>
            </button>
        </div>

    </div>
</header>

<!-- ══ HERO ════════════════════════════════════════════════════════════════ -->
<section class="dd-hero">
    <div class="dd-container dd-hero__grid">

        <div class="dd-hero__content">
            <span class="dd-pill">Authentic Indian Dining</span>
            <h1 class="dd-hero__title dd-serif"><?php echo wp_kses_post( $dd_h_title ); ?></h1>
            <p class="dd-hero__copy"><?php echo esc_html( $dd_h_sub ); ?></p>
            <div class="dd-hero__actions">
                <a href="#menu"    class="dd-btn dd-btn--brand">Order Now</a>
                <a href="#reserve" class="dd-btn dd-btn--outline">Reserve Table</a>
                <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
                   class="dd-btn dd-btn--soft">View Full Menu</a>
            </div>
            <div class="dd-hero__chips">
                <div class="dd-hero__chip">Authentic Indian Flavors</div>
                <div class="dd-hero__chip">Delivery &amp; Pickup Available</div>
                <div class="dd-hero__chip">Elegant Dine-In Experience</div>
                <div class="dd-hero__chip">Freshly Prepared Daily</div>
            </div>
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
                    : wc_placeholder_img_src( 'large' );
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
            <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
               class="dd-btn dd-btn--light" target="_blank">View Full Menu</a>
            <a href="#menu" class="dd-btn dd-btn--gold" id="ddStartOrder">Start Order</a>
        </div>
    </div>
</section>

<!-- ══ CATEGORIES ══════════════════════════════════════════════════════════ -->
<?php if ( ! empty( $dd_cats ) ) : ?>
<section class="dd-section" id="categories">
    <div class="dd-container">
        <div class="dd-section__top">
            <div>
                <div class="dd-section__label">Browse by category</div>
                <h2 class="dd-section__title dd-serif">Choose your craving</h2>
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
            ?>
                <button class="dd-cat-card<?php echo $i === 0 ? ' active' : ''; ?>"
                        data-slug="<?php echo esc_attr( $cat->slug ); ?>"
                        data-name="<?php echo esc_attr( $cat->name ); ?>">
                    <img src="<?php echo esc_url( $thumb_url ); ?>"
                         alt="<?php echo esc_attr( $cat->name ); ?>">
                    <div class="dd-cat-card__meta">
                        <span><?php echo esc_html( $cat->name ); ?></span>
                        <span class="dd-cat-card__count"><?php echo esc_html( $cat->count ); ?>+</span>
                    </div>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══ MENU ════════════════════════════════════════════════════════════════ -->
<section class="dd-section" id="menu">
    <div class="dd-container">

        <div class="dd-chips">
            <button class="dd-chip active" data-filter="">All</button>
            <button class="dd-chip" data-filter="featured">Featured</button>
            <button class="dd-chip" data-filter="veg">Veg</button>
            <button class="dd-chip" data-filter="popular">Popular</button>
        </div>

        <div class="dd-section__top">
            <div>
                <div class="dd-section__label">Featured dishes</div>
                <h2 class="dd-section__title dd-serif">Best sellers today</h2>
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

        <div class="dd-section__top" style="margin-top:56px;">
            <div>
                <div class="dd-section__label">Selected category</div>
                <h2 class="dd-section__title dd-serif" id="ddCatTitle">
                    <?php echo ! empty( $dd_cats ) ? esc_html( $dd_cats[0]->name ) : 'Menu'; ?>
                </h2>
            </div>
            <div class="dd-arrows">
                <button class="dd-arrow-btn" id="ddSelPrev">&#8592;</button>
                <button class="dd-arrow-btn" id="ddSelNext">&#8594;</button>
            </div>
        </div>

        <div class="dd-menu-layout">
            <div class="dd-cat-rows-wrap">
                <?php foreach ( $dd_cats as $i => $cat ) : ?>
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
                <?php if ( empty( $dd_cats ) ) : ?>
                    <p class="dd-empty">Add product categories in WooCommerce to show dishes here.</p>
                <?php endif; ?>
            </div>

            <aside class="dd-summary">
                <div class="dd-summary__label">Your order</div>
                <div class="dd-summary__list" id="ddSummaryList">
                    <div class="dd-summary__empty">Your cart is empty</div>
                </div>
                <div class="dd-summary__totals">
                    <div class="dd-summary__row"><span>Subtotal</span><span id="ddSumSubtotal">RWF 0</span></div>
                    <div class="dd-summary__row"><span>Delivery</span><span id="ddSumDelivery">RWF 2,000</span></div>
                    <div class="dd-summary__row dd-summary__row--main"><span>Total</span><span id="ddSumTotal">RWF 0</span></div>
                </div>
                <a href="<?php echo esc_url( wc_get_checkout_url() ); ?>"
                   class="dd-btn dd-btn--gold dd-btn--block" style="margin-top:20px;">Proceed to checkout</a>
            </aside>
        </div>

    </div>
</section>

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
                <input type="date"   class="dd-field" placeholder="Date">
                <input type="time"   class="dd-field" placeholder="Time">
                <input type="number" class="dd-field" placeholder="Guests" min="1" max="20">
                <input type="tel"    class="dd-field" placeholder="Phone Number">
                <textarea class="dd-field dd-field--full" rows="3"
                          placeholder="Special requests..."></textarea>
            </div>
            <button class="dd-btn dd-btn--brand dd-btn--block" style="margin-top:20px;">Reserve now</button>
        </div>
    </div>
</section>

<!-- ══ REVIEWS ═════════════════════════════════════════════════════════════ -->
<section class="dd-section" id="reviews">
    <div class="dd-container">
        <div class="dd-section__top" style="margin-bottom:24px;">
            <div>
                <div class="dd-section__label">Loved by guests</div>
                <h2 class="dd-section__title dd-serif">What our customers say</h2>
            </div>
        </div>
        <div class="dd-reviews">
            <?php
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
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ══ FOOTER ══════════════════════════════════════════════════════════════ -->
<footer class="dd-footer">
    <div class="dd-container dd-footer__grid">
        <div>
            <div class="dd-brand dd-footer__brand">
                <?php if ( $dd_logo ) : ?>
                    <img src="<?php echo esc_url( $dd_logo ); ?>"
                         alt="<?php echo esc_attr( $dd_name ); ?>"
                         style="height:48px;object-fit:contain;">
                <?php else : ?>
                    <span class="dd-brand__badge"><?php echo esc_html( $dd_initials ); ?></span>
                    <span class="dd-footer__brand-name"><?php echo esc_html( $dd_name ); ?></span>
                <?php endif; ?>
            </div>
            <p class="dd-footer__copy">Premium Indian dining and a refined digital ordering experience designed for smooth discovery, fast checkout, and repeat cravings.</p>
            <?php if ( $dd_fb || $dd_ig || $dd_wa ) : ?>
                <div class="dd-footer__social">
                    <?php if ( $dd_fb ) echo '<a href="' . esc_url( $dd_fb ) . '" target="_blank" rel="noopener">Facebook</a>'; ?>
                    <?php if ( $dd_ig ) echo '<a href="' . esc_url( $dd_ig ) . '" target="_blank" rel="noopener">Instagram</a>'; ?>
                    <?php if ( $dd_wa ) echo '<a href="https://wa.me/' . esc_attr( preg_replace( '/\D/', '', $dd_wa ) ) . '" target="_blank" rel="noopener">WhatsApp</a>'; ?>
                </div>
            <?php endif; ?>
        </div>
        <div>
            <div class="dd-footer__heading">Explore</div>
            <ul class="dd-footer__list">
                <li><a href="#home">Home</a></li>
                <li><a href="#menu">Menu</a></li>
                <li><a href="#reserve">Reserve Table</a></li>
                <li><a href="<?php echo esc_url( wc_get_account_url( 'orders' ) ); ?>">Track Order</a></li>
            </ul>
        </div>
        <div>
            <div class="dd-footer__heading">Contact</div>
            <ul class="dd-footer__list">
                <?php if ( $dd_addr )  echo '<li>' . esc_html( $dd_addr ) . '</li>'; ?>
                <?php if ( $dd_phone ) echo '<li><a href="tel:' . esc_attr( preg_replace( '/\s/', '', $dd_phone ) ) . '">' . esc_html( $dd_phone ) . '</a></li>'; ?>
                <?php if ( $dd_email ) echo '<li><a href="mailto:' . esc_attr( $dd_email ) . '">' . esc_html( $dd_email ) . '</a></li>'; ?>
            </ul>
        </div>
        <div>
            <div class="dd-footer__heading">Opening Hours</div>
            <ul class="dd-footer__list">
                <?php foreach ( $dd_hours_lines as $line ) echo '<li>' . esc_html( $line ) . '</li>'; ?>
            </ul>
        </div>
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
        <a href="<?php echo esc_url( wc_get_checkout_url() ); ?>"
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
    checkoutUrl: '<?php echo esc_url( wc_get_checkout_url() ); ?>',
    deliveryFee: <?php echo (int) get_option( 'dish_dash_delivery_fee', 2000 ); ?>,
    cartCount:   <?php echo (int) $dd_cart_count; ?>,
    firstCat:    '<?php echo ! empty( $dd_cats ) ? esc_js( $dd_cats[0]->slug ) : ''; ?>'
};
</script>

<?php wp_footer(); ?>
</body>
</html>
