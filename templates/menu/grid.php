<?php
/**
 * Dish Dash – Menu Page Template
 *
 * Desktop: category circles carousel + AJAX product grid (v3.1.7)
 * Mobile:  existing list-row layout (unchanged)
 *
 * Variables from DD_Menu_Module::shortcode():
 *   $items        WP_Query
 *   $categories   WP_Term[]
 *   $atts         shortcode attributes
 *   $product_cats array[ product_id => WP_Term[] ]
 *
 * @package DishDash
 * @since   3.1.7
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$primary = get_option( 'dish_dash_primary_color', '#6B1D1D' );
$dark    = get_option( 'dish_dash_dark_color',    '#160F0D' );

$show_filter = $atts['show_filter'] !== 'no';
$show_search = $atts['show_search'] !== 'no';

$nonce = wp_create_nonce( 'dd_add_to_cart' );
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
                <button type="button" class="dd-menu-cat dd-menu-cat--all is-active" data-cat-slug="">
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
                    <button type="button" class="dd-menu-cat" data-cat-slug="<?php echo esc_attr( $cat->slug ); ?>">
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
            <div class="dd-menu-grid" id="ddMenuGrid" data-current-cat="">
                <?php
                $dd_initial_query = new WP_Query( [
                    'post_type'      => 'product',
                    'posts_per_page' => 8,
                    'post_status'    => 'publish',
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ] );
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

<!-- ═══ MOBILE LAYOUT (unchanged) ════════════════════════════════════ -->
<div class="dd-menu-page dd-menu-page--mobile">

<div class="dd-menu-page" style="--dd-primary:<?php echo esc_attr($primary); ?>;--dd-dark:<?php echo esc_attr($dark); ?>;">

    <?php if ( $show_search || $show_filter ) : ?>
    <!-- ── Controls ───────────────────────────────────────────── -->
    <div class="dd-menu-controls">

        <?php if ( $show_search ) : ?>
        <div class="dd-menu-search-wrap">
            <span class="dd-menu-search-icon">&#128269;</span>
            <input
                type="search"
                id="ddMenuSearch"
                class="dd-menu-search-input"
                placeholder="Search dishes..."
                autocomplete="off"
                aria-label="Search dishes">
            <button class="dd-menu-search-clear" id="ddMenuSearchClear" aria-label="Clear search" style="display:none;">&#10005;</button>
        </div>
        <?php endif; ?>

        <?php if ( $show_filter && ! empty( $categories ) ) : ?>
        <div class="dd-menu-filters" id="ddMenuFilters" role="tablist" aria-label="Filter by category">
            <button class="dd-menu-filter-btn active"
                    data-slug=""
                    data-term-id=""
                    role="tab"
                    aria-selected="true">
                All
            </button>
            <?php foreach ( $categories as $cat ) : ?>
            <button class="dd-menu-filter-btn"
                    data-slug="<?php echo esc_attr( $cat->slug ); ?>"
                    data-term-id="<?php echo esc_attr( $cat->term_id ); ?>"
                    role="tab"
                    aria-selected="false">
                <?php echo esc_html( $cat->name ); ?>
                <span class="dd-menu-filter-count"><?php echo (int) $cat->count; ?></span>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <!-- ── Count ──────────────────────────────────────────────── -->
    <div class="dd-menu-meta">
        <span id="ddMenuCount"><?php echo (int) $items->found_posts; ?></span> dishes
    </div>

    <!-- ── Product list ───────────────────────────────────────── -->
    <?php if ( $items->have_posts() ) : ?>
    <div class="dd-menu-list" id="ddMenuList">

        <?php while ( $items->have_posts() ) : $items->the_post();
            global $product;
            if ( ! $product ) $product = wc_get_product( get_the_ID() );
            if ( ! $product )  continue;

            $id        = $product->get_id();
            $name      = $product->get_name();
            $raw_price = (float) $product->get_price();
            $price     = $raw_price ? 'RWF ' . number_format( $raw_price, 0, '.', ',' ) : '';

            $short = $product->get_short_description();
            $long  = $product->get_description();
            $desc  = wp_trim_words( strip_tags( $short ?: $long ), 12, '...' );

            $img_id  = $product->get_image_id();
            $img_url = $img_id
                ? wp_get_attachment_image_url( $img_id, 'thumbnail' )
                : ( function_exists('wc_placeholder_img_src') ? wc_placeholder_img_src('thumbnail') : '' );

            $item_cats    = $product_cats[ $id ] ?? [];
            $cat_slugs    = implode( ',', array_column( $item_cats, 'slug' ) );
            $first_cat_id = ! empty( $item_cats ) ? $item_cats[0]->term_id : '';
        ?>

        <article class="dd-menu-item"
                 data-id="<?php echo esc_attr( $id ); ?>"
                 data-name="<?php echo esc_attr( strtolower( $name ) ); ?>"
                 data-slugs="<?php echo esc_attr( $cat_slugs ); ?>"
                 data-cat-id="<?php echo esc_attr( $first_cat_id ); ?>">

            <?php if ( $img_url ) : ?>
            <div class="dd-menu-item__img">
                <img src="<?php echo esc_url( $img_url ); ?>"
                     alt="<?php echo esc_attr( $name ); ?>"
                     loading="lazy"
                     width="80" height="80">
            </div>
            <?php endif; ?>

            <div class="dd-menu-item__body">
                <h3 class="dd-menu-item__name"><?php echo esc_html( $name ); ?></h3>
                <?php if ( $desc ) : ?>
                <p class="dd-menu-item__desc"><?php echo esc_html( $desc ); ?></p>
                <?php endif; ?>
                <div class="dd-menu-item__footer">
                    <span class="dd-menu-item__price"><?php echo esc_html( $price ); ?></span>
                    <button class="dd-btn dd-btn--brand dd-btn--sm dd-add-btn dd-menu-add-btn"
                            data-id="<?php echo esc_attr( $id ); ?>"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>"
                            aria-label="Add <?php echo esc_attr( $name ); ?> to cart">
                        + Add
                    </button>
                </div>
            </div>

        </article>

        <?php endwhile; wp_reset_postdata(); ?>

    </div>

    <div class="dd-menu-empty" id="ddMenuEmpty" style="display:none;">
        <span>&#128372;</span>
        <p>No dishes found.</p>
        <button class="dd-btn dd-btn--outline" id="ddMenuReset">Show all dishes</button>
    </div>

    <?php else : ?>
    <div class="dd-menu-empty">
        <span>&#128372;</span>
        <p>No dishes available yet.</p>
    </div>
    <?php endif; ?>

</div><!-- /.dd-menu-page (mobile inner) -->

</div><!-- /.dd-menu-page--mobile -->

<style>
.dd-menu-page {
    max-width: 800px;
    margin: 0 auto;
    padding: 0 0 40px;
    font-family: 'Inter', system-ui, sans-serif;
}

/* Controls sticky bar */
.dd-menu-controls {
    position: sticky;
    top: 0;
    z-index: 100;
    background: transparent;
    padding: 12px 0 8px;
    margin-bottom: 4px;
}

/* Search — transparent, no white box */
.dd-menu-search-wrap {
    position: relative;
    display: flex;
    align-items: center;
    background: transparent;
    border: 1.5px solid rgba(107, 29, 29, 0.18);
    border-radius: 999px;
    padding: 0 16px;
    height: 50px;
    margin-bottom: 12px;
    transition: border-color .2s, box-shadow .2s;
}
.dd-menu-search-wrap:focus-within {
    border-color: var(--dd-primary, #6B1D1D);
    box-shadow: 0 0 0 3px rgba(107,29,29,.07);
}
.dd-menu-search-icon {
    font-size: 16px;
    margin-right: 8px;
    opacity: .4;
    flex-shrink: 0;
}
.dd-menu-search-input {
    flex: 1;
    border: none;
    outline: none;
    background: transparent;
    padding: 0;
    font-size: 15px;
    color: #221B19;
    min-width: 0;
    -webkit-appearance: none;
    appearance: none;
}
.dd-menu-search-input::placeholder { color: #aaa; }
.dd-menu-search-input::-webkit-search-cancel-button { display: none; }
.dd-menu-search-clear {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 13px;
    color: #aaa;
    padding: 4px;
    flex-shrink: 0;
}

/* Filter pills */
.dd-menu-filters {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 4px;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
}
.dd-menu-filters::-webkit-scrollbar { display: none; }

.dd-menu-filter-btn {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 16px;
    border-radius: 999px;
    border: 1.5px solid rgba(107,29,29,0.12);
    background: transparent;
    font-size: 13px;
    font-weight: 600;
    color: #4a3728;
    cursor: pointer;
    white-space: nowrap;
    transition: all .18s;
    font-family: 'Inter', system-ui, sans-serif;
}
.dd-menu-filter-btn:hover {
    border-color: var(--dd-primary, #6B1D1D);
    color: var(--dd-primary, #6B1D1D);
}
.dd-menu-filter-btn.active {
    background: transparent;
    border-color: var(--dd-primary, #6B1D1D);
    color: var(--dd-primary, #6B1D1D);
    font-weight: 700;
}
.dd-menu-filter-count {
    font-size: 11px;
    opacity: .65;
}

/* Meta */
.dd-menu-meta {
    font-size: 12px;
    color: #999;
    padding: 4px 0 12px;
    font-weight: 500;
}

/* List container */
.dd-menu-list {
    display: flex;
    flex-direction: column;
    gap: 1px;
    background: rgba(107,29,29,0.08);
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid rgba(107,29,29,0.08);
}

/* Item row */
.dd-menu-item {
    display: flex;
    align-items: center;
    gap: 14px;
    background: #fff;
    padding: 14px 16px;
    transition: background .15s;
}
.dd-menu-item:hover { background: #fdfaf7; }
.dd-menu-item[hidden] { display: none !important; }

/* Image */
.dd-menu-item__img {
    flex-shrink: 0;
    width: 72px;
    height: 72px;
    border-radius: 12px;
    overflow: hidden;
    background: #f5efe6;
}
.dd-menu-item__img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

/* Body */
.dd-menu-item__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.dd-menu-item__name {
    font-size: 15px;
    font-weight: 700;
    color: #221B19;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dd-menu-item__desc {
    font-size: 12px;
    color: #999;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dd-menu-item__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 6px;
    gap: 8px;
}
.dd-menu-item__price {
    font-size: 14px;
    font-weight: 800;
    color: var(--dd-primary, #6B1D1D);
}
.dd-menu-add-btn {
    flex-shrink: 0;
    padding: 7px 16px !important;
    font-size: 13px !important;
    border-radius: 999px !important;
}

/* Empty state */
.dd-menu-empty {
    text-align: center;
    padding: 48px 20px;
    color: #999;
}
.dd-menu-empty span { font-size: 48px; display: block; margin-bottom: 12px; }
.dd-menu-empty p { font-size: 15px; margin: 0 0 16px; }

@media (max-width: 480px) {
    .dd-menu-item__img { width: 60px; height: 60px; }
    .dd-menu-item__name { font-size: 14px; }
    .dd-menu-item { padding: 12px; gap: 10px; }
}
</style>

<script>
(function() {
    var list    = document.getElementById('ddMenuList');
    var filters = document.getElementById('ddMenuFilters');
    var search  = document.getElementById('ddMenuSearch');
    var clearBtn= document.getElementById('ddMenuSearchClear');
    var countEl = document.getElementById('ddMenuCount');
    var emptyEl = document.getElementById('ddMenuEmpty');
    var resetBtn= document.getElementById('ddMenuReset');

    if (!list) return;

    var allItems     = Array.from(list.querySelectorAll('.dd-menu-item'));
    var activeSlug   = '';
    var activeSearch = '';

    function updateCount() {
        var visible = allItems.filter(function(i) { return !i.hidden; }).length;
        if (countEl) countEl.textContent = visible;
        if (emptyEl) emptyEl.style.display = visible === 0 ? '' : 'none';
    }

    function applyFilters() {
        allItems.forEach(function(item) {
            var slugs = (item.dataset.slugs || '').split(',');
            var name  = item.dataset.name || '';
            var catOk = !activeSlug || slugs.indexOf(activeSlug) !== -1;
            var srcOk = !activeSearch || name.indexOf(activeSearch) !== -1;
            item.hidden = !(catOk && srcOk);
        });
        updateCount();
    }

    if (filters) {
        filters.addEventListener('click', function(e) {
            var btn = e.target.closest('.dd-menu-filter-btn');
            if (!btn) return;
            Array.from(filters.querySelectorAll('.dd-menu-filter-btn')).forEach(function(b) {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            btn.classList.add('active');
            btn.setAttribute('aria-selected', 'true');
            activeSlug = btn.dataset.slug || '';
            applyFilters();
        });
    }

    if (search) {
        search.addEventListener('input', function() {
            activeSearch = this.value.trim().toLowerCase();
            if (clearBtn) clearBtn.style.display = activeSearch ? '' : 'none';
            applyFilters();
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (search) { search.value = ''; search.focus(); }
            activeSearch = '';
            this.style.display = 'none';
            applyFilters();
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            activeSlug = activeSearch = '';
            if (search) search.value = '';
            if (clearBtn) clearBtn.style.display = 'none';
            if (filters) {
                Array.from(filters.querySelectorAll('.dd-menu-filter-btn')).forEach(function(b, i) {
                    b.classList.toggle('active', i === 0);
                    b.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
                });
            }
            applyFilters();
        });
    }

    updateCount();
})();
</script>
