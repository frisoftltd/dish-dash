<?php
/**
 * File:    admin/pages/kitchen.php
 * Purpose: Kitchen screen (v3.18.54) — full-screen, chrome-free display of
 *          confirmed orders for a kitchen tablet. Rendered as a standalone
 *          HTML document by DD_Orders_Module::maybe_render_kitchen_screen()
 *          on admin_init, before WordPress prints any wp-admin chrome —
 *          same escape-hatch pattern dd_invoice_render_page()
 *          (dishdash-core/class-dd-helpers.php) already uses for the
 *          standalone invoice page. The caller has already checked
 *          is_user_logged_in()/auth_redirect() and
 *          current_user_can('dd_manage_orders') before requiring this file.
 *
 * Does not touch: the WhatsApp module, the rider flow, the dashboard's own
 * Kitchen Queue card, or any customer-facing message.
 *
 * Last modified: v3.18.54
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$brand_color = sanitize_hex_color( get_option( 'dish_dash_primary_color', '#e8832a' ) ) ?: '#e8832a';
$admin_url   = admin_url( 'admin.php?page=dish-dash' );
$nonce       = wp_create_nonce( 'dish_dash_admin' );
$sound_url   = DD_ASSETS_URL . 'sounds/kitchen-alert.wav';
$css_url     = DD_ASSETS_URL . 'css/kitchen-screen.css';
$js_url      = DD_ASSETS_URL . 'js/kitchen-screen.js';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="robots" content="noindex, nofollow">
<title><?php esc_html_e( 'Kitchen — Dish Dash', 'dish-dash' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>?v=<?php echo esc_attr( DD_VERSION ); ?>">
<style>:root{--dd-ks-brand: <?php echo esc_html( $brand_color ); ?>;}</style>
</head>
<body class="dd-ks-body">

<div class="dd-ks-topbar">
    <div class="dd-ks-topbar__title">🍳 <?php esc_html_e( 'Kitchen', 'dish-dash' ); ?></div>
    <div class="dd-ks-topbar__right">
        <span class="dd-ks-status" id="dd-ks-status" data-offline="0">
            <span class="dd-ks-status__dot"></span>
            <span id="dd-ks-status-text"><?php esc_html_e( 'Live', 'dish-dash' ); ?></span>
        </span>
        <a class="dd-ks-back" href="<?php echo esc_url( $admin_url ); ?>">&larr; <?php esc_html_e( 'Back to admin', 'dish-dash' ); ?></a>
    </div>
</div>

<div class="dd-ks-grid" id="dd-ks-grid"></div>
<div class="dd-ks-empty" id="dd-ks-empty" style="display:none;"><?php esc_html_e( 'No confirmed orders right now.', 'dish-dash' ); ?></div>

<div class="dd-ks-toast" id="dd-ks-toast"></div>

<div class="dd-ks-overlay" id="dd-ks-overlay" style="display:none;">
    <div class="dd-ks-overlay__box">
        <h2><?php esc_html_e( 'Kitchen Screen', 'dish-dash' ); ?></h2>
        <p><?php esc_html_e( 'Browsers block sound until you tap once. Enable sound so new orders get an audible alert on this tablet.', 'dish-dash' ); ?></p>
        <button type="button" class="dd-ks-overlay__btn" id="dd-ks-enable-sound">🔔 <?php esc_html_e( 'Enable Sound', 'dish-dash' ); ?></button>
    </div>
</div>

<script>
window.ddKitchenData = {
    ajaxUrl:  <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
    nonce:    <?php echo wp_json_encode( $nonce ); ?>,
    soundUrl: <?php echo wp_json_encode( $sound_url ); ?>
};
</script>
<script src="<?php echo esc_url( $js_url ); ?>?v=<?php echo esc_attr( DD_VERSION ); ?>"></script>
</body>
</html>
