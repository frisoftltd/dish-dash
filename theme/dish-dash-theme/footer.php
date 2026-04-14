<?php
/**
 * File:    theme/dish-dash-theme/footer.php
 * Purpose: WordPress theme footer template — outputs wp_footer() to
 *          fire all footer scripts, then closes the <body> and <html>
 *          tags. Loaded via get_footer() in all theme templates.
 *
 * Dependencies (this file needs):
 *   - WordPress: wp_footer()
 *
 * Dependents (files that need this):
 *   - theme/dish-dash-theme/index.php    (calls get_footer())
 *   - theme/dish-dash-theme/page.php     (calls get_footer())
 *   - theme/dish-dash-theme/singular.php (calls get_footer())
 *
 * Last modified: v3.1.13
 */
?>

<?php wp_footer(); ?>
</body>
</html>
