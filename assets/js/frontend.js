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
        const row  = $(rowId);
        const prev = $(prevId);
        const next = $(nextId);
        if (!row || !prev || !next) return;

        // Clone to remove old listeners
        const newPrev = prev.cloneNode(true);
        const newNext = next.cloneNode(true);
        prev.parentNode.replaceChild(newPrev, prev);
        next.parentNode.replaceChild(newNext, next);

        newPrev.addEventListener('click', () => row.scrollBy({ left: -340, behavior: 'smooth' }));
        newNext.addEventListener('click', () => row.scrollBy({ left:  340, behavior: 'smooth' }));
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

                var filter = chip.dataset.filter || '';
                if ( ! featRow ) return;

                $all('.dd-dish-card', featRow).forEach( function(card) {
                    if ( ! filter ) {
                        card.style.display = '';
                        return;
                    }
                    var cardFilter = (card.dataset.filter || '').toLowerCase();
                    var tagEl      = $q('.dd-dish-card__tag', card);
                    var tagText    = tagEl ? tagEl.textContent.toLowerCase() : '';
                    var matches    = cardFilter.indexOf(filter) !== -1 || tagText.indexOf(filter) !== -1;
                    card.style.display = matches ? '' : 'none';
                });
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

        // Category card clicks
        $all('.dd-cat-card').forEach((card) => {
            card.addEventListener('click', () => {
                $all('.dd-cat-card').forEach((c) => c.classList.remove('active'));
                card.classList.add('active');
                switchCategory(card.dataset.slug, card.dataset.name);
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

        // Other setups
        setupSearch();
        setupModeButtons();
        setupChips();
        setupMobileNav();
        setupBottomNav();
        setupCartControls();
        setupSmoothScroll();

        // Initial summary render
        renderSummary();
        renderDrawer();

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
