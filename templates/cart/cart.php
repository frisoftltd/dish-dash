<?php
/**
 * Template: Cart Sidebar
 * Override: /your-theme/dish-dash/cart/cart.php
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="dd-cart-overlay"></div>

<aside class="dd-cart-sidebar" aria-label="<?php esc_attr_e( 'Shopping cart', 'dish-dash' ); ?>">

    <div class="dd-cart-sidebar__header">
        <h3>🛒 <?php esc_html_e( 'Your Cart', 'dish-dash' ); ?></h3>
        <button class="dd-cart-close" aria-label="<?php esc_attr_e( 'Close cart', 'dish-dash' ); ?>">✕</button>
    </div>

    <div class="dd-cart-items">
        <p class="dd-cart-empty"><?php esc_html_e( 'Your cart is empty.', 'dish-dash' ); ?></p>
    </div>

    <div class="dd-cart-summary" style="display:none">
        <div class="dd-cart-summary__row">
            <span><?php esc_html_e( 'Subtotal', 'dish-dash' ); ?></span>
            <span class="dd-cart-subtotal">—</span>
        </div>
        <?php if ( (float) DD_Settings::get( 'tax_rate', 0 ) > 0 ) : ?>
        <div class="dd-cart-summary__row">
            <span><?php echo esc_html( DD_Settings::get( 'tax_label', 'Tax' ) ); ?></span>
            <span class="dd-cart-tax">—</span>
        </div>
        <?php endif; ?>
        <div class="dd-cart-summary__row dd-cart-summary__row--total">
            <span><?php esc_html_e( 'Total', 'dish-dash' ); ?></span>
            <span class="dd-cart-total">—</span>
        </div>
        <button class="dd-cart-checkout-btn">
            <?php esc_html_e( 'Proceed to Checkout', 'dish-dash' ); ?> →
        </button>
    </div>

</aside>

<!-- Floating cart trigger button -->
<button class="dd-cart-trigger" aria-label="<?php esc_attr_e( 'Open cart', 'dish-dash' ); ?>">
    🛒 <?php esc_html_e( 'Cart', 'dish-dash' ); ?>
    <span class="dd-cart-count" style="display:none">0</span>
</button>
