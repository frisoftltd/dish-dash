/**
 * File:    assets/js/cart.js
 * Purpose: Client-side cart engine and checkout form binding — opens/closes
 *          the cart sidebar, renders cart items from AJAX responses, handles
 *          quantity controls and item removal, and submits the checkout form
 *          via dd_place_order AJAX.
 *
 * DOM elements required:
 *   - .dd-add-to-cart-btn   (product "Add" buttons — shared with frontend.js)
 *   - .dd-cart-trigger      (floating cart button)
 *   - .dd-cart-overlay      (backdrop)
 *   - .dd-cart-sidebar      (.dd-cart-sidebar--open toggled to show)
 *   - .dd-cart-close        (close button)
 *   - .dd-cart-items        (items container, re-rendered on every update)
 *   - .dd-cart-summary      (totals section)
 *   - .dd-cart-checkout-btn (checkout link)
 *   - .dd-checkout-form     (checkout page form)
 *   - .dd-order-type-btn    (delivery/pickup/dine-in selector)
 *
 * Localized data needed (wp_localize_script):
 *   - window.dishDash  (ajaxUrl, nonce, cartUrl, checkoutUrl, currency_symbol,
 *     currency_position)  — localized by DD_Template_Module
 *
 * AJAX endpoints called:
 *   - admin-ajax.php?action=dd_cart_add
 *   - admin-ajax.php?action=dd_cart_update
 *   - admin-ajax.php?action=dd_cart_remove
 *   - admin-ajax.php?action=dd_cart_get
 *   - admin-ajax.php?action=dd_cart_clear
 *   - admin-ajax.php?action=dd_place_order
 *
 * Custom events fired:   None
 * Custom events listened: None
 *
 * Dependents:
 *   - modules/template/class-dd-template-module.php (enqueues this)
 *
 * Last modified: v3.1.13
 */
(function () {
    'use strict';

    const DD = window.dishDash || {};

    /* ── CART STATE ─────────────────────────────────────── */
    let cart = { items: [], count: 0, subtotal: 0, tax: 0, total: 0 };

    /* ── INIT ───────────────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        fetchCart();
        bindMenuButtons();
        bindCartEvents();
        bindCheckoutEvents();
    });

    /* ── FETCH CART FROM SERVER ─────────────────────────── */
    function fetchCart() {
        ajax('dd_cart_get', {}, function (data) {
            cart = data;
            renderCart();
            updateCartCount();
        });
    }

    /* ── BIND ADD TO CART BUTTONS ───────────────────────── */
    function bindMenuButtons() {
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.dd-add-to-cart-btn');
            if (!btn) return;

            e.preventDefault();
            const item = {
                id:    btn.dataset.id,
                name:  btn.dataset.name,
                price: btn.dataset.price,
                image: btn.dataset.image || '',
                qty:   1,
            };

            ajax('dd_cart_add', item, function (data) {
                cart = data;
                renderCart();
                updateCartCount();
                showAddedFeedback(btn);
                openCartSidebar();
            });
        });
    }

    /* ── BIND CART EVENTS ───────────────────────────────── */
    function bindCartEvents() {
        document.addEventListener('click', function (e) {

            // Open cart sidebar
            if (e.target.closest('.dd-cart-trigger')) {
                openCartSidebar();
            }

            // Close cart sidebar
            if (e.target.closest('.dd-cart-close') || e.target.classList.contains('dd-cart-overlay')) {
                closeCartSidebar();
            }

            // Remove item
            const removeBtn = e.target.closest('.dd-cart-item__remove');
            if (removeBtn) {
                const key = removeBtn.dataset.key;
                ajax('dd_cart_remove', { key }, function (data) {
                    cart = data;
                    renderCart();
                    updateCartCount();
                });
            }

            // Checkout button in sidebar
            if (e.target.closest('.dd-cart-checkout-btn')) {
                window.location.href = DD.checkout_url || '/checkout-dd/';
            }
        });

        // Quantity change
        document.addEventListener('change', function (e) {
            const input = e.target.closest('.dd-cart-item__qty');
            if (!input) return;
            const key = input.dataset.key;
            const qty = parseInt(input.value, 10);
            ajax('dd_cart_update', { key, qty }, function (data) {
                cart = data;
                renderCart();
                updateCartCount();
            });
        });
    }

    /* ── RENDER CART SIDEBAR ────────────────────────────── */
    function renderCart() {
        const container = document.querySelector('.dd-cart-items');
        const summary   = document.querySelector('.dd-cart-summary');
        if (!container) return;

        if (!cart.items || cart.items.length === 0) {
            container.innerHTML = '<p class="dd-cart-empty">' + (DD.i18n?.emptyCart || 'Your cart is empty') + '</p>';
            if (summary) summary.style.display = 'none';
            return;
        }

        if (summary) summary.style.display = '';

        container.innerHTML = cart.items.map(function (item) {
            const addonTotal = (item.addons || []).reduce((s, a) => s + a.price, 0);
            const unitPrice  = item.price + addonTotal;
            const sym        = DD.currency_symbol || '$';
            const pos        = DD.currency_position || 'before';
            const fmt        = (v) => pos === 'before' ? sym + parseFloat(v).toFixed(2) : parseFloat(v).toFixed(2) + sym;

            const addonHtml = item.addons && item.addons.length
                ? '<span class="dd-cart-item__addons">' + item.addons.map(a => a.name).join(', ') + '</span>'
                : '';

            return `
            <div class="dd-cart-item" data-key="${item.key || ''}">
                ${item.image ? `<img class="dd-cart-item__img" src="${item.image}" alt="${item.name}" loading="lazy">` : ''}
                <div class="dd-cart-item__info">
                    <span class="dd-cart-item__name">${item.name}</span>
                    ${addonHtml}
                    <span class="dd-cart-item__price">${fmt(unitPrice)}</span>
                </div>
                <div class="dd-cart-item__controls">
                    <input class="dd-cart-item__qty" type="number" min="0" value="${item.qty}" data-key="${item.key || ''}">
                    <button class="dd-cart-item__remove" data-key="${item.key || ''}" aria-label="Remove">✕</button>
                </div>
            </div>`;
        }).join('');

        // Update totals
        const sym = DD.currency_symbol || '$';
        const pos = DD.currency_position || 'before';
        const fmt = (v) => pos === 'before' ? sym + parseFloat(v).toFixed(2) : parseFloat(v).toFixed(2) + sym;

        const subtotalEl = document.querySelector('.dd-cart-subtotal');
        const taxEl      = document.querySelector('.dd-cart-tax');
        const totalEl    = document.querySelector('.dd-cart-total');

        if (subtotalEl) subtotalEl.textContent = fmt(cart.subtotal);
        if (taxEl)      taxEl.textContent      = fmt(cart.tax);
        if (totalEl)    totalEl.textContent     = fmt(cart.total);
    }

    /* ── CART SIDEBAR OPEN / CLOSE ──────────────────────── */
    function openCartSidebar() {
        const sidebar = document.querySelector('.dd-cart-sidebar');
        const overlay = document.querySelector('.dd-cart-overlay');
        if (sidebar) sidebar.classList.add('dd-cart-sidebar--open');
        if (overlay) overlay.classList.add('dd-cart-overlay--visible');
        document.body.style.overflow = 'hidden';
    }

    function closeCartSidebar() {
        const sidebar = document.querySelector('.dd-cart-sidebar');
        const overlay = document.querySelector('.dd-cart-overlay');
        if (sidebar) sidebar.classList.remove('dd-cart-sidebar--open');
        if (overlay) overlay.classList.remove('dd-cart-overlay--visible');
        document.body.style.overflow = '';
    }

    /* ── CART COUNT BADGE ───────────────────────────────── */
    function updateCartCount() {
        document.querySelectorAll('.dd-cart-count').forEach(function (el) {
            el.textContent = cart.count || 0;
            el.style.display = (cart.count > 0) ? 'inline-flex' : 'none';
        });
    }

    /* ── CHECKOUT FORM ──────────────────────────────────── */
    function bindCheckoutEvents() {
        const form = document.querySelector('.dd-checkout-form');
        if (!form) return;

        // Populate cart summary on checkout page
        renderCheckoutSummary();

        // Order type toggle
        form.addEventListener('change', function (e) {
            const radio = e.target.closest('input[name="order_type"]');
            if (!radio) return;
            toggleDeliverySection(radio.value === 'delivery');
        });

        // Submit
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submitOrder(form);
        });
    }

    function renderCheckoutSummary() {
        const container = document.querySelector('.dd-checkout-items');
        if (!container || !cart.items) return;

        const sym = DD.currency_symbol || '$';
        const pos = DD.currency_position || 'before';
        const fmt = (v) => pos === 'before' ? sym + parseFloat(v).toFixed(2) : parseFloat(v).toFixed(2) + sym;

        container.innerHTML = cart.items.map(function (item) {
            return `<div class="dd-checkout-item">
                <span class="dd-checkout-item__name">${item.name} × ${item.qty}</span>
                <span class="dd-checkout-item__price">${fmt(item.price * item.qty)}</span>
            </div>`;
        }).join('');

        const totalEl = document.querySelector('.dd-checkout-total');
        if (totalEl) totalEl.textContent = fmt(cart.total);
    }

    function toggleDeliverySection(show) {
        const section = document.querySelector('.dd-delivery-section');
        if (section) section.style.display = show ? '' : 'none';
    }

    function submitOrder(form) {
        const btn = form.querySelector('[type="submit"]');
        if (btn) { btn.disabled = true; btn.textContent = 'Placing order…'; }

        const data = {
            customer_name:        form.querySelector('[name="customer_name"]')?.value || '',
            customer_phone:       form.querySelector('[name="customer_phone"]')?.value || '',
            customer_email:       form.querySelector('[name="customer_email"]')?.value || '',
            order_type:           form.querySelector('input[name="order_type"]:checked')?.value || 'delivery',
            special_instructions: form.querySelector('[name="special_instructions"]')?.value || '',
            payment_method:       form.querySelector('[name="payment_method"]')?.value || 'cod',
            items:                JSON.stringify(cart.items),
            delivery_fee:         0,
            branch_id:            DD.branch_id || 1,
        };

        ajax('dd_place_order', data, function (result) {
            // Clear cart
            cart = { items: [], count: 0, subtotal: 0, tax: 0, total: 0 };
            updateCartCount();

            // Redirect to tracking page
            if (result.track_url) {
                window.location.href = result.track_url;
            } else {
                showNotice('success', 'Order placed! Your order number is ' + result.order_number);
            }
        }, function (err) {
            showNotice('error', err || 'Something went wrong. Please try again.');
            if (btn) { btn.disabled = false; btn.textContent = 'Place Order'; }
        });
    }

    /* ── HELPERS ────────────────────────────────────────── */
    function ajax(action, data, onSuccess, onError) {
        const body = new FormData();
        body.append('action', action);
        body.append('nonce', DD.nonce || '');
        Object.entries(data).forEach(([k, v]) => body.append(k, v));

        fetch(DD.ajax_url || '/wp-admin/admin-ajax.php', { method: 'POST', body })
            .then(r => r.json())
            .then(function (res) {
                if (res.success) {
                    onSuccess && onSuccess(res.data);
                } else {
                    onError && onError(res.data?.message);
                }
            })
            .catch(function () {
                onError && onError('Network error');
            });
    }

    function showAddedFeedback(btn) {
        const orig = btn.textContent;
        btn.textContent = '✓ Added!';
        btn.style.background = '#2e9e5b';
        btn.disabled = true;
        setTimeout(function () {
            btn.textContent = orig;
            btn.style.background = '';
            btn.disabled = false;
        }, 1200);
    }

    function showNotice(type, msg) {
        let notice = document.querySelector('.dd-notice');
        if (!notice) {
            notice = document.createElement('div');
            notice.className = 'dd-notice';
            document.querySelector('.dd-checkout-form')?.prepend(notice);
        }
        notice.className = `dd-notice dd-notice--${type}`;
        notice.textContent = msg;
        notice.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // Expose cart API globally
    window.DDCart = { open: openCartSidebar, close: closeCartSidebar, refresh: fetchCart };

})();
