# R4b Verification — Diagnostic Investigation (READ-ONLY)

**Brief:** Reconcile "user 14 (habumugisha.innocent, +250785553103) shows **39** orders in the
browser, but the DB phone-anchored set is **56** (delivered 33, ready 10, cancelled 13). No clean
status filter produces 39." Find why the UI shows 39.

**Scope:** Read-only. No code edits, no version bump, no release. Claude Code has NO live DB access —
every count/verification query is marked **[RUN ON SERVER]** for the developer.

---

## Headline answer (the shortcut the developer asked for)

**The counted list is the full Order History page (Query C), NOT the "recent orders" widget.**
39 is far above the recent widget's `LIMIT 5` and favorites' `LIMIT 6`, so it can only be Query C.

Query C's only reductions vs. the raw 56-row count are:
1. `AND is_test = 0` — **excludes test orders** (the raw 56 almost certainly did NOT filter this), and
2. `LIMIT 50` — **not binding** at 39 (39 < 50).

Query C has **no status filter** (cancelled orders ARE shown) and **no date cutoff**.

**Therefore the 56→39 gap = ~17 rows with `is_test = 1`.** This is the primary reconciliation and
is a single [RUN ON SERVER] query to confirm (Task 4). If the raw 56 was computed with `+250785553103`
but the customer's stored `whatsapp` is a different string, a secondary phone-format effect is also
possible (Task 5, H1) — but since 39 is large, phone matching is clearly working, so `is_test` is by
far the likeliest gap.

---

## Task 1 — The three profile order-display queries (full SQL + assembling PHP)

All three build the WHERE from a **canonical-only phone set** = `[ customer->whatsapp ]`
(after `array_filter` drops empties). If the set is non-empty it becomes
`( customer_id = %d OR customer_phone IN (%s,…) )`; if empty it falls back to `customer_id = %d`.
`get_customer_for_user()` returns `SELECT *` (customer-manager.php:312-319) — so the phone value is
the **raw stored `wp_dishdash_customers.whatsapp` string, un-normalized at runtime**.

### Query A — Favorites — `modules/orders/class-dd-customer-profile.php:117-129`
PHP assembly (lines 100-115):
```php
$customer_id = (int) $user_id;
$phones = array_values( array_filter( [ (string) ( $customer->whatsapp ?? '' ) ] ) );
if ( $phones ) {
    $ph      = implode( ',', array_fill( 0, count( $phones ), '%s' ) );
    $where_o = "( o.customer_id = %d OR o.customer_phone IN ($ph) )"; // aliased (favorites)
    $match_args = array_merge( [ $customer_id ], $phones );
} else {
    $where_o    = "o.customer_id = %d";
    $match_args = [ $customer_id ];
}
```
SQL:
```sql
SELECT oi.menu_item_id, oi.item_name, SUM(oi.quantity) AS times_ordered
FROM wp_dishdash_order_items oi
JOIN wp_dishdash_orders o ON o.id = oi.order_id
WHERE ( o.customer_id = %d OR o.customer_phone IN (%s,…) )
  AND o.is_test = 0
  AND o.status NOT IN ('cancelled')
GROUP BY oi.menu_item_id, oi.item_name
ORDER BY times_ordered DESC
LIMIT 6
```
Item-level GROUP BY, `LIMIT 6`. **Cannot show 39.**

### Query B — Recent orders (reorder widget) — `class-dd-customer-profile.php:139-148`
```sql
SELECT id, order_number, total, status, created_at
FROM wp_dishdash_orders
WHERE ( customer_id = %d OR customer_phone IN (%s,…) )
  AND is_test = 0
  AND status NOT IN ('cancelled')
ORDER BY id DESC
LIMIT 5
```
`LIMIT 5`, excludes cancelled. **Cannot show 39.**

### Query C — Order History page — `modules/profile/class-dd-profile-module.php:151-158`
PHP assembly (lines 128-149):
```php
$customer = DD_Customer_Manager::get_customer_for_user( $user_id );
// … if ! $customer → early return ("add your phone number") …
$phones = array_values( array_filter( [ (string) ( $customer->whatsapp ?? '' ) ] ) );
if ( $phones ) {
    $ph    = implode( ',', array_fill( 0, count( $phones ), '%s' ) );
    $where = "( customer_id = %d OR customer_phone IN ($ph) )";
    $args  = array_merge( [ (int) $user_id ], $phones );
} else {
    $where = "customer_id = %d";
    $args  = [ (int) $user_id ];
}
```
SQL:
```sql
SELECT id, order_number, total, status, order_type, payment_method, created_at
FROM wp_dishdash_orders
WHERE ( customer_id = %d OR customer_phone IN (%s,…) ) AND is_test = 0
ORDER BY id DESC
LIMIT 50
```
Rendered by `render_order_history()` — echoes **every** returned row as a `.dd-order-card`
(the per-order items sub-query at :167 does not drop the card; the items `<ul>` is conditional but the
card wrapper is always emitted). **This is the 39-order list.**

---

## Task 2 — Which query renders the counted list

**Query C (`render_order_history`, profile-module.php:122-214).**

- It is hooked to `woocommerce_account_orders_endpoint` (priority 5, line 38) and titled
  **"Order history"** — the "Order History" menu tab the developer was viewing.
- It first `remove_all_actions('woocommerce_account_orders_endpoint')` (line 124), so WooCommerce's
  own paginated order list never runs. There is **no WooCommerce pagination** — the module echoes all
  rows directly. The only cap is the SQL `LIMIT 50`.
- 39 rows ≫ Query B's `LIMIT 5` and Query A's `LIMIT 6`, so neither of those is the counted list.

**Is 39 a LIMIT cap?** No. Query C's `LIMIT 50 > 39`, so the limit is not binding — 39 is the true
returned row count, not a truncation. (Had the developer been counting the *recent* widget, a low
LIMIT could have explained a capped number — but that widget is `LIMIT 5`, so this is ruled out.)

---

## Task 3 — Every filter narrowing Query C

| Filter | Present? | Detail |
|---|---|---|
| **Status filter** | ❌ **None** | No `status IN(...)`, no exclusion. **Cancelled orders ARE included.** So all of delivered 33 + ready 10 + cancelled 13 are eligible. |
| **`is_test`** | ✅ | `AND is_test = 0` — **test orders excluded.** The only status-independent row filter. |
| **LIMIT / offset** | ✅ (non-binding) | `LIMIT 50`, no offset. 39 < 50 → not truncating. |
| **Date cutoff** | ❌ None | No `created_at >` window. Full history. |
| **Deduplication** | ❌ None | No `DISTINCT`, no `GROUP BY`, no PHP-side dedupe. The render loop (:166-210) emits one card per row, skips nothing. |
| **JOIN drop/multiply** | ❌ None | Query C is single-table (`dishdash_orders`). The items fetch (:167) is a *separate* per-order query and never removes the parent order card (card head at :179 is unconditional). No INNER JOIN that could drop rows. |

**Net:** the ONLY thing separating Query C's result from the raw 56-row set is `AND is_test = 0`
(and a non-binding `LIMIT 50`). Everything else (status, date, dedupe, joins) is a no-op.

---

## Task 4 — Expected count vs. 39

Given Task 3, the UI count should equal the phone-anchored set **with test orders removed**:

**[RUN ON SERVER] — primary reconciliation**
```sql
-- Use the SAME phone string the app uses (see Task 5 H1 — confirm it first).
SELECT COUNT(*) AS ui_expected
FROM wp_dishdash_orders
WHERE ( customer_id = 14 OR customer_phone = '+250785553103' )
  AND is_test = 0;
```
**Prediction: 39.** If this returns 39 → **R4b VERIFIED. The number was correct all along; the raw 56
simply included ~17 test orders that the live page correctly hides.**

**[RUN ON SERVER] — prove the gap is exactly `is_test`**
```sql
SELECT is_test, COUNT(*) AS n
FROM wp_dishdash_orders
WHERE ( customer_id = 14 OR customer_phone = '+250785553103' )
GROUP BY is_test;
-- Expect: is_test=0 → 39, is_test=1 → 17  (39 + 17 = 56)
```

**[RUN ON SERVER] — status breakdown of what the page actually shows (all statuses, test excluded)**
```sql
SELECT status, COUNT(*) AS n
FROM wp_dishdash_orders
WHERE ( customer_id = 14 OR customer_phone = '+250785553103' )
  AND is_test = 0
GROUP BY status ORDER BY n DESC;
-- Sum should be 39. Cancelled rows WILL appear here (Query C has no status filter),
-- but their count will be lower than the raw 13 if some cancelled orders were test rows.
```

If COUNT = 39 → done, R4b is verified and the "56 vs 39" was an apples-to-oranges compare
(raw counted test rows; the page correctly excludes them). If it does **not** equal 39 → Task 5.

---

## Task 5 — If unreconciled: ranked hypotheses (each with a [RUN ON SERVER] SELECT)

### H1 — Phone-format mismatch: the app's `whatsapp` ≠ `'+250785553103'` (HIGH — verify FIRST)
`get_customer_for_user()` returns the **raw stored `whatsapp`** (customer-manager.php:316-318, `SELECT *`,
no re-normalization). Per CLAUDE.md the R3 E.164 flip migration has **not** run on the server
(`dd_phone_format` still `bare`), so `customers.whatsapp` for user 14 may be stored **bare**
(`250785553103`, no `+`) while R4a rewrote 6 order rows to E.164 (`+250785553103`) and newer
intl-tel-input guest orders store `+250…`. If the developer's raw "56" used `'+250785553103'` but the
app binds a **different** string, the app matches a different row set → the two counts are not
comparable and 39 may be the app matching a *different* ~37 rows.
> Note: because 39 is large, the phone arm is clearly matching ~37 rows, so `whatsapp` is *probably*
> already `+250785553103`. But this must be confirmed before trusting the Task 4 query.

**[RUN ON SERVER] — confirm the exact string the app uses, then re-run Task 4 with it verbatim:**
```sql
SELECT id, user_id, whatsapp, LENGTH(whatsapp) AS len, HEX(whatsapp) AS hx
FROM wp_dishdash_customers
WHERE user_id = 14;
-- If whatsapp = '+250785553103' → Task 4's query is valid as written.
-- If whatsapp = '250785553103' (bare, len 12) → the app matches bare-format order rows only;
--    re-run Task 4 with customer_phone = '250785553103' to get the app's TRUE set, then apply is_test.
-- HEX reveals hidden whitespace / leading chars if len looks off.
```
```sql
-- Distribution of the two matched arms in BOTH formats, to see which the app really hits:
SELECT
  SUM(customer_id = 14)                          AS by_customer_id,
  SUM(customer_phone = '+250785553103')          AS by_phone_e164,
  SUM(customer_phone = '250785553103')           AS by_phone_bare
FROM wp_dishdash_orders;
```

### H2 — `is_test` accounts for the whole gap (HIGH — the expected answer)
Already covered by Task 4's `GROUP BY is_test` (expect 17 test rows). If that shows is_test=0 → 39,
H2 is confirmed and no other hypothesis is needed.

### H3 — A second, un-migrated order-fetch path feeds the list (LOW)
Codebase grep for order fetches that could feed the profile:
- `class-dd-orders-module.php:519 / 526` — `get_customer_orders()`-style query, `AND customer_id = %d`
  (no phone OR). **Not wired to the My-Account Order History tab** (that tab is exclusively Query C via
  the `woocommerce_account_orders_endpoint` hook, which `render_order_history()` clears at :124). Feeds
  self-service order views, not this page.
- `class-dd-orders-module.php:1679` — single-order lookup (`LIMIT 1`), IDOR-gated. Not a list.
- Favorites/Recent (Query A/B) — different LIMITs, cannot yield 39.

**[RUN ON SERVER] — only if H1/H2 fail:** confirm no other list is on screen by checking the page HTML
has exactly one `.dd-order-history` block. (No SQL — DOM inspection.)

### H4 — PHP-side row skipping after the SQL (RULED OUT)
`render_order_history()` (:166-210) loops every returned row and always emits the card head (:179).
The only conditional is the items `<ul>` (:191) and the Reorder button (:204, hidden for cancelled),
neither of which removes the order card. **No hidden/soft-delete/status skip in PHP.**

### H5 — LIMIT 50 truncation (RULED OUT)
39 < 50, so the limit is not binding. Would only matter if the true is_test=0 set exceeded 50.

---

## Deliverable summary

- **Counted list** = Query C, the full Order History page (profile-module.php:151-158), `LIMIT 50`,
  **all statuses (incl. cancelled)**, `is_test = 0`. Not the recent widget, not a LIMIT cap.
- **Only narrowing filter vs. the raw 56** = `AND is_test = 0`.
- **Expected UI count** = phone-anchored set minus test orders. Predicted **39**; confirm with the
  Task 4 `COUNT(*) … AND is_test = 0` and the `GROUP BY is_test` (expect 17 test rows).
- **Before trusting Task 4**, confirm H1: the app binds the *raw stored* `whatsapp`; if that string
  isn't `+250785553103`, re-run Task 4 with the actual stored value.
- **Most likely conclusion:** R4b is correct; "56 vs 39" is raw-count (includes test orders) vs.
  live page (correctly excludes test orders). No code defect indicated.

*No files were modified. No version bump. No release.*
