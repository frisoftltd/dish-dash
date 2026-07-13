# R2 — Track Order Page: Active-Orders List — Phase 1 Confirm (READ-ONLY)

**Goal:** upgrade the existing `/track-order/` page from *one most-recent order* → a **phone-anchored
list of the logged-in user's active orders**, each row deep-linking to the existing per-order live tracker.
**Version:** 3.10.44 → 3.10.45 (Phase 2). Read-only confirm; no edits. Live version `3.10.44`
(dish-dash.php:47).

---

## 1. How `shortcode_track()` + `track.php` fetch "most-recent order" today

`DD_Orders_Module::shortcode_track()` — **orders-module.php:1645-1712** (unchanged by v3.10.44; the R1 edit
was at :110 and was line-count-neutral). Resolution order:
1. `?order_id=` (numeric) → `get_order()` (:1665-1667)
2. `?order=` (order_number) → single row by `order_number` (:1668-1674)
3. **else — the default "most recent"** (:1675-1683):
```php
$order = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}dishdash_orders
     WHERE customer_id = %d AND is_test = 0
     ORDER BY id DESC LIMIT 1",
    $uid
) );
```
Then an ownership gate (:1687-1689): non-staff whose `customer_id !== $uid` → `$order = null`. On no order →
`state = requested ? 'notfound' : 'empty'`. On an order → enqueues `order-tracking.css/js`, localizes
`ddTrackConfig` (ajaxUrl + `dish_dash_frontend` nonce), renders `track.php` with `state = 'ok'`.

**Key facts for the upgrade:**
- The default fetch is **`customer_id`-only** (no phone anchor) and **`LIMIT 1`** (single order), and is
  **not status-filtered** — a delivered/cancelled order can be the "most recent" shown.
- `templates/orders/track.php` states today: `guest` / `notfound` / `empty` / `ok`. The `ok` branch renders a
  4-step timeline (`placed`→`confirmed`→`ready`→`delivered`) from the `_at` columns. **Phase 2 needs a NEW
  state** (e.g. `list`) that renders multiple rows; the existing states/`ok` timeline stay untouched so the
  `?order_id=` per-order tracker keeps working.

## 2. R4b OR-block to copy — CONFIRMED at profile-module.php:141-149
```php
$phones = array_values( array_filter( [ (string) ( $customer->whatsapp ?? '' ) ] ) );
if ( $phones ) {
    $ph      = implode( ',', array_fill( 0, count( $phones ), '%s' ) );
    $where   = "( customer_id = %d OR customer_phone IN ($ph) )";
    $args    = array_merge( [ (int) $user_id ], $phones );
} else {
    $where   = "customer_id = %d";
    $args    = [ (int) $user_id ];
}
```
Empty-set guard present (never emits `IN ()`); phones bound as generated `%s` placeholders via
`$wpdb->prepare( …, $args )` — no concatenation. This is the exact pattern to reuse.

## 3. Canonical phone source — CONFIRMED
`DD_Customer_Manager::get_customer_for_user( $uid )` (customer-manager.php:312-319) returns `SELECT *` of the
`user_id = %d` customers row (user_id is UNIQUE → 0 or 1 row). Its **`->whatsapp`** is the canonical phone
(raw stored value). `array_filter` drops it if empty → falls back to `customer_id`-only. Same helper R4b uses,
so no parallel implementation.

## 4. Active statuses — CONFIRMED = `pending`, `confirmed`, `ready`
From `dd_order_status_transitions()` / `dd_order_status_label()` (helpers.php:112-134): the 5 statuses are
`pending, confirmed, ready, delivered, cancelled`. Terminal = `delivered`, `cancelled`. **Active/trackable =
`pending`, `confirmed`, `ready`** → `AND status IN ('pending','confirmed','ready')`.

## 5. Per-order tracker accepts `?order_id=` deep-link — CONFIRMED
orders-module.php:1665-1667: `if ( isset($_GET['order_id']) && is_numeric(...) ) $order = $this->get_order(
absint($_GET['order_id']) );`. So list rows can link to `<track-page>/?order_id=<id>` and the existing
single-order live tracker renders (ownership-gated; live poll works for `customer_id`-owned orders — the
accepted v1 limitation for phone-only orders is unchanged, no `ajax_get_order()` change).

---

## ⚠️ One scope flag for Phase 2 — the sidebar item is NOT a clean one-liner

The brief says "add 'Track Order' via `DD_Profile_Module::add_menu_item()` (one-line data append)". Confirmed
the insertion point (`add_menu_item()`, profile-module.php:81-98, rebuilds the menu array). **But the track
page is a standalone WP page (`/track-order/`, slug `track-order`, `[dish_dash_track]`), NOT a WooCommerce
account endpoint.** WooCommerce renders each account-menu item's href via
`wc_get_account_endpoint_url( $key )`, so a bare `$clean['track-order'] = 'Track Order'` would link to
**`/my-account/track-order/`** — a non-existent endpoint that falls back to the account dashboard, not the
`/track-order/` page. (Contrast: `my-profile` works because it is registered as a full WC endpoint —
`add_rewrite_endpoint` + `query_vars` + `woocommerce_get_query_vars` + flush; see profile-module.php:26-74.)

**Two viable Phase-2 wirings (pick at "proceed"):**
- **Option S1 — href remap (lightest, keeps the standalone page):** append the menu item AND add a
  `woocommerce_get_endpoint_url` (or `woocommerce_get_account_menu_item_classes`) href filter that maps the
  `track-order` key to `get_permalink( get_option('dish_dash_track_page_id') )`. ~2 small hooks, no rewrite
  flush, no new render surface. Recommended for a snapshot v1.
- **Option S2 — real WC endpoint (mirrors my-profile):** register `track-order` as a WC account endpoint
  whose callback echoes the active-orders list. Heavier (rewrite endpoint + 3 filters + one-time flush) and
  creates a second surface (`/my-account/track-order/` alongside `/track-order/`).

**Recommendation:** S1 — smallest change, honors "upgrade the existing `/track-order/` page", no flush risk.
The append is still ~1 line in `add_menu_item()`; the href correctness costs one extra filter. Flagging so
Phase 2 doesn't ship a menu item that 404s/bounces to the dashboard.

---

## Phase 2 plan (for reference — build on "proceed")
1. `shortcode_track()`: when no `?order_id=`/`?order=` is given, build the phone-anchored **active-orders
   list** (R4b OR-block + `is_test = 0` + `status IN ('pending','confirmed','ready')`, `ORDER BY id DESC`)
   and pass `state = 'list'` (or `'empty'` if none). Keep branches 1 & 2 (`?order_id=`/`?order=`) exactly as
   today so the per-order live tracker is untouched.
2. `track.php`: add a `list` branch (rows: order number · status label · time; each an `<a>` to
   `?order_id=<id>`) + a clean "No active orders" empty state. Leave `guest`/`notfound`/`ok` intact.
3. Sidebar: `add_menu_item()` append + the S1 href filter (per decision above).
4. Bump 3.10.45 (dish-dash.php ×2 + CLAUDE.md), commit, push, `report.md`. No release.

**Out of scope (confirmed):** no guest tracking, no `ajax_get_order()` gate change, no `customer_id`
writes / R4c.

*No files were modified. Read-only confirm. Awaiting "proceed" for Phase 2.*
