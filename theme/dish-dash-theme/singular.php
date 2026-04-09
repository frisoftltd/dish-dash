
<?php
/**
 * Dish Dash Theme — Singular Template
 * Handles single posts, pages, WooCommerce pages.
 *
 * @package DishDashTheme
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
