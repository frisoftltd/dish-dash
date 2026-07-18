# INVESTIGATION — Non-published products in pre-repair state

**Phase 1, read-only. No code, no data, no publish/repair/delete.** This is a **menu/data** question — almost
everything is **PENDING (server)** with exact SQL. Two grounding facts come from the code (below); the rest is
the developer's queries + a menu decision.

---

## TL;DR

- **Nothing forces action before go-live.** The 26 non-published products are **invisible to customers today** —
  the menu/frontend reads published-only: `DD_API::get_products()` defaults `['status'=>'publish']`
  (`class-dd-api.php:150`) and `wc_get_products()` is published-only by default. A private/draft product cannot
  appear on the menu, cart, or search. **Leaving all 26 hidden is a safe launch.**
- The repair states only matter **if** a product is chosen for publishing — and publishing a priceless one is
  visibly broken: `get_price()` returns `''` → `product-card.php:44` renders an **empty** price (no "RWF 0", just
  blank). So the two price-broken ones (100409, 102706) must **not** be published without pricing first.
- The real question is **editorial, not technical**: which of the 26 are retired duplicates (rename artifacts like
  private "Murg X" = published "Chicken X") vs genuine dishes the restaurant wants live. SQL below surfaces the
  duplicate pairs; the keep/publish/delete call is the developer's.

---

## Grounding facts (from code)

1. **Non-published = invisible.** `DD_API::get_products()` → `wp_parse_args($args, ['limit'=>10,'status'=>'publish'])`
   (`class-dd-api.php:150`), and every menu/hero/search path routes through `wc_get_products()` (published-only)
   or `DD_API`. No frontend query includes `private`/`draft`. → the 26 are hidden from customers **now**.
2. **Priceless render.** `product-card.php:43-44`: `$raw_price = (float) $product->get_price(); $price = $raw_price ? 'RWF '…: ''`.
   A NULL/missing `_price` → `get_price()===''` → `$raw_price=0.0` → `$price=''` → the card shows **no price** (not
   "RWF 0"). Publishing 100409 (no `_price`) or 102706 (both NULL) would render priceless.
3. **Category IDs are data, not code.** `132/133/135/141` and the spice-removal rule live only in the DB / the
   ops you ran — nothing in the plugin references them (grep: none). So the 26's categories + spice state must be
   read via SQL (§5).

---

## 1. Full inventory — **PENDING (server)** (`{$P}` = prefix)

```sql
SELECT p.ID, p.post_title, p.post_status,
       pr.meta_value AS price,
       rp.meta_value AS regular_price,
       GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS categories
FROM {$P}posts p
LEFT JOIN {$P}postmeta pr ON pr.post_id=p.ID AND pr.meta_key='_price'
LEFT JOIN {$P}postmeta rp ON rp.post_id=p.ID AND rp.meta_key='_regular_price'
LEFT JOIN {$P}term_relationships tr ON tr.object_id=p.ID
LEFT JOIN {$P}term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
LEFT JOIN {$P}terms t ON t.term_id=tt.term_id
WHERE p.post_type='product' AND p.post_status IN ('private','draft','pending')
GROUP BY p.ID
ORDER BY p.post_status, p.post_title;
```
Should return the 26 (private + draft; `pending` included defensively — if the count ≠ 26, widen to
`post_status NOT IN ('publish','trash','auto-draft','inherit')` and report the extra statuses). This is the base
table for §2-§5; the "near-identical published name" column is produced by §2.

## 2. Duplicate detection — **PENDING (server)**

Pure SQL can't do true fuzzy matching, but the rename pattern is mechanical: Indian dishes keep the **dish word**
(Sagwala/Masala/Korma/Lababedar…) and swap the **protein prefix** (Murg↔Chicken, Paneer, etc.). Match on the
**last word** of the title, then eyeball:

```sql
-- each non-published product ↔ published products sharing the last title word (dish name)
SELECT np.ID AS np_id, np.post_title AS np_title, np.post_status,
       pub.ID AS pub_id, pub.post_title AS pub_title,
       GROUP_CONCAT(DISTINCT t.name) AS np_categories
FROM {$P}posts np
JOIN {$P}posts pub
  ON pub.post_type='product' AND pub.post_status='publish'
 AND LOWER(SUBSTRING_INDEX(pub.post_title,' ',-1)) = LOWER(SUBSTRING_INDEX(np.post_title,' ',-1))
LEFT JOIN {$P}term_relationships tr ON tr.object_id=np.ID
LEFT JOIN {$P}term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
LEFT JOIN {$P}terms t ON t.term_id=tt.term_id
WHERE np.post_type='product' AND np.post_status IN ('private','draft','pending')
GROUP BY np.ID, pub.ID
ORDER BY np.post_title;
```
**Read it as:** a non-published product that shares its dish-word with a published one — especially with a
**protein-prefix swap** (Murg/Chicken, or the same dish under Paneer vs Chicken) and the **same category** — is
almost certainly a **retired rename → keep hidden**. Flag same-name + same-category + similar price as
high-confidence duplicates; last-word match but different dish/category as "review". (Also worth a manual pass for
protein synonyms the last-word test misses: *Murg*=Chicken, *Gosht/Mutton*=Lamb, *Jhinga*=Prawn.)

## 3. Genuine unpublished dishes (no published equivalent) — **PENDING (server)**

```sql
SELECT np.ID, np.post_title, np.post_status,
       (SELECT meta_value FROM {$P}postmeta WHERE post_id=np.ID AND meta_key='_price')         AS price,
       (SELECT meta_value FROM {$P}postmeta WHERE post_id=np.ID AND meta_key='_regular_price') AS regular_price
FROM {$P}posts np
WHERE np.post_type='product' AND np.post_status IN ('private','draft','pending')
  AND NOT EXISTS (
    SELECT 1 FROM {$P}posts pub
    WHERE pub.post_type='product' AND pub.post_status='publish'
      AND LOWER(SUBSTRING_INDEX(pub.post_title,' ',-1)) = LOWER(SUBSTRING_INDEX(np.post_title,' ',-1))
  )
ORDER BY np.post_title;
```
These have **no** last-word match among published products → the real candidates for **publish (after repair)**
or **delete (if abandoned)**. Everything *not* in this list is a probable duplicate (§2) → keep hidden.

## 4. If repaired, what each state needs

Per the four states (confirm each product's actual meta with §1 first):

| State (count) | Meta today | Repair (only if publishing) |
|---|---|---|
| **Missing `_regular_price` (16)** | `_price` set, `_regular_price` NULL | Backfill via the v-less R1 pattern — `wc_get_product($id)->set_regular_price( get_price() )->save()` (recomputes `_price`, updates lookup, clears caches) → **then +100** via the same API if **not** category 135. Idempotent read of `_price`. |
| **Missing `_price` (1 — Chicken Tikka Masala, 100409)** | confirm `_regular_price`: if **set**, a `set_regular_price(get_regular_price())->save()` recomputes `_price` from regular → fixed. If `_regular_price` is **also** NULL, it's the "both NULL" case. **Verify before acting.** |
| **Both NULL (1 — Chilli Paneer Lababedar, 102706)** | no price anywhere | **Incomplete product — do not guess a price.** Publishing renders priceless (grounding fact #2). Needs a menu decision: price it (then publish) or leave hidden/delete. **Flag, don't repair.** |
| **Both set & matching (8)** | `_price==_regular_price`, pre-bump | If they should track the bumped menu → **+100** (ex cat 135); if intentionally priced (e.g. a special) → **leave**. Menu decision. |

All repair ops are the **same WC-API path** used for the published set (`set_regular_price()`+`save()` — no direct
meta writes), so the lookup table / caches stay correct. None of this runs unless a product is chosen to publish.

## 5. Spice attribute state — **PENDING (server)**

```sql
SELECT p.ID, p.post_title, p.post_status,
       CASE WHEN pa.meta_value LIKE '%pa_spiciness-level%' THEN 'YES' ELSE 'no' END AS has_spice_attr,
       GROUP_CONCAT(DISTINCT tt.term_id ORDER BY tt.term_id) AS category_ids,
       GROUP_CONCAT(DISTINCT t.name    ORDER BY t.name)     AS categories
FROM {$P}posts p
LEFT JOIN {$P}postmeta pa ON pa.post_id=p.ID AND pa.meta_key='_product_attributes'
LEFT JOIN {$P}term_relationships tr ON tr.object_id=p.ID
LEFT JOIN {$P}term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
LEFT JOIN {$P}terms t ON t.term_id=tt.term_id
WHERE p.post_type='product' AND p.post_status IN ('private','draft','pending')
GROUP BY p.ID;
```
- The spice **chips now read any *visible* `pa_spiciness-level` attribute generically** (v3.10.80,
  `class-dd-ajax.php` mirrors `map_product`), so a published product carrying the attribute **will show chips**.
- **If** any of the 26 get published **and** fall in the categories where the published set had spice removed
  (132/133/135/141 — the editorial "no spice here" set), they need the **same category-based removal** to stay
  consistent. The query above reports each product's `has_spice_attr` + `category_ids` so the developer can apply
  the identical rule per published product. **Report only — do not strip anything.**

## 6. Recommendation (report, do not act)

**Three buckets** — populated by §2/§3, decided by the developer:

- **Leave hidden (duplicates / retired):** the §2 matches — private "Murg/Paneer X" mirroring a published
  "Chicken X" in the same category. **No action; safe for go-live as-is.** Almost certainly the majority.
- **Repair + publish (genuine dishes the restaurant wants live):** the §3 no-equivalent list, **per the
  developer's yes on each**. Then apply the §4 per-state repair (WC-API path) and the §5 spice rule. The two
  price-broken IDs (100409, 102706) must be priced first — never publish priceless.
- **Delete (abandoned / unfinished, no equivalent):** decision-gated — e.g. 102706 (both NULL) if the restaurant
  doesn't want it. Deletion is a separate op with its own confirmation.

**Does anything force action before launch? No.** All 26 are invisible to customers (grounding fact #1), so the
**safest go-live answer is "leave all 26 hidden"** and treat this as **optional post-launch cleanup**. The *only*
non-technical risk is a genuine dish (a §3 entry) that the restaurant **expects** to be live — that's a
**menu-content check with the restaurant**, not a code/data blocker. Nothing here can break the published menu:
the 232 are untouched and the read path is published-only.

---

## Pending server checks (consolidated)
1. §1 — full 26-row inventory (ID/title/status/`_price`/`_regular_price`/categories); confirm the count is 26.
2. §2 — last-word duplicate join → the retired-rename pairs (keep hidden).
3. §3 — no-published-equivalent list → publish/delete candidates.
4. §4 — verify 100409 has `_regular_price` (fixable by `save()`) vs is incomplete; confirm the 16 / 8 buckets.
5. §5 — `has_spice_attr` + `category_ids` per product (spice-removal need if published).
6. (menu-content, with the restaurant) — is any §3 dish *expected* live? The only thing that would force pre-launch action.
