# INVESTIGATION — Spice selector: move from per-product attribute to a category rule

**Read-only. No code changes.** Plugin: dish-dash (universal). Surfaced on: nyarutarama, v3.11.5.

**Goal:** the spice-level selector should appear for **all** products **except** four categories —
Roti Ka Khazana (bread), Meetha Ka Khazana (desserts), Dahi (yogurt), Papad. Today spice is a
per-product WooCommerce attribute (`pa_spiciness-level`), so any product missing that attribute has no
selector. Moving to a category rule makes it automatic and correct for all/future products.

Findings are **confirmed from source** (exact `file:line`). Live term IDs/slugs need **server
verification** (no DB access from the repo checkout) — marked **VERIFY (server)**.

---

## 1. How the spice selector is rendered today

**There is NO spice-specific code anywhere.** Repo-wide grep for `spice` / `spici` / `pa_spiciness` /
`Spiciness` across `assets/js/`, `templates/`, `dishdash-core/`, `modules/`, `admin/`, `install.php`
returns **only one hit** — a comment:

- `dishdash-core/class-dd-ajax.php:83` — *"Generic over any attribute (no pa_spiciness-level
  special-casing)."*

So the "spice selector" is just the **generic attribute-pill UI**. It appears for a product **iff that
product has a VISIBLE WooCommerce attribute** (the spice one, taxonomy `pa_spiciness-level`), which the
API normalizes into the product's `attributes[]`, and the frontend renders as pills.

**The single mechanism:**

- **API normalization** — `DD_API::normalize_product()` (`class-dd-api.php:560-571`) and the desktop
  endpoint `DD_Ajax::ajax_get_product()` (`class-dd-ajax.php:84-96`) both do the identical loop:
  ```php
  foreach ( $product->get_attributes() as $attr ) {
      if ( ! $attr->get_visible() ) continue;
      $options = $attr->is_taxonomy()
          ? wc_get_product_terms( $product->get_id(), $attr->get_name(), [ 'fields' => 'names' ] )
          : $attr->get_options();
      $attributes[] = [ 'name' => wc_attribute_label( $attr->get_name(), $product ), 'options' => … ];
  }
  ```
  → For a product that has the spice attribute assigned, `attributes[]` gains
  `{ name: "Spiciness Level", options: ["Mild","Medium","Hot", …] }`. Nothing marks it as "spice" — it
  is one attribute among any others (e.g. `Size` on the v3.11.5 variable products).
- **Mobile render** — `menu-page.js` `showProductDetails()` (`:581-593`) maps `product.attributes` into
  `.dd-mobile-attr-pill` groups in `#dd-mobile-single-attrs`; the pill handler (`:377-432`) stores the
  choice in `selectedAttributes[label]` and is POSTed as the `variation` text.
- **Desktop render** — `frontend.js` `fetchProductEnrichment()` (`:1108-1155`) maps `p.attributes` into
  `.dd-pm__attr-pill` groups in `#ddPmAttrs`, writing to `ddPmSelected`, POSTed as `variation`.

**What makes the selector appear today:** the product has the visible `pa_spiciness-level` attribute
assigned. No assignment → no selector. That is exactly why "many products are missing it."

**Capture:** the selection rides in the order-item `variation` text (the same JSON, e.g.
`{"Spiciness Level":"Hot"}`) — there is no dedicated spice column.

---

## 2. Category data — already available to the frontend

**Category is already in the product payload** (no new plumbing for the mobile list):

- `normalize_product()` (`class-dd-api.php:539-544, 585-586`) reads
  `get_the_terms( $product_id, 'product_cat' )` and emits:
  - `'categories'  => [ { id, slug, name }, … ]`  (`:585`)
  - `'category_ids' => [ term_id, … ]`            (`:586`)
- The mobile product list is localized from `DD_API::get_products( ['limit'=>-1] )`
  (`templates/menu/grid.php:335`) → every mobile product already carries `categories` + `category_ids`.

**Gap for desktop:** `ajax_get_product()` (`class-dd-ajax.php:98-108`) does **NOT** send categories (only
id/name/price/price_html/description/image/rating/attributes/variations). So a **frontend-side** category
rule would work on mobile but the desktop modal would need `categories` added — which is why a
**server-side `has_spice` flag** (computed in both endpoints) is the cleaner choice (see §4).

**The four no-spice categories** — names given: Roti Ka Khazana, Meetha Ka Khazana - Desserts,
Dahi - Yogurt, Papad. Brief's approximate term IDs: **135, 141, 133, 132**. **VERIFY (server)** — no
category slugs/IDs are hardcoded anywhere in the repo (grep of `install.php` + modules = none), so the
real slugs/IDs must be read live:
```bash
wp term list product_cat --fields=term_id,slug,name --format=table
```
> ⚠️ **Term IDs are per-install.** For a white-label/universal plugin, keying the rule on **slugs** (or an
> admin setting) is safe; hardcoding IDs (135/141/133/132) is **not** portable across restaurants.

---

## 3. Where the spice options (Mild/Medium/Hot) live

`pa_spiciness-level` is a **global WooCommerce product-attribute taxonomy** (the `pa_` prefix = a
registered attribute taxonomy under Products → Attributes). Its **terms** (Mild / Medium / Hot / …) are
**global**, stored once — not per product. A product merely *references* a subset of those terms when the
attribute is assigned to it.

- Today the option list shown for a product = **that product's assigned terms**
  (`wc_get_product_terms( $id, 'pa_spiciness-level', ['fields'=>'names'] )`, `class-dd-api.php:565`).
- The **canonical full list**, independent of any product, is the taxonomy's terms:
  ```php
  get_terms( [ 'taxonomy' => 'pa_spiciness-level', 'hide_empty' => false ] );
  ```

**This is the key answer to the design question:** the spice options **already live in a global taxonomy**.
Decoupling visibility from per-product assignment does **not** require moving/duplicating the options —
they can keep coming from `pa_spiciness-level`'s terms, read globally. **VERIFY (server)** the exact
taxonomy slug (`pa_spiciness-level` vs `pa_spiciness_level`) and the term set:
```bash
wp wc product_attribute list --user=1        # find the attribute + its taxonomy slug
wp term list pa_spiciness-level --fields=term_id,slug,name --format=table
```

---

## 4. Recommendation — category rule, options from the taxonomy

### Design decision (the one the brief asked to nail)
**Keep `pa_spiciness-level` as the option source; decide visibility by category.** Of the three options:
- **A. Global spice-options setting** — flexible but duplicates data already in the taxonomy; extra admin
  surface to keep in sync. ✗ (unnecessary)
- **B. Hardcoded Mild/Medium/Hot list** — not white-label, drifts from the taxonomy. ✗
- **C. Read options from the `pa_spiciness-level` taxonomy terms (global), gate visibility by category.**
  ✓ **RECOMMENDED** — reuses existing data, zero migration, canonical, white-label-safe.

### How to gate by category
Compute a **server-side `has_spice`** so both endpoints and both UIs agree and no category data has to be
shipped to the client:
- `has_spice = ( product's category set does NOT intersect the excluded set )`.
- **Excluded set — make it a setting, not hardcoded IDs.** Add an admin field (Settings) —
  `dd_spice_excluded_categories` (multi-select of `product_cat`, stored as term IDs or slugs). White-label
  restaurants pick their own no-spice categories; nyarutarama seeds Roti/Meetha/Dahi/Papad.
  (Acceptable interim: a slug allow-list constant, since slugs are stable; IDs are not.)

### Minimal change set (server-driven, frontend-thin)
1. **API — `class-dd-api.php`**
   - New helper `DD_API::spice_options()` → cached `get_terms('pa_spiciness-level' → names)` (5-min
     transient like the others). Single global read.
   - New helper `DD_API::product_has_spice( $product )` → true unless its `category_ids`/slugs intersect
     `dd_spice_excluded_categories`.
   - In `normalize_product()` add `'has_spice' => …`. (Options can be sent per product or, better, once
     in the page-level localize to avoid repeating the same list on every product.)
2. **Desktop endpoint — `class-dd-ajax.php`** `ajax_get_product()`: add the same `'has_spice'` (+ options
   if not page-localized). This is also where `categories` is currently absent — `has_spice` avoids
   needing to add it.
3. **Frontend — `menu-page.js` + `frontend.js`**: when `has_spice`, render **one dedicated spice pill
   group** from the global `spice_options` (reuse the existing `.dd-mobile-attr-pill` /
   `.dd-pm__attr-pill` markup), SEPARATE from `attributes[]` so it composes with real attributes (e.g.
   `Size`). Include it in the "all selected" gate if spice is to be required (or make it optional with a
   default).
4. **Capture** — the spice choice must reach the order. Two clean options:
   - Fold it into the existing `variation` JSON text on the **simple** path (works today, no schema
     change). **Nuance for VARIABLE products:** the v3.11.5 cart **rebuilds** the variation text
     server-side from the variation's own attributes (`class-dd-cart.php` `variation_label()`), which
     would **drop** a client spice choice. So for variable products the spice must be merged in
     server-side (append the posted spice to the rebuilt text) **or** carried in a separate field.
   - Simplest universal: a dedicated `spice` POST field stored on the cart line and appended to the
     order-item `variation`/`special_note` on both paths (no price impact — spice never changes price, so
     it must **not** be modeled as a WC variation/`variation_id`).

### Why NOT inject spice into `attributes[]`
Tempting (frontend unchanged), but it collides with v3.11.5: for variable products the server rebuilds
`variation` text from the matched variation and would discard the injected spice; and it muddies the
"required selections" gate with a non-variation attribute. Keep spice as its **own** flagged selector.

### Files to change (implementation, later)
| Layer | File · function | Change |
|---|---|---|
| API | `dishdash-core/class-dd-api.php` · `normalize_product()` + new `spice_options()` / `product_has_spice()` | emit `has_spice`; provide global spice options; category-exclusion check |
| API (desktop) | `dishdash-core/class-dd-ajax.php` · `ajax_get_product()` | emit `has_spice` (+ options if not page-localized) |
| Frontend | `assets/js/menu-page.js` · `showProductDetails()` + pill handler | render dedicated spice group when `has_spice`; capture selection |
| Frontend | `assets/js/frontend.js` · `renderModal()` / `fetchProductEnrichment()` | same for the desktop modal |
| Cart | `modules/orders/class-dd-cart.php` · `ajax_add()` (+ `variation_label()`) | accept + persist spice into the order line on BOTH simple and variable paths |
| Admin (recommended) | `admin/pages/settings.php` | `dd_spice_excluded_categories` multi-select (white-label) |

### Server verification checklist (before the implementation brief)
- Exact spice taxonomy slug + its terms (§3 commands).
- The four excluded categories' real slugs/IDs (§2 command) — decide slug-list vs setting.
- Whether spice should be **required** or **optional-with-default** on non-excluded products.

**STOP — awaiting review before the implementation brief.**
