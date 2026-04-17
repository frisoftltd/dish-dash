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
 * Last modified: v3.1.13
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

    initElements() {
        this.screens = {
            categories: document.querySelector('.dd-mobile-screen--categories'),
            products: document.querySelector('.dd-mobile-screen--products'),
            single: document.querySelector('.dd-mobile-screen--single')
        };
        
        this.elements = {
            catList: document.getElementById('dd-mobile-cat-list'),
            catPills: document.getElementById('dd-mobile-cat-pills'),
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
            });
        }

        // Back buttons
        if (this.elements.backToCats) {
            this.elements.backToCats.addEventListener('click', () => this.showScreen('categories'));
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
                
                this.currentCategory.id = pill.dataset.catId;
                this.loadProductsForCategory(this.currentCategory.id);
            });
        }

        // Product list click
        if (this.elements.productList) {
            this.elements.productList.addEventListener('click', (e) => {
                const card = e.target.closest('.dd-mobile-product-card');
                if (!card) return;
                
                this.currentProduct = {
                    id: card.dataset.id,
                    name: card.dataset.name,
                    isSimple: card.dataset.isSimple === 'true'
                };
                this.showProductDetails(this.currentProduct.id);
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

        // Search
        if (this.elements.searchInput) {
            this.elements.searchInput.addEventListener('input', () => {
                this.filterProducts();
            });
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
    }

    showScreen(screenName) {
        if (!this.screens[screenName]) return;
        
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
        
        this.elements.productList.innerHTML = products.map(product => {
            return `
                <li class="dd-mobile-product-card" 
                    data-id="${product.id}" 
                    data-name="${product.name.toLowerCase()}"
                    data-is-simple="${product.is_simple}">
                    <div class="dd-mobile-product-card__image">
                        <img src="${product.image}" alt="${product.name}" loading="lazy" />
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
                            <button class="dd-mobile-product-card__quick-add" 
                                    aria-label="Add ${product.name} to cart">
                                + Add
                            </button>
                        </div>
                    </div>
                </li>
            `;
        }).join('');
    }

    showProductDetails(productId) {
        if (!this.products) return;
        
        const product = this.products.find(p => p.id === parseInt(productId));
        if (!product) return;
        
        const { singleProduct } = this.elements;
        
        // Update UI with product details
        singleProduct.heroImg.src = product.image;
        singleProduct.heroImg.alt = product.name;
        singleProduct.name.textContent = product.name;
        singleProduct.price.textContent = `RWF ${product.price.toLocaleString()}`;
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
        } else {
            singleProduct.attrs.innerHTML = '';
        }
        
        this.showScreen('single');
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

    addToCart() {
        const { qtyCount } = this.elements.singleProduct;
        const qty = parseInt(qtyCount.textContent);
        
        // Simulate adding to cart
        this.updateCartCount(this.cartCount + qty);
        
        // Show feedback
        alert(`${qty} ${this.currentProduct.name} added to cart`);
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

    updateCartCount(count) {
        this.cartCount = count;
        
        if (this.elements.cartBadge) {
            this.elements.cartBadge.textContent = count;
        }
        
        if (this.elements.bottomNavCart) {
            this.elements.bottomNavCart.textContent = count;
            this.elements.bottomNavCart.dataset.count = count;
        }
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