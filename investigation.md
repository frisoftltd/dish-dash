# Order Tracking — Phase 1 Investigation (READ-ONLY)

**Feature:** Dedicated "Track Order" page for logged-in customers showing live status of their
**active** orders. Guest tracking deferred. Also: diagnose the `[dish_dash_account]`
double-registration.

**Live version:** `3.10.43` (dish-dash.php:47). Read-only — no edits, no version bump, no release.
Claude Code has NO live DB access → live-data checks marked **[RUN ON SERVER]**.

---

## TL;DR (the two scope-shaping facts)

1. **A Track page already exists and is FUNCTIONAL — not a stub.** `install.php` auto-creates
   `/track-order/` with `[dish_dash_track]`, and `DD_Orders_Module::shortcode_track()` +
   `templates/orders/track.php` render a **live, polling single-order status timeline** (built v3.10.30).
   The work is **upgrading** it from *one most-recent order* → *a list of active orders* (phone-anchored,
   active-status-filtered) — **not greenfield**.

2. **The double-registration is decoupled from the tracker → two releases.** `[dish_dash_account]` is
   registered by two modules; the tracker uses `[dish_dash_track]` (already sole-owned since v3.10.30) and
   never touches the account registration. **R1 = delete the dead account twin (isolated, small);
   R2 = the active-orders tracker upgrade.**

---

## Task 1 — Order status model

### The complete status set (5)
Plain **string values** in `wp_dishdash_orders.status` (VARCHAR; no PHP enum/constant class). The
canonical set is defined by two helpers in `dishdash-core/class-dd-helpers.php`:

| Status | Label (helpers.php:125-134) | Timestamp column | Role |
|---|---|---|---|
| `pending` | Pending | *(none — `created_at` = placed)* | **active** |
| `confirmed` | Confirmed | `confirmed_at` | **active** |
| `ready` | Ready | `ready_at` | **active** |
| `delivered` | Delivered | `delivered_at` | **terminal** |
| `cancelled` | Cancelled | `cancelled_at` | **terminal** |

There is **no `preparing` / `out_for_delivery`** status — the earlier brief's guess doesn't match the
code. Five statuses only.

### Lifecycle (transition map — helpers.php:112-119)
```php
'pending'   => [ 'confirmed', 'cancelled' ],
'confirmed' => [ 'ready',     'cancelled' ],
'ready'     => [ 'delivered', 'cancelled' ],
'delivered' => [ 'ready' ],       // reverse (reopen)
'cancelled' => [ 'pending' ],     // reverse (reopen)
```
Forward path: **placed(pending) → confirmed → ready → delivered**. `cancelled` reachable from
pending/confirmed/ready. Two reverse (reopen) transitions exist for staff correction.

- **Active / trackable (in-progress):** `pending`, `confirmed`, `ready`
- **Terminal:** `delivered`, `cancelled`

### How status is updated + timestamps → **timeline IS possible**
`DD_Orders_Module::update_status()` (orders-module.php:537-586):
- Validates the transition against the map (rejects illegal jumps).
- **Stamps a dedicated per-status DATETIME column** (`confirmed_at` / `ready_at` / `delivered_at` /
  `cancelled_at`) on each change — so each milestone has its own timestamp.
- Fires `do_action('dish_dash_order_status_changed', …)` and (on delivered) `dish_dash_order_delivered`,
  then `send_status_update()` to notify the customer.
- **`created_at` is the "Placed" stamp** — there is no `pending_at` column (order exists = placed).

Callers: admin order modal (status-change AJAX), gateway/thank-you flows create the order. Because every
non-pending milestone has its own column, `track.php` already renders a **4-step timeline** (Placed →
Confirmed → Ready → Delivered) with real times — not just current state. **[RUN ON SERVER]** to confirm
the live status vocabulary matches:
```sql
SELECT status, COUNT(*) FROM wp_dishdash_orders GROUP BY status ORDER BY 2 DESC;
```
Schema confirmed in `install.php:99-139` (all four `_at` columns + `is_test` + `KEY customer_id`).

---

## Task 2 — Active-orders query for a logged-in user

**Reuse the R4b pattern.** The canonical phone set comes from
`DD_Customer_Manager::get_customer_for_user( $uid )` (customer-manager.php:312-319, `SELECT *`) → use its
`->whatsapp` (raw stored value, not re-normalized). The exact PHP assembly to copy is already in
`profile-module.php:141-149`:

```php
$customer = DD_Customer_Manager::get_customer_for_user( $uid );
$phones = array_values( array_filter( [ (string) ( $customer->whatsapp ?? '' ) ] ) );
if ( $phones ) {
    $ph    = implode( ',', array_fill( 0, count( $phones ), '%s' ) );
    $where = "( customer_id = %d OR customer_phone IN ($ph) )";
    $args  = array_merge( [ (int) $uid ], $phones );
} else {
    $where = "customer_id = %d";
    $args  = [ (int) $uid ];
}
// active-orders query:
"SELECT * FROM {$wpdb->prefix}dishdash_orders
 WHERE {$where} AND is_test = 0
   AND status IN ('pending','confirmed','ready')
 ORDER BY id DESC"
```

**Neither existing helper does this today** — both are `customer_id`-only:
- `shortcode_track()` default fetch (orders-module.php:1677-1682): `WHERE customer_id = %d AND is_test = 0
  ORDER BY id DESC LIMIT 1` — single order, **no phone anchor, no active-status filter** (returns the most
  recent order even if delivered/cancelled).
- `get_orders()` helper (orders-module.php:502-531): supports `status`/`customer_id` args but **no phone
  OR** and only a single exact `status`, not a set.

So the active-orders list query is genuinely new; model it on R4b, don't reuse `get_orders()` as-is.
**[RUN ON SERVER]** sanity check for a sample user (replace 14 / phone with real values):
```sql
SELECT id, order_number, status, created_at FROM wp_dishdash_orders
WHERE ( customer_id = 14 OR customer_phone = '+250785553103' )
  AND is_test = 0 AND status IN ('pending','confirmed','ready')
ORDER BY id DESC;
```

---

## Task 3 — Page/route infrastructure + current Track-page state

### How the six customer pages are created & routed
`DD_Schema_Upgrader::create_pages()` (install.php:591-650) auto-creates plain **WP pages** (each holding
one shortcode) and stores each page ID in an option. Idempotent: skips if the option is set, else adopts an
existing same-slug page, else `wp_insert_post`.

| Option key | Slug | Shortcode |
|---|---|---|
| `dish_dash_menu_page_id` | `restaurant-menu` | `[dish_dash_menu]` |
| `dish_dash_cart_page_id` | `cart-dd` | `[dish_dash_cart]` |
| `dish_dash_checkout_page_id` | `checkout-dd` | `[dish_dash_checkout]` |
| **`dish_dash_track_page_id`** | **`track-order`** | **`[dish_dash_track]`** |
| `dish_dash_account_page_id` | `my-restaurant-account` | `[dish_dash_account]` |
| `dish_dash_reserve_page_id` | `reserve-table` | `[dish_dash_reserve]` |

Routing = normal WP page + shortcode (NOT WC endpoints). Exception: the **My Profile / Order History**
experience lives on `/my-account/` as WooCommerce account endpoints (DD_Profile_Module), separate from the
standalone `[dish_dash_account]` page. `dishdash-core/class-dd-helpers.php:178` reads
`dish_dash_track_page_id` (a helper resolves the track page URL).

### Current state of the Track page → **FUNCTIONAL, single-order**
`shortcode_track()` (orders-module.php:1645-1712) + `templates/orders/track.php`:
- **Logged-in only** (guests get a "please log in" state). Four states: `guest` / `notfound` / `empty` / `ok`.
- Resolves the order: `?order_id=` (numeric) → `?order=` (order_number) → else **most-recent own order**
  (`customer_id = %d`, LIMIT 1).
- Ownership-gated (staff read any; customer reads own only; failed gate == "not found", no existence leak).
- On `ok`, enqueues `order-tracking.css/js` + localizes `ddTrackConfig` (ajaxUrl + `dish_dash_frontend`
  nonce). The template renders a **4-step timeline** from the `_at` columns; `order-tracking.js` **polls
  `dd_get_order` every 30s and re-renders until terminal**.

**Gap vs. this brief:** it tracks **one** order (most-recent by `customer_id`, or an explicitly requested
one), **not a list of active orders**, and the default fetch isn't status-filtered (a delivered order shows)
nor phone-anchored (guest-placed orders under the user's phone won't appear by default). So v1 = **upgrade
the existing functional page** to a phone-anchored, active-status list — reusing the timeline template and
the existing per-order polling for drill-in.

---

## Task 4 — `[dish_dash_account]` double-registration

### The two registrations (exact file:line)
```
modules/menu/class-dd-menu-module.php:54    add_shortcode( 'dish_dash_account', [ $this, 'shortcode_account' ] );
modules/orders/class-dd-orders-module.php:110  add_shortcode( 'dish_dash_account', [ $this, 'shortcode_account' ] );
```
Two **different** handlers:
- **Menu** (`shortcode_account`, menu-module.php:459-467): outputs `woocommerce_account_content()` — the
  real WC account UI.
- **Orders** (`shortcode_account`, orders-module.php:1714-1720): outputs a custom `orders/account.php` list
  of the user's 10 most recent orders (`get_orders([customer_id, limit:10])`).

### Cause & symptom — a silent shadow, not doubled output
`add_shortcode()` with the same tag **overwrites** the prior callback (WordPress keeps one callback per tag
in `$shortcode_tags`). Load order (loader.php:68-106, foreach over the array): **Menu (`:84`) inits before
Orders (`:86`)**, so **Orders registers last and WINS**. Result:
- `[dish_dash_account]` (the `/my-restaurant-account/` page) currently renders the **DD custom orders
  list**, and the **menu-module's WooCommerce-account version is dead/unreachable code**.
- **No doubled UI, no PHP notice** — `add_shortcode` doesn't warn on override. The defect is *ambiguity +
  dead code*: two sources of truth, wrong-handler-silently-wins, brittle to load-order changes. It's already
  flagged in the menu-module header comment (`:23` "last one wins, see ARCHITECTURE.md").
- **Precedent:** the identical `[dish_dash_track]` twin was removed from menu-module in **v3.10.30** (see
  menu-module.php:53-54) making Orders the sole owner. The account twin is the leftover that de-dup missed.

### Coupling assessment → **independent, ship R1 first**
The tracker consumes `[dish_dash_track]` (already sole-owned in Orders) and does **not** read or modify the
account-shortcode registration. Fixing this = **delete one of the two `add_shortcode` lines**; zero overlap
with the tracker page. → **Two releases.** R1 (double-registration) can and should ship in isolation.

**One decision for R1 (which handler is canonical):** should `/my-restaurant-account/` be the **WC account
UI** (keep Menu, delete Orders line) or the **DD orders list** (keep Orders, delete Menu line)? Note the real
account experience now lives at `/my-account/` via DD_Profile_Module (My Profile + Order History). Recommend
confirming the page's intended role before deleting — the safe default is to keep whichever matches what the
live `/my-restaurant-account/` page is expected to show and delete the other. **[RUN ON SERVER]** to see what
currently renders:
```sql
SELECT ID, post_title, post_status, post_name FROM wp_posts
WHERE ID = (SELECT option_value FROM wp_options WHERE option_name = 'dish_dash_account_page_id');
```

---

## Task 5 — Sidebar / navigation for a "Track Order" item

The relevant nav is the **WooCommerce My-Account menu**, built by
`DD_Profile_Module::add_menu_item()` (profile-module.php:81-98) via the `woocommerce_account_menu_items`
filter — it **rebuilds the array**: `My Profile · Order History · Addresses · Account details · Log out`.

Adding "Track Order" is a **data/template change, not a structural rebuild**: append one entry to that array
(one line), mirroring how `my-profile` is added. Two viable shapes:
- **(a) WC endpoint** (like `my-profile`): also needs the triple registration WC requires —
  `add_rewrite_endpoint` + `query_vars` + `woocommerce_get_query_vars` + a one-time `flush_rewrite_rules`
  (the v3.10.3 fix documents this gotcha: miss the third and WC falls back to the dashboard).
- **(b) Plain menu link** to the **already-existing `/track-order/` page** — simplest; no endpoint plumbing.

**Fragile-CSS lesson does NOT apply here.** The `:has()` breakage (v3.10.16) was in the **admin** sidebar
hider (`hide_irrelevant_menu_items`), not this menu. The WC account menu is a PHP-filtered array rendered by
a WC template — no `:has()`, no CSS-structural dependency. Safe to extend.

---

## Task 6 — Real-time-ness (recommendation for v1)

**Polling infra already exists and ships:** `order-tracking.js` polls `dd_get_order` every 30s and
re-renders the timeline until terminal; the endpoint `dd_get_order` → `ajax_get_order()`
(orders-module.php:1146-1168) is registered (`:78`) and ownership-gated.

**Critical caveat for phone-anchored orders:** `ajax_get_order()` gates on
`(int) $order->customer_id === get_current_user_id()` (**customer_id only**, line 1159) with a staff bypass.
A **guest-placed order matched only by phone** (customer_id NULL/mismatched) would render on first paint but
**fail the 30s poll gate** → the live refresh breaks for exactly the orders R4b/phone-anchoring surfaces.
Closing that needs either **R4c** (write `customer_id` onto those orders — parked) or a **phone-aware
extension of the ownership gate**. Flagging as the realtime coupling risk.

**Recommended v1 = snapshot-on-load list, reuse existing live tracker for drill-in.** Concretely:
- The Track page shows a **snapshot list of active orders** (phone-anchored query from Task 2) at page load —
  simple, no new polling, avoids the gate mismatch (list query is server-side, no per-order AJAX gate).
- Each row **deep-links to the existing single-order tracker** (`?order_id=`), which already polls live — for
  `customer_id`-owned orders this "just works" today; phone-only orders would need the gate fix (defer with
  R4c, or land the phone-aware gate as a small add-on).
- **Defer** simultaneous multi-order live polling to a later release — it's the heavy part and not needed for
  a useful v1.

---

## Deliverable summary & recommended shape

- **Status model:** 5 string statuses (pending/confirmed/ready/cancelled/delivered); active =
  pending/confirmed/ready; each milestone has its own `_at` column → full timeline supported
  (helpers.php:112-134, update_status orders-module.php:537).
- **Active-orders query:** reuse R4b `( customer_id = %d OR customer_phone IN (…) )` from
  profile-module.php:141-149, add `AND is_test = 0 AND status IN ('pending','confirmed','ready')`. Phone set
  from `get_customer_for_user()->whatsapp`.
- **Track page:** already exists & functional (single-order live timeline, v3.10.30). v1 = upgrade to a
  phone-anchored **list of active orders**, not a new build.
- **Double-registration:** `[dish_dash_account]` in menu-module.php:54 vs orders-module.php:110; Orders wins
  by load order; symptom = silent shadow / dead WC-account handler, no notice. **Independent of the tracker →
  R1 fix first** (delete one line; confirm which handler is canonical for `/my-restaurant-account/`).
- **Sidebar:** append one item to `add_menu_item()` (profile-module.php:81-98) — data change; link to the
  existing `/track-order/` page (simplest) or add a WC endpoint (triple-register like my-profile). No fragile
  CSS involved.
- **Realtime:** polling infra exists; recommend **snapshot list for v1** + deep-link to the existing
  per-order live tracker. Watch the `ajax_get_order` customer_id-only gate for phone-only orders (needs R4c
  or a phone-aware gate).

**Suggested releases:** **R1** = `[dish_dash_account]` de-dup (small, isolated). **R2** = Track Order page
upgrade (snapshot active-orders list, phone-anchored; live per-order polling reused via deep-link, full
multi-order polling deferred).

*No files were modified. No version bump. No release.*
