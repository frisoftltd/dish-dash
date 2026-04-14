<?php
/**
 * File:    theme/dish-dash-theme/singular.php
 * Purpose: Theme singular template — handles single posts, WooCommerce
 *          product pages, and other singular views not caught by page.php.
 *          Outputs the_content() inside a max-width centered wrapper.
 *
 * Dependencies (this file needs):
 *   - get_header() → theme/dish-dash-theme/header.php
 *   - get_footer() → theme/dish-dash-theme/footer.php
 *   - WordPress: have_posts(), the_post(), the_content()
 *
 * Dependents (files that need this):
 *   - WordPress template hierarchy (loaded for single posts, WC products)
 *
 * Last modified: v3.1.13
 */

get_header();
?>

<main id="main">
    <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
        <div class="entry-content" style="max-width:1200px;margin:40px auto;padding:0 20px;">
            <?php the_content(); ?>
        </div>
    <?php endwhile; endif; ?>
</main>

<?php get_footer(); ?>
