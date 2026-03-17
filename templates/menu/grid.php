<?php
/**
 * Template: Menu Grid
 *
 * Variables available:
 *   $items      WP_Query  — menu item posts
 *   $categories array     — taxonomy terms for filter bar
 *   $atts       array     — shortcode attributes
 *
 * Override this template by copying it to:
 * /your-theme/dish-dash/menu/grid.php
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="dd-menu-wrap" data-columns="<?php echo esc_attr( $atts['columns'] ); ?>">

    <?php if ( 'yes' === $atts['show_search'] ) : ?>
    <div class="dd-menu-search">
        <input
            type="search"
            class="dd-search-input"
            placeholder="<?php esc_attr_e( 'Search your favourite food…', 'dish-dash' ); ?>"
            id="dd-search-input"
            autocomplete="off"
        />
        <span class="dd-search-icon">🔍</span>
    </div>
    <?php endif; ?>

    <?php if ( 'yes' === $atts['show_filter'] && ! is_wp_error( $categories ) && ! empty( $categories ) ) : ?>
    <nav class="dd-filter-bar" aria-label="<?php esc_attr_e( 'Filter by category', 'dish-dash' ); ?>">
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
            $price      = get_post_meta( $post_id, '_dd_price',      true );
            $sale_price = get_post_meta( $post_id, '_dd_sale_price', true );
            $badge      = get_post_meta( $post_id, '_dd_badge',      true );
            $prep_time  = get_post_meta( $post_id, '_dd_prep_time',  true );
            $allergens  = get_post_meta( $post_id, '_dd_allergens',  true );
            $calories   = get_post_meta( $post_id, '_dd_calories',   true );

            $cats      = get_the_terms( $post_id, 'dd_menu_category' );
            $cat_slugs = $cats && ! is_wp_error( $cats )
                ? implode( ' ', wp_list_pluck( $cats, 'slug' ) )
                : '';
            $cat_names = $cats && ! is_wp_error( $cats )
                ? implode( ', ', wp_list_pluck( $cats, 'name' ) )
                : '';

            $has_sale = $sale_price && (float) $sale_price < (float) $price;
            $display_price = $has_sale ? $sale_price : $price;
        ?>
        <article
            class="dd-menu-card"
            data-category="<?php echo esc_attr( $cat_slugs ); ?>"
            data-title="<?php echo esc_attr( strtolower( get_the_title() ) ); ?>"
        >
            <?php if ( has_post_thumbnail() ) : ?>
            <div class="dd-card-image">
                <?php the_post_thumbnail( 'medium', [ 'loading' => 'lazy', 'alt' => get_the_title() ] ); ?>

                <?php if ( $badge ) : ?>
                <span class="dd-badge dd-badge--<?php echo esc_attr( $badge ); ?>">
                    <?php
                    $badge_labels = [
                        'new'          => __( 'New',           'dish-dash' ),
                        'popular'      => __( 'Popular',       'dish-dash' ),
                        'spicy'        => __( 'Spicy 🌶',      'dish-dash' ),
                        'vegan'        => __( 'Vegan 🌱',      'dish-dash' ),
                        'gluten-free'  => __( 'Gluten-Free',   'dish-dash' ),
                        'on-sale'      => __( 'On Sale',       'dish-dash' ),
                        'chef-special' => __( "Chef's Special ⭐", 'dish-dash' ),
                    ];
                    echo esc_html( $badge_labels[ $badge ] ?? ucfirst( $badge ) );
                    ?>
                </span>
                <?php endif; ?>

                <?php if ( $has_sale ) : ?>
                <span class="dd-badge dd-badge--sale"><?php esc_html_e( 'On Sale', 'dish-dash' ); ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="dd-card-body">

                <?php if ( $cat_names ) : ?>
                <p class="dd-card-category"><?php echo esc_html( $cat_names ); ?></p>
                <?php endif; ?>

                <h3 class="dd-card-title"><?php the_title(); ?></h3>

                <div class="dd-card-excerpt">
                    <?php the_excerpt(); ?>
                </div>

                <div class="dd-card-meta">
                    <?php if ( $prep_time ) : ?>
                    <span class="dd-card-meta__item">⏱ <?php echo esc_html( $prep_time ); ?> min</span>
                    <?php endif; ?>
                    <?php if ( $calories ) : ?>
                    <span class="dd-card-meta__item">🔥 <?php echo esc_html( $calories ); ?> kcal</span>
                    <?php endif; ?>
                </div>

                <?php if ( $allergens ) : ?>
                <p class="dd-card-allergens">
                    <strong><?php esc_html_e( 'Allergens:', 'dish-dash' ); ?></strong>
                    <?php echo esc_html( $allergens ); ?>
                </p>
                <?php endif; ?>

                <div class="dd-card-footer">
                    <div class="dd-card-price">
                        <?php if ( $has_sale ) : ?>
                            <span class="dd-price--original"><?php echo esc_html( dd_price( (float) $price ) ); ?></span>
                            <span class="dd-price--sale"><?php echo esc_html( dd_price( (float) $sale_price ) ); ?></span>
                        <?php elseif ( $price ) : ?>
                            <span class="dd-price"><?php echo esc_html( dd_price( (float) $price ) ); ?></span>
                        <?php endif; ?>
                    </div>

                    <button
                        class="dd-add-to-cart-btn"
                        data-id="<?php echo esc_attr( $post_id ); ?>"
                        data-name="<?php echo esc_attr( get_the_title() ); ?>"
                        data-price="<?php echo esc_attr( $display_price ); ?>"
                        data-image="<?php echo esc_attr( get_the_post_thumbnail_url( $post_id, 'thumbnail' ) ); ?>"
                        aria-label="<?php echo esc_attr( sprintf( __( 'Add %s to cart', 'dish-dash' ), get_the_title() ) ); ?>"
                    >
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
        <p><?php esc_html_e( 'No menu items found. Add some from the Dish Dash admin panel.', 'dish-dash' ); ?></p>
    </div>
    <?php endif; ?>

</div>
