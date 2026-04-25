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
        if (overlay) overlay.classList.add('open');
        if (drawer)  drawer.classList.add('open');
        if (typeof window.DDCart !== 'undefined') window.DDCart.refresh();
    }

    function closeCart() {
        const overlay = $('ddCartOverlay');
        const drawer  = $('ddCartDrawer');
        if (overlay) overlay.classList.remove('open');
        if (drawer)  drawer.classList.remove('open');
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
       BIND ALL ADD-TO-CART BUTTONS (featured + category rows)
    ══════════════════════════════════════════════════════════ */
    function bindAddBtns(scope) {
        $all('.dd-add-btn', scope).forEach((btn) => {
            if (btn.dataset.bound) return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', () => {
                const id = parseInt(btn.dataset.id, 10);
                addToCart(id, 1, btn);
            });
        });
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

        const activeRow = document.querySelector('.dd-cat-row[data-slug="' + slug + '"]');
        if (activeRow) bindAddBtns(activeRow);
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
        const featRow = $('ddFeatRow');
        if (featRow) bindAddBtns(featRow);

        const firstRow = document.querySelector('.dd-cat-row:not([hidden])');
        if (firstRow) bindAddBtns(firstRow);

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
            DDTrack.fire('view_product', productId, null, { source: 'modal' });
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
            if (p.attributes && p.attributes.length) {
                var attrsEl = $('ddPmAttrs');
                if (attrsEl) {
                    var html = p.attributes.map(function(attr) {
                        return '<div style="margin-bottom:0.6rem;">' +
                            '<div style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:#7A6558;margin-bottom:0.35rem;">' +
                                escHtml(attr.name) +
                            '</div>' +
                            '<div style="display:flex;flex-wrap:wrap;gap:6px;">' +
                            (attr.options || []).map(function(opt) {
                                return '<button class="dd-pm__pill" data-attr="' + escHtml(attr.name) + '" data-value="' + escHtml(opt) + '" ' +
                                    'style="background:#F5EFE6;border:2px solid #EAD9CE;border-radius:999px;padding:4px 14px;font-size:0.82rem;font-weight:600;cursor:pointer;color:#221B19;transition:all 0.15s;">' +
                                    escHtml(opt) +
                                '</button>';
                            }).join('') +
                            '</div></div>';
                    }).join('');
                    attrsEl.innerHTML = html;

                    attrsEl.querySelectorAll('.dd-pm__pill').forEach(function(pill) {
                        pill.addEventListener('click', function() {
                            var attrName = this.dataset.attr;
                            attrsEl.querySelectorAll('.dd-pm__pill[data-attr="' + attrName + '"]').forEach(function(p) {
                                p.style.background  = '#F5EFE6';
                                p.style.borderColor = '#EAD9CE';
                                p.style.color       = '#221B19';
                            });
                            this.style.background  = '#65040d';
                            this.style.borderColor = '#65040d';
                            this.style.color       = '#fff';
                        });
                    });
                }
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
        if (!modal) return;

        modal.addEventListener('click', function(e) {
            if (e.target === modal || e.target.id === 'ddProductModalOverlay') closeProductModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('open')) closeProductModal();
        });

        document.addEventListener('click', function(e) {
            if (e.target.closest('.dd-product-modal')) return;
            var card = e.target.closest('.dd-dish-card');
            if (!card) return;
            if (e.target.closest('.dd-add-btn')) return;
            var pid = card.dataset.id;
            if (pid) openProductModal(pid);
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
