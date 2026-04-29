<?php
/**
 * File:    templates/cart/cart.php
 * Purpose: Cart drawer — slide-in panel with item list, delivery progress
 *          nudge, subtotal, and checkout button. Also renders the floating
 *          cart trigger button (desktop).
 *
 * Variables expected (set by inject_cart_sidebar() before require_once):
 *   $checkout_url  (string) — URL to checkout page
 *   $dd_cart_count (int)    — server-side cart count for initial badge
 *
 * CSS classes (Implementation B — authoritative):
 *   .dd-cart-drawer-overlay, .dd-cart-drawer, .dd-cart-drawer--open,
 *   .dd-cart-drawer__header, .dd-cart-drawer__items, .dd-cart-drawer__nudge,
 *   .dd-cart-drawer__footer, .dd-cart-btn, .dd-cart-btn__count
 *
 * JS target IDs:
 *   #ddCartOverlay, #ddCartDrawer, #ddCartClose, #ddCartItems,
 *   #ddCartNudge, #ddNudgeFill, #ddNudgeRemaining,
 *   #ddCartSubtotal, #ddCartCheckout, #ddCartBtn, #ddCartBtnCount
 *
 * Included by:
 *   modules/template/class-dd-template-module.php → inject_cart_sidebar()
 *
 * Last modified: v3.2.13
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Fallback if variables not set by caller
if ( ! isset( $checkout_url ) ) {
    $checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
}
if ( ! isset( $dd_cart_count ) ) {
    $dd_cart_count = 0;
}
?>

<!-- ══ CART OVERLAY ══════════════════════════════════════════ -->
<div class="dd-cart-drawer-overlay" id="ddCartOverlay"></div>

<!-- ══ CART DRAWER ═══════════════════════════════════════════ -->
<aside class="dd-cart-drawer" id="ddCartDrawer"
       role="dialog" aria-modal="true"
       aria-label="<?php esc_attr_e( 'Your cart', 'dish-dash' ); ?>">

    <!-- Header -->
    <div class="dd-cart-drawer__header">
        <span class="dd-cart-drawer__title">
            &#128722; <?php esc_html_e( 'Your Order', 'dish-dash' ); ?>
        </span>
        <button class="dd-cart-drawer__close" id="ddCartClose"
                aria-label="<?php esc_attr_e( 'Close cart', 'dish-dash' ); ?>">
            &#10005;
        </button>

        <script>
        (function() {
            function doClose() {
                var d = document.getElementById('ddCartDrawer');
                var o = document.getElementById('ddCartOverlay');
                if (d) d.classList.remove('dd-cart-drawer--open');
                if (o) o.classList.remove('dd-cart-drawer-overlay--visible');
                document.body.classList.remove('dd-cart-open');
            }
            var btn = document.getElementById('ddCartClose');
            if (btn) {
                btn.addEventListener('touchstart', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    doClose();
                }, { passive: false });
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    doClose();
                });
            }
            var overlay = document.getElementById('ddCartOverlay');
            if (overlay) {
                overlay.addEventListener('touchstart', function(e) {
                    e.preventDefault();
                    doClose();
                }, { passive: false });
            }
        })();
        </script>
    </div>

    <!-- Items list — JS replaces contents on fetch -->
    <div class="dd-cart-drawer__items" id="ddCartItems">
        <p class="dd-cart-drawer__empty">
            <?php esc_html_e( 'Your cart is empty', 'dish-dash' ); ?>
            &mdash; <?php esc_html_e( 'add something delicious', 'dish-dash' ); ?> &#x1F37D;
        </p>
    </div>

    <!-- Delivery nudge bar — hidden until items in cart -->
    <div class="dd-cart-drawer__nudge" id="ddCartNudge" style="display:none">
        <p class="dd-nudge__label">
            &#128757; <?php esc_html_e( 'Add', 'dish-dash' ); ?>
            <span id="ddNudgeRemaining">3,500</span>
            RWF <?php esc_html_e( 'more for FREE delivery', 'dish-dash' ); ?>
        </p>
        <div class="dd-nudge__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
            <div class="dd-nudge__fill" id="ddNudgeFill" style="width:0%"></div>
        </div>
    </div>

    <!-- Footer: subtotal + checkout -->
    <div class="dd-cart-drawer__footer">
        <div class="dd-cart-drawer__subtotal">
            <span class="dd-cart-drawer__subtotal-label">
                <?php esc_html_e( 'Subtotal', 'dish-dash' ); ?>
            </span>
            <span class="dd-cart-drawer__subtotal-value" id="ddCartSubtotal">RWF 0</span>
        </div>
        <a href="<?php echo esc_url( $checkout_url ); ?>"
           class="dd-cart-drawer__checkout dd-cart-drawer__checkout--disabled"
           id="ddCartCheckout"
           aria-disabled="true"
           tabindex="-1">
            <?php esc_html_e( 'Proceed to Checkout', 'dish-dash' ); ?> &#8594;
        </a>
    </div>

</aside>

<!-- ══ FLOATING CART BUTTON (desktop) ══════════════════════ -->
<button class="dd-cart-btn" id="ddCartBtn"
        aria-label="<?php esc_attr_e( 'Open cart', 'dish-dash' ); ?>">
    &#128722;
    <span class="dd-cart-btn__count" id="ddCartBtnCount"
          style="<?php echo $dd_cart_count > 0 ? '' : 'display:none'; ?>">
        <?php echo esc_html( $dd_cart_count ); ?>
    </span>
</button>
