# R4 Phase 1 — Investigation: Phone-Anchored Order Resolution (READ-ONLY)

Live version confirmed **v3.10.41** (`dish-dash.php:6` + `:47`; CLAUDE.md "Deployed
version v3.10.41"). Read-only — no edits, no writes, no migration. Code findings are
exact; DB counts (Tasks 4 & 5) require live SELECTs I cannot run from here — the exact
queries are provided for the server, clearly marked **[RUN ON SERVER]**.

---

## 1. Order-creation attribution (how `customer_id` is set today)

**Correction to the handover's "line 1142":** `class-dd-orders-module.php:1142` is NOT an
insert — it is a field in the `wp_send_json_success()` **response payload**
(`'customer_id' => $customer_result['customer_id'] ?? 0`), returned to the browser, never
written to `dishdash_orders`. The customers-PK appears there only as an AJAX return value.

**The one and only insert into `dishdash_orders`** is in `place_order()`:
```php
// class-dd-orders-module.php:354-382  (method place_order(), :319)
$order_data = [
    'order_number'   => $order_number,
    'branch_id'      => absint( $data['branch_id'] ?? 1 ),
    'customer_id'    => get_current_user_id() ?: null,          // ← WP user id, or NULL
    'customer_name'  => sanitize_text_field( $data['customer_name'] ),
    'customer_phone' => sanitize_text_field( $data['customer_phone'] ), // ← RAW, not normalized
    ...
];
$inserted = $wpdb->insert( $wpdb->prefix . 'dishdash_orders', $order_data, ... ); // :378
```

**Every insert path routes through `place_order()`** — its 5 callers are the gateway
branches of `ajax_place_order()`:
| Caller | class-dd-orders-module.php | Gateway |
|---|---|---|
| :877  | offline / COD (bacs, cheque) |
| :1012 | (online branch) |
| :1073 | (online branch) |
| :1200 | pending → confirm |
| :1270 | pending → confirm |

All of them insert via `place_order():378`, so **`customer_id` is always `get_current_user_id() ?: null`** — i.e. **WP user id (logged-in) or NULL (guest)**. The current code **never** writes the customers-table PK. Any customers-PK values sitting in `orders.customer_id` are **legacy** rows from pre-refactor code.

⚠️ **Nuance (gateway-confirm paths):** for MoMo/PesaPal/Irembo the order is created when
payment is *confirmed*, in a later request. If that confirmation runs **without the
customer's session** (server webhook/IPN rather than browser polling), `get_current_user_id()`
returns 0 → the order is stored as a **guest (NULL)** even though the customer was logged
in at checkout. Whether each confirm path carries the session is a runtime fact worth
confirming, but structurally this is a source of NULL attribution for logged-in customers.

**`customer_phone` is stored RAW** (`:359`, `sanitize_text_field($data['customer_phone'])`) —
it does **not** pass through `normalize_phone()`. What it contains depends on the client:
- New picker orders: `cart.js readPhone()` (:571) returns `iti.getNumber()` → **E.164
  `+250…`** (raw fallback only if the picker failed). So recent `customer_phone` values are
  already `+250…` and **match** the R3-migrated `customers.whatsapp`.
- Legacy orders: whatever was typed pre-picker — `0788…`, `250…`, `+250 788…` (mixed).

## 2. "Known phone numbers" for one WP user (post-dedupe)

- **Link:** `customers.user_id` (nullable, `UNIQUE` — added v3.9.8) points to `wp_users.ID`.
  `DD_Customer_Manager::get_customer_for_user($user_id)` (`:312`) is
  `SELECT * FROM dishdash_customers WHERE user_id = %d LIMIT 1`.
- Because `user_id` is `UNIQUE`, **one WP user maps to at most ONE customers row**, hence
  **one E.164 phone** (`$customer->whatsapp`, `+250…` post-R3). R3's survivor rule (linked
  `user_id`) and `link_user_to_phone()`'s no-steal guard keep it 1:1.
- So the "known phone set" for a user is effectively the single string
  `get_customer_for_user($uid)->whatsapp`. (A user who ordered with *other* phones as a
  guest has those only on the order rows, not on a second customers row — that's exactly
  what R4's phone-anchor is meant to reunite, but it can't expand the "known set" beyond the
  one linked number without a chosen policy.)
- **Columns:** `dishdash_customers` → PK `id`, phone `whatsapp` (VARCHAR(20), E.164),
  WP link `user_id`. `dishdash_orders` → `customer_id`, `customer_phone` (VARCHAR(50)).

## 3. Where the phone-anchor OR layers on (read-only design confirmation)

**Three history/aggregation queries** currently key on `customer_id = <wp_user_id>`
(the v3.10.31 fix — `$customer_id = (int) $user_id`):

| # | File:line | Current WHERE |
|---|---|---|
| A | class-dd-customer-profile.php:106 (favorites, JOINs order_items→orders, GROUP BY) | `WHERE o.customer_id = %d` |
| B | class-dd-customer-profile.php:126 (recent orders) | `WHERE customer_id = %d` |
| C | class-dd-profile-module.php:142 (order history) | `WHERE customer_id = %d` |

**Target shape** (per user, with `$phones` = the known E.164 set from Task 2):
```
WHERE ( customer_id = %d OR customer_phone IN ( … ) ) AND is_test = 0 AND status …
```
- Orders-side column is **`customer_phone`** — confirmed on all three. **None** filters on
  the customers-PK by mistake; all three already use the WP user id.
- **Double-count risk:** none from the OR itself. B and C are single-table → a row matched
  by both branches is still one row. A JOINs `order_items→orders` and `GROUP BY
  menu_item_id,item_name` → the OR widens which orders qualify but does not multiply rows
  per order. **Flag:** the *only* way the OR duplicates is if the phone set contained a
  number belonging to a different person's orders — an identity/data risk, not a SQL-dup
  risk (and it would cross-attribute, worse than a dup). Keep the phone set to the user's
  own linked E.164.

**Access-control reads (separate concern — decision point, not a history query):** ownership
gates compare `customer_id` to `get_current_user_id()` at
`class-dd-orders-module.php:1159` (ajax_get_order), `:1631`, `:1687` (order_permission),
and `:1718` (get_orders for the account). If R4 wants a user to *open* an order matched only
by phone (not user_id), these gates would also need the OR — but that widens who can view an
order and must be decided deliberately (a shared/mistyped phone could expose another
person's order). **Recommend R4 scope the phone-anchor to the read-only history/aggregation
queries A/B/C first, and treat the ownership gates as a separate, carefully-reasoned step.**

## 4. Backfill need — [RUN ON SERVER] (characterize, don't run)

I cannot query the live DB. Run these read-only SELECTs (replace `wp_` with the live
prefix). Report the counts back.

```sql
-- (a) guest orders (NULL/0)
SELECT COUNT(*) FROM wp_dishdash_orders WHERE customer_id IS NULL OR customer_id = 0;

-- (b) customer_id that matches a real WP user
SELECT COUNT(*) FROM wp_dishdash_orders o
JOIN wp_users u ON u.ID = o.customer_id WHERE o.customer_id > 0;

-- (c) customer_id > 0 that is NOT a WP user but IS a customers-table PK (legacy mis-attribution, e.g. order 121)
SELECT COUNT(*) FROM wp_dishdash_orders o
WHERE o.customer_id > 0
  AND NOT EXISTS (SELECT 1 FROM wp_users u        WHERE u.ID = o.customer_id)
  AND EXISTS     (SELECT 1 FROM wp_dishdash_customers c WHERE c.id = o.customer_id);

-- (c2) customer_id > 0 that matches NEITHER a WP user NOR a customers PK (pure orphan)
SELECT COUNT(*) FROM wp_dishdash_orders o
WHERE o.customer_id > 0
  AND NOT EXISTS (SELECT 1 FROM wp_users u        WHERE u.ID = o.customer_id)
  AND NOT EXISTS (SELECT 1 FROM wp_dishdash_customers c WHERE c.id = o.customer_id);

-- (d) guest orders recoverable by EXACT phone match (works only where order phone is already E.164)
SELECT COUNT(*) FROM wp_dishdash_orders o
JOIN wp_dishdash_customers c ON c.whatsapp = o.customer_phone
WHERE (o.customer_id IS NULL OR o.customer_id = 0);

-- (d2) same, but format-agnostic digit compare (catches legacy-format order phones too)
SELECT COUNT(*) FROM wp_dishdash_orders o
JOIN wp_dishdash_customers c
  ON REGEXP_REPLACE(c.whatsapp,'[^0-9]','') = REGEXP_REPLACE(o.customer_phone,'[^0-9]','')
WHERE (o.customer_id IS NULL OR o.customer_id = 0);

-- Handover examples — report exact rows:
SELECT id, customer_id, customer_phone FROM wp_dishdash_orders WHERE id IN (120,121,122);
-- Whose customer row does order 121's phone belong to?
SELECT c.id, c.user_id, c.whatsapp FROM wp_dishdash_customers c
WHERE REGEXP_REPLACE(c.whatsapp,'[^0-9]','') =
      REGEXP_REPLACE((SELECT customer_phone FROM wp_dishdash_orders WHERE id = 121),'[^0-9]','');
```
Expectation to verify: order 121 → `customer_id = 14` (a WP user) but its phone maps to a
*different* customers row (mis-attribution); orders 120/122 → `customer_id` NULL (guest)
with phones that now match migrated `+250…` records. **A one-time attribution backfill is
warranted** if (c)/(c2)/(d2) are non-trivial; its matching key would be **normalized
`customer_phone` → `customers.whatsapp` (E.164)**, and it would set `orders.customer_id`
to the matched customer's `user_id` (the canonical WP-user-id direction). Do NOT design/run
it yet.

## 5. Format / matching-key integrity — the linchpin — [RUN ON SERVER]

```sql
-- Is the flip live?
--   wp option get dd_phone_format            → expect 'e164'
-- Sample order phones (eyeball the format):
SELECT id, customer_id, customer_phone FROM wp_dishdash_orders ORDER BY id DESC LIMIT 25;
-- Order-side format distribution:
SELECT
  SUM(customer_phone LIKE '+%')                AS starts_plus,     -- E.164 (matches customers)
  SUM(customer_phone REGEXP '^250[0-9]{9}$')   AS bare_250,        -- legacy bare
  SUM(customer_phone LIKE '0%')                AS starts_zero,     -- legacy trunk-0
  SUM(customer_phone REGEXP '[^0-9+]')         AS has_separators,  -- spaces/dashes
  SUM(customer_phone = '' OR customer_phone IS NULL) AS empty_null
FROM wp_dishdash_orders;
-- Customer-side (should be all '+…' post-R3):
SELECT SUM(whatsapp LIKE '+%') AS plus, COUNT(*) AS total FROM wp_dishdash_customers;
```

**Code-proven finding (does not need the DB):** `customers.whatsapp` is E.164 (`+250…`) after
R3, but `orders.customer_phone` is stored **raw** (`place_order():359`) and is **NOT run
through `normalize_phone()`**. Therefore:
- **New picker orders** → `+250…` (from `getNumber()`) → **match** the customers table. ✅
- **Legacy orders** → mixed (`0788…`, `250…`, `+250 788…`) → will **NOT** match a `+250…`
  key on an exact comparison. ❌

**This is the critical R4 risk the brief flagged:** an exact `customer_phone = customers.whatsapp`
(or `IN (…)`) match **silently returns nothing** for legacy-format order phones. SQL cannot
run libphonenumber, so a reliable phone-anchor needs one of:
1. a **one-time backfill** normalizing `orders.customer_phone` to E.164 via the PHP
   `normalize_phone()` (preferred — makes the column a clean matching key), or
2. a **normalized shadow column** (e.g. `customer_phone_e164`) populated on write + backfilled, or
3. a digit-stripped comparison in SQL (`REGEXP_REPLACE(...,'[^0-9]','')`) as a *matching*
   convenience — fragile for trunk-0/foreign edge cases (exactly what R3-fix handled in PHP),
   so acceptable for characterization but not as the canonical key.

The distribution query above tells us how big the legacy-format tail is and thus whether the
match key must be normalized before the OR can be trusted. **Confirm order-side vs
customer-side format parity with the actual SELECT before any build brief** — an assumed
parity here would make the phone-anchor a silent no-op.

---

## Summary of answers
1. Single insert (`place_order():378`); `customer_id = WP user id | NULL`; `customer_phone`
   **raw, unnormalized**; line 1142 is a response field, not a write. Gateway-confirm paths
   can yield NULL for logged-in customers.
2. `customers.user_id` (UNIQUE) → one customers row → one E.164 phone
   (`get_customer_for_user():312`). Known set = that single `whatsapp`.
3. OR layers on the 3 history/aggregation queries (customer-profile.php:106/:126,
   profile-module.php:142) as `(customer_id = %d OR customer_phone IN (…))`; orders column is
   `customer_phone`; no double-count from the OR; ownership gates (1159/1631/1687/1718) are a
   separate, security-sensitive decision.
4. [RUN ON SERVER] counts pending; a normalized-phone attribution backfill is likely
   warranted (matching key = normalized `customer_phone` → `customers.whatsapp`), canonical
   target `orders.customer_id = user_id`.
5. [RUN ON SERVER] format check pending; **code shows order-side is raw/mixed while
   customer-side is E.164 — the match key must be normalized or the phone-anchor no-ops on
   legacy rows.** Linchpin risk. Confirm `dd_phone_format = e164` too.

## Out-of-scope items raised (not acted on)
- **DB password rotation** (exposed a prior session): flagged. Rotate + update `wp-config.php`
  out-of-band; do not paste credentials in chat. Independent of R4; not touched here.

**Read-only. No writes performed. Awaiting the Task 4/5 server SELECT results and a scoping
discussion before any R4 build brief.**
