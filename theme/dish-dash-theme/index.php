<?php
/**
 * File:    theme/dish-dash-theme/index.php
 * Purpose: WordPress theme fallback template — outputs post loop wrapped in
 *          a max-width container. Used only for non-Dish-Dash pages on sites
 *          running the Dish Dash theme where no more specific template applies.
 *
 * Dependencies (this file needs):
 *   - get_header() → theme/dish-dash-theme/header.php
 *   - get_footer() → theme/dish-dash-theme/footer.php
 *   - WordPress: have_posts(), the_post(), the_content()
 *
 * Dependents (files that need this):
 *   - WordPress template hierarchy (loads as last-resort template)
 *
 * Last modified: v3.1.13
 */

get_header();
?>

<main id="main" class="site-main">
    <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <div class="entry-content" style="max-width:1200px;margin:40px auto;padding:0 20px;">
                <?php the_content(); ?>
            </div>
        </article>
    <?php endwhile; endif; ?>
</main>

<?php get_footer(); ?>
