# R4b Phase 1 — Read-Side Phone-Anchor: Pre-Code Confirmation (READ-ONLY)

Live version **v3.10.42** (`dish-dash.php:47`). Read-only — no edits. Confirms the three
target queries, the known-phone source, the IN-set decision, and the empty-set guard before
any Phase 2 code.

---

## 1a. The three target queries (line numbers re-confirmed — no drift)

**A — Favorites** (`modules/orders/class-dd-customer-profile.php:101-113`), JOIN + GROUP BY:
```sql
SELECT oi.menu_item_id, oi.item_name, SUM(oi.quantity) AS times_ordered
FROM {prefix}dishdash_order_items oi
JOIN {prefix}dishdash_orders o ON o.id = oi.order_id
WHERE o.customer_id = %d                 -- :106
  AND o.is_test = 0
  AND o.status NOT IN ('cancelled')
GROUP BY oi.menu_item_id, oi.item_name
ORDER BY times_ordered DESC LIMIT 6
```
bind `$customer_id` (`= (int) $user_id`, set at :100).

**B — Recent orders** (`class-dd-customer-profile.php:123-132`), single table:
```sql
SELECT id, order_number, total, status, created_at
FROM {prefix}dishdash_orders
WHERE customer_id = %d                    -- :126
  AND is_test = 0
  AND status NOT IN ('cancelled')
ORDER BY id DESC LIMIT 5
```
bind `$customer_id` (`= (int) $user_id`).

**C — Order history** (`modules/profile/class-dd-profile-module.php:139-146`), single table:
```sql
SELECT id, order_number, total, status, order_type, payment_method, created_at
FROM {prefix}dishdash_orders
WHERE customer_id = %d AND is_test = 0    -- :142
ORDER BY id DESC LIMIT 50
```
bind `(int) $user_id`.

- **Orders column is `customer_phone`** on all three (they query `dishdash_orders`; the
  phone-anchor targets `customer_phone` / `o.customer_phone`).
- **None filters on the customers-table PK.** All three bind the **WP user id**
  (`$user_id`), per the v3.10.31 fix. Confirmed correct.

## 1b. Known-E.164-set source (one user → one phone)

- Helper `DD_Customer_Manager::get_customer_for_user($user_id)`
  (`class-dd-customer-manager.php:312`): `SELECT * FROM dishdash_customers WHERE user_id =
  %d LIMIT 1` → exposes `->whatsapp` (E.164 post-R3).
- **UNIQUE constraint is LIVE in the schema, not just in deduped data:** `install.php:361`
  `UNIQUE KEY uniq_user_id (user_id)` (and `:360 UNIQUE KEY whatsapp`). So the DB itself
  guarantees **≤ 1 customers row per WP user** → **exactly one canonical `whatsapp`** per
  user. No code path can produce a second row for the same `user_id` (the insert/UPDATE
  would violate the unique key; `link_user_to_phone()` also refuses to steal).

## 1c. What goes in the IN set — recommend **(i) canonical-phone-only**

- **(i) canonical only** — set = `[ get_customer_for_user($uid)->whatsapp ]` (one E.164).
  Catches the primary case: guest orders whose `customer_phone` equals the user's own
  canonical number (e.g. user 14's 46 guest orders under `+250785553103`). Tight,
  predictable, one placeholder.
- **(ii) canonical + own-attributed phones** — additionally union `DISTINCT customer_phone`
  from orders already `customer_id = $uid`. **Redundant for pulling those orders** (they're
  already matched by the `customer_id = %d` branch), and it only helps drag in *other* guest
  orders that share a secondary number — which is exactly where the unresolved user-1/user-14
  **conflict phones** live. Pulling those in before R4c resolves attribution would
  cross-contaminate.

**Recommendation: (i) canonical-only for R4b.** It is sufficient to tie the guest orders
(they were placed under the user's canonical whatsapp), and it structurally cannot touch the
conflict phones (which are not the current user's canonical number). (ii) is deferred until
R4c settles attribution.

## 1d. Empty / NULL-set safety

- `whatsapp` is `NOT NULL DEFAULT ''` (schema :348) — so an unlinked/edge row yields `''`,
  and an unlinked user yields **no row at all** (`get_customer_for_user` → `null`).
- **Guard (mandatory):** build the phone array in PHP; **drop empties**
  (`array_filter`, reject `''`). **If the resulting set is empty → run the EXISTING
  `WHERE customer_id = %d` query unchanged.** Only when the set is non-empty do we add the
  `OR customer_phone IN (…)`.
- This avoids `IN ()` (SQL syntax error) and `IN (NULL)` (matches nothing, but never build
  it). A brand-new user / guest with no customers row therefore falls straight back to the
  `customer_id`-only query → sees only their own (likely empty) history, never everyone's.

## 2 (preview) — no double-count

- **B and C** are single-table `dishdash_orders` — the `OR` is a WHERE filter, so an order
  matched by both branches is still one row. No dup, no `DISTINCT` needed.
- **A** JOINs `order_items → orders` and `GROUP BY` item — the `OR` only widens which orders
  qualify; it adds no JOIN and cannot multiply the grouped result. No `DISTINCT`/extra
  `GROUP BY` needed.

**Prepared-statement shape (Phase 2, never string-concatenate phones):**
```php
$phones = array_values( array_filter( [ $customer->whatsapp ?? '' ] ) ); // canonical-only, drop ''
if ( $phones ) {
    $ph  = implode( ',', array_fill( 0, count( $phones ), '%s' ) );
    $sql = "... WHERE ( customer_id = %d OR customer_phone IN ($ph) ) AND is_test = 0 ...";
    $rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( [ $user_id ], $phones ) ) );
} else {
    // existing customer_id = %d query, unchanged
}
```
(For query A the column is `o.customer_phone` / `o.customer_id`.)

---

## Summary
- 1a: three queries confirmed at :106, :126, :142; all key on WP user id; orders column
  `customer_phone`; none uses the customers-PK. ✅
- 1b: `get_customer_for_user():312` exposes `whatsapp`; `UNIQUE KEY uniq_user_id`
  (install.php:361) makes 1-user→1-row a live DB guarantee. ✅
- 1c: recommend **(i) canonical-only** — sufficient for the guest-order case, avoids the
  conflict phones.
- 1d: build set in PHP, drop empties, **fall back to `customer_id = %d` only when empty** —
  no `IN ()`/`IN (NULL)`.
- No double-count on any of the three; prepared-placeholder list, never concatenated.

**Read-only; no code changed. Awaiting "proceed" for Phase 2.** Ownership gates
(1159/1631/1687/1718) will NOT be touched (out of scope).
