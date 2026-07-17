# INVESTIGATION — Spice level: selection is not reaching the kitchen

**Phase 1, read-only.** Every claim carries `file:line`. Live-DB facts are marked **PENDING (server)** with exact
SQL.

---

## TL;DR — it's **two independent bugs**

1. **DISPLAY (mobile orders):** the spice choice **is** captured and stored in `dishdash_order_items.variation`
   (as JSON, e.g. `{"Spiciness Level":"Medium"}`), but **only the kitchen WhatsApp builder reads it**
   (`class-dd-notifications.php:361-370`). The **admin order modal** (`orders.php:844-851`), the **admin order
   WhatsApp** (`notifications.php:299-300`), and the **order email** (`notifications.php:205-208`) all render
   `qty × name` and **ignore `variation`**. So on the surfaces the restaurant actually watches, the choice is
   invisible — even though it's in the DB.
2. **CAPTURE (desktop):** the desktop add-to-cart (`frontend.js:191-212`) sends only
   `product_id` + `quantity` — **no `variation`**, and desktop renders **no spice chips at all**. So desktop
   orders store an empty `variation`; the choice never exists.

The mobile write path is fully wired end-to-end; the break is on the **read/display** side (bug 1) and the
**desktop capture** side (bug 2). These are separate files and separate fixes.

---

## 1. Where do the mobile chips come from?

- **Render:** `assets/js/menu-page.js:581-593` — the mobile single-product screen maps `product.attributes[]`
  into `.dd-mobile-attr-group` blocks with a `.dd-mobile-attr-pill` per option. Container is
  `#dd-mobile-single-attrs` (`templates/menu/grid.php:301`).
- **Data source:** **WooCommerce product attributes**, not the variation posts and not a hardcoded list.
  `product.attributes` comes from `DD_API::map_product()` `class-dd-api.php:560-571`, which iterates
  `$product->get_attributes()` and includes any attribute where `get_visible()` is true, emitting
  `{ name, options[] }` (terms of `pa_spiciness-level` → Mild/Medium/Hot/Extra Hot). Supplied to the mobile JS
  via `grid.php:335` (`DD_API::get_products`).
- **Why mobile only:** the chip UI exists **only** in the mobile component (`menu-page.js` + the
  `#dd-mobile-single-*` markup in `grid.php:295-301`). The **desktop** product interaction is `frontend.js`'s
  `addToCart()` (`:191`), a **quick-add** straight from the card — there is **no desktop product-detail view and
  no attribute/pill render anywhere in `frontend.js` / `product-card.php`** (grep: none). So desktop has no
  equivalent; it's a missing component, not a responsive branch.
- **On click:** `menu-page.js:383-398` — clears siblings, marks the pill `.is-active`, and stores
  `this.currentProduct.selectedAttributes[label] = pill.textContent.trim()` (JS state, keyed by the attribute
  label). Add button stays disabled until `selectedAttributes` count ≥ `requiredSelections` (`:400-402`).

## 2. Does the selection reach the server? — **Mobile: yes. Desktop: never sent.**

**Mobile path (fully wired):**
1. Sent: `menu-page.js:717` — `formData.append('variation', JSON.stringify(selectedAttributes))` to
   `dd_cart_add` (value like `{"Spiciness Level":"Medium"}`).
2. Cart stores it: `class-dd-cart.php:240` (`ajax_add` reads `$_POST['variation']`) → `add()` persists it into
   the session line at `class-dd-cart.php:97` (`'variation' => sanitize_text_field(...)`). `sanitize_text_field`
   keeps the JSON (it strips tags/newlines, not quotes/braces/colons). The line hash includes variation
   (`:179`), so different spice = different cart line.
3. Checkout carries it: `ajax_place_order()` reads the **server** cart — `$summary = (new DD_Cart)->summary()`
   (`orders-module.php:796`, "never trust client items") — and passes `'items' => $summary['items']` into
   `place_order()` (`:884` / `:1019` / `:1080` for the offline/COD/momo_manual branches).
4. Persisted: `place_order()` → `insert_order_items()` writes
   `'variation' => sanitize_text_field($item['variation'])` to `dishdash_order_items.variation`
   (`orders-module.php:446`).

**So for a mobile order the value lands in `dishdash_order_items.variation`.** The break is **downstream, at the
readers** (§4).

**Desktop path (lost at capture):** `frontend.js:191-212` `addToCart()` POSTs only `action, nonce, product_id,
quantity` — **no `variation` field** — and no chip UI ever set one. → cart line `variation=''` → order row
`variation=''`. **Lost before it's ever sent.**

**Exact break points:**
- **Mobile:** not lost in capture; lost at **display** (admin modal `orders.php:844-851`, admin WhatsApp
  `notifications.php:299-300`, email `notifications.php:205-208` — none read `variation`).
- **Desktop:** lost at **capture** — `frontend.js:207-212` sends no `variation`.

## 3. What is `order_items.variation` actually holding? — **PENDING (server)**

```sql
-- how many rows carry a value, and what do they look like?
SELECT COUNT(*)                                   AS total_rows,
       SUM(variation IS NOT NULL AND variation<>'') AS non_empty,
       SUM(variation LIKE '{%')                   AS json_shaped
FROM {$P}dishdash_order_items;

-- distinct values (spot the JSON vs plain vs empty split)
SELECT variation, COUNT(*) AS n
FROM {$P}dishdash_order_items
GROUP BY variation
ORDER BY n DESC
LIMIT 50;
```
**Interpretation:** if `non_empty` > 0 with `{...}` JSON, the mobile write path works and the bug is purely
display (bug 1) — expected given §2. If **all empty**, either the tested orders were desktop (bug 2) or the write
path never fired — the distinct-values query disambiguates. (Column is `VARCHAR(100)`; `{"Spiciness
Level":"Medium"}` = 27 chars, fits — but a longer attribute label + value could truncate; worth eyeing in the
distinct list.)

## 4. Does anything read `variation`? — **Only the kitchen WhatsApp.**

| Surface | `file:line` | Reads `variation`? |
|---|---|---|
| **Kitchen WhatsApp** | `class-dd-notifications.php:339` (SELECT) + `:361-370` (json_decode → `"Label: Value"`, plain-text fallback) | **YES** — the only reader |
| **Admin order modal** | `admin/pages/orders.php:844-851` (`renderModal` → `qty × item_name × price`) | **NO** (ignores it, though `get_order_items()` returns the column via `SELECT *` `orders-module.php:498`) |
| **Admin order WhatsApp** | `notifications.php:299-300` (`build_admin_whatsapp_url` items = `qty × name`) | **NO** |
| **Order email (admin)** | `notifications.php:205-208` (`notify_admin_email` items_html = `qty × name`) | **NO** |
| **Customer WhatsApp** | `notifications.php:262-269` (order number + ETA only, no item lines) | **NO** |
| **Rider WhatsApp** | `notifications.php:398-433` (address/customer/total, no item detail) | **NO** |

So the **display end is largely unwired** — this confirms two bugs, not one. And even the single reader (kitchen)
only fires if a `dd_whatsapp_kitchen` number is configured and the restaurant actually uses that message; a
restaurant working from the **admin order view** or the **admin order WhatsApp** sees nothing. Note for the fix:
`variation` is stored as **JSON**, so any newly-wired reader must `json_decode` it (as the kitchen builder does at
`:362`), not print it raw.

## 5. What role do the 900 variations play? — **Dead relative to the chip UI.**

- **Reachable by WooCommerce?** All 232 parents are typed `simple`, so `wc_get_product($id)` returns a
  `WC_Product_Simple`; `is_type('variable')` is false everywhere. The plugin's only variation-aware branch
  (`class-dd-ajax.php:81` `get_variation_attributes()`) **never executes**. Nothing calls `get_children()` /
  `get_available_variations()` in the plugin (grep: none). So the 900 `product_variation` posts are **not read by
  any plugin code path**.
- **Were the chips ever meant to read them?** No — the chips are built from the parent's **attributes**
  (`get_attributes()` → `map_product` `class-dd-api.php:560-571`), and the selection is sent as a **free-text
  label** (`menu-page.js:717`), never as a `variation_id`. There is **no code anywhere** that maps a chip
  selection to a variation post, even if the parents were typed `variable`. The current mobile flow would still
  send free text.
- **Verdict (evidence, not preference):** the 900 variations are **vestigial** — authored (parents carry
  `pa_spiciness-level` with `is_variation:1` + children) but stranded when the products were typed/inserted as
  `simple`. They contribute nothing to capture or display today. (Cleanup is R3, decision-gated — do **not**
  delete here.)

## 6. Options (report only)

**A — Dish Dash owns spice (matches the code as-built).**
- *Mechanics:* (bug 1) wire `variation` into the three unwired readers — admin order modal (`orders.php:844-851`,
  `json_decode` + render), admin order WhatsApp (`notifications.php:299-300`), order email
  (`notifications.php:205-208`); (bug 2) add a desktop spice UI that renders `product.attributes` and sends
  `variation` like mobile does.
- *Fixes:* kitchen/restaurant sees the choice on every surface; desktop starts capturing.
- *Leaves broken:* the 900 variations stay dead (separate cleanup, R3).
- *Effort:* moderate — display reads are small; desktop chip parity is the larger piece (there is no desktop
  product-detail component today, so it's a new UI, not a tweak).

**B — WooCommerce owns spice (re-type parents to `variable`).**
- *Mechanics:* convert 222 parents to `variable`, repair the 900 stale variation prices, let WC handle variation
  selection/cart/order natively.
- *What breaks:* the DD cart stores a **free-text** `variation`, not a `variation_id` (`cart.php:97/240`), and
  add-to-cart never selects a variation (`menu-page.js:717`, `frontend.js:207`) — so the whole DD cart/checkout
  would need reworking to carry `variation_id`, price-per-variation, and stock. **Pricing specifically breaks:**
  the +100 bump was applied to **parents'** `_regular_price`; a variable product derives its price from its
  **variations** (all stale at 4500), so the menu would show 4500 (pre-bump) and ignore the parent price.
  The DD menu render (`product-card.php:43`, `map_product`) assumes a single `get_price()` and has no
  variable-range handling.
- *Effort:* high, high-risk — touches cart, checkout, pricing, and the +100 work.

**C — What the code suggests:** Option **A**. Capture already works on mobile as free-text attributes; only
display wiring and desktop parity are missing. Option B fights the entire cart/pricing model and would undo the
+100 bump's effect. The variations are vestigial either way.

## 7. Release decomposition (ranked by customer impact)

Your R1 bundled "capture + display" — **splitting it**, because capture works on mobile and the two halves live
in different files:

| # | Fix | `file:line` | Impact | Verify before next |
|---|---|---|---|---|
| **R1 — DISPLAY (the money bug)** | Wire `variation` (json_decode) into the admin order modal, admin order WhatsApp, and order email | `orders.php:844-851`, `notifications.php:299-300`, `notifications.php:205-208` | **Highest** — the restaurant is mobile-first; mobile orders **already carry** the spice, it's just invisible on the surfaces they watch. This alone stops the wrong-spice-cooked bug for the majority path | Place a **mobile** order with spice → confirm it now shows in the order modal, the admin WhatsApp, and the email; kitchen msg unchanged |
| **R2 — DESKTOP capture** | Add a desktop spice UI rendering `product.attributes` + send `variation` in `addToCart` | `frontend.js:191-212` (+ a desktop product-detail render) | Medium — closes the desktop hole so desktop orders capture spice; only useful **after** R1 makes it visible | Desktop order with spice → confirm `order_items.variation` populated **and** visible via R1 surfaces |
| **R3 — variation cleanup** | Delete or convert the 900 vestigial variations (per §6 A vs B decision) | data-only (no plugin code) | Low — invisible to customers; hygiene only | Decision-gated; independent of R1/R2 |

**Ranking rationale:** R1 first — it's a pure read-side wiring of data **that already exists** in the DB for
mobile orders, so it fixes the live money bug immediately with the smallest, lowest-risk change. R2 second — it
requires building a desktop component and is only meaningful once R1 makes captured values visible. R3 last /
decision-gated. **Do not bundle R1 and R2** — different files, different risk (R1 tiny read-side; R2 a new UI),
and R1 must ship first so R2 is testable.

---

## Pending server checks (consolidated)
1. §3 two queries — is `order_items.variation` populated (JSON) for real orders, and are any values truncated at `VARCHAR(100)`?
2. Confirm a `dd_whatsapp_kitchen` number is/ isn't configured (`wp option get dd_whatsapp_kitchen`) — determines whether the single existing reader ever reaches anyone today.
3. (context) split recent orders by source to gauge mobile-vs-desktop mix — informs whether R1 (mobile display) or R2 (desktop capture) covers more live orders.
