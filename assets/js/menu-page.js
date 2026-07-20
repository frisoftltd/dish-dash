/**
 * File:    assets/js/menu-page.js
 * Purpose: AJAX-driven category navigation and paginated product grid
 *          loading for the desktop menu page (/restaurant-menu/).
 *          Also syncs initial state from the ?cat= URL deep-link param.
 *          Arrow scroll (prev/next) initialises without requiring DDMenu.
 *
 * DOM elements required:
 *   - #ddMenuGrid        (product grid container, data-current-cat attribute)
 *   - #ddMenuLoadMore    (load-more button, data-page attribute)
 *   - #ddMenuCatsTrack   (category carousel scroll container)
 *   - #ddMenuCatsPrev, #ddMenuCatsNext  (arrow buttons)
 *   - #ddMenuGridTitle   (grid section heading, updated on category click)
 *   - .dd-menu-cat       (category buttons, data-cat-slug attribute)
 *
 * Localized data needed (wp_localize_script):
 *   - window.DDMenu  (ajaxUrl, nonce, action='dd_menu_load_products', perPage)
 *     Localized by DD_Menu_Module::enqueue_menu_assets()
 *
 * AJAX endpoints called:
 *   - admin-ajax.php?action=dd_menu_load_products  (cat_slug, page, per_page)
 *   - admin-ajax.php?action=dd_cart_add            (id, name, price, qty, image, variation, addons, note)
 *
 * Custom events fired:   None
 * Custom events listened: None
 *
 * Dependencies:
 *   - window.DDTrackConfig (optional — from tracking.js, used for category view events)
 *
 * Dependents:
 *   - modules/menu/class-dd-menu-module.php (enqueues this on menu page)
 *   - templates/menu/grid.php (DOM elements rendered here)
 *
 * Last modified: v3.1.18
 */
(function () {
    'use strict';

    var grid         = document.getElementById('ddMenuGrid');
    var loadMore     = document.getElementById('ddMenuLoadMore');
    var loadMoreWrap = loadMore ? loadMore.parentNode : null;
    var catTrack     = document.getElementById('ddMenuCatsTrack');
    var catPrev      = document.getElementById('ddMenuCatsPrev');
    var catNext      = document.getElementById('ddMenuCatsNext');
    var gridTitle    = document.getElementById('ddMenuGridTitle');

    // ── Arrow scroll — no DDMenu dependency, init immediately ──────────
    function scrollCats(dir) {
        if (!catTrack) return;
        var item = catTrack.querySelector('.dd-menu-cat');
        if (!item) return;
        var step = (item.offsetWidth + 24) * 3;
        catTrack.scrollBy({ left: dir * step, behavior: 'smooth' });
    }
    if (catPrev) catPrev.addEventListener('click', function () { scrollCats(-1); });
    if (catNext) catNext.addEventListener('click', function () { scrollCats(1); });

    // ── AJAX features require DDMenu localization ──────────────────────
    if (!grid || !loadMore || !catTrack) return;
    if (typeof DDMenu === 'undefined') return;

    // ── Sync JS state with ?cat= URL param (server pre-filtered) ──────
    (function () {
        var params   = new URLSearchParams(window.location.search);
        var catParam = params.get('cat') || '';
        if (!catParam) return;

        // Server already set data-current-cat in HTML; confirm it matches.
        grid.setAttribute('data-current-cat', catParam);

        // Fire one category_view tracking event for deep-link traffic.
        var trackCfg = window.DDTrackConfig || {};
        if (trackCfg.ajaxUrl) {
            var body = new URLSearchParams({
                action:      'dd_track_event',
                nonce:       trackCfg.nonce      || '',
                session_id:  trackCfg.sessionId  || '',
                event_type:  'view_category',
                product_id:  '',
                category_id: '',
                meta:        JSON.stringify({ slug: catParam, source: 'deep_link' }),
            });
            fetch(trackCfg.ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    body.toString(),
                keepalive: true,
            }).catch(function () {});
        }
    })();

    catTrack.addEventListener('click', function (e) {
        var btn = e.target.closest('.dd-menu-cat');
        if (!btn) return;

        catTrack.querySelectorAll('.dd-menu-cat').forEach(function (b) {
            b.classList.remove('is-active');
        });
        btn.classList.add('is-active');

        var slug = btn.getAttribute('data-cat-slug') || '';

        // Update grid section heading to reflect active category
        if (gridTitle) {
            if (slug === '') {
                gridTitle.textContent = 'All Dishes';
            } else {
                var nameEl = btn.querySelector('.dd-menu-cat__name');
                gridTitle.textContent = nameEl ? nameEl.textContent.trim() : 'All Dishes';
            }
        }

        grid.setAttribute('data-current-cat', slug);
        loadMore.setAttribute('data-page', '1');

        loadProducts(slug, 1, true);
    });

    loadMore.addEventListener('click', function () {
        if (loadMore.classList.contains('is-loading')) return;
        var slug = grid.getAttribute('data-current-cat') || '';
        var page = parseInt(loadMore.getAttribute('data-page'), 10) + 1;
        loadProducts(slug, page, false);
    });


    function loadProducts(catSlug, page, replace) {
        loadMore.classList.add('is-loading');

        var formData = new FormData();
        formData.append('action', 'dd_menu_load_products');
        formData.append('nonce', DDMenu.nonce);
        formData.append('cat_slug', catSlug);
        formData.append('page', String(page));

        fetch(DDMenu.ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                loadMore.classList.remove('is-loading');
                if (!data || !data.success) {
                    console.error('DD Menu load failed', data);
                    return;
                }
                if (replace) {
                    grid.innerHTML = data.data.html;
                } else {
                    grid.insertAdjacentHTML('beforeend', data.data.html);
                }

                // Disable Add to Cart if restaurant is closed
                if (window.DD && (window.DD.hours_state === 'closed' || window.DD.hours_state === 'break')) {
                    document.querySelectorAll('.dd-add-btn').forEach(function(btn) {
                        btn.disabled = true;
                        btn.textContent = "We're Closed";
                        btn.classList.add('dd-add-btn--closed');
                    });
                }

                loadMore.setAttribute('data-page', String(data.data.page));
                if (loadMoreWrap) {
                    loadMoreWrap.style.display = data.data.has_more ? '' : 'none';
                }
            })
            .catch(function (err) {
                console.error('DD Menu fetch error', err);
                loadMore.classList.remove('is-loading');
            });
    }
})();

/**
 * DDMobileMenu - Handles the 3-screen mobile menu navigation
 * and product interactions for the mobile app interface.
 */
class DDMobileMenu {
    constructor() {
        this.currentScreen = 'categories';
        this.currentCategory = null;
        this.currentProduct = null;
        this.favorites = new Set();
        this.cartCount = 0;

        this.initElements();
        this.bindEvents();
        this.loadInitialData();
    }

    debounce(fn, delay) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    initElements() {
        this.screens = {
            categories: document.querySelector('.dd-mobile-screen--categories'),
            products: document.querySelector('.dd-mobile-screen--products'),
            single: document.querySelector('.dd-mobile-screen--single')
        };

        this.elements = {
            catList:            document.getElementById('dd-mobile-cat-list'),
            catsEmpty:          document.querySelector('.dd-mobile-cats-empty'),
            searchResultsWrap:  document.getElementById('dd-mobile-search-results'),
            searchResultsList:  document.getElementById('dd-mobile-search-results-list'),
            searchResultsEmpty: document.querySelector('.dd-mobile-search-results__empty'),
            catPills:           document.getElementById('dd-mobile-cat-pills'),
            productList: document.getElementById('dd-mobile-product-list'),
            backToCats: document.getElementById('dd-mobile-back-to-cats'),
            backToProducts: document.getElementById('dd-mobile-back-to-products'),
            searchInput: document.querySelector('.dd-mobile-search__input'),
            searchClear: document.querySelector('.dd-mobile-search-clear'),
            cartBadge: document.querySelector('.dd-mobile-cart-badge'),
            bottomNavCart: document.getElementById('dd-bottom-nav-cart-count'),
            singleProduct: {
                heroImg: document.getElementById('dd-mobile-single-hero-img'),
                name: document.getElementById('dd-mobile-single-name'),
                price: document.getElementById('dd-mobile-single-price'),
                rating: document.getElementById('dd-mobile-single-rating'),
                prepTime: document.getElementById('dd-mobile-single-prep'),
                desc: document.getElementById('dd-mobile-single-desc'),
                attrs: document.getElementById('dd-mobile-single-attrs'),
                heart: document.getElementById('dd-mobile-single-heart'),
                qtyMinus: document.getElementById('dd-mobile-qty-minus'),
                qtyPlus: document.getElementById('dd-mobile-qty-plus'),
                qtyCount: document.getElementById('dd-mobile-qty-count'),
                addToCart: document.getElementById('dd-mobile-add-to-cart')
            }
        };
    }

    bindEvents() {
        // Category list click
        if (this.elements.catList) {
            this.elements.catList.addEventListener('click', (e) => {
                const item = e.target.closest('.dd-mobile-category-item');
                if (!item) return;

                this.currentCategory = {
                    id: item.dataset.catId,
                    slug: item.dataset.catSlug
                };
                this.showScreen('products');
                this.loadProductsForCategory(this.currentCategory.id);

                if (this.elements.catPills) {
                    Array.from(this.elements.catPills.children).forEach(function(p) {
                        p.classList.remove('is-active');
                    });
                    var activePill = this.elements.catPills.querySelector('[data-cat-slug="' + item.dataset.catSlug + '"]');
                    if (activePill) {
                        activePill.classList.add('is-active');
                        var container = this.elements.catPills;
                        requestAnimationFrame(function() {
                            var pillLeft = activePill.offsetLeft;
                            var pillWidth = activePill.offsetWidth;
                            var containerWidth = container.offsetWidth;
                            container.scrollLeft = pillLeft - (containerWidth / 2) + (pillWidth / 2);
                        });
                    }
                }
            });
        }

        // Back buttons
        if (this.elements.backToCats) {
            this.elements.backToCats.addEventListener('click', () => {
                this.showScreen('categories');
                if (this.elements.searchInput) this.elements.searchInput.value = '';
                this.filterCategories();
                this.searchProducts();
            });
        }
        if (this.elements.backToProducts) {
            this.elements.backToProducts.addEventListener('click', () => this.showScreen('products'));
        }

        // Category pills
        if (this.elements.catPills) {
            this.elements.catPills.addEventListener('click', (e) => {
                const pill = e.target.closest('.dd-mobile-cat-pill');
                if (!pill) return;

                Array.from(this.elements.catPills.children).forEach(p =>
                    p.classList.remove('is-active')
                );
                pill.classList.add('is-active');
                var container = this.elements.catPills;
                requestAnimationFrame(function() {
                    var pillLeft = pill.offsetLeft;
                    var pillWidth = pill.offsetWidth;
                    var containerWidth = container.offsetWidth;
                    container.scrollLeft = pillLeft - (containerWidth / 2) + (pillWidth / 2);
                });
                this.currentCategory = {
                    id: pill.dataset.catId,
                    slug: pill.dataset.catSlug || ''
                };
                this.loadProductsForCategory(this.currentCategory.id);
            });
        }

        // Product list click — check quick-add button first, then open detail
        if (this.elements.productList) {
            this.elements.productList.addEventListener('click', (e) => {
                const quickAdd = e.target.closest('.dd-mobile-product-card__quick-add');
                if (quickAdd) {
                    e.stopPropagation();
                    const card = quickAdd.closest('.dd-mobile-product-card');
                    this.showProductDetails(card.dataset.id);
                    return;
                }
                // Otherwise open product detail
                const card = e.target.closest('.dd-mobile-product-card');
                if (!card) return;

                // Desktop: use the shared product modal instead of mobile screen nav
                if (window.innerWidth >= 1025) {
                    document.dispatchEvent(new CustomEvent('dd:open-modal', { detail: { productId: card.dataset.id } }));
                    return;
                }

                this.currentProduct = {
                    id: card.dataset.id,
                    name: card.dataset.name,
                    isSimple: card.dataset.isSimple === 'true'
                };
                this.showProductDetails(this.currentProduct.id);
            });
        }

        // Search results list click — mirrors productList listener but no desktop modal branch
        if (this.elements.searchResultsList) {
            this.elements.searchResultsList.addEventListener('click', (e) => {
                const quickAdd = e.target.closest('.dd-mobile-product-card__quick-add');
                if (quickAdd) {
                    e.stopPropagation();
                    const card = quickAdd.closest('.dd-mobile-product-card');
                    this.showProductDetails(card.dataset.id);
                    return;
                }
                const card = e.target.closest('.dd-mobile-product-card');
                if (!card) return;
                this.showProductDetails(card.dataset.id);
            });
        }

        // Single product interactions
        if (this.elements.singleProduct.heart) {
            this.elements.singleProduct.heart.addEventListener('click', () => {
                this.toggleFavorite(this.currentProduct.id);
            });
        }

        if (this.elements.singleProduct.qtyMinus) {
            this.elements.singleProduct.qtyMinus.addEventListener('click', () => {
                this.adjustQuantity(-1);
            });
        }

        if (this.elements.singleProduct.qtyPlus) {
            this.elements.singleProduct.qtyPlus.addEventListener('click', () => {
                this.adjustQuantity(1);
            });
        }

        if (this.elements.singleProduct.addToCart) {
            this.elements.singleProduct.addToCart.addEventListener('click', () => {
                this.addToCart();
            });
        }

        // Attribute pill selection (radio behaviour within each group)
        if (this.elements.singleProduct.attrs) {
            this.elements.singleProduct.attrs.addEventListener('click', (e) => {
                const pill = e.target.closest('.dd-mobile-attr-pill');
                if (!pill) return;

                const group = pill.closest('.dd-mobile-attr-group__pills');
                if (!group) return;

                // Deactivate all pills in this group
                group.querySelectorAll('.dd-mobile-attr-pill').forEach(p => p.classList.remove('is-active'));

                // Activate clicked pill
                pill.classList.add('is-active');

                // Store selection on currentProduct
                const groupEl = pill.closest('.dd-mobile-attr-group');
                const label = groupEl?.querySelector('.dd-mobile-attr-group__label')?.textContent?.trim();
                if (!this.currentProduct.selectedAttributes) {
                    this.currentProduct.selectedAttributes = {};
                }
                if (label) {
                    this.currentProduct.selectedAttributes[label] = pill.textContent.trim();
                }

                // Variable products: match the current selection to a variation and
                // show its authoritative price; store variation_id for add-to-cart.
                const variations = this.currentProduct.variations || [];
                let variationOk = true;
                if (variations.length) {
                    const match = this.findMatchingVariation(variations, this.currentProduct.selectedAttributes);
                    if (match) {
                        this.currentProduct.selectedVariationId = match.variation_id;
                        this.elements.singleProduct.price.textContent = `RWF ${Number(match.price).toLocaleString()}`;
                    } else {
                        this.currentProduct.selectedVariationId = 0;
                    }
                    variationOk = this.currentProduct.selectedVariationId > 0;
                }

                const totalSelected = Object.keys(this.currentProduct.selectedAttributes).length;
                const allSelected = totalSelected >= (this.currentProduct.requiredSelections || 0);
                const addBtn = this.elements.singleProduct.addToCart;
                if (addBtn) {
                    if (window.DD && (window.DD.hours_state === 'closed' || window.DD.hours_state === 'break')) {
                        addBtn.disabled = true;
                        addBtn.textContent = "We're Closed";
                        addBtn.classList.add('dd-add-btn--closed');
                    } else if (allSelected && variationOk) {
                        addBtn.disabled = false;
                        addBtn.classList.remove('is-disabled');
                    } else {
                        // Not all options chosen, or no matching variation → block add
                        // (never allow adding a variable product at the parent price).
                        addBtn.disabled = true;
                        addBtn.classList.add('is-disabled');
                    }
                }
            });
        }

        // Search
        if (this.elements.searchInput) {
            const debouncedSearch = this.debounce(() => {
                const onCategoryScreen = document.querySelector('.dd-mobile-screen--categories.is-active');
                if (onCategoryScreen) {
                    this.filterCategories();
                    this.searchProducts();
                } else {
                    this.filterProducts();
                }
            }, 300);

            this.elements.searchInput.addEventListener('input', debouncedSearch);
        }

        if (this.elements.searchClear) {
            this.elements.searchClear.addEventListener('click', () => {
                this.elements.searchInput.value = '';
                this.filterProducts();
            });
        }
    }

    loadInitialData() {
        if (!window.DD_MOBILE_DATA) return;

        // Load categories
        if (this.elements.catList && DD_MOBILE_DATA.categories) {
            // Already rendered server-side
        }

        // Load products
        if (DD_MOBILE_DATA.products) {
            this.products = DD_MOBILE_DATA.products;
        }

        // Update cart count
        this.updateCartCount(DD_MOBILE_DATA.cartCount || 0);

        // Deep link: if ?cat= param present, navigate directly to product list
        var deepCat = new URLSearchParams(window.location.search).get('cat');
        if (deepCat && this.elements.catList) {
            var item = this.elements.catList.querySelector('[data-cat-slug="' + deepCat + '"]');
            if (item) {
                this.currentCategory = {
                    id: item.dataset.catId,
                    slug: item.dataset.catSlug
                };
                this.loadProductsForCategory(item.dataset.catId);
                this.showScreen('products');
                if (this.elements.catPills) {
                    Array.from(this.elements.catPills.children).forEach(function(p) {
                        p.classList.remove('is-active');
                    });
                    var activePill = this.elements.catPills.querySelector('[data-cat-slug="' + deepCat + '"]');
                    if (activePill) {
                        activePill.classList.add('is-active');
                        var container = this.elements.catPills;
                        requestAnimationFrame(function() {
                            var pillLeft = activePill.offsetLeft;
                            var pillWidth = activePill.offsetWidth;
                            var containerWidth = container.offsetWidth;
                            container.scrollLeft = pillLeft - (containerWidth / 2) + (pillWidth / 2);
                        });
                    }
                }
            }
        }
    }

    showScreen(screenName) {
        if (!this.screens[screenName]) return;

        if (typeof window.DDCart !== 'undefined') window.DDCart.sync();

        this.currentScreen = screenName;

        Object.values(this.screens).forEach(screen => {
            screen.classList.remove('is-active');
            screen.setAttribute('aria-hidden', 'true');
        });

        this.screens[screenName].classList.add('is-active');
        this.screens[screenName].setAttribute('aria-hidden', 'false');
    }

    loadProductsForCategory(categoryId) {
        if (!this.products) return;

        const filteredProducts = this.products.filter(
            p => p.category_ids.includes(parseInt(categoryId))
        );

        this.renderProductList(filteredProducts);
    }

    renderProductList(products) {
        if (!this.elements.productList) return;

        const isClosed = window.DD && (window.DD.hours_state === 'closed' || window.DD.hours_state === 'break');

        this.elements.productList.innerHTML = products.map(product => {
            return `
                <li class="dd-mobile-product-card"
                    data-id="${product.id}"
                    data-name="${product.name.toLowerCase()}"
                    data-is-simple="${product.is_simple}">
                    <div class="dd-mobile-product-card__image">
                        <img src="${product.image_url || product.image_thumbnail_url || ''}" alt="${product.name}" loading="lazy" onerror="this.style.opacity='0'" />
                        <button class="dd-mobile-product-card__heart ${this.favorites.has(product.id) ? 'is-fav' : ''}"
                                aria-label="${this.favorites.has(product.id) ? 'Remove from favorites' : 'Add to favorites'}">
                            ${this.getHeartSVG()}
                        </button>
                    </div>
                    <div class="dd-mobile-product-card__info">
                        <div class="dd-mobile-product-card__top-row">
                            <h3 class="dd-mobile-product-card__name">${product.name}</h3>
                            <span class="dd-mobile-product-card__rating">${product.rating || ''}</span>
                        </div>
                        <p class="dd-mobile-product-card__description">${product.short_description || ''}</p>
                        <div class="dd-mobile-product-card__bottom-row">
                            <span class="dd-mobile-product-card__price">RWF ${product.price.toLocaleString()}</span>
                            <button class="dd-mobile-product-card__quick-add${isClosed ? ' dd-add-btn--closed' : ''}"
                                    aria-label="Add ${product.name} to cart"
                                    ${isClosed ? 'disabled' : ''}>
                                ${isClosed ? "We're Closed" : 'Add to Cart'}
                            </button>
                        </div>
                    </div>
                </li>
            `;
        }).join('');
    }

    showProductDetails(productId) {
        // Always fully initialise currentProduct before any early return
        this.currentProduct = {
            id: parseInt(productId),
            selectedAttributes: {},
            requiredSelections: 0,
            variations: [],
            selectedVariationId: 0
        };

        if (!this.products) return;

        const product = this.products.find(p => p.id === parseInt(productId));
        if (!product) return;

        const { singleProduct } = this.elements;

        // Update UI with product details
        singleProduct.heroImg.src = product.image_url || product.image_thumbnail_url || '';
        singleProduct.heroImg.alt = product.name;
        singleProduct.heroImg.style.display = '';
        singleProduct.name.textContent = product.name;
        // Variable products: keep the variations for match-on-select, and show the
        // lowest variation price as the default (updated when a size is chosen).
        // Simple products show the parent price unchanged.
        this.currentProduct.variations = Array.isArray(product.variations) ? product.variations : [];
        const displayPrice = this.currentProduct.variations.length
            ? Math.min.apply(null, this.currentProduct.variations.map(v => Number(v.price)))
            : product.price;
        singleProduct.price.textContent = `RWF ${Number(displayPrice).toLocaleString()}`;
        singleProduct.rating.textContent = product.rating || '';
        singleProduct.prepTime.textContent = product.prep_time ? `${product.prep_time} min` : '';
        singleProduct.desc.textContent = product.description || '';

        // Update favorite heart
        singleProduct.heart.classList.toggle('is-fav', this.favorites.has(product.id));

        // Reset quantity
        singleProduct.qtyCount.textContent = '1';

        // Render attributes if available
        if (product.attributes && product.attributes.length > 0) {
            singleProduct.attrs.innerHTML = product.attributes.map(attr => {
                return `
                    <div class="dd-mobile-attr-group">
                        <span class="dd-mobile-attr-group__label">${attr.name}</span>
                        <div class="dd-mobile-attr-group__pills">
                            ${attr.options.map(opt =>
                                `<span class="dd-mobile-attr-pill">${opt}</span>`
                            ).join('')}
                        </div>
                    </div>
                `;
            }).join('');

            // Reset selectedAttributes — require user to select before adding
            this.currentProduct.selectedAttributes = {};
            this.currentProduct.requiredSelections = product.attributes.length;
            this.currentProduct.selectedVariationId = 0;

            const addBtn = this.elements.singleProduct.addToCart;
            if (addBtn) {
                addBtn.disabled = true;
                if (window.DD && (window.DD.hours_state === 'closed' || window.DD.hours_state === 'break')) {
                    addBtn.textContent = "We're Closed";
                    addBtn.classList.add('dd-add-btn--closed');
                } else {
                    addBtn.classList.add('is-disabled');
                }
            }
        } else {
            singleProduct.attrs.innerHTML = '';
            if (this.currentProduct) {
                this.currentProduct.selectedAttributes = {};
                this.currentProduct.requiredSelections = 0;
            }
            const addBtn = this.elements.singleProduct.addToCart;
            if (addBtn) {
                if (window.DD && (window.DD.hours_state === 'closed' || window.DD.hours_state === 'break')) {
                    addBtn.disabled = true;
                    addBtn.textContent = "We're Closed";
                    addBtn.classList.add('dd-add-btn--closed');
                } else {
                    addBtn.disabled = false;
                    addBtn.classList.remove('is-disabled');
                }
            }
        }

        this.showScreen('single');
        this.renderRelatedProducts(product);

        // Disable Add to Cart if restaurant is closed
        if (window.DD && (window.DD.hours_state === 'closed' || window.DD.hours_state === 'break')) {
            var mobileAddBtn = document.getElementById('dd-mobile-add-to-cart');
            if (mobileAddBtn) {
                mobileAddBtn.disabled = true;
                mobileAddBtn.textContent = "We're Closed";
                mobileAddBtn.classList.add('dd-add-btn--closed');
            }
        }
    }

    renderRelatedProducts(product) {
        const relatedContainer = document.getElementById('dd-mobile-related');
        const relatedList = document.getElementById('dd-mobile-related-list');
        if (!relatedList || !this.products) return;

        const sameCat = this.products.filter(p =>
            p.id !== parseInt(product.id) &&
            p.category_ids.some(id => product.category_ids.includes(id))
        );
        const shuffled = sameCat.sort(() => Math.random() - 0.5).slice(0, 8);

        if (shuffled.length === 0) {
            relatedContainer.style.display = 'none';
            return;
        }
        relatedContainer.style.display = '';

        relatedList.innerHTML = shuffled.map(p => `
            <li class="dd-mobile-related-card" data-id="${p.id}">
                <div class="dd-mobile-related-card__img-wrap">
                    <img src="${p.image_url || p.image_thumbnail_url || ''}"
                         alt="${p.name}" loading="lazy"
                         onerror="this.style.opacity='0'" />
                </div>
                <div class="dd-mobile-related-card__info">
                    <span class="dd-mobile-related-card__name">${p.name}</span>
                    <span class="dd-mobile-related-card__price">RWF ${p.price.toLocaleString()}</span>
                </div>
            </li>
        `).join('');

        // Clone to remove any previously attached listeners before re-adding
        const newList = relatedList.cloneNode(true);
        relatedList.parentNode.replaceChild(newList, relatedList);
        newList.addEventListener('click', (e) => {
            const card = e.target.closest('.dd-mobile-related-card');
            if (!card) return;
            this.currentProduct = { id: card.dataset.id, selectedAttributes: {} };
            this.showProductDetails(card.dataset.id);
            document.querySelector('.dd-mobile-screen--single').scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    toggleFavorite(productId) {
        if (this.favorites.has(productId)) {
            this.favorites.delete(productId);
            this.elements.singleProduct.heart.classList.remove('is-fav');
        } else {
            this.favorites.add(productId);
            this.elements.singleProduct.heart.classList.add('is-fav');
        }

        // Sync with server
        this.saveFavorites();
    }

    adjustQuantity(change) {
        const { qtyCount } = this.elements.singleProduct;
        let qty = parseInt(qtyCount.textContent) + change;
        qty = Math.max(1, qty); // Don't go below 1
        qtyCount.textContent = qty;
    }

    // Find the variation whose attributes all match the current pill selection.
    // Match rule: every attribute the variation defines must equal the selected
    // value for that label. Returns the variation object or null.
    findMatchingVariation(variations, selected) {
        if (!Array.isArray(variations) || !variations.length) return null;
        return variations.find(v => {
            const attrs = v.attributes || {};
            const keys = Object.keys(attrs);
            if (!keys.length) return false;
            return keys.every(k => selected[k] === attrs[k]);
        }) || null;
    }

    addToCartById(productId, qty, selectedAttributes = {}) {
        const product = this.products.find(p => p.id === parseInt(productId));
        if (!product) return;

        // variation_id is authoritative server-side; a variable product must have a
        // matched variation here (the Add button stays disabled until it does).
        const variationId = this.currentProduct ? (this.currentProduct.selectedVariationId || 0) : 0;

        const formData = new FormData();
        formData.append('action', 'dd_cart_add');
        formData.append('nonce', DD_MOBILE_DATA.cart_nonce);
        formData.append('id', productId);
        formData.append('name', product.name);
        formData.append('price', product.price);
        formData.append('qty', qty);
        formData.append('image', product.image_thumbnail_url || product.image_url || '');
        formData.append('variation', JSON.stringify(selectedAttributes));
        formData.append('variation_id', variationId);
        formData.append('addons', JSON.stringify([]));
        formData.append('note', '');

        const btn = this.elements.singleProduct.addToCart;
        if (btn) { btn.disabled = true; btn.textContent = 'Adding...'; }

        fetch(DD_MOBILE_DATA.ajax_url, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                console.log('[DD Cart] response:', JSON.stringify(data));
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = 'Add To Cart <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';
                }
                if (data.success) {
                    const newCount = data.data?.count ?? data.data?.cart_count ?? (this.cartCount + qty);
                    this.updateCartCount(newCount);
                    if (typeof window.DDCart !== 'undefined') window.DDCart.refresh();
                    if (typeof window.showToast === 'function') window.showToast('✓ Added to cart!');
                    // Track add to cart from mobile menu
                    if (window.DDTrack) window.DDTrack.addToCart(productId, null);
                } else {
                    console.error('Add to cart failed', data);
                }
            })
            .catch(err => {
                console.error('[DD Cart] fetch error:', err);
                if (btn) { btn.disabled = false; }
            });
    }

    addToCart() {
        console.log('[DD Cart] currentProduct:', JSON.stringify(this.currentProduct));
        console.log('[DD Cart] ajax_url:', DD_MOBILE_DATA.ajax_url);
        console.log('[DD Cart] cart_nonce:', DD_MOBILE_DATA.cart_nonce);
        if (!this.currentProduct || !this.currentProduct.id) {
            console.error('[DD Cart] No current product set');
            return;
        }
        const qty = parseInt(this.elements.singleProduct.qtyCount.textContent);
        this.addToCartById(
            this.currentProduct.id,
            qty,
            this.currentProduct.selectedAttributes || {}
        );
    }

    filterProducts() {
        const searchTerm = this.elements.searchInput.value.toLowerCase().trim();

        if (!searchTerm) {
            // Show all products when search is empty
            this.loadProductsForCategory(this.currentCategory.id);
            return;
        }

        const filtered = this.products.filter(p =>
            p.name.toLowerCase().includes(searchTerm) &&
            p.category_ids.includes(parseInt(this.currentCategory.id))
        );

        this.renderProductList(filtered);
    }

    filterCategories() {
        const term = this.elements.searchInput
            ? this.elements.searchInput.value.toLowerCase().trim()
            : '';

        if (!this.elements.catList) return;

        const items = this.elements.catList.querySelectorAll('.dd-mobile-category-item');
        let visibleCount = 0;

        items.forEach(item => {
            const name = item.querySelector('.dd-mobile-category-item__name');
            const match = !term || (name && name.textContent.toLowerCase().includes(term));
            item.style.display = match ? '' : 'none';
            if (match) visibleCount++;
        });

        if (this.elements.catsEmpty) {
            this.elements.catsEmpty.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    }

    searchProducts() {
        const term = this.elements.searchInput
            ? this.elements.searchInput.value.toLowerCase().trim()
            : '';

        const wrap  = this.elements.searchResultsWrap;
        const list  = this.elements.searchResultsList;
        const empty = this.elements.searchResultsEmpty;

        if (!wrap || !list) return;

        // Hide results section when search is empty
        if (!term) {
            wrap.style.display = 'none';
            list.innerHTML = '';
            return;
        }

        // Filter products by name
        const matches = (this.products || []).filter(p =>
            p.name && p.name.toLowerCase().includes(term)
        );

        wrap.style.display = 'block';

        if (matches.length === 0) {
            list.innerHTML = '';
            if (empty) empty.style.display = 'block';
            return;
        }

        if (empty) empty.style.display = 'none';

        // Reuse existing renderProductList() to build the HTML
        // Temporarily swap the list element, render, then restore
        const originalList = this.elements.productList;
        this.elements.productList = list;
        this.renderProductList(matches);
        this.elements.productList = originalList;
    }

    updateCartCount(count) {
        this.cartCount = count;
        // Update all badge elements used across cart.js and menu-page.js
        const ids = ['dd-bottom-nav-cart-count', 'ddBottomBadge', 'ddCartCount', 'ddCartBtnCount'];
        ids.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.textContent = count;
                el.dataset.count = count;
                el.style.display = count > 0 ? '' : 'none';
            }
        });
    }

    saveFavorites() {
        if (!window.DD_MOBILE_DATA || !DD_MOBILE_DATA.ajax_url) return;

        const formData = new FormData();
        formData.append('action', 'dd_save_favorites');
        formData.append('nonce', DD_MOBILE_DATA.nonce);
        formData.append('favorites', JSON.stringify(Array.from(this.favorites)));

        fetch(DD_MOBILE_DATA.ajax_url, {
            method: 'POST',
            body: formData
        }).catch(err => {
            console.error('Failed to save favorites', err);
        });
    }

    getHeartSVG() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if (document.querySelector('.dd-mobile-app')) {
        new DDMobileMenu();
    }
});
