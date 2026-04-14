<?php
/**
 * File:    theme/dish-dash-theme/header.php
 * Purpose: WordPress theme header template — outputs the full HTML document
 *          opening (DOCTYPE, <html>, <head> with wp_head(), <body> with
 *          body_class() and wp_body_open()). Loaded via get_header() in all
 *          theme templates. DD_Template_Module hooks into wp_body_open to
 *          inject the branded global header on Dish Dash pages.
 *
 * Dependencies (this file needs):
 *   - WordPress: language_attributes(), bloginfo(), wp_head(),
 *     body_class(), wp_body_open()
 *
 * Dependents (files that need this):
 *   - theme/dish-dash-theme/index.php    (calls get_header())
 *   - theme/dish-dash-theme/page.php     (calls get_header())
 *   - theme/dish-dash-theme/singular.php (calls get_header())
 *
 * Hooks fired (by WordPress): wp_head, wp_body_open
 *
 * Last modified: v3.1.13
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
