
# Dish Dash — Installer Consolidation Audit

**Audit date:** 2026-06-02
**Audited by:** Claude Code (investigative pass — no edits made)
**Policy:** Keep all live DB tables and columns — never drop what is live
**Rule:** Live DB wins on every divergence — production schema is authoritative

---

## 1. Table Inventory

All 13 custom DB tables referenced across both installer files. "Active" means the root installer creates this table on plugin activation. "Missing" means the table is absent from that file.

| # | Table | install.php (root) | class-dd-install.php (core) | uninstall.php |
|---|---|---|---|---|
| 1 | dishdash_branches | Active (diverges) | Present (diverges) | DROP |
| 2 | dishdash_orders | Active (diverges) | Present (extended) | DROP |
| 3 | dishdash_order_items | Active | Present (matches) | DROP |
| 4 | dishdash_delivery_zones | Active (diverges) | Present (diverges) | DROP |
| 5 | dishdash_tables | Active (diverges) | Present (diff schema) | DROP |
| 6 | dishdash_reservations | Active (diverges) | Present (diff schema) | DROP |
| 7 | dishdash_pos_sessions | Active | Missing | DROP |
| 8 | dishdash_analytics | Active (diverges) | Present (diverges) | Missing |
| 9 | dishdash_user_events | Active | Missing | Missing |
| 10 | dishdash_user_profiles | Active | Missing | Missing |
| 11 | dishdash_customers | Missing | Present | Missing |
| 12 | dishdash_birthday_tokens | Missing | Present | Missing |
| 13 | dishdash_reservation_refunds | Missing | Present | Missing |

**Summary:** root install.php creates 10 tables on activation; class-dd-install.php creates a different 10 tables; uninstall.php drops only 8. Five tables are orphaned on plugin deletion.

---

## 2. Active Installer

`dish-dash.php` lines 186–187 call:

    require_once DD_PLUGIN_DIR . 'install.php';
    DD_Install::run();

`install.php` (root) is therefore the active installer on every plugin activation. `dishdash-core/class-dd-install.php` is also referenced by the autoloader (noted in `dish-dash.php` header line 28). Both files define `class DD_Install` — see Section 3.

---

## 3. Class Name Conflict

| File | Entry method | Called from |
|---|---|---|
| install.php (root) | DD_Install::run() | dish-dash.php activation hook (lines 186–187) |
| dishdash-core/class-dd-install.php | DD_Install::activate() | Autoloader |

**Risk:** if the autoloader loads the core file before the activation hook fires `require_once install.php`, PHP will throw a fatal on the second class definition. The site is live without fatals — the `require_once` guard is winning — but this is fragile and could break on any change to the load order.

---

## 4. Tables Defined Only in root install.php

These tables are created on activation but have no definition in class-dd-install.php. If the core file ever becomes primary, these tables will not be created for new installs.

### 4.1 dishdash_pos_sessions

Columns: id, branch_id, cashier_id, cash_float, total_cash, total_card, total_orders, opened_at, closed_at, notes
Keys: PRIMARY (id), KEY branch_id, KEY cashier_id

### 4.2 dishdash_user_events

Columns: id, user_id, session_id, event_type, product_id, category_id, meta (JSON), schema_version, created_at
Keys: PRIMARY (id), KEY per column, compound KEY idx_event_type_schema (event_type, schema_version)
Note: AI Foundation table — feeds the Phase 6 behavior engine.

### 4.3 dishdash_user_profiles

Columns: id, user_id, session_id, favorite_items, favorite_categories, avg_order_value, order_count, order_times, last_orders, last_seen, updated_at
Keys: PRIMARY (id), UNIQUE KEY user_id, KEY session_id, KEY updated_at
Note: one row per user (logged-in) or session (guest).

---

## 5. Tables Defined Only in class-dd-install.php

These tables are used by live modules but are absent from the active installer. A fresh install from the current codebase would not create them.

### 5.1 dishdash_customers

Columns: id, whatsapp (UNIQUE), name, delivery_address, birthday, dd_birthday_asked, total_orders, total_spent, first_order_at, last_order_at, created_at, updated_at
Identity model: WhatsApp number is the primary customer identifier.

### 5.2 dishdash_birthday_tokens

Columns: id, token (VARCHAR 64, UNIQUE), customer_id, used, expires_at, created_at

### 5.3 dishdash_reservation_refunds

Columns: id, reservation_id, amount, reason, refunded_at, created_at
Keys: PRIMARY (id), KEY reservation_id

---

## 6. Schema Divergences

Tables defined in both files with differing columns or types.

### 6.1 dishdash_orders

The core file adds 6 columns and 1 index absent in root install.php:

| Column or Index | install.php (root) | class-dd-install.php (core) |
|---|---|---|
| order_type | VARCHAR(20) | ENUM('delivery','pickup','dine-in','pos') |
| confirmed_at | Missing | DATETIME NULL |
| ready_at | Missing | DATETIME NULL |
| delivered_at | Missing | DATETIME NULL |
| cancelled_at | Missing | DATETIME NULL |
| is_test | Missing | TINYINT(1) NOT NULL DEFAULT 0 |
| platform_fee | Missing | INT UNSIGNED NOT NULL DEFAULT 0 |
| KEY branch_status | Missing | KEY branch_status (branch_id, status) |

These columns were added in released versions (is_test in v3.4.64, timestamp columns in v3.4.76, platform_fee in v3.4.91). They exist on the live DB. Root install.php is out of date for fresh installs.

### 6.2 dishdash_tables

Completely different designs with no shared columns beyond id, name, and capacity:

| Column | install.php (root) | class-dd-install.php (core) |
|---|---|---|
| id | BIGINT UNSIGNED | INT UNSIGNED |
| branch_id | BIGINT UNSIGNED NOT NULL | Missing |
| qr_code | VARCHAR(255) DEFAULT NULL | Missing |
| status | VARCHAR(20) DEFAULT 'available' | Missing |
| section | Missing | VARCHAR(20) DEFAULT 'indoor' |
| is_active | Missing | TINYINT(1) DEFAULT 1 |
| sort_order | Missing | SMALLINT DEFAULT 0 |
| created_at | Missing | DATETIME NOT NULL |

Live DB check required before resolving — see Q1.

### 6.3 dishdash_reservations

Completely different identity models:

| Column | install.php (root) | class-dd-install.php (core) |
|---|---|---|
| booking_ref | Missing | VARCHAR(20) NOT NULL UNIQUE |
| customer_id | Missing | BIGINT UNSIGNED NULL |
| branch_id | BIGINT UNSIGNED NOT NULL | Missing |
| customer_name | VARCHAR(255) NOT NULL | Missing (uses 'name') |
| customer_phone | VARCHAR(50) NOT NULL | Missing (uses 'whatsapp') |
| customer_email | VARCHAR(255) NOT NULL | Missing |
| party_size | INT UNSIGNED NOT NULL | Missing (uses 'guests') |
| reservation_date | DATE NOT NULL | Missing (uses 'date') |
| reservation_time | TIME NOT NULL | Missing (uses 'time VARCHAR(5)') |
| session | Missing | VARCHAR(10) NOT NULL DEFAULT '' |
| whatsapp | Missing | VARCHAR(30) NOT NULL DEFAULT '' |
| special_requests | Missing (uses 'notes') | TEXT NULL |
| deposit_required | Missing | TINYINT(1) NOT NULL DEFAULT 0 |
| deposit_amount | Missing | INT UNSIGNED NOT NULL DEFAULT 0 |
| deposit_status | Missing | VARCHAR(20) NOT NULL DEFAULT 'none' |
| deposit_paid_at | Missing | DATETIME NULL |
| payment_ref | Missing | VARCHAR(100) NULL |
| source | Missing | VARCHAR(30) NOT NULL DEFAULT '' |
| updated_at | Missing | DATETIME ON UPDATE CURRENT_TIMESTAMP |

The live reservation system uses WhatsApp-based identity with booking_ref and deposit fields (core schema). Core schema is authoritative — see Q2.

### 6.4 dishdash_branches

| Column | install.php (root) | class-dd-install.php (core) |
|---|---|---|
| opening_hours | JSON | LONGTEXT DEFAULT NULL |
| settings | JSON | LONGTEXT DEFAULT NULL |
| updated_at | DATETIME ON UPDATE CURRENT_TIMESTAMP | Missing |
| latitude / longitude | NOT NULL DEFAULT '0.000...' | DEFAULT NULL |

### 6.5 dishdash_delivery_zones

| Column | install.php (root) | class-dd-install.php (core) |
|---|---|---|
| zone_data | JSON NOT NULL | LONGTEXT NOT NULL DEFAULT '' |
| zone_type | VARCHAR(20) NOT NULL DEFAULT 'radius' | ENUM('radius','polygon','zipcode') |

### 6.6 dishdash_analytics

| Column | install.php (root) | class-dd-install.php (core) |
|---|---|---|
| top_items | JSON DEFAULT NULL | LONGTEXT DEFAULT NULL |
| order_types | JSON DEFAULT NULL | Missing |
| data | Missing | LONGTEXT DEFAULT NULL |
| created_at | DATETIME NOT NULL | Missing |

---

## 7. Canonical Schema Reference

Recommended consolidated CREATE TABLE statements for all 13 tables. For divergent tables, the merged schema includes every column from both files — nothing is dropped. `{prefix}` = `$wpdb->prefix`, `{charset}` = `$wpdb->get_charset_collate()`.

Live DB verification (Q1–Q4) is required before finalising the divergent table schemas. All statements use LONGTEXT in place of JSON for wider MySQL compatibility (5.6+).

```sql
-- 1. dishdash_branches
CREATE TABLE {prefix}dishdash_branches (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(255)    NOT NULL,
    slug          VARCHAR(100)    NOT NULL,
    address       TEXT            NOT NULL DEFAULT '',
    latitude      DECIMAL(10,8)            DEFAULT NULL,
    longitude     DECIMAL(11,8)            DEFAULT NULL,
    phone         VARCHAR(50)     NOT NULL DEFAULT '',
    email         VARCHAR(255)    NOT NULL DEFAULT '',
    opening_hours LONGTEXT                 DEFAULT NULL,
    min_order     DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    settings      LONGTEXT                 DEFAULT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY  slug (slug),
    KEY         is_active (is_active)
) {charset};
```

```sql
-- 2. dishdash_orders
CREATE TABLE {prefix}dishdash_orders (
    id                   BIGINT UNSIGNED      NOT NULL AUTO_INCREMENT,
    order_number         VARCHAR(50)          NOT NULL,
    wc_order_id          BIGINT UNSIGNED               DEFAULT NULL,
    branch_id            BIGINT UNSIGNED      NOT NULL DEFAULT 1,
    customer_id          BIGINT UNSIGNED               DEFAULT NULL,
    customer_name        VARCHAR(255)         NOT NULL DEFAULT '',
    customer_phone       VARCHAR(50)          NOT NULL DEFAULT '',
    customer_email       VARCHAR(255)         NOT NULL DEFAULT '',
    order_type           ENUM('delivery','pickup','dine-in','pos') NOT NULL DEFAULT 'delivery',
    status               VARCHAR(50)          NOT NULL DEFAULT 'pending',
    subtotal             DECIMAL(10,2)        NOT NULL DEFAULT '0.00',
    delivery_fee         DECIMAL(10,2)        NOT NULL DEFAULT '0.00',
    discount             DECIMAL(10,2)        NOT NULL DEFAULT '0.00',
    tip                  DECIMAL(10,2)        NOT NULL DEFAULT '0.00',
    tax                  DECIMAL(10,2)        NOT NULL DEFAULT '0.00',
    total                DECIMAL(10,2)        NOT NULL DEFAULT '0.00',
    payment_method       VARCHAR(100)         NOT NULL DEFAULT '',
    payment_status       VARCHAR(50)          NOT NULL DEFAULT 'unpaid',
    scheduled_at         DATETIME                      DEFAULT NULL,
    delivery_address     TEXT                          DEFAULT NULL,
    special_instructions TEXT                          DEFAULT NULL,
    pos_session_id       BIGINT                        DEFAULT NULL,
    table_id             BIGINT                        DEFAULT NULL,
    created_at           DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    confirmed_at         DATETIME                      NULL,
    ready_at             DATETIME                      NULL,
    delivered_at         DATETIME                      NULL,
    cancelled_at         DATETIME                      NULL,
    is_test              TINYINT(1)           NOT NULL DEFAULT 0,
    platform_fee         INT UNSIGNED         NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY  order_number (order_number),
    KEY         branch_id (branch_id),
    KEY         customer_id (customer_id),
    KEY         status (status),
    KEY         created_at (created_at),
    KEY         is_test (is_test),
    KEY         branch_status (branch_id, status)
) {charset};
```

```sql
-- 3. dishdash_order_items
CREATE TABLE {prefix}dishdash_order_items (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id     BIGINT UNSIGNED NOT NULL,
    menu_item_id BIGINT UNSIGNED NOT NULL,
    item_name    VARCHAR(255)    NOT NULL DEFAULT '',
    quantity     INT UNSIGNED    NOT NULL DEFAULT 1,
    unit_price   DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
    addons       LONGTEXT                 DEFAULT NULL,
    variation    VARCHAR(100)             DEFAULT NULL,
    special_note TEXT                     DEFAULT NULL,
    line_total   DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
    PRIMARY KEY (id),
    KEY         order_id (order_id),
    KEY         menu_item_id (menu_item_id)
) {charset};
```

```sql
-- 4. dishdash_delivery_zones
CREATE TABLE {prefix}dishdash_delivery_zones (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id      BIGINT UNSIGNED NOT NULL,
    name           VARCHAR(255)    NOT NULL DEFAULT '',
    zone_type      VARCHAR(20)     NOT NULL DEFAULT 'radius',
    zone_data      LONGTEXT        NOT NULL,
    delivery_fee   DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
    min_order      DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
    estimated_time INT UNSIGNED    NOT NULL DEFAULT 30,
    is_active      TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY         branch_id (branch_id),
    KEY         is_active (is_active)
) {charset};
```

```sql
-- 5. dishdash_tables  [PENDING live DB verification -- see Q1]
-- Merged schema includes columns from both definitions.
-- Run DESCRIBE wp_dishdash_tables before finalising.
CREATE TABLE {prefix}dishdash_tables (
    id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    branch_id  BIGINT UNSIGNED          DEFAULT NULL,
    name       VARCHAR(100)    NOT NULL DEFAULT '',
    capacity   INT UNSIGNED    NOT NULL DEFAULT 2,
    section    VARCHAR(20)     NOT NULL DEFAULT 'indoor',
    qr_code    VARCHAR(255)             DEFAULT NULL,
    status     VARCHAR(20)     NOT NULL DEFAULT 'available',
    is_active  TINYINT(1)      NOT NULL DEFAULT 1,
    sort_order SMALLINT        NOT NULL DEFAULT 0,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY         branch_id (branch_id)
) {charset};
```

```sql
-- 6. dishdash_reservations  [PENDING live DB verification -- see Q2]
-- Core schema is authoritative: WhatsApp identity, booking_ref, deposits.
CREATE TABLE {prefix}dishdash_reservations (
    id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    booking_ref      VARCHAR(20)      NOT NULL DEFAULT '',
    customer_id      BIGINT UNSIGNED           NULL,
    date             DATE             NOT NULL,
    time             VARCHAR(5)       NOT NULL DEFAULT '',
    session          VARCHAR(10)      NOT NULL DEFAULT '',
    guests           TINYINT UNSIGNED NOT NULL DEFAULT 1,
    table_id         INT UNSIGNED              NULL,
    name             VARCHAR(100)     NOT NULL DEFAULT '',
    whatsapp         VARCHAR(30)      NOT NULL DEFAULT '',
    special_requests TEXT                      NULL,
    status           VARCHAR(20)      NOT NULL DEFAULT 'pending',
    deposit_required TINYINT(1)       NOT NULL DEFAULT 0,
    deposit_amount   INT UNSIGNED     NOT NULL DEFAULT 0,
    deposit_status   VARCHAR(20)      NOT NULL DEFAULT 'none',
    deposit_paid_at  DATETIME                  NULL,
    payment_ref      VARCHAR(100)              NULL,
    source           VARCHAR(30)      NOT NULL DEFAULT '',
    created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY  booking_ref (booking_ref),
    KEY         customer_id (customer_id),
    KEY         date_session (date, session),
    KEY         status (status)
) {charset};
```

```sql
-- 7. dishdash_pos_sessions
CREATE TABLE {prefix}dishdash_pos_sessions (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id    BIGINT UNSIGNED NOT NULL,
    cashier_id   BIGINT UNSIGNED NOT NULL,
    cash_float   DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
    total_cash   DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
    total_card   DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
    total_orders INT UNSIGNED    NOT NULL DEFAULT 0,
    opened_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at    DATETIME                 DEFAULT NULL,
    notes        TEXT                     DEFAULT NULL,
    PRIMARY KEY (id),
    KEY         branch_id (branch_id),
    KEY         cashier_id (cashier_id)
) {charset};
```

```sql
-- 8. dishdash_analytics
CREATE TABLE {prefix}dishdash_analytics (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id       BIGINT UNSIGNED NOT NULL,
    stat_date       DATE            NOT NULL,
    orders_count    INT UNSIGNED    NOT NULL DEFAULT 0,
    revenue         DECIMAL(12,2)   NOT NULL DEFAULT '0.00',
    avg_order_value DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
    top_items       LONGTEXT                 DEFAULT NULL,
    order_types     LONGTEXT                 DEFAULT NULL,
    data            LONGTEXT                 DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY  branch_date (branch_id, stat_date),
    KEY         stat_date (stat_date)
) {charset};
```

```sql
-- 9. dishdash_user_events
CREATE TABLE {prefix}dishdash_user_events (
    id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED            DEFAULT NULL,
    session_id     VARCHAR(64)       NOT NULL DEFAULT '',
    event_type     VARCHAR(50)       NOT NULL DEFAULT '',
    product_id     BIGINT UNSIGNED            DEFAULT NULL,
    category_id    BIGINT UNSIGNED            DEFAULT NULL,
    meta           LONGTEXT                   DEFAULT NULL,
    schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY         user_id (user_id),
    KEY         session_id (session_id),
    KEY         event_type (event_type),
    KEY         product_id (product_id),
    KEY         category_id (category_id),
    KEY         created_at (created_at),
    KEY         idx_event_type_schema (event_type, schema_version)
) {charset};
```

```sql
-- 10. dishdash_user_profiles
CREATE TABLE {prefix}dishdash_user_profiles (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED          DEFAULT NULL,
    session_id          VARCHAR(64)     NOT NULL DEFAULT '',
    favorite_items      LONGTEXT                 DEFAULT NULL,
    favorite_categories LONGTEXT                 DEFAULT NULL,
    avg_order_value     DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
    order_count         INT UNSIGNED    NOT NULL DEFAULT 0,
    order_times         LONGTEXT                 DEFAULT NULL,
    last_orders         LONGTEXT                 DEFAULT NULL,
    last_seen           DATETIME                 DEFAULT NULL,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY  user_id (user_id),
    KEY         session_id (session_id),
    KEY         updated_at (updated_at)
) {charset};
```

```sql
-- 11. dishdash_customers
CREATE TABLE {prefix}dishdash_customers (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    whatsapp          VARCHAR(20)     NOT NULL DEFAULT '',
    name              VARCHAR(255)    NOT NULL DEFAULT '',
    delivery_address  TEXT                     NULL,
    birthday          DATE                     NULL,
    dd_birthday_asked TINYINT(1)      NOT NULL DEFAULT 0,
    total_orders      INT UNSIGNED    NOT NULL DEFAULT 0,
    total_spent       DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
    first_order_at    DATETIME                 NULL,
    last_order_at     DATETIME                 NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY  whatsapp (whatsapp)
) {charset};
```

```sql
-- 12. dishdash_birthday_tokens
CREATE TABLE {prefix}dishdash_birthday_tokens (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    token       VARCHAR(64)     NOT NULL DEFAULT '',
    customer_id BIGINT UNSIGNED NOT NULL,
    used        TINYINT(1)      NOT NULL DEFAULT 0,
    expires_at  DATETIME        NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY  token (token),
    KEY         customer_id (customer_id)
) {charset};
```

```sql
-- 13. dishdash_reservation_refunds
CREATE TABLE {prefix}dishdash_reservation_refunds (
    id             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    reservation_id BIGINT UNSIGNED NOT NULL,
    amount         INT UNSIGNED    NOT NULL DEFAULT 0,
    reason         VARCHAR(255)    NOT NULL DEFAULT '',
    refunded_at    DATETIME                 NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY         reservation_id (reservation_id)
) {charset};
```

---

## 8. Recommendations

### R1 — Resolve the class name conflict

Both `install.php` (root) and `dishdash-core/class-dd-install.php` define `class DD_Install`. Rename the core file's class to `DD_Schema_Upgrader` (or similar). One class name, one file.

### R2 — Designate root install.php as the sole canonical installer

It is already called on activation. All 13 table definitions must live here. The core file should become an upgrade/migration helper only and no longer define `DD_Install`.

### R3 — Verify live DB schema before any edits

Before modifying any file, run `DESCRIBE wp_dishdash_tables`, `DESCRIBE wp_dishdash_reservations`, and `SHOW TABLES LIKE 'wp_dishdash_%'` on the live server. Live DB is authoritative. Do not infer schema from code alone.

### R4 — Add dishdash_customers to root install.php

Used by active modules (customers, orders). Absent from the active installer. Fresh installs will not create this table.

### R5 — Add dishdash_birthday_tokens to root install.php

Linked to dishdash_customers via customer_id. Must be created alongside it (R4).

### R6 — Add dishdash_reservation_refunds to root install.php

Used by the reservations module. Absent from the active installer.

### R7 — Patch dishdash_orders: add the 6 missing columns

Add `confirmed_at`, `ready_at`, `delivered_at`, `cancelled_at`, `is_test`, `platform_fee` to the CREATE TABLE in root `install.php`. These are live columns shipped in v3.4.64, v3.4.76, and v3.4.91. Fresh installs currently miss all six.

### R8 — Patch dishdash_orders: add KEY branch_status

Add `KEY branch_status (branch_id, status)` to the root `install.php` definition. This compound index improves order queue query performance and exists in the core file.

### R9 — Resolve dishdash_tables divergence after live DB check

After running `DESCRIBE wp_dishdash_tables` (Q1), write the final merged schema that includes every column present on the live server. Use Section 7 statement 5 as the starting point. Never drop a column.

### R10 — Resolve dishdash_reservations divergence — core schema wins

The live reservation system uses WhatsApp identity, `booking_ref`, and deposit fields (core schema). Replace the root schema with the core schema in root `install.php`. Verify no live column is omitted before merging.

### R11 — Resolve dishdash_branches minor divergence

Add `updated_at` to the root `install.php` definition. Normalise `latitude`/`longitude` to `DEFAULT NULL`. Use LONGTEXT for `opening_hours` and `settings` (wider MySQL compatibility than JSON type).

### R12 — Resolve dishdash_delivery_zones and dishdash_analytics minor divergences

For delivery_zones: use LONGTEXT for `zone_data`, VARCHAR(20) for `zone_type` (less brittle than ENUM for future zone types).
For analytics: add both `order_types` and `data` columns to the root definition; add `created_at`.

### R13 — Update uninstall.php to DROP all 13 tables

Currently drops only 8. Add to the DROP list: `dishdash_user_events`, `dishdash_user_profiles`, `dishdash_customers`, `dishdash_birthday_tokens`, `dishdash_reservation_refunds`. The no-drop policy applies to live upgrades only; uninstall is opt-in (`dish_dash_remove_data_on_uninstall = '1'`) and intentional.

### R14 — Archive dishdash-core/class-dd-install.php after consolidation

Once all 13 tables are confirmed correct in root `install.php` and on the live server, retire the core file. Do not delete until all logic it contains has been verified as replicated or obsolete. Update the autoloader reference at `dish-dash.php` line 28.

---

## 9. Open Questions

> Q5–Q7 were resolved by the 'no tables dropped' policy adopted 2026-06-02. Numbering preserved for traceability.

| # | Question | Status |
|---|---|---|
| Q1 | What is the exact live schema of dishdash_tables? Has the live DB diverged from both installer definitions? | Open — run DESCRIBE wp_dishdash_tables on server |
| Q2 | What is the exact live schema of dishdash_reservations? Does it have branch_id (root) or booking_ref (core)? | Open — run DESCRIBE wp_dishdash_reservations on server |
| Q3 | Have dishdash_customers, dishdash_birthday_tokens, and dishdash_reservation_refunds been created on the live DB? | Open — run SHOW TABLES LIKE 'wp_dishdash_%' on server |
| Q4 | Has the class name conflict (Section 3) ever caused a PHP fatal on a fresh activation? | Open — test on a clean WP install |
| Q5 | Should root install.php be deleted once consolidation is complete? | Resolved — no. It stays as the canonical installer, promoted to contain all 13 tables. |
| Q6 | Should divergent columns be dropped from the live DB for cleanliness? | Resolved — no. Live DB wins. Drop nothing. |
| Q7 | Can dishdash-core/class-dd-install.php be deleted after consolidation? | Resolved — only after all logic is confirmed replicated elsewhere. Archive, do not blindly delete. |

---

*Produced 2026-06-02 — investigative static analysis of `install.php`, `dishdash-core/class-dd-install.php`, and `uninstall.php`. No files were edited during this pass. All findings reflect code state at v3.4.91.*
