# INVESTIGATION — Variable products: variation price is ignored (Half charged as Full)

**Read-only. No code changes.** Plugin: dish-dash (universal). Surfaced on: nyarutarama, v3.11.4.
5 products are now WooCommerce **variable** (Size: Half / Full). The menu shows and the cart charges the
**parent** price regardless of the selected size — selecting **Half** still shows/charges **Full**.

Root cause is **confirmed from source** below (exact `file:line`). Line numbers are from the local repo
checkout (`C:\dish-dash`).

---

## The full flow: API price → render → selection → POST → cart price

```
WC variable product ("Biryani", Size: Half 3,000 / Full 5,000)
        │
   [1] DD_API::map_product()            → price = parent get_price()  (ONE number)
        │                                  attributes = [{name:"Size", options:["Half","Full"]}]  (labels only)
        ▼
   menu-page.js  loadProducts()         → product.price, product.attributes
        │
   [2] renderSingleProduct()            → #dd-mobile-single-price = "RWF " + product.price   (parent)
        │                                  renders pills into #dd-mobile-single-attrs
        │
   [pill click] attrs handler           → selectedAttributes["Size"] = "Half"   (TEXT)
        │                                  ❌ price element NOT updated
        ▼
   [3] addToCartById()                  → POST id, price=product.price(parent), qty,
        │                                       variation = JSON.stringify(selectedAttributes)  (TEXT)
        │                                  ❌ no variation_id posted
        ▼
   [4] DD_Cart::ajax_add()              → $price = (float) $product->get_price()   (PARENT — posted price ignored)
        │                                  variation stored as TEXT only
        ▼
   cart line: parent price + "Half" label   →  charged as Full
```

No step ever resolves **Size=Half → a variation → that variation's price**. The price is the parent's from
first render to final charge.

---

## Layer-by-layer evidence

### 1. API layer — no per-variation price or id is sent

**`dishdash-core/class-dd-api.php` — `map_product()` (`:573-595`)** is the normalizer behind the product list:

- `:577` `'price' => (float) $product->get_price()` — for a `WC_Product_Variable`, `get_price()` returns the
  single price WooCommerce syncs onto the parent (by default the **min** active-variation price; exact value is
  config-dependent). It is **one fixed number, independent of any selection**. This is what the UI shows and what
  the cart re-derives — hence "Half shows/charges the same as Full."
- `:560-571` `attributes` = visible attributes normalized to `[{ 'name' => label, 'options' => [names…] }]`.
  **Option labels only** — no `variation_id`, no attribute→value map, no per-option price.
- `:557` exposes `is_simple = $product->is_type('simple')` — the ONLY variable-vs-simple signal sent. There is
  **no `variations[]` array** in the output.

**Desktop modal has the same gap.** `dishdash-core/class-dd-ajax.php` — `ajax_get_product()` (`:70`) outputs
`:101 'price' => (float) $product->get_price()` (parent), `:102 'price_html' => $product->get_price_html()` (a
**range** string like "RWF 3,000 – RWF 5,000", never the selected price), and `:92-99` the same `{name, options}`
attributes — again no variation id/price.

> Grep result: `get_available_variations`, `get_variation_prices`, `WC_Product_Variable`, `variation_id` →
> **zero matches** in `dishdash-core/`.

### 2. Frontend display — price never recalculates on selection (`assets/js/menu-page.js`)

- `renderSingleProduct()` `:569` `singleProduct.price.textContent = ` `` `RWF ${product.price.toLocaleString()}` ``
  → binds `#dd-mobile-single-price` (`templates/menu/grid.php:297`) to the **parent** price, once.
- `:581-593` renders pills (`.dd-mobile-attr-pill`) from `product.attributes` into `#dd-mobile-single-attrs`
  (`grid.php:301`).
- **Pill click handler `:377-412`:** stores the label — `:397`
  `this.currentProduct.selectedAttributes[label] = pill.textContent.trim()` — and enables the Add button when all
  groups are chosen (`:400-411`). It **never touches the price element.** → **Selecting Half does not change the
  displayed price.**

Selector markup + IDs that a fix must drive:
- Price element: `#dd-mobile-single-price` (JS ref `singleProduct.price`).
- Attributes container: `#dd-mobile-single-attrs` (JS ref `singleProduct.attrs`); pills `.dd-mobile-attr-pill`,
  groups `.dd-mobile-attr-group` / `.dd-mobile-attr-group__label`.
- Selection state: `this.currentProduct.selectedAttributes` (object of label→optionText).

### 3. Add to cart — POST carries the text label, no variation_id

- `addToCartById()` `:709-719` POSTs to `dd_cart_add`: `id`, `name`, **`price` = `product.price` (parent)**,
  `qty`, `image`, **`variation` = `JSON.stringify(selectedAttributes)`** (e.g. `{"Size":"Half"}` — TEXT),
  `addons=[]`, `note=''`. **No `variation_id`.**
- `DD_Cart::ajax_add()` (`modules/orders/class-dd-cart.php:211-253`):
  - `:249` `'variation' => sanitize_text_field( $_POST['variation'] ?? '' )` — stored as **display text only**.
  - `:239` `$price = (float) $product->get_price()` — **re-derives the PARENT price** (the POSTed price is ignored,
    correctly, for security — but it resolves to the parent, not the variation).
  - Reads `product_id`, `quantity`, `variation`, `addons`, `note`. **Never reads a `variation_id`.**

### 4. Variation resolution — none exists (CORE GAP)

Grep across `modules/` and `dishdash-core/` for
`get_available_variations` / `find_matching_product_variation` / `get_matching_variation` /
`WC_Product_Variable` / `variation_id` / `get_variation_prices` → **zero matches.**

There is **no code anywhere** that maps a selected attribute (Size=Half) to a `variation_id` or to that
variation's price. This is the root gap that makes 1–3 unavoidable.

---

## Gaps (numbered)

1. **API emits no variation data.** `map_product()` (and `ajax_get_product()`) send only the parent `get_price()`
   and attribute `{name, options}` labels — no `variations[]` (id, attribute→value map, per-variation price,
   stock).
2. **Mobile display never updates the price on selection.** `#dd-mobile-single-price` is set once to the parent
   price; the pill handler updates `selectedAttributes` but not the price element (`menu-page.js:377-412` vs `:569`).
3. **Add-to-cart POST has no `variation_id`** — only the text label in `variation` (`menu-page.js:717`). The
   POSTed `price` is the parent price (and is ignored server-side anyway).
4. **Cart re-derives the parent price.** `DD_Cart::ajax_add()` uses `$product->get_price()` (parent) and stores
   the variation as display text only — no resolution to a variation (`class-dd-cart.php:239, :249`).
5. **No server-side variation-resolution utility exists** (attributes → variation_id → price). Root enabler of 1–4.

**Also affected (same root, out of the brief's primary scope but note for completeness):**
- Desktop modal path (`ajax_get_product` + `frontend.js`, which v3.10.80 wired to capture attribute text) charges
  the same parent price and posts variation as text via the same `ajax_add`; its `price_html` is a **range**.
- Homepage cards `templates/partials/product-card.php` (`:43-44`, `.dd-add-btn` quick-add, `data-id` only) post
  the parent price with **no** variation at all.

---

## Files / functions to change for full support

| Layer | File · function | Change |
|---|---|---|
| API | `dishdash-core/class-dd-api.php` · `map_product()` | Emit a `variations[]` array for variable products (variation_id, attributes map, price, in_stock). Keep `attributes` for the pills. |
| API (desktop) | `dishdash-core/class-dd-ajax.php` · `ajax_get_product()` | Same `variations[]` (only if variable products must work in the desktop modal too). |
| Frontend | `assets/js/menu-page.js` · `renderSingleProduct()` + pill handler + `addToCartById()` | On selection, match `selectedAttributes` → `variation_id` + price from `product.variations`; update `#dd-mobile-single-price`; POST `variation_id`. |
| Frontend (desktop) | `assets/js/frontend.js` (product modal, `ddPmSelected`) | Mirror the resolve-and-post-`variation_id` logic. |
| Cart | `modules/orders/class-dd-cart.php` · `ajax_add()` | Accept `variation_id`; validate it's a child of `product_id`; use the variation's `get_price()`; derive the variation text from `$variation->get_attributes()`. |

---

## Recommended minimal correct change set

Key the whole flow on **`variation_id`** — WooCommerce-native, price authority stays server-side (never trust the
POSTed price). Three files for the mobile path (add two if the desktop modal must support variable products).

1. **API — emit variations** (`map_product`, for `is_type('variable')`):
   ```php
   'variations' => array_map( fn( $v ) => [
       'variation_id' => $v['variation_id'],
       'attributes'   => $v['attributes'],       // e.g. ['attribute_size' => 'half']
       'price'        => (float) $v['display_price'],
       'in_stock'     => $v['is_in_stock'],
   ], $product->get_available_variations() ),
   ```
   (`$product` is already a `WC_Product_Variable` here; `attributes` for the pills stays as-is.)

2. **Frontend — resolve + display + post** (`menu-page.js`): on each pill selection, find the entry in
   `product.variations` whose `attributes` match all `selectedAttributes`; set
   `#dd-mobile-single-price` to that `price` and stash `variation_id` on `currentProduct`; in `addToCartById()`
   add `formData.append('variation_id', variationId)`. The existing "require all groups before enabling Add"
   gate (`:400-411`) already guarantees a full match before add.

3. **Cart — trust `variation_id` server-side** (`ajax_add`):
   ```php
   $variation_id = (int) ( $_POST['variation_id'] ?? 0 );
   if ( $variation_id ) {
       $variation = wc_get_product( $variation_id );
       if ( $variation && $variation->get_parent_id() === $product_id ) {
           $price = (float) $variation->get_price();        // authoritative variation price
           // variation display text ← implode of $variation->get_attributes()
       }
   }
   // else: simple product / no id → keep $product->get_price() (backward compatible)
   ```

This resolves all five gaps, keeps price authority on the server, is fully backward-compatible for simple
products (no `variation_id` → unchanged), and is WC-native (no bespoke attribute→price table). Recommend
including the desktop modal (step 1 in `ajax_get_product`, step 2 mirrored in `frontend.js`) in the same release
since v3.10.80 already wired desktop attribute capture and would otherwise still charge the parent price.

**STOP — awaiting "proceed" before any implementation brief.**
