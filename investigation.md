# INVESTIGATION — Product price storage & read paths (bulk +100 RWF pre-work)

**Phase 1, read-only. No code, no data changes.** Every claim carries `file:line`. Live-DB facts are marked
**PENDING (server)** with the exact WP-CLI/SQL.

Goal context: a bulk **+100 RWF on all regular prices except category 135** (Roti Ka Khazana, 19 products).
This doc establishes where prices live and who reads them — it does **not** perform or design the update.

---

## TL;DR

- **Prices live in WooCommerce core meta only.** Simple product → `_regular_price` / `_sale_price` / `_price`
  postmeta; each variation (a `product_variation` post) has its **own** trio. The plugin **never** stores a
  product regular price — grep for `wc_product_meta_lookup` / direct `_price` meta writes / `set_regular_price` in
  the plugin returns **NONE**.
- **Dish Dash is a pure reader.** Every price it shows comes from `wc_get_product()->get_price()` /
  `get_regular_price()` at read time. The one persisted copy is the **order-item snapshot** (`unit_price` /
  `line_total`), which is correct and must not be back-dated.
- **One plugin-owned cache matters:** DD_API's 5-minute transients (`_transient_dd_api_*`) carry `price`/
  `regular_price` and are auto-cleared **only** on `save_post_product` / `woocommerce_update_product`
  (`class-dd-api.php:512-514`). A **direct meta write bypasses those hooks** → stale up to 5 min.
- **Recommended write path (not executed): `wc_get_product() + set_regular_price() + save()`.** `save()` drives the
  WC datastore, which recomputes `_price`, updates `wp_wc_product_meta_lookup`, busts WC variation-price
  transients, and fires `woocommerce_update_product` → `DD_API::clear_cache()`. Direct `update_post_meta` does
  none of that. After the batch you still flush **LiteSpeed + object cache** manually. **Variable products need
  per-variation handling** — setting the parent's regular price does not touch variation prices.

---

## 1. Where WooCommerce stores price on this install

**Storage is WC core — the plugin touches none of it (grep: NONE).**

| Thing | Where | Notes |
|---|---|---|
| Simple product regular price | `wp_postmeta` `_regular_price` | The value the bulk op edits |
| Simple product sale price | `wp_postmeta` `_sale_price` | Independent; may be empty |
| Simple product **active** price | `wp_postmeta` `_price` | WC-computed: `_sale_price` if a sale is active, else `_regular_price`. **Derived — do not edit directly** |
| Variation regular/sale/active price | `wp_postmeta` on each `product_variation` post (`_regular_price` / `_sale_price` / `_price`) | Each variation is its own post with its own trio |

- **`wp_wc_product_meta_lookup`** — WC core table (one row per product/variation) holding `min_price`, `max_price`,
  `onsale`, `stock_status`, rating, etc. Populated/maintained by the WC datastore
  (`WC_Product_Data_Store_CPT`) on every `save()`. It powers sort-by-price, price filters, and query performance.
  **Plugin reference: none (grep: NONE)** — this is **core only**. Kept in sync by `save()`, **not** by direct
  meta writes.
- **Price-keyed caches/transients:**
  - WC **variable-price cache** — a hashed transient set per variable product (busted by WC on save / a global
    version bump). Only relevant if variable products exist (§4).
  - WC **product object cache** (`woocommerce_get_product_*` via the object cache group).
  - **DD_API transients** — plugin-owned (§2).
  - **LiteSpeed page cache** — serves the rendered menu HTML (stack line in CLAUDE.md).

---

## 2. Does Dish Dash store prices independently?

**No product-price column anywhere.** Schema audit of `install.php` (all 17 `CREATE TABLE` blocks) — the only
price columns are the **order-item snapshot**:

- `dishdash_order_items` (`install.php:146-160`): `unit_price DECIMAL(10,2)` (`:152`) + `line_total DECIMAL(10,2)`
  (`:156`), plus `menu_item_id` / `item_name`. **This is a correct order-time snapshot** — it freezes what the
  customer paid and must **not** be retroactively changed by a price update. Not a problem; expected.
- No `dishdash_products` table; no price column on any other DD table (customers, reservations, analytics,
  user_events, etc.).

**Price reads/denormalisation in the plugin (all live from WC, none persisted):**

| Site | `file:line` | Reads |
|---|---|---|
| DD_API `map_product()` | `class-dd-api.php:577-578` | `get_price()` / `get_regular_price()` → cached 5 min |
| Cart add | `class-dd-cart.php:230` | `get_price()` (into the cart **session** at add-time) |
| Product modal AJAX | `class-dd-ajax.php:93-94` | `get_price()` + `get_price_html()` |
| Menu grid card | `templates/partials/product-card.php:43-44` | `get_price()` |
| Homepage hero | `templates/page-dishdash.php:341` | `get_price()` |
| Reorder / profile | `class-dd-profile-module.php:371` | `get_price()` |
| Order placement | `class-dd-orders-module.php:187` | `wc_get_product()` (re-reads live at checkout, then snapshots) |
| Tracking value | `class-dd-tracking-module.php:231` | `get_price()` |

The **cart session** (`class-dd-cart.php:230`) holds a per-customer price copy captured when the item was added;
a bulk price change won't retroactively alter items already sitting in someone's cart (same principle as the
order snapshot — acceptable, not a denormalised master copy).

---

## 3. What renders the customer-facing menu / cart / checkout price?

- **Menu — server-rendered card:** `templates/partials/product-card.php:43-44` →
  `(float) $product->get_price()` (live `wc_get_product`). Grid loop supplies products via
  `wc_get_products()` (`grid.php:145`).
- **Menu — mobile JS data layer:** `grid.php:334-335` → `DD_API::get_products([ 'limit' => -1 ])` (and
  `DD_API::get_categories()`), i.e. the **5-min-cached** DD_API path. So the menu has **two** price sources: a
  live server card **and** a cached JS dataset. After a price change the JS dataset can lag up to 5 min unless
  DD_API cache is cleared (§5).
- **Cart drawer:** price is set when the item is added — `class-dd-cart.php:230` `get_price()` — and stored in
  the cart session; the drawer renders from that session copy.
- **Checkout / order placement:** `class-dd-orders-module.php:187` re-reads `wc_get_product($item['id'])` live,
  then writes the `unit_price`/`line_total` snapshot to `dishdash_order_items`.

**None of these read `_price` meta directly or read a Dish Dash price table** — they all go through
`wc_get_product()` (live) or `DD_API` (WC live, 5-min cached).

---

## 4. Variable products on this install — **PENDING (server)**

Counts can't be read from the repo. Run (adjust `wp_` prefix if different):

```bash
# Product-type split (simple / variable / grouped / external) with live counts:
wp term list product_type --fields=name,count

# Published products (all types):
wp post list --post_type=product --post_status=publish --format=count

# Published variations:
wp post list --post_type=product_variation --post_status=publish --format=count

# Category 135 — resolve term_id → term_taxonomy_id, count published products in it:
wp db query "SELECT COUNT(*) FROM {$P}posts p
  JOIN {$P}term_relationships tr ON tr.object_id = p.ID
  JOIN {$P}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
  WHERE tt.term_id = 135 AND tt.taxonomy = 'product_cat'
    AND p.post_type='product' AND p.post_status='publish'"

# Are any category-135 products VARIABLE? (join product_type)
wp db query "SELECT COUNT(*) FROM {$P}posts p
  JOIN {$P}term_relationships tr  ON tr.object_id = p.ID
  JOIN {$P}term_taxonomy tt       ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.term_id = 135
  JOIN {$P}term_relationships tr2 ON tr2.object_id = p.ID
  JOIN {$P}term_taxonomy tt2      ON tt2.term_taxonomy_id = tr2.term_taxonomy_id AND tt2.taxonomy='product_type'
  JOIN {$P}terms t2               ON t2.term_id = tt2.term_id AND t2.slug='variable'
  WHERE p.post_type='product' AND p.post_status='publish'"

# Published products with NO regular price set (would be skipped / need attention):
wp db query "SELECT COUNT(*) FROM {$P}posts p
  WHERE p.post_type='product' AND p.post_status='publish'
    AND NOT EXISTS (SELECT 1 FROM {$P}postmeta m
                    WHERE m.post_id=p.ID AND m.meta_key='_regular_price' AND m.meta_value <> '')"
```
(`{$P}` = the real table prefix, e.g. `wp_`.) The developer stated cat 135 has **19 products** — the query above
verifies it and, critically, whether any are **variable** (which changes the write path — see §6).

**Why this matters:** for a **simple** product the +100 edits one `_regular_price`. For a **variable** product the
regular price lives on **each variation**, not the parent — a bulk routine must iterate variations, and "+100 on
the product" is ambiguous (per-variation? only the parent's displayed range?). The counts decide whether the
bulk op is simple-only or must handle variations.

---

## 5. What a bulk price change would miss (if written directly to meta)

If `_regular_price` were updated via `update_post_meta` (bypassing WC `save()`):

| Goes stale | Why | Fix / command |
|---|---|---|
| `_price` (active price) | WC derives it on save; direct write leaves the old active price → **menu shows old price** | Recompute via `save()`, or `wp wc tool run regenerate_product_lookup_tables` won't fix `_price` — only `save()` does |
| `wp_wc_product_meta_lookup` (min/max) | Updated only by the datastore on `save()` | WP Admin → WooCommerce → Status → Tools → **Regenerate the product lookup tables**, or `wp wc tool run regenerate_product_lookup_tables --user=1` |
| WC variable-price transients | Busted by WC on save | `wp transient delete --all` (or WC clears on next save) — only matters if variable products exist |
| DD_API transients (`_transient_dd_api_*`) | Cleared only on `save_post_product`/`woocommerce_update_product` (`class-dd-api.php:512-514`); a direct meta write fires neither | `wp transient delete --all`, or call `DD_API::clear_cache()`, or wait ≤5 min |
| LiteSpeed page cache | Serves cached menu HTML | LiteSpeed → Toolbox → **Purge All** (or `wp litespeed-purge all` if the CLI is available) |
| Object cache (if persistent) | Cached `WC_Product` objects | `wp cache flush` |

**The direct-meta route requires manually fixing every row above.** The `_price` desync alone means the customer
menu would keep showing the **old** price after a direct `_regular_price` write.

---

## 6. Safest write path (report only — not executed)

**Recommended: `wc_get_product( $id )` → `set_regular_price( $new )` → `save()`.**

`save()` routes through `WC_Product_Data_Store_CPT`, which in one call:
- writes `_regular_price`, **recomputes and writes `_price`** (respecting any active `_sale_price`),
- **updates `wp_wc_product_meta_lookup`** (min/max/onsale),
- **busts the WC variable-price transient** for variable parents,
- fires **`woocommerce_update_product`** → `DD_API::clear_cache()` (`class-dd-api.php:514`) clearing the plugin's
  5-min cache.

So it handles **rows 1–4 of §5 automatically**. Only **LiteSpeed page cache** and (if enabled) **object cache**
remain to flush manually after the batch — neither is reachable from a product `save()`.

**Direct `update_post_meta( $id, '_regular_price', … )` is NOT recommended:** it leaves `_price`, the lookup
table, WC transients, and DD_API cache all stale (§5), producing a menu that shows old prices and price filters
that sort on wrong values until every cache is manually regenerated.

**Caveats to carry into the write brief (not decisions for here):**
1. **Variable products** (§4): `set_regular_price()`+`save()` on the parent does **not** change variation prices.
   If cat-excluded set contains variables, the routine must load `$product->get_children()` and
   `set_regular_price()`+`save()` each variation — and "+100" per variation must be an explicit decision.
2. **Sale prices:** +100 on `_regular_price` while a `_sale_price` is active means the customer still pays the
   (unchanged) sale price until the sale ends. Decide whether sale prices also move.
3. **Empty regular price** (§4 query): products with no `_regular_price` should be skipped, not set to `100`.
4. **Category 135 exclusion** must be evaluated per product (its `product_cat` terms), and for variations by the
   **parent's** category.
5. **Idempotency/backup:** a re-run would add another +100. Take a DB backup and record the affected IDs before
   any write (developer step, not this phase).

---

## Pending server checks (consolidated)
1. `wp term list product_type --fields=name,count` — simple vs variable split.
2. `wp post list --post_type=product --post_status=publish --format=count` and `--post_type=product_variation …` — product & variation totals.
3. The two cat-135 SQL queries in §4 — product count (verify 19) and whether any are variable.
4. The no-regular-price SQL in §4 — products the bulk op must skip.
5. (post-write, informational) confirm `_price` == `_regular_price` for updated simple products and that the lookup table regenerated.
