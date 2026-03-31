/**
 * Dish Dash — Frontend JS
 * Handles all interactivity for the DishDash page template.
 * Works with pre-rendered PHP category rows and WooCommerce AJAX cart.
 *
 * @package DishDash
 * @since   2.5.8
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

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
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
        .catch(() => {});
    }

    /* ══════════════════════════════════════════════════════════
       LOCAL CART HELPERS
    ══════════════════════════════════════════════════════════ */
    function addToLocalCart(id, name, price) {
        const existing = cartItems.find((i) => i.id === id);
        if (existing) {
            existing.qty += 1;
        } else {
            cartItems.push({ id, name, price, qty: 1 });
        }
    }

    function parseRWF(str) {
        return parseInt(String(str).replace(/[^0-9]/g, ''), 10) || 0;
    }

    /* ══════════════════════════════════════════════════════════
       BIND ADD-TO-CART BUTTONS IN A ROW
    ══════════════════════════════════════════════════════════ */
    function bindAddBtns(container) {
        $all('.dd-add-btn', container).forEach((btn) => {
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

        // Hide all category rows, show the target
        $all('.dd-cat-row').forEach((row) => {
            if (row.id === 'ddCatRow-' + slug) {
                row.removeAttribute('hidden');
                bindAddBtns(row);
            } else {
                row.setAttribute('hidden', '');
            }
        });

        // Update selected category title
        const titleEl = $('ddSelCatTitle');
        if (titleEl && name) titleEl.textContent = name;
    }

    /* ══════════════════════════════════════════════════════════
       HORIZONTAL SCROLL ARROWS
    ══════════════════════════════════════════════════════════ */
    function setupArrows(prevId, nextId, rowId) {
        const prev = $(prevId);
        const next = $(nextId);
        const row  = typeof rowId === 'string' ? $(rowId) : rowId;
        if (!prev || !next || !row) return;

        const scroll = (dir) => {
            row.scrollBy({ left: dir * 280, behavior: 'smooth' });
        };

        prev.addEventListener('click', () => scroll(-1));
        next.addEventListener('click', () => scroll(1));
    }

    /* ══════════════════════════════════════════════════════════
       SEARCH
    ══════════════════════════════════════════════════════════ */
    function setupSearch() {
        const input = $('ddSearchInput');
        if (!input) return;

        input.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();

            $all('.dd-dish-card').forEach((card) => {
                const title = ($q('.dd-dish-card__title', card) || {}).textContent || '';
                card.style.display = (!q || title.toLowerCase().includes(q)) ? '' : 'none';
            });
        });
    }

    /* ══════════════════════════════════════════════════════════
       MODE BUTTONS (Delivery / Pickup)
    ══════════════════════════════════════════════════════════ */
    function setupModeButtons() {
        const btns = $all('.dd-mode-btn');
        btns.forEach((btn) => {
            btn.addEventListener('click', () => {
                btns.forEach((b) => b.classList.remove('active'));
                btn.classList.add('active');
            });
        });
    }

    /* ══════════════════════════════════════════════════════════
       FILTER CHIPS
    ══════════════════════════════════════════════════════════ */
    function setupChips() {
        const chips = $all('.dd-chip');
        chips.forEach((chip) => {
            chip.addEventListener('click', () => {
                chips.forEach((c) => c.classList.remove('active'));
                chip.classList.add('active');

                const tag = chip.dataset.tag || '';

                $all('.dd-dish-card').forEach((card) => {
                    if (!tag || card.dataset.tags?.split(',').includes(tag)) {
                        card.style.display = '';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        });
    }

    /* ══════════════════════════════════════════════════════════
       DESKTOP GRID + LOAD MORE
    ══════════════════════════════════════════════════════════ */
    function setupDesktopGrid() {
        const loadMoreBtn = $('ddLoadMore');
        if (!loadMoreBtn) return;

        loadMoreBtn.addEventListener('click', () => {
            const hidden = $all('.dd-dish-card[data-hidden="1"]');
            let shown = 0;
            hidden.forEach((card) => {
                if (shown < 4) {
                    card.removeAttribute('data-hidden');
                    card.style.display = '';
                    shown++;
                }
            });
            if ($all('.dd-dish-card[data-hidden="1"]').length === 0) {
                loadMoreBtn.style.display = 'none';
            }
        });
    }

    /* ══════════════════════════════════════════════════════════
       STICKY HEADER
    ══════════════════════════════════════════════════════════ */
    function setupStickyHeader() {
        const header = $q('.dd-header');
        if (!header) return;

        window.addEventListener('scroll', () => {
            if (window.scrollY > 60) {
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
       GOOGLE REVIEWS
       Calls the dd_get_reviews AJAX action (PHP handles the
       Google Places API call server-side, so the API key is
       never exposed in the browser).
       Results are cached on the server for 12 hours.
    ══════════════════════════════════════════════════════════ */
    function loadReviews() {
        // Support both id conventions: ddReviewsGrid (new) and reviewsGrid (old)
        var grid = $('ddReviewsGrid') || $('reviewsGrid');
        if (!grid) return;
        if (!window.DD || !window.DD.ajaxUrl) return;

        // Show skeleton cards while loading
        grid.innerHTML = [1, 2, 3].map(function () {
            return '<div class="dd-review-card dd-review-card--skeleton">' +
                '<div class="dd-review-skel dd-review-skel--stars"></div>' +
                '<div class="dd-review-skel dd-review-skel--line"></div>' +
                '<div class="dd-review-skel dd-review-skel--line dd-review-skel--short"></div>' +
                '<div class="dd-review-skel dd-review-skel--author"></div>' +
                '</div>';
        }).join('');

        fetch(window.DD.ajaxUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams({
                action: 'dd_get_reviews',
                nonce:  window.DD.nonce || '',
            }).toString(),
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success || !res.data || !res.data.length) {
                grid.innerHTML = '';
                return;
            }

            grid.innerHTML = res.data.map(function (r) {
                // Build star icons
                var stars = '';
                for (var i = 1; i <= 5; i++) {
                    stars += '<span class="dd-review-star' + (i <= r.rating ? ' filled' : '') + '">★</span>';
                }

                // Author avatar: photo if available, else initial letter
                var avatar = r.photo
                    ? '<img class="dd-review-photo" src="' + escHtml(r.photo) + '" alt="" loading="lazy">'
                    : '<div class="dd-review-avatar">' + escHtml((r.author || 'G').charAt(0).toUpperCase()) + '</div>';

                return '<div class="dd-review-card">' +
                    '<div class="dd-review-stars">' + stars + '</div>' +
                    '<p class="dd-review-text">' + escHtml(r.text) + '</p>' +
                    '<div class="dd-review-footer">' +
                        avatar +
                        '<div>' +
                            '<strong class="dd-review-author">' + escHtml(r.author) + '</strong>' +
                            (r.time ? '<span class="dd-review-time">' + escHtml(r.time) + '</span>' : '') +
                        '</div>' +
                    '</div>' +
                '</div>';
            }).join('');
        })
        .catch(function () {
            // On error just clear the skeleton — section still shows title
            grid.innerHTML = '';
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
        setupSearch();
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

        // Load Google Reviews from server
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
       BOOT
    ══════════════════════════════════════════════════════════ */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
