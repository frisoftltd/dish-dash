# Investigation: Test Customer Flag

**Type:** Read-only investigation. No files edited, no DB writes, no commits.
**Date:** 2026-08-05

---

## 0. CLAUDE.md state check

- `DD_VERSION` / "Deployed version" / "Last updated": **v3.15.3** — accurate, correctly maintained every release.
- **Stale fields found:** "Current phase" (Phase 7), "Current sub-phase," "Next task," and "Last working state" are all frozen at the **v3.13.5 era** — text still describes CSV import as the "last shipped" work and says "no code work currently queued," despite v3.14.0–v3.15.3 (paid reservations, billing page, rider notification, etc.) having shipped since. Only the version-number fields have been kept current; the narrative prose fields have not. Not fixing — noted per instructions, and flagged again in Observations.
- Phase 8 backlog explicitly lists "test customer flag" as a queued, not-yet-built item — confirms this investigation matches current project state.

---

## 1. Customer storage

### Schema — `wp_dishdash_customers` (`install.php`, table 11)

```sql
CREATE TABLE wp_dishdash_customers (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           BIGINT(20) UNSIGNED NULL DEFAULT NULL,
    whatsapp          VARCHAR(20)     NOT NULL DEFAULT '',
    name              VARCHAR(255)    NOT NULL DEFAULT '',
    delivery_address  TEXT                     DEFAULT NULL,
    birthday          DATE                     DEFAULT NULL,
    dd_birthday_asked TINYINT(1)      NOT NULL DEFAULT 0,
    total_orders      INT UNSIGNED    NOT NULL DEFAULT 0,
    total_spent       DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
    first_order_at    DATETIME                 DEFAULT NULL,
    last_order_at     DATETIME                 DEFAULT NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY   whatsapp (whatsapp),
    UNIQUE KEY   uniq_user_id (user_id)
)
```

**No `is_test` column exists on this table today.**

### Identity anchor confirmation

`whatsapp VARCHAR(20) NOT NULL DEFAULT ''` with `UNIQUE KEY whatsapp (whatsapp)` — **confirmed NOT NULL + UNIQUE**, matching the brief's premise. One nuance: the default is an empty string, not a true `NULL` — MySQL's `UNIQUE` constraint does enforce uniqueness on empty strings (unlike `NULL`, which is exempt), so this can't silently create duplicate blank-identity rows, but there's also no `CHECK` preventing a row from ever being inserted with `whatsapp=''` in the first place. `DD_Customer_Manager::upsert()` (see below) does guard against this — it returns early with `customer_id => 0` if `normalize_phone()` produces an empty string — so in practice this path is closed off by application logic, not the schema itself.

### Creation function

`modules/orders/class-dd-customer-manager.php` — `DD_Customer_Manager::upsert(string $whatsapp, string $name, string $delivery_address, float $order_total): array`

- Normalizes phone via `self::normalize_phone()`, looks up by `whatsapp`, `UPDATE`s stats (`total_orders++`, `total_spent += $order_total`, `last_order_at`) if found, else `INSERT`s a new row with `total_orders=1`.
- **Called from 5 places, all in `modules/orders/class-dd-orders-module.php`, all at order-creation/payment-confirmation time**: IremboPay confirm (`:992`), MoMo poll success (`:1348`), main `place_order()` flow (`:1221`), PesaPal poll promote (`:1708`), PesaPal IPN fallback create (`:1887`).
- **None of these 5 call sites check `is_test` before calling `upsert()`.** This is a real, pre-existing gap — see Observations.
- Reservations do **not** call `upsert()` directly — they resolve/create a customer via the `dd_resolve_customer_id` filter instead (see §3).

---

## 2. Order ↔ customer link

**Not a real foreign key, and not WooCommerce order meta.** The actual mechanism is a mix of two things, and they're inconsistent with each other:

1. **`wp_dishdash_orders.customer_id`** (`BIGINT UNSIGNED DEFAULT NULL`) is populated at insert time (`class-dd-orders-module.php:366`) as:
   ```php
   'customer_id' => get_current_user_id() ?: null,
   ```
   **This stores the WordPress user ID, not `wp_dishdash_customers.id`.** It's `NULL` for every guest checkout (the majority case in this market, per the product's own WhatsApp-first design).

2. **The real link to `wp_dishdash_customers`** is a denormalized string match: `orders.customer_phone` against `customers.whatsapp`, resolved fresh on each request via `DD_Customer_Manager::upsert()` — there is no column on `wp_dishdash_orders` that stores `wp_dishdash_customers.id`.

This means `orders.customer_id` is a misleadingly-named column — it looks like a customer-table FK but isn't one. See Observations for a concrete bug this causes in `analytics.php`.

### Order status

Column: `status VARCHAR(50) NOT NULL DEFAULT 'pending'` on `wp_dishdash_orders`. Free text, not an ENUM. Values actually used in code: `'pending'`, `'confirmed'`, `'ready'`, `'delivered'`, `'cancelled'`, `'pending_payment'` (PesaPal-pending, a real distinct value — see the reservations-fee investigation from earlier this session for why that distinction matters).

**Confirmed exact string: `'delivered'` — all lowercase**, e.g. `class-dd-orders-module.php:594,617,674,676` and every billing/analytics/dashboard query that gates on delivered orders. Not `"Delivered"` capitalized as written in the brief — worth being precise about since this is a free-text column, not an enum, so exact casing matters for every query.

---

## 3. Reservation ↔ customer link

**Different, and — unlike orders — actually correct.** Reservations resolve a customer via:
```php
$customer_id = (int) apply_filters( 'dd_resolve_customer_id', 0, $whatsapp, $name );
```
(`class-dd-reservations-module.php`, in `ajax_submit_reservation()`), which is answered by `DD_Customer_Manager::on_resolve_customer_id()` (`class-dd-customer-manager.php:181-219`) — looks up/creates by `whatsapp`, and **returns the real `wp_dishdash_customers.id`**. That value is then stored directly into `wp_dishdash_reservations.customer_id` at insert.

So `reservations.customer_id` **does** correctly FK to `wp_dishdash_customers.id` — while `orders.customer_id` (same column name) does not. Two tables, identically-named column, two different meanings. Flagged in Observations.

### Schema — `wp_dishdash_reservations` (`install.php`, table 6, current after this session's work)

```sql
CREATE TABLE wp_dishdash_reservations (
    id                BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    table_id          INT UNSIGNED                 DEFAULT NULL,
    branch_id         BIGINT UNSIGNED     NOT NULL,
    customer_name     VARCHAR(255)        NOT NULL DEFAULT '',
    customer_phone    VARCHAR(50)         NOT NULL DEFAULT '',
    customer_email    VARCHAR(255)        NOT NULL DEFAULT '',
    party_size        INT UNSIGNED        NOT NULL DEFAULT 2,
    reservation_date  DATE                NOT NULL,
    reservation_time  TIME                NOT NULL,
    status            VARCHAR(20)         NOT NULL DEFAULT 'pending',
    notes             TEXT                         DEFAULT NULL,
    created_at        DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    duration_minutes  INT UNSIGNED        NOT NULL DEFAULT 90,
    booking_ref       VARCHAR(20)         NOT NULL DEFAULT '',
    customer_id       BIGINT UNSIGNED              DEFAULT NULL,   -- correctly FKs to dishdash_customers.id
    date              DATE                NOT NULL,
    time              VARCHAR(5)          NOT NULL DEFAULT '',
    session           VARCHAR(10)         NOT NULL DEFAULT '',
    guests            TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
    name              VARCHAR(100)        NOT NULL DEFAULT '',
    whatsapp          VARCHAR(30)         NOT NULL DEFAULT '',
    special_requests  TEXT                         DEFAULT NULL,
    source            VARCHAR(30)         NOT NULL DEFAULT '',
    updated_at        DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deposit_required  TINYINT(1)          NOT NULL DEFAULT 0,
    deposit_amount    INT UNSIGNED        NOT NULL DEFAULT 0,
    deposit_status    VARCHAR(20)         NOT NULL DEFAULT 'none',
    deposit_paid_at   DATETIME                     DEFAULT NULL,
    payment_ref       VARCHAR(100)                 DEFAULT NULL,   -- unused, dead column
    pesapal_tracking_id VARCHAR(64)                DEFAULT NULL,
    deposit_proof_attachment_id BIGINT UNSIGNED     DEFAULT NULL,
    platform_fee      INT UNSIGNED        NOT NULL DEFAULT 0,
    is_test           TINYINT(1)          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY  booking_ref (booking_ref),
    UNIQUE KEY  pesapal_tracking_id (pesapal_tracking_id),
    KEY table_id, branch_id, reservation_date, status, customer_id, date, is_test
)
```

### Reservation status values — corrected against the brief's assumed values

The brief asks to confirm "Paid, Confirmed, No-show, deposit-required flag" as reservation statuses. **These aren't all the same kind of field** — reservations actually split billability across two independent columns:

- **`status`** (booking lifecycle): `'pending'`, `'confirmed'`, `'cancelled'`, `'no_show'` (lowercase+underscore, not "No-show"), `'auto_cancelled'`.
- **`deposit_status`** (payment lifecycle, separate column): `'none'`, `'pending'`, `'claimed'`, `'paid'`, `'failed'`, `'refunded'`. **"Paid" is a `deposit_status` value, not a `status` value** — a reservation is never `status='paid'`.
- **`deposit_required`** — real column, `TINYINT(1) NOT NULL DEFAULT 0`. Confirmed exists exactly as described.

A reservation only counts as "billable" when `deposit_required=1 AND deposit_status='paid'`, OR `deposit_required=0 AND status='confirmed'` — this exact combined condition is already implemented in `admin/pages/billing.php` (shipped this session, v3.15.0) and is the closest existing precedent for "is this real revenue" logic that a test-flag feature would need to sit alongside.

---

## 4. Everywhere a test flag must be respected

`is_test` already exists on `wp_dishdash_orders` and `wp_dishdash_reservations` (not on `wp_dishdash_customers`) and is already consistently checked in most — but not all — of these:

| Surface | File | `is_test` respected today? |
|---|---|---|
| Owner/manager Dashboard KPIs + Chart.js revenue data | `admin/pages/dashboard.php` | ✅ Yes — every order/reservation query filters `is_test = 0` |
| Analytics (funnel, revenue trends, customer tier counts, order-type/payment-method breakdowns, reservation stats) | `admin/pages/analytics.php` | ✅ Yes — every query checks `is_test=0` on both `dishdash_orders` and `dishdash_reservations` |
| Orders admin list | `admin/pages/orders.php` | ✅ Yes (own `is_test` toggle UI, `dd_toggle_test` AJAX action) |
| Reservations admin list | `modules/reservations/class-dd-reservations-admin.php` | ✅ Yes (own "Test" tab, `mark_test`/`unmark_test` bulk actions, and the "Awaiting Payment" exclusion logic from this session both respect it) |
| Billing page (orders + reservations sections, both KPI rows, both Monthly History tables, both Status Breakdowns) | `admin/pages/billing.php` | ✅ Yes — every query in both sections filters `is_test = 0` |
| **Customers admin page (list, stats, tier filter)** | `modules/customers/class-dd-customers-module.php` | ❌ **No `is_test` reference anywhere in this file.** Customers list/stats are computed straight from `wp_dishdash_customers` with no test-exclusion at all — confirms the gap the brief is asking about. |
| Future billing ledger (`wp_dd_billing_payments`, `dd_mark_month_paid`/`ajax_mark_month_paid`) | `modules/orders/class-dd-orders-module.php` (handler), `modules/reservations/class-dd-reservations-module.php` (`filter_billing_fees_for_month`, answers a filter so the orders module never queries the reservations table directly) | ✅ Indirectly — both fee-sum queries this hooks into already filter `is_test=0`. **This is the natural hook point for any future flag** — if a test-customer flag changes what counts as billable, it only has to change the two underlying billable-fee queries (`billing.php`'s and `filter_billing_fees_for_month()`'s), and the ledger inherits the fix automatically since it re-sums from source on every "Mark Paid" click rather than storing a stale total. |

---

## 5. Design question — flag placement

### Option A: flag on the customer (`is_test` on `wp_dishdash_customers`)
### Option B: flag on each order/reservation individually (mirrors the existing `is_test` columns)

**Recommendation: Option A, as the primary/authoritative flag — but it doesn't remove the need to also fix `DD_Customer_Manager::upsert()`, and it's a larger migration than Option B.**

Reasoning:

- **Fits the whatsapp identity model better.** The whole point of this codebase's identity system is "one whatsapp number = one customer, everywhere." A test customer (e.g., Fri Soft's own number used to test the live site) is a property of *that identity*, not of any single transaction. Option A lets staff flag it once and have it stick — Option B requires staff to remember to tick "test" on every single order and every single reservation that number ever generates, forever. Given this feature is being requested at all, the per-transaction manual discipline (Option B's existing pattern, already live on orders/reservations today) has apparently proven insufficient or error-prone enough to need a better answer.

- **Less error-prone for billing accuracy, for the same reason** — one flag, set once, can't be forgotten on the 15th test order the way a per-transaction checkbox can.

- **Real cost, found during this investigation:** none of `wp_dishdash_orders`/`wp_dishdash_reservations` have a working FK to `wp_dishdash_customers.id` (orders' `customer_id` is actually the WP user ID — see §2). Making Option A actually exclude "this customer's orders" from Dashboard/Analytics/Billing/Customers list requires a **whatsapp string join** (`orders.customer_phone = customers.whatsapp`) added to every query in §4's table — a materially bigger set of changes than Option B, which needs zero new joins (every one of those queries already has its own `is_test` column to check).

- **Neither option is complete on its own for billing accuracy** without also touching `DD_Customer_Manager::upsert()` — it increments `total_orders`/`total_spent` on `wp_dishdash_customers` unconditionally, with no test-awareness at all today (§1). Under Option A this is actually easier to close: `upsert()` could check the *existing* customer row's own `is_test` flag before incrementing (one extra `SELECT`, no new parameters needed at any of the 5 call sites). Under Option B, `upsert()` would need an `is_test` parameter threaded through all 5 call sites individually, since it currently has no visibility into the order's test status at all.

**Secondary note, not a third option:** the two aren't necessarily exclusive. The existing per-order/per-reservation `is_test` toggle already works and is already respected almost everywhere (§4) — nothing about adding a customer-level flag requires removing it. A customer-level flag could be the primary "set once" mechanism, with the per-transaction toggle remaining available for the genuinely one-off case (a real customer's one order gets marked test for some reason, without flagging their whole identity). Not recommending this hybrid as the deliverable — just noting it's compatible, since the brief asked for one recommendation, not an either/or lock-in.

---

## 6. Migration note

Confirmed: **a new `is_test` column on `wp_dishdash_customers` would need the standard `install.php` + version bump path** — but the brief's premise that "dbDelta doesn't add columns" is **incorrect for this codebase**. `CLAUDE.md` (§"What auto-migration can and can't do") explicitly documents dbDelta **can** add new columns to existing tables via the auto-migration guard in `dish-dash.php` (runs on the next admin page load after a `DD_VERSION` mismatch, updates `dd_db_version` automatically) — no manual `ALTER TABLE`/WP-CLI step needed for a straightforward new nullable/defaulted column addition like this one.

The manual-`ALTER TABLE` requirement in this codebase is reserved for **drops and renames** only (dbDelta never drops, and can't rename — those need a real migration script, same pattern as `scripts/dd-r3-migrate.php`/`scripts/dd-r15-reservation-fee-backfill.php` from this session). Adding `is_test TINYINT(1) NOT NULL DEFAULT 0` to `wp_dishdash_customers` is a pure addition, so it follows the normal path:

1. Add the column to `install.php`'s `CREATE TABLE dishdash_customers` block.
2. Bump `DD_VERSION` (both locations in `dish-dash.php`).
3. Auto-migration guard picks it up on next admin page load — no WP-CLI step.

No backfill script would be needed either, since a brand-new `is_test` column defaults to `0` for all existing rows, which is the correct value for every real customer already in the table.

---

## Observations (unrelated to the task — not fixed)

1. **`orders.customer_id` vs `reservations.customer_id` store different things.** Orders: WordPress user ID (`class-dd-orders-module.php:366`, `NULL` for every guest checkout). Reservations: `wp_dishdash_customers.id` (correct FK, via `on_resolve_customer_id()`). Same column name, two unrelated ID spaces, on sibling tables in the same product.

2. **Likely-broken metric found as a direct consequence of #1**: `admin/pages/analytics.php:109-113`, the "Returning Customers" / return-rate KPI:
   ```sql
   SELECT COUNT(DISTINCT o.customer_id) FROM dishdash_orders o
   JOIN dishdash_customers c ON c.id = o.customer_id
   WHERE o.is_test=0 AND o.created_at>=%s AND c.total_orders>1
   ```
   This joins WP user IDs against `dishdash_customers.id` — an incidental numeric coincidence at best, and `o.customer_id IS NULL` for every guest order (the majority in this market per the product's own WhatsApp-first design), so this JOIN silently drops most rows. The return-rate percentage this feeds is very likely wrong. Same root cause affects `analytics.php:106-108`'s "Total Customers" KPI (`COUNT(DISTINCT customer_id) ... customer_id IS NOT NULL`), which under-counts real unique customers by excluding every guest order instead of counting distinct `whatsapp` values.

3. **`payment_ref VARCHAR(100)` on `wp_dishdash_reservations`** — confirmed still completely unused (zero reads/writes anywhere in the codebase), flagged once already earlier this session during the MoMo proof-upload work.

4. **`wp_dishdash_orders.pesapal_tracking_id` missing from `install.php`** — already a documented Known Issue in CLAUDE.md itself (live-DB-only manual `ALTER TABLE`), re-confirmed still true, not re-investigated further here.

5. **CLAUDE.md's narrative "Current state" fields are ~10 releases stale** (see §0) — worth a housekeeping pass independent of this feature.
