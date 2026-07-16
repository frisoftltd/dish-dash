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
