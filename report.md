# R2 — Track Order Page: Active-Orders List (Option A) — Build Report

**Version:** 3.10.46 (bumped in `dish-dash.php` header + `DD_VERSION`, and CLAUDE.md).
**Sidebar wiring:** S1 (href remap; standalone `/track-order/` page kept as the single surface).
**Status:** built, committed, pushed. **Not released** — developer creates the GitHub release.

> **v3.10.46 fix (this release):** in the list, **only orders the user owns are clickable**. The list SELECT
> now returns `customer_id`; a row links to `?order_id=` only when `customer_id === current_user_id`.
> Phone-only rows (customer_id NULL / another id) render `number · status · time · "In progress"` as a
> **non-clickable** `<div class="dd-track__order-link--static">` (hover/pointer disabled) — so no one can
> reach the customer_id-only ownership gate → "Order not found". No gate change, no customer_id write.

---

## What changed (4 files)

### 1. `modules/orders/class-dd-orders-module.php` — active-orders list query + refactor
`shortcode_track()` (~:1645) split into two paths:
- **Requested order (`?order_id=` or `?order=`) → new private `render_single_track()`** — the pre-R2
  single-order behavior moved verbatim: same fetch, same ownership gate
  (`! current_user_can('dd_manage_orders') && (int)$order->customer_id !== $uid` → null), same
  `order-tracking.js` live poll + `ddTrackConfig` localize. **Untouched behavior.**
- **Default (no param) → phone-anchored active-orders list.** Reuses the R4b OR-block:
  ```sql
  WHERE ( customer_id = %d OR customer_phone IN (<canonical E.164…>) )
    AND is_test = 0
    AND status IN ('pending','confirmed','ready')
  ORDER BY id DESC
  ```
  - Phone set = `get_customer_for_user($uid)->whatsapp`, `array_filter`'d for empties.
  - **Empty-set guard:** no phone → `WHERE customer_id = %d` only (never emits `IN ()`).
  - Bound with generated `%s` placeholders via `$wpdb->prepare( …, array_merge([$uid], $phones) )` —
    no string concatenation of phone values.
  - Selects `id, order_number, status, created_at`; renders `state = 'list'`.
  - Enqueues `order-tracking.css` (style only — **no** JS/poll on the list).

### 2. `templates/orders/track.php` — new `list` state
- Docblock updated (`$state` now includes `list`; `$orders` documented); `$orders` defaulted to `[]`.
- New `elseif ( 'list' === $state )` branch:
  - Non-empty → `<ul class="dd-track__orders">`, one row per order: **order number · status label · time**,
    the whole row an `<a>` to `add_query_arg('order_id', (int)$o->id, dd_track_url())`.
  - Empty → clean **"No active orders."** state + "Browse the menu" CTA.
- **`guest` / `notfound` / `empty` / `ok` branches unchanged** — the `ok` single-order timeline is intact.

### 3. `assets/css/order-tracking.css` — list-row styles (appended, no new file)
`.dd-track__orders`, `.dd-track__order-row`, `.dd-track__order-link` (grid, hover), `.dd-track__order-num`,
`.dd-track__order-status` (pill on `var(--dd-track-accent)`), `.dd-track__order-time`, plus a mobile
(`≤480px`) reflow. Brand token + `rgba()` only — no hex literals (matches file convention).

### 4. `modules/profile/class-dd-profile-module.php` — sidebar S1
- `add_menu_item()` appends `$clean['track-order'] = 'Track Order'` (after **Order History**).
- `init()` registers `add_filter( 'woocommerce_get_endpoint_url', [ $this, 'track_order_menu_url' ], 10, 4 )`.
- New `track_order_menu_url()` returns `dd_track_url()` for the `track-order` key, so the menu item links to
  the standalone `/track-order/` page instead of a dead `/my-account/track-order/` endpoint.

---

## Behavior

| Scenario | Result |
|---|---|
| Logged-out → `/track-order/` | `guest` state — "Please log in" (unchanged) |
| Logged-in, has active orders | **List** of pending/confirmed/ready orders (is_test=0), newest first |
| — owned row (customer_id = you) | Clickable → `?order_id=` single-order live timeline (unchanged) |
| — phone-only row (customer_id NULL) | Non-clickable: `number · status · time · "In progress"` |
| Logged-in, no active orders | Clean **"No active orders."** empty state |
| `?order=<number>` | Existing single-order path — unchanged |
| Sidebar "Track Order" | Links to `/track-order/` (S1 href remap), not the account dashboard |

---

## Scope guardrails honored
- ❌ No `ajax_get_order()` gate change. ❌ No guest tracking. ❌ No `customer_id` writes / R4c.
- The `?order_id=`/`?order=` per-order tracker branches are behavior-identical (extracted, not modified).

## Phone-only orders (handled in v3.10.46)
A **phone-only** active order (matched by canonical phone but `customer_id` null/mismatched) still **appears in
the list** for completeness, but is now rendered **non-clickable** (`number · status · time · "In progress"`),
so no one reaches the `render_single_track()` ownership gate → "Order not found". Owned orders
(`customer_id === current user`) remain clickable → per-order live tracker. Full attribution (making these
orders owned/live) comes with **R4c**. Documented in CLAUDE.md Next task.

---

## Verification (LIVE, after deploy)
1. Deploy 3.10.45; purge LiteSpeed; test in incognito.
2. Log in as **user 14** → open **Track Order** (sidebar item or `/track-order/`):
   - Confirm the page lists only **active** orders (pending/confirmed/ready, `is_test=0`), newest first.
   - Click a row → confirm it opens the per-order live timeline (`?order_id=`).
3. Log in as a user with **no active orders** → confirm the clean **"No active orders."** state.
4. Confirm the sidebar **Track Order** link goes to `/track-order/` (not `/my-account/` dashboard).

**[RUN ON SERVER] expected-list cross-check** (replace phone with user 14's stored `customers.whatsapp`):
```sql
SELECT id, order_number, status, created_at FROM wp_dishdash_orders
WHERE ( customer_id = 14 OR customer_phone = '+250785553103' )
  AND is_test = 0 AND status IN ('pending','confirmed','ready')
ORDER BY id DESC;
```

## Notes
- PHP not available in the build environment → no local `php -l`; edits verified by re-reading each changed
  region (brace/branch structure confirmed). Recommend a smoke load of `/track-order/` right after deploy.
- No schema change, no new tables, no migration.
