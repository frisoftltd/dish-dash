/**
 * Dish Dash — Search Module
 *
 * Completely self-contained. Zero dependencies on frontend.js.
 * Handles: desktop search bar + mobile expandable search.
 * Communicates with other modules via custom DOM events only:
 *   → fires   'dd:open-modal' (detail: { productId })
 *   → fires   'dd:filter-cards' (detail: { query })
 *
 * @package DishDash
 * @since   2.5.97
 */
(function () {
    'use strict';

    /* ── Internal state — private to this module ── */
    var products       = [];   // { id, name, price, desc, img, nonce }
    var productsReady  = false;
    var loadingProducts= false;

    /* ── Helpers ───────────────────────────────── */
    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function hi(text, query) {
        if (!query) return esc(text);
        var safe = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return esc(text).replace(
            new RegExp('(' + safe + ')', 'gi'),
            '<mark class="dd-ss__hl">$1</mark>'
        );
    }

    function ajax() {
        return (window.DD && window.DD.ajaxUrl)
            || (window.DDAauth && window.DDAauth.ajaxUrl)
            || '/wp-admin/admin-ajax.php';
    }

    function nonce() {
        return (window.DD && window.DD.nonce)
            || (window.DDAauth && window.DDAauth.nonce)
            || '';
    }

    /* ── Read products from DOM (homepage/menu page) ── */
    function readFromDOM() {
        var seen = {};
        document.querySelectorAll('.dd-dish-card, .dd-menu-item').forEach(function (card) {
            var isDish  = card.classList.contains('dd-dish-card');
            var titleEl = card.querySelector(isDish ? '.dd-dish-card__title' : '.dd-menu-item__name');
            var priceEl = card.querySelector(isDish ? '.dd-price' : '.dd-menu-item__price');
            var descEl  = card.querySelector(isDish ? '.dd-dish-card__desc' : '.dd-menu-item__desc');
            var imgEl   = card.querySelector('img');
            var addBtn  = card.querySelector('.dd-add-btn');
            var id      = card.dataset.id || '';
            if (titleEl && id && !seen[id]) {
                seen[id] = true;
                products.push({
                    id:    id,
                    name:  titleEl.textContent.trim(),
                    price: priceEl ? priceEl.textContent.trim() : '',
                    desc:  descEl  ? descEl.textContent.trim()  : '',
                    img:   imgEl   ? imgEl.src                  : '',
                    nonce: addBtn  ? (addBtn.dataset.nonce || '') : '',
                });
            }
        });
    }

    /* ── Fetch products from server (non-homepage pages) ── */
    function loadFromServer(callback) {
        if (productsReady)  { callback(); return; }
        if (loadingProducts){ callback(); return; }
        loadingProducts = true;

        fetch(ajax(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'dd_get_search_products',
                nonce:  nonce()
            }).toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success && res.data) {
                var seen = {};
                products.forEach(function (p) { seen[p.id] = true; });
                res.data.forEach(function (p) {
                    if (!seen[p.id]) {
                        seen[p.id] = true;
                        products.push(p);
                    }
                });
            }
            productsReady  = true;
            loadingProducts= false;
            callback();
        })
        .catch(function () {
            productsReady  = true;
            loadingProducts= false;
            callback();
        });
    }

    function ensureProducts(callback) {
        if (productsReady) { callback(); return; }
        loadFromServer(callback);
    }

    /* ── Match products by query ── */
    function match(query, limit) {
        var q = (query || '').toLowerCase().trim();
        if (!q) return [];
        return products.filter(function (p) {
            return p.name.toLowerCase().indexOf(q) !== -1;
        }).slice(0, limit || 6);
    }

    /* ── Build result cards HTML ── */
    function buildCards(results, query) {
        if (!results.length) {
            return '<div class="dd-ss__empty">No dishes found for &ldquo;' + esc(query) + '&rdquo;</div>';
        }
        var html = '<div class="dd-ss__dropdown-section">';
        html += '<div class="dd-ss__dropdown-label">Dishes (' + results.length + ')</div>';
        html += '<div class="dd-ss__results-grid">';
        results.forEach(function (p) {
            html += '<button class="dd-ss__result-card" data-pid="' + esc(p.id) + '">' +
                '<div class="dd-ss__result-img">' +
                    (p.img
                        ? '<img src="' + esc(p.img) + '" alt="' + esc(p.name) + '" loading="lazy">'
                        : '<span>&#127869;</span>') +
                '</div>' +
                '<div class="dd-ss__result-body">' +
                    '<div class="dd-ss__result-name">' + hi(p.name, query) + '</div>' +
                    '<div class="dd-ss__result-price">' + esc(p.price) + '</div>' +
                '</div>' +
            '</button>';
        });
        html += '</div></div>';
        return html;
    }

    /* ── Fire custom events (decoupled communication) ── */
    function openModal(productId) {
        document.dispatchEvent(new CustomEvent('dd:open-modal', {
            detail: { productId: productId }
        }));
    }

    function filterCards(query) {
        document.dispatchEvent(new CustomEvent('dd:filter-cards', {
            detail: { query: query }
        }));
    }

    /* ════════════════════════════════════════════════
       DESKTOP SEARCH
    ════════════════════════════════════════════════ */
    function initDesktop() {
        var input    = document.getElementById('ddSearch');
        var dropdown = document.getElementById('ddSearchDropdown');
        var clearBtn = document.getElementById('ddSearchClear');

        if (!input || !dropdown) return;

        input.value = ''; // clear any browser autofill

        function showDropdown(query) {
            var results = match(query, 6);
            dropdown.innerHTML = buildCards(results, query);
            dropdown.classList.add('open');
            input.setAttribute('aria-expanded', 'true');
        }

        function hideDropdown() {
            dropdown.innerHTML = '';
            dropdown.classList.remove('open');
            input.setAttribute('aria-expanded', 'false');
        }

        /* Input: real-time results */
        input.addEventListener('input', function () {
            var q = this.value.trim();
            if (clearBtn) clearBtn.classList.toggle('visible', q.length > 0);
            filterCards(q);

            if (!q) { hideDropdown(); return; }

            ensureProducts(function () { showDropdown(q); });
        });

        /* Focus: preload products */
        input.addEventListener('focus', function () {
            ensureProducts(function () {});
        });

        /* Keyboard */
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { hideDropdown(); this.blur(); }
            if (e.key === 'Enter') {
                var q = this.value.trim();
                if (q.length >= 5 && window.DDTrack) window.DDTrack.search(q);
                hideDropdown();
            }
        });

        /* Clear */
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                input.value = '';
                this.classList.remove('visible');
                filterCards('');
                hideDropdown();
                input.focus();
            });
        }

        /* Click on result card */
        dropdown.addEventListener('click', function (e) {
            var card = e.target.closest('.dd-ss__result-card');
            if (!card) return;
            var pid = card.dataset.pid;
            hideDropdown();
            if (pid) openModal(pid);
        });

        /* Click outside → close */
        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                hideDropdown();
            }
        });

        /* Prevent blur before click registers */
        dropdown.addEventListener('mousedown', function (e) {
            e.preventDefault();
        });
    }

    /* ════════════════════════════════════════════════
       MOBILE SEARCH
    ════════════════════════════════════════════════ */
    function initMobile() {
        var trigger  = document.getElementById('ddMobileSearchTrigger');
        var panel    = document.getElementById('ddMobileSearchPanel');
        var input    = document.getElementById('ddMobileSearch');
        var closeBtn = document.getElementById('ddMobileSearchClose');
        var dropdown = document.getElementById('ddMobileSearchDropdown');

        if (!trigger || !panel || !input) return;

        /* Overlay */
        var overlay = document.createElement('div');
        overlay.className = 'dd-mobile-search-overlay';
        overlay.id = 'ddMobileSearchOverlay';
        document.body.appendChild(overlay);

        function open() {
            panel.classList.add('open');
            overlay.classList.add('open');
            panel.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            ensureProducts(function () {}); // preload
            setTimeout(function () {
                input.value = '';
                input.focus();
            }, 80);
        }

        function close() {
            panel.classList.remove('open');
            overlay.classList.remove('open');
            panel.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            if (dropdown) { dropdown.innerHTML = ''; dropdown.classList.remove('open'); }
            input.value = '';
            input.blur();
        }

        trigger.addEventListener('click', open);
        if (closeBtn) closeBtn.addEventListener('click', close);
        overlay.addEventListener('click', close);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && panel.classList.contains('open')) close();
        });

        /* Input: real-time results */
        input.addEventListener('input', function () {
            var q = this.value.trim();
            filterCards(q);

            if (!q) {
                dropdown.innerHTML = '';
                dropdown.classList.remove('open');
                return;
            }

            ensureProducts(function () {
                var results = match(q, 8);
                dropdown.innerHTML = buildCards(results, q);
                dropdown.classList.toggle('open', results.length > 0 || q.length > 0);
            });
        });

        /* Click on result */
        if (dropdown) {
            dropdown.addEventListener('click', function (e) {
                var card = e.target.closest('.dd-ss__result-card');
                if (!card) return;
                var pid = card.dataset.pid;
                close();
                if (pid) openModal(pid);
            });
        }

        /* Enter */
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                var q = this.value.trim();
                if (q.length >= 5 && window.DDTrack) window.DDTrack.search(q);
                close();
            }
            if (e.key === 'Escape') close();
        });
    }

    /* ════════════════════════════════════════════════
       PRODUCT DATA API
       Lets other modules request product data by ID
       without sharing scope or variables
    ════════════════════════════════════════════════ */
    document.addEventListener('dd:get-product', function (e) {
        var pid = e.detail && e.detail.productId;
        if (!pid) return;

        function respond() {
            var found = null;
            for (var i = 0; i < products.length; i++) {
                if (String(products[i].id) === String(pid)) {
                    found = products[i]; break;
                }
            }
            document.dispatchEvent(new CustomEvent('dd:product-data', {
                detail: found || null
            }));
        }

        if (productsReady) {
            respond();
        } else {
            ensureProducts(respond);
        }
    });

    /* ════════════════════════════════════════════════
       BOOT — runs when DOM is ready
    ════════════════════════════════════════════════ */
    function boot() {
        readFromDOM();          // grab products from page if available
        initDesktop();          // desktop search bar
        initMobile();           // mobile expandable search
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

})();
