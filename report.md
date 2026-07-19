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

## Release 4 — v3.10.53 — Manual MoMo up-front placement (COD-style) ✅

**Goal:** New `momo_manual` ("MoMo (scan & pay)") method places the DB row immediately like COD with
`payment_status='claimed_pending'`, fully separate from the Collections/PesaPal Option B transient flow.

**Files changed (4 edits, minimal):**
- `modules/orders/class-dd-orders-module.php`:
  - **Where it branches off from COD / where it is kept out of Option B** — `ajax_place_order()`,
    the `$is_online` computation (now ~line 808–813):
    ```php
    $is_online = ! in_array( $payment_method, self::OFFLINE_GATEWAYS, true )
                 && 'momo_manual' !== $payment_method;
    ```
    Forcing `$is_online = false` for `momo_manual` is the exact point that keeps it OUT of the online
    branch and the Option B path: it means the method skips `if ('mtn_momo')` (~line 815),
    `if ('irembopay')` (~line 862), `if ('pesapal')` (~line 968), and `if ($is_online)` (~line 1013),
    and falls through to the **OFFLINE GATEWAY FLOW** (~line 1075) — the same block COD/bacs/cheque use
    (`place_order()` up front → clear cart → notifications → customer upsert → birthday).
  - **The claim-state stamp** — right after the offline `place_order()` `is_wp_error` check (~line 1088):
    ```php
    if ( 'momo_manual' === $payment_method ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'dishdash_orders',
            [ 'payment_status' => 'claimed_pending' ], [ 'id' => $result['order_id'] ],
            [ '%s' ], [ '%d' ] );
    }
    ```
    `place_order()` inserts with `payment_status='unpaid'`; this override sets `claimed_pending`.
    Free-text VARCHAR(50) → no schema change.
- `modules/template/class-dd-template-module.php` — after the WC-gateway loop that builds
  `ddCartData.paymentGateways` (~line 300), append a synthetic entry so the method renders in the drawer,
  visibly distinct from the Collections `mtn_momo` gateway:
  `[ 'id' => 'momo_manual', 'title' => 'MoMo (scan & pay)', 'icon' => '📲', 'iconUrl' => '' ]`.
  It is NOT a WooCommerce gateway (self-contained; no Collections API, no Option B transient).
- `dishdash-core/class-dd-helpers.php` — `dd_format_payment_method()` map adds
  `'momo_manual' => 'MoMo (scan & pay)'` (admin Orders list + email/notification labels).
- `assets/js/admin.js` — `ddFormatPaymentMethod()` map adds the same label (bell/notification panel).
- `dish-dash.php` — version `3.10.53`. `CLAUDE.md` — Current State + release row.

**Flow proof (why it can't touch Option B):** `momo_manual` is an exact string; it does not equal
`mtn_momo`/`irembopay`/`pesapal`, and with `$is_online=false` it never enters the online branch. It never
reads/writes the `dd_momo_pending_*` / `dd_pesapal_pending_*` transients, never calls `DD_MoMo` or
`dd_momo_check_status`, and never hits the Collections API. The row is written by `place_order()` at
checkout time. `momo_phone` is not required (that validation lives only inside the `mtn_momo` branch).

**PesaPal abandon-at-payment regression (required report item):** I did **not** run a live test —
I cannot drive the hosted PesaPal page from here; that live check is the developer's gate (and is listed
under the brief's developer-verify). **Code-inspection confirmation that no row is created on abandon:**
PesaPal is handled entirely by its own `if ( 'pesapal' === $payment_method )` branch (~line 968), which
`return`s BEFORE `$is_online` is ever used, so my `$is_online` change cannot affect it. That branch still
only stores a transient (`dd_pesapal_pending_*`) and writes the DB row exclusively inside
`ajax_pesapal_check_status()` on `status === 'COMPLETED'` — both untouched this release. My
`claimed_pending` stamp is guarded to `momo_manual`, so it never runs for PesaPal. Therefore abandoning
at the PesaPal page still creates no row (v3.6.8 ghost-order fix intact). **Developer must still run the
live abandon test as the release gate.**

**cart.js NOT touched this release:** a `momo_manual` order currently lands on the generic confirmation
panel (the offline response shape). That is acceptable for R4 (proves up-front placement); R5 branches
the frontend to the QR screen.

**Test steps (developer, after deploy) — regression-focused:**
1. Checkout drawer shows **MoMo (scan & pay)** as a method, visibly distinct from the Collections MoMo.
2. Place an order with it → a row exists IMMEDIATELY with `payment_status='claimed_pending'`:
   `wp db query "SELECT id, order_number, payment_method, payment_status FROM wp_dishdash_orders ORDER BY id DESC LIMIT 3"`.
3. **CRITICAL:** Place a **PesaPal** order, abandon at the payment page (don't pay) → NO new row exists
   (ghost-order fix still working).
4. Place a **Collections MoMo** order → still behaves as before (row on confirm only).
5. **COD** still works unchanged.

**Status:** Implemented, committed, pushed. Awaiting developer publish + deploy + the PesaPal abandon
regression check before Release 5.

## Release 5 — v3.10.54 — MTN MoMo logo + title on the scan & pay method ✅

**Goal:** Presentation only. The `momo_manual` method (from R4) shows the official MTN MoMo logo and
reads "Scan and pay with MoMo". No behavior change.

**Investigation findings (as requested):**
- **Collections logo asset:** `class-dd-template-module.php` builds `$icon_urls['mtn_momo'] =
  plugins_url( 'assets/images/mtn-momo-logo.jpg', DD_PLUGIN_FILE )`. The file `assets/images/mtn-momo-logo.jpg`
  already exists (vendored) — reused as-is, no new file added.
- **Synthetic `momo_manual` entry** lives in the same `paymentGateways` closure (appended after the WC
  gateway loop). That is where title + icon are set.
- **How the renderer picks logo vs emoji:** `assets/js/cart.js` (~L606–613) does
  `var iconUrl = gw.iconUrl || '';` then renders `iconUrl ? '<img … class="dd-payment-option__logo">' :
  gw.icon`. So a non-empty **`iconUrl`** shows the image; otherwise it falls back to the emoji `icon`.
  The synthetic entry previously had `iconUrl => ''` (so it showed 📲) — setting `iconUrl` fixes it.

**Files changed:**
- `modules/template/class-dd-template-module.php` — synthetic `momo_manual` entry: `title` →
  `'Scan and pay with MoMo'`; `iconUrl` → `$icon_urls['mtn_momo']` (reuses the exact Collections asset
  path already in scope — DRY, guaranteed identical). Emoji `'📲'` kept as a harmless fallback.
- `dishdash-core/class-dd-helpers.php` — `dd_format_payment_method()` map:
  `'momo_manual' => 'Scan and pay with MoMo'`.
- `assets/js/admin.js` — `ddFormatPaymentMethod()` map: same label.
- `dish-dash.php` — version `3.10.54`. `CLAUDE.md` — Current State + release row.

**Scope guard (untouched):** payment flow, order placement, the R4 `claimed_pending` stamp, `$is_online`
routing; the Collections `mtn_momo` gateway code (the developer has hidden it from live checkout — this
release does NOT manage that visibility, only restyles the scan & pay entry); PesaPal, COD, R2
notifications, R3 button. No QR yet (R6). No "I have paid" (R7).

**Test steps (developer, after deploy):**
1. Checkout: the scan & pay method shows the MTN MoMo logo (not 📲) and reads "Scan and pay with MoMo".
2. Place an order with it → still works exactly as R4 (row created immediately,
   `payment_status='claimed_pending'`). No behavior change.
3. Admin order view / notification panel shows the updated label "Scan and pay with MoMo".

**Status:** Implemented, committed, pushed. Awaiting developer publish + deploy + verify before the QR
release.

## Release 6 — v3.10.55 — Scan & pay first in the payment list ✅

**Goal:** Ordering only. "Scan and pay with MoMo" (`momo_manual`) renders FIRST at checkout, ahead of
PesaPal and Cash on delivery. No behavior change.

**How order is determined (as requested):** `assets/js/cart.js` (~L605–617) renders the payment options
by `.map()`-ing `ddCartData.paymentGateways` in plain array order (no sort/reorder), and marks the first
entry (`i === 0`) as `checked`. So the array order IS the visual order, and index 0 is the default
selection.

**File changed:**
- `modules/template/class-dd-template-module.php` — the synthetic `momo_manual` entry is now added with
  `array_unshift( $out, [ … ] )` instead of `$out[] = [ … ]`, placing it at index 0 (before the WC
  gateways). Same title/logo as R5 (`$icon_urls['mtn_momo']`).
- `dish-dash.php` — version `3.10.55`. `CLAUDE.md` — Current State + release row.

**Consequence to note:** because cart.js checks index 0, `momo_manual` is now not only first but also the
**default-selected** method. This is the natural, intended result of being first (the brief asks for it
to be the first option). Which methods appear is unchanged — only their order.

**Scope guard (untouched):** payment behavior, order placement, the R4 `claimed_pending` stamp,
`$is_online`; the R5 logo + title; PesaPal, COD, Collections `mtn_momo` code, R2 notifications, R3
button. No QR (R7). No "I have paid" (R8).

**Test steps (developer, after deploy):**
1. Checkout: "Scan and pay with MoMo" is the FIRST payment option (and pre-selected); PesaPal and COD
   follow.
2. Select + place an order with it → still works (row created immediately, `payment_status='claimed_pending'`).
   No behavior change.

**Status:** Implemented, committed, pushed. Awaiting developer publish + deploy + verify before the QR
release.

## Release 7 — v3.10.56 — Dynamic MoMo QR + iOS copy fallback ✅

**Goal:** A `momo_manual` order (already placed up front, `claimed_pending`) now lands on a dedicated
Scan-&-pay QR screen instead of the generic confirmation panel.

**Investigation (reported before coding):**
- **Branch point:** cart.js offline/COD confirmation success handler (the `data.eta` path). `payment`
  (the selected method id) is in scope (`var payment = pmEl ? pmEl.value : …`), so branching on
  `payment === 'momo_manual'` selects the QR screen.
- **Data available at confirmation:** the offline response is `array_merge($result, [...])` →
  `data.order_number`, `data.order_id`, `data.total` (order total), `data.eta`, `data.whatsapp_url`
  (R3), `data.customer_id`. So amount (`data.total`) and reference (`data.order_number`) are both
  present client-side. **Merchant code was NOT exposed** → added `momoMerchantCode` to `ddCartData`.
- **QR library vendored?** No — searched `assets/`, none present.

**QR library chosen:** **`qrcode-generator` by Kazuhiko Arase (MIT)** — vendored at
`assets/vendor/qrcode/qrcode.js` (56 KB, one file). Chosen because it is a single self-contained file
with **no jQuery and no build step**, exposes a global `qrcode`, and its `createDataURL(cellSize, margin)`
returns a ready-to-use `data:` URI I can drop straight into an `<img>` (no canvas/container dance).
`qrcode(0, 'M')` auto-sizes the symbol (its `make()` auto-selects the type when `typeNumber < 1`), which
suits our short payload. `.gitignore` whitelists `assets/vendor/**`, so it ships in the zip.

**Exact QR payload built (sample order — merchant `888444`, total `12000` RWF):**
```
tel:*182*8*1*888444*12000%23
```
(`amount = Math.round(data.total)` → integer RWF, no decimals/commas; `#` encoded as `%23` for the
`tel:` URI. The order reference is NOT in the payload.)

**Files changed:**
- `assets/vendor/qrcode/qrcode.js` — **new** vendored MIT QR library (single file).
- `modules/template/class-dd-template-module.php` — enqueue `dd-qrcode` (before cart.js) and add it to
  `dish-dash-cart`'s deps; expose `'momoMerchantCode' => preg_replace('/\D/','', get_option('dish_dash_momo_merchant_code',''))`
  in `ddCartData` (read-only display).
- `assets/js/cart.js` —
  - inject a new drawer panel `#ddPanelMomoManual` at DOMContentLoaded; register it in `showPanel()`;
  - helpers `makeQrDataUrl()` (guarded `typeof qrcode === 'undefined'` + try/catch), `copyText()` /
    `legacyCopy()` (clipboard API + textarea fallback + "Copied" feedback), and `renderMomoManualPanel()`;
  - branch the offline confirmation handler: `if ( payment === 'momo_manual' )` → render QR panel; else
    the generic confirmation (R3 WhatsApp button stays on the generic path only).
- `assets/css/cart.css` — `.dd-momoqr*` styles (QR `<img>`, `tel:` dial CTA using `var(--dd-accent)`,
  tap-to-copy rows with "Copied" state).
- `dish-dash.php` — version `3.10.56`. `CLAUDE.md` — Current State + release row.

**What the screen shows (merchant code set):** order number, an instruction line, the QR `<img>`, a
tappable **"Dial to pay now"** `tel:` link (Android dials the USSD; iOS may not), and three tap-to-copy
rows — **Merchant code**, **Amount (RWF)** (copies the raw integer), **Order reference** (the order
number). The reference is display/reconciliation only, never in the QR.

**iOS fallback:** iOS blocks `tel:` USSD auto-dial by design, so the copyable merchant code + amount +
reference ARE the fallback (always present). The QR is scannable from another device/camera.

**Empty-merchant-code behavior (chosen):** graceful fallback — **no QR, no dial link, merchant row
hidden**; still show the copyable **Amount** and **Order reference** plus a plain note "Pay via MTN MoMo,
then share your order reference with the restaurant." I did **not** add an admin-side "merchant code
unset" notice (the brief marked it optional) to keep this release's scope tight — can be added later if
wanted.

**Scope guard (untouched):** order placement / the R4 `claimed_pending` stamp (order is already placed;
this only renders the pay screen); the "I have paid" claim + WhatsApp combine (that is R8); PesaPal, COD,
Collections MoMo, R2 notifications. No live-network calls added (QR is generated client-side).

**Test steps (developer, after deploy):**
1. Settings → Order Handling → set MoMo Merchant Code (e.g. `888444`) → Save → purge LiteSpeed.
2. Place a "Scan and pay with MoMo" order → QR screen renders. Scanning the QR on Android opens the
   dialer with `*182*8*1*888444*{amount}#` pre-filled (amount = order total).
3. Merchant code, amount, and order reference are shown and copy on tap ("Copied" feedback).
4. On iPhone: QR/tel may not auto-dial — confirm the copyable details are present and usable.
5. Clear the merchant code → place an order → no broken QR; graceful fallback (amount + reference + note).
6. Order still placed up front (`payment_status='claimed_pending'`) — unchanged.

**Status:** Implemented, committed, pushed. Awaiting developer publish + deploy + verify before Release 8
("I have paid").

## Release 8 — v3.10.57 — Single-tap "I have paid", sticky panel, mobile-safe close ✅ (FINAL)

**Goal:** One "I have paid — notify restaurant" button on the Scan-&-pay panel (claim always, WhatsApp if
handoff on); panel persists until the customer taps X; always-reachable pinned X fixes the R7 mobile
cutoff.

**Claim endpoint added (route + guard):**
- Route: `dd_momo_claim_paid` → `DD_Orders_Module::ajax_momo_claim_paid()`, registered with
  `DD_Ajax::register( 'dd_momo_claim_paid', …, true )` (`nopriv = true` — customers may be guests, same as
  `dd_momo_check_status`). Nonce via `DD_Ajax::verify_nonce()` (field `nonce`, action `dish_dash_frontend`
  = `ddCartData.nonce`).
- Server-side guard: reads the order row; **404s if it doesn't exist**; **rejects if
  `payment_method !== 'momo_manual'`**; then **only flips `payment_status` from `claimed_pending` →
  `claimed`** (idempotent — a double-tap / replay on an already-`claimed` or other-state order is a
  harmless no-op success). It **never sets `paid`** — this is a customer attestation; the restaurant
  reconciles against their MoMo statement using the order reference. Free-text VARCHAR → no schema change.

**Auto-close behaviors found on the drawer, and what was disabled for this panel:**
Investigated cart.js for `blur` / `visibilitychange` / `pagehide` / focus-loss / timeout / outside-click /
Escape. **Found only two auto-close paths**, both now suppressed *only while the QR panel is up*
(`momoManualLocked`, set by `showPanel()` = true iff the visible panel is `#ddPanelMomoManual`):
1. **Overlay / outside-click** — the `#ddCartOverlay` `touchend` handler AND the document click-delegation
   `.dd-cart-drawer-overlay` branch. I split the old combined `#ddCartClose, .dd-cart-drawer-overlay`
   handler so the overlay is guarded by `momoManualLocked` while the drawer's own explicit `#ddCartClose`
   still closes (explicit dismissal is allowed).
2. **Escape keydown** — guarded with `&& ! momoManualLocked`.
**No `blur` / `visibilitychange` / `pagehide` / timeout close exists anywhere** — so backgrounding the
browser to pay in MoMo and returning already keeps the panel (nothing to disable there). Scope: the lock
is per-this-panel; other drawers/panels' close behavior is unchanged.

**Persistence across visibilitychange/blur — how ensured:** the panel is a normal DOM element inside the
drawer; nothing in the codebase closes it on tab/app backgrounding (confirmed by the search above), and
the two events that *would* dismiss it (overlay tap, Escape) are locked out while it's shown. So switching
to the MoMo app and back leaves the panel — QR, dial link, copy rows, button — exactly as it was.

**Single-tap button behavior:**
- Tap → disable immediately (double-tap guard) → "Recording…".
- If `dish_dash_order_handoff_whatsapp` on AND the order has a restaurant `whatsapp_url`: open it via an
  in-gesture `<a target="_blank" rel="noopener">` click (popup-safe; reuses THIS order's R3 ticket). If
  off (or no restaurant number): no WhatsApp.
- Fire `dd_momo_claim_paid { order_id }`. On success → button hidden, "Payment recorded — you can close
  this." shown, **panel stays open** (no auto-close). On failure → re-enable for retry (server idempotent;
  WhatsApp already opened). The button checks ONLY the handoff flag, never the dashboard-notify flag.

**Mobile-safe close / R7 cutoff fix:** `#ddPanelMomoManual` is now `display:flex; flex-direction:column;
flex:1; min-height:0` (fills the fixed-height drawer, like `#ddPanelCart`); the header
(`.dd-momoqr__header`, `flex-shrink:0`) is pinned with an always-visible **X** (`#ddMomoQrClose` →
`closeCart()`), and `.dd-momoqr` is the `flex:1; min-height:0; overflow-y:auto` scroll body — so both the X
and the action button are reachable at ~380px.

**Files changed:**
- `modules/orders/class-dd-orders-module.php` — register + add `ajax_momo_claim_paid()`.
- `assets/js/cart.js` — `momoManualLocked` lock (set in `showPanel`), guard overlay `touchend` +
  click-delegation (split) + Escape; panel markup (header X, claim button + recorded note, removed Done);
  X + claim handlers; `markMomoClaimed()`; `renderMomoManualPanel()` stashes order id / whatsapp url and
  resets claim UI.
- `assets/css/cart.css` — `#ddPanelMomoManual` flex-fill, `.dd-momoqr__header` / `.dd-momoqr__close`,
  `.dd-momoqr` as scroll body, `.dd-momoqr__claim`, `.dd-momoqr__recorded`.
- `dish-dash.php` — version `3.10.57`. `CLAUDE.md` — Current State + release row (track marked complete).

**Scope guard (untouched):** order placement / R4 `claimed_pending` stamp; R7 QR payload / rendering /
dial link / copy rows; other drawers' close behavior; PesaPal, COD, Collections MoMo, R2 notifications,
R3 generic-panel button.

**Test steps (developer, after deploy):**
1. Handoff ON: scan & pay order → tap "I have paid" → DB `payment_status` = `claimed` AND WhatsApp opens
   with this order's ticket; panel stays open with the confirmation.
2. Handoff OFF: same tap → status `claimed`, NO WhatsApp, confirmation shown.
3. Persistence (mobile): open panel → switch app/tab → back → panel still there with all details.
4. Mobile close: narrow phone → X visible/reachable, button reachable via scroll; X closes.
5. Double-tap: tapping twice does not double-claim (server idempotent) / does not double-open.
6. No regressions: PesaPal/COD/Collections unchanged; generic confirmation panel (non-momo_manual)
   unchanged; overlay/Escape still close OTHER panels.

**Status:** Implemented, committed, pushed. FINAL release of the track — awaiting developer verify.

---

## v3.10.59 — Match reservation WhatsApp button styling to order confirmation (styling only)

**Decision: SHARED CLASS (not a duplicated rule).**
Both surfaces load the same stylesheets, so no duplication is needed:
- `.dd-confirm-panel__whatsapp` and `.dd-confirm-panel__close` live in **cart.css**.
- cart.css is enqueued **unconditionally** in `class-dd-template-module.php`
  `enqueue_frontend_assets()` — the same block that enqueues `reservations.js`. So
  wherever the reservation modal/JS runs, cart.css is present and both classes resolve.
- `--dd-accent` (used by `.dd-confirm-panel__close`) is set at `:root` in **frontend.css**,
  also loaded on this surface → the Close pill renders the identical accent (#e8832a).
- Result: zero new CSS. Reused the existing rules verbatim.

**Exact rule reused (cart.css:975 — unchanged, applied to the reservation button):**
```
.dd-confirm-panel__whatsapp {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    background: #25D366; color: #fff; text-decoration: none;
    border-radius: 10px; padding: 12px 28px;
    font-size: 15px; font-weight: 700; line-height: 1.3;
    cursor: pointer; margin: 0 0 12px;
}
```
Close reuses `.dd-confirm-panel__close` (cart.css:963 — accent pill, radius 10px, padding 12px 40px, 16px/700).

**Investigation findings:**
- ORDER button (reference): `.dd-confirm-panel__whatsapp` in cart.css; sits inside the
  centered flex column `.dd-confirm-panel`, with `.dd-confirm-panel__close` (accent) below.
- RESERVATION button (before): built in `reservations.js` `showWhatsAppButtons()` with
  `class="dd-res-btn"` + inline `display:block;width:100%;background:#25D366;…` and a Close
  `class="dd-res-btn dd-res-btn--outline"` with inline `width:100%`. Crucially,
  `.dd-res-btn` / `.dd-res-btn--outline` have **no CSS rule anywhere** → both rendered as
  flat, full-width, sharp-cornered, flush browser-default controls. That's the "unfinished" look.
- Stylesheets: order = cart.css; reservation JS-built buttons = (none). Since cart.css also
  loads on the reservation surface, sharing is valid → shared-class chosen.

**Change (JS only — `assets/js/reservations.js`):** `showWhatsAppButtons()` now builds a
centered inline flex-column wrapper (mirrors the order panel's stacked layout), applies
`.dd-confirm-panel__whatsapp` to the WhatsApp `<a>` and `.dd-confirm-panel__close` to Close,
and gives "Booking received!" 16px breathing room. Inline styles used only for layout glue
(centering/spacing), never for button appearance — appearance comes entirely from the reused classes.

**Untouched:** admin_url ticket, `dd_reservation_handoff_whatsapp` gating, tap-only/no-auto-open,
hidden-when-off/empty; the ORDER confirmation panel + its CSS (reference only); deposit chain; PesaPal.

**Status:** Implemented, committed, pushed — awaiting developer verify.

---

## v3.10.60 — Fix country-code picker on the reservation form

**Root cause (evidenced): z-index / stacking-context burial.**
The picker attaches its country list to `<body>` (`dropdownContainer: document.body` on all
surfaces) as `.iti--container`. That element's shared z-index was `10050 !important` (cart.css),
chosen only to clear the cart DRAWER (desktop 9200 / mobile 10001). The reservation modal
`.dd-res-overlay` sits in the app's **99999 modal tier** (confirmed: `reservations.css:60`
`z-index:99999`; `.dd-closed-overlay` in frontend.css:487 is also 99999). Both the modal and
the body-attached dropdown are `wp_footer`-injected body-level, position:fixed siblings, so they
compete in the root stacking context: `99999 > 10050` → the list opens **behind** the modal
(invisible/unusable). On CHECKOUT the picker lives in the drawer tier (≤10001), which 10050
clears → checkout works. That single environmental difference explains "identical library,
one surface works, the other doesn't."

**Exact diff between the two inits (before the fix):**
- Init OPTIONS: **byte-identical** — both `initialCountry:'rw'`, `countryOrder:['rw','ke','ug','tz','bi']`,
  `nationalMode:false`, `separateDialCode:true`, `dropdownContainer:document.body`, same `loadUtils`.
  → Rules OUT "missing options" (countrySearch/separateDialCode etc. are the same).
- Init TIMING: checkout calls `initPhonePicker()` right after `showPanel(panelCheckout)` — i.e. when
  `#ddFieldWhatsapp` is VISIBLE (cart.js:896-899, comment "now that the field is visible").
  The reservation called it in `init()` at page load (reservations.js:54), when its field
  `#dd-res-whatsapp` is on screen 3 (Details), which is `display:none`
  (`.dd-res-screen--hidden{display:none}`, reservations.css:182). → init-while-hidden.
- Re-render check: the Details step is NOT re-rendered/re-created between init and use — `goToScreen()`
  only toggles the `--hidden` class; the input node persists, so the instance is not wiped
  (double-init is not the cause; a once-guard exists regardless).

The z-index burial is the PRIMARY cause (makes the list unusable). Init-while-hidden is a real but
secondary issue (mis-measures the separate dial code on a `display:none` node — cosmetic; the field
is still typeable today, which is why only the dropdown was reported).

**Fix (match checkout on both axes):**
1. `assets/css/cart.css` — raised the shared `.iti--container` z-index `10050 → 100050 !important`
   so it clears the 99999 modal tier as well as the drawer. Behaviour-neutral for checkout
   (still far above its ≤10001 drawer); also benefits the My Profile picker (same body-attached list).
2. `assets/js/reservations.js` — removed `initPhonePicker()` from `init()`; call it in `goToScreen()`
   under `if (n === 3)` so it inits when the Details field first becomes visible, mirroring checkout's
   init-when-shown. The existing `if (itiWhatsapp) return;` once-guard makes back/forward re-entry a no-op.

**Untouched:** checkout picker / cart.js init (reference), the vendored intl-tel-input library/assets,
R1 handoff logic, the v3.10.59 button styling, and all server-side phone normalization/validation
(`dd_phone_format`=e164, libphonenumber) — this is a UI-picker fix only.

**Known issue recorded for later (do NOT fix here):** the reservation surface has orphan
`.dd-res-btn` / `.dd-res-btn--outline` classes with NO CSS rule anywhere (found in v3.10.59). Other
reservation buttons may render unstyled for the same reason — flagged for a future release.

**Status:** Implemented, committed, pushed — awaiting developer verify.

---

## v3.10.61 — Remove dead PesaPal deposit path from reservations (pure removal)

**What was removed** — all in `modules/reservations/class-dd-reservations-module.php`,
all part of the never-executed deposit-via-PesaPal route (gated behind `$deposit_enabled = 0`):

1. **Hook registration (was line 31):**
   `add_action( 'woocommerce_payment_complete', [ $this, 'on_deposit_payment_complete' ] );`
   — a reservations-only callback that always no-opped (it early-returns unless the order has
   `_dd_is_deposit` meta, which no order ever had, since the deposit path never ran).
2. **The `if ( $deposit_enabled ) { … }` block in `ajax_submit_reservation()` (was lines 140–174)**
   — created the WC order via `create_deposit_wc_order()`, scheduled auto-cancel, tracked
   `deposit_initiated`, and returned `requires_payment` / `payment_url` (the PesaPal redirect).
3. **`create_deposit_wc_order()` (was lines 399–424)** — `wc_create_order()` + a fee item +
   `$order->set_payment_method( 'pesapal' )` + `_dd_is_deposit` meta.
4. **`on_deposit_payment_complete()` (was lines 428–475)** — the deposit-side payment-complete
   handler + its `// ── Deposit payment complete ──` section comment.

**What was kept (per brief):**
- `$deposit_enabled = 0` / `$deposit_amount = 0` / `$deposit_status = 'none'` hardcodes and the
  insert's `deposit_required` / `deposit_amount` / `deposit_status` columns — deposits stay OFF until R3.
- `calculate_deposit_amount()` — dead (no callers, confirmed by grep) but a generic amount helper,
  not part of the PesaPal/WC-order route; R3 wires it in. Left in place to avoid re-adding it later.
- All deposit SETTINGS (Require Deposit, amount, type, auto-cancel hours, refunds, refund policy).
- `run_autocancel()` and its `dd_reservation_autocancel` hook (line 31 now) — untouched (R4 territory).
- `send_admin_email()` — shared with the free-booking path (called at the `// 9. Email admin` step);
  only the deposit CALLER (`on_deposit_payment_complete`) was removed, not the method.

**Confirmation that nothing shared with the ORDER PesaPal flow was touched:**
- The removed `woocommerce_payment_complete` registration was the RESERVATIONS module's own callback.
  The ORDERS module registers its OWN, separate `woocommerce_payment_complete` →
  `DD_Notifications::on_payment_complete` — that is in a different module and was NOT touched.
- No change to the PesaPal gateway, `class-dd-pesapal.php`, `ajax_pesapal_check_status`, the
  order transient-then-confirm logic, or anything in the orders module.
- `reservations.js` was NOT touched — it contains no PesaPal redirect / `payment_url` /
  `requires_payment` handling (verified by grep). `depositActive = false` (:36) and the deposit UI
  scaffolding stay in place per the brief.

**Ambiguous/shared code:** none. `send_admin_email()` is shared but was left intact (only its dead
caller was removed). No code used by both surfaces was modified.

**Verification done here:** post-removal grep for `create_deposit_wc_order`, `on_deposit_payment_complete`,
`_dd_is_deposit`, `WC_Order_Item_Fee`, `get_checkout_payment_url`, `requires_payment` →
**zero matches** in the module. Control flow around the removed block reads cleanly
(insert → build WhatsApp URLs → email → success). PHP lint not run (no PHP binary in the env);
change verified by inspection.

**Scope:** one file — `class-dd-reservations-module.php`. No schema, no `deposit_status`, no auto-cancel change.

**Status:** Implemented, committed, pushed — awaiting developer verify (esp. the PesaPal
ORDER place + abandon/no-ghost-row test).

---

## v3.10.62 — Enable fixed reservation deposits (make "Require Deposit" real; no payment UI)

**`deposit_status` values / convention followed:**
The column (`wp_dishdash_reservations.deposit_status`, VARCHAR(20), schema default `'none'`)
already uses: `'none'` (no deposit — schema default), `'paid'` (analytics queries + the WhatsApp
notification builder + the removed on_deposit_payment_complete set it), `'failed'` (auto-cancel,
`run_autocancel()` line 420). There was **no** pre-existing *pending* value — the old flow put the
pending concept in the booking `status` column as the phantom `'pending_payment'`. I set
`deposit_status = 'pending'` for deposit-required bookings, matching the convention the track's
R4/R6 briefs reference (progression `pending → claimed → paid`, with `failed` on auto-cancel).
The booking `status` stays `'pending'` (unchanged).

**Percent-type decision: REMOVED (not disabled).**
The Deposit Type `<select>` now has a single `<option value="fixed">Fixed amount (RWF)</option>` —
the `percent` option is deleted, so it can't be selected into a broken state. Rationale: a percentage
needs a base order value that does not exist at booking time (a reservation has no cart), so it can
compute nothing. I removed rather than disabled so there is no dead/greyed control implying a
future capability. The `dd_reservation_deposit_type` save handler is left intact (it now always
persists `'fixed'`), and `calculate_deposit_amount()` ignores the type regardless, returning the
fixed `dd_reservation_deposit_amount`. Adjacent helper text that mentioned "percent type" was updated.

**⚠️ Does enabling deposits make the auto-cancel bug live? YES.**
`run_autocancel()` (class-dd-reservations-module.php) selects
`WHERE ... status = 'pending_payment'` — a value never written anywhere. A deposit booking from this
release has `status = 'pending'` and `deposit_status = 'pending'`, so it would never match that query.
On top of that, **no auto-cancel event is even scheduled** — the `wp_schedule_single_event(...
'dd_reservation_autocancel' ...)` call lived inside the PesaPal deposit block that was removed in
v3.10.61. So an unpaid deposit booking is **never** auto-cancelled and **holds its slot indefinitely**.
Before this release deposits were off, so this was dormant/harmless; enabling them makes it live.

> **LIVE-SITE WARNING:** Require Deposit must stay **OFF** on production until R4 / v3.10.63 (which
> re-keys auto-cancel to `deposit_status` and re-establishes scheduling). Turning it on before then
> means unpaid deposit bookings accumulate and never release their slots. Safe to test on a
> non-production/prospect site only. (Khana Khazana does not use deposits.)

**Files changed:** `modules/reservations/class-dd-reservations-module.php` (deposit reads + amount +
pending status), `assets/js/reservations.js` (`depositActive` reads real setting → screen-1 notice),
`admin/pages/settings.php` (fixed-only Deposit Type + helper text). No schema change
(`deposit_status` VARCHAR(20) sufficient). Auto-cancel, payment UI, PesaPal/orders untouched.

**Verification done here:** confirmed the JS `depositActive` flag drives ONLY the informational
`#dd-res-deposit-notice` badge (grep: `populateDepositScreen()` is defined but never called; no
`payment_url`/`requires_payment` handling exists in reservations.js); confirmed `calculate_deposit_amount()`
returns the fixed option value. PHP lint not run (no PHP binary in env) — verified by inspection.

**Status:** Implemented, committed, pushed — awaiting developer verify (deposit OFF unchanged;
deposit ON stores real amount + `deposit_status='pending'`; no payment screen; percent gone).

---

## v3.10.63 — Fix reservation auto-cancel (re-key on deposit state + restore scheduling)

**Investigation:**
- `run_autocancel()` (class-dd-reservations-module.php:405, pre-fix) queried
  `SELECT * ... WHERE id = %d AND status = 'pending_payment'` — `pending_payment` is written
  nowhere (bookings are `status='pending'`), so it matched nothing. On a match it set
  `status='auto_cancelled', deposit_status='failed'` and fired `booking_auto_cancelled` tracking.
- Hook signature: `run_autocancel( int $reservation_id )` — takes ONE booking id. Registered
  `add_action('dd_reservation_autocancel', [$this,'run_autocancel'], 10, 1)` (line 31, present).
- Removed scheduling (git 9a7135c:153–158, inside the deleted PesaPal deposit block):
  `wp_schedule_single_event( time() + ($hours * HOUR_IN_SECONDS), 'dd_reservation_autocancel', [$reservation_id] )`.
  A per-booking single event — sound and PesaPal-independent (it just happened to live in that block).
- Slot release: `ajax_check_availability()` is a **stub** — it returns `available:true` unconditionally.
  There is no DB capacity engine, so "releasing a slot" means the booking leaves the active/pending
  set (admin list + analytics exclude `auto_cancelled`). Marking the status is all that's needed.
- WP-Cron reliability call: a **per-booking single event** is adequate here — the window is hours,
  restaurant sites get regular traffic (WP-Cron fires on visits), and a missed/late event only
  DELAYS slot release; there's no data-integrity risk. I did NOT add a recurring sweep: it would
  change the hook to no-arg and expand scope against "match the existing design." Recommend a sweep
  later only if the developer observes events not firing on this host.

**Fix (one file — class-dd-reservations-module.php):**
1. **Re-keyed the query** in `run_autocancel()`:
   `WHERE id = %d AND deposit_required = 1 AND deposit_status IN ('pending','claimed')`.
   - Cancels: `pending` (unpaid) and `claimed` (customer attested, unverified) — per the governing
     rule, a claim does NOT stop the timer.
   - Safe: `paid` (restaurant-confirmed), `none` (no deposit), `failed` (already cancelled).
   - No time predicate: the single event's fire time IS the window. (Deliberately avoided a
     `created_at`-vs-current-option check — if the admin lengthens the window after scheduling, an
     already-fired event with a current-window check could skip and the booking would never cancel.)
   - On cancel: unchanged — `status='auto_cancelled', deposit_status='failed'` (convention), slot released.
2. **Restored scheduling** in `ajax_submit_reservation()` (new step 7B, immediately after the insert,
   guarded `if ($deposit_enabled)`): re-added the exact per-booking pattern above. No PesaPal code.

**Option key:** `dd_reservation_autocancel_hours` (default 2) — read at schedule time, per the original design.

**Boundaries respected:** no PesaPal/orders change; deposit AMOUNT logic (v3.10.62) untouched; the
claim endpoint (R6) is not built and not referenced; schema unchanged (`deposit_status` VARCHAR(20));
booking flow / confirmation modal / WhatsApp handoff / country picker untouched.

**Verification done here:** grep confirms zero remaining `pending_payment` references in the module;
the cancel-write still stamps `auto_cancelled`/`failed`; scheduling uses the same event name + single-id
arg the hook expects. PHP lint not run (no PHP binary in env) — verified by inspection. The live test
booking RES-20260718-733D (`deposit_status='pending'`) is now cancellable as expected once its event
fires or is run manually.

**Status:** Implemented, committed, pushed — awaiting developer verify on a NON-PRODUCTION site
(deposit booking backdated + `wp cron event run dd_reservation_autocancel` → cancelled/failed;
`none` and `paid` bookings untouched; `wp cron event list` shows the event scheduled after a booking).

---

## v3.10.64 — Deposit scan & pay QR on reservation confirmation

**Shared PHP helper (single source of the USSD format):**
- `dd_momo_merchant_code(): string` and `dd_momo_ussd_payload( int $amount ): string`
- File: `dishdash-core/class-dd-helpers.php` (both wrapped in `function_exists` guards).
- `dd_momo_ussd_payload()` returns `tel:*182*8*1*{merchant}*{amount}%23`, or `''` when no
  merchant code is set. This is now the ONLY place in PHP that encodes the USSD format.

**Orders QR payload — byte-identical before/after (verified):**
The only refactor touching the orders surface is `ddCartData['momoMerchantCode']`, changed from
the inline `preg_replace( '/\D/', '', (string) get_option( 'dish_dash_momo_merchant_code', '' ) )`
to `dd_momo_merchant_code()` — which runs that *exact* `preg_replace` on the *same* option, so the
value cart.js receives is unchanged. **cart.js was NOT modified**; it still builds the order payload
inline (`'tel:*182*8*1*' + merchant + '*' + amount + '%23'`) from that identical `momoMerchantCode`
and the client-side order total. Therefore the orders QR/dial string is byte-identical to today.
(Note: because cart.js/orders are a hard do-not-touch and the order amount is computed client-side
at confirmation, the literal format still appears in cart.js too. I kept the PHP helper's format
identical to it and documented this; fully unifying would require modifying cart.js, which this
brief forbids. Reservations — the new surface — sources its format ONLY from the helper, so the two
cannot silently drift from a reservation-side change.)

**Reservation side:**
- `ddReservations` now carries `momoMerchantCode` = `dd_momo_merchant_code()` and
  `depositPayload` = `dd_momo_ussd_payload( (int) get_option( 'dd_reservation_deposit_amount', 2000 ) )`
  — the fixed deposit amount, i.e. the same value stored on the booking row (v3.10.62).
- `dish-dash-reservations` script gains a `dd-qrcode` dependency (the lib was already enqueued
  unconditionally for cart.js; the explicit dep guarantees availability + load order on this surface).
- `reservations.js`: submit-success branches `if (depositActive) renderDepositPanel(data)` else the
  existing `showWhatsAppButtons(...)`. `renderDepositPanel()` builds into `.dd-res-confirm-area`,
  reusing the orders `.dd-momoqr*` classes: QR `<img>` of the server payload, a `tel:` "Dial to pay
  now" link, and always-copyable rows — Merchant code (hidden when none), Deposit (RWF, raw-integer
  copy value), Booking reference (display/reconciliation only — NOT in the QR). Tap-to-copy uses
  `navigator.clipboard` with a textarea fallback (iOS). Only these small presentation helpers
  (`makeQrDataUrl` / `copyText` / `legacyCopy`) were duplicated from cart.js — no business logic.

**Empty-merchant-code behavior chosen:** graceful fallback — NO QR image, NO dial link, the merchant
row is omitted; the panel still shows the copyable Deposit amount + Booking reference and a plain
"Pay your {amount} RWF deposit via MTN MoMo, then share your booking reference…" note. No broken QR.
(Matches the orders fallback, reservation-worded.)

**CSS added:** none. Reused `.dd-momoqr*` (cart.css) + `.dd-confirm-panel__close` (cart.css), both
already enqueued on the reservation surface. The `.dd-momoqr` container's `flex:1` is inert without a
flex parent (it just renders as a centered, padded column in `.dd-res-confirm-area`) — visually matches.

**Booking ref:** stays OUT of the QR (USSD has no field for it) — display + copy only.

**Boundaries respected:** cart.js / orders QR untouched (byte-identical); no claim button (R6);
auto-cancel (v3.10.63), deposit amount logic (v3.10.62), PesaPal/orders, country picker, WhatsApp
handoff, and phone normalization all untouched.

**Verification done here:** grep confirms each duplicated helper is defined once in reservations.js
(no collisions); the QR/dial payload comes solely from the server-built `ddRes.depositPayload`;
`dd_momo_merchant_code()` reproduces the prior `preg_replace` exactly. PHP lint not run (no PHP binary
in env) — verified by inspection.

**Status:** Implemented, committed, pushed — awaiting developer verify on a NON-PRODUCTION site
(scan QR on Android → dialer shows *182*8*1*{merchant}*{deposit}#; copy rows; empty-merchant fallback;
deposit OFF unchanged; ORDERS QR still identical; ~380px).

---

## v3.10.65 — Single-tap "I have paid" for deposits + sticky panel (FINAL, reservation track)

**Claim endpoint — route + server guard:**
- Route: AJAX action `dd_reservation_claim_deposit` → `DD_Reservations_Module::ajax_claim_deposit()`.
  Registered via `DD_Ajax::register( 'dd_reservation_claim_deposit', [...], true )` — **nopriv**
  (guests book/claim). Nonce: `DD_Ajax::verify_nonce()` (action `dish_dash_frontend`, field `nonce`)
  — the same nonce the reservation submit already sends (`ddRes.nonce`).
- Keyed on `booking_ref` (no reservation id is available client-side — only `data.booking_ref`
  comes back from submit). Guards: booking must EXIST; `deposit_required` must be `1`.
- **Idempotent:** updates `deposit_status` only when it is currently `'pending'` (→ `'claimed'`).
  Double-tap / replay = no-op. **NEVER sets `'paid'`** — `paid` means restaurant-confirmed; this is
  only an unverified customer attestation. Fires `deposit_claimed` tracking on the first flip.

**Close behaviors found on `#dd-res-overlay` and what was locked:**
Exactly three closers exist (bindOpenClose): (1) the header X `#dd-res-close` → `closeModal`;
(2) overlay outside-click (`e.target === #dd-res-overlay`) → `closeModal`; (3) `Escape` keydown →
`closeModal`. There is **no** `blur` / `visibilitychange` / `pagehide` / focus-loss / timeout close
anywhere — so app-switching to the MoMo app and returning already survives; only the two auto-closers
were a risk. Added `depositPanelLocked` (set true in `renderDepositPanel()`, reset false in
`closeModal()`): the overlay-click and Escape handlers now early-return while it's true. The header X
(`#dd-res-close`) is deliberately left UNGATED so it remains the explicit dismissal. Scoped to the
deposit panel only — the booking modal's other steps close exactly as before.

**Always-reachable X / mobile:** no restructure or CSS needed — the reservation modal already pins its
header (`.dd-res-modal__header`, `flex-shrink:0`, contains `#dd-res-close`) above a scrollable body
(`.dd-res-modal__body`, `flex:1; overflow-y:auto`). So the X stays visible and the claim button is
reachable by scrolling the body at ~380px. (This is why, unlike orders R8, no panel restructure was
required.)

**EXPLICIT: a claimed booking still auto-cancels.**
`run_autocancel()` (v3.10.63) was NOT touched this release — its query is still
`WHERE id=%d AND deposit_required=1 AND deposit_status IN ('pending','claimed')` (verified by grep,
lines 484–485). So a booking a customer has `claimed` (without restaurant confirmation) still matches
and is cancelled on schedule → `status='auto_cancelled', deposit_status='failed'`. Only a
restaurant-set `deposit_status='paid'` (which the claim endpoint never writes) is safe from cancel.
No unschedule-on-claim, no claim-aware exemption was added anywhere.

**Frontend (reservations.js):** the deposit panel's bottom action changed from a "Close" button to
`#dd-res-momo-claim` (`.dd-momoqr__claim`) + a hidden `.dd-momoqr__recorded` note. On tap: guard
double-tap → (opt-in, in-gesture `<a target=_blank>`) open `depositWhatsappUrl` if `ddRes.whatsappHandoff`
→ `claimDeposit(booking_ref)` (FormData `fetch` to the claim action) → on success hide the button +
reveal the recorded note (panel stays open); on error re-enable for retry. `renderDepositPanel()` now
stashes `depositBookingRef` + `depositWhatsappUrl` (the R1 restaurant `admin_url`) and locks the panel.

**CSS added:** none — reused `.dd-momoqr__claim` + `.dd-momoqr__recorded` (cart.css, already enqueued
on the reservation surface).

**Boundaries respected:** auto-cancel (read-only), the QR payload / shared PHP helper / dial link /
copy rows (v3.10.64), deposit amount logic (v3.10.62), booking flow, country picker, PesaPal, orders,
cart.js, and the WhatsApp URL builders — all untouched.

**Operational note (surfaced for the developer):** there is currently no admin UI to set a booking's
`deposit_status='paid'` (the only state that stops auto-cancel). A restaurant confirming a real deposit
payment would need that. Flagged as a possible follow-up (not in this track's scope).

**Verification done here:** grep confirms `depositPanelLocked` gates both auto-closers and resets on
close; the claim endpoint is nopriv + booking_ref-keyed + pending-only; `run_autocancel` still cancels
'claimed'. PHP lint not run (no PHP binary) — verified by inspection.

**Status:** Implemented, committed, pushed — FINAL release of the reservation track; awaiting developer
verify on a NON-PROD site (esp. verify step 4: a claimed booking still auto-cancels).

---

## v3.10.66 — Admin "Mark deposit paid" control (closes the deposit loop)

**Endpoint / route + capability check:**
- AJAX action `dd_reservation_mark_deposit_paid` → `DD_Reservations_Module::ajax_mark_deposit_paid()`.
  Registered `DD_Ajax::register( 'dd_reservation_mark_deposit_paid', [...], false )` — **not nopriv**
  (logged-in only; there is no `wp_ajax_nopriv_` hook).
- Auth: `DD_Ajax::verify_nonce( 'nonce', 'dish_dash_admin' )` (the admin nonce the Reservations page
  already prints) **plus** `current_user_can( 'dd_manage_reservations' )`.
- Capability chosen: `dd_manage_reservations` — this is exactly what the Reservations submenu itself
  requires (`add_submenu_page( …, 'dd_manage_reservations', 'dd-reservations', … )`) and what
  `ajax_bulk_action()` already checks. (Note: `ajax_update_status()` is nonce-only with no
  `current_user_can` — I used the stronger, explicit capability check to match the page + bulk action.)
- Keyed on reservation `id`. Guards: booking must EXIST and `deposit_required = 1`. Idempotent —
  updates only when `deposit_status IN ('pending','claimed')` (→ `'paid'`, also stamps
  `deposit_paid_at`); re-tap on an already-`paid` booking is a no-op. Fires `deposit_confirmed_paid`
  tracking. Does NOT unschedule the cron (per brief) — `run_autocancel()` already skips `'paid'`.

**How the deposit state is surfaced in the list:**
The Reservations table already had a **Deposit column** (rendered when `deposit_required` is set) showing
a labelled `deposit_status` + the amount. I extended its label map to include `claimed` →
"🙋 Claimed (unverified)" (previously `claimed` fell through to the raw string). So the restaurant sees
`⏳ Awaiting` / `🙋 Claimed (unverified)` / `✅ Paid` / `✗ Failed` at a glance, and the new
"✅ Mark deposit paid" button appears in the row Actions exactly for the `pending`/`claimed` rows.
The button is gated **per booking** — `deposit_required = 1 AND deposit_status IN ('pending','claimed')`
— NOT on `dd_reservation_deposit_enabled`, so turning deposits off never strands an existing unconfirmed
deposit. `none` / `paid` / `failed` rows show no action. Wired by an inline JS handler that mirrors the
existing `.dd-res-status-btn` pattern (fetch → toast → reload). New amber `.dd-res-action-btn--deposit`
CSS modifier (both action-button blocks in reservations-admin.css).

**Where `status='confirmed'` is written on claim (KNOWN ISSUE — flagged, NOT fixed):**
Investigation finding: **there is NO on-claim code path that writes `status='confirmed'`.** The customer
claim endpoint `ajax_claim_deposit()` (v3.10.65) writes ONLY `deposit_status='claimed'` and never touches
the booking `status`; reservations.js writes no status either. A repo-wide search for reservation
`status` being set to `'confirmed'` finds only:
  - `ajax_update_status()` — the admin "Confirm" button (`data-status="confirmed"`), and
  - `ajax_bulk_action()` — bulk "Confirm".
  (The old `on_deposit_payment_complete()` used to set `status='confirmed'` + `deposit_status='paid'`,
   but it was removed in v3.10.61 and never ran.)
So the observed `status='confirmed'` on a merely-`claimed` booking (RES-20260720-2BA5) was written by a
human clicking Confirm (or bulk-confirm), independent of the deposit state — the UI conflates the
booking-workflow `status` with the payment `deposit_status`. Auto-cancel ignores `status`, so there's no
exploit; but the display is misleading. **Flagged for a future release** (e.g. don't show "Confirmed"
until `deposit_status='paid'`, or relabel/decouple the two). Not changed here, per the brief.

**Boundaries respected:** `run_autocancel()` / the auto-cancel query untouched (read-only); customer
claim endpoint (v3.10.65), deposit QR (v3.10.64), deposit amount logic (v3.10.62), booking flow,
WhatsApp handoff, country picker, PesaPal, orders, cart.js all untouched. No reservation fees added; no
manual cancel/refund actions (both explicitly out of scope).

**Verification done here:** confirmed the row query is `SELECT *` (so `deposit_status`/`deposit_required`
are available for the gate + label); the new endpoint uses the same admin nonce + a stronger capability
than `ajax_update_status`; `deposit_paid_at` column exists (install.php:237). PHP lint not run (no PHP
binary in env) — verified by inspection.

**Status:** Implemented, committed, pushed — awaiting developer verify on a NON-PROD site (esp. verify
step 3: a paid booking does NOT auto-cancel; and step 5: the action still shows after Require Deposit
is turned OFF).

---

## v3.10.67 — Fix opening-hours two-writer collision (footer)

**Root cause:** `dish_dash_opening_hours` had two writers. The Homepage module textarea
(`modules/homepage/class-dd-homepage-module.php:156/920`, multi-line — the correct surface) and a
Template Settings text input (`admin/pages/template-settings.php`) whose hardcoded default
`'Monday – Friday 10 AM – 7 PM'` (formerly template-settings.php:50) was persisted on **every**
Template Settings save via `sanitize_text_field`, silently overwriting the Homepage value. The footer
reads the key at `modules/template/class-dd-template-module.php:861`.

**Fix (Homepage owns the key; Template Settings no longer writes it) — one file,
`admin/pages/template-settings.php`:**
1. Removed the field markup — the `<label>Opening Hours</label>` + `<input type="text"
   name="dish_dash_opening_hours" …>` block (was ~:188-193).
2. Removed the read that pre-filled it — `$opening_hours = get_option( 'dish_dash_opening_hours',
   'Monday – Friday 10 AM – 7 PM' )` (was :50).
3. Removed `'dish_dash_opening_hours'` from the save `$fields` allowlist (was :33). The other three
   keys — `dish_dash_hero_title`, `dish_dash_hero_subtitle`, `dish_dash_hero_image` — are untouched;
   the save loop is otherwise unchanged.
4. Updated the two now-stale "4 keys" comments (header docblock + save-handler comment) to "3 keys"
   and noted why the key was removed.

**Not touched (per brief):** `modules/homepage/class-dd-homepage-module.php`; the footer read site
`class-dd-template-module.php:861`; the unrelated `dd_opening_hours` JSON key; the dead
`dd_footer_show_*` toggles (v3.10.68). **No option value written in code** — no data migration this release.

**Live-value check — could NOT run here.** This is the local repo with no live DB/WP-CLI access.
The developer must run `wp option get dish_dash_opening_hours` on the server:
- If it returns `Monday – Friday 10 AM – 7 PM` → Template Settings won last; the Homepage value is
  gone and must be **re-entered** in Homepage → Footer Section after deploy.
- If it returns the Homepage value → nothing to restore.
The code fix is identical either way.

**Smoke test after deploy (no PHP linter available here):** open Template Settings (opening-hours field
gone; page saves without error and does not alter `dish_dash_opening_hours`); open Homepage → Footer
Section (textarea intact); edit + save Homepage hours; reload a frontend page and confirm the footer
shows the Homepage value.

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.68 — Wire the dead footer column visibility toggles

**Five toggle keys → save handler → footer part (all map cleanly; nothing ambiguous):**

| Option key | Save handler (homepage-module.php) | Field | Footer part now gated |
|---|---|---|---|
| `dd_footer_show_description` | :153 (map), applied :162-169 | :913 | Brand column description `<p class="dd-footer__copy">` |
| `dd_footer_show_social` | :155 | :926 | Brand column social-icons row `<div class="dd-footer__social">` |
| `dd_footer_show_explore` | :157 | :930 | Explore grid column |
| `dd_footer_show_contact` | :158 | :934 | Contact grid column |
| `dd_footer_show_hours` | :159 | :938 | Opening Hours grid column |

**Default when unset = SHOW.** Verified against the form: `DD_Homepage_Module::checked()`
(`homepage-module.php:222-225`) reads `get_option($key, '1')` → a fresh install renders every box
**checked**. The footer now uses the same default: `get_option('dd_footer_show_*', '1') === '1'`. So on a
fresh install the footer shows all columns and the form UI agrees.

**Fix — read-side only, `modules/template/class-dd-template-module.php` `inject_global_footer()`:**
- Added five `$show_*` reads just before the `<footer>` markup.
- Wrapped each part in `<?php if ( $show_* ) : ?> … <?php endif; ?>`:
  description `<p>`, social `<div>` (both inside the brand column), and the Explore / Contact / Hours
  grid `<div>`s.

**Point 4 — empty-shell check (decision):** the grid wrapper is **kept, not gated**. The brand
column's logo/name (`if($dd_logo)/else` badge — no toggle) and the copyright bar (`.dd-footer__bottom`
— no toggle) always render, so even with all five toggles OFF the footer still shows the brand + © line
— never an empty shell. Gating the wrapper would risk hiding the copyright, which is out of scope.

**Not touched (per brief):** the Homepage form (read-side wiring only); the copyright line (no toggle);
Explore column literals; hero pill; notification hardcodes; the baked-in tagline; the `dd_opening_hours`
JSON key. **No option values written in code.**

**Smoke test (developer, after deploy — purge LiteSpeed first):** all five on → footer identical to now;
toggle each off one at a time → only that part disappears, no layout break; brand logo/name + copyright
remain even with all off; toggle all back on → footer restored.

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.69 — Footer Explore column reads a selectable WP nav menu

**Task:** Replace the footer's hardcoded Explore link list with a selectable WordPress nav menu.

**Scope:** 2 files.

### Files changed

1. **`modules/homepage/class-dd-homepage-module.php`** (admin form + save)
   - Save `$fields` map: added `'dd_footer_explore_menu' => 'absint',` (right after `'dd_footer_show_explore' => 'checkbox',`).
   - Footer Section markup: added an "Explore Column Menu" `<select name="dd_footer_explore_menu" class="dd-hp-select">`
     under the "Show Explore Column" checkbox row. First option `— None —` value `0`; then one `<option>` per
     `wp_get_nav_menus()` (`term_id` / `name`). Selected state via the existing `$this->select('dd_footer_explore_menu', …, 0)`
     helper. Helper note: "Manage menus in Appearance → Menus."

2. **`modules/template/class-dd-template-module.php`** (`inject_global_footer()`, read side)
   - Deleted the hardcoded Explore `<ul><li><a>` list (Home / Our Menu / Reserve Table / Track Order / Privacy Policy / Refund & Returns).
   - Added, next to the `$show_*` reads:
     ```php
     $explore_menu_id   = absint( get_option( 'dd_footer_explore_menu', 0 ) );
     $explore_menu_html = '';
     if ( $show_explore && $explore_menu_id && is_nav_menu( $explore_menu_id ) ) {
         $explore_menu_html = wp_nav_menu( [
             'menu'        => $explore_menu_id,
             'container'   => false,
             'echo'        => false,
             'fallback_cb' => false,
             'depth'       => 1,
             'menu_class'  => 'dd-footer__list',
         ] );
     }
     ```
   - Explore column now gated on `if ( $explore_menu_html ) :` and echoes `$explore_menu_html` (phpcs-ignore — wp_nav_menu returns safe escaped markup).

### Composed condition (as required by the brief)

The Explore column renders **iff `$explore_menu_html` is non-empty**, which is true only when **all** of:
- `$show_explore` (the `dd_footer_show_explore` toggle) is ON, **and**
- `$explore_menu_id` is truthy (a menu was picked, not 0/None), **and**
- `is_nav_menu( $explore_menu_id )` (the menu still exists), **and**
- `wp_nav_menu()` actually produced markup (`fallback_cb => false` → empty string if the menu has no items).

**No fallback:** 0 / empty / a deleted menu / toggle off → `$explore_menu_html` stays `''` → the entire Explore
column (heading + list) is skipped. The old hardcoded links never appear.

### CSS — which classes, how applied

Existing footer CSS in `theme.css` uses **descendant selectors**: `.dd-footer__list li` (~:1587) and `.dd-footer__list a` (~:1593).
`wp_nav_menu` outputs `<ul class="dd-footer__list"><li ...><a ...>…</a></li></ul>` when passed `menu_class => 'dd-footer__list'`,
so the existing rules match the generated `<li>`/`<a>` exactly. **No new CSS written**, no per-link class needed, no
`nav_menu_link_attributes` filter. The `<div class="dd-footer__heading">Explore</div>` label is unchanged.

### Not touched
- No new theme menu **location** registered (uses an existing menu directly by term ID).
- `dd_footer_show_explore` toggle kept — both the toggle and a valid menu must pass.
- Other footer columns / copyright / v3.10.68 toggle wiring / v3.10.67 opening-hours fix.
- No option values written from code.

### Verification
- By inspection (no PHP linter in this environment; developer smoke-tests live).
- Version bumped 3.10.68 → 3.10.69 in both locations in `dish-dash.php`; CLAUDE.md updated (Last updated, Current State, changelog).

**Smoke test (developer, after deploy — purge LiteSpeed first):**
1. Appearance → Menus: create a menu (e.g. "Footer Explore") with a few items.
2. Dish Dash → Homepage → Footer Section: pick it in "Explore Column Menu", ensure "Show Explore Column" is on, save.
3. Front end: footer Explore column shows the menu's items, styled like before.
4. Set the select back to "— None —" (or turn off Show Explore) → the Explore column disappears entirely (no default links).

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.70 — Split tagline from restaurant name + footer attribution selector

**Task:** Give the tagline its own Brand Identity field (it was baked into the restaurant name), and make the footer attribution selectable. Year logic untouched.

**Scope:** 2 files.

### What the copyright markup looked like (as found)

`modules/template/class-dd-template-module.php`, inside `.dd-footer__bottom` → `.dd-container`:
```php
<p>&copy; <?php echo date( 'Y' ); ?> <?php echo esc_html( $dd_name ); ?> &mdash; Built by <strong>Fri Soft Ltd</strong></p>
```
So the **"Fri Soft Ltd" portion already sat in its own `<strong>`** — "Built by " is plain text, only the company
name is bold. Year is `date('Y')` (dynamic). Both attribution strings in the new code keep that pattern: the prefix
("Built by " / "Powered by ") is plain, the name ("Fri Soft Ltd" / "Dish Dash") is wrapped in `<strong>`.

### Files changed

1. **`admin/pages/brand-identity.php`**
   - `$fields` allowlist: added `'dish_dash_restaurant_tagline'` (saved via `sanitize_text_field` under the existing `dd_brand_identity_save` nonce, exactly like its neighbours).
   - **Separate** whitelist save block for attribution (NOT in `$fields`, because it must not pass raw input through):
     ```php
     if ( isset( $_POST['dish_dash_footer_attribution'] ) ) {
         $attr = sanitize_text_field( wp_unslash( $_POST['dish_dash_footer_attribution'] ) );
         if ( ! in_array( $attr, array( 'frisoft', 'dishdash', 'none' ), true ) ) {
             $attr = 'frisoft';
         }
         update_option( 'dish_dash_footer_attribution', $attr );
     }
     ```
   - Current-value reads: `$restaurant_tagline` (default `''`), `$footer_attribution` (default `'frisoft'`).
   - Markup after the Restaurant Name field: a **Tagline** text input (helper: "Optional. Shown after the restaurant
     name in the footer copyright.") and a **Footer Attribution** `<select>` with three options — Built by Fri Soft Ltd
     (`frisoft`) / Powered by Dish Dash (`dishdash`) / None (`none`) — selected-state via `selected()`.

2. **`modules/template/class-dd-template-module.php`** (`inject_global_footer()`)
   - Added reads: `$dd_tagline = get_option('dish_dash_restaurant_tagline','')` and `$dd_attrib = get_option('dish_dash_footer_attribution','frisoft')`.
   - Recomposed the copyright `<p>` (markup/classes/entities preserved):
     ```php
     <p>&copy; <?php echo date( 'Y' ); ?> <?php echo esc_html( $dd_name ); ?><?php
         if ( '' !== trim( (string) $dd_tagline ) ) {
             echo ' - ' . esc_html( $dd_tagline );
         }
         if ( 'dishdash' === $dd_attrib ) {
             echo ' &mdash; Powered by <strong>Dish Dash</strong>';
         } elseif ( 'none' !== $dd_attrib ) {
             echo ' &mdash; Built by <strong>Fri Soft Ltd</strong>';
         }
     ?></p>
     ```
   - Deleted the hardcoded `Built by Fri Soft Ltd` literal.

### Composition rules (as specified)
- **Name** — `esc_html( $dd_name )`, existing read, unchanged.
- **Tagline** — appended as ` - ` + tagline **only when non-empty** (`trim()` guard); empty → no separator, no tagline.
- **Attribution** — the rendered strings live in the read site, DB stores only the key. `frisoft`/default →
  `— Built by <strong>Fri Soft Ltd</strong>`; `dishdash` → `— Powered by <strong>Dish Dash</strong>`; `none` →
  nothing (no `—` separator either).
- The `elseif ( 'none' !== $dd_attrib )` branch renders the Fri Soft string for `frisoft` **and** any unexpected value
  (defence-in-depth; save already whitelists so unexpected shouldn't occur).
- Name and tagline escaped on output; the two attribution strings are fixed literals (no user data).

### No data migration
No option values written from code. Until the developer shortens Restaurant Name and moves the tagline into the new
field, the copyright renders the full old string (`Khana Khazana - The Authentic Indian Restaurant`) as `$dd_name` —
**cosmetically identical to now**.

### Not touched
- Year logic (`date('Y')`).
- Hero pill, notification "Khana Khazana" hardcodes (later releases).
- Every **other** surface reading `dish_dash_restaurant_name` (header, emails) — this release only splits the FOOTER's
  use of the name. After the developer shortens the name, those surfaces will correctly show the shortened name (that
  is the intended, in-scope consequence of editing the shared option — no code there was changed).

### Verification
- By inspection (no PHP linter in this environment; developer smoke-tests live).
- Version bumped 3.10.69 → 3.10.70 (both spots in `dish-dash.php`); CLAUDE.md updated (Last updated, Current State, changelog).

**Smoke test (developer, after deploy — purge LiteSpeed first):**
1. Brand Identity shows the Tagline field + Attribution select (default "Built by Fri Soft Ltd").
2. Before editing: copyright identical to now.
3. Name → `Khana Khazana`, Tagline → `The Authentic Indian Restaurant`, save → copyright reads as before.
4. Clear Tagline → `© 2026 Khana Khazana — Built by Fri Soft Ltd` (no stray dash).
5. Attribution → Dish Dash → `— Powered by Dish Dash`.
6. Attribution → None → line ends after name/tagline, no trailing dash.
7. Header + emails reflect the shortened name.

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.71 — Editable hero pill

**Task:** Replace the hardcoded "Authentic Indian Dining" hero pill with an editable text field + visibility toggle.

**Scope:** 2 files.

### Files changed

1. **`modules/homepage/class-dd-homepage-module.php`** (Hero Section form + save)
   - `$fields` allowlist: added `'dd_hero_pill_show' => 'checkbox'` and `'dd_hero_pill_text' => 'sanitize_text_field'`
     (in the `// 2. Hero` block, before `dish_dash_hero_title`). The existing save loop handles them:
     `checkbox` → `isset($_POST[$key]) ? '1' : '0'` (unchecked saves '0'); text → `sanitize_text_field($_POST[$key] ?? '')`.
   - Markup: a **Hero Pill** field placed **before** Hero Title in the Hero Section body — an inline "Show" toggle
     (`dd_hero_pill_show`, via the existing `checked()` helper, mirroring the Feature Chips toggle pattern) plus a
     wide text input (`dd_hero_pill_text`) and the hint "Small badge shown above the hero title."

2. **`templates/page-dishdash.php`** (read side)
   - Added to the `// 2. Hero` reads: `$dd_pill_show = get_option('dd_hero_pill_show','1') === '1'` and
     `$dd_pill_text = get_option('dd_hero_pill_text','')`.
   - Deleted the literal `<span class="dd-pill">Authentic Indian Dining</span>`.
   - Replaced with:
     ```php
     <?php if ( $dd_pill_show && '' !== trim( (string) $dd_pill_text ) ) : ?>
     <span class="dd-pill"><?php echo esc_html( $dd_pill_text ); ?></span>
     <?php endif; ?>
     ```

### How the two conditions compose (as requested)

The pill renders **iff BOTH**: `$dd_pill_show` (the `dd_hero_pill_show` toggle, default on) is true **AND**
`trim($dd_pill_text)` is non-empty. A single `&&`:
- toggle **off** → no pill (regardless of text);
- toggle **on** but text **empty/whitespace** → no pill (avoids an empty badge);
- toggle **on** + non-empty text → pill renders with `esc_html($dd_pill_text)`.

The `.dd-pill` markup and class are byte-identical to before; `theme.css:603` untouched. **No new CSS.**

### No data migration
No option values written from code. `dd_hero_pill_text` defaults to `''`, so **on deploy the pill disappears until
the developer types the text** — expected, and called out in the release description below.

### Not touched
- Dead footer/social/hours variable block in page-dishdash.php (`:94-96, :160-167, :232`) — separate release.
- Hero Title / Subtitle / Feature Chips / CTA buttons.
- Brand Identity tagline (`dish_dash_restaurant_tagline`, v3.10.70) — independent field/value.
- Notification hardcodes.

### Verification
- By inspection (no PHP linter in this environment; developer smoke-tests live).
- Version bumped 3.10.70 → 3.10.71 (both spots in `dish-dash.php`); CLAUDE.md updated (Last updated, Current State, changelog).

**Smoke test (developer, after deploy — purge LiteSpeed first):**
1. Homepage → Hero Section shows the new Hero Pill toggle (checked) + empty text field.
2. Before typing: no pill on the homepage, hero otherwise unchanged, no layout gap.
3. Type "Authentic Indian Dining", save, purge → pill renders exactly as before.
4. Clear the text → pill gone.
5. Text present, toggle off → pill gone.
6. `curl -s https://dishdash.khanakhazana.rw | grep -c dd-pill` → 1 when set, 0 when not.

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.72 — R1: order notification emails read the restaurant name option

**Task:** Replace the six hardcoded "Khana Khazana" literals in the order-notification path with the same
`get_option` read the reservation path already uses. One file.

### Pattern matched (reported before editing)

The reservation builder (`class-dd-reservations-module.php`) reads **once into `$restaurant`** with fallback
`'Khana Khazana'` (`:204`), then re-uses the variable: subject `sprintf('[%s] New Reservation — %s', $restaurant,…)`
(`:209`, no escaping), footer `esc_html($restaurant)` (`:289`, HTML body), From `'From: ' . $restaurant . ' <…>'`
(`:300`, raw in header). I matched this exactly — no second pattern invented.

### File changed — `modules/orders/class-dd-notifications.php` (6 sites, 3 methods)

**`notify_admin_email()`** — added `$restaurant = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );`
right after the `$admin_email` guard, then:
- `:183` subject → `sprintf( '[%s] New Order %s — %s RWF', $restaurant, … )` — **raw** (plain-text subject; mirrors reservation `:209`).
- `:207` body sub-line → `' . esc_html( $restaurant ) . ' &mdash; …` — **`esc_html()`** (HTML body).
- `:227` footer → `Dish Dash &mdash; ' . esc_html( $restaurant ) . ' ordering system` — **`esc_html()`** (HTML body). "Dish Dash" product word left intact (R4).
- `:233` From-name → `'From: ' . $restaurant . ' <' . get_option( 'woocommerce_email_from_address', $admin_email ) . '>'` — **raw** in the header (mirrors reservation `:300`; from-address read untouched).

**`build_customer_whatsapp_url()`** — added the same read, then `'✅ Order Confirmed! — ' . $restaurant` — **raw** (plain text inside the rawurlencoded wa.me message).

**`build_admin_whatsapp_url()`** — added the same read, then `'🔔 New Order ' . $order['order_number'] . ' — ' . $restaurant` — **raw** (plain text).

### Escaping rationale (as requested)
- **Subject, From-name, WhatsApp** → raw `$restaurant`. No HTML context; the reservation path does the same, and WhatsApp text is `rawurlencode`d downstream.
- **HTML email body** (sub-line + footer) → `esc_html( $restaurant )`, matching the reservation footer.

### Verification
- `grep "Khana Khazana"` on the file now returns **only** the four `get_option( …, 'Khana Khazana' )` fallback defaults (`:44` reservation, `:183`/`:264`/`:296` the three new reads) — zero hardcoded output literals remain.
- By inspection (no PHP linter in this environment; developer smoke-tests live).
- Version bumped 3.10.71 → 3.10.72 (both spots in `dish-dash.php`); CLAUDE.md updated (Last updated, Current State, changelog).

### Not touched (per brief)
- `orders-module.php:151` birthday WhatsApp — R2.
- `#65040d` brand hex — R3.
- "Dish Dash" product word in footers — R4 (decision-gated).
- Customer email path / missing `templates/emails/*.php` / dead `notify_restaurant()` — separate ticket (no customer-email recipient is collected anyway).

**Smoke test (developer, after deploy):**
1. Place a test order → admin email subject, body sub-line, footer, and From-name all read "Khana Khazana" (no tagline, no stray separator).
2. Compare to a reservation admin email — now consistent.
3. Change Brand Identity name, place another test order → the email follows the new name.

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.73 — R2: birthday WhatsApp sign-off reads the restaurant name

**Task:** Replace the last hardcoded "Khana Khazana" output literal in the orders module —
`orders-module.php:151`, the birthday-ask WhatsApp sign-off (WP-Cron ~2 min after a customer's first order).

### Pattern matched (reported before editing)
`class-dd-notifications.php:264`/`:296` (the R1 WhatsApp builders):
`$restaurant = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );` — read once, used **raw** in the
wa.me message (rawurlencoded downstream).

### File changed — `modules/orders/class-dd-orders-module.php` (`send_birthday_whatsapp()`)
- Added `$restaurant = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );` immediately before the `$msg` build.
- Sign-off line: `'— Khana Khazana 🍽'` → `'— ' . $restaurant . ' 🍽'` (emoji unchanged), raw per the WhatsApp pattern.

### Verification
- `grep -rn "Khana Khazana" modules/orders/` → only `get_option( …, 'Khana Khazana' )` fallback defaults remain
  (`class-dd-notifications.php:44/183/264/296` + `orders-module.php:146`). **Zero output literals left in the module.**
- By inspection (no PHP linter; cron-triggered so not directly observable — developer may place a first order from a new number and watch ~2 min later).
- Version bumped 3.10.72 → 3.10.73 (both spots); CLAUDE.md updated.

### Other hardcodes spotted (reported, NOT fixed — per Rule 1a)
None new in `send_birthday_whatsapp()`. The already-known R3 (`#65040d`) and R4 ("Dish Dash" product word) live in
other files and were left untouched.

### Not touched
- Anything else in `orders-module.php`; R3 (`#65040d`); R4 ("Dish Dash").

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.74 — R4: email footers reuse the footer attribution setting

**Task:** Replace the hardcoded "Dish Dash" prefix in the order + reservation admin email footers with the
existing `dish_dash_footer_attribution` option (v3.10.70). Two files.

### Reported before editing
- **Footer strings:** order email `class-dd-notifications.php:230` (`Dish Dash &mdash; … ordering system`, HTML
  entity `&mdash;`); reservation email `class-dd-reservations-module.php:289` (`Dish Dash — … reservation
  system`, literal em-dash `—`).
- **v3.10.70 composition** (`class-dd-template-module.php`): read `:857` `get_option('dish_dash_footer_attribution','frisoft')`
  (fallback `'frisoft'`); mapping `:966-970` `if('dishdash')… elseif('none' !== $dd_attrib)…` (so `none` → nothing),
  `&mdash;` separator prepended.
- **Duplicated, not shared:** the two footers are inline literals in two separate modules — no shared helper.
  Kept duplicated (no refactor this release, per brief).

### Composition (matched v3.10.70's read/fallback/branching; email-specific output strings)
The site footer renders the **verb form** ("Powered by Dish Dash" / "Built by Fri Soft Ltd") because its grammar
is `© {year} {name} — {attribution}`. The email grammar is `{attribution} — {name} {ordering|reservation}
system`, so per the brief's exact targets I map to a **bare-name prefix**:
```php
$attrib        = get_option( 'dish_dash_footer_attribution', 'frisoft' );
$attrib_prefix = '';
if ( 'dishdash' === $attrib ) {
    $attrib_prefix = 'Dish Dash {sep} ';
} elseif ( 'none' !== $attrib ) {
    $attrib_prefix = 'Fri Soft Ltd {sep} ';
}
```
`{sep}` = `&mdash;` in the order email, `—` in the reservation email (each footer's original glyph preserved).
Footer line becomes `' . $attrib_prefix . esc_html( $restaurant ) . ' ordering system'` (and `reservation system`).

### Resulting output
| Setting | Order email footer | Reservation email footer |
|---|---|---|
| `frisoft` (default) | `Fri Soft Ltd — Khana Khazana ordering system` | `Fri Soft Ltd — Khana Khazana reservation system` |
| `dishdash` | `Dish Dash — Khana Khazana ordering system` | `Dish Dash — Khana Khazana reservation system` |
| `none` (live) | `Khana Khazana ordering system` | `Khana Khazana reservation system` |

`none` → `$attrib_prefix` is `''`, so no prefix, no separator, no leading dash, no double space.

### Escaping
Brand prefixes ("Dish Dash" / "Fri Soft Ltd") are fixed literals → no escaping (matches the site footer, which
echoes them raw). `$restaurant` stays `esc_html()`. HTML-body context preserved.

### Files changed
- `modules/orders/class-dd-notifications.php` — `$attrib_prefix` block after the `$restaurant` read in `notify_admin_email()`; footer line `:230`.
- `modules/reservations/class-dd-reservations-module.php` — `$attrib_prefix` block after the `$restaurant` read in `send_admin_email()`; footer line `:289`.

### Not touched
- Site footer read site in `class-dd-template-module.php` (this release only adds email consumers of the option).
- Brand Identity form (field already exists), `#65040d` (R3), customer email path, SMTP from-name reseeding, `install.php:481`.
- No new settings, no data migration, no option values written in code.

### Verification
- By inspection (no PHP linter). Version bumped 3.10.73 → 3.10.74 (both spots); CLAUDE.md updated.

**Smoke test (developer, after deploy):**
1. Live setting is `None` → place a test order → footer `Khana Khazana ordering system` (no leading dash); trigger a test reservation → `Khana Khazana reservation system`.
2. Set attribution → Dish Dash → both footers regain the `Dish Dash — …` prefix.
3. Set → Fri Soft Ltd → both read `Fri Soft Ltd — …`.
4. Confirm the site footer copyright still follows the same setting (unchanged code path).

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.75 — R3: email brand color reads the primary color option

**Task:** Replace the hardcoded `#65040d` brand color in the email path with `dish_dash_primary_color`. Two
independent modules, no shared helper.

### Reported before editing
- **Sites:** order email `class-dd-notifications.php:218` (header-bar `background`) + `:228` (Total-amount
  `color`); reservation email `class-dd-reservations-module.php:207` (`$primary = '#65040d'`, reused at `:249`
  header bg, `:257` booking-ref accent, `:292` CTA button bg).
- **Brand Identity read pattern** (`brand-identity.php:72`): `get_option( 'dish_dash_primary_color', '#65040d' )`
  — fallback `'#65040d'`. Matched exactly.
- **Escaping:** all occurrences are inside inline `style="…"` attributes → `esc_attr()`.

### Files changed
- `modules/orders/class-dd-notifications.php` — added `$primary_color = get_option( 'dish_dash_primary_color', '#65040d' );`
  next to the `$restaurant` read; both inline literals now embed `' . esc_attr( $primary_color ) . '`.
- `modules/reservations/class-dd-reservations-module.php` — `$primary = '#65040d';` → `$primary = esc_attr( get_option( 'dish_dash_primary_color', '#65040d' ) );`
  (one line; covers all three `$primary` usages, each a style-attribute context).

### Escaping used (as requested)
`esc_attr()` in both files — order email at each of the 2 embed sites (mirrors how the file reads `$restaurant`
raw then escapes at use); reservation email once at the assignment, valid because `$primary` is used **only** in
`style="…"` attributes (`:249/:257/:292`).

### Verification
- `grep "#65040d"` on both files now returns **only** the two `get_option( …, '#65040d' )` fallback defaults — zero hardcoded output literals.
- By inspection (no PHP linter). Version bumped 3.10.74 → 3.10.75 (both spots); CLAUDE.md updated.

### Other hardcoded hex found (reported, NOT fixed — per brief; none is the primary `#65040d`)
- Order email: `#f0e8e0, #777, #27ae60, #eee, #fff, #128276, #f9f5f0, #aaa`.
- Reservation email: `#6E5B4C, #221B19, #F5EFE6, #FBF7F1, #E6C9CC, #EADFCE, #FBE8C8, #b45309, #F0E7D8`.

### Not touched
- Any non-`#65040d` color; dark/background color options; customer email path; SMTP reseeding; `install.php:481`.
- No new settings, no data migration, no option values written in code.

**Smoke test (developer, after deploy):**
1. Place a test order → email renders identically to now (option value equals the old literal).
2. Change Brand Identity primary color to green → place another test order → email header + Total follow; trigger a test reservation → header/accent/CTA follow.
3. Change it back to `#65040d`.

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.76 — Reservations R1: status badge reflects an unpaid deposit (display only)

**Task:** When a booking is `status='confirmed'` but its required deposit isn't restaurant-confirmed
(`paid`), the admin list badge must not read as a secured green "Confirmed". Badge render at
`class-dd-reservations-admin.php:373` only.

### Reported before editing
- **Current badge:** `<span class="dd-res-badge dd-res-badge--{$r->status}">{label}</span>`; label from
  `$statuses[$r->status]` (fallback `ucfirst(str_replace('_',' ',…))`); class modifier is the raw status value.
- **Existing badge states** (`reservations-admin.css:217-222`): `--pending` amber `#fef3c7/#92400e`,
  `--confirmed` green `#d1fae5/#065f46`, `--cancelled` grey, `--no_show` red, `--pending_payment` blue,
  `--auto_cancelled` grey (+ `--test` `:482`).
- **CSS support:** the amber `.dd-res-badge--pending` is an existing warning/attention class → reused, **no new
  CSS**. Deliberately did NOT use `--pending_payment` (that touches the R3-flagged phantom label).

### Change — `modules/reservations/class-dd-reservations-admin.php` (`:373` render only)
Compute `$badge_mod`/`$badge_label` before the `<span>`; override only in the exact problem case:
```php
$badge_mod   = $r->status;
$badge_label = $statuses[ $r->status ] ?? ucfirst( str_replace( '_', ' ', $r->status ) );
if ( 'confirmed' === $r->status
     && ! empty( $r->deposit_required )
     && 'paid' !== $r->deposit_status ) {
    $badge_mod   = 'pending';                       // amber attention (existing class)
    $badge_label = 'Confirmed — deposit unpaid';
}
```
Span now emits `dd-res-badge--{$badge_mod}` (`esc_attr`) and `{$badge_label}` (`esc_html`).

### Cases (verified by reading the condition)
| status | deposit_required | deposit_status | Result |
|---|---|---|---|
| confirmed | 1 | pending / claimed / failed | **amber "Confirmed — deposit unpaid"** (new) |
| confirmed | 1 | paid | green "Confirmed" (unchanged) |
| confirmed | 0 | (none) | green "Confirmed" (unchanged) |
| pending / cancelled / no_show / auto_cancelled | any | any | unchanged |

### Not touched (per brief)
- Every writer — Confirm (`admin:55` / `module:374`), bulk (`module:527`), claim, mark-paid, auto-cancel, booking insert. No data written.
- The separate Deposit column (`admin:380-396`) — untouched.
- Confirmation WhatsApp gate (`admin:407`) — R2.
- `pending_payment` / `refunded` phantom labels — R3.
- KPI/analytics/dashboard reads (`admin:161`, `analytics-reservations.php:33/123/125`, `dashboard.php:107/358`) — **no data and no query changed**, so they hold by construction. Confirmed: the badge reads `$r->deposit_required`/`$r->deposit_status`, already SELECTed for the Deposit column → **no query change**.
- No schema, no migration, no new settings, no new CSS.

### Verification
- By inspection (no PHP linter). Version bumped 3.10.75 → 3.10.76 (both spots); CLAUDE.md updated.

**Smoke test (developer — live affected count is 0, so synthesise):**
1. Create a test reservation with a deposit required; Confirm it via the admin button **without** marking the deposit paid → badge reads amber **"Confirmed — deposit unpaid"**, not green.
2. Mark deposit paid → badge returns to green "Confirmed".
3. Confirm a no-deposit booking → badge unchanged (green "Confirmed").
4. A pending booking with an unpaid deposit → badge unchanged ("Pending").
5. Delete the test row afterwards (use the `is_test` flag / bulk).

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.77 — Reservations R2: confirmation WhatsApp reflects an unpaid deposit

**Task:** When a confirmed booking's required deposit isn't `paid`, the "💬 Send Confirmation" WhatsApp must not
promise a secured table. Message text only; button stays enabled.

### Change — `modules/reservations/class-dd-reservations-admin.php` (`:421` confirmed block)
Branched the existing `if ( $r->status === 'confirmed' )` on the **same condition as v3.10.76's badge**:
`status==='confirmed' && ! empty($r->deposit_required) && 'paid' !== $r->deposit_status`.

- **Unpaid required deposit** → new variant:
  ```
  RESERVATION HELD — DEPOSIT PENDING ⏳
  {restaurant}

  Hi {name}, we've reserved your table — it's held pending your deposit.

  Ref: {booking_ref}
  Date: {date_fmt}
  Time: {time} ({session_fmt})
  Guests: {guests} {guest_word}

  Deposit required: {number_format((int)$r->deposit_amount)} RWF
  Your booking is secured once we receive it. Until then, the table may be released.

  Questions? Call us: {admin_phone}      ← only if admin_phone set
  ```
- **Paid or no deposit** → `elseif ( $r->status === 'confirmed' )` → the original "RESERVATION CONFIRMED ✅ …
  your table is booked! 🎉" message, **byte-for-byte** unchanged.

The one approved wording change vs my draft was applied: the closing line is
"Your booking is secured once we receive it. Until then, the table may be released."

### Conformance to the reported patterns
- Same `$restaurant` read (`:414`, v3.10.72 pattern); same Ref/Date/Time/Guests block shape.
- Raw lines → `implode("\n")` → `rawurlencode` downstream (`:472-473`), matching the sibling cancelled/no_show variants.
- Deposit amount via `number_format( (int) $r->deposit_amount )` — the same currency format as the admin Deposit column.
- Button unchanged: `💬 Send Confirmation` (`:475`), enabled in both cases; no disable, no confirm dialog.

### Not touched
- The v3.10.76 badge (condition reused, not modified); every writer (Confirm/bulk/claim/mark-paid/auto-cancel/booking insert); the customer WhatsApp; the cancelled/no_show branches; the Deposit column. No schema/migration/settings.

### Verification
- By inspection (no PHP linter). Version bumped 3.10.76 → 3.10.77 (both spots); CLAUDE.md updated.

**Smoke test (developer):** synthetic deposit booking (live affected count 0) — confirm without marking paid → Send Confirmation produces the HELD variant with the correct amount; mark paid → original message; no-deposit confirmed → original message unchanged.

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.78 — Spice R1: show the variation choice on admin surfaces

**Task:** Admin order WhatsApp, order email, and order modal ignored `order_items.variation`; wire all three to
decode + render it (indented continuation line), reusing the kitchen builder's decode. Additive producer fix so
the readers receive the data.

### Shared decode helper (new) — `class-dd-notifications.php` `variation_lines()`
Mirrors the kitchen builder's decode (`stripslashes`, `'{}'` guard, `json_decode` → `is_array` pairs, else the
plain-text fallback stripping `{}[]"'\`), but returns **un-indented** content lines (`"Spice Level: Extra Hot"`),
`[]` for empty/`{}`/malformed-empty — so each surface indents per its medium. **The kitchen builder is untouched**
(reference only); this is a new sibling method.

### Producers (additive — required, they stripped `variation`)
Both down-maps built items as name/qty/price only, so the readers had no data. Added `'variation' => $item['variation'] ?? ''`:
- `modules/orders/class-dd-orders-module.php:1123-1129` — offline `$notification_data['items']` (source has it via `$summary['items']`).
- `modules/orders/class-dd-notifications.php` `build_from_wc_order()` — online (`SELECT *` already returns it).
Nothing else changed at either site.

### Readers — indented continuation line per key/value pair (§2 format, NOT kitchen inline parens)
- **Admin WhatsApp** (`build_admin_whatsapp_url`): items loop emits `qty× name`, then `'   ' . $vl` (3-space indent, matching the kitchen `'   Note:'` convention) per pair. Raw, `rawurlencode`d downstream.
- **Order email** (`notify_admin_email`): per pair appends `<br><span style="color:#777;font-size:12px;padding-left:16px;">esc_html($vl)</span>` inside the existing name `<td>` — inline style (email convention), **no new CSS**, `esc_html` each pair.
- **Admin modal** (`admin/pages/orders.php`): new JS `ddVariationLines()` replicates the decode (`JSON.parse` in try/catch = malformed fallback; `'{}'` guard; regex strips stray braces/quotes for the plain fallback), rendering one `<div style="padding-left:16px;color:#777;font-size:12px;margin:-2px 0 4px;">` per pair under the item row. `item.variation` is present because `get_order_items()` does `SELECT *`.

### Edge cases (all three)
- empty/NULL → helper returns `[]` → nothing rendered, no blank line.
- `{}` → guard → `[]` → nothing.
- malformed JSON → not an array (PHP) / `JSON.parse` throws (JS) → plain-text fallback; if empty after stripping → nothing.
- unknown keys → rendered generically (`{"Size":"Half"}` → `Size: Half`); no special-casing of "Spice Level".

### Not touched
- Kitchen WhatsApp builder (its inline-parens format stays); customer WhatsApp; the capture path (desktop chips = R2); the 900 variations (R3). No schema, migration, or settings.

### Escaping / decode vs render
Decode logic is shared/identical to the kitchen builder across all readers; only the render format differs (indented, per §2) — decode and render kept as separate concerns per the brief. Email uses `esc_html`; modal matches the surrounding markup (item values are `sanitize_text_field`-sanitized at storage, so no tags survive); WhatsApp raw.

### Verification
- By inspection (no PHP linter). Version bumped 3.10.77 → 3.10.78 (both spots); CLAUDE.md updated.

**Smoke test (developer):** mobile order with Extra Hot → indented line in WhatsApp + email + modal, matching the kitchen; mobile order with no chip tapped → no blank line; desktop order (no variation sent) → unchanged; existing `{"Size":"Half"}` order → renders `Size: Half`.

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.79 — Spice R1-fix: modal decodes variation via the PHP helper (Option B)

**Bug:** the v3.10.78 admin order **modal** showed `Spice Level\":\"Hot` instead of `Spice Level: Hot`. Its JS
`ddVariationLines()` reimplemented the decode but didn't `stripslashes()` before `JSON.parse`; the column stores
slash-escaped JSON (`{\"Spice Level\":\"Hot\"}` — WP `wp_magic_quotes` at POST + `sanitize_text_field` doesn't
strip slashes), so `JSON.parse` threw → plain-text fallback left the interior escaped quotes. WhatsApp + email were
correct because the PHP `variation_lines()` `stripslashes()` first.

**Fix (Option B — single source of truth):**
1. `modules/orders/class-dd-notifications.php` — `variation_lines()` visibility `private static` → **`public static`**. **Body unchanged** → WhatsApp/email output byte-for-byte identical.
2. `modules/orders/class-dd-orders-module.php` `ajax_get_order()` — after the `SELECT *` fetch, attach **additively** `$item->variation_lines = DD_Notifications::variation_lines( $item->variation ?? '' )` per item; no other returned field changed.
3. `admin/pages/orders.php` — **deleted** `ddVariationLines()` entirely; the item loop now renders `( item.variation_lines || [] )` into the same indented `<div style="padding-left:16px;color:#777;font-size:12px;margin:-2px 0 4px;">` per line. Identical visual to v3.10.78, correctly decoded.

**Result:** one proven decode implementation (the PHP helper, shared by WhatsApp/email/modal); the JS duplicate that drifted is gone.

**Edge cases** (via the shared helper): empty / `{}` / malformed → `variation_lines()` returns `[]` → `.forEach` renders nothing, no blank line or artifact. `( item.variation_lines || [] )` guards an undefined property.

**Not touched:** `variation_lines()` body; the WhatsApp/email readers + producers (v3.10.78); the kitchen builder; customer WhatsApp; the write-path slash-escaping (parked — the helper handles it at read time); desktop capture (R2); the 900 variations (R3). No schema/settings.

### Verification
- By inspection (no PHP linter). Version bumped 3.10.78 → 3.10.79 (both spots); CLAUDE.md updated.

**Smoke test (developer):** open an existing order with a spice/`{"Size":"Half"}` variation in the admin modal → shows `Spice Level: Hot` / `Size: Half`, correctly decoded, indented; WhatsApp + email unchanged; an order with no variation → no extra line.

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.80 — Spice R2: desktop product modal captures the variation choice

**Task:** Wire the desktop modal's existing-but-dead attribute pills — data source, add-to-cart, and CSS — so
desktop orders capture the spice/variation choice like mobile. Three inseparable parts.

### Reported before editing
- **`map_product()` normalization** (`class-dd-api.php:560-571`): skip `!get_visible()`; options = taxonomy →
  `wc_get_product_terms($id, $attr->get_name(), ['fields'=>'names'])` else `$attr->get_options()`; emit
  `['name' => wc_attribute_label($attr->get_name(), $product), 'options' => array_values((array)$options)]`.
- **What `frontend.js` expects** (`:1116-1120`): `attr.name` (label) + `attr.options[]` → one pill per option.
  **Shapes match** — no adaptation needed.
- **Payload equivalence:** mobile `selectedAttributes[label] = pill.textContent` (`menu-page.js:397/717`) and
  desktop `ddPmSelected[attrName] = pill.dataset.val` both produce `{"<attr.name>":"<option>"}`, so
  `JSON.stringify` is identical for identical choices (given `attr.name` from the same `wc_attribute_label`).
- **Blast radius:** `dd_get_product` is called only by `frontend.js` (`:921` no-card fetch, `:1069` enrichment) —
  confirmed; mobile uses `map_product`, untouched.
- **CSS reuse:** `.dd-chip` (`theme.css:1005-1021`) is a pill with a ready `.dd-chip.active` state and loads on
  every DishDash page → reused; **zero new CSS**. (`.dd-pm__attr-pill` had no rule; `.dd-mobile-attr-pill` lives
  in `menu-page.css`, not loaded on all desktop surfaces.)

### Part 1 — data source (`dishdash-core/class-dd-ajax.php` `ajax_get_product`)
Replaced the `is_type('variable')`-gated block with `map_product()`'s exact visible-attribute loop, so simple
products now return their attributes. Generic — no `pa_spiciness-level` special-casing.

### Part 2 — wiring (`assets/js/frontend.js`)
- New module-scoped `var ddPmSelected = {};` (`:900`) bridges `fetchProductEnrichment` (module-level) and the Add
  handler (inside `renderModal`) — the smallest change; no modal refactor.
- Reset `ddPmSelected = {}` at each `renderModal()` open (`:944`) — fresh per product; no-attribute products keep `{}`.
- Pill click writes `ddPmSelected[attrName] = pill.dataset.val` (replaced the local `selected`; the "enable Add
  when all groups chosen" logic now reads `ddPmSelected`).
- Modal Add POST gains `variation: JSON.stringify(ddPmSelected)` (`:1025`).

### Part 3 — CSS (reuse, no new rule)
Added `dd-chip` to the pill class (`:1119` → `class="dd-pm__attr-pill dd-chip"`). Base pill styling + selected
highlight come from the existing `.dd-chip` / `.dd-chip.active` (which matches the `active` class the modal
already toggles at `:1137`).

### Validation (free — confirmed matches mobile)
The desktop enrichment already disables Add and re-enables only when every attribute group has a selection
(`:1106-1145`); mobile does the same (`menu-page.js:400-402`). No new validation added. No-attribute products:
Add stays enabled, `ddPmSelected` stays `{}` → `variation` sent as `"{}"` (identical to mobile; `variation_lines()`
guards `'{}'` → nothing displayed).

### Not touched
- Mobile path / `DD_API::map_product()` — the shared normalization was **mirrored, not modified**.
- Display (v3.10.78/79), kitchen builder, customer WhatsApp, the 900 variations (R3), write-path slash-escaping (parked).
- No schema, settings, or migration.

### ⚠️ Still open — separate releases (re-flagged so they stay visible)
- **Desktop `#ddPmNotes` dropped:** the same modal Add handler still does not send the special-instructions
  textarea (`frontend.js:955`) — desktop `note` is never captured. Same bug class as this fix, different field;
  **own release**, deliberately not touched here.
- **Card quick-add** (`.dd-add-btn`, `frontend.js:191`) bypasses the modal, so it can never capture attributes —
  a UX decision (leave as a flat fast-add, or route attribute-bearing products through the modal). Flagged, not designed.

### Verification
- By inspection (no PHP linter). `grep` confirms only `ddPmSelected` remains (no stray `selected`), `variation`
  in the modal POST, and `dd-chip` on the pill. Version bumped 3.10.79 → 3.10.80 (both spots); CLAUDE.md updated.

**Smoke test (developer):** desktop → open a spice product → styled pills, none pre-selected, Add disabled until a
pick; choose Extra Hot + add + order → admin WhatsApp/email/modal show `Spice Level: Extra Hot`; a desktop order
and a mobile order of the same dish store an identical `variation`; a no-attribute product → no pills, Add works
immediately; mobile unchanged.

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.81 — Notes R1: capture special instructions + clean the kitchen display

**Task:** Wire the desktop/homepage modal's `#ddPmNotes` textarea into the order (never read today) and
`stripslashes` the kitchen reader so the first captured note displays cleanly. Two inseparable parts.

### Reported before editing
- **Sibling wiring** (v3.10.80): `variation: JSON.stringify(ddPmSelected)` in the modal Add handler's
  `body: new URLSearchParams({...})` (`frontend.js:1025`). The `note` field is added right beside it.
- **Server field name:** `class-dd-cart.php:242` — `'note' => sanitize_textarea_field($_POST['note'] ?? '')`.
  POST field = **`note`**. Matched.
- **Kitchen reader verbatim:** `notifications.php:434` — `$lines[] = '   Note: ' . $item['special_note'];`
  (guarded by `:433`).

### Part 1 — capture (`assets/js/frontend.js`, modal Add handler)
Added `var pmNotes = ($('ddPmNotes') || {}).value || '';` before the fetch, and `note: pmNotes` in the POST body
directly under the `variation:` line. Empty textarea → `''` (same as the prior hardcoded behaviour on the mobile
app). The write path (`dd_cart_add` → cart `:99` → `insert_order_items` `special_note` `orders-module.php:447`)
already persists it; no server change needed to store it.

### Part 2 — clean display (`modules/orders/class-dd-notifications.php`, kitchen builder)
`'   Note: ' . $item['special_note']` → `'   Note: ' . stripslashes( $item['special_note'] )`. `wp_magic_quotes()`
slashes `$_POST` and `sanitize_textarea_field` doesn't strip them, so a note with an apostrophe/quote stores as
`\'`/`\"`; `stripslashes` on display removes the artifact (same root cause fixed for `variation` in v3.10.79).
Guard and format otherwise unchanged; raw after stripping (plain wa.me text, `rawurlencode`d downstream).

### Why together
The kitchen WhatsApp is the **only** reader of `special_note` today. Shipping capture without the stripslashes fix
would mean the very first captured note reaches the kitchen with stray backslashes. Both parts land in one release.

### Not touched (per brief)
- The variation path (v3.10.80) — only a sibling POST field was added; `ddPmSelected` wiring untouched.
- Admin WhatsApp / order email / admin modal — **R2**: they don't carry `special_note` (the two notification
  producers would need it, same additive shape as v3.10.78 did for `variation`).
- The `/restaurant-menu/` mobile app (`grid.php` has no notes field; `menu-page.js:719` still sends `note:''`) — **R3**, additive.
- The per-order `dishdash_orders.special_instructions` field — different writer, not the modal note.
- No schema, settings, or migration (column `special_note` already exists).

### Verification
- By inspection (no PHP linter). Version bumped 3.10.80 → 3.10.81 (both spots); CLAUDE.md updated.

**Smoke test (developer):** homepage modal → type `no onions - it's for an allergy` (apostrophe) → add → order →
kitchen WhatsApp shows it with **no** backslash before the apostrophe; `SELECT special_note … ORDER BY id DESC
LIMIT 1` shows the note stored; empty textarea → no change/artifacts; spice still captures + displays everywhere.

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.82 — Cart dedup: items with a note never merge (Option 2)

**Task:** After R1 started capturing notes, `add()`'s dedup dropped a new note when the same dish+variation was
already in the cart (`item_key()` excludes `note`). Make noted items never merge. One file, `add()` only.

### Reported before editing
- **Merge branch verbatim** (`class-dd-cart.php:86-101`): `$key = item_key($item)` → `if (isset($cart[$key]))`
  qty++ (`:88-89`) → else store full line incl. `note` (`:91-100`).
- **Field & empty test:** incoming field is `$item['note']` (already `sanitize_textarea_field`'d by `ajax_add`);
  "empty" tested **after `trim()`** so whitespace-only = no note.
- **Uniqueness:** `item_key()` unchanged; a noted line's key = `item_key($item) . '-' . uniqid('', true)`.

### Change — `modules/orders/class-dd-cart.php` `add()` (only)
Before the dedup branch:
```php
if ( '' !== trim( (string) ( $item['note'] ?? '' ) ) ) {
    $key = $this->item_key( $item ) . '-' . uniqid( '', true );  // noted → unique key → new line
} else {
    $key = $this->item_key( $item );                             // noteless → dedup as today
}
```
The existing `if ( isset( $cart[$key] ) ) { qty++ } else { …store line incl. note… }` is unchanged. For a noted
item the unique key can't already exist → always the else branch → a fresh line carrying the note.

### Why the uniqueness is correct (the subtle part)
`uniqid('', true)` returns a microtime-based id with an extra random suffix — unique per call, so two identical
noted items (same id + variation + note) produce **different** keys and therefore **two** cart lines, never a
collision. Each line is stored under its own key; `summary()` echoes it as `['key' => …]`; `cart.js` renders
`data-key="…"`; the qty stepper (`dd_cart_update`) and remove (`dd_cart_remove`) send that key back to
`update()`/`remove()`, which address `$cart[$key]` directly — so both work **per noted line**. The stored note
uses the else-branch's `sanitize_textarea_field($item['note'])` (original value, not the trimmed test copy).

### Not touched
- `item_key()`'s **formula** (only the key-selection decision in `add()` changed).
- `update()`, `remove()`, `summary()` — key-agnostic, unaffected.
- The **variation** path — variation stays in the key; identical-variation **noteless** items still merge.
- R1 note capture (`frontend.js`), the R2 display gap, and the `/restaurant-menu/` app (same backend, always
  `note:''` → dedups as before, unaffected).
- No schema, settings, or migration. In-flight cart transients are safe — keys are stored, not recomputed; worst
  case a customer mid-session sees a one-time non-merge across the deploy (no corruption).

### Verification
- By inspection (no PHP linter). Version bumped 3.10.81 → 3.10.82 (both spots); CLAUDE.md updated.

**Smoke test (developer):** empty cart → add a dish with note "no onions" (1 line) → add the same dish with note
"extra spicy" (**2 separate lines**, each note intact); qty-stepper the first noted line (only it changes); remove
one noted line (only it goes); add a plain no-note dish twice (merges to qty 2); place order → both noted lines
land in `order_items` with distinct notes; noteless spice items still merge.

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---

## v3.10.83 — Notes R2: show special_note on admin WhatsApp, email, and modal

**Task:** Render the captured `special_note` on the admin order WhatsApp, order email, and admin modal — mirroring
the v3.10.78/79 variation fix (producers carry it + three readers render it, stripslashes-cleaned).

### Reported before editing
- **Producers — source-key divergence (the important find):** the two notification producers have *different*
  source shapes. Online `build_from_wc_order` (`notifications.php`) reads a `SELECT *` DB row → the note column is
  **`special_note`**. Offline `$notification_data` (`orders-module.php:1123-1131`) maps `$summary['items']` where
  the **cart** stores the note under key **`note`** (`cart.php:99`), *not* `special_note`. So the output key is
  normalized to `special_note` on both, but the **source differs** — online `$item['special_note']`, offline
  `$item['note']`. The variation fix didn't hit this because the cart key *is* `variation` on both sides; writing
  the brief's literal `$item['special_note']` on the offline path would have shipped an always-empty note on the
  common (COD/offline) path.
- **Kitchen label:** `'   Note: ' . stripslashes( $item['special_note'] )` (`notifications.php`) — label `Note:`,
  3-space indent. All four surfaces now use `Note:`.
- **Modal escaping:** orders.php's JS `esc()` (`:1018`) only escapes `"` — insufficient for free-text content.

### Shared cleaning helper (new)
`DD_Notifications::clean_note( $note )` (public static) = `stripslashes((string)$note)`, `''` for
empty/whitespace. Plain text — **no JSON decode** (unlike `variation_lines`). One cleaning impl for the three new
surfaces; the **kitchen reader is untouched** (keeps its own inline stripslashes, per brief). Escaping is
per-surface (context-specific): WhatsApp raw, email + modal `esc_html`.

### Producers (additive)
- Online `build_from_wc_order`: `'special_note' => $item['special_note'] ?? ''`.
- Offline `$notification_data` (`orders-module.php`): `'special_note' => $item['note'] ?? ''` (cart key).

### Readers (indented `Note:` line, after the spice line, only when non-empty)
- **Admin WhatsApp** (`build_admin_whatsapp_url`): after the variation loop, `$item_lines[] = '   Note: ' . clean_note($i['special_note'])` — raw, `rawurlencode`d downstream.
- **Order email** (`notify_admin_email`): `$note_html = '<br><span style="…padding-left:16px;">Note: ' . esc_html( clean_note($item['special_note']) ) . '</span>'` appended after `$var_html` (added a 4th `%s` to the row sprintf). `esc_html` because a note can contain `<`/`&`.
- **Admin modal**: `ajax_get_order` attaches `$item->special_note = esc_html( DD_Notifications::clean_note( $item->special_note ?? '' ) )` (server-side clean + escape, matching v3.10.79's PHP-single-source pattern); the modal renders `item.special_note` raw in a `<div style="padding-left:16px;…">Note: …</div>` after the variation lines.

### Edge cases (all surfaces)
- empty/NULL → `clean_note` returns `''` → nothing rendered, no blank line (`if ('' !== …)` / `if (item.special_note)`).
- apostrophe → stripslashes-clean, no stray backslash (v3.10.79 lesson).
- `<` / `&` → `esc_html` in email + modal (server-side); WhatsApp is plain text.
- note but no spice → the variation loop produces nothing, so only the `Note:` line renders — no orphan spice line.

### Not touched
- Kitchen WhatsApp reader (reference only); the variation rendering (v3.10.78/79); R1 capture; dedup (v3.10.82);
  the `/restaurant-menu/` app (Notes R3). No schema, settings, or migration.

### Verification
- By inspection (no PHP linter). Version bumped 3.10.82 → 3.10.83 (both spots); CLAUDE.md updated.

**Smoke test (developer):** order with a note containing an apostrophe → admin WhatsApp + email + modal all show
`Note: …` cleanly (no backslash); order with spice **and** note → both lines, spice then note; note-only order →
`Note:` alone; neither → unchanged, no blank lines; kitchen WhatsApp still correct.

**Status:** Implemented, committed, pushed — awaiting developer deploy + verify.

---
---

# Report — PesaPal server-side IPN order creation

**Brief:** Create the DD order from PesaPal's server-to-server IPN, not from the
client-side poll. Poll stays as a fast-path but is no longer load-bearing. Creation
must be idempotent: poll + IPN (+ retries) must never create duplicates.

**Status:** Implemented, PHP-lint clean (`php -l` — no syntax errors). NOT committed,
NOT version-bumped (awaiting release instructions). Requires a manual `ALTER TABLE`
after deploy (below).

## Files changed

- `modules/orders/class-dd-orders-module.php` — all changes here.
- `modules/payments/class-dd-pesapal.php` — **NOT changed.** The callback URL
  (`home_url('/wc-api/wc_pesapal_gateway/')`) is already sent to PesaPal in
  `submit_order()`, and `get_transaction_status()` already exists. Nothing in the
  client class needed to change, so it was left untouched (Rule 1a).

## Change 1 — register the IPN listener

`init()` (beside the other `add_action` calls):

```php
add_action( 'woocommerce_api_wc_pesapal_gateway', [ $this, 'handle_pesapal_ipn' ] );
```

WooCommerce routes any request to `/wc-api/{request}/` to the action
`woocommerce_api_{strtolower(request)}`. The already-registered callback URL uses
`wc_pesapal_gateway`, so this hook fires without changing the URL (the brief's
preferred option — no REST fallback needed).

## Change 2 — `handle_pesapal_ipn()`

Runs **unauthenticated** (server-to-server; no nonce). Trust comes from re-verifying
the status against PesaPal's API, never from the request payload.

Flow:
1. Read `OrderTrackingId` (GET; POST accepted defensively). Missing → **HTTP 400**, exit.
2. `find_pesapal_order(tracking_id)` — already created? → **200** (idempotent ack).
3. Load `dd_pesapal_pending_{tracking_id}`. Absent **and** no existing order →
   `error_log()` a reconcile-needed notice, **200** (nothing to create; stop retries).
4. `get_transaction_status(tracking_id)` — the authoritative check:
   - `COMPLETED` → `create_pesapal_order_from_pending()`; success → **200**,
     transient creation failure (lock/DB) → **500** so PesaPal retries.
   - `FAILED`/`INVALID` → delete transient, **200**.
   - `PENDING`/`UNKNOWN` → **200** (PesaPal will re-notify).
5. Every acknowledgement is a JSON body (`orderNotificationType`, `orderTrackingId`,
   `orderMerchantReference`, `status`) via `pesapal_ipn_respond()`.

Rationale for status codes: PesaPal retries until it gets a 200, so 200 covers every
*handled* outcome; non-200 is reserved for "please retry" (400 malformed, 500 transient
creation failure).

## Change 3 — client poll made idempotent

`ajax_pesapal_check_status()`:
- **Before** touching the transient it calls `find_pesapal_order()`. If the IPN already
  created the order, it returns that `order_number`/`order_id` with
  `paid=true, status=COMPLETED` and creates nothing. (This also fixes the previous
  "Payment session expired" error the poll returned when the IPN had already consumed
  the transient.)
- The `COMPLETED` branch now calls the **same** `create_pesapal_order_from_pending()`
  helper as the IPN, so a poll↔IPN race between the check and the create still yields
  one order.

## Idempotency mechanism (three layers)

1. **`pesapal_tracking_id` column** on `wp_dishdash_orders` (new, nullable, UNIQUE).
   `find_pesapal_order()` looks up by it; the create path stamps it. The UNIQUE index
   is the hard DB backstop — a duplicate stamp fails.
2. **`add_option()` lock** (`dd_pesapal_lock_{tracking_id}`): `add_option` is an atomic
   INSERT on the unique `option_name`, so only one concurrent request wins; the loser
   re-checks for the finished row and bails. Lock is always released before return.
3. **Duplicate-key recovery**: if `place_order()` runs but the stamp UPDATE fails
   (another path stamped first), the just-created row **and its items** are deleted and
   the canonical (winner) order is returned — no orphan, no duplicate.

`create_pesapal_order_from_pending()` is the single creation routine used by both paths.
It: checks existing → locks → `place_order()` → stamps `status=pending`,
`payment_status=paid`, `pesapal_tracking_id` → `DD_Customer_Manager::upsert()` →
**fires `DD_Notifications::on_order_created()`** (dashboard + email/WhatsApp; NOT via
`woocommerce_payment_complete`, since Option B creates no WC order) → deletes the
transient → releases the lock.

**Graceful pre-migration degradation:** `has_pesapal_tracking_column()` (cached
`SHOW COLUMNS`) gates every reference to the new column. If the ALTER has not been run,
`find_pesapal_order()` returns null and the create path marks the order paid *without*
the tracking stamp — i.e. exactly the old behaviour, no fatal. Full idempotency turns
on the moment the column exists.

**Note — behaviour change (intentional, for parity):** the old poll `COMPLETED` path did
**not** fire `on_order_created()`, so PesaPal orders never triggered a WhatsApp/email/bell
notification (Option B makes no WC order, so `woocommerce_payment_complete` never fires
either). Routing both paths through the shared helper means whichever path creates the
order now fires notifications exactly once. This is required by the brief's goal ("puts
it on the dashboard + email/WhatsApp").

## MIGRATION — developer runs after deploy

`dbDelta()` does not alter existing tables, so run this once (adjust `wp_` prefix if
different):

```sql
ALTER TABLE wp_dishdash_orders
  ADD COLUMN pesapal_tracking_id VARCHAR(64) NULL AFTER payment_status,
  ADD UNIQUE KEY uq_pesapal_tracking (pesapal_tracking_id);
```

Or via WP-CLI:

```bash
wp db query "ALTER TABLE wp_dishdash_orders ADD COLUMN pesapal_tracking_id VARCHAR(64) NULL AFTER payment_status, ADD UNIQUE KEY uq_pesapal_tracking (pesapal_tracking_id);"
```

Verify:

```bash
wp db query "SHOW COLUMNS FROM wp_dishdash_orders LIKE 'pesapal_tracking_id';"
wp db query "SHOW INDEX FROM wp_dishdash_orders WHERE Key_name='uq_pesapal_tracking';"
```

(Multiple `NULL` values are allowed under a MySQL UNIQUE index, so pre-existing and
non-PesaPal orders are unaffected.)

End-to-end check after migration: place a PesaPal order and pay it. Expected — exactly
one row in `wp_dishdash_orders` with `payment_status='paid'` and a populated
`pesapal_tracking_id`, one dashboard notification, and the poll returns that same
`order_number` (never a second order) whether the IPN or the poll landed first.

## Out of scope — reported, not fixed

- **Fresh-install schema:** `install.php` `CREATE TABLE dishdash_orders` does not include
  `pesapal_tracking_id`, and it is out of the brief's file scope. New installs will run
  with idempotency **off** (safe, via the column-existence guard) until the column is
  added. Recommend adding the column to `install.php` in a follow-up so fresh installs
  get idempotency automatically without a manual ALTER.
- **Poll nonce/cache 400** (`dd_pesapal_check_status`): harmless now that the IPN is the
  primary creator — the poll is purely a UX fast-path. Not chased (per brief).
- **PesaPal keys in `debug.log`**: security, handled out-of-band. Not touched.
- **Release housekeeping not done** (no explicit instruction): `DD_VERSION` bump in
  `dish-dash.php` (both spots) + CLAUDE.md `Last updated`/Current State per Rule 0, and
  the `git add/commit/push`. Awaiting release instructions.

---

## Release — v3.11.1 — DIAGNOSTIC: instrument PesaPal IPN create path (LOGGING ONLY) ✅

**Site:** nyarutarama. **Goal:** trace the ghost-order failure (payment taken, no order
created) end-to-end without changing behaviour.

**Scope:** LOGGING ONLY. No logic/flow changes.
**File touched:** `modules/orders/class-dd-orders-module.php`.
**Version:** bumped to **v3.11.1** (`dish-dash.php` header line 6 + `DD_VERSION` line 47;
`CLAUDE.md` `Last updated` + Current State + changelog row).
**Syntax:** `php -l` → *No syntax errors detected*.

All probes emit via `error_log('DD_DIAG: …')` → the site's PHP `error_log` target
(`wp-content/debug.log` when `WP_DEBUG_LOG` is on, else the PHP/cPanel error log).

> Note on "no flow change": two call results were captured into local vars purely so
> their value could be logged — `$dd_diag_pp_set` for the checkout `set_transient`, and
> `$dd_diag_ipn_existing` for the IPN dup check. Call count and control flow are identical
> to before (the value was previously used inline in the same statement/`if`).

### Every DD_DIAG log line (code order)

**A) Checkout — transient write (`ajax_place_order`, pesapal branch)**
| Line | Log |
|---|---|
| 1013 | `DD_DIAG: CHECKOUT set_transient key=dd_pesapal_pending_<tid> set_transient_returned=<true\|false>` |

**B) Poll — `ajax_pesapal_check_status()`**
| Line | Log |
|---|---|
| 1357 | `DD_DIAG: POLL entry tracking=<tid> EXISTING_ORDER=<t/f>` |
| 1369 | `DD_DIAG: POLL get_transient key=dd_pesapal_pending_<tid> FOUND=<t/f>` |
| 1371 | `DD_DIAG: TRANSIENT MISSING at POLL tracking=<tid>` (only when FOUND=false) |
| 1378 | `DD_DIAG: POLL get_transaction_status tracking=<tid> status=<STRING>` |
| 1384 | `DD_DIAG: POLL ATTEMPTING CREATE tracking=<tid>` |
| 1388 | `DD_DIAG: POLL CREATE failed tracking=<tid> error=<msg>` |
| 1393 | `DD_DIAG: POLL CREATE ok tracking=<tid> order_id=<id>` |
| 1404 | `DD_DIAG: DELETE TRANSIENT dd_pesapal_pending_<tid> from=poll` |

**C) IPN — `handle_pesapal_ipn()`** (brief points A–F)
| Line | Log | Brief |
|---|---|---|
| 1438 | `DD_DIAG: IPN entry OrderTrackingId=<tid> OrderMerchantReference=<ref>` | A |
| 1447 | `DD_DIAG: IPN find_pesapal_order tracking=<tid> EXISTING_ORDER=<t/f> skipped_as_dup=<t/f>` | C |
| 1454 | `DD_DIAG: IPN get_transient key=dd_pesapal_pending_<tid> FOUND=<t/f>` | B |
| 1459 | `DD_DIAG: TRANSIENT MISSING at IPN tracking=<tid>` | B (FOUND=false) |
| 1468 | `DD_DIAG: IPN get_transaction_status tracking=<tid> status=<STRING>` | D |
| 1471 | `DD_DIAG: IPN ATTEMPTING CREATE tracking=<tid>` | E |
| 1476 | `DD_DIAG: IPN CREATE failed tracking=<tid> error=<msg>` | E |
| 1481 | `DD_DIAG: IPN CREATE ok tracking=<tid> order_id=<id>` | E |
| 1487 | `DD_DIAG: DELETE TRANSIENT dd_pesapal_pending_<tid> from=ipn` | F |

**D) Shared helper — `create_pesapal_order_from_pending()`** (both poll & IPN delegate here)
| Line | Log |
|---|---|
| 1573 | `DD_DIAG: HELPER entry tracking=<tid>` |
| 1577 | `DD_DIAG: HELPER find_pesapal_order tracking=<tid> EXISTING_ORDER=<t/f>` (C) |
| 1587 | `DD_DIAG: HELPER lock-contended re-check tracking=<tid> EXISTING_ORDER=<t/f>` |
| 1595 | `DD_DIAG: HELPER ATTEMPTING CREATE (place_order) tracking=<tid>` (E) |
| 1608 | `DD_DIAG: HELPER place_order failed tracking=<tid> error=<msg>` (E) |
| 1615 | `DD_DIAG: HELPER place_order ok tracking=<tid> order_id=<id>` (E) |
| 1633 | `DD_DIAG: HELPER stamp failed (dup tracking_id) tracking=<tid> discarded_order_id=<id> winner_order_id=<id\|none>` |
| 1686 | `DD_DIAG: DELETE TRANSIENT dd_pesapal_pending_<tid> from=helper` (F) |
| 1690 | `DD_DIAG: HELPER create complete tracking=<tid> order_id=<id>` |

**Every `delete_transient('dd_pesapal_pending_*')` site is tagged (3 total):**
`from=poll` (1405), `from=ipn` (1488), `from=helper` (1687).

### Grep to read the logs

```bash
# WP_DEBUG_LOG on → wp-content/debug.log:
grep "DD_DIAG:" /home/imitjsiy/dishdash.khanakhazana.rw/wp-content/debug.log

# Live during a test payment:
tail -f wp-content/debug.log | grep "DD_DIAG:"

# One payment end-to-end by tracking id:
grep "DD_DIAG:" wp-content/debug.log | grep "<OrderTrackingId>"

# Who deleted the pending transient (the ghost-order smoking gun):
grep "DD_DIAG: DELETE TRANSIENT" wp-content/debug.log

# Failure signatures:
grep -E "DD_DIAG: (TRANSIENT MISSING|.*CREATE failed|.*stamp failed)" wp-content/debug.log
```

> If `WP_DEBUG_LOG` is **not** on, `error_log()` goes to the server PHP error log — check
> cPanel → Metrics → Errors or the domain `error_log` file. Recommend enabling
> `WP_DEBUG_LOG` (with `WP_DEBUG_DISPLAY` off) so the trace lands in `wp-content/debug.log`.

### What the trace will confirm/deny
1. **Poll consumes the transient before the IPN** — the `from=<ipn|poll|helper>` tag on
   every delete + `TRANSIENT MISSING at IPN` pinpoints the consumer/order-of-arrival.
2. **Transient never written** — `CHECKOUT … returned=false` (object cache / TTL / size).
3. **Status string mismatch** — `get_transaction_status … status=<X>` shows the literal
   value (e.g. case/whitespace) that would skip the COMPLETED branch.
4. **Create attempted but aborted** — `ATTEMPTING CREATE` → `CREATE failed` /
   `place_order failed` / `stamp failed` shows the failing layer.
5. **Transient expired before confirmation** — `TRANSIENT MISSING at IPN` + reconcile-needed
   with no `ATTEMPTING CREATE`.

### Not changed
- No behaviour/flow altered; no schema, options, settings, or endpoints.
- `pesapal_ipn_respond`, `find_pesapal_order`, `has_pesapal_tracking_column`,
  `place_order`, `submit_order`, and all non-PesaPal paths untouched.
- Pre-existing `error_log('DD PesaPal IPN: …')` lines kept (DD_DIAG added alongside).

**Awaiting go-ahead to commit v3.11.1.**

---

## Release — v3.11.2 — PesaPal durable order + correct status check ✅

**Root cause (confirmed by the v3.11.1 DD_DIAG trace):** the ~5 s client poll called
`get_transaction_status` before PesaPal finalized the payment; PesaPal returned the text
`payment_status_description = "INVALID"`; the poll treated INVALID as terminal →
`delete_transient` → the cart data was destroyed; the real payment then completed and the
IPN fired, but the transient was gone → no order was created (ghost order).

**Files changed (in brief scope):** `modules/payments/class-dd-pesapal.php`,
`modules/orders/class-dd-orders-module.php`. **`php -l` clean on both.**

### A. Authoritative NUMERIC status_code — `get_transaction_status()`

`modules/payments/class-dd-pesapal.php`. The text `payment_status_description` lags and reads
INVALID in the early window, so it is no longer trusted as primary. Now reads the numeric
`status_code` and maps:

| status_code | returns |
|---|---|
| 1 | `COMPLETED` |
| 2 | `FAILED` |
| 3 | `REVERSED` |
| 0 | `INVALID` |

`COMPLETED` is returned **only** on `status_code === 1`. `isset()` + `is_numeric()` guards
(so `0` is honoured, not treated as empty). Falls back to `strtoupper(payment_status_description)`
**only** when `status_code` is absent. `UNKNOWN` on token/HTTP failure (unchanged).

### B. PERSIST AT SUBMIT — durable order row before redirect

`class-dd-orders-module.php`, pesapal checkout branch. After `submit_order()` succeeds and
before the redirect response, the DD order row is created via `place_order()` and then
UPDATEd to `status='pending_payment'`, `payment_status='unpaid'`,
`pesapal_tracking_id={tracking_id}`. The row now exists **independent of the transient**.
The transient is still written (retained for the fallback create path).

- **Guarded on `has_pesapal_tracking_column()`.** If the column is missing (migration not
  run), the up-front persist is **skipped** and the flow falls back to the previous
  transient-only create — otherwise the fallback create would produce a **duplicate** row.
  So: migrated → durable persist+promote; un-migrated → old behaviour **plus** the A/C
  fixes (status_code + no-delete), which is still strictly better than before.
- `place_order()` at submit fires no customer-facing notification: it fires the
  listener-less `dish_dash_order_placed` hook and `send_order_confirmation()` (which
  `wp_mail`s an empty address → no-op, since checkout collects no email). `on_order_created`
  is **not** fired here — it fires once on promotion (C).

### C. IPN + poll are now PROMOTE, not CREATE

`class-dd-orders-module.php`.

- New `promote_pesapal_order( $order, $tracking_id )`: conditional
  `UPDATE … SET status='pending', payment_status='paid' WHERE id=%d AND payment_status='unpaid'`.
  The `payment_status='unpaid'` predicate is the idempotency guard — only the first caller
  (poll or IPN) gets rows-affected=1 and fires notifications; a racing second caller gets 0
  and skips. **`on_order_created` fires exactly once.**
- New `fire_pesapal_notifications( $order_id )`: rebuilds the notification payload from the
  **durable DB row + order_items** (not the transient, so it still fires if the transient
  expired) and runs the customer upsert — mirrors the offline order path.
- **IPN** (`handle_pesapal_ipn`): find row by tracking id → if `paid`, no-op → else verify
  status → `COMPLETED` promotes; `FAILED`/`REVERSED` marks the row `payment_status='failed'`
  (conditional on still-`unpaid`) **without deleting the transient**; PENDING/INVALID/UNKNOWN
  leave it `pending_payment` for the next notification. No-row case → fallback
  create-from-transient (unchanged, "shouldn't happen").
- **Poll** (`ajax_pesapal_check_status`): if row already `paid`, return it → else verify
  status → `COMPLETED` promotes the row (or fallback-creates if somehow no row);
  `FAILED`/`REVERSED` → `{paid:false,status:<X>}`; **INVALID/PENDING/UNKNOWN →
  `{paid:false,status:'PENDING'}` = keep polling, never terminal.**
- `find_pesapal_order()` SELECT extended to include `payment_status` (needed by the
  paid/unpaid promote checks).

### Confirmation — no `delete_transient` remains in the poll

`grep "delete_transient( 'dd_pesapal_pending_" class-dd-orders-module.php` → the **only**
hit is in `create_pesapal_order_from_pending()` (the fallback create path, tagged
`from=helper`), which deletes the transient it just consumed. The poll and IPN contain
**zero** `delete_transient` for the pending key (the old `from=poll` / `from=ipn` deletes
are gone). Transients now expire naturally (2 h TTL).

### D. Dashboard exclusion of `pending_payment` — findings (reported, not silently expanded)

`pending_payment` is a **new** order-status value (previously unused for orders). Audit:

**Already safe (exclude by construction):**
- Revenue / fees / AOV / revenue chart (`dashboard.php`): filter `status='delivered'`.
- Active Orders KPI (`dashboard.php`): `status IN ('pending','confirmed','ready')`.
- **In-scope notification poll** (`ajax_poll_notifications`): `status='pending'` only →
  `pending_payment` never alerts; promoted orders (now `status='pending'`) alert correctly.
  **No change needed — verified.**

**Would surface / act on `pending_payment` — OUT OF SCOPE (dashboard.php / orders.php not in
brief file list); recommend a follow-up brief:**
- `dashboard.php`: "Total Orders" KPI (`COUNT(*)`, no status filter) and "Recent Orders"
  list (`SELECT * … WHERE is_test=0`, no status filter) will show awaiting-payment rows.
- `dashboard.php`: the **manual** "mark stale delivered" button
  (`WHERE status NOT IN ('delivered','cancelled') AND updated_at < 24h`) would sweep an
  abandoned `pending_payment` row into `delivered` → into revenue. Admin-triggered, but a
  real hazard.
- `orders.php`: "Total Orders" KPI and **"Total Revenue" KPI (`SUM(total)`, no status
  filter)** include `pending_payment` (note: that revenue KPI already summed
  pending/cancelled pre-existing — this only widens an existing imprecision); the main
  orders list shows all statuses.

**Recommended follow-up (separate release):** exclude `status='pending_payment'` from those
count/revenue/recent/list/stale-sweep queries, and/or add a small cron to purge abandoned
`pending_payment` rows older than ~2 h (matches the transient TTL and the "let it expire
naturally" principle) so they don't accumulate.

### Related CLIENT-SIDE defect found — OUT OF SCOPE (cart.js not in brief), reported only

`assets/js/cart.js` PesaPal poll callback (~L1047-1067). `ajax()` invokes
`onSuccess(res.data)`, so inside the callback `res` **is** the data object
(`{paid,status,order_number,order_id}`). But the code reads `res.data.order_number` (L1053)
and `res.data.status` (L1061) — `res.data` is `undefined`, so those lines **throw**. Also
L1061 treats `status === 'INVALID'` as terminal ("Payment failed").

Impact with this release: the **order is still durable** — persist-at-submit + the IPN
(authoritative) create/promote it regardless of what the client does, so no ghost order.
But the customer's on-screen confirmation is unreliable. **Therefore verify this release via
the DB/admin + DD_DIAG logs, NOT the customer screen.** Recommended follow-up brief for
cart.js: use `res.order_number` / `res.status`, drop `INVALID` from the terminal check (the
server no longer emits it to the client), and add `REVERSED`.

### DD_DIAG logging — kept IN for one more verification cycle (per brief)

New/updated probes this release: `CHECKOUT persisted …` (or `persist SKIPPED …` when the
column is absent), `POLL/IPN … EXISTING_ORDER=… payment_status=…`, `POLL/IPN PROMOTING …`,
`PROMOTE unpaid->paid …` / `PROMOTE skipped (already paid / raced) …`,
`IPN already paid …`, `IPN payment FAILED/REVERSED → mark failed …`, and the fallback
`… CREATE (fallback) …` lines. Read with `grep "DD_DIAG:" wp-content/debug.log`.

### Verification checklist (durable path)

1. Ensure the `pesapal_tracking_id` column exists (v3.11.0 migration). If a checkout logs
   `DD_DIAG: CHECKOUT persist SKIPPED …`, the column is missing → run the ALTER first.
2. Place a PesaPal order. Expect at redirect: one row `status='pending_payment'`,
   `payment_status='unpaid'`, `pesapal_tracking_id` populated, and
   `DD_DIAG: CHECKOUT persisted …`.
3. Complete payment. Expect `DD_DIAG: … get_transaction_status … status=COMPLETED`, then
   `PROMOTE unpaid->paid` **once** (whichever of poll/IPN wins), the row flips to
   `status='pending'`, `payment_status='paid'`, exactly one dashboard notification, and
   **no** second/duplicate row.
4. Early polls before completion should log `status=INVALID` (or PENDING) and the poll must
   return `PENDING` (keep polling) — the transient must **survive** (no `DELETE TRANSIENT
   … from=poll` anywhere).

### Not changed
- `create_pesapal_order_from_pending()` retained as the no-row fallback create path (still
  idempotent, still fires notifications, still deletes its own consumed transient).
- Offline/COD/MoMo/IremboPay paths, WC-order online flow, reservations — untouched.
- No schema change (relies on the existing `pesapal_tracking_id` column;
  `status='pending_payment'`/`payment_status='failed'` are free-text VARCHAR values).

**Awaiting go-ahead to commit v3.11.2.**
