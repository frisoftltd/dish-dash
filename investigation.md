# Investigation — add_to_cart call sites (Phase 1, read-only)

Scope: locate every place `dd_cart_add` is actually fired from `assets/js/frontend.js`
and `assets/js/menu-page.js`, what product data is in scope at each site, whether
`ddTrack`/`window.gtag` are reachable there, and whether both files are genuinely
enqueued on the frontend. No files changed.

---

## 1. Files exist

```
assets/js/frontend.js
assets/js/menu-page.js
```

Confirmed both present under `assets/`.

---

## 2. `dd_cart_add` / add-to-cart hits

```
assets/js/frontend.js:24:    - admin-ajax.php?action=dd_cart_add
assets/js/frontend.js:191:  function addToCart(productId, quantity, btn) {
assets/js/frontend.js:208:      action:     'dd_cart_add',
assets/js/frontend.js:1069:    action:     'dd_cart_add',
assets/js/frontend.js:1318:  if (window.wc_add_to_cart_params && window.wc_add_to_cart_params.cart_url) {

assets/js/menu-page.js:22:   - admin-ajax.php?action=dd_cart_add (id, name, price, qty, image, variation, addons, note)
assets/js/menu-page.js:230:  addToCart: document.getElementById('dd-mobile-add-to-cart')
assets/js/menu-page.js:370-373:  addToCart button click -> this.addToCart()
assets/js/menu-page.js:789:  addToCartById(productId, qty, selectedAttributes = {}) {
assets/js/menu-page.js:798:    formData.append('action', 'dd_cart_add');
assets/js/menu-page.js:811:  const btn = this.elements.singleProduct.addToCart;
assets/js/menu-page.js:828:  if (window.DDTrack) window.DDTrack.addToCart(productId, null);
assets/js/menu-page.js:839:  addToCart() {
assets/js/menu-page.js:848:  this.addToCartById(
```

### Key finding — `frontend.js:191` `addToCart(productId, quantity, btn)` is dead code

Grepped the whole repo (JS + PHP templates) for callers: **zero**. Nothing calls
`addToCart(` in `frontend.js` — no click handler wires it, no inline `onclick` in
any template. The homepage card's `.dd-add-btn` (`templates/partials/product-card.php:79`)
has no handler of its own; its click bubbles to the delegated listener at
`frontend.js:1276` (`document.addEventListener('click', ...)` -> `closest('.dd-dish-card')`
-> `openProductModal()`), because the button lives inside the card. So clicking
"Add" on a homepage/menu card **opens the modal**, it does not add anything.
This matches the existing CLAUDE.md note from v3.11.6 ("no quick-add bypass exists
... cards open the modal").

**The one real add-to-cart call site in `frontend.js`** is the product modal's Add
button, inside `renderModal()` -> `pmAdd.addEventListener('click', ...)` at
**line 1052-1100** (the `dd_cart_add` fetch is at line 1069).

### `menu-page.js` — mobile

`quick-add` on the product list (`.dd-mobile-product-card__quick-add`, wired at
lines 306-315 and 336-344) does **not** add to cart either — it calls
`showProductDetails(card.dataset.id)`, i.e. opens the single-product screen. Same
non-bypass pattern as desktop.

**The one real add-to-cart call site in `menu-page.js`** is `addToCartById()`
(line 789-837), reached only via the class method `addToCart()` (839-853), which
is reached only via the single-product screen's Add button click handler
(370-373: `this.elements.singleProduct.addToCart.addEventListener('click', () =>
this.addToCart())`).

**So, codebase-wide, there are exactly two places an item is actually added to
the cart:** the desktop product modal (`frontend.js`) and the mobile single-product
screen (`menu-page.js`). Everything else (homepage card, mobile card quick-add) is
a router into one of these two, not a third add path.

---

## 3. Data in scope at each real call site

### A. `frontend.js` — modal Add button (`pmAdd` click, ~line 1052)

This handler is nested inside `renderModal(productId, name, price, desc, imgSrc)`
(line 978), so all of its parameters are closure-captured and available at the
`fetch` call:

- `productId` — yes, string/number id.
- `name` — yes, but it's whatever text was scraped from the DOM card
  (`.dd-dish-card__title`) or the `dd_get_product` fallback response — plain string,
  ready to use as `item_name`.
- `price` — yes, but it's a **display string** (e.g. `"RWF 5,000"`, or
  `escHtml(price)` of that), not a bare number. Getting a clean numeric `value` for
  GA4 needs either parsing this string (fragile — locale/format-dependent) or
  reading `ddPmVariations`' matched price / the enrichment response's raw price
  (`p.price`, fetched separately in `fetchProductEnrichment`, async, may not have
  landed by the time Add is clicked for a no-variation product — needs checking if
  it's stored anywhere numeric). Simplest reliable path: skip `value`/`price` in
  the `items[]` payload unless a numeric price is confirmed available, exactly as
  the `purchase`/`add_payment_info` events already do (`data.total` from the
  server response, not scraped text).
- `qty` — yes, local `var qty` in the same closure, current stepper value.

Net: `item_name` and `quantity` are solid; a numeric `price`/`value` is not
guaranteed without extra work. Simplest correct v1: fire `add_to_cart` with
`{ currency: 'RWF', items: [{ item_id: productId, item_name: name, quantity: qty }] }`
and add `value`/`price` only if a follow-up decides to thread the numeric price
through (e.g. from the `dd_cart_add` AJAX response, if it echoes back a price —
not confirmed in this read-only pass, would need a quick read of the PHP handler).

### B. `menu-page.js` — `addToCartById(productId, qty, selectedAttributes)`

- `product` — full object, looked up via `this.products.find(p => p.id ===
  parseInt(productId))` (line 790). `product.name` and `product.price` (line
  801-802) are sent straight to the server as form fields, so they're already
  known to be the right shape/type for that product (numeric price, presumably —
  matches what `DD_API::get_products()` returns, localized wholesale into
  `DD_MOBILE_DATA.products` in `grid.php:338`).
- `qty` — yes, parameter.
- `productId` — yes, parameter.

Net: this site has everything GA4 wants — `item_name`, numeric `price`, `qty` —
with no scraping/parsing needed. This is the stronger of the two sites for a full
`items[]` payload.

---

## 4. `ddTrack` / `window.gtag` reachability

```
frontend.js:  no "ddTrack", no "gtag" (bare), no "window.gtag", no "ga4Id".
              Only "window.ddCartData" appears (10 sites, all reading
              ajax_url/nonce) — confirming frontend.js DOES receive the
              same localized ddCartData object cart.js uses (wp_localize_script
              binds it to the 'dish-dash-cart' handle; ddCartData is a bare
              `var` on `window`, so any script that loads after it on the same
              page can read it). ga4Id is on that object as of v3.13.0
              (`ddCartData.ga4Id`) but nothing in frontend.js reads it today.

menu-page.js: zero matches for all five patterns (ddTrack, window.gtag, gtag,
              ddCartData, ga4Id). It doesn't read ddCartData at all — it has its
              own localized object, DD_MOBILE_DATA (bound to the 'dd-menu-page'
              handle in templates/menu/grid.php:336), which does NOT currently
              carry ga4Id.
```

**`ddTrack()` itself is a local (non-exported) function defined inside `cart.js`'s
IIFE** (`assets/js/cart.js`, added in v3.13.0, right after the CONFIG block). It
is not attached to `window`, so neither `frontend.js` nor `menu-page.js` can call
it — confirmed, this needs a small redefinition (or a single shared global) in
whichever file(s) fire `add_to_cart`.

Both files **do** see `window.gtag` at runtime once GA4 is loaded, because
`gtag.js`'s inline bootstrap script (`class-dd-template-module.php`,
`enqueue_frontend_assets()`) defines `window.gtag` globally, and it's enqueued on
every page `is_dishdash_page()` returns true for — independent of which module
enqueues which of `frontend.js`/`menu-page.js`/`cart.js`. So a tiny local
`ddTrack` guarded by `if (window.gtag)` in each file will work exactly like
cart.js's, with no import/dependency wiring needed — just duplicate the 3-line
guard function (or promote it to a genuinely shared global — see §6).

---

## 5. Enqueue confirmation — and a wrinkle the brief's grep would have missed

```
modules/template/class-dd-template-module.php:293:
  wp_enqueue_script( 'dish-dash-frontend', $this->asset_url( 'js', 'frontend.js' ), [ 'dish-dash-search' ], DD_VERSION, true );
```

`frontend.js` **is** enqueued in the template module, gated by
`enqueue_frontend_assets()` -> `is_dishdash_page()` (true on the homepage, cart,
checkout, birthday, my-account, track-order, and any page using the
`page-dishdash.php`/`page-simple.php` templates).

`menu-page.js` is **not** in `class-dd-template-module.php` at all — the brief's
grep (`modules/template/class-dd-template-module.php frontend/`) would have
returned nothing for it and looked like a gap. It's actually enqueued from a
**different module**:

```
modules/menu/class-dd-menu-module.php:153-177:
  public function enqueue_menu_assets(): void {
      if ( ! $this->is_menu_page() ) return;
      ...
      wp_enqueue_script( 'dd-menu-page', DD_ASSETS_URL . 'js/menu-page.js', [], DD_VERSION, true );
      wp_localize_script( 'dd-menu-page', 'DDMenu', [ 'ajaxUrl' => ..., 'nonce' => ... ] );
  }
```

gated by its own `is_menu_page()` (checks the stored `dish_dash_menu_page_id`
option, falling back to slug matching).

**Both are confirmed enqueued on the pages where their add-to-cart flow lives.**
GA4's `gtag.js` bootstrap, however, is gated by `is_dishdash_page()` in the
*template* module, which checks the **literal slug** `is_page('restaurant-menu')`
— not the stored `dish_dash_menu_page_id` option that `is_menu_page()` in the menu
module uses. On the default install these agree (both resolve to the same page),
but if a restaurant renames/relocates their menu page, `is_menu_page()` would
still enqueue `menu-page.js` there (option-based) while `is_dishdash_page()` could
miss it (slug-based) — meaning `add_to_cart` fires (once wired) but `window.gtag`
might not exist on that page, and `ddTrack`'s guard silently no-ops. Pre-existing
gap, unrelated to this task, flagging since it directly affects whether the new
event reaches GA4 in a non-default setup.

---

## 6. Answering the four questions from the brief

**Where does add-to-cart actually fire?**
Exactly two places, both identified above: `frontend.js` modal Add button
(desktop, plus the >=1025px-width branch of mobile since menu-page.js dispatches
`dd:open-modal` to reuse this same modal above that breakpoint), and
`menu-page.js` `addToCartById()` (mobile single-product screen, <1025px). No
third path — the two "quick add" buttons (homepage card, mobile card list) both
just open one of these two flows rather than adding directly.

**What product data is in scope?**
`menu-page.js`'s site has full clean data (`name`, numeric `price`, `qty`) with no
extra work. `frontend.js`'s site has `name` and `qty` cleanly, but `price` is a
formatted display string, not a number — getting a numeric `value` there needs
either string-parsing (fragile) or sourcing the number from somewhere else (the
`dd_cart_add` AJAX response, if it echoes back a price — not confirmed here,
would need a quick read of the PHP handler in a Phase 2 pass if the brief wants
`value` included). Firing a `value`-less `add_to_cart` (`items[]` with just
`item_name`/`quantity`) is the safe v1 shape for the `frontend.js` site; the
`menu-page.js` site can carry the full shape from day one.

**Does `ddTrack` need to be redefined?**
Yes. It's a private function inside `cart.js`'s IIFE, not on `window`. Two options
for the implementation brief to choose between: (a) copy the same 3-line
`function ddTrack(event, params){ if (window.gtag) gtag('event', event, params ||
{}); }` into each of `frontend.js` and `menu-page.js` (consistent with how each
file already duplicates its own `ajaxUrl`/`nonce` resolution rather than sharing
a module), or (b) hoist one copy onto `window.ddTrack` from wherever loads first
and have all three files call `window.ddTrack(...)`. Cart.js currently loads
before frontend.js on pages where both are enqueued (`dish-dash-frontend`
depends on `dish-dash-search`, not on `dish-dash-cart` — so load order isn't
guaranteed by WP's dependency graph even though both are typically enqueued
together), and menu-page.js is enqueued by an entirely different module with no
dependency edge to cart.js at all — so hoisting onto `window` would need an
explicit dependency edge added to be safe, whereas copying the tiny guard has no
ordering requirement. Given the project's existing style (each cart-ish file
re-resolves its own `ajaxUrl`/`nonce` rather than importing a shared helper),
duplicating the guard is the lower-risk, more consistent-with-precedent choice —
noting it here for the brief to make the actual call.

**Overlap / double-count risk?**
None found. The two real add sites are in different files, wired to different
buttons, and the desktop-modal-via-mobile-dispatch path (`dd:open-modal`) routes
through `frontend.js`'s single Add button — it does not also go through
`menu-page.js`'s `addToCartById()`. Each user action that results in a cart line
touches exactly one `dd_cart_add` call site. No dedup logic needed beyond "put the
`ddTrack('add_to_cart', ...)` call next to the existing `res.success` branch" at
each of the two sites (mirroring where `menu-page.js` already fires its own
internal `DDTrack.addToCart(productId, null)` at line 828, and where `frontend.js`
shows the "Added!" state).

---

## Summary for the implementation brief

- Two files, two call sites: `frontend.js` line ~1081 (`res.success` branch inside
  the `pmAdd` click handler, alongside the existing `showToast('Added to cart!')`),
  and `menu-page.js` line ~822-828 (`data.success` branch inside
  `addToCartById()`, alongside the existing `if (window.DDTrack)
  window.DDTrack.addToCart(...)` call).
- `menu-page.js` can carry a full `items[]` payload (`item_name`, numeric
  `price`, `quantity`) immediately. `frontend.js` should ship a simpler
  event (no numeric `value`) unless the brief wants to also solve the
  price-is-a-string problem.
- Both files need their own tiny `ddTrack` guard (dead-simple copy from
  cart.js) — no shared/global helper exists yet, and none is required to make
  this work.
- `frontend.js`'s dead `addToCart(productId, quantity, btn)` function (line 191)
  is unrelated to this work — flagging only so a future release doesn't
  mistakenly wire tracking into it thinking it's live.
- No double-counting risk between the two files.
- Pre-existing, unrelated gap: `is_dishdash_page()`'s slug-based menu-page
  detection could, in a non-default setup, disagree with `is_menu_page()`'s
  option-based detection — meaning `gtag.js` might not load on a renamed menu
  page even though `menu-page.js` (and the new tracking call) does. Not blocking,
  just noting it since it affects whether this specific event reaches GA4 in that
  edge case.

**STOP — read-only. Awaiting the implementation brief (v3.13.1).**
