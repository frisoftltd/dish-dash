<?php
/**
 * File:    templates/checkout/checkout.php
 * Purpose: Checkout form template — collects order type (delivery/pickup/
 *          dine-in), delivery address, customer details, payment method,
 *          and special instructions. Submits via dd_place_order AJAX.
 *          Theme override: /your-theme/dish-dash/checkout/checkout.php
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core guard)
 *   - assets/css/cart.css (shares checkout styles)
 *   - assets/js/cart.js   (form binding, AJAX submit)
 *   - DD_Settings (via wp_localize_script data)
 *
 * Dependents (files that need this):
 *   - modules/menu/class-dd-menu-module.php ([dish_dash_checkout] shortcode)
 *
 * Form fields: order_type, delivery_street, delivery_city,
 *   delivery_postcode, customer_name, customer_phone, customer_email,
 *   special_instructions, payment_method
 *
 * AJAX action called: dd_place_order
 *
 * Key CSS classes:
 *   .dd-checkout-wrap, .dd-checkout-form, .dd-order-types,
 *   .dd-order-type-btn, .dd-submit-btn, .dd-checkout-summary
 *
 * Last modified: v3.1.13
 */
?>

<?php
/**
 * Template: Checkout
 * Override: /your-theme/dish-dash/checkout/checkout.php
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="dd-checkout-wrap">

    <!-- LEFT: Checkout Form -->
    <div class="dd-checkout-left">

        <form class="dd-checkout-form" novalidate>

            <!-- Order Type -->
            <h2><?php esc_html_e( 'How would you like your order?', 'dish-dash' ); ?></h2>
            <div class="dd-order-types">
                <?php if ( dd_is_enabled( 'delivery' ) ) : ?>
                <label class="dd-order-type-btn">
                    <input type="radio" name="order_type" value="delivery" checked>
                    <span class="dd-order-type-btn__icon">🛵</span>
                    <span class="dd-order-type-btn__label"><?php esc_html_e( 'Delivery', 'dish-dash' ); ?></span>
                </label>
                <?php endif; ?>
                <?php if ( dd_is_enabled( 'pickup' ) ) : ?>
                <label class="dd-order-type-btn">
                    <input type="radio" name="order_type" value="pickup">
                    <span class="dd-order-type-btn__icon">🏃</span>
                    <span class="dd-order-type-btn__label"><?php esc_html_e( 'Pickup', 'dish-dash' ); ?></span>
                </label>
                <?php endif; ?>
                <?php if ( dd_is_enabled( 'dinein' ) ) : ?>
                <label class="dd-order-type-btn">
                    <input type="radio" name="order_type" value="dine-in">
                    <span class="dd-order-type-btn__icon">🍽</span>
                    <span class="dd-order-type-btn__label"><?php esc_html_e( 'Dine In', 'dish-dash' ); ?></span>
                </label>
                <?php endif; ?>
            </div>

            <!-- Delivery Address -->
            <div class="dd-delivery-section">
                <h2><?php esc_html_e( 'Delivery Address', 'dish-dash' ); ?></h2>
                <div class="dd-form-group">
                    <label><?php esc_html_e( 'Street Address', 'dish-dash' ); ?> *</label>
                    <input type="text" name="delivery_street" required placeholder="<?php esc_attr_e( 'Enter your street address', 'dish-dash' ); ?>" />
                </div>
                <div class="dd-form-row">
                    <div class="dd-form-group">
                        <label><?php esc_html_e( 'City', 'dish-dash' ); ?> *</label>
                        <input type="text" name="delivery_city" required />
                    </div>
                    <div class="dd-form-group">
                        <label><?php esc_html_e( 'Postcode', 'dish-dash' ); ?></label>
                        <input type="text" name="delivery_postcode" />
                    </div>
                </div>
            </div>

            <!-- Customer Details -->
            <h2><?php esc_html_e( 'Your Details', 'dish-dash' ); ?></h2>
            <div class="dd-form-row">
                <div class="dd-form-group">
                    <label><?php esc_html_e( 'Full Name', 'dish-dash' ); ?> *</label>
                    <input type="text" name="customer_name" required
                        value="<?php echo esc_attr( is_user_logged_in() ? wp_get_current_user()->display_name : '' ); ?>" />
                </div>
                <div class="dd-form-group">
                    <label><?php esc_html_e( 'Phone', 'dish-dash' ); ?> *</label>
                    <input type="tel" name="customer_phone" required />
                </div>
            </div>
            <div class="dd-form-group">
                <label><?php esc_html_e( 'Email', 'dish-dash' ); ?> *</label>
                <input type="email" name="customer_email" required
                    value="<?php echo esc_attr( is_user_logged_in() ? wp_get_current_user()->user_email : '' ); ?>" />
            </div>

            <!-- Special Instructions -->
            <div class="dd-form-group">
                <label><?php esc_html_e( 'Special Instructions', 'dish-dash' ); ?></label>
                <textarea name="special_instructions" rows="3"
                    placeholder="<?php esc_attr_e( 'Allergies, preferences, gate code...', 'dish-dash' ); ?>"></textarea>
            </div>

            <!-- Payment Method -->
            <h2><?php esc_html_e( 'Payment', 'dish-dash' ); ?></h2>
            <div class="dd-form-group">
                <select name="payment_method">
                    <option value="cod"><?php esc_html_e( 'Cash on Delivery', 'dish-dash' ); ?></option>
                    <option value="card"><?php esc_html_e( 'Pay by Card', 'dish-dash' ); ?></option>
                </select>
            </div>

            <button type="submit" class="dd-submit-btn">
                <?php esc_html_e( 'Place Order', 'dish-dash' ); ?> →
            </button>

        </form>
    </div>

    <!-- RIGHT: Order Summary -->
    <div class="dd-checkout-summary">
        <h3><?php esc_html_e( 'Your Order', 'dish-dash' ); ?></h3>
        <div class="dd-checkout-items"></div>
        <div class="dd-checkout-total-row">
            <span><?php esc_html_e( 'Total', 'dish-dash' ); ?></span>
            <span class="dd-checkout-total">—</span>
        </div>
    </div>

</div>
