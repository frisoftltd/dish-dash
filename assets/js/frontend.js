/**
 * File:    assets/js/frontend.js
 * Purpose: All interactivity for the DishDash full page template
 *          (templates/page-dishdash.php) — sticky header scroll shrink,
 *          mobile nav drawer, hero chip filters, category tab navigation
 *          with AJAX product loading, featured dish scroll rows, reserve
 *          form, product modal, cart badge updates, and summary sidebar.
 *
 * DOM elements required:
 *   - #ddCartCount, #ddFloatingCount, #ddBottomBadge, #ddSumBadge (cart badges)
 *   - #ddSummaryList, #ddSumSubtotal, #ddSumTotal (summary sidebar)
 *   - .dd-header (.scrolled class added on scroll)
 *   - .dd-menu-toggle, .dd-nav-drawer, .dd-drawer-overlay (mobile nav)
 *   - .dd-filter-btn--active (homepage filter chips)
 *   - .dd-dish-card (product cards, click → opens product modal)
 *   - .dd-add-btn (add-to-cart on homepage cards)
 *   - .dd-product-modal, .dd-product-modal.open (product detail modal)
 *
 * Localized data needed (wp_localize_script):
 *   - window.DD  (firstCat, deliveryFee, cartCount — set inline in template)
 *   - window.ddCartData  (ajax_url, nonce — localized by cart module)
 *
 * AJAX endpoints called:
 *   - admin-ajax.php?action=dd_cart_add
 *   - admin-ajax.php?action=dd_get_product
 *
 * Custom events listened to:
 *   - dd:open-modal    (detail: { productId }) — fired by search.js
 *   - dd:filter-cards  (detail: { query })     — fired by search.js
 *
 * Search (smart search, mobile search) is handled exclusively by search.js.
 *
 * Dependents:
 *   - modules/template/class-dd-template-module.php (enqueues this)
 *   - templates/page-dishdash.php (relies on this for all interactivity)
 *
 * Last modified: v3.2.16
 */
(function () {
    'use strict';

    /* ══════════════════════════════════════════════════════════
       STATE
    ══════════════════════════════════════════════════════════ */
    window.DD = window.DD || {};

    let activeSlug  = window.DD ? window.DD.firstCat : '';
    let deliveryFee = window.DD ? parseInt(window.DD.deliveryFee, 10) : 2000;
    let cartCount   = window.DD ? parseInt(window.DD.cartCount, 10) : 0;

    // Local cart mirror (keeps summary sidebar in sync without full page reload)
    let cartItems = [];

    /* ══════════════════════════════════════════════════════════
       HELPERS
    ══════════════════════════════════════════════════════════ */
    const $ = (id) => document.getElementById(id);
    const $q = (sel, scope = document) => scope.querySelector(sel);
    const $all = (sel, scope = document) => [...scope.querySelectorAll(sel)];

    const fmt = (n) => 'RWF ' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 });

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function parseRWF(str) {
        return parseInt((str || '').replace(/[^\d]/g, ''), 10) || 0;
    }

    function showToast(msg) {
        var t = document.getElementById('ddToast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'ddToast';
            t.style.cssText = 'position:fixed;bottom:90px;right:20px;background:#65040d;color:#fff;' +
                'padding:12px 20px;border-radius:12px;font-size:14px;font-weight:600;' +
                'z-index:99999;opacity:0;transition:opacity .3s;pointer-events:none;' +
                'box-shadow:0 4px 16px rgba(0,0,0,0.2);';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.style.opacity = '1';
        clearTimeout(t._hide);
        t._hide = setTimeout(function() { t.style.opacity = '0'; }, 2500);
    }
    window.showToast = showToast;

    /* ── Attribute pill + card hover CSS (injected once) ── */
    (function() {
        var style = document.createElement('style');
        style.textContent = [
            '.dd-pm__attr-pill {',
            '  display:inline-block;',
            '  padding:6px 14px;',
            '  border:1.5px solid #e0d5c5 !important;',
            '  border-radius:20px !important;',
            '  background:#fff !important;',
            '  cursor:pointer;',
            '  font-size:13px;',
            '  font-family:inherit;',
            '  transition:all .2s;',
            '  margin:4px;',
            '}',
            '.dd-pm__attr-pill.active {',
            '  background:#65040d !important;',
            '  color:#fff !important;',
            '  border-color:#65040d !important;',
            '}',
            '.dd-pm__attr-group { margin-bottom:12px; }',
            '.dd-pm__attr-label { font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#8a7a66; margin-bottom:6px; }',
            '.dd-pm__attr-pills { display:flex; flex-wrap:wrap; gap:6px; }',
            '.dd-dish-card { cursor:pointer; transition:transform .2s, box-shadow .2s; }',
            '.dd-dish-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,0.12); }',
            '.dd-menu-item { cursor:pointer; transition:transform .2s, box-shadow .2s; }',
            '.dd-menu-item:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,0.12); }',
        ].join('\n');
        document.head.appendChild(style);
    })();

    /* ══════════════════════════════════════════════════════════
       CART BADGE SYNC
    ══════════════════════════════════════════════════════════ */
    function updateBadges(count) {
        cartCount = count;
        [$('ddCartCount'), $('ddFloatingCount'), $('ddBottomBadge'), $('ddSumBadge')]
            .forEach(function(el) { if (el) el.textContent = count; });
    }

    /* ══════════════════════════════════════════════════════════
       SUMMARY SIDEBAR
    ══════════════════════════════════════════════════════════ */
    function renderSummary() {
        const listEl = $('ddSummaryList');
        if (!listEl) return;

        const subtotal = cartItems.reduce((s, i) => s + i.price * i.qty, 0);
        const total    = subtotal + deliveryFee;

        const subEl = $('ddSumSubtotal');
        const totEl = $('ddSumTotal');
        if (subEl) subEl.textContent = fmt(subtotal);
        if (totEl) totEl.textContent = fmt(total);

        if (!cartItems.length) {
            listEl.innerHTML = '<div class="dd-summary__empty">Your cart is empty</div>';
            return;
        }

        listEl.innerHTML = cartItems.map((item) => `
            <div class="dd-summary__item">
                <div>
                    <h4>${escHtml(item.name)}</h4>
                    <p>${item.qty} × ${fmt(item.price)}</p>
                </div>
                <span style="color:var(--dd-gold-soft);font-size:13px;">×${item.qty}</span>
            </div>
        `).join('');
    }

    /* ══════════════════════════════════════════════════════════
       CART HELPERS
    ══════════════════════════════════════════════════════════ */
    function openCart() {
        const overlay = $('ddCartOverlay');
        const drawer  = $('ddCartDrawer');
        if (overlay) overlay.classList.add('dd-cart-drawer-overlay--visible');
        if (drawer)  drawer.classList.add('dd-cart-drawer--open');
        document.body.classList.add('dd-cart-open');
        if (typeof window.DDCart !== 'undefined') window.DDCart.refresh();
    }

    function closeCart() {
        const overlay = $('ddCartOverlay');
        const drawer  = $('ddCartDrawer');
        if (overlay) overlay.classList.remove('dd-cart-drawer-overlay--visible');
        if (drawer)  {
            drawer.classList.remove('dd-cart-drawer--open');
            drawer.classList.remove('open');
        }
        document.body.classList.remove('dd-cart-open');
    }

    /* ══════════════════════════════════════════════════════════
       ADD TO CART — WooCommerce AJAX
    ══════════════════════════════════════════════════════════ */
    function addToCart(productId, quantity, btn) {
        quantity = quantity || 1;

        var ajaxUrl = (window.ddCartData && window.ddCartData.ajax_url)
            ? window.ddCartData.ajax_url
            : (window.DD && window.DD.ajaxUrl) || '/wp-admin/admin-ajax.php';
        var nonce = (window.ddCartData && window.ddCartData.nonce)
            ? window.ddCartData.nonce
            : (window.DD && window.DD.nonce) || '';

        btn.classList.add('loading');
        btn.textContent = 'Adding…';

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action:     'dd_cart_add',
                nonce:      nonce,
                product_id: productId,
                quantity:   quantity,
            }),
        })
        .then((r) => r.json())
        .then((res) => {
            btn.classList.remove('loading');

            if (res.success) {
                const newCount = res.data && res.data.count !== undefined
                    ? res.data.count : cartCount + quantity;
                updateBadges(newCount);

                if (res.data && res.data.items) cartItems = res.data.items;
                renderSummary();

                if (typeof window.DDCart !== 'undefined') window.DDCart.refresh();

                btn.textContent = '✓ Added!';
                showToast('✓ Added to cart!');
                setTimeout(() => { btn.textContent = 'Add to cart'; }, 1800);

            } else {
                btn.textContent = 'Add to cart';
                console.warn('[DishDash] Add to cart failed:', res);
            }
        })
        .catch(() => {
            btn.classList.remove('loading');
            btn.textContent = 'Add to cart';
        });
    }

    function removeFromCart(productId) {
        var ajaxUrl = (window.ddCartData && window.ddCartData.ajax_url)
            ? window.ddCartData.ajax_url
            : (window.DD && window.DD.ajaxUrl) || '/wp-admin/admin-ajax.php';
        var nonce = (window.ddCartData && window.ddCartData.nonce)
            ? window.ddCartData.nonce
            : (window.DD && window.DD.nonce) || '';

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action:     'dd_remove_from_cart',
                product_id: productId,
                nonce:      nonce,
            }).toString(),
        })
        .then((r) => r.json())
        .then((res) => {
            if (res.success) {
                const newCount = res.data && res.data.count !== undefined
                    ? res.data.count : Math.max(0, cartCount - 1);
                updateBadges(newCount);

                if (res.data && res.data.items) {
                    cartItems = res.data.items;
                } else {
                    cartItems = cartItems.filter((i) => i.id !== productId);
                }

                renderSummary();
                if (typeof window.DDCart !== 'undefined') window.DDCart.refresh();
            }
        })
        .catch((e) => console.warn('[DishDash] Remove failed', e));
    }

    /* ══════════════════════════════════════════════════════════
       CATEGORY SWITCHING
    ══════════════════════════════════════════════════════════ */
    function switchCategory(slug, name) {
        activeSlug = slug;

        const titleEl = $('ddCatTitle');
        if (titleEl) titleEl.textContent = name;

        $all('.dd-cat-row').forEach((row) => {
            if (row.dataset.slug === slug) {
                row.removeAttribute('hidden');
            } else {
                row.setAttribute('hidden', '');
            }
        });

        setupArrows('ddSelPrev', 'ddSelNext', 'ddCatRow-' + slug);

    }

    /* ══════════════════════════════════════════════════════════
       ARROW SCROLL BUTTONS
    ══════════════════════════════════════════════════════════ */
    function setupArrows(prevId, nextId, rowId) {
        var row  = document.getElementById(rowId);
        var prev = document.getElementById(prevId);
        var next = document.getElementById(nextId);
        if (!row || !prev || !next) return;

        if (prev.dataset.bound === rowId) return;
        prev.dataset.bound = rowId;
        next.dataset.bound = rowId;

        prev.addEventListener('click', function() { row.scrollBy({ left: -300, behavior: 'smooth' }); });
        next.addEventListener('click', function() { row.scrollBy({ left:  300, behavior: 'smooth' }); });
    }

    /* ══════════════════════════════════════════════════════════
       FILTER DISH CARDS (called by dd:filter-cards event from search.js)
    ══════════════════════════════════════════════════════════ */
    function filterDishCards(q) {
        const featRow = $('ddFeatRow');
        if (!featRow) return;

        $all('.dd-dish-card', featRow).forEach((card) => {
            const name = ($q('.dd-dish-card__title', card) || {}).textContent || '';
            const desc = ($q('.dd-dish-card__desc',  card) || {}).textContent || '';
            const tag  = ($q('.dd-dish-card__tag',   card) || {}).textContent || '';
            const matches = !q ||
                name.toLowerCase().includes(q) ||
                desc.toLowerCase().includes(q) ||
                tag.toLowerCase().includes(q);
            card.style.display = matches ? '' : 'none';
        });
    }

    /* ══════════════════════════════════════════════════════════
       FILTER CHIPS — real filtering on featured row
    ══════════════════════════════════════════════════════════ */
    function setupChips() {
        var chips   = $all('.dd-chip');
        var featRow = $('ddFeatRow');

        chips.forEach(function(chip) {
            chip.addEventListener('click', function() {
                chips.forEach(function(c) { c.classList.remove('active'); });
                chip.classList.add('active');

                var filter = (chip.dataset.filter || '').toLowerCase();
                if (!featRow) return;

                var existingBtn = featRow.nextElementSibling;
                if (existingBtn && existingBtn.classList.contains('dd-load-more-btn')) {
                    existingBtn.remove();
                }

                $all('.dd-dish-card', featRow).forEach(function(card) {
                    card.classList.remove('dd-card-hidden');
                    card.style.display = '';
                });

                if (!filter) {
                    if (window.innerWidth > 860) applyGridLoadMore(featRow, 'ddFeatLoadMoreFiltered', 8);
                    return;
                }

                var matching = [];
                $all('.dd-dish-card', featRow).forEach(function(card) {
                    var cardFilter = (card.dataset.filter || '').toLowerCase();
                    var matches = cardFilter.split(',').some(function(f) { return f.trim() === filter; });
                    if (matches) {
                        matching.push(card);
                    } else {
                        card.style.display = 'none';
                    }
                });

                if (window.innerWidth > 860 && matching.length > 8) {
                    matching.forEach(function(card, i) {
                        if (i >= 8) card.classList.add('dd-card-hidden');
                    });
                    applyGridLoadMore(featRow, 'ddFeatLoadMoreFiltered', 8);
                }
            });
        });
    }

    /* ══════════════════════════════════════════════════════════
       MODE BUTTONS (Delivery / Pickup)
    ══════════════════════════════════════════════════════════ */
    function setupModeButtons() {
        var currentMode = 'delivery';
        $all('.dd-mode-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                $all('.dd-mode-btn').forEach(function(b) { b.classList.remove('active'); });
                btn.classList.add('active');
                currentMode = btn.dataset.mode || 'delivery';

                var fee = currentMode === 'pickup' ? 0 : deliveryFee;
                var delRow    = $('ddSumDelivery');   if (delRow)    delRow.textContent    = fee === 0 ? 'Free' : fmt(fee);
                var drawerDel = $('ddDrawerDelivery'); if (drawerDel) drawerDel.textContent = fee === 0 ? 'Free' : fmt(fee);

                var sub  = cartItems.reduce(function(s, i) { return s + i.price * i.qty; }, 0);
                var tot  = sub + fee;
                var subEl = $('ddSumSubtotal');   if (subEl) subEl.textContent = fmt(sub);
                var totEl = $('ddSumTotal');       if (totEl) totEl.textContent = fmt(tot);
                var dSub  = $('ddDrawerSubtotal'); if (dSub)  dSub.textContent  = fmt(sub);
                var dTot  = $('ddDrawerTotal');    if (dTot)  dTot.textContent  = fmt(tot);
            });
        });
    }

    /* ══════════════════════════════════════════════════════════
       DESKTOP GRID + LOAD MORE
    ══════════════════════════════════════════════════════════ */
    function setupDesktopGrid() {
        if (window.innerWidth <= 860) return;

        var PER_PAGE = 8;
        var featRow = $('ddFeatRow');
        if (featRow) applyGridLoadMore(featRow, 'ddFeatLoadMore', PER_PAGE);

        $all('.dd-cat-row').forEach(function(row) {
            applyGridLoadMore(row, row.id + 'LoadMore', PER_PAGE);
        });
    }

    function applyGridLoadMore(row, btnId, perPage) {
        var cards = Array.from(row.querySelectorAll('.dd-dish-card'));
        if (cards.length <= perPage) return;

        cards.forEach(function(card, i) {
            if (i >= perPage) card.classList.add('dd-card-hidden');
        });

        var btn = document.createElement('button');
        btn.id        = btnId;
        btn.className = 'dd-load-more-btn';
        btn.innerHTML = 'Load More <span class="dd-load-more-icon">↓</span>';
        row.parentNode.insertBefore(btn, row.nextSibling);

        btn.addEventListener('click', function() {
            Array.from(row.querySelectorAll('.dd-dish-card.dd-card-hidden'))
                .slice(0, perPage)
                .forEach(function(card) { card.classList.remove('dd-card-hidden'); });

            if (row.querySelectorAll('.dd-dish-card.dd-card-hidden').length === 0) {
                btn.remove();
            }
        });
    }

    /* ══════════════════════════════════════════════════════════
       STICKY HEADER
    ══════════════════════════════════════════════════════════ */
    function setupStickyHeader() {
        var header = document.querySelector('.dd-header');
        if (!header) return;
        window.addEventListener('scroll', function() {
            header.classList.toggle('scrolled', (window.pageYOffset || document.documentElement.scrollTop) > 50);
        }, { passive: true });
    }

    /* ══════════════════════════════════════════════════════════
       OPENING HOURS BANNERS
    ══════════════════════════════════════════════════════════ */
    function setupHoursBanner() {
        var DD         = window.DD || {};
        var state      = (DD.hours_state || 'open');
        var nextOpenTs = parseInt(DD.next_open_ts || 0, 10) * 1000;
        var closeTs    = parseInt(DD.close_ts || 0, 10) * 1000;
        var waNumber   = (DD.whatsapp_admin || '').replace(/\D/g, '');
        var menuUrl    = DD.menu_url || '/restaurant-menu/';

        if (state === 'open') return;
        if (sessionStorage.getItem('dd_banner_hidden') === '1') return;

        var isHomepage = document.querySelector('.dd-page') !== null;

        // Inject banner styles if not already present
        if (!document.getElementById('dd-hours-styles')) {
            var style = document.createElement('style');
            style.id = 'dd-hours-styles';
            style.textContent = [
                '.dd-strip-banner{display:flex;align-items:center;justify-content:center;gap:20px;background:#c0392b;color:#fff;padding:10px 20px;font-size:14px;flex-wrap:wrap;position:relative;z-index:9999;width:100%;box-sizing:border-box;}',
                '.dd-strip-banner__label{font-weight:600;}',
                '.dd-strip-banner__countdown{display:flex;align-items:center;gap:10px;}',
                '.dd-strip-unit{display:flex;align-items:center;gap:5px;}',
                '.dd-strip-num{font-size:20px;font-weight:700;min-width:28px;text-align:center;}',
                '.dd-strip-unit-label{font-size:12px;opacity:.85;text-transform:uppercase;letter-spacing:.5px;}',
                '.dd-strip-sep{opacity:.5;font-size:16px;}',
                '.dd-strip-banner__hide{background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);color:#fff;padding:5px 14px;border-radius:4px;font-size:13px;cursor:pointer;}',
                '.dd-closed-overlay{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;}',
                '.dd-closed-modal{background:#fff;border-radius:12px;padding:40px 32px 36px;max-width:520px;width:100%;text-align:center;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.4);}',
                '.dd-closed-modal__close{position:absolute;top:14px;right:18px;background:none;border:none;font-size:20px;cursor:pointer;color:#999;padding:4px 8px;}',
                '.dd-closed-modal__intro{font-size:16px;color:#333;margin:0 0 6px;font-weight:500;}',
                '.dd-closed-modal__sub{font-size:14px;color:#777;margin:0 0 28px;}',
                '.dd-closed-modal__circles{display:flex;justify-content:center;gap:20px;margin-bottom:32px;}',
                '.dd-circle{display:flex;flex-direction:column;align-items:center;justify-content:center;width:110px;height:110px;border-radius:50%;background:linear-gradient(135deg,#65040d,#a00015);color:#fff;box-shadow:0 4px 16px rgba(101,4,13,.35);}',
                '.dd-circle__num{font-size:32px;font-weight:700;line-height:1;}',
                '.dd-circle__label{font-size:11px;text-transform:uppercase;letter-spacing:.8px;opacity:.85;margin-top:4px;}',
                '.dd-closed-modal__actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}',
                '.dd-closed-btn{display:inline-flex;align-items:center;gap:6px;padding:11px 22px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer;}',
                '.dd-closed-btn--ghost{border:2px solid #65040d;color:#65040d;background:transparent;}',
                '.dd-closed-btn--wa{background:#25D366;color:#fff;border:2px solid transparent;}',
                '.dd-bottom-strip{position:fixed;bottom:0;left:0;right:0;z-index:99998;background:#6b1d1d;color:#fff;padding:12px 24px;display:flex;align-items:center;justify-content:center;gap:20px;font-size:14px;}',
                '.dd-bottom-strip__text{font-weight:500;}',
                '.dd-bottom-strip__text span{font-weight:700;color:#E8832A;}',
                '.dd-bottom-strip__hide{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;padding:5px 14px;border-radius:4px;font-size:13px;cursor:pointer;}',
                '.dd-add-btn--closed,.dd-add-btn--closed:hover,.dd-mobile-product-card__quick-add.dd-add-btn--closed,.dd-mobile-product-card__quick-add.dd-add-btn--closed:hover{background:#cccccc!important;color:#888888!important;cursor:not-allowed!important;pointer-events:none!important;border-color:#cccccc!important;}',
                '@media(max-width:600px){.dd-circle{width:82px;height:82px;}.dd-circle__num{font-size:24px;}.dd-closed-modal{padding:32px 20px 28px;}.dd-closed-modal__circles{gap:12px;}}'
            ].join('');
            document.head.appendChild(style);
        }

        var timerInterval = null;

        function formatCountdown(ms) {
            // Only reload if we just ticked past zero — not if nextOpenTs was never set (0)
            if (ms <= 0 && ms > -10000) { location.reload(); return { h:'00', m:'00', s:'00' }; }
            if (ms <= 0) { return { h:'00', m:'00', s:'00' }; }
            var total = Math.floor(ms / 1000);
            var h = Math.floor(total / 3600);
            var m = Math.floor((total % 3600) / 60);
            var s = total % 60;
            return {
                h: String(h).padStart(2, '0'),
                m: String(m).padStart(2, '0'),
                s: String(s).padStart(2, '0')
            };
        }

        // ── STRIP (closing_soon + break) ─────────────────────────────────────
        if (state === 'closing_soon' || state === 'break') {
            var targetTs = (state === 'closing_soon') ? closeTs : nextOpenTs;
            var label    = (state === 'closing_soon')
                ? 'We close soon \u2014 Order now!'
                : 'We\'re on a break \u2014 Back open soon';

            var strip = document.createElement('div');
            strip.className = 'dd-strip-banner';
            strip.innerHTML =
                '<span class="dd-strip-banner__label">' + label + '</span>' +
                '<span class="dd-strip-banner__countdown">' +
                    '<span class="dd-strip-unit"><span class="dd-strip-num" id="ddStripH">00</span><span class="dd-strip-unit-label">Hours</span></span>' +
                    '<span class="dd-strip-sep">|</span>' +
                    '<span class="dd-strip-unit"><span class="dd-strip-num" id="ddStripM">00</span><span class="dd-strip-unit-label">Minutes</span></span>' +
                    '<span class="dd-strip-sep">|</span>' +
                    '<span class="dd-strip-unit"><span class="dd-strip-num" id="ddStripS">00</span><span class="dd-strip-unit-label">Seconds</span></span>' +
                '</span>' +
                '<button class="dd-strip-banner__hide" id="ddStripHide">Hide Message</button>';

            document.body.insertBefore(strip, document.body.firstChild);

            function tickStrip() {
                var diff = targetTs - Date.now();
                var t    = formatCountdown(diff);
                var elH  = document.getElementById('ddStripH');
                var elM  = document.getElementById('ddStripM');
                var elS  = document.getElementById('ddStripS');
                if (elH) elH.textContent = t.h;
                if (elM) elM.textContent = t.m;
                if (elS) elS.textContent = t.s;
            }
            tickStrip();
            timerInterval = setInterval(tickStrip, 1000);

            document.getElementById('ddStripHide').addEventListener('click', function() {
                sessionStorage.setItem('dd_banner_hidden', '1');
                strip.remove();
                if (timerInterval) clearInterval(timerInterval);
            });
        }

        // ── MODAL (closed) ───────────────────────────────────────────────────
        if (state === 'closed') {
            if (isHomepage) {
                var overlay = document.createElement('div');
                overlay.className = 'dd-closed-overlay';
                overlay.innerHTML =
                    '<div class="dd-closed-modal">' +
                        '<button class="dd-closed-modal__close" id="ddClosedX">&#x2715;</button>' +
                        '<p class="dd-closed-modal__intro">Sorry, we are not taking orders right now.</p>' +
                        '<p class="dd-closed-modal__sub">We will start taking orders in</p>' +
                        '<div class="dd-closed-modal__circles">' +
                            '<div class="dd-circle"><span class="dd-circle__num" id="ddModalH">00</span><span class="dd-circle__label">Hours</span></div>' +
                            '<div class="dd-circle"><span class="dd-circle__num" id="ddModalM">00</span><span class="dd-circle__label">Minutes</span></div>' +
                            '<div class="dd-circle"><span class="dd-circle__num" id="ddModalS">00</span><span class="dd-circle__label">Seconds</span></div>' +
                        '</div>' +
                        '<div class="dd-closed-modal__actions">' +
                            (waNumber ? '<a href="https://wa.me/' + waNumber + '" target="_blank" rel="noopener" class="dd-closed-btn dd-closed-btn--wa"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:6px;"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg> Message Us</a>' : '') +
                        '</div>' +
                    '</div>';

                document.body.appendChild(overlay);

                function tickModal() {
                    var diff = nextOpenTs - Date.now();
                    var t    = formatCountdown(diff);
                    var elH  = document.getElementById('ddModalH');
                    var elM  = document.getElementById('ddModalM');
                    var elS  = document.getElementById('ddModalS');
                    if (elH) elH.textContent = t.h;
                    if (elM) elM.textContent = t.m;
                    if (elS) elS.textContent = t.s;
                }
                tickModal();
                timerInterval = setInterval(tickModal, 1000);

                document.getElementById('ddClosedX').addEventListener('click', function() {
                    overlay.remove();
                    if (timerInterval) clearInterval(timerInterval);

                    var bottomStrip = document.createElement('div');
                    bottomStrip.className = 'dd-bottom-strip';
                    bottomStrip.innerHTML =
                        '<span class="dd-bottom-strip__text">We\'re Closed \u2014 Opens in ' +
                            '<span id="ddBottomH">00</span>h ' +
                            '<span id="ddBottomM">00</span>m ' +
                            '<span id="ddBottomS">00</span>s' +
                        '</span>' +
                        '<button class="dd-bottom-strip__hide" id="ddBottomHide">Hide Message</button>';
                    document.body.appendChild(bottomStrip);

                    var bottomTimer = setInterval(function() {
                        var diff = nextOpenTs - Date.now();
                        if (diff <= 0) { location.reload(); return; }
                        var t = formatCountdown(diff);
                        var elH = document.getElementById('ddBottomH');
                        var elM = document.getElementById('ddBottomM');
                        var elS = document.getElementById('ddBottomS');
                        if (elH) elH.textContent = t.h;
                        if (elM) elM.textContent = t.m;
                        if (elS) elS.textContent = t.s;
                    }, 1000);
                    (function() {
                        var diff = nextOpenTs - Date.now();
                        var t = formatCountdown(diff);
                        var elH = document.getElementById('ddBottomH');
                        var elM = document.getElementById('ddBottomM');
                        var elS = document.getElementById('ddBottomS');
                        if (elH) elH.textContent = t.h;
                        if (elM) elM.textContent = t.m;
                        if (elS) elS.textContent = t.s;
                    })();

                    document.getElementById('ddBottomHide').addEventListener('click', function() {
                        sessionStorage.setItem('dd_banner_hidden', '1');
                        bottomStrip.remove();
                        clearInterval(bottomTimer);
                        if (timerInterval) clearInterval(timerInterval);
                    });
                });
            } else {
                // Non-homepage: show bottom strip directly (no modal)
                var bottomStrip = document.createElement('div');
                bottomStrip.className = 'dd-bottom-strip';
                bottomStrip.innerHTML =
                    '<span class="dd-bottom-strip__text">We\'re Closed \u2014 Opens in ' +
                        '<span id="ddBottomH">00</span>h ' +
                        '<span id="ddBottomM">00</span>m ' +
                        '<span id="ddBottomS">00</span>s' +
                    '</span>' +
                    '<button class="dd-bottom-strip__hide" id="ddBottomHide">Hide Message</button>';
                document.body.appendChild(bottomStrip);

                var bottomTimer = setInterval(function() {
                    var diff = nextOpenTs - Date.now();
                    if (diff <= 0) { location.reload(); return; }
                    var t = formatCountdown(diff);
                    var elH = document.getElementById('ddBottomH');
                    var elM = document.getElementById('ddBottomM');
                    var elS = document.getElementById('ddBottomS');
                    if (elH) elH.textContent = t.h;
                    if (elM) elM.textContent = t.m;
                    if (elS) elS.textContent = t.s;
                }, 1000);
                (function() {
                    var diff = nextOpenTs - Date.now();
                    var t = formatCountdown(diff);
                    var elH = document.getElementById('ddBottomH');
                    var elM = document.getElementById('ddBottomM');
                    var elS = document.getElementById('ddBottomS');
                    if (elH) elH.textContent = t.h;
                    if (elM) elM.textContent = t.m;
                    if (elS) elS.textContent = t.s;
                })();

                document.getElementById('ddBottomHide').addEventListener('click', function() {
                    sessionStorage.setItem('dd_banner_hidden', '1');
                    bottomStrip.remove();
                    clearInterval(bottomTimer);
                });
            }
        }

        // ── DISABLE ADD TO CART when closed or break ─────────────────────────
        if (state === 'closed' || state === 'break') {
            document.querySelectorAll('.dd-add-btn').forEach(function(btn) {
                btn.disabled = true;
                btn.textContent = 'We\'re Closed';
                btn.classList.add('dd-add-btn--closed');
            });

            // Also disable the product modal Add to Cart button
            var modalAddBtn = document.getElementById('ddPmAddBtn');
            if (modalAddBtn) {
                modalAddBtn.disabled = true;
                modalAddBtn.textContent = "We're Closed";
                modalAddBtn.classList.add('dd-add-btn--closed');
            }

            // Re-disable whenever modal opens (for dynamically loaded modal)
            document.addEventListener('dd:open-modal', function() {
                setTimeout(function() {
                    var btn = document.getElementById('ddPmAddBtn');
                    if (btn) {
                        btn.disabled = true;
                        btn.textContent = "We're Closed";
                        btn.classList.add('dd-add-btn--closed');
                    }
                }, 600);
            });
        }
    }

    /* ══════════════════════════════════════════════════════════
       MOBILE NAV DRAWER
    ══════════════════════════════════════════════════════════ */
    function setupMobileNav() {
        var toggle  = $('ddMenuToggle');
        var drawer  = $('ddNavDrawer');
        var overlay = $('ddDrawerOverlay');
        var closeBtn= $('ddDrawerClose');

        if (!toggle || !drawer) return;

        function openDrawer() {
            drawer.classList.add('open');
            if (overlay) overlay.classList.add('open');
            toggle.classList.add('open');
            toggle.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }

        function closeDrawer() {
            drawer.classList.remove('open');
            if (overlay) overlay.classList.remove('open');
            toggle.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }

        toggle.addEventListener('click', function() {
            drawer.classList.contains('open') ? closeDrawer() : openDrawer();
        });

        if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
        if (overlay)  overlay.addEventListener('click', closeDrawer);
        $all('a', drawer).forEach(function(link) { link.addEventListener('click', closeDrawer); });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeDrawer(); });
    }

    /* ══════════════════════════════════════════════════════════
       MOBILE BOTTOM NAV ACTIVE STATE
    ══════════════════════════════════════════════════════════ */
    function setupBottomNav() {
        const links    = $all('.dd-bottom-link[data-target]');
        const sections = ['home', 'categories', 'menu', 'reserve'];

        function setActive() {
            let current = 'home';
            sections.forEach((id) => {
                const el = document.getElementById(id);
                if (!el) return;
                if (el.getBoundingClientRect().top <= 120) current = id;
            });
            if (current === 'categories') current = 'menu';
            links.forEach((link) => {
                link.classList.toggle('active', link.dataset.target === current);
            });
        }

        links.forEach((link) => {
            link.addEventListener('click', () => {
                links.forEach((l) => l.classList.remove('active'));
                link.classList.add('active');
            });
        });

        window.addEventListener('scroll', setActive, { passive: true });
        setActive();
    }

    /* ══════════════════════════════════════════════════════════
       CART CONTROLS
    ══════════════════════════════════════════════════════════ */
    function setupCartControls() {
        // #ddFloatingCart and #ddCloseCart no longer exist — removed
        [$('ddCartTopBtn'), $('ddBottomCartBtn')]
            .forEach((btn) => btn && btn.addEventListener('click', openCart));

        const overlay = $('ddCartOverlay');
        if (overlay) overlay.addEventListener('click', closeCart);
    }

    /* ══════════════════════════════════════════════════════════
       SMOOTH SCROLL
    ══════════════════════════════════════════════════════════ */
    function setupSmoothScroll() {
        $all('a[href^="#"]').forEach((link) => {
            link.addEventListener('click', (e) => {
                const id = link.getAttribute('href').slice(1);
                const target = document.getElementById(id);
                if (!target) return;
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        const startOrderBtn = $('ddStartOrder');
        if (startOrderBtn) {
            startOrderBtn.addEventListener('click', (e) => {
                e.preventDefault();
                const menu = $('menu');
                if (menu) menu.scrollIntoView({ behavior: 'smooth' });
            });
        }
    }

    /* ══════════════════════════════════════════════════════════
       INIT
    ══════════════════════════════════════════════════════════ */
    function init() {
        $all('.dd-cat-card').forEach((card) => {
            card.addEventListener('click', () => {
                $all('.dd-cat-card').forEach((c) => c.classList.remove('active'));
                card.classList.add('active');
                switchCategory(card.dataset.slug, card.dataset.name);
                if (card.dataset.url && card.dataset.url !== '#') {
                    window.location.href = card.dataset.url;
                }
            });
        });

        $all('.dd-selcat__tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                $all('.dd-selcat__tab').forEach(function(t) { t.classList.remove('active'); });
                tab.classList.add('active');
                switchCategory(tab.dataset.slug, tab.dataset.name);
                setupArrows('ddSelCatPrev', 'ddSelCatNext', 'ddCatRow-' + tab.dataset.slug);
            });
        });

        if (activeSlug) setupArrows('ddSelPrev', 'ddSelNext', 'ddCatRow-' + activeSlug);
        setupArrows('ddFeatPrev', 'ddFeatNext', 'ddFeatRow');
        setupArrows('ddCatPrev',  'ddCatNext',  'ddCatScrollRow');

        var activeSelCatRow = document.querySelector('.dd-cat-row:not([hidden])');
        if (activeSelCatRow) setupArrows('ddSelCatPrev', 'ddSelCatNext', activeSelCatRow.id);

        setupDesktopGrid();
        setupModeButtons();
        setupChips();
        setupMobileNav();
        setupBottomNav();
        setupCartControls();
        setupSmoothScroll();
        setupStickyHeader();
        setupHoursBanner();

        renderSummary();
        loadReviews();
        setupProductModal();

        // Listen for dd:filter-cards from search.js
        document.addEventListener('dd:filter-cards', function(e) {
            filterDishCards(e.detail ? e.detail.query : '');
        });

        // Sync cart count from WooCommerce fragments (if available)
        document.body.addEventListener('wc_fragments_refreshed', syncCartFromFragments);
        document.body.addEventListener('wc_cart_button_updated', syncCartFromFragments);
        document.body.addEventListener('added_to_cart', (e) => {
            const fragments = e.detail;
            if (fragments && fragments.cart_count !== undefined) updateBadges(fragments.cart_count);
        });
    }

    /* ══════════════════════════════════════════════════════════
       PRODUCT MODAL
       Opens when user clicks a product card or a search result.
       Basic data from DOM is shown immediately; attributes and
       ratings are fetched via dd_get_product AJAX and injected.
    ══════════════════════════════════════════════════════════ */
    function openProductModal(productId) {
        var modal   = $('ddProductModal');
        var content = $('ddProductModalContent');
        if (!modal || !content) return;

        // 1. Try to find card in DOM first
        var card = document.querySelector('.dd-dish-card[data-id="' + productId + '"]')
                || document.querySelector('.dd-menu-item[data-id="' + productId + '"]');

        if (card) {
            var isDishCard = card.classList.contains('dd-dish-card');
            var name   = ((isDishCard ? card.querySelector('.dd-dish-card__title') : card.querySelector('.dd-menu-item__name')) || {}).textContent || '';
            var price  = ((isDishCard ? card.querySelector('.dd-price') : card.querySelector('.dd-menu-item__price')) || {}).textContent || '';
            var desc   = ((isDishCard ? card.querySelector('.dd-dish-card__desc') : card.querySelector('.dd-menu-item__desc')) || {}).textContent || '';
            var imgEl  = card.querySelector('img');
            var imgSrc = imgEl ? imgEl.src : '';
            renderModal(productId, name, price, desc, imgSrc);
        } else {
            // 2. No card in DOM — fetch directly from server (handles search results
            //    on pages where products are not rendered in the DOM, e.g. /restaurant-menu/)
            var ajaxUrl = (window.ddCartData && window.ddCartData.ajax_url) || (window.DD && window.DD.ajaxUrl) || '/wp-admin/admin-ajax.php';
            var nonce   = (window.ddCartData && window.ddCartData.nonce)    || (window.DD && window.DD.nonce)   || '';
            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'dd_get_product', product_id: productId, nonce: nonce })
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.success || !res.data) return;
                var p = res.data;
                renderModal(productId, p.name || '', 'RWF ' + (p.price || ''), p.description || '', p.image || '');
            })
            .catch(function() {});
        }

    function renderModal(productId, name, price, desc, imgSrc) {
        var modal   = $('ddProductModal');
        var content = $('ddProductModalContent');
        if (!modal || !content) return;

        // Fire tracking event on open
        if (typeof DDTrack !== 'undefined') {
            DDTrack.event('view_product', productId, null, { source: 'modal' });
        }

        content.innerHTML =
            '<div class="dd-pm__img-wrap">' +
                (imgSrc
                    ? '<img src="' + escHtml(imgSrc) + '" alt="' + escHtml(name) + '" style="width:100%;border-radius:16px 16px 0 0;object-fit:cover;max-height:240px;display:block;">'
                    : '<div class="dd-pm__img-placeholder">&#127869;</div>') +
            '</div>' +
            '<div class="dd-pm__body">' +
                '<h2 class="dd-pm__name dd-serif" style="font-size:1.3rem;font-weight:800;margin:0 0 0.35rem;color:#221B19;">' + escHtml(name) + '</h2>' +
                '<div class="dd-pm__rating" id="ddPmRating" style="font-size:0.82rem;color:#7A6558;margin-bottom:0.4rem;min-height:18px;"></div>' +
                '<div class="dd-pm__price" style="font-size:1.1rem;font-weight:800;color:#E8832A;margin-bottom:0.75rem;">' + escHtml(price) + '</div>' +
                (desc ? '<p class="dd-pm__desc" style="font-size:0.88rem;color:#7A6558;margin:0 0 1rem;line-height:1.5;">' + escHtml(desc) + '</p>' : '') +
                '<div class="dd-pm__attrs" id="ddPmAttrs" style="margin-bottom:0.75rem;"></div>' +
                '<div class="dd-pm__notes-wrap" style="margin-bottom:1rem;">' +
                    '<textarea id="ddPmNotes" placeholder="Add special instructions (optional)..." rows="2" ' +
                        'style="width:100%;box-sizing:border-box;padding:0.6rem 0.85rem;border:2px solid #EAD9CE;border-radius:10px;font-size:0.85rem;font-family:inherit;resize:none;outline:none;background:#fff;color:#221B19;">' +
                    '</textarea>' +
                '</div>' +
                '<div class="dd-pm__footer" style="display:flex;align-items:center;gap:12px;">' +
                    '<div class="dd-pm__qty-wrap" style="display:flex;align-items:center;gap:8px;background:#F5EFE6;border-radius:999px;padding:4px 12px;">' +
                        '<button class="dd-pm__qty-btn" id="ddPmMinus" aria-label="Decrease" ' +
                            'style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#65040d;font-weight:700;line-height:1;padding:2px 4px;">&#8722;</button>' +
                        '<span class="dd-pm__qty" id="ddPmQty" style="font-weight:700;font-size:1rem;min-width:24px;text-align:center;">1</span>' +
                        '<button class="dd-pm__qty-btn" id="ddPmPlus" aria-label="Increase" ' +
                            'style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#65040d;font-weight:700;line-height:1;padding:2px 4px;">&#43;</button>' +
                    '</div>' +
                    '<button id="ddPmAddBtn" data-id="' + escHtml(productId) + '" ' +
                        'style="flex:1;background:#65040d;color:#fff;border:none;border-radius:999px;padding:0.75rem 1rem;font-size:0.95rem;font-weight:700;cursor:pointer;transition:background 0.2s;">' +
                        'Add to Cart' +
                    '</button>' +
                '</div>' +
            '</div>';

        // Quantity stepper
        var qty    = 1;
        var qtyEl  = $('ddPmQty');
        var minBtn = $('ddPmMinus');
        var plsBtn = $('ddPmPlus');
        var pmAdd  = $('ddPmAddBtn');

        if (minBtn) {
            minBtn.addEventListener('click', function() {
                if (qty > 1) { qty--; if (qtyEl) qtyEl.textContent = qty; }
            });
        }
        if (plsBtn) {
            plsBtn.addEventListener('click', function() {
                qty++;
                if (qtyEl) qtyEl.textContent = qty;
            });
        }

        if (pmAdd) {
            pmAdd.addEventListener('mouseover', function() { this.style.background = '#4a0209'; });
            pmAdd.addEventListener('mouseout',  function() { this.style.background = '#65040d'; });

            pmAdd.addEventListener('click', function() {
                var ajaxUrl = (window.ddCartData && window.ddCartData.ajax_url)
                    ? window.ddCartData.ajax_url
                    : (window.DD && window.DD.ajaxUrl) || '/wp-admin/admin-ajax.php';
                var nonce = (window.ddCartData && window.ddCartData.nonce)
                    ? window.ddCartData.nonce
                    : (window.DD && window.DD.nonce) || '';

                pmAdd.textContent = 'Adding…';
                pmAdd.disabled = true;

                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action:     'dd_cart_add',
                        nonce:      nonce,
                        product_id: productId,
                        quantity:   qty,
                    }),
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        var newCount = (res.data && res.data.count !== undefined)
                            ? res.data.count : cartCount + qty;
                        updateBadges(newCount);
                        if (res.data && res.data.items) cartItems = res.data.items;
                        renderSummary();
                        if (typeof window.DDCart !== 'undefined') window.DDCart.refresh();
                        pmAdd.textContent = '✓ Added!';
                        showToast('✓ Added to cart!');
                        setTimeout(function() { closeProductModal(); }, 900);
                    } else {
                        pmAdd.textContent = 'Add to Cart';
                        pmAdd.disabled = false;
                    }
                })
                .catch(function() {
                    pmAdd.textContent = 'Add to Cart';
                    pmAdd.disabled = false;
                });
            });
        }

        modal.classList.add('open');
        document.body.style.overflow = 'hidden';

        var cb = $('ddProductModalClose');
        if (cb) {
            cb.onclick = function(e) { e.stopPropagation(); closeProductModal(); };
        }

        // Fetch richer product data — attributes + ratings (fails silently)
        fetchProductEnrichment(productId);
    }

    } // end openProductModal

    /* ── Enrich modal with attributes + ratings from server ── */
    function fetchProductEnrichment(productId) {
        var ajaxUrl = (window.ddCartData && window.ddCartData.ajax_url)
            ? window.ddCartData.ajax_url
            : (window.DD && window.DD.ajaxUrl) || '/wp-admin/admin-ajax.php';
        var nonce = (window.ddCartData && window.ddCartData.nonce)
            ? window.ddCartData.nonce
            : (window.DD && window.DD.nonce) || '';

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action:     'dd_get_product',
                product_id: productId,
                nonce:      nonce,
            }),
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success || !res.data) return;
            var p = res.data;

            // Ratings
            if (p.rating_count && p.average_rating) {
                var ratingEl = $('ddPmRating');
                if (ratingEl) {
                    var stars = Math.min(5, Math.max(0, Math.round(parseFloat(p.average_rating))));
                    var starHtml = '★'.repeat(stars) + '☆'.repeat(5 - stars);
                    ratingEl.innerHTML =
                        '<span style="color:#E8832A;">' + starHtml + '</span>' +
                        '<span style="margin-left:5px;color:#7A6558;">(' + escHtml(String(p.rating_count)) + ' reviews)</span>';
                }
            }

            // Attribute pills
            var attrsEl = document.getElementById('ddPmAttrs');
            var addBtn  = document.getElementById('ddPmAddBtn');

            if (p.attributes && p.attributes.length > 0) {
                // Disable Add to Cart until all attributes selected
                if (addBtn) {
                    addBtn.disabled = true;
                    addBtn.style.opacity = '0.5';
                    addBtn.style.cursor = 'not-allowed';
                }

                var html = '';
                p.attributes.forEach(function(attr) {
                    html += '<div class="dd-pm__attr-group" style="margin-bottom:0.6rem;">';
                    html += '<div class="dd-pm__attr-label" style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:#7A6558;margin-bottom:0.35rem;">' + escHtml(attr.name) + '</div>';
                    html += '<div class="dd-pm__attr-pills" style="display:flex;flex-wrap:wrap;gap:6px;">';
                    (attr.options || []).forEach(function(opt) {
                        html += '<button type="button" class="dd-pm__attr-pill" data-attr="' + escHtml(attr.name) + '" data-val="' + escHtml(opt) + '">' + escHtml(opt) + '</button>';
                    });
                    html += '</div></div>';
                });

                if (attrsEl) attrsEl.innerHTML = html;

                // Enable Add to Cart when all attribute groups have a selection
                var selected = {};
                var total    = p.attributes.length;

                if (attrsEl) {
                    attrsEl.addEventListener('click', function(e) {
                        var pill = e.target.closest('.dd-pm__attr-pill');
                        if (!pill) return;
                        var attrName = pill.dataset.attr;
                        attrsEl.querySelectorAll('.dd-pm__attr-pill[data-attr="' + attrName + '"]')
                            .forEach(function(p) { p.classList.remove('active'); });
                        pill.classList.add('active');
                        selected[attrName] = pill.dataset.val;
                        if (Object.keys(selected).length >= total && addBtn) {
                            var _ddState = window.DD && window.DD.hours_state;
                            if (_ddState !== 'closed' && _ddState !== 'break') {
                                addBtn.disabled = false;
                                addBtn.style.opacity = '1';
                                addBtn.style.cursor = 'pointer';
                            }
                        }
                    });
                }
            } else {
                if (attrsEl) attrsEl.innerHTML = '';
            }
        })
        .catch(function() { /* silently fail — basic info already shown */ });
    }

    function closeProductModal() {
        var modal = $('ddProductModal');
        if (modal) modal.classList.remove('open');
        document.body.style.overflow = '';
    }
    window.ddCloseModal = closeProductModal;

    function setupProductModal() {
        var modal = $('ddProductModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal || e.target.id === 'ddProductModalOverlay') closeProductModal();
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('open')) closeProductModal();
            });
        }

        document.addEventListener('click', function(e) {
            var card = e.target.closest('.dd-dish-card') || e.target.closest('.dd-menu-item');
            if (!card) return;

            var productId = card.dataset.id;
            if (productId) openProductModal(productId);
        });
    }

    /* ══════════════════════════════════════════════════════════
       LOAD REVIEWS
    ══════════════════════════════════════════════════════════ */
    function loadReviews() {
        var grid = document.getElementById('ddReviewsGrid');
        if (!grid || !window.DD || !window.DD.ajaxUrl) return;

        grid.innerHTML = '<div class="dd-review-skeleton"></div><div class="dd-review-skeleton"></div><div class="dd-review-skeleton"></div>';

        fetch(window.DD.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'dd_get_reviews', nonce: window.DD.nonce }).toString()
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success || !res.data || !res.data.length) { grid.innerHTML = ''; return; }
            grid.innerHTML = res.data.map(function(r) {
                var stars = '★★★★★'.slice(0, Math.round(r.rating || 5));
                return '<div class="dd-review-card">' +
                    '<div class="dd-review-card__stars">' + stars + '</div>' +
                    '<p>' + escHtml(r.text || r.review || '') + '</p>' +
                    '<div class="dd-review-card__author">' + escHtml(r.author || r.name || '') + '</div>' +
                '</div>';
            }).join('');
        })
        .catch(function() { grid.innerHTML = ''; });
    }

    /* ══════════════════════════════════════════════════════════
       WOO FRAGMENTS SYNC
    ══════════════════════════════════════════════════════════ */
    function syncCartFromFragments() {
        if (window.wc_add_to_cart_params && window.wc_add_to_cart_params.cart_url) {
            fetch(window.DD ? window.DD.ajaxUrl : '/wp-admin/admin-ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'dd_get_cart_count',
                    nonce:  window.DD ? window.DD.nonce : '',
                }).toString(),
            })
            .then((r) => r.json())
            .then((res) => {
                if (res.success && res.data && res.data.count !== undefined) updateBadges(res.data.count);
            })
            .catch(() => {});
        }
    }

    /* ══════════════════════════════════════════════════════════
       SEARCH MODAL BRIDGE
       Registered immediately (not inside DOMContentLoaded) so it
       is ready before search.js fires dd:open-modal — avoids the
       race condition where search.js boots first on DOMContentLoaded
       and dispatches the event before frontend.js has registered.
       openProductModal is a function declaration so it is hoisted.
    ══════════════════════════════════════════════════════════ */
    document.addEventListener('dd:open-modal', function(e) {
        if (e.detail && e.detail.productId) openProductModal(e.detail.productId);
    });

    /* ══════════════════════════════════════════════════════════
       BOOT
    ══════════════════════════════════════════════════════════ */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
