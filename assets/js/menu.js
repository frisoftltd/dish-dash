/* ============================================================
   Dish Dash — Frontend Menu JS
   File: assets/js/menu.js
   Vanilla JS, no dependencies.
   ============================================================ */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.dd-menu-wrap').forEach(initMenu);
    });

    function initMenu(wrap) {
        initFilter(wrap);
        initSearch(wrap);
        initCart(wrap);
    }

    /* ── Category Filter ────────────────────────────────── */
    function initFilter(wrap) {
        var btns  = wrap.querySelectorAll('.dd-filter-btn');
        var cards = wrap.querySelectorAll('.dd-menu-card');

        if (!btns.length) return;

        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var filter = btn.dataset.filter;

                btns.forEach(function (b) {
                    b.classList.remove('dd-filter-btn--active');
                });
                btn.classList.add('dd-filter-btn--active');

                cards.forEach(function (card) {
                    if (filter === 'all') {
                        card.classList.remove('dd-hidden');
                    } else {
                        var cats = (card.dataset.category || '').split(' ');
                        card.classList.toggle('dd-hidden', !cats.includes(filter));
                    }
                });

                updateNoResults(wrap);
            });
        });
    }

    /* ── Live Search ────────────────────────────────────── */
    function initSearch(wrap) {
        var input = wrap.querySelector('.dd-search-input');
        if (!input) return;

        var cards = wrap.querySelectorAll('.dd-menu-card');

        input.addEventListener('input', function () {
            var query = input.value.trim().toLowerCase();

            cards.forEach(function (card) {
                if (!query) {
                    card.classList.remove('dd-hidden');
                } else {
                    var title = (card.dataset.title || '').toLowerCase();
                    card.classList.toggle('dd-hidden', !title.includes(query));
                }
            });

            // Reset active filter button to "All"
            if (query) {
                wrap.querySelectorAll('.dd-filter-btn').forEach(function (b) {
                    b.classList.remove('dd-filter-btn--active');
                });
            }

            updateNoResults(wrap);
        });
    }

    /* ── No Results message ─────────────────────────────── */
    function updateNoResults(wrap) {
        var visible   = wrap.querySelectorAll('.dd-menu-card:not(.dd-hidden)');
        var noResults = wrap.querySelector('.dd-no-results');
        if (noResults) {
            noResults.style.display = visible.length === 0 ? 'block' : 'none';
        }
    }

    /* ── Add to Cart ────────────────────────────────────── */
    function initCart(wrap) {
        wrap.querySelectorAll('.dd-add-to-cart-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var item = {
                    id:    btn.dataset.id,
                    name:  btn.dataset.name,
                    price: parseFloat(btn.dataset.price) || 0,
                    image: btn.dataset.image || '',
                    qty:   1,
                };

                addToCart(item);
                showAddedFeedback(btn);
            });
        });
    }

    /* ── Cart Storage ───────────────────────────────────── */
    var CART_KEY = 'dd_cart';

    function getCart() {
        try {
            return JSON.parse(localStorage.getItem(CART_KEY)) || [];
        } catch (e) {
            return [];
        }
    }

    function saveCart(cart) {
        localStorage.setItem(CART_KEY, JSON.stringify(cart));
        // Dispatch event so other components (cart widget) can react.
        document.dispatchEvent(new CustomEvent('dd_cart_updated', { detail: { cart: cart } }));
    }

    function addToCart(item) {
        var cart    = getCart();
        var existing = cart.find(function (c) { return c.id === item.id; });

        if (existing) {
            existing.qty += 1;
        } else {
            cart.push(item);
        }

        saveCart(cart);
        updateCartCount();
    }

    function updateCartCount() {
        var cart  = getCart();
        var total = cart.reduce(function (sum, i) { return sum + i.qty; }, 0);

        document.querySelectorAll('.dd-cart-count').forEach(function (el) {
            el.textContent = total;
            el.style.display = total > 0 ? 'inline-flex' : 'none';
        });
    }

    /* ── Button feedback animation ──────────────────────── */
    function showAddedFeedback(btn) {
        var original = btn.textContent;
        btn.textContent = '✓ Added!';
        btn.style.background = '#2e9e5b';
        btn.disabled = true;

        setTimeout(function () {
            btn.textContent = original;
            btn.style.background = '';
            btn.disabled = false;
        }, 1200);
    }

    // Init cart count on page load.
    document.addEventListener('DOMContentLoaded', updateCartCount);

})();
