# INVESTIGATION — R3: orphan `product_variation` cleanup

**Phase 1, read-only. No code, no data, no deletes.** Plugin facts carry `file:line`; every live-DB fact is
**PENDING (server)** with exact SQL. WooCommerce core is **not** in this repo — `wp_delete_post()` behaviour is
core WC and carries a **mandatory single-variation dry-run** before any batch.

---

## TL;DR

- **§1 re-verified against current (post-v3.10.80) code:** `class-dd-ajax.php` reads **parent** attributes only
  (`get_attributes()` + `get_visible()` + `wc_get_product_terms()` on the *parent* id — taxonomy **terms**, not
  variation posts). A full-plugin grep for `get_children`/`product_variation`/variation reads returns **nothing**.
  Nothing in the plugin reads the 900 variation posts. The cleanup premise **still holds**.
- **The R2 chips key on `get_visible()` (`class-dd-api.php:563`), NOT `is_variation`.** So deleting the
  variations — and even the `is_variation:1` residue on the parents — is invisible to the chips.
- **Deleting is safe *as data*, but the mechanism has one real WC unknown:** `wp_delete_post()` on a variation
  whose parent is typed `simple` may trigger WC's parent-sync path (`WC_Product_Variable::sync()`), which assumes
  a variable parent. Low risk (orphans, simple parent → sync is a no-op or a notice, not corruption), but
  **unproven from the repo → dry-run one variation first.**
- **Decomposition:** R3 = delete the orphan variations only. The `_product_attributes` `is_variation` cleanup is
  **separable, optional, and higher-risk** (it rewrites the same serialized blob the chips read for visibility) —
  recommend **leaving it**. Data op, **no version bump**.

---

## 1. Re-verify unreferenced, against current code

**Current `class-dd-ajax.php:84-96` (post-v3.10.80)** iterates `$product->get_attributes()`, skips
`!$attr->get_visible()`, and reads options via `wc_get_product_terms( $product->get_id(), … )` (taxonomy) or
`$attr->get_options()`. Every call targets the **parent product** and its **attribute taxonomy terms** — it never
loads `get_children()`, `get_available_variations()`, or any `product_variation` post. **No change to the cleanup
premise.**

**Full-plugin grep** (`modules/`, `dishdash-core/`, `templates/`) for `get_children` / `product_variation` /
`get_available_variations` / `get_variation*` / `WC_Product_Variation` / `variation_id` / `is_type('variable')`,
excluding the free-text `order_items.variation` field and the chip `selectedAttributes`/`ddPmSelected` state:
**zero matches.** Nothing reads variation posts. (The `variation`/`variation_lines` references that exist are all
the R1 free-text order-item field — unrelated to the 900 posts.)

**Reference counts to re-run — PENDING (server)** (`{$P}` = prefix):
```sql
-- (a) DD order items pointing at a variation id (prior: 0)
SELECT COUNT(*) FROM {$P}dishdash_order_items
WHERE menu_item_id IN (SELECT ID FROM {$P}posts WHERE post_type='product_variation');

-- (b) WC order itemmeta _variation_id (prior: 0)
SELECT COUNT(*) FROM {$P}woocommerce_order_itemmeta
WHERE meta_key='_variation_id' AND meta_value NOT IN ('','0');

-- (c) NOT yet checked — tracking/behaviour tables keyed on product_id
SELECT COUNT(*) FROM {$P}dishdash_user_events
WHERE product_id IN (SELECT ID FROM {$P}posts WHERE post_type='product_variation');

-- (d) NOT yet checked — WC product lookup rows for the variations (these WILL exist; see §2)
SELECT COUNT(*) FROM {$P}wc_product_meta_lookup
WHERE product_id IN (SELECT ID FROM {$P}posts WHERE post_type='product_variation');

-- (e) NOT yet checked — WC analytics order-product lookup (should be 0, mirrors (b))
SELECT COUNT(*) FROM {$P}wc_order_product_lookup
WHERE product_id IN (SELECT ID FROM {$P}posts WHERE post_type='product_variation');
```
`dishdash_analytics`/`dishdash_pos_sessions` store JSON/aggregates (no product-id FK column per schema
`install.php`) and active **cart/WC sessions** are ephemeral (`{$P}woocommerce_sessions`, transient) and only ever
hold the **parent** product id (customers never add a variation) — note both as low-risk, non-blocking. `(a)`,
`(b)`, `(c)`, `(e)` must all be **0** before deleting; `(d)` is the lookup residue the delete must also clear (§2/§3).

> **STOP condition:** if the current `class-dd-ajax.php` (or anything else) is found to call `get_children()`/
> variation data, or if `(a)/(b)/(c)/(e)` is non-zero, the premise changes — halt and report before any delete.

## 2. Full delete scope (what a delete removes, per variation) — **PENDING (server)**

| Target | Rows | SQL |
|---|---|---|
| `wp_posts` (the variation) | 900 (publish) + any non-publish (§5) | `SELECT COUNT(*) FROM {$P}posts WHERE post_type='product_variation';` |
| `wp_postmeta` (its `_price`, `_regular_price`, `attribute_pa_*`, `_stock*`, `_thumbnail_id`, …) | ~15–25 × 900 | `SELECT COUNT(*) FROM {$P}postmeta WHERE post_id IN (SELECT ID FROM {$P}posts WHERE post_type='product_variation');` |
| `wp_wc_product_meta_lookup` | one per variation (~900) | see §1(d) |
| `wp_term_relationships` | expected **0** — variation attributes live in `attribute_*` postmeta, not term rels | `SELECT COUNT(*) FROM {$P}term_relationships WHERE object_id IN (SELECT ID FROM {$P}posts WHERE post_type='product_variation');` |
| Attachments (`_thumbnail_id`) | the referenced media are **separate posts, NOT deleted** — only the variation's meta pointer goes; confirm no shared thumbnails matter | (informational) |
| `wp_comments` | expected 0 (no reviews on variations) | `SELECT COUNT(*) FROM {$P}comments WHERE comment_post_ID IN (SELECT ID FROM {$P}posts WHERE post_type='product_variation');` |

No other DD table is keyed on a variation post id (§1). The heavy rows are **postmeta** (thousands) and the
**lookup** table (~900) — both must be cleaned, which decides the mechanism (§3).

## 3. Delete mechanism — the key unknown

**`wp_delete_post( $id, true )` (force) vs raw SQL:**

| | Cleans `wp_posts` | Cleans `wp_postmeta` | Cleans `wc_product_meta_lookup` | Clears WC transients | Fires WC hooks |
|---|---|---|---|---|---|
| `wp_delete_post($id,true)` / `wp post delete $id --force` | ✅ | ✅ (core deletes child meta) | ✅ (via WC `delete_post` hook) | ✅ | ✅ |
| Raw `DELETE FROM wp_posts …` | ✅ | ❌ (orphaned meta left) | ❌ (orphaned lookup left) | ❌ | ❌ |

**Recommend `wp_delete_post($id, true)` / `wp post delete --force`** — it is the only path that also clears the
postmeta and the lookup table (§2) and busts caches. Raw SQL would leave thousands of orphaned postmeta rows and
900 stale lookup rows.

**The WC unknown (investigate before batch):** these variations' parents are typed `simple`. On variation
deletion WC hooks fire — notably `woocommerce_before_delete_product_variation` / the data-store `delete()` and a
**parent sync** (`WC_Product_Variable::sync( $parent_id )` recomputes a variable parent's price range from its
children). For a `simple` parent, `wc_get_product($parent)` returns `WC_Product_Simple`, so the variable-sync
path is either a **no-op or emits a notice** — it does **not** rewrite a simple product's `_price`/`_regular_price`
(those aren't derived from children for simple products). **Risk assessment: low (no data corruption expected),
but unproven from the repo** because WC core isn't here. **Mandatory dry-run:** delete **one** variation on
staging/live-with-backup and verify:
```sql
-- pick one variation of a known parent, capture BEFORE, delete, then re-run AFTER:
SELECT p.ID, p.post_parent,
   (SELECT meta_value FROM {$P}postmeta WHERE post_id=p.post_parent AND meta_key='_price')          AS parent_price,
   (SELECT meta_value FROM {$P}postmeta WHERE post_id=p.post_parent AND meta_key='_regular_price')  AS parent_regular,
   (SELECT COUNT(*)   FROM {$P}posts    WHERE post_parent=p.post_parent AND post_type='product_variation') AS siblings
FROM {$P}posts p WHERE p.post_type='product_variation' LIMIT 1;
```
Expect after the single delete: **parent `_price`/`_regular_price` unchanged**, parent still renders on the menu,
the variation's postmeta + lookup row gone, **no PHP error/notice in the log**. Only then batch.

**Batch + order:** the 900 are independent orphans → **order does not matter**. Batch **50–100** per pass
(bounds memory; each `wp_delete_post` loads the object + fires hooks + writes lookup/transients). Pause between
passes; verify (§6) between batches.

## 4. The `_product_attributes` residue (`is_variation:1`)

Each parent's serialized `_product_attributes` marks `pa_spiciness-level` with `is_variation => 1` ("this
attribute generates variations").

- **Does anything read `is_variation`?** **The chips do NOT.** `map_product()` (mobile) and `ajax_get_product`
  (desktop) both key on **`get_visible()`** (`class-dd-api.php:563`; and the mirrored desktop loop
  `class-dd-ajax.php:86`) — the attribute's **`is_visible`** flag, a *different* field in the same serialized
  array. `is_variation` is only consulted by WC when a product is **`variable`**; for a **`simple`** product WC
  **ignores it**. → after the variations are gone, `is_variation:1` is **inert residue**, not harmful.
- **⚠️ If clearing it were attempted, it could break R2's chips.** `is_visible` and `is_variation` live in the
  **same** `_product_attributes` blob. A cleanup that rewrites that blob and accidentally drops `is_visible`
  (or drops the whole `pa_spiciness-level` entry) would make `get_visible()` false → **the chips would stop
  rendering** on both surfaces. So clearing `is_variation` is **not safe to do casually** and must never touch
  `is_visible`/the attribute entry.
- **Recommendation (report, not choose):** **leave `is_variation` as-is.** It is inert, and the only downside of
  keeping it is a cosmetic WC-admin note if a product is later edited. Clearing it buys nothing and risks the
  chips. If ever cleared, it must be a *surgical* edit that flips only `is_variation`→0 and preserves
  `is_visible` and the attribute options — a separate, carefully-scoped op, not part of the delete.

## 5. Non-published variations — **PENDING (server)**

The "900" is `post_status='publish'`. Drafts/private/trashed/auto-drafts may exist (the private parent **100608**
is a known case — a private parent's variations may be `private`/`publish`/inherit).
```sql
SELECT post_status, COUNT(*) FROM {$P}posts
WHERE post_type='product_variation' GROUP BY post_status;
-- and specifically the known private parent:
SELECT ID, post_status FROM {$P}posts WHERE post_type='product_variation' AND post_parent=100608;
```
All statuses are orphans by the same logic (nothing references any variation id) → the delete should target
**every** `product_variation` regardless of status, not just `publish`. The reference-count queries in §1 already
match on `post_type` (status-agnostic), so they cover these too.

## 6. Reversibility

- **Restore:** a full DB backup + `wp db import`. Take a **fresh** dump immediately before R3 (the existing
  `~/backup-before-price-bump-20260716-1624.sql` predates the +100 bump and v3.10.77-80 — do **not** rely on it
  for R3 rollback; it would revert unrelated work).
- **Trash as an intermediate:** `wp_delete_post($id, false)` / `wp post delete $id` (no `--force`) sets
  `post_status='trash'` — reversible via untrash, and a genuinely useful safety step: trash a batch, leave the
  site running, confirm menu/orders/chips are unaffected, then **force-delete the trashed set**. Cost: a second
  pass, and trashed rows still occupy `wp_posts`/postmeta/lookup until force-deleted (so trashing alone does
  **not** achieve the cleanup — it's a staging step, not the end state). Reasonable for the first batch;
  optional thereafter once the dry-run + first batch prove clean.
- **Verify between batches:** (a) a spot parent's `_price`/`_regular_price` unchanged; (b) the menu renders and
  the R2 chips still show on a spice product (mobile + desktop); (c) `wc_product_meta_lookup` variation-row count
  dropped by the batch size; (d) order history intact (`dishdash_order_items` untouched); (e) no new PHP
  errors/notices in the log.

## 7. Decomposition

- **R3 (this operation): delete the orphan `product_variation` posts** — all statuses (§5), via
  `wp_delete_post(true)` / `wp post delete --force`, batched 50–100, **after** a single-variation dry-run (§3) and
  a fresh backup (§6). One coherent data operation.
- **`_product_attributes` `is_variation` cleanup: SEPARATE and, recommended, NOT done.** It is inert residue (§4)
  and touching the serialized blob risks the R2 chips (which read `is_visible` from the same array). Keep it out
  of R3 entirely; if ever pursued, it's its own surgical, chip-preserving op with its own dry-run.

So: **one delete operation**, plus an explicitly-deferred (and discouraged) attribute-flag tidy. No plugin code,
**no version bump**.

---

## Pending server checks (consolidated — all must pass before deleting)
1. §1 (a)(b)(c)(e) reference counts → **all 0** (halt if any non-zero); §1 (d) = the lookup residue the delete clears.
2. §2 scope counts (posts / postmeta / lookup / term-rels / comments) — sizes the operation.
3. §5 status breakdown (incl. parent 100608) — confirm the delete targets all statuses.
4. §3 **single-variation dry-run** — parent `_price`/`_regular_price` unchanged, postmeta+lookup gone, no PHP error — the gate before batching.
5. Fresh DB backup taken immediately before R3 (not the pre-bump dump).
