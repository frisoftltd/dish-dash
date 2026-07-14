# Report — Order Delivery Modes + Manual MoMo QR

Rolling report. One section per release. Do not push until told.

---

## Release 1 — v3.10.50 — Settings fields only (no behavior change) ✅

**Root cause / goal:** Add three persisted settings so later releases can branch on them.
No behavior is wired this release.

**Files changed:**
- `admin/pages/settings.php`
  - Save handler (after the Pricing & Fees block): persist three new options.
    - `dish_dash_order_notify_dashboard` — `isset($_POST[...]) ? '1' : '0'`, checkbox default **'1'** (on).
    - `dish_dash_order_handoff_whatsapp` — `isset($_POST[...]) ? '1' : '0'`, checkbox default **'0'** (off).
    - `dish_dash_momo_merchant_code` — text, **digits-only** via `preg_replace('/\D/','', …)`, no default.
  - Markup: new **"📦 Order Handling"** `dd-settings-card` inserted after the Pricing & Fees card
    (before the hidden opening-hours field). Two `.dd-check-label` checkboxes + one `dd-input--medium`
    numeric text field, each with the brief's helper text. Follows the `dd_fees_enabled` pattern verbatim
    (`checked()` for state, `get_option()` for read).
- `dish-dash.php` — version bumped to `3.10.50` (header comment + `DD_VERSION` constant).
- `CLAUDE.md` — `Last updated`, Current State (Deployed version / sub-phase / Next task / Last working
  state), and release table rows for v3.10.50–v3.10.55.

**Scope guard:** Only the three `update_option()` calls + one settings card were added. No consumer reads
these options yet. No gateway, order-flow, notification, or schema code touched. The `DD_MoMo` Collections
path, PesaPal, and `dd_momo_check_status` are untouched (Option B boundary intact).

**Test steps (developer, after deploy):**
1. WP Admin → Dish Dash → Settings → scroll to **📦 Order Handling**. Confirm three fields render:
   Dashboard Notifications (checked), WhatsApp Handoff (unchecked), MoMo Merchant Code (empty).
2. Enter a merchant code with spaces/letters (e.g. `12 34ab5`) → Save → reopen → value is digits only
   (`12345`).
3. Toggle WhatsApp Handoff on → Save → box stays checked on reload.
4. Verify persistence:
   - `wp option get dish_dash_order_notify_dashboard` → `1`
   - `wp option get dish_dash_order_handoff_whatsapp` → `1` (after toggling on) / `0` (default)
   - `wp option get dish_dash_momo_merchant_code` → the digits saved
5. Confirm no visible behavior change anywhere else (dashboard notifications, checkout, WhatsApp all
   exactly as before).

**Status:** Implemented, committed (not pushed). Awaiting developer publish + deploy before Release 2.

---

## Release 2 — v3.10.51 — Gate dashboard notification alerts ✅

**Goal:** `dish_dash_order_notify_dashboard` (default `'1'`) controls ONLY the interrupting
notification alerts. Orders page + order data + statuses remain fully functional.

**Key finding:** The notification polling is NOT a standalone script. It lives inside the shared
`assets/js/admin.js`, which is enqueued on ALL Dish Dash admin pages and also powers confirm-delete,
auto-fade notices, and the Orders modal. So admin.js cannot be skipped without breaking the Orders page.
The "enqueue-guard" is therefore implemented as an **enqueue-time config flag** that prevents the poll
from initializing — `wp_localize_script` regenerates on every page load, so even a browser-cached
admin.js receives the current flag value (equivalent to not shipping the poll).

**Files changed:**
- `admin/class-dd-admin.php` (`enqueue_admin_assets()`): added
  `'notifyEnabled' => get_option( 'dish_dash_order_notify_dashboard', '1' ) === '1'` to the
  `dishDashAdmin` localize array.
- `assets/js/admin.js`: wrapped the top-level `initPolling();` call in
  `if ( config.notifyEnabled !== false ) { initPolling(); }`. Explicit-false-only, so a missing flag
  still polls (default-on safety). No other JS touched — the 60s bell-timestamp refresher and the
  bell-click handler are harmless no-ops with an empty list (not polls/alerts).
- `modules/orders/class-dd-orders-module.php` (`ajax_poll_notifications()`): after the permission
  check, early-return `{ pending_items: [], pending_count: 0 }` when the option `!== '1'`. Backstops a
  stale cached script.
- `dish-dash.php` — version `3.10.51` (header + `DD_VERSION`).
- `CLAUDE.md` — Current State + release row.

**Behavior:**
- OFF ⇒ no `dd_poll_notifications` requests, no beep, no browser Notification, no bell badge (the badge
  is only ever set inside `poll()`).
- ON / default / fresh install (no saved option) ⇒ polls as before (`get_option` fallback `'1'`).

**Scope guard (untouched):** Orders page render/queries/modal, status-transition logic, the customer
`/track-order/` page (its flag-gating is a later release), and the reservations admin's OWN notification
system. Confirmed repo-wide that only the orders module + admin.js reference `dd_poll_notifications`, so
the reservations admin is unaffected.

**Test steps (developer, after deploy):**
1. Settings → Order Handling → **uncheck** Dashboard Notifications → Save → purge LiteSpeed → reload
   dashboard. Network tab: no `dd_poll_notifications` requests. Place a test order → no beep, no browser
   alert, no bell badge. Orders page: list, search, filters, pagination, order modal all still work.
2. **Re-check** Dashboard Notifications → Save → purge → reload. Polling resumes; a new test order
   triggers alert + bell badge.
3. `wp option get dish_dash_order_notify_dashboard` reflects the saved value (`0` / `1`).

**Status:** Implemented, committed, pushed. Awaiting developer publish + deploy + verify before Release 3.

## Release 3 — v3.10.52 — Customer WhatsApp handoff button (opt-in, tap-only) ✅

**Goal:** Restore the customer-facing "send my order to the restaurant on WhatsApp" button on the
order-confirmation screen — ONLY when `dish_dash_order_handoff_whatsapp` is on, and ONLY as a manual tap
(never auto-open, which is what v3.5.25 removed).

**Which URL targets the restaurant (confirmed):** `data.whatsapp_url` = `build_admin_whatsapp_url()` →
the `dd_whatsapp_admin` number, `rawurlencode`d. Its message already contains order number, items,
quantities, and total. `data.whatsapp_customer_url` goes to the customer's own phone — NOT used here.
Server URL builders were **not modified**.

**Files changed (JS/CSS/markup + one localize, no server URL change):**
- `modules/template/class-dd-template-module.php` — `ddCartData` localize gains
  `'whatsappHandoff' => get_option( 'dish_dash_order_handoff_whatsapp', '0' ) === '1'`.
- `templates/cart/cart.php` — confirmation panel gains a hidden anchor
  `<a id="ddConfirmWhatsapp" target="_blank" rel="noopener noreferrer" hidden>…</a>` between the ETA line
  and the Done button. Plain `<a>` = genuine tap; there is no scripted click anywhere.
- `assets/css/cart.css` — `.dd-confirm-panel__whatsapp` (WhatsApp green `#25D366` — the WhatsApp service
  color, already used in `reservations.js`, not the restaurant brand) + a `.dd-confirm-panel__whatsapp[hidden]`
  author guard so the `hidden` attribute wins over the class `display` (author rules beat the UA
  `[hidden]` rule, so the guard is required).
- `assets/js/cart.js`:
  - Offline confirmation handler (the `data.eta` branch): reveal the button
    (`waBtn.href = data.whatsapp_url; waBtn.hidden = false`) **only** when `ddCartData.whatsappHandoff`
    is true AND `data.whatsapp_url` is non-empty; otherwise keep it hidden.
  - Done/close handler: reset the button to `hidden` + `href = '#'` so no stale URL carries into a later
    order in the same session (also covers the confirmation panel being reused by online gateways).
- `dish-dash.php` — version `3.10.52` (header + `DD_VERSION`).
- `CLAUDE.md` — Current State + release row.

**Edge case (handoff ON but empty/missing WhatsApp URL — e.g. no restaurant number configured):**
chosen behavior = **hide the button** (no dead button). The confirmation screen shows its normal
order-received state (order # + ETA + Done). No URL is rebuilt.

**Tap-only guarantee:** the button is an `<a target="_blank">` the user must tap. No `.click()`,
no `window.open`, no `setTimeout` auto-open anywhere — the intrusive auto-open from pre-v3.5.25 is not
reintroduced.

**Scope guard (untouched):** payment flow, order placement, all gateways; the online-gateway
(MoMo/IremboPay/PesaPal) success handlers (they don't return `whatsapp_url`, so the button never appears
there); the Release-2 dashboard notification code; `build_admin_whatsapp_url()` / server URL builders;
no "I have paid" combine logic (that is Release 6).

**Test steps (developer, after deploy):**
1. Settings → Order Handling → **check** WhatsApp Handoff → Save → purge LiteSpeed. Place a test COD
   order → confirmation screen shows the WhatsApp button → tapping opens WhatsApp with a ticket
   containing order #, items, quantities, total, addressed to the RESTAURANT number. It must NOT open by
   itself.
2. **Uncheck** WhatsApp Handoff → Save → purge → place a test order → confirmation screen unchanged, no
   button.
3. Confirm dashboard notifications still behave as Release 2 left them (toggle independent).

**Status:** Implemented, committed, pushed. Awaiting developer publish + deploy + verify before Release 4.

## Release 4 — v3.10.53 — Manual MoMo up-front placement
_Pending._

## Release 5 — v3.10.54 — Dynamic QR + iOS copy fallback
_Pending._

## Release 6 — v3.10.55 — Single-tap "I have paid"
_Pending._
