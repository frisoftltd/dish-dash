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

    <!-- ══ PANEL: CART ══════════════════════════════════════════ -->
    <div class="dd-cart-panel" id="ddPanelCart" data-panel="cart">

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
            <button class="dd-cart-drawer__checkout dd-cart-drawer__checkout--disabled"
                    id="ddCartCheckout"
                    disabled
                    type="button">
                <?php esc_html_e( 'Proceed to Checkout', 'dish-dash' ); ?> &#8594;
            </button>
        </div>

    </div><!-- /#ddPanelCart -->

    <!-- ══ PANEL: CHECKOUT ══════════════════════════════════════ -->
    <div class="dd-cart-panel dd-cart-panel--hidden" id="ddPanelCheckout" data-panel="checkout">
        <div class="dd-checkout-panel__header">
            <button class="dd-checkout-panel__back" id="ddCheckoutBack" type="button" aria-label="<?php esc_attr_e( 'Back to cart', 'dish-dash' ); ?>">
                &#8592; <?php esc_html_e( 'Back', 'dish-dash' ); ?>
            </button>
            <span class="dd-checkout-panel__title"><?php esc_html_e( 'Checkout', 'dish-dash' ); ?></span>
        </div>

        <div class="dd-checkout-panel__body">
            <!-- Order summary strip -->
            <div class="dd-checkout-summary-strip" id="ddCheckoutSummaryStrip"></div>

            <!-- Checkout form -->
            <form class="dd-checkout-form-inner" id="ddCheckoutForm" novalidate>
                <div class="dd-cform-group">
                    <label for="ddFieldName"><?php esc_html_e( 'Full Name', 'dish-dash' ); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" id="ddFieldName" name="customer_name"
                           autocomplete="name" placeholder="Jean Pierre" required>
                    <span class="dd-cform-error" id="ddErrorName"></span>
                </div>

                <div class="dd-cform-group">
                    <label for="ddFieldWhatsapp"><?php esc_html_e( 'WhatsApp Number', 'dish-dash' ); ?> <span aria-hidden="true">*</span></label>
                    <input type="tel" id="ddFieldWhatsapp" name="whatsapp"
                           autocomplete="tel" placeholder="+250 78 000 0000" required>
                    <span class="dd-cform-error" id="ddErrorWhatsapp"></span>
                </div>

                <div class="dd-cform-group">
                    <label for="ddFieldAddress"><?php esc_html_e( 'Delivery Address', 'dish-dash' ); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" id="ddFieldAddress" name="delivery_address"
                           autocomplete="street-address" placeholder="Kacyiru, Kigali" required>
                    <span class="dd-cform-error" id="ddErrorAddress"></span>
                </div>

                <div class="dd-cform-group">
                    <p class="dd-cform-label"><?php esc_html_e( 'Payment Method', 'dish-dash' ); ?></p>
                    <div class="dd-payment-options" id="ddPaymentOptions">
                        <!-- Rendered by cart.js from ddCartData.paymentGateways -->
                    </div>
                </div>
            </form>
        </div>

        <div class="dd-checkout-panel__footer">
            <div class="dd-checkout-total-line">
                <span><?php esc_html_e( 'Total', 'dish-dash' ); ?></span>
                <span id="ddCheckoutTotal">&#8212;</span>
            </div>
            <div class="dd-checkout-delivery-line" id="ddCheckoutDeliveryLine">
                <span><?php esc_html_e( 'Delivery', 'dish-dash' ); ?></span>
                <span id="ddCheckoutDeliveryFee">&#8212;</span>
            </div>
            <button class="dd-checkout-place-order" id="ddPlaceOrder" type="button">
                <?php esc_html_e( 'Place Order', 'dish-dash' ); ?> &#8594;
            </button>
            <p class="dd-checkout-eta" id="ddCheckoutEta"></p>
        </div>
    </div><!-- /#ddPanelCheckout -->

    <!-- ══ PANEL: CONFIRMATION (stub — wired in v3.2.44) ════════ -->
    <div class="dd-cart-panel dd-cart-panel--hidden" id="ddPanelConfirmation" data-panel="confirmation">
        <div class="dd-confirm-panel">
            <div class="dd-confirm-panel__icon">&#9989;</div>
            <h2 class="dd-confirm-panel__title"><?php esc_html_e( 'Order Confirmed!', 'dish-dash' ); ?></h2>
            <p class="dd-confirm-panel__order-num" id="ddConfirmOrderNum"></p>
            <p class="dd-confirm-panel__eta" id="ddConfirmEta"></p>
            <button class="dd-confirm-panel__close" id="ddConfirmClose" type="button">
                <?php esc_html_e( 'Done', 'dish-dash' ); ?>
            </button>
        </div>
    </div><!-- /#ddPanelConfirmation -->

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
