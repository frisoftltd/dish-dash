# CSS_REGISTRY.md — Dish Dash `.dd-` Class Reference
> Version 3.1.13 | Generated April 2026 | Every class with the `dd-` prefix, where it lives, and what uses it.

**Legend:**
- File references use paths relative to plugin root.
- Line numbers are from v3.1.13 source.
- ⚠️ = class defined or used in more than one file — all locations listed.

---

## Layout — Containers, Wrappers, Grids

### .dd-page

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` line 79, 115 |
| Used in templates | `templates/page-dishdash.php` (root `<body>` class) |
| Used in JS | None |
| Purpose | Root scope class applied to `<body>` on pages using `page-dishdash.php`. All theme styles are scoped inside `.dd-page` to prevent bleed into other WordPress pages. |
| Known overrides | `assets/css/theme.css` line 125: `.dd-page *` resets box-sizing and font |
| Last modified | v2.4.x |

---

### .dd-container

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` line 143 |
| Used in templates | `templates/page-dishdash.php` (section wrappers) |
| Used in JS | None |
| Purpose | Centered max-width page wrapper (max-width 1200px, auto margins, horizontal padding) |
| Known overrides | None |
| Last modified | v2.2.0 |

---

### .dd-scroll-row ⚠️

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` line 151 |
| Used in templates | `templates/page-dishdash.php` (category, featured dishes, selected category rows) |
| Used in JS | `assets/js/frontend.js` (scroll arrow controls) |
| Purpose | Horizontal overflow scroll container — `display: flex`, `overflow-x: auto`, no wrap |
| Known overrides | `.dd-hide-scrollbar` companion class removes scrollbar chrome (`theme.css` line 161) |
| Last modified | v2.3.x |

---

### .dd-section

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` line 830 |
| Used in templates | `templates/page-dishdash.php` (every major homepage section) |
| Used in JS | None |
| Purpose | Standard page section with 72px top padding and background color |
| Known overrides | None |
| Last modified | v2.2.0 |

---

### .dd-section__top, .dd-section__label, .dd-section__title

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` lines 834, 842, 850 |
| Used in templates | `templates/page-dishdash.php` |
| Purpose | Section heading group — eyebrow label above main title |
| Last modified | v2.2.0 |

---

### .dd-menu-container ⚠️

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu-page.css` line 11 |
| Used in templates | `templates/menu/grid.php` (root wrapper of menu page layout) |
| Used in JS | `assets/js/frontend.js` (used as parent scope for menu page queries) |
| Purpose | Centered max-width wrapper for the restaurant menu page (desktop layout) |
| Known overrides | None |
| Last modified | v3.1.7 |

---

### .dd-menu-page--desktop, .dd-menu-page--mobile

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu-page.css` lines 7, 8 |
| Used in templates | `templates/menu/grid.php` (two separate layout blocks, conditionally shown) |
| Used in JS | `assets/js/frontend.js` (reads to determine which layout is active) |
| Purpose | CSS-driven responsive layout toggle — desktop hidden below 860px, mobile hidden above 860px |
| Known overrides | None |
| Last modified | v3.1.7 |

---

### .dd-menu-wrap

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu.css` line 6 |
| Used in templates | Rendered by `[dish_dash_menu]` shortcode in `DD_Menu_Module` |
| Used in JS | `assets/js/menu.js` (root querySelector, data-columns attribute) |
| Purpose | Root container for the shortcode-based menu widget (non-menu-page context) |
| Known overrides | Responsive overrides at `menu.css` lines 80–130 |
| Last modified | v2.5.x |

---

### .dd-menu-grid

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu.css` line 34 (shortcode grid), `assets/css/menu-page.css` (menu page grid) |
| Used in templates | `templates/menu/grid.php`, `[dish_dash_menu]` shortcode output |
| Used in JS | `assets/js/frontend.js`, `assets/js/menu.js` |
| Purpose | CSS Grid container for product cards (`repeat(var(--dd-cols), 1fr)`) |
| Known overrides | `--dd-cols` CSS variable controlled by `data-columns` attribute on `.dd-menu-wrap` |
| Last modified | v3.1.12 |

---

### .dd-checkout-wrap

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/cart.css` line 251 |
| Used in templates | `templates/checkout/checkout.php`, `templates/cart/cart.php` |
| Used in JS | `assets/js/cart.js` |
| Purpose | Two-column checkout layout wrapper (form left, summary right) |
| Known overrides | None |
| Last modified | v2.0.x |

---

## Components — Cards, Buttons, Forms

### .dd-btn (and variants) ⚠️

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` lines 167–236 |
| Variants | `.dd-btn--sm` (line 186), `.dd-btn--block` (line 187), `.dd-btn--brand` (line 189), `.dd-btn--outline` (line 199), `.dd-btn--soft` (line 209), `.dd-btn--light` (line 219), `.dd-btn--gold` (line 230) |
| Used in templates | `templates/page-dishdash.php` (hero CTAs, filter chips), `templates/cart/cart.php`, `templates/checkout/checkout.php`, shortcode outputs |
| Used in JS | `assets/js/frontend.js` (dynamically generates button HTML) |
| Purpose | Full button system — base class plus modifier variants for brand red, gold, outline, soft, light styles |
| Known overrides | None |
| Last modified | v2.2.0 |

---

### .dd-dish-card ⚠️

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` line 993 |
| Sub-classes | `.dd-dish-card__media` (1010), `.dd-dish-card__tag` (1019), `.dd-dish-card__body` (1032), `.dd-dish-card__title` (1039), `.dd-dish-card__desc` (1047), `.dd-dish-card__footer` (1051) |
| Used in templates | `templates/page-dishdash.php` (Featured Dishes + Selected Category sections) |
| Used in JS | `assets/js/frontend.js` (dynamically renders card HTML via `renderDishCard()` or similar) |
| Purpose | Horizontal scroll food card (290px wide) — used in homepage scroll rows only |
| Known overrides | Not used in menu grid — grid uses `.dd-menu-card` instead |
| Last modified | v2.5.x |

---

### .dd-menu-card ⚠️

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu.css` line 40 |
| Sub-classes | `.dd-card-image` (44), `.dd-card-body` (56), `.dd-card-category` (57), `.dd-card-title` (58), `.dd-card-excerpt` (59), `.dd-card-footer` (62), `.dd-card-price` (63), `.dd-add-to-cart-btn` (68) |
| Used in templates | `templates/menu/grid.php`, `[dish_dash_menu]` shortcode output |
| Used in JS | `assets/js/menu.js` (filter hides/shows via `.dd-hidden` toggle), `assets/js/cart.js` (Add to Cart button), `assets/js/frontend.js` (product modal trigger) |
| Purpose | Grid-layout food product card — used on menu page and shortcode widget |
| Known overrides | `.dd-menu-card.dd-hidden` sets `display:none` (`menu.css` line 42) |
| Last modified | v3.1.12 |

---

### .dd-cat-card ⚠️

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` line 899 |
| Sub-classes | `.dd-cat-card__circle` (915), `.dd-cat-card__circle img` (926), `.dd-cat-card__name` (937), `.dd-cat-card.active .dd-cat-card__circle` (948) |
| Used in templates | `templates/page-dishdash.php` (Browse by Category section) |
| Used in JS | `assets/js/frontend.js` (click handler sets `.active` class, triggers category navigation) |
| Purpose | Circular category image card (200px circle + name below) in homepage category row |
| Known overrides | `.dd-cat-card__initial` for fallback letter avatar (`theme.css` line 2998) |
| Last modified | v2.5.x |

---

### .dd-menu-cat (menu-page category pills) ⚠️

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu-page.css` (no direct line — generated inline in `grid.php`) |
| Sub-classes | `.dd-menu-cat--all`, `.dd-menu-cat__circle`, `.dd-menu-cat__name`, `.dd-menu-cat__initial`, `.dd-menu-cat.is-active` |
| Used in templates | `templates/menu/grid.php` (desktop category carousel) |
| Used in JS | `assets/js/frontend.js` (click → `dd_menu_load_products` AJAX → updates `.dd-menu-grid`) |
| Purpose | Category pill buttons in the restaurant menu page carousel — horizontal scroll row at top |
| Known overrides | `.dd-menu-cat.is-active` applies brand color highlight |
| Last modified | v3.1.7 |

---

### .dd-badge (and variants)

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu.css` line 48 |
| Variants | `.dd-badge--on-sale` (49), `.dd-badge--popular` (50), `.dd-badge--new` (51), `.dd-badge--spicy` (52), `.dd-badge--vegan` (53), `.dd-badge--gluten-free` (54) |
| Used in templates | `templates/menu/grid.php`, `[dish_dash_menu]` shortcode output |
| Used in JS | None |
| Purpose | Pill badge overlaid on card image — color-coded by product type |
| Known overrides | None |
| Last modified | v2.5.x |

---

### .dd-price, .dd-price--original, .dd-price--sale

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu.css` lines 64–66 |
| Used in templates | `templates/menu/grid.php`, shortcode card output |
| Used in JS | None |
| Purpose | Price display — regular price, strikethrough original price, red sale price |
| Known overrides | None |
| Last modified | v2.5.x |

---

### .dd-add-to-cart-btn ⚠️

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu.css` line 68 |
| Used in templates | `templates/menu/grid.php`, shortcode card output |
| Used in JS | `assets/js/menu.js` line ~20 (localStorage cart — TECH DEBT), `assets/js/cart.js` (AJAX cart — canonical), `assets/js/frontend.js` (product modal add to cart) |
| Purpose | "Add" button on product cards — dark rounded pill button |
| Known overrides | None |
| ⚠️ Warning | This selector is targeted by THREE separate JS files with different behaviors — see ARCHITECTURE.md DEBT-002 |
| Last modified | v2.5.x |

---

### .dd-form-group, .dd-form-row

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/cart.css` lines 276, 269 |
| Used in templates | `templates/checkout/checkout.php` |
| Used in JS | None |
| Purpose | Form layout utilities — label + input stacking, two-column row grid |
| Known overrides | None |
| Last modified | v2.0.x |

---

### .dd-submit-btn

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/cart.css` line 336 |
| Used in templates | `templates/checkout/checkout.php` |
| Used in JS | `assets/js/cart.js` (click handler binds here) |
| Purpose | Full-width checkout submit button — brand red, 56px height |
| Known overrides | `.dd-submit-btn:disabled` grays out when cart is empty |
| Last modified | v2.0.x |

---

### .dd-notice, .dd-notice--success, .dd-notice--error

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/cart.css` lines 393, 401, 402 |
| Used in templates | `templates/checkout/checkout.php` |
| Used in JS | `assets/js/cart.js` (dynamically injects notice HTML) |
| Purpose | Inline feedback messages — green success, red error |
| Known overrides | None |
| Last modified | v2.0.x |

---

### .dd-pill

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` line 570 |
| Used in templates | `templates/page-dishdash.php` (hero feature chips) |
| Used in JS | None |
| Purpose | Small rounded chip/tag for hero section feature labels |
| Known overrides | None |
| Last modified | v2.2.0 |

---

### .dd-summary (and sub-classes)

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` line 1206 |
| Sub-classes | `.dd-summary__header` (1216), `.dd-summary__icon` (1224), `.dd-summary__title` (1225), `.dd-summary__badge` (1231), `.dd-summary__list` (1236), `.dd-summary__empty` (1244), `.dd-summary__item` (1250), `.dd-summary__totals` (1264), `.dd-summary__row` (1273), `.dd-summary__row--main` (1278) |
| Used in templates | `templates/page-dishdash.php` (sticky order summary sidebar on right side) |
| Used in JS | `assets/js/frontend.js` — updates `#ddSummaryList`, `#ddSumSubtotal`, `#ddSumTotal` |
| Purpose | Dark-background order summary sidebar visible on homepage |
| Known overrides | None |
| Last modified | v2.5.x |

---

## Menu Page Specific

### .dd-menu-cats, .dd-menu-grid-section, .dd-menu-grid-header, .dd-menu-grid-title, .dd-menu-grid-eyebrow

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu-page.css` |
| Used in templates | `templates/menu/grid.php` (desktop layout only) |
| Used in JS | `assets/js/frontend.js` |
| Purpose | Desktop menu page structural sections — category carousel wrapper and product grid header |
| Known overrides | Hidden on mobile via `menu-page.css` responsive rules |
| Last modified | v3.1.7 |

---

### .dd-filter-bar, .dd-filter-btn, .dd-filter-btn--active

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu.css` lines 29, 30, 32 |
| Used in templates | `[dish_dash_menu]` shortcode output (mobile view + shortcode widget) |
| Used in JS | `assets/js/menu.js` (click handler toggles `.dd-filter-btn--active`, hides/shows `.dd-menu-card`) |
| Purpose | Category filter pill bar above shortcode menu grid |
| Known overrides | None |
| Last modified | v2.5.x |

---

### .dd-search-input, .dd-search-icon

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu.css` lines 25, 27 |
| Used in templates | `[dish_dash_menu]` shortcode output |
| Used in JS | `assets/js/menu.js` (input event listener for live filter) |
| Purpose | Search bar above shortcode menu widget |
| Known overrides | None |
| Last modified | v2.5.x |

---

### .dd-empty-menu, .dd-no-results

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu.css` lines 72, 74 |
| Used in templates | `[dish_dash_menu]` shortcode output |
| Used in JS | `assets/js/menu.js` (shows `.dd-no-results` when filter matches 0 cards) |
| Purpose | Empty state displays — no products exist vs no filter results |
| Known overrides | None |
| Last modified | v2.5.x |

---

### .dd-menu-loadmore (and variants)

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu-page.css` |
| Used in templates | `templates/menu/grid.php` (desktop grid load more) |
| Used in JS | `assets/js/frontend.js` (click → `dd_menu_load_products` AJAX, appends results) |
| Purpose | "Load More" button below desktop menu grid |
| Known overrides | None |
| Last modified | v3.1.7 |

---

## Cart / Checkout Specific

### .dd-cart-trigger, .dd-cart-count

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/cart.css` lines 20, 43 |
| Used in templates | `templates/cart/cart.php` (floating cart button) |
| Used in JS | `assets/js/cart.js` (click opens sidebar), `assets/js/frontend.js` (updates badge count) |
| Purpose | Floating cart button + red item count badge |
| Known overrides | `.dd-cart-top` + `.dd-cart-badge` in `theme.css` are the header cart button — different element |
| Last modified | v2.0.x |

---

### .dd-cart-overlay, .dd-cart-sidebar, .dd-cart-sidebar--open

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/cart.css` lines 58, 74, 90 |
| Used in templates | `templates/cart/cart.php` |
| Used in JS | `assets/js/cart.js` (adds/removes `.dd-cart-sidebar--open` and `.dd-cart-overlay--visible`) |
| Purpose | Slide-in cart sidebar from right + dark overlay |
| Known overrides | None |
| Last modified | v2.0.x |

---

### .dd-cart-item (and sub-classes)

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/cart.css` line 133 |
| Sub-classes | `.dd-cart-item__img` (143), `.dd-cart-item__info` (151), `.dd-cart-item__name` (158), `.dd-cart-item__addons` (165), `.dd-cart-item__price` (171), `.dd-cart-item__controls` (178), `.dd-cart-item__qty` (184), `.dd-cart-item__remove` (194) |
| Used in templates | `templates/cart/cart.php` (rendered dynamically) |
| Used in JS | `assets/js/cart.js` (dynamically generates cart item HTML, quantity controls, remove) |
| Purpose | Individual cart line item — image, name, price, quantity controls, remove |
| Known overrides | None |
| Last modified | v2.0.x |

---

### .dd-cart-summary, .dd-cart-checkout-btn

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/cart.css` lines 207, 231 |
| Used in templates | `templates/cart/cart.php` |
| Used in JS | `assets/js/cart.js` (shows/hides summary, updates totals) |
| Purpose | Cart totals area at bottom of sidebar + Proceed to Checkout button |
| Known overrides | None |
| Last modified | v2.0.x |

---

### .dd-order-type-btn, .dd-order-type-btn--active

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/cart.css` lines 314, 326–327 |
| Used in templates | `templates/checkout/checkout.php` |
| Used in JS | `assets/js/cart.js` (click handler toggles active, shows/hides delivery address form) |
| Purpose | Delivery / Pickup / Dine-in selector cards on checkout form |
| Known overrides | `:has(input:checked)` CSS selector used for radio-driven activation |
| Last modified | v2.0.x |

---

### .dd-checkout-summary, .dd-checkout-item, .dd-checkout-total-row

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/cart.css` lines 354, 370, 381 |
| Used in templates | `templates/checkout/checkout.php` |
| Used in JS | `assets/js/cart.js` (populates summary from cart data) |
| Purpose | Order summary right-column on checkout page |
| Known overrides | None |
| Last modified | v2.0.x |

---

## Homepage Specific

### .dd-header (and sub-classes)

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` line 244 |
| Sub-classes | `.dd-header.scrolled` (256), `.dd-header__inner` (278), `.dd-header__left` (289), `.dd-header__search` (297), `.dd-header__actions` (394) |
| Used in templates | `templates/page-dishdash.php` + injected globally by `DD_Template_Module::inject_global_header()` |
| Used in JS | `assets/js/frontend.js` (scroll listener adds `.scrolled` class) |
| Purpose | Sticky site header — 72px default, shrinks to 58px on scroll |
| Known overrides | `.dd-header.scrolled .dd-header__inner` (line 262), `.dd-header.scrolled .dd-brand__logo` (267), `.dd-header.scrolled .dd-nav` (272) |
| Last modified | v2.4.x |

---

### .dd-brand, .dd-brand__logo, .dd-brand__name, .dd-brand__badge, .dd-brand__sub

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` lines 311, 319, 341, 326, 347 |
| Used in templates | `templates/page-dishdash.php` (header logo area) |
| Used in JS | None |
| Purpose | Logo area — image logo + text name + restaurant badge |
| Known overrides | Shrink on scroll via `.dd-header.scrolled .dd-brand__logo` |
| Last modified | v2.4.x |

---

### .dd-nav, .dd-nav-drawer (and sub-classes)

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` — `.dd-nav` line 356, `.dd-nav-drawer` line 482 |
| Sub-classes | `.dd-nav-drawer.open` (497), `.dd-nav-drawer__header` (499), `.dd-nav-drawer__close` (508), `.dd-nav-drawer__nav` (525), `.dd-nav-drawer__footer` (561) |
| Used in templates | `templates/page-dishdash.php` (desktop nav inline, mobile drawer slide-in) |
| Used in JS | `assets/js/frontend.js` (hamburger toggle, adds `.open` class) |
| Purpose | Desktop: horizontal nav links. Mobile: full-height slide-in drawer from left |
| Known overrides | None |
| Last modified | v2.4.x |

---

### .dd-menu-toggle, .dd-menu-toggle__bar

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` lines 429, 448 |
| Used in templates | `templates/page-dishdash.php` (hamburger button) |
| Used in JS | `assets/js/frontend.js` (click toggles `.open` class, animates bars to X) |
| Purpose | Animated hamburger → X mobile menu button |
| Known overrides | `.dd-menu-toggle.open .dd-menu-toggle__bar:nth-child(n)` (lines 459, 462, 466) |
| Last modified | v2.4.x |

---

### .dd-hero (and sub-classes)

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` line 587 |
| Sub-classes | `.dd-hero::after` (594, dark gradient overlay), `.dd-hero::before` (607, brand color overlay), `.dd-hero__grid` (618), `.dd-hero__title` (628), `.dd-hero__copy` (637), `.dd-hero__actions` (645), `.dd-hero__chips` (653), `.dd-hero__chip` (660), `.dd-hero__card` (672), `.dd-hero__img` (684), `.dd-hero__overlay` (691), `.dd-hero__overlay-top` (705), `.dd-hero__overlay-badge` (712) |
| Used in templates | `templates/page-dishdash.php` (hero section) |
| Used in JS | None |
| Purpose | Full-width hero section — dark background + food image + brand overlay + CTA |
| Known overrides | `--dd-hero-overlay-color` and `--dd-hero-overlay-opacity` CSS variables set by PHP from settings |
| Last modified | v2.5.x |

---

### .dd-cart-top, .dd-cart-badge

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` lines 396, 413 |
| Used in templates | `templates/page-dishdash.php` (header cart button) |
| Used in JS | `assets/js/frontend.js` — updates `#ddCartCount` inside `.dd-cart-badge` |
| Purpose | Header cart button with item count badge (different from floating `.dd-floating-cart`) |
| Known overrides | Not the same as `.dd-cart-trigger` in cart.css |
| Last modified | v2.4.x |

---

### .dd-floating-cart

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` line 1714 |
| Sub-classes | `.dd-floating-cart__text` (1736) |
| Used in templates | `templates/page-dishdash.php` (fixed position cart button bottom-right) |
| Used in JS | `assets/js/frontend.js` (cart count `#ddFloatingCount`) |
| Purpose | Fixed "View Order" button — visible on mobile, hidden at small breakpoint when `.dd-bottom-nav` shows |
| Known overrides | Hidden at ≤ 680px |
| Last modified | v2.4.x |

---

### .dd-bottom-nav (and sub-classes)

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` line 1741 |
| Used in templates | `templates/page-dishdash.php` (fixed bottom mobile nav) |
| Used in JS | `assets/js/frontend.js` (cart badge `#ddBottomBadge`) |
| Purpose | 4-tab mobile bottom navigation (Home, Menu, Reserve, Cart) |
| Known overrides | `display: none` by default — shown at ≤ 680px via media query |
| Last modified | v2.4.x |

---

## Smart Search Classes

### .dd-ss__bar, .dd-ss__dropdown, .dd-ss__suggestion (and sub-classes) ⚠️

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` lines 2241–2323 |
| Sub-classes | `.dd-ss__dropdown-section` (2256), `.dd-ss__dropdown-label` (2262), `.dd-ss__suggestion` (2271), `.dd-ss__sug-icon` (2289), `.dd-ss__sug-text` (2303), `.dd-ss__highlight` (2304), `.dd-ss__sug-remove` (2306), `.dd-ss__empty` (2325) |
| Used in templates | `templates/page-dishdash.php` (smart search bar in header) |
| Used in JS | `assets/js/frontend.js` (search input, dropdown, recent searches, product results) |
| Purpose | Smart search autocomplete — recent searches + product results dropdown |
| Known overrides | `.dd-ss__bar--mobile-expand` (line 2924) for mobile variant |
| Last modified | v3.1.x |

---

### .dd-ss__result-card, .dd-ss__results-grid

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` lines 2364, 2370; also redefined at line 2984 |
| ⚠️ Warning | `.dd-ss__result-card` is defined TWICE in theme.css (lines 2370 and 2984) — second definition adds animation. Second definition wins for shared properties. |
| Used in templates | `templates/page-dishdash.php` (search results display) |
| Used in JS | `assets/js/frontend.js` (dynamically renders result cards) |
| Purpose | Product search result cards in dropdown and expanded search panel |
| Last modified | v3.1.x |

---

### .dd-menu-search-wrap

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` line 2333 |
| Used in templates | `templates/page-dishdash.php` (search bar wrapper in header) |
| Used in JS | `assets/js/frontend.js` (focus-within triggers expanded state) |
| Purpose | Search bar container — expands on focus via `:focus-within` |
| Known overrides | `.dd-menu-search-wrap:focus-within` (line 2345) |
| Last modified | v3.1.x |

---

## Product Modal

### .dd-product-modal (and sub-classes)

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` lines 2438–2613 |
| Sub-classes | `.dd-product-modal.open` (2446), `.dd-product-modal__overlay` (2454), `.dd-product-modal__wrap` (2463), `.dd-product-modal__close` (2494), `.dd-pm__img-wrap` (2516), `.dd-pm__body` (2538), `.dd-pm__name` (2542), `.dd-pm__desc` (2550), `.dd-pm__footer` (2557), `.dd-pm__price` (2566), `.dd-pm__qty-wrap` (2573), `.dd-pm__qty-btn` (2582), `.dd-pm__qty` (2597), `.dd-pm__add` (2605) |
| Used in templates | `templates/page-dishdash.php` (modal markup injected at page bottom) |
| Used in JS | `assets/js/frontend.js` (opens on dish card click, quantity controls, add to cart) |
| Purpose | Full product detail modal — image, description, quantity selector, add to cart |
| Known overrides | None |
| Last modified | v3.1.x |

---

## Auth Modal

### .dd-auth-modal (and sub-classes)

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` lines 2633–2850 |
| Sub-classes | `.dd-auth-modal.open` (2641), `.dd-auth-modal__wrap` (2652), `.dd-auth-panel` (2691), `.dd-auth-google-btn` (2722), `.dd-auth-divider` (2747), `.dd-auth-field` (2764), `.dd-auth-submit` (2827), `.dd-auth-switch` (2830), `.dd-auth-msg` (2838), `.dd-auth-msg--error` (2846), `.dd-auth-msg--success` (2847) |
| Used in templates | Injected by `DD_Auth_Module::inject_auth_modal()` in `wp_footer` |
| Used in JS | `assets/js/frontend.js` (modal open/close, form submission, Google OAuth redirect) |
| Purpose | Login / Register modal dialog — email/password + Google OAuth |
| Known overrides | None |
| Last modified | v3.1.x (Auth Module) |

---

## Utilities

### .dd-serif, .dd-gold

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` lines 137, 138 |
| Used in templates | `templates/page-dishdash.php` (decorative text) |
| Purpose | `.dd-serif` applies Cormorant Garamond; `.dd-gold` applies gold-soft color |
| Last modified | v2.2.0 |

---

### .dd-hide-scrollbar

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/theme.css` lines 161–162 |
| Used in templates | `templates/page-dishdash.php` (on `.dd-scroll-row` elements) |
| Purpose | Hides scrollbar chrome while keeping scroll functionality |
| Last modified | v2.2.0 |

---

### .dd-hidden

| Aspect | Detail |
|---|---|
| Defined in | `assets/css/menu.css` line 42 (`.dd-menu-card.dd-hidden { display: none }`) |
| Used in JS | `assets/js/menu.js` (toggled on cards during category filter and search) |
| Purpose | Utility class to hide menu cards during client-side filter |
| Known overrides | Must always be scoped to `.dd-menu-card.dd-hidden` — a bare `.dd-hidden` with `display: flex !important` would override the `hidden` HTML attribute (see CLAUDE.md rule 10) |
| Last modified | v2.5.x |

---

### .dd-type-badge, .dd-status-badge (admin only)

| Aspect | Detail |
|---|---|
| Defined in | Inline in `admin/class-dd-admin.php` `get_admin_styles()` PHP method |
| Used in templates | `admin/pages/orders.php` (order table rows) |
| Purpose | Color-coded badges for order type (delivery/pickup/dine-in/pos) and status in admin |
| ⚠️ Warning | Defined inline in PHP — not in any CSS file. See ARCHITECTURE.md DEBT-003. |
| Last modified | v2.5.x |

---

*Last audited: v3.1.13, April 2026*
