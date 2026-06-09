<?php
/**
 * Template Name: Dish Dash Simple Page
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();
?>
<main class="dd-simple-page">
    <div class="dd-container">
        <div class="dd-simple-page__inner">
            <h1 class="dd-simple-page__title"><?php the_title(); ?></h1>
            <div class="dd-simple-page__content">
                <?php
                while ( have_posts() ) :
                    the_post();
                    the_content();
                endwhile;
                ?>
            </div>
        </div>
    </div>
</main>
<?php get_footer(); ?>
