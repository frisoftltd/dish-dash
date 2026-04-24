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
 *   - #ddSearchDropdown, #ddSearch (smart search — also handled by search.js)
 *   - #ddMenuGrid, #ddMenuLoadMore, #ddMenuCatsTrack (menu page elements)
 *   - .dd-header (.scrolled class added on scroll)
 *   - .dd-menu-toggle, .dd-nav-drawer, .dd-drawer-overlay (mobile nav)
 *   - .dd-filter-btn--active (homepage filter chips)
 *   - .dd-dish-card (product cards, click → opens product modal)
 *   - .dd-add-btn (add-to-cart on homepage cards)
 *   - .dd-product-modal, .dd-product-modal.open (product detail modal)
 *
 * Localized data needed (wp_localize_script):
 *   - window.dishDash  (ajaxUrl, nonce, cartUrl, checkoutUrl, currency_symbol,
 *     currency_position, primaryColor, menuPageId)
 *   - window.DD  (firstCat, deliveryFee, cartCount — set inline in template)
 *   - window.ddCartData  (ajax_url, nonce — localized by cart module)
 *
 * AJAX endpoints called:
 *   - admin-ajax.php?action=dd_cart_add
 *   - admin-ajax.php?action=dd_cart_get
 *   - admin-ajax.php?action=dd_menu_load_products
 *
 * Custom events listened to:
 *   - dd:open-modal  (detail: { productId }) — fired by search.js
 *
 * Custom events fired:
 *   - dd:filter-cards  (detail: { query }) — listened by search.js
 *
 * Dependents:
 *   - modules/template/class-dd-template-module.php (enqueues this)
 *   - templates/page-dishdash.php (relies on this for all interactivity)
 *
 * Last modified: v3.2.15
 */
(function () {
    'use strict';

    /* ══════════════════════════════════════════════════════════
       STATE
    ══════════════════════════════════════════════════════════ */
    // Ensure window.DD exists — may not be set on non-homepage pages
    window.DD = window.DD || {};

    // Shared product data — populated by setupSmartSearch, used by openProductModal
    var productNames   = [];
    var productsLoaded = false;
    var productsSeen   = {};

    // Shared recent searches state
    var recentSearches = [];
    var recentLoaded   = false;

    // Shared dropdown close — used by renderDropdown and event handlers
    function closeDropdown() {
        var dd = document.getElementById('ddSearchDropdown');
        var inp = document.getElementById('ddSearch');
        if (dd) { dd.innerHTML = ''; dd.classList.remove('open'); }
        if (inp) inp.setAttribute('aria-expanded', 'false');
    }

    let activeSlug    = window.DD ? window.DD.firstCat : '';
    let deliveryFee   = window.DD ? parseInt(window.DD.deliveryFee, 10) : 2000;
    let cartCount     = window.DD ? parseInt(window.DD.cartCount, 10) : 0;

    // Local cart mirror (keeps summary sidebar in sync without full page reload)
    let cartItems     = [];

    /* ══════════════════════════════════════════════════════════
       HELPERS
    ══════════════════════════════════════════════════════════ */
    const $ = (id) => document.getElementById(id);
    const $q = (sel, scope = document) => scope.querySelector(sel);
    const $all = (sel, scope = document) => [...scope.querySelectorAll(sel)];

    const fmt = (n) => 'RWF ' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 });

    /* ── Highlight matching text in search results ── */
    function highlight(text, query) {
        if (!query) return escHtml(text);
        var escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return escHtml(text).replace(
            new RegExp('(' + escaped + ')', 'gi'),
            '<span class="dd-ss__highlight">$1</span>'
        );
    }

    /* ══════════════════════════════════════════════════════════
       CART BADGE SYNC
    ══════════════════════════════════════════════════════════ */
    function updateBadges(count) {
        cartCount = count;
        var badges = [
            $('ddCartCount'),
            $('ddFloatingCount'),
            $('ddBottomBadge'),
            $('ddSumBadge'),
        ];
        badges.forEach(function(el) { if (el) el.textContent = count; });
    }

    /* ══════════════════════════════════════════════════════════
       SUMMARY SIDEBAR
    ══════════════════════════════════════════════════════════ */
    function renderSummary() {
        const listEl = $('ddSummaryList');
        if (!listEl) return;

        const subtotal = cartItems.reduce((s, i) => s + i.price * i.qty, 0);
        const total    = subtotal + deliveryFee;

        // Subtotal / Total
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
        // Cart rendering is owned by cart.js — DDCart.refresh() handles it
        if (typeof window.DDCart !== 'undefined') {
            window.DDCart.refresh();
        }
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
                // Update badge from server response
                const newCount = res.data && res.data.count !== undefined
                    ? res.data.count
                    : cartCount + quantity;
                updateBadges(newCount);

                // Sync local cart state if server sends items
                if (res.data && res.data.items) {
                    cartItems = res.data.items;
                }

                renderSummary();

                // Cart drawer rendering owned by cart.js
                if (typeof window.DDCart !== 'undefined') {
                    window.DDCart.refresh();
                }

                // Brief visual feedback
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
                const newCount = res.data && res.data.count !== undefined ? res.data.count : Math.max(0, cartCount - 1);
                updateBadges(newCount);

                if (res.data && res.data.items) {
                    cartItems = res.data.items;
                } else {
                    cartItems = cartItems.filter((i) => i.id !== productId);
                }

                renderSummary();

                // Cart drawer rendering owned by cart.js
                if (typeof window.DDCart !== 'undefined') {
                    window.DDCart.refresh();
                }
            }
        })
        .catch((e) => console.warn('[DishDash] Remove failed', e));
    }

    /* ══════════════════════════════════════════════════════════
       LOCAL CART HELPERS
    ══════════════════════════════════════════════════════════ */
    function addToLocalCart(id, name, price) {
        const existing = cartItems.find((i) => i.id === id);
        if (existing) {
            existing.qty++;
        } else {
            cartItems.push({ id, name, price, qty: 1 });
        }
    }

    function parseRWF(str) {
        // "RWF 12,500" → 12500
        return parseInt((str || '').replace(/[^\d]/g, ''), 10) || 0;
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ══════════════════════════════════════════════════════════
       BIND ALL ADD-TO-CART BUTTONS (featured + category rows)
    ══════════════════════════════════════════════════════════ */
    function bindAddBtns(scope) {
        $all('.dd-add-btn', scope).forEach((btn) => {
            // Avoid double-binding
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

        // Update title
        const titleEl = $('ddCatTitle');
        if (titleEl) titleEl.textContent = name;

        // Hide all rows, show selected
        $all('.dd-cat-row').forEach((row) => {
            if (row.dataset.slug === slug) {
                row.removeAttribute('hidden');
            } else {
                row.setAttribute('hidden', '');
            }
        });

        // Update active arrow buttons for selected row
        setupArrows('ddSelPrev', 'ddSelNext', 'ddCatRow-' + slug);

        // Bind any new add-to-cart buttons
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

        // Use data attribute to prevent double-binding
        if (prev.dataset.bound === rowId) return;
        prev.dataset.bound = rowId;
        next.dataset.bound = rowId;

        prev.addEventListener('click', function() {
            row.scrollBy({ left: -300, behavior: 'smooth' });
        });
        next.addEventListener('click', function() {
            row.scrollBy({ left: 300, behavior: 'smooth' });
        });
    }

    /* ══════════════════════════════════════════════════════════
       SEARCH FILTER
    ══════════════════════════════════════════════════════════ */
    function setupSearch() {
        const input = $('ddSearch');
        if (!input) return;

        input.addEventListener('input', () => {
            const q = input.value.trim().toLowerCase();
            filterDishCards(q);
        });
    }

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

        chips.forEach( function(chip) {
            chip.addEventListener('click', function() {
                chips.forEach( function(c) { c.classList.remove('active'); });
                chip.classList.add('active');

                var filter = (chip.dataset.filter || '').toLowerCase();
                if ( ! featRow ) return;

                // Remove any existing load more button to rebuild
                var existingBtn = featRow.nextElementSibling;
                if ( existingBtn && existingBtn.classList.contains('dd-load-more-btn') ) {
                    existingBtn.remove();
                }

                // First show all cards so we can filter properly
                $all('.dd-dish-card', featRow).forEach( function(card) {
                    card.classList.remove('dd-card-hidden');
                    card.style.display = '';
                });

                if ( ! filter ) {
                    // Show All — re-apply load more if desktop
                    if ( window.innerWidth > 860 ) {
                        applyGridLoadMore( featRow, 'ddFeatLoadMoreFiltered', 8 );
                    }
                    return;
                }

                // Filter by tag slug
                var matching = [];
                var nonMatching = [];

                $all('.dd-dish-card', featRow).forEach( function(card) {
                    var cardFilter = (card.dataset.filter || '').toLowerCase();
                    var matches = cardFilter.split(',').some(function(f) {
                        return f.trim() === filter;
                    });
                    if ( matches ) {
                        matching.push(card);
                    } else {
                        nonMatching.push(card);
                        card.style.display = 'none';
                    }
                });

                // Re-apply load more for filtered results on desktop
                if ( window.innerWidth > 860 && matching.length > 8 ) {
                    matching.forEach(function(card, i) {
                        if ( i >= 8 ) card.classList.add('dd-card-hidden');
                    });
                    applyGridLoadMore( featRow, 'ddFeatLoadMoreFiltered', 8 );
                }
            });
        });
    }

    /* ══════════════════════════════════════════════════════════
       MODE BUTTONS (Delivery / Pickup) — store + visual
    ══════════════════════════════════════════════════════════ */
    function setupModeButtons() {
        var currentMode = 'delivery';
        $all('.dd-mode-btn').forEach( function(btn) {
            btn.addEventListener('click', function() {
                $all('.dd-mode-btn').forEach( function(b) { b.classList.remove('active'); });
                btn.classList.add('active');
                currentMode = btn.dataset.mode || 'delivery';

                // Update delivery fee display
                var delRow = $('ddSumDelivery');
                var drawerDel = $('ddDrawerDelivery');
                var fee = currentMode === 'pickup' ? 0 : deliveryFee;
                if ( delRow   ) delRow.textContent   = fee === 0 ? 'Free' : fmt(fee);
                if ( drawerDel ) drawerDel.textContent = fee === 0 ? 'Free' : fmt(fee);

                // Recalculate totals with new fee
                var sub  = cartItems.reduce( function(s,i){ return s + i.price * i.qty; }, 0 );
                var tot  = sub + fee;
                var subEl = $('ddSumSubtotal');  if (subEl) subEl.textContent = fmt(sub);
                var totEl = $('ddSumTotal');      if (totEl) totEl.textContent = fmt(tot);
                var dSub  = $('ddDrawerSubtotal'); if (dSub) dSub.textContent = fmt(sub);
                var dTot  = $('ddDrawerTotal');    if (dTot) dTot.textContent = fmt(tot);
            });
        });
    }
    /* ══════════════════════════════════════════════════════════
       DESKTOP GRID + LOAD MORE
       Mobile: horizontal scroll row (unchanged)
       Desktop (>860px): 4×2 grid with Load More button
    ══════════════════════════════════════════════════════════ */
    function setupDesktopGrid() {
        // Only apply on desktop
        if ( window.innerWidth <= 860 ) return;

        var PER_PAGE = 8;

        // Apply to featured row
        var featRow = $('ddFeatRow');
        if ( featRow ) {
            applyGridLoadMore( featRow, 'ddFeatLoadMore', PER_PAGE );
        }

        // Apply to all category rows
        $all('.dd-cat-row').forEach(function(row) {
            var rowId = row.id + 'LoadMore';
            applyGridLoadMore( row, rowId, PER_PAGE );
        });
    }

    function applyGridLoadMore( row, btnId, perPage ) {
        var cards = Array.from( row.querySelectorAll('.dd-dish-card') );
        if ( cards.length <= perPage ) return; // No need if all fit

        // Hide cards beyond first page
        var visible = perPage;
        cards.forEach(function(card, i) {
            if ( i >= visible ) {
                card.classList.add('dd-card-hidden');
            }
        });

        // Create Load More button
        var btn = document.createElement('button');
        btn.id        = btnId;
        btn.className = 'dd-load-more-btn';
        btn.innerHTML = 'Load More <span class="dd-load-more-icon">↓</span>';

        // Insert after the row
        row.parentNode.insertBefore( btn, row.nextSibling );

        btn.addEventListener('click', function() {
            var hidden = Array.from( row.querySelectorAll('.dd-dish-card.dd-card-hidden') );
            var toShow = hidden.slice(0, perPage);

            toShow.forEach(function(card) {
                card.classList.remove('dd-card-hidden');
            });

            var stillHidden = row.querySelectorAll('.dd-dish-card.dd-card-hidden').length;
            if ( stillHidden === 0 ) {
                btn.remove();
            } else {
                btn.innerHTML = 'Load More <span class="dd-load-more-icon">↓</span>';
            }
        });
    }

    /* ══════════════════════════════════════════════════════════
       STICKY HEADER SCROLL BEHAVIOR
    ══════════════════════════════════════════════════════════ */
    function setupStickyHeader() {
        var header = document.querySelector('.dd-header');
        if ( ! header ) return;

        window.addEventListener('scroll', function() {
            var currentScroll = window.pageYOffset || document.documentElement.scrollTop;

            if ( currentScroll > 50 ) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        }, { passive: true });
    }

    function setupMobileNav() {
        // New: Instacart-style slide-out drawer (desktop + mobile)
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
            if (drawer.classList.contains('open')) {
                closeDrawer();
            } else {
                openDrawer();
            }
        });

        if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
        if (overlay)  overlay.addEventListener('click', closeDrawer);

        // Close when nav link clicked
        $all('a', drawer).forEach(function(link) {
            link.addEventListener('click', closeDrawer);
        });

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDrawer();
        });
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

            // Map categories → menu for nav purposes
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
        const openBtns = [$('ddCartTopBtn'), $('ddBottomCartBtn')];
        openBtns.forEach((btn) => btn && btn.addEventListener('click', openCart));

        const overlay = $('ddCartOverlay');
        if (overlay) overlay.addEventListener('click', closeCart);
    }

    /* ══════════════════════════════════════════════════════════
       SMOOTH SCROLL FOR ANCHOR LINKS
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
        // Bind add-to-cart on featured row
        const featRow = $('ddFeatRow');
        if (featRow) bindAddBtns(featRow);

        // Bind add-to-cart on first (visible) category row
        const firstRow = document.querySelector('.dd-cat-row:not([hidden])');
        if (firstRow) bindAddBtns(firstRow);

        // Category card clicks (browse by category circles)
        $all('.dd-cat-card').forEach((card) => {
            card.addEventListener('click', () => {
                $all('.dd-cat-card').forEach((c) => c.classList.remove('active'));
                card.classList.add('active');
                switchCategory(card.dataset.slug, card.dataset.name);

                // Navigate to category page if url is set
                if ( card.dataset.url && card.dataset.url !== '#' ) {
                    window.location.href = card.dataset.url;
                }
            });
        });

        // Selected category TAB clicks
        $all('.dd-selcat__tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                $all('.dd-selcat__tab').forEach(function(t) { t.classList.remove('active'); });
                tab.classList.add('active');
                switchCategory( tab.dataset.slug, tab.dataset.name );
                setupArrows( 'ddSelCatPrev', 'ddSelCatNext', 'ddCatRow-' + tab.dataset.slug );
            });
        });

        // Category row arrows (initial binding for first cat)
        if (activeSlug) {
            setupArrows('ddSelPrev', 'ddSelNext', 'ddCatRow-' + activeSlug);
        }

        // Featured row arrows
        setupArrows('ddFeatPrev', 'ddFeatNext', 'ddFeatRow');

        // Category scroll arrows
        setupArrows('ddCatPrev', 'ddCatNext', 'ddCatScrollRow');

        // Selected category product scroll arrows (mobile)
        var activeSelCatRow = document.querySelector('.dd-cat-row:not([hidden])');
        if ( activeSelCatRow ) {
            setupArrows( 'ddSelCatPrev', 'ddSelCatNext', activeSelCatRow.id );
        }

        // Desktop grid + load more
        setupDesktopGrid();

        // Other setups
        setupModeButtons();
        setupChips();
        setupMobileNav();
        setupBottomNav();
        setupCartControls();
        setupSmoothScroll();
        setupStickyHeader();

        // Initial summary render
        renderSummary();

        // Load Google Reviews
        loadReviews();

        // Product modal
        setupProductModal();

        // Listen for search module events (decoupled communication)
        document.addEventListener('dd:open-modal', function(e) {
            if (e.detail && e.detail.productId) openProductModal(e.detail.productId);
        });
        document.addEventListener('dd:filter-cards', function(e) {
            filterDishCards(e.detail ? e.detail.query : '');
        });

        // Sync cart count from WooCommerce fragments (if available)
        document.body.addEventListener('wc_fragments_refreshed', syncCartFromFragments);
        document.body.addEventListener('wc_cart_button_updated', syncCartFromFragments);

        // WooCommerce added_to_cart event
        document.body.addEventListener('added_to_cart', (e) => {
            const fragments = e.detail;
            if (fragments && fragments.cart_count !== undefined) {
                updateBadges(fragments.cart_count);
            }
        });
    }


    /* ══════════════════════════════════════════════════════════
       SMART SEARCH
       Replaces basic setupSearch().
       - Transparent bar, no white background
       - Dropdown with recent searches from DB (not localStorage)
       - Autosuggestion from product names already in DOM
       - Sticky after hero scrolls out of view
    ══════════════════════════════════════════════════════════ */
    /* ── Fetch products via AJAX if none in DOM ──────── */
    function loadProductsFromServer(callback) {
        var ajaxUrl = (window.DD && window.DD.ajaxUrl)
               || (window.DDAauth && window.DDAauth.ajaxUrl)
               || '/wp-admin/admin-ajax.php';
        var nonce   = (window.DD && window.DD.nonce)
               || (window.DDAauth && window.DDAauth.nonce)
               || '';
        if (!ajaxUrl) { callback(); return; }
        fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'dd_get_search_products',
            nonce:  nonce
        }).toString()
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
        if (res.success && res.data) {
            res.data.forEach(function(p) {
            if (!productsSeen[p.id]) {
                productsSeen[p.id] = true;
                productNames.push(p);
            }
            });
        }
        productsLoaded = true;
        callback();
        })
        .catch(function() { productsLoaded = true; callback(); });
    }


    /* ── Fetch recent searches from DB ───────────────── */
    function loadRecentSearches(callback) {
        if (!window.DD || !window.DD.ajaxUrl) { callback([]); return; }
        fetch(window.DD.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'dd_get_recent_searches',
            nonce:  window.DD.nonce || ''
        }).toString()
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
        callback(res.success ? (res.data || []) : []);
        })
        .catch(function() { callback([]); });
    }

    /* ── Highlight matching text ─────────────────────── */
    function highlight(text, query) {
        if (!query) return escHtml(text);
        var escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return escHtml(text).replace(
        new RegExp('(' + escaped + ')', 'gi'),
        '<span class="dd-ss__highlight">$1</span>'
        );
    }

    /* ── Render dropdown — products only, click opens modal ── */
    function renderDropdown(recents, query) {
        var q = (query || '').toLowerCase().trim();

        // No query — close dropdown
        if (!q) { closeDropdown(); return; }

        // Load from server if no DOM cards (non-homepage pages)
        if (!productsLoaded) {
        loadProductsFromServer(function() { renderDropdown(recents, query); });
        return;
        }

        var matches = productNames.filter(function(p) {
        return p.name.toLowerCase().indexOf(q) !== -1;
        }).slice(0, 6);

        var html = '';
        if (matches.length > 0) {
        html += '<div class="dd-ss__dropdown-section">';
        html += '<div class="dd-ss__dropdown-label">Dishes (' + matches.length + ')</div>';
        html += '<div class="dd-ss__results-grid">';
        matches.forEach(function(p) {
            html +=
            '<button class="dd-ss__result-card" data-product-id="' + escHtml(p.id) + '">' +
                '<div class="dd-ss__result-img">' +
                (p.img ? '<img src="' + escHtml(p.img) + '" alt="' + escHtml(p.name) + '" loading="lazy">' : '<span>&#127869;</span>') +
                '</div>' +
                '<div class="dd-ss__result-body">' +
                '<div class="dd-ss__result-name">' + highlight(p.name, query) + '</div>' +
                '<div class="dd-ss__result-price">' + escHtml(p.price) + '</div>' +
                '</div>' +
            '</button>';
        });
        html += '</div></div>';
        } else {
        html = '<div class="dd-ss__empty">No dishes found for &ldquo;' + escHtml(query) + '&rdquo;</div>';
        }

        dropdown.innerHTML = html;
        dropdown.classList.add('open');
        input.setAttribute('aria-expanded', 'true');
    }



    function setupSmartSearch() {
        var input    = $('ddSearch');
        var dropdown = $('ddSearchDropdown');
        var clearBtn = $('ddSearchClear');
        var searchEl = $('ddSmartSearch');

        // Fallback to basic search if smart search elements not present
        if (!input) return;

        // Clear any browser-autofilled value immediately
        input.value = '';
        var mobileInput = $('ddMobileSearch');
        if (mobileInput) mobileInput.value = '';
        if (!dropdown) {
                return;
        }

        // Extract full product data from DOM — works on homepage (.dd-dish-card)
        // AND menu page (.dd-menu-item)
        // Uses outer-scope productNames/productsSeen so openProductModal can access them

        // Homepage dish cards
        document.querySelectorAll('.dd-dish-card').forEach(function(card) {
            var titleEl = card.querySelector('.dd-dish-card__title');
            var priceEl = card.querySelector('.dd-price');
            var descEl  = card.querySelector('.dd-dish-card__desc');
            var imgEl   = card.querySelector('img');
            var addBtn  = card.querySelector('.dd-add-btn');
            var id = card.dataset.id || '';
            if (titleEl && id && !productsSeen[id]) {
                productsSeen[id] = true;
                var linkEl = card.querySelector('a') || card.closest('a');
                var cardUrl = (linkEl && linkEl.href) ? linkEl.href : '';
                // Also check data-url attribute
                if (!cardUrl && card.dataset.url) cardUrl = card.dataset.url;
                productNames.push({
                    id:    id,
                    name:  titleEl.textContent.trim(),
                    price: priceEl  ? priceEl.textContent.trim()  : '',
                    desc:  descEl   ? descEl.textContent.trim()   : '',
                    img:   imgEl    ? imgEl.src                   : '',
                    nonce: addBtn   ? (addBtn.dataset.nonce || '') : '',
                    url:   cardUrl,
                });
            }
        });

        // Menu page list items
        document.querySelectorAll('.dd-menu-item').forEach(function(card) {
            var titleEl = card.querySelector('.dd-menu-item__name');
            var priceEl = card.querySelector('.dd-menu-item__price');
            var descEl  = card.querySelector('.dd-menu-item__desc');
            var imgEl   = card.querySelector('img');
            var addBtn  = card.querySelector('.dd-add-btn');
            var id = card.dataset.id || '';
            if (titleEl && id && !productsSeen[id]) {
                productsSeen[id] = true;
                var linkEl = card.querySelector('a') || card.closest('a');
                var cardUrl = (linkEl && linkEl.href) ? linkEl.href : '';
                // Also check data-url attribute
                if (!cardUrl && card.dataset.url) cardUrl = card.dataset.url;
                productNames.push({
                    id:    id,
                    name:  titleEl.textContent.trim(),
                    price: priceEl  ? priceEl.textContent.trim()  : '',
                    desc:  descEl   ? descEl.textContent.trim()   : '',
                    img:   imgEl    ? imgEl.src                   : '',
                    nonce: addBtn   ? (addBtn.dataset.nonce || '') : '',
                    url:   cardUrl,
                });
            }
        });

        productsLoaded = productNames.length > 0;

        /* ── Input focus — preload products on all pages ── */
        input.addEventListener('focus', function() {
            // Always ensure products are loaded (for non-homepage pages)
            if (!productsLoaded) {
                loadProductsFromServer(function() {
                    var q = input.value.trim();
                    if (q) renderDropdown(recentSearches, q);
                });
            }
        });

        /* ── Input change ───────────────────────────────── */
        input.addEventListener('input', function() {
            var q = this.value.trim();

            // Show / hide clear button
            if (clearBtn) {
                clearBtn.classList.toggle('visible', q.length > 0);
            }

            // Filter dish cards on the page
            filterDishCards(q);

            // Render suggestions — do NOT track here (partial queries)
            renderDropdown(recentSearches, q);
        });

        /* ── Clear button ───────────────────────────────── */
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                input.value = '';
                this.classList.remove('visible');
                filterDishCards('');
                closeDropdown();
                input.focus();
            });
        }

        /* ── Dropdown interaction ───────────────────────── */
        dropdown.addEventListener('mousedown', function(e) {
            e.preventDefault();
        });

        dropdown.addEventListener('click', function(e) {
            // Remove recent search
            var removeBtn = e.target.closest('.dd-ss__sug-remove');
            if (removeBtn) {
                var toRemove = removeBtn.dataset.remove;
                recentSearches = recentSearches.filter(function(s) { return s !== toRemove; });
                renderDropdown(recentSearches, input.value.trim());
                return;
            }

            // Add to cart button on result card — stop propagation so modal doesn't open
            var addBtn = e.target.closest('.dd-ss__result-add');
            if (addBtn) {
                e.stopPropagation();
                // Already handled by global add-to-cart listener in bindAddBtns
                return;
            }

            // Product result card click — open modal
            var resultCard = e.target.closest('.dd-ss__result-card');
            if (resultCard) {
                var pid = resultCard.dataset.productId;
                if (pid) {
                    closeDropdown();
                    if (!productsLoaded) {
                        loadProductsFromServer(function() { openProductModal(pid); });
                    } else {
                        openProductModal(pid);
                    }
                }
                return;
            }

            // Text suggestion click — fill input + filter + track
            var suggestion = e.target.closest('.dd-ss__suggestion');
            if (suggestion) {
                var q = suggestion.dataset.query || '';
                input.value = q;
                if (clearBtn) clearBtn.classList.toggle('visible', q.length > 0);
                closeDropdown();

                // Check if query matches exactly one product — open modal directly
                var exactMatch = null;
                for (var pi = 0; pi < productNames.length; pi++) {
                    if (productNames[pi].name.toLowerCase() === q.toLowerCase()) {
                        exactMatch = productNames[pi];
                        break;
                    }
                }

                if (exactMatch) {
                    // Open product modal directly
                    if (!productsLoaded) {
                        loadProductsFromServer(function() { openProductModal(exactMatch.id); });
                    } else {
                        openProductModal(exactMatch.id);
                    }
                } else {
                    // Filter page cards
                    filterDishCards(q);
                }

                // Track full query to DB
                if (window.DDTrack && q.length >= 5) window.DDTrack.search(q);
                // Update local list immediately
                recentSearches = [q].concat(
                    recentSearches.filter(function(s) { return s.toLowerCase() !== q.toLowerCase(); })
                ).slice(0, 5);
            }
        });

        /* ── Close on outside click ─────────────────────── */
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dd-smart-search') && !e.target.closest('.dd-ss-section')) {
                closeDropdown();
            }
        });

        /* ── Keyboard navigation ────────────────────────── */
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDropdown();
                this.blur();
            }
            if (e.key === 'Enter') {
                var q = this.value.trim();
                if (q && q.length >= 5) {
                    filterDishCards(q);
                    closeDropdown();
                    // Save full query to DB (not partial letters)
                    if (window.DDTrack) window.DDTrack.search(q);
                    // Update recent searches locally for immediate display
                    recentSearches = [q].concat(
                        recentSearches.filter(function(s) {
                            return s.toLowerCase() !== q.toLowerCase();
                        })
                    ).slice(0, 5);
                }
            }
        });

        /* ── Sync mobile search with desktop search ───────── */
        var mobileInput = document.getElementById('ddMobileSearch');
        var mobileClear = document.getElementById('ddMobileSearchClear');

        if (mobileInput) {
            // Typing in mobile search syncs to main search + filters
            mobileInput.addEventListener('input', function() {
                var q = this.value.trim();
                if (mobileClear) mobileClear.style.display = q ? '' : 'none';
                filterDishCards(q);
                // Also track the search
                if (q.length >= 2 && window.DDTrack) window.DDTrack.search(q);
            });
        }
        if (mobileClear) {
            mobileClear.addEventListener('click', function() {
                if (mobileInput) { mobileInput.value = ''; mobileInput.focus(); }
                this.style.display = 'none';
                filterDishCards('');
            });
        }
    }


    /* ══════════════════════════════════════════════════════════
       PRODUCT MODAL
       Opens when user clicks a product in search results
       or anywhere else that calls openProductModal(id).
       Reads product data from DOM — AJAX fetch enriches with
       attributes and ratings.
    ══════════════════════════════════════════════════════════ */
    function openProductModal(productId) {
        var modal   = $('ddProductModal');
        var content = $('ddProductModalContent');
        if (!modal || !content) return;

        var name = '', price = '', desc = '', imgSrc = '', nonce = '';

        // 1. Try to find card in DOM first
        var card = document.querySelector('.dd-dish-card[data-id="' + productId + '"]')
                || document.querySelector('.dd-menu-item[data-id="' + productId + '"]');

        if (card) {
            var isDishCard = card.classList.contains('dd-dish-card');
            name   = ((isDishCard ? card.querySelector('.dd-dish-card__title') : card.querySelector('.dd-menu-item__name')) || {}).textContent || '';
            price  = ((isDishCard ? card.querySelector('.dd-price') : card.querySelector('.dd-menu-item__price')) || {}).textContent || '';
            desc   = ((isDishCard ? card.querySelector('.dd-dish-card__desc') : card.querySelector('.dd-menu-item__desc')) || {}).textContent || '';
            var imgEl  = card.querySelector('img');
            imgSrc = imgEl ? imgEl.src : '';
            var addBtn = card.querySelector('.dd-add-btn');
            nonce  = addBtn ? (addBtn.dataset.nonce || '') : '';
            renderModal(productId, name, price, desc, imgSrc);
        } else {
            // 2. Request product data from search.js via custom event
            function onData(e) {
                document.removeEventListener('dd:product-data', onData);
                var p = e.detail;
                if (!p) return;
                renderModal(productId, p.name || '', p.price || '', p.desc || '', p.img || '');
            }
            document.addEventListener('dd:product-data', onData);
            document.dispatchEvent(new CustomEvent('dd:get-product', {
                detail: { productId: productId }
            }));
            return; // renderModal called async via event
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
                        setTimeout(function() {
                            closeProductModal();
                        }, 900);
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

        // Show modal
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';

        // Re-bind close button directly
        var cb = $('ddProductModalClose');
        if (cb) {
            cb.onclick = function(e) {
                e.stopPropagation();
                closeProductModal();
            };
        }

        // Fetch richer product data — attributes + ratings (fails silently)
        fetchProductEnrichment(productId);
    }

    }

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

                    // Interactive pill selection
                    attrsEl.querySelectorAll('.dd-pm__pill').forEach(function(pill) {
                        pill.addEventListener('click', function() {
                            var attrName = this.dataset.attr;
                            attrsEl.querySelectorAll('.dd-pm__pill[data-attr="' + attrName + '"]').forEach(function(p) {
                                p.style.background = '#F5EFE6';
                                p.style.borderColor = '#EAD9CE';
                                p.style.color = '#221B19';
                            });
                            this.style.background = '#65040d';
                            this.style.borderColor = '#65040d';
                            this.style.color = '#fff';
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
    // Expose globally so onclick= in HTML can call it directly
    window.ddCloseModal = closeProductModal;

    function setupProductModal() {
        var modal = $('ddProductModal');
        if (!modal) return;

        // Overlay click closes modal
        modal.addEventListener('click', function(e) {
            if (e.target === modal || e.target.id === 'ddProductModalOverlay') {
                closeProductModal();
            }
        });

        // Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('open')) {
                closeProductModal();
            }
        });

        // Open modal when dish card image/name clicked (not Add button)
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
       Fetches reviews via AJAX and renders them into #ddReviewsGrid
    ══════════════════════════════════════════════════════════ */
    function loadReviews() {
        var grid = document.getElementById('ddReviewsGrid');
        if (!grid || !window.DD || !window.DD.ajaxUrl) return;

        // Show skeleton
        grid.innerHTML = '<div class="dd-review-skeleton"></div><div class="dd-review-skeleton"></div><div class="dd-review-skeleton"></div>';

        fetch(window.DD.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'dd_get_reviews', nonce: window.DD.nonce }).toString()
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success || !res.data || !res.data.length) {
                grid.innerHTML = '';
                return;
            }
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
       MOBILE SEARCH — Expandable Uber Eats style
       Trigger: search icon tap → panel slides down → instant focus
       Real-time results as user types (no submit)
    ══════════════════════════════════════════════════════════ */
    function setupMobileSearch() {
        var trigger   = $('ddMobileSearchTrigger');
        var panel     = $('ddMobileSearchPanel');
        var input     = $('ddMobileSearch');
        var closeBtn  = $('ddMobileSearchClose');
        var dropdown  = $('ddMobileSearchDropdown');

        if (!trigger || !panel || !input) return;

        // Create overlay
        var overlay = document.createElement('div');
        overlay.className = 'dd-mobile-search-overlay';
        overlay.id = 'ddMobileOverlay';
        document.body.appendChild(overlay);

        function openMobileSearch() {
            panel.classList.add('open');
            overlay.classList.add('open');
            panel.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            // Pre-load products so results are instant
            preloadProducts();
            // Slight delay so animation starts before keyboard opens
            setTimeout(function() {
                input.value = '';
                input.focus();
            }, 80);
        }

        function closeMobileSearch() {
            panel.classList.remove('open');
            overlay.classList.remove('open');
            panel.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            input.value = '';
            if (dropdown) {
                dropdown.innerHTML = '';
                dropdown.classList.remove('open');
            }
            input.blur();
        }

        trigger.addEventListener('click', openMobileSearch);
        closeBtn && closeBtn.addEventListener('click', closeMobileSearch);
        overlay.addEventListener('click', closeMobileSearch);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && panel.classList.contains('open')) closeMobileSearch();
        });

        // When panel opens, pre-load products from server (non-homepage pages)
        // so results are instant when user starts typing
        function preloadProducts() {
            if (!productsLoaded) loadProductsFromServer(function() {});
        }

        // Real-time search — fires on every keystroke
        input.addEventListener('input', function() {
            var q = this.value.trim();

            // Filter page cards
            filterDishCards(q);

            // Show results in mobile dropdown
            renderMobileDropdown(q);
        });

        // Enter key on mobile search
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                var q = this.value.trim();
                if (q && q.length >= 5 && window.DDTrack) window.DDTrack.search(q);
                closeMobileSearch();
            }
            if (e.key === 'Escape') closeMobileSearch();
        });
    }

    /* Render results into mobile dropdown — identical logic to desktop */
    function renderMobileDropdown(query) {
        var dropdown = $('ddMobileSearchDropdown');
        if (!dropdown) return;

        var q = (query || '').toLowerCase().trim();

        if (!q) {
            dropdown.innerHTML = '';
            dropdown.classList.remove('open');
            return;
        }

        // Load from server if no DOM cards (non-homepage pages)
        if (!productsLoaded) {
            loadProductsFromServer(function() { renderMobileDropdown(query); });
            return;
        }

        var matches = productNames.filter(function(p) {
            return p.name.toLowerCase().indexOf(q) !== -1;
        }).slice(0, 8);

        if (!matches.length) {
            dropdown.innerHTML = '<div class="dd-ss__empty">No dishes found for &ldquo;' + escHtml(query) + '&rdquo;</div>';
            dropdown.classList.add('open');
            return;
        }

        var html = '<div class="dd-ss__dropdown-section">';
        html += '<div class="dd-ss__dropdown-label">Dishes (' + matches.length + ')</div>';
        html += '<div class="dd-ss__results-grid">';
        matches.forEach(function(p) {
            html += '<button class="dd-ss__result-card" data-product-id="' + escHtml(p.id) + '">' +
                '<div class="dd-ss__result-img">' +
                    (p.img ? '<img src="' + escHtml(p.img) + '" alt="' + escHtml(p.name) + '" loading="lazy">' : '<span>&#127869;</span>') +
                '</div>' +
                '<div class="dd-ss__result-body">' +
                    '<div class="dd-ss__result-name">' + highlight(p.name, query) + '</div>' +
                    '<div class="dd-ss__result-price">' + escHtml(p.price) + '</div>' +
                '</div>' +
            '</button>';
        });
        html += '</div></div>';

        dropdown.innerHTML = html;
        dropdown.classList.add('open');

        // Click on result card → close search + open product modal
        dropdown.querySelectorAll('.dd-ss__result-card').forEach(function(card) {
            card.addEventListener('click', function() {
                var pid = this.dataset.productId;
                var panel   = $('ddMobileSearchPanel');
                var overlay = $('ddMobileOverlay');
                if (panel)   panel.classList.remove('open');
                if (overlay) overlay.classList.remove('open');
                document.body.style.overflow = '';
                if (pid) openProductModal(pid);
            });
        });
    }


    /* ══════════════════════════════════════════════════════════
       WOO FRAGMENTS SYNC
    ══════════════════════════════════════════════════════════ */
    function syncCartFromFragments() {
        // Try to read count from WooCommerce session
        if (window.wc_add_to_cart_params && window.wc_add_to_cart_params.cart_url) {
            // Just refresh count from server
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
                if (res.success && res.data && res.data.count !== undefined) {
                    updateBadges(res.data.count);
                }
            })
            .catch(() => {});
        }
    }

    /* ══════════════════════════════════════════════════════════
       BOOT
    ══════════════════════════════════════════════════════════ */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
