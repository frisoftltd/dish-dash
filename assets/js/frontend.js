/**
 * Dish Dash — Frontend JS
 * Handles all interactivity for the DishDash page template.
 * Works with pre-rendered PHP category rows and WooCommerce AJAX cart.
 *
 * @package DishDash
 * @since   2.2.0
 */

(function () {
    'use strict';

    /* ══════════════════════════════════════════════════════════
       STATE
    ══════════════════════════════════════════════════════════ */
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
       CART DRAWER
    ══════════════════════════════════════════════════════════ */
    function renderDrawer() {
        const body = $('ddDrawerBody');
        if (!body) return;

        const subtotal = cartItems.reduce((s, i) => s + i.price * i.qty, 0);
        const total    = subtotal + deliveryFee;

        const subEl = $('ddDrawerSubtotal');
        const totEl = $('ddDrawerTotal');
        if (subEl) subEl.textContent = fmt(subtotal);
        if (totEl) totEl.textContent = fmt(total);

        if (!cartItems.length) {
            body.innerHTML = '<div class="dd-cart-drawer__empty">Your cart is empty.</div>';
            return;
        }

        body.innerHTML = `
            <div class="dd-cart-drawer__items">
                ${cartItems.map((item) => `
                    <div class="dd-cart-drawer__item">
                        <div>
                            <h4>${escHtml(item.name)}</h4>
                            <p>${item.qty} × ${fmt(item.price)}</p>
                        </div>
                        <button class="dd-btn dd-btn--sm dd-btn--light dd-rm-btn"
                                data-id="${item.id}"
                                style="padding:6px 12px;font-size:12px;">Remove</button>
                    </div>
                `).join('')}
            </div>
        `;

        // Bind remove buttons
        $all('.dd-rm-btn', body).forEach((btn) => {
            btn.addEventListener('click', () => removeFromCart(parseInt(btn.dataset.id, 10)));
        });
    }

    function openCart() {
        const overlay = $('ddCartOverlay');
        const drawer  = $('ddCartDrawer');
        if (overlay) overlay.classList.add('open');
        if (drawer)  drawer.classList.add('open');
        renderDrawer();
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
    function addToCart(productId, nonce, btn) {
        if (!window.DD) return;

        btn.classList.add('loading');
        btn.textContent = 'Adding…';

        const body = new URLSearchParams({
            action:     'dd_add_to_cart',
            product_id: productId,
            quantity:   1,
            nonce:      nonce,
        });

        fetch(window.DD.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:   body.toString(),
        })
        .then((r) => r.json())
        .then((res) => {
            btn.classList.remove('loading');
            btn.textContent = 'Add to cart';

            if (res.success) {
                // Update badge from server response
                const newCount = res.data && res.data.count !== undefined
                    ? res.data.count
                    : cartCount + 1;
                updateBadges(newCount);

                // If server sends cart items, sync local state
                if (res.data && res.data.items) {
                    cartItems = res.data.items;
                } else {
                    // Optimistic local update using card's name + price
                    const card  = btn.closest('.dd-dish-card');
                    const name  = card ? ($q('.dd-dish-card__title', card) || {}).textContent || '' : '';
                    const price = card ? parseRWF(($q('.dd-price', card) || {}).textContent || '') : 0;
                    addToLocalCart(productId, name, price);
                }

                renderSummary();
                renderDrawer();

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
        if (!window.DD) return;

        fetch(window.DD.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action:     'dd_remove_from_cart',
                product_id: productId,
                nonce:      window.DD.nonce,
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
                renderDrawer();
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
                const id    = parseInt(btn.dataset.id, 10);
                const nonce = btn.dataset.nonce || (window.DD ? window.DD.nonce : '');
                addToCart(id, nonce, btn);
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
        const toggle = $('ddMobileToggle');
        const nav    = $('ddMainNav');
        if (!toggle || !nav) return;

        toggle.addEventListener('click', () => nav.classList.toggle('open'));

        // Close when a link is clicked
        $all('a', nav).forEach((link) => {
            link.addEventListener('click', () => nav.classList.remove('open'));
        });

        // Close on outside click
        document.addEventListener('click', (e) => {
            if (!nav.contains(e.target) && e.target !== toggle) {
                nav.classList.remove('open');
            }
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
        const openBtns = [$('ddCartTopBtn'), $('ddFloatingCart'), $('ddBottomCartBtn')];
        openBtns.forEach((btn) => btn && btn.addEventListener('click', openCart));

        const closeBtn = $('ddCloseCart');
        if (closeBtn) closeBtn.addEventListener('click', closeCart);

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
        setupSmartSearch();   // replaces setupSearch() — adds dropdown + DB recent searches
        setupModeButtons();
        setupChips();
        setupMobileNav();
        setupBottomNav();
        setupCartControls();
        setupSmoothScroll();
        setupStickyHeader();

        // Initial summary render
        renderSummary();
        renderDrawer();

        // Load Google Reviews
        loadReviews();

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
    function setupSmartSearch() {
        var input    = $('ddSearch');
        var dropdown = $('ddSearchDropdown');
        var clearBtn = $('ddSearchClear');
        var searchEl = $('ddSmartSearch');

        // Fallback to basic search if smart search elements not present
        if (!input) return;
        if (!dropdown) {
            setupSearch();
            return;
        }

        // Extract all product names from DOM for instant suggestions
        var productNames = [];
        document.querySelectorAll('.dd-dish-card').forEach(function(card) {
            var title = card.querySelector('.dd-dish-card__title');
            if (title) {
                productNames.push({
                    name: title.textContent.trim(),
                    id:   card.dataset.id || ''
                });
            }
        });

        var recentSearches  = [];
        var recentLoaded    = false;

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

        /* ── Render dropdown ─────────────────────────────── */
        function renderDropdown(recents, query) {
            var html = '';
            var q = (query || '').toLowerCase().trim();

            if (!q && recents.length > 0) {
                // No query — show recent searches
                html += '<div class="dd-ss__dropdown-section">';
                html += '<div class="dd-ss__dropdown-label">Recently searched</div>';
                recents.forEach(function(s) {
                    html +=
                        '<button class="dd-ss__suggestion" data-query="' + escHtml(s) + '">' +
                        '<span class="dd-ss__sug-icon dd-ss__sug-icon--recent">&#128337;</span>' +
                        '<span class="dd-ss__sug-text">' + escHtml(s) + '</span>' +
                        '<button class="dd-ss__sug-remove" data-remove="' + escHtml(s) + '" tabindex="-1">&#10005;</button>' +
                        '</button>';
                });
                html += '</div>';
            }

            if (q.length >= 1) {
                // Filter recent searches
                var matchRecent = recents.filter(function(s) {
                    return s.toLowerCase().indexOf(q) !== -1;
                });

                // Filter product names
                var matchDishes = productNames.filter(function(p) {
                    return p.name.toLowerCase().indexOf(q) !== -1;
                }).slice(0, 5);

                if (matchRecent.length > 0) {
                    html += '<div class="dd-ss__dropdown-section">';
                    html += '<div class="dd-ss__dropdown-label">Recent matches</div>';
                    matchRecent.forEach(function(s) {
                        html +=
                            '<button class="dd-ss__suggestion" data-query="' + escHtml(s) + '">' +
                            '<span class="dd-ss__sug-icon dd-ss__sug-icon--recent">&#128337;</span>' +
                            '<span class="dd-ss__sug-text">' + highlight(s, query) + '</span>' +
                            '</button>';
                    });
                    html += '</div>';
                }

                if (matchDishes.length > 0) {
                    html += '<div class="dd-ss__dropdown-section">';
                    html += '<div class="dd-ss__dropdown-label">Dishes</div>';
                    matchDishes.forEach(function(p) {
                        html +=
                            '<button class="dd-ss__suggestion" data-query="' + escHtml(p.name) + '">' +
                            '<span class="dd-ss__sug-icon dd-ss__sug-icon--dish">&#127869;</span>' +
                            '<span class="dd-ss__sug-text">' + highlight(p.name, query) + '</span>' +
                            '</button>';
                    });
                    html += '</div>';
                }

                if (!matchRecent.length && !matchDishes.length) {
                    html = '<div class="dd-ss__empty">No results for &ldquo;' + escHtml(query) + '&rdquo;</div>';
                }
            }

            if (!html) { closeDropdown(); return; }

            dropdown.innerHTML = html;
            dropdown.classList.add('open');
            input.setAttribute('aria-expanded', 'true');
        }

        function closeDropdown() {
            dropdown.classList.remove('open');
            dropdown.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
        }

        /* ── Input focus ────────────────────────────────── */
        input.addEventListener('focus', function() {
            var q = this.value.trim();
            if (!recentLoaded) {
                loadRecentSearches(function(searches) {
                    recentSearches = searches;
                    recentLoaded   = true;
                    renderDropdown(recentSearches, q);
                });
            } else {
                renderDropdown(recentSearches, q);
            }
        });

        /* ── Input change ───────────────────────────────── */
        input.addEventListener('input', function() {
            var q = this.value.trim();

            // Show / hide clear button
            if (clearBtn) {
                clearBtn.classList.toggle('visible', q.length > 0);
            }

            // Filter dish cards on the page (existing behaviour)
            filterDishCards(q);

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
            // prevent input blur before click registers
            e.preventDefault();
        });

        dropdown.addEventListener('click', function(e) {
            // Remove button inside suggestion
            var removeBtn = e.target.closest('.dd-ss__sug-remove');
            if (removeBtn) {
                var toRemove = removeBtn.dataset.remove;
                recentSearches = recentSearches.filter(function(s) { return s !== toRemove; });
                renderDropdown(recentSearches, input.value.trim());
                return;
            }

            // Suggestion click — fill input + filter + track
            var suggestion = e.target.closest('.dd-ss__suggestion');
            if (suggestion) {
                var q = suggestion.dataset.query || '';
                input.value = q;
                if (clearBtn) clearBtn.classList.toggle('visible', q.length > 0);
                filterDishCards(q);
                closeDropdown();

                // Track via DDTrack (fires dd_track_event which saves to DB)
                if (window.DDTrack) window.DDTrack.search(q);
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
                if (q) {
                    filterDishCards(q);
                    closeDropdown();
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

        /* ── Sticky behaviour ───────────────────────────── */
        if (searchEl) {
            var hero = document.querySelector('.dd-hero');
            if (hero && window.IntersectionObserver) {
                var stickyObs = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        searchEl.classList.toggle('sticky', !entry.isIntersecting);
                    });
                }, { threshold: 0, rootMargin: '-72px 0px 0px 0px' });
                stickyObs.observe(hero);
            }
        }
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
