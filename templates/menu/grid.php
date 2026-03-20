<?php
/**
 * Template: Menu Grid (WooCommerce Products)
 * Override: /your-theme/dish-dash/menu/grid.php
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Hide search if we are on the full page template
// because the hero section already has a search bar
$is_full_page = is_page() && 'page-dishdash.php' === get_post_meta( get_the_ID(), '_wp_page_template', true );
$show_search  = ( 'yes' === $atts['show_search'] && ! $is_full_page ) ? 'yes' : 'no';
?>
<div class="dd-menu-wrap" data-columns="<?php echo esc_attr( $atts['columns'] ); ?>">

    <?php if ( 'yes' === $show_search ) : ?>
    <div class="dd-menu-search">
        <input type="search" class="dd-search-input" id="dd-search-input"
               placeholder="<?php esc_attr_e( 'Search your favourite food…', 'dish-dash' ); ?>"
               autocomplete="off" />
        <span class="dd-search-icon">🔍</span>
    </div>
    <?php endif; ?>

    <?php if ( 'yes' === $atts['show_filter'] && ! is_wp_error( $categories ) && ! empty( $categories ) ) : ?>
    <nav class="dd-filter-bar">
        <button class="dd-filter-btn dd-filter-btn--active" data-filter="all">
            <?php esc_html_e( 'All', 'dish-dash' ); ?>
        </button>
        <?php foreach ( $categories as $cat ) : ?>
        <button class="dd-filter-btn" data-filter="<?php echo esc_attr( $cat->slug ); ?>">
            <?php echo esc_html( $cat->name ); ?>
        </button>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <?php if ( $items->have_posts() ) : ?>
    <div class="dd-menu-grid" id="dd-menu-grid">

        <?php while ( $items->have_posts() ) : $items->the_post();
            $post_id    = get_the_ID();
            $product    = wc_get_product( $post_id );
            $price      = $product ? $product->get_price() : '';
            $reg_price  = $product ? $product->get_regular_price() : '';
            $sale_price = $product ? $product->get_sale_price() : '';
            $has_sale   = $product && $product->is_on_sale();
            $cats       = get_the_terms( $post_id, 'product_cat' );
            $cat_slugs  = $cats && ! is_wp_error( $cats ) ? implode( ' ', wp_list_pluck( $cats, 'slug' ) ) : '';
            $cat_names  = $cats && ! is_wp_error( $cats ) ? implode( ', ', wp_list_pluck( $cats, 'name' ) ) : '';
            $badge      = get_post_meta( $post_id, '_dd_badge', true );
        ?>
        <article class="dd-menu-card"
            data-category="<?php echo esc_attr( $cat_slugs ); ?>"
            data-title="<?php echo esc_attr( strtolower( get_the_title() ) ); ?>">

            <?php if ( has_post_thumbnail() ) : ?>
            <div class="dd-card-image">
                <?php the_post_thumbnail( 'medium', [ 'loading' => 'lazy' ] ); ?>
                <?php if ( $has_sale ) : ?>
                    <span class="dd-badge dd-badge--on-sale"><?php esc_html_e( 'On Sale', 'dish-dash' ); ?></span>
                <?php elseif ( $badge ) : ?>
                    <span class="dd-badge dd-badge--<?php echo esc_attr( $badge ); ?>">
                        <?php echo esc_html( ucfirst( $badge ) ); ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="dd-card-body">
                <?php if ( $cat_names ) : ?>
                <p class="dd-card-category"><?php echo esc_html( $cat_names ); ?></p>
                <?php endif; ?>

                <h3 class="dd-card-title"><?php the_title(); ?></h3>

                <div class="dd-card-excerpt"><?php the_excerpt(); ?></div>

                <div class="dd-card-footer">
                    <div class="dd-card-price">
                        <?php if ( $has_sale ) : ?>
                            <span class="dd-price--original"><?php echo esc_html( dd_price( (float) $reg_price ) ); ?></span>
                            <span class="dd-price--sale"><?php echo esc_html( dd_price( (float) $sale_price ) ); ?></span>
                        <?php elseif ( $price ) : ?>
                            <span class="dd-price"><?php echo esc_html( dd_price( (float) $price ) ); ?></span>
                        <?php endif; ?>
                    </div>

                    <button class="dd-add-to-cart-btn"
                        data-id="<?php echo esc_attr( $post_id ); ?>"
                        data-name="<?php echo esc_attr( get_the_title() ); ?>"
                        data-price="<?php echo esc_attr( $price ); ?>"
                        data-image="<?php echo esc_attr( get_the_post_thumbnail_url( $post_id, 'thumbnail' ) ); ?>">
                        <?php esc_html_e( 'Add to Cart', 'dish-dash' ); ?>
                    </button>
                </div>
            </div>
        </article>
        <?php endwhile; wp_reset_postdata(); ?>
    </div>

    <p class="dd-no-results" style="display:none">
        <?php esc_html_e( 'No items match your search.', 'dish-dash' ); ?>
    </p>

    <?php else : ?>
    <div class="dd-empty-menu">
        <span>🍽</span>
        <p><?php esc_html_e( 'No menu items found.', 'dish-dash' ); ?></p>
    </div>
    <?php endif; ?>
</div>
