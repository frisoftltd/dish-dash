<?php
/**
 * File:    theme/dish-dash-theme/page.php
 * Purpose: WordPress page template for all non-DishDash pages — renders
 *          the_content() inside .entry-content. Dish Dash shortcode pages
 *          (menu, cart, checkout, track) use this template to output their
 *          [shortcode] content via the_content().
 *
 * Dependencies (this file needs):
 *   - get_header() → theme/dish-dash-theme/header.php
 *   - get_footer() → theme/dish-dash-theme/footer.php
 *   - WordPress: have_posts(), the_post(), the_content()
 *
 * Dependents (files that need this):
 *   - WordPress template hierarchy (loaded for page post type)
 *   - All shortcode pages: /restaurant-menu/, /cart-dd/, /checkout-dd/,
 *     /track-order/, /reserve-table/ (each has a [shortcode] in content)
 *
 * Last modified: v3.1.13
 */

get_header();
?>

<main id="main">
    <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <div class="entry-content">
                <?php the_content(); ?>
            </div>
        </article>
    <?php endwhile; endif; ?>
</main>

<?php get_footer(); ?>
