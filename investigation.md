# INVESTIGATION — Spice R2: desktop variation capture

**Phase 1, read-only.** Every claim carries `file:line`. Live-DB facts are marked **PENDING (server)** with exact
SQL.

---

## TL;DR — the desktop chip UI **already exists** and is fully built; it's fed empty data and not wired to Add

The desktop product **modal** (`openProductModal` → `renderModal` → `fetchProductEnrichment`, `frontend.js:896-1144`)
has a **complete attribute-pill component** — an `#ddPmAttrs` container (`:953`), a pill renderer with
per-attribute groups (`:1104-1120`), selection state, and Add-button gating "disabled until all attributes
selected" (`:1095-1136`). It is dead for two reasons:

1. **Data-source gap:** its data endpoint `dd_get_product` (`class-dd-ajax.php:80-88`) returns `attributes` **only
   when `is_type('variable')`** — all 232 products are `simple`, so `p.attributes` is always `[]`, the
   `if (p.attributes && p.attributes.length > 0)` guard (`frontend.js:1095`) is false, and no pills render. Mobile
   works because it uses a **different** source, `DD_API::map_product()` (`class-dd-api.php:560-571`), which reads
   `get_attributes()` **visible** attributes (not gated on variable type).
2. **Wiring gap:** even if pills rendered, the modal's Add handler (`frontend.js:1011-1016`) sends only
   `product_id` + `quantity`. The selection is captured in `fetchProductEnrichment`'s **local** `selected` object
   (`:1119/1128`) which the Add handler — a sibling closure in `renderModal` — never reads. So the choice would
   still not be sent.

**The server already accepts it:** `dd_cart_add` reads `$_POST['variation']` from **any** caller
(`class-dd-cart.php:240`) → stores it → `order_items.variation` (`orders-module.php:446`). Nothing downstream is
mobile-specific or would drop it. So R2 is: (a) expose visible attributes in `dd_get_product`, (b) bridge
`selected` into the Add POST as `variation`. Both must ship together.

---

## 1. What is the desktop product UI?

- **A separate JS-built modal**, not a template and not the mobile component. `openProductModal(productId)`
  (`frontend.js:896`) → inner `renderModal()` (`:932`) injects the modal HTML into `#ddProductModalContent`, then
  `fetchProductEnrichment()` (`:1051/1057`) fetches extras.
- **The desktop product card** is `templates/partials/product-card.php` — `<article class="dd-dish-card"
  data-id …>` with a title/desc/price and a quick-add `<button class="dd-add-btn" data-id data-nonce>`
  (`:64-83`). Clicking the card opens the modal (`frontend.js:1165-1170`); the quick-add button calls
  `addToCart()` directly (`:191`).
- **Where a chip row goes:** the home already exists — `<div class="dd-pm__attrs" id="ddPmAttrs">` (`:953`),
  between the description and the notes textarea. `fetchProductEnrichment` injects `.dd-pm__attr-group` /
  `.dd-pm__attr-pill` markup into it (`:1104-1120`).
- **Does the desktop view receive the attribute data?** It *requests* it (via `dd_get_product`, `:1069`) and is
  *coded to render* it, but the endpoint returns `[]` for simple products (§2). So the component never fires.

## 2. Why does mobile have chips and desktop not?

- **Not responsive CSS and not one template** — they are **two separate components with two separate data
  sources**:
  - **Mobile:** `menu-page.js` single-product screen, fed by the **cached DD_API dataset** localized from
    `grid.php:335` (`DD_API::get_products`), whose `map_product()` includes **visible** attributes
    (`class-dd-api.php:560-571`). Renders `.dd-mobile-attr-pill` (`menu-page.js:588`).
  - **Desktop:** `frontend.js` modal, fed **on demand** by the `dd_get_product` AJAX endpoint
    (`class-dd-ajax.php:70`), which only exposes **variation** attributes (`is_type('variable')` gate, `:81`).
    Renders `.dd-pm__attr-pill` (`frontend.js:1113`).
- **`grid.php:335`'s cached DD_API dataset feeds mobile only.** The desktop modal does not read that dataset for
  attributes; it fetches `dd_get_product` per-open. (`grid.php` is the `/restaurant-menu/` mobile grid;
  `DD_MOBILE_DATA`/`ddMenuData` is the mobile layer.)
- **Built before, or deliberately excluded?** Evidence points to **built-but-starved**, not excluded: the
  desktop modal has the full pill renderer, selection state, and "disable Add until selected" logic
  (`:1095-1136`) — clearly *intended* to show attributes — but it queries an endpoint that was only ever wired
  for WooCommerce *variable* products, so it silently returns nothing for these simple products. The two data
  sources diverged (`map_product` = visible attrs; `ajax_get_product` = variation attrs).

## 3. The add-to-cart path

- **Desktop modal Add** (`frontend.js:997-1039`): POSTs `action=dd_cart_add, nonce, product_id, quantity` — **no
  `variation`** (`:1011-1016`). (It also drops the `#ddPmNotes` textarea — desktop never sends `note` either; a
  *separate* capture gap, out of scope, flagged below.)
- **Desktop quick-add** (`frontend.js:191-212`): same — `product_id` + `quantity` only.
- **Server expectation:** `dd_cart_add` (`class-dd-cart.php:217-243`) reads `product_id`/`id`, `quantity`/`qty`,
  and **`variation`** via `sanitize_text_field($_POST['variation'] ?? '')` (`:240`) → `DD_Cart::add()` persists it
  (`:97`) → `place_order` → `insert_order_items` writes `order_items.variation` (`orders-module.php:446`). **The
  handler is caller-agnostic — it already accepts `variation` from any source.** If desktop sent it, nothing
  downstream rejects or drops it (proven by mobile using the identical endpoint).

## 4. Reuse potential

- **The mobile chip component cannot be lifted wholesale** — it's bound to mobile-only markup
  (`.dd-mobile-attr-*`) and the `menu-page.js` product-screen state (`this.currentProduct.selectedAttributes`).
- **But desktop doesn't need it** — it has its **own** equivalent already (`fetchProductEnrichment`
  `:1092-1141`, `.dd-pm__attr-*`). What must change is small and additive:
  1. **`class-dd-ajax.php` `ajax_get_product`** — expose **visible** attributes for simple products, mirroring
     `DD_API::map_product()`'s normalization (`class-dd-api.php:560-571`: iterate `get_attributes()`, keep
     `get_visible()`, taxonomy → `wc_get_product_terms(..., ['fields'=>'names'])`, non-taxonomy →
     `get_options()`, emit `{name, options[]}`). This makes `p.attributes` non-empty → the existing pill
     renderer fires. **Blast radius: `dd_get_product` is called only by `frontend.js`** (grep: `:921` no-card
     fetch + `:1069` enrichment — nothing else), so this touches only the desktop modal; mobile uses
     `map_product` and is unaffected.
  2. **`frontend.js`** — bridge the enrichment's `selected` object into the Add POST as
     `variation: JSON.stringify(selected)`. Today `selected` (`:1119`) is scoped inside
     `fetchProductEnrichment` and the Add handler is in `renderModal`; the two need a shared reference (hoist
     `selected` to the `openProductModal` scope, or stash it where the Add handler can read it).
- **Existing CSS:** the pills are **inline-styled** in `fetchProductEnrichment` (`.dd-pm__attr-pill` with inline
  `style=` on each element, `:1113`) plus an `active` class toggled on selection (`:1127`). PENDING check: does
  `.dd-pm__attr-pill.active` have a CSS rule (for the selected-state highlight)? If not, that's the only
  potential styling gap — the base pills are inline-styled and will show without new CSS.
  ```bash
  grep -rn "dd-pm__attr-pill" assets/css/    # is there an .active rule for the selected highlight?
  ```

## 5. The "no selection" case

- **Mobile requires a selection when attributes exist:** `requiredSelections = product.attributes.length`
  (`menu-page.js:597`), Add stays disabled until `selectedAttributes` count ≥ required (`:400-402`). It sends
  `{}` only when a product has **no** attributes (empty `selectedAttributes`) — that is what the **18 `{}` rows**
  represent (attribute-less products), not a skipped required chip.
- **Desktop already mirrors this:** `fetchProductEnrichment` disables Add and only re-enables once every
  attribute group has a selection (`:1095-1136`). So once desktop is fed attributes, **the "require selection"
  behavior comes for free** — no separate validation work, and it matches mobile.
- *Design decision (not made here):* whether a product with attributes should ever be addable without a choice.
  Both surfaces currently say **no** (Add gated). R2 preserving that is the low-surprise path.

## 6. Scope check — **PENDING (server)** (`{$P}` = prefix)

```sql
-- products carrying the spice attribute
SELECT COUNT(DISTINCT p.ID) FROM {$P}posts p
JOIN {$P}postmeta m ON m.post_id = p.ID AND m.meta_key = '_product_attributes'
WHERE p.post_type='product' AND p.post_status='publish'
  AND m.meta_value LIKE '%pa_spiciness-level%';

-- products carrying ANY product attribute (serialized non-empty)
SELECT COUNT(DISTINCT p.ID) FROM {$P}posts p
JOIN {$P}postmeta m ON m.post_id = p.ID AND m.meta_key = '_product_attributes'
WHERE p.post_type='product' AND p.post_status='publish'
  AND m.meta_value <> '' AND m.meta_value <> 'a:0:{}';

-- every product-attribute taxonomy in use (spice, size, …) — decides how generic R2 must be
SELECT DISTINCT taxonomy FROM {$P}term_taxonomy WHERE taxonomy LIKE 'pa_%';
```

- **Which attributes should NOT be customer-selectable?** Can't be inferred from raw data; the intent signal is
  each attribute's **visible** flag in `_product_attributes` (what `map_product`/`get_visible()` keys off). Any
  attribute set visible will surface as a chip under the "mirror map_product" approach. If some visible attribute
  is informational (not a choice), that's a **per-attribute editorial call** — flag for the developer; note that
  `{"Size":"Half"}` already exists in real data, so R2 must be **generic over any attribute**, not spice-only.

## 7. Release decomposition

**One release.** The two required changes are **not independently shippable**:

- **Data source alone** (expose attributes in `dd_get_product`) → pills render **and gate the Add button**, but
  the Add handler ignores `selected` → the customer is forced to pick, yet the pick is silently dropped. Worse
  than today.
- **Wiring alone** (send `variation` from the modal) → nothing to send; no pills exist. No effect.

So **R2 = one atomic release**: (a) `ajax_get_product` returns visible attributes for simple products (mirroring
`map_product`), (b) `frontend.js` bridges `selected` → `variation=JSON.stringify(selected)` in the modal Add.
The **validation** ("require a choice") is **already implemented** on both surfaces (`:1095-1136`) and needs no
separate release — it activates for free once attributes render. Confirm the `.dd-pm__attr-pill.active` CSS
exists (§4) — if not, a one-line style is part of the same release, not a separate one.

**Explicitly out of scope (separate tickets, flag only):**
- **Desktop `note` gap:** the modal's `#ddPmNotes` textarea (`frontend.js:955`) is never sent to
  `dd_cart_add` — desktop special instructions are dropped, same class of bug as variation but a different field.
  Not spice; own release.
- **Desktop quick-add button** (`.dd-add-btn` on the card, `frontend.js:191`): bypasses the modal entirely, so it
  can never capture attributes. Decision needed (leave as a no-attribute fast path, or route attribute-bearing
  products through the modal) — but that's a UX decision, not required for R2 (the modal path captures spice;
  the quick-add stays a flat add). Flag, don't design.
- Mobile path, display (v3.10.78/79), kitchen builder, the 900 variations (R3) — untouched.

---

## Pending server checks (consolidated)
1. §6 three queries — how many products carry `pa_spiciness-level`, how many carry any attribute, and the full list of `pa_*` taxonomies (decides how generic R2's render must be — it must be generic; `{"Size":"Half"}` proves it).
2. `grep -rn "dd-pm__attr-pill" assets/css/` — does `.dd-pm__attr-pill.active` have a selected-state rule, or is a one-line style needed in R2?
3. (informational) confirm no product has a *visible* attribute that is informational-only and should not become a chip — a per-attribute editorial check before R2 ships.
