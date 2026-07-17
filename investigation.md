# INVESTIGATION — Product price data repair (pre-bump)

**Phase 1, read-only. No code, no data changes.** Every claim carries `file:line` (plugin) or is marked
**PENDING (server)** with exact SQL. WooCommerce source is **not** in this repo (confirmed: no
`class-wc-product-simple.php`, no non-plugin `woocommerce.php`), so all `save()`-behaviour claims are **WC core
design** and carry a **live 1-product dry-run** to confirm on this exact WC version before any batch.

Established facts (given, not re-derived): 232 published products all typed `simple`; 900 published
`product_variation` rows across 222 parents; 204/232 have `_price` but no `_regular_price`; 232/232 have `_price`.

---

## TL;DR

- **Orphan variations are unreachable by customers.** The plugin surfaces variation attributes **only** when
  `is_type('variable')` (`class-dd-ajax.php:81`); all 232 products are `simple`, so that branch never runs. The
  menu/cart/checkout show a flat `_price`.
- **No order row references a variation ID.** `dishdash_order_items.menu_item_id` = the **product** id
  (`orders-module.php:430/441`); `.variation` is a **free-text label** (`:446`), never a variation post ID. So
  deleting orphan variations cannot break DD order history. (WC-side order itemmeta check is PENDING but the DD
  purchase flow never selects a WC variation for a simple product.)
- **Today's displayed prices are correct** — everything reads `get_price()` → `_price`, which is set on all 232.
  The data model is wrong (`_regular_price` missing) but the UI is right. Confirmed.
- **The backfill is low-risk.** `wc_get_product()` returns a `WC_Product_Simple`; its `save()` has **no code path
  that enumerates, deletes, or re-links variation children** — only the *variable* product data store does that.
  So backfilling `_regular_price` on the simple parents leaves the 900 orphans untouched. **This is the
  highest-risk unknown and the answer is: safe, because the products stay `simple` — but verify with a
  single-product dry-run before the batch.**
- **Decomposition holds: R1 backfill → R2 bump → R3 orphan cleanup**, R3 decision-gated and lowest urgency
  (invisible to customers). These are **data operations, not plugin code — no version bump.**

---

## 1. Exact damage inventory — **PENDING (server)** (`{$P}` = table prefix, e.g. `wp_`)

```sql
-- (a) products with _price but NO _regular_price  (expect 204)
SELECT COUNT(*) FROM {$P}posts p
WHERE p.post_type='product' AND p.post_status='publish'
  AND EXISTS     (SELECT 1 FROM {$P}postmeta WHERE post_id=p.ID AND meta_key='_price'         AND meta_value<>'')
  AND NOT EXISTS (SELECT 1 FROM {$P}postmeta WHERE post_id=p.ID AND meta_key='_regular_price' AND meta_value<>'');

-- (b) products with BOTH set — do they agree?
SELECT SUM(pr.meta_value = rp.meta_value) AS agree,
       SUM(pr.meta_value <> rp.meta_value) AS disagree
FROM {$P}posts p
JOIN {$P}postmeta pr ON pr.post_id=p.ID AND pr.meta_key='_price'
JOIN {$P}postmeta rp ON rp.post_id=p.ID AND rp.meta_key='_regular_price' AND rp.meta_value<>''
WHERE p.post_type='product' AND p.post_status='publish';

-- (c) products with a _sale_price set  (bump interacts with active sales — §4 caveat)
SELECT COUNT(*) FROM {$P}postmeta
WHERE meta_key='_sale_price' AND meta_value<>''
  AND post_id IN (SELECT ID FROM {$P}posts WHERE post_type='product' AND post_status='publish');

-- (d) products with NEITHER _price nor _regular_price  (would be skipped)
SELECT COUNT(*) FROM {$P}posts p
WHERE p.post_type='product' AND p.post_status='publish'
  AND NOT EXISTS (SELECT 1 FROM {$P}postmeta WHERE post_id=p.ID AND meta_key='_price'         AND meta_value<>'')
  AND NOT EXISTS (SELECT 1 FROM {$P}postmeta WHERE post_id=p.ID AND meta_key='_regular_price' AND meta_value<>'');

-- (e) the 28 that DO have _regular_price — what's different? inspect date + agreement + child count
SELECT p.ID, p.post_title, p.post_date,
       pr.meta_value AS price, rp.meta_value AS regular,
       (SELECT COUNT(*) FROM {$P}posts c WHERE c.post_parent=p.ID AND c.post_type='product_variation') AS children
FROM {$P}posts p
JOIN {$P}postmeta rp ON rp.post_id=p.ID AND rp.meta_key='_regular_price' AND rp.meta_value<>''
LEFT JOIN {$P}postmeta pr ON pr.post_id=p.ID AND pr.meta_key='_price'
WHERE p.post_type='product' AND p.post_status='publish'
ORDER BY p.post_date;
```

**Hypothesis for the 28 (to confirm with query (e)):** if their `post_date` clusters later than the 204, or they
have 0 variation children, they were likely added/edited through the WooCommerce admin (which always writes
`_regular_price`), whereas the 204 were bulk-inserted via SQL (bypassing the data layer) — matching the given
probable cause.

**Orphan variations:**
```sql
-- variation posts total (expect 900) and how many carry their own _price / _regular_price
SELECT
  (SELECT COUNT(*) FROM {$P}posts WHERE post_type='product_variation' AND post_status='publish') AS variations,
  (SELECT COUNT(*) FROM {$P}postmeta WHERE meta_key='_price'         AND meta_value<>''
     AND post_id IN (SELECT ID FROM {$P}posts WHERE post_type='product_variation')) AS var_with_price,
  (SELECT COUNT(*) FROM {$P}postmeta WHERE meta_key='_regular_price' AND meta_value<>''
     AND post_id IN (SELECT ID FROM {$P}posts WHERE post_type='product_variation')) AS var_with_regular;
```

---

## 2. Are the orphan variations reachable by customers? — **No (code-proven; two live confirmations)**

- **Does the plugin query `product_variation` directly?** No. The only variation-aware code is
  `class-dd-ajax.php:81-88` (`ajax_get_product`), gated on `$product->is_type('variable')`. All 232 are `simple`
  → the branch never executes → no variation attributes are ever emitted to the modal. DD_API only records an
  `is_simple` flag (`class-dd-api.php:557/593`) and never calls `get_children()` (grep: none).
- **Does the menu/cart/checkout surface a variation for a simple parent?** No. The product modal shows a flat
  `get_price()` (`class-dd-ajax.php:93`); the cart stores the **product id** + a free-text `variation` label
  (`class-dd-cart.php:240`, `orders-module.php:446`); checkout re-reads the **product** (`orders-module.php:187`).
  The customer never selects a WC variation.
- **Do any `dishdash_order_items` rows reference a variation ID?** **No, by construction** — `menu_item_id` is
  `absint($item['id'])` = the product id (`orders-module.php:430/441`). Confirm live:
  ```sql
  SELECT COUNT(*) FROM {$P}dishdash_order_items
  WHERE menu_item_id IN (SELECT ID FROM {$P}posts WHERE post_type='product_variation');   -- expect 0
  ```
  And the WC-side (online-gateway orders), also expected 0 since no variation is ever chosen:
  ```sql
  SELECT COUNT(*) FROM {$P}woocommerce_order_itemmeta
  WHERE meta_key='_variation_id' AND meta_value NOT IN ('', '0');                          -- expect 0
  ```
- **Conclusion:** if both counts are 0, deleting the orphan variations destroys **no** reachable data or order
  history. (The free-text `variation` label a customer saw — e.g. a spiciness level — is snapshotted in
  `order_items.variation` as text and is independent of the variation posts.)

---

## 3. What does `_price` currently drive? — **Confirmed: the whole customer-facing price, correctly**

Prior finding holds. Every customer-facing price reads WC's active `_price` via `get_price()`:
`product-card.php:43` (menu card), `class-dd-cart.php:230` (cart add), `orders-module.php:187` (checkout re-read),
plus `class-dd-ajax.php:93` (modal), `page-dishdash.php:341` (hero), `class-dd-api.php:577` (mobile JS dataset,
5-min cached). **So the displayed prices are correct today** even though 204 products lack `_regular_price` — the
missing field is invisible to the read path. **Confirmed, not refuted.**

Implication: the repair is a **data-integrity** fix (so WC/reporting/price-filters own it properly and the +100
bump has a `_regular_price` to add to), **not** a fix for anything a customer currently sees.

---

## 4. Repair options (report only — do not choose)

### Option A — Backfill `_regular_price = _price` for the 204 (via the WC data layer)
**Mechanics:** for each of the 204, `$p = wc_get_product($id); $p->set_regular_price( $p->get_price() ); $p->save();`
`save()` writes `_regular_price`, recomputes `_price`, updates `wp_wc_product_meta_lookup`, and fires
`woocommerce_update_product` → `DD_API::clear_cache()` (`class-dd-api.php:514`).
**Risk:** low. **Leaves behind:** the 900 orphan variations untouched (unpriced, still orphaned) — that's R3.

**Highest-risk unknown — does `save()` on a simple product with variation children delete/orphan/re-link them?**
- `wc_get_product($id)` returns a **`WC_Product_Simple`** (type is `simple`; children are ignored by type).
- The **simple** product data store (`WC_Product_Data_Store_CPT::update`) writes postmeta + lookup + transients.
  **It has no variation logic.** Child sync/deletion lives only in the **variable** path
  (`WC_Product_Variable_Data_Store_CPT` / `WC_Product_Variable::save()`), which is **not** invoked for a simple
  product. So a simple `save()` **does not enumerate, delete, or re-link** `product_variation` children — they
  remain exactly as-is.
- **Therefore the backfill is safe *as long as the product stays `simple`.*** The danger only appears if a step
  first re-types the product to `variable` (that would trigger child sync). The backfill does not change type.
- **This is WC core, not in-repo → mandatory live confirmation before the batch:** dry-run ONE product and prove
  the child set is unchanged:
  ```sql
  -- before AND after saving product 100115 (example), compare counts + that _price is unchanged:
  SELECT
    (SELECT COUNT(*) FROM {$P}posts WHERE post_parent=100115 AND post_type='product_variation') AS children,
    (SELECT meta_value FROM {$P}postmeta WHERE post_id=100115 AND meta_key='_price')             AS price,
    (SELECT meta_value FROM {$P}postmeta WHERE post_id=100115 AND meta_key='_regular_price')     AS regular;
  ```
  Expect: `children` identical before/after, `regular` becomes 4500, `price` stays 4500.

**Does `save()` change `_price` when `_regular_price` is newly set?** No net change here. WC sets
`_price = _sale_price` if an active sale exists, else `_regular_price`. With no `_sale_price`, `_price` becomes
`_regular_price` = the old `_price` value → **identical** (confirm with the dry-run above). Products that **do**
have an active `_sale_price` (query 1(c)) are the exception to watch — there `_price` already equals the sale
price and stays so.

### Option B — Orphan variation cleanup (SEPARATE operation, SEPARATE release = R3)
Two directions, **decision is the developer's**:
- **B1 Delete the 900 variations** (`wp_delete_post($var_id, true)` per child, or a scoped SQL delete of the
  `product_variation` posts + their postmeta). **Safe iff** §2's two counts are 0 (no order references). Leaves
  the products cleanly simple. Removes the latent "these look half-variable" ambiguity.
- **B2 Convert the 222 parents to `variable`** and keep the variations (restores a real variation picker). This
  **changes customer UX** — price *ranges*, a variation selector the plugin would now render
  (`class-dd-ajax.php:81` would start firing), and it requires every variation to have a valid price. Much larger
  blast radius; only correct if spiciness-level was *meant* to be a purchasable WC variation rather than a flat
  simple product.
- **What the data suggests (not a decision):** the products carry `_product_attributes` with `is_variation:1`
  plus real variation children — i.e. they were **authored as variable** but inserted/typed as **simple**, so
  the variations were stranded. If the business wants flat pricing (as it renders today), **B1 (delete)** matches
  current behaviour; if per-variation pricing was intended, **B2 (convert)** restores it. This needs a product
  call, not a data call.

### Product type overall
Today's correct behaviour = simple + flat `_price`. R1 preserves that. Changing type is **B2 only** and must be
deliberate. R1 and R2 assume the products **stay simple**.

---

## 5. Idempotency & reversibility

- **Backfill idempotent?** **Yes.** `set_regular_price( get_price() )` reads the current active `_price` and
  writes it to `_regular_price`; a second run writes the same value (nothing has shifted, no sale created). Safe
  to re-run. **Critical distinction:** the backfill must read `_price` and copy it — it must **not** be conflated
  with the +100 bump (which is *not* idempotent). Keep them separate operations (R1 vs R2) precisely so a re-run
  of R1 is harmless.
- **Rollback:** full restore from `~/backup-before-price-bump-20260716-1624.sql`. Mid-way failure leaves a
  partial set where each completed product is independently correct (`_regular_price=_price`) — simply re-run R1
  to completion (idempotent), no cleanup needed.
- **Batches:** yes — 204 products. Batch **50** (`wc_get_product`+`save` loads a full product object + writes
  meta/lookup/transients per item; 50 bounds memory and lets the lookup table settle). Nothing special needed
  between batches. Log every ID + old/new `_regular_price` to a file for an audit trail.

---

## 6. Release decomposition (data ops, not plugin code — **no version bump for any**)

**Your R1 → R2 → R3 split is correct.** Refinements + gates:

| # | What | Scope / `file:line`-free (data only) | Risk | Gate before proceeding |
|---|---|---|---|---|
| **R1** | Backfill `_regular_price=_price` for the 204 simple products | `wc_get_product`+`set_regular_price(get_price())`+`save()`, batched 50; products stay `simple` | **Low** | **First** dry-run 1 product (§4 SQL): children unchanged, `_price` unchanged, `_regular_price` set. Then batch. **Verify after:** 232/232 have `_regular_price==_price`; lookup table regenerated; menu visually unchanged (LiteSpeed purge + spot-check) |
| **R2** | +100 RWF on `_regular_price`, **excluding category 135** and **excluding variations** | Same WC-API path; per product, `set_regular_price(get_regular_price()+100)`+`save()`; skip cat-135 members and any product with an active `_sale_price` (decision) | **Medium** (touches every price customers see) | **Only after R1** so every target has a `_regular_price` to add to. Verify cat-135's 19 untouched; verify `_price` moved +100 on the rest; **not idempotent** — one run only, backup first |
| **R3** | Orphan variation cleanup (B1 delete **or** B2 convert-to-variable) | Data delete OR type conversion — **decision-gated** | B1 **Low**, B2 **High** | **Independent of R1/R2** and lowest urgency (invisible to customers). Requires §2 counts = 0 for B1; requires a product decision + per-variation pricing for B2 |

**Ranking by risk:** R1 lowest (idempotent, reversible, invisible) → do first. R2 medium (customer-visible, not
idempotent) → strictly after R1, backup + verify. R3 is the highest *ambiguity* (B2 especially) but the lowest
*urgency* — nothing customer-facing depends on it; it can trail indefinitely or be dropped (B1).

**One challenge to the ordering:** R3 need not precede R2 — the orphan variations are unpriced and unread, and the
bump only touches the 232 simple parents, so R2 is unaffected by whether the orphans still exist. Keep R3 last (or
never). And R1/R2 must explicitly **exclude `product_variation` rows** from all writes — only the 232 parents.

---

## Pending server checks (consolidated)
1. §1 queries (a)-(e) — full damage inventory incl. the 28's provenance and sale-price interaction.
2. §1 orphan-variation price query — do the 900 carry their own prices.
3. §2 two `COUNT` queries — **decisive**: any variation ID in `dishdash_order_items` or `woocommerce_order_itemmeta` (expect 0 → safe to delete in R3-B1).
4. §4 single-product dry-run before R1 — prove simple `save()` leaves children + `_price` untouched on this WC version.
5. (post-R1) confirm `_price == _regular_price` on all 232 and that `wp_wc_product_meta_lookup` regenerated.
