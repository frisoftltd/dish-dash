# Investigation — Order Delivery Modes + Manual MoMo QR
Deployed version: **v3.10.49** / Phase: **Phase 7 — Role Cleanup & Access Control** (sub-phase 7C — Customer Profile)

**READ-ONLY investigation.** No plugin logic was changed. This file overwrites the previous
(now-stale) `investigation.md` from the R2-polish task.

---

## A. Existing MoMo flow

### 1. Where MoMo is defined & rendered at checkout
- **The payment method itself is NOT defined by DishDash.** It comes from a *separate WooCommerce
  gateway plugin* (`mtn-momo-woocommerce`). The drawer checkout reads whatever gateways WooCommerce
  has registered:
  `modules/template/class-dd-template-module.php:269-298` — `ddCartData.paymentGateways` is built from
  `WC()->payment_gateways->get_available_payment_gateways()`. Each gateway's `id`, `title`, emoji/logo
  is passed to JS. MoMo logo mapped at `:273` (`assets/images/mtn-momo-logo.jpg`).
- **Rendered in the drawer** by JS, not PHP: `templates/cart/cart.php:139-197` is the checkout panel
  (`#ddPanelCheckout`); the radio list is injected by `assets/js/cart.js` from `ddCartData.paymentGateways`
  (comment at `cart.php:177` "Rendered by cart.js from ddCartData.paymentGateways").
- **MoMo phone field** is injected by JS: `assets/js/cart.js:623-634` builds `#ddMomoPhoneWrap` /
  `#ddMomoPhone`, shown only when the `mtn_momo` radio is selected (`cart.js:264`, `:643-645`).
- **API client:** `modules/payments/class-dd-momo.php` — `DD_MoMo`, a thin wrapper over the **MTN MoMo
  Collections API v1** (`request_to_pay` `:92`, `get_status` `:147`). Credentials read from the
  `woocommerce_mtn_momo_settings` option (`:33`). **This is the 2.6% Collections API — exactly the path
  the new manual QR is meant to replace.**
- **Server branch** that handles a MoMo submit: `modules/orders/class-dd-orders-module.php:811-855`
  (inside `ajax_place_order()`).

### 2. What "Option B — create order only after payment confirmed" does (full trace)
Flow for `payment_method === 'mtn_momo'` (`class-dd-orders-module.php:811-855`):
1. Validate `DD_MoMo::is_configured()` and that a `momo_phone` was posted (`:813-822`).
2. Compute total via `calculate_totals()` — **no DB row is written yet** (`:824-826`).
3. Fire `request_to_pay()` → sends a USSD push prompt to the customer's phone; returns a `reference_id`
   (`:829-835`). A temp key `md5(phone+whatsapp+microtime())` is used as the external id.
4. **Store the whole order payload in a transient** `dd_momo_pending_{reference_id}` for 30 min
   (`:838-845`) — customer_name, phone, address, items, delivery_fee, total. **Still no DB row.**
5. Respond `{ momo:true, order_id:0, order_number:'—', reference_id, total }` (`:847-853`).
6. **Customer sees** the `#ddPanelMomo` waiting panel ("Check your phone / A USSD prompt has been sent…",
   `cart.js:60-69`, shown at `cart.js:756-768`), then JS polls `dd_momo_check_status` every 5 s
   (`startMomoPolling`, 24 attempts / 2 min cap `cart.js:910-912`).
7. Polling handler `ajax_momo_check_status()` (`class-dd-orders-module.php:1170-1249`): on `SUCCESSFUL`
   it **now** calls `place_order()` to write the DB row (`:1200-1214`), then `UPDATE` to
   `status='pending', payment_status='paid'` (`:1220-1226`), deletes the transient, upserts the customer
   (`:1229`), returns `{ paid:true, order_number, order_id }`. On FAILED/REJECTED/TIMEOUT it just deletes
   the transient — **no order is ever created** (`:1240-1244`). This is the "ghost orders eliminated"
   win noted in CLAUDE.md for v3.6.8.

**Key point for the rebuild:** the DB write is `place_order()` (`:319`), which always inserts with
`status='pending'`, `payment_status='unpaid'` (`:362`, `:370`). PesaPal (`:964-1006` / `:1251-1320`) and
IremboPay (`:858-961`) follow the same transient-then-confirm pattern; **only IremboPay creates the DD
row up front** (`:877`), MoMo & PesaPal defer it.

### 3. Existing QR generation anywhere?
**None.** `grep -i "qr|QRCode|qrcode"` hits are all non-code or unrelated:
- `install.php:190` — `qr_code VARCHAR(255) DEFAULT NULL` **column on the `dishdash_tables` table**
  (dine-in table QR, Phase 9 "QR scan ordering" — never populated, no generator).
- `readme.txt`, `CLAUDE.md`, `INSTALLER_CONSOLIDATION.md` — docs.
- `vendor/giggsey/locale/data/az.php` — libphonenumber locale data (false positive).
- "USSD" strings exist (`class-dd-momo.php:11`, `orders-module.php:828`, `cart.js:63/633`) but they refer
  to the **Collections API push prompt**, not a scannable/manual USSD QR.
**There is no QR image/string generation, and no QR library in `vendor/`.**

### 4. Is the merchant USSD string / merchant code stored anywhere?
**No.** No option, constant, or column holds a merchant code or a `*182*8*1*…#` template. Searches for
`*182`, `182*8`, `merchant_code`, `merchant` returned only libphonenumber/vendor license noise and the
Collections-API code. **The manual-QR feature would introduce brand-new option keys** (e.g.
`dd_momo_merchant_code`) — nothing to reuse.

### 5. `tel:` link in checkout/payment templates?
**None.** No `tel:` anywhere in the codebase. All customer/admin contact links are `wa.me` (WhatsApp) or
plain text phone numbers. (A manual-USSD "tap to dial `*182*…#`" `tel:` link would be new.)

---

## B. Removed WhatsApp handoff (v3.5.25)

### 6. What was removed, and is any of it still present?
Two commits on 2026-06-08 shipped v3.5.25:
- **`539b13f`** "remove Notify Restaurant WhatsApp button from customer booking confirmation" — touched
  `assets/js/cart.js` and `assets/js/reservations.js`.
- **`9841a8f`** "remove Notify Restaurant button and hide submit button after reservation confirmed" —
  `assets/js/reservations.js`.

**In `cart.js` the removed block (from `539b13f`) was the AUTO-OPEN of admin WhatsApp** on the order
confirmation panel:
```js
// Open WhatsApp in new tab via anchor click (avoids mobile popup block)
if ( data.whatsapp_url ) {
    setTimeout( function () {
        const a = document.createElement('a');
        a.href = data.whatsapp_url; a.target = '_blank'; a.rel = 'noopener noreferrer';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
    }, 800 );
}
...
var waBtn = document.getElementById( 'ddConfirmWhatsappBtn' );
if ( waBtn ) waBtn.style.display = 'none';
```
**Current state — the SERVER SIDE IS STILL FULLY LIVE.** This is the most important finding for the
rebuild:
- `ajax_place_order()` (offline path) still calls `DD_Notifications::on_order_created()` (`orders-module.php:1111`)
  and still returns **`whatsapp_url` (admin) and `whatsapp_customer_url` (customer)** in the success
  payload (`:1140-1141`).
- The builders are intact and working: `DD_Notifications::build_admin_whatsapp_url()`
  (`class-dd-notifications.php:280-306`) and `build_customer_whatsapp_url()` (`:251-272`).
- **Only the JS consumer was deleted.** `cart.js` no longer references `data.whatsapp_url` /
  `whatsapp_customer_url` at all (confirmed: no matches). The confirmation panel now shows only order
  number + ETA (`cart.js:858-864`).
- There is **no commented-out** remnant in `cart.js` — it was cleanly deleted.

So: **to bring back a WhatsApp handoff, no PHP is needed — the URL is already on the wire; only a JS
consumer (a button, or an auto-open) must be re-added.** The intended "I have paid" one-tap can reuse
`whatsapp_url` verbatim.

### 7. WHY was it removed? — evidence, not guess
**No explicit reason is stated anywhere.** Evidence examined:
- Commit `539b13f` body: only the title + `Co-Authored-By`. No rationale.
- Commit `9841a8f` body: same, no rationale.
- CLAUDE.md diff for v3.5.25 (`git show 539b13f -- CLAUDE.md`): "Last working state: removed 'Notify
  Restaurant' WhatsApp button from customer-facing booking confirmation; removed auto-open WhatsApp from
  orders confirmation. **Admin outbound buttons (Send Confirmation etc.) unchanged.**" — describes WHAT,
  not WHY. There is no `report.md` in the repo and no nearby code comment giving a reason.

**Verdict: no stated reason found.** (Context only, *not* asserted as the reason: the commit is titled
around a customer-facing *booking* button, and the immediately-prior WhatsApp history — `dccdf47`
v3.2.51 "show Notify Restaurant button instead of blocked popup", `19be936` v3.2.52 "remove customer
button" — shows a long-running fight with mobile popup blockers and unwanted auto-redirects into
WhatsApp. But the v3.5.25 commits do not say this.)

### 8. How the customer confirmation / success screen renders now
- **Markup:** `templates/cart/cart.php` — the confirmation panel is a `dd-cart-panel` (id
  `#ddPanelConfirmation`, sibling of `#ddPanelCheckout`/`#ddPanelMomo`). It contains `#ddConfirmOrderNum`,
  `#ddConfirmEta`, and a `#ddConfirmClose` button.
- **Populated by JS:** `cart.js:858-865` sets order number + ETA and calls `showPanel(panelConfirmation)`.
  Same panel is reused by the MoMo/IremboPay/PesaPal success callbacks (`cart.js:788-794`, `:822-829`).
- **Shows:** "Order #DD-00042", "🛵 Estimated delivery: 30–45 minutes", a Close button. **No WhatsApp
  button, no status link.** Close returns to the cart panel (`cart.js:901-907`).

### 9. Existing `wa.me` URL builder — show it & confirm escaping
The canonical builder is `DD_Notifications::build_admin_whatsapp_url()`
(`modules/orders/class-dd-notifications.php:280-306`):
```php
public static function build_admin_whatsapp_url( array $order ): string {
    $admin_phone = preg_replace( '/[^0-9]/', '', get_option( 'dd_whatsapp_admin', '' ) );
    if ( ! $admin_phone ) return '';
    $items_text = implode( "\n", array_map( fn($i) => $i['qty'].'× '.$i['name'], $order['items'] ) );
    ...
    $msg = implode( "\n", [ '🔔 New Order '.$order['order_number'].' — Khana Khazana', ... ] );
    return 'https://wa.me/' . $admin_phone . '?text=' . rawurlencode( $msg );
}
```
- **Escaping: `rawurlencode()` on the message text.** The number is stripped to digits with
  `preg_replace('/[^0-9]/','')`. **It does NOT use `esc_url()` or `esc_attr()`.**
- This is deliberate and matches the hard-won lesson (CLAUDE.md): `esc_url()` strips `%0A`, destroying
  line breaks — so newlines are kept as literal `"\n"` joined lines and `rawurlencode()` encodes them to
  `%0A` which WhatsApp honors. Every wa.me builder in the file follows the same pattern
  (`build_customer_whatsapp_url` `:271`, kitchen `:388`, rider `:432`, on-the-way `:475`, reservation
  `:101-105`).
- **Reuse note:** the customer/admin variants take a normalized `$order` array
  (name/phone/items/total/…), not a DB row. `build_kitchen_whatsapp_url()` / `build_rider_whatsapp_url()`
  / `build_customer_ontheway_url()` DO take a `wp_dishdash_orders` row + fetch items themselves — those
  are the closest existing match to a "post-order ticket to the restaurant" for the new "I have paid"
  button.

---

## C. Notification + status workflow

### 10. Order statuses & transitions — where defined
- **Statuses** (5): `pending`, `confirmed`, `ready`, `delivered`, `cancelled`. Labels in
  `dd_order_status_label()` (`dishdash-core/class-dd-helpers.php:125-134`).
- **Transitions** in `dd_order_status_transitions()` (`class-dd-helpers.php:112-120`):
  ```
  pending   → confirmed, cancelled
  confirmed → ready, cancelled
  ready     → delivered, cancelled
  delivered → ready            (reopen)
  cancelled → pending          (reopen)
  ```
- Enforced in `update_status()` (`orders-module.php:546-548`) — an illegal transition returns `false`.
  Each status stamps a timestamp column (`confirmed_at`/`ready_at`/`delivered_at`/`cancelled_at`,
  `:554-559`), fires `dish_dash_order_status_changed`, notifies the customer (`send_status_update`,
  `:585`) and recalculates the platform fee (`:587`).
- **No "paid/claimed" order status exists.** Payment state is tracked separately in `payment_status`
  (`unpaid`/`paid`) — see §17. The intended "claimed" state after "I have paid" has **no existing
  `status` value**; options are (a) reuse `payment_status` while `status` stays `pending`, or (b) add a
  new status — but a new status must be added to both the labels map and the transitions map (a hard
  gate).

### 11. Customer-facing status page — does it exist? route/template/how reached
**Yes** (added v3.10.30, polished through v3.10.49):
- **Route:** the `/track-order/` WordPress page (slug `track-order`, id in option
  `dish_dash_track_page_id`; URL built by `dd_track_url()` `class-dd-helpers.php:177-183`).
- **Renderer:** `[dish_dash_track]` shortcode → `DD_Orders_Module::shortcode_track()`
  (`orders-module.php:1645`), registered at `:109`. Template `templates/orders/track.php`.
- **Two modes:** default (no param) = a **list** of the logged-in user's active orders
  (`status IN pending/confirmed/ready`, phone-anchored); `?order_id=<id>` = a **live status timeline** via
  `render_single_track()` (`:1725`), which polls `dd_get_order` and stops on a terminal status
  (`assets/js/order-tracking.js`).
- **How a customer reaches it:** a `track-order` item was added to the My-Account sidebar
  (`DD_Profile_Module::add_menu_item()`), href remapped to `/track-order/`; and `place_order()` returns a
  `track_url` (`:417`). **This page is only meaningful when the restaurant runs the dashboard/status
  workflow** — the intended design says it should exist only when dashboard notification is ON.
- **Ownership gate:** `ajax_get_order()` (`:1146-1168`) — staff (`dd_manage_orders`) read any order; a
  customer reads only their own (`customer_id === get_current_user_id()`); guests refused. (This is the
  gate behind the parked R4c phone-only-order limitation.)

### 12. Dashboard notification system — gated or always-on? where a toggle hooks in
**Always-on. There is NO per-restaurant gate anywhere.**
- **AJAX endpoint:** `dd_poll_notifications` → `ajax_poll_notifications()` (registered
  `orders-module.php:87`; handler `:1409-1468`). Returns all pending orders + pending reservations with
  server-computed `seconds_ago` (`TIMESTAMPDIFF`, `:1421/:1431`) and a `pending_count`. No option is
  consulted.
- **Client:** `assets/js/admin.js` — `setInterval(poll, 30000)` (`:70`, `:83`), posts
  `dd_poll_notifications` (`:127`), plays a beep on genuinely-new items (`:137`) and fires a browser
  `new Notification(...)` (`:174`). This runs on every DD admin page load, unconditionally.
- **Where a toggle would hook in (for "dashboard notification off"):** cleanest gate is at the **enqueue
  of the polling script / localize of its config** in the admin module (so the interval never starts) —
  plus a defensive early-return in `ajax_poll_notifications()`. A new boolean option (e.g.
  `dd_dashboard_notifications_enabled`) read in both places. The order-storage path (`place_order()`) must
  stay untouched so **every order is still stored regardless of mode** (the AI-data requirement).

---

## D. Settings infrastructure

### 13. Where Settings is registered/rendered + one boolean field end-to-end
- **Page:** `admin/pages/settings.php`. Save handler is an inline `if ( isset($_POST['dd_save_settings'])
  && check_admin_referer('dd_settings_save') ) { … }` block at the top of the file (`:33-…`), each field
  saved with `update_option()`. `get_option()` reads happen inline in the form markup below.

**Worked example — `dd_fees_enabled` (the platform-fee toggle), verbatim:**

Save handler — `settings.php:97`:
```php
update_option( 'dd_fees_enabled', isset( $_POST['dd_fees_enabled'] ) ? '1' : '0' );
```
Form field — `settings.php:743-744`:
```php
<input type="checkbox" name="dd_fees_enabled" value="1"
    <?php checked( '1', get_option( 'dd_fees_enabled', '1' ) ); ?>>
```
Read site (consumer) — `class-dd-orders-module.php:350-351`:
```php
$fees_enabled    = get_option( 'dd_fees_enabled', '1' ) === '1';
$dd_platform_fee = $fees_enabled ? absint( get_option( 'dd_per_order_fee', 750 ) ) : 0;
```
That is the exact pattern to copy for the two new toggles (dashboard-notification on/off, WhatsApp-handoff
on/off) and the merchant-code text field.

### 14. Confirm the checkbox-save pattern
Confirmed. The idiom is `isset( $_POST[$key] ) ? '1' : '0'` (string) — used for `dd_fees_enabled`
(`:97`). Reservation toggles use the int variant `isset(...) ? 1 : 0` (`:86`, `:90`). Either works; the
string form is what the fee toggle (closest analog) uses. The `checked()` helper renders the box state
from `get_option()`.

### 15. Existing `dish_dash_*` / order-payment-notification option keys
Order / payment / delivery / notification related options (mix of `dish_dash_*` and `dd_*` prefixes):
- **Delivery/order:** `dd_free_delivery_threshold`, `dd_delivery_fee`, `dd_delivery_eta`,
  `dd_kitchen_prep_time`, `dd_minimum_order_amount`, `dish_dash_min_order`, `dish_dash_order_prefix`,
  `dish_dash_order_counter`, `dish_dash_tax_rate`, `dish_dash_currency*`.
- **Order-type enablement:** `dish_dash_enable_pickup`, `dish_dash_enable_delivery`,
  `dish_dash_enable_dinein`, `dish_dash_enable_reservations`, `dish_dash_enable_pos` (`settings.php:38-39`).
- **Payment gateway toggles (documented in CLAUDE.md, gateway-side):** `dd_payment_card_enabled`,
  `dd_payment_momo_enabled`, `dd_payment_cod_enabled`. MoMo API creds live in the external
  `woocommerce_mtn_momo_settings` option; PesaPal/IremboPay in `woocommerce_{pesapal,irembopay}_settings`.
- **Notifications / WhatsApp:** `dd_whatsapp_admin`, `dd_whatsapp_kitchen`, `dd_riders`, `dd_admin_email`.
- **Fees:** `dd_per_order_fee`, `dd_fees_enabled`.
- **Track page:** `dish_dash_track_page_id`.
- **No option exists for:** dashboard-notification on/off, WhatsApp-handoff on/off, or a MoMo merchant
  code / manual-QR — all three would be **new**.

---

## E. Schema

### 16. `wp_dishdash_orders` columns (from `install.php:101-142`)
```
id                   BIGINT UNSIGNED  PK, auto-increment
order_number         VARCHAR(50)      NOT NULL, UNIQUE
wc_order_id          BIGINT UNSIGNED  NULL
branch_id            BIGINT UNSIGNED  NOT NULL DEFAULT 1
customer_id          BIGINT UNSIGNED  NULL          (stores WP user id; nullable → guest)
customer_name        VARCHAR(255)
customer_phone       VARCHAR(50)
customer_email       VARCHAR(255)
order_type           ENUM('delivery','pickup','dine-in','pos') DEFAULT 'delivery'
status               VARCHAR(50)      DEFAULT 'pending'
subtotal             DECIMAL(10,2)
delivery_fee         DECIMAL(10,2)
discount             DECIMAL(10,2)
tip                  DECIMAL(10,2)
tax                  DECIMAL(10,2)
total                DECIMAL(10,2)
payment_method       VARCHAR(100)     DEFAULT ''
payment_status       VARCHAR(50)      DEFAULT 'unpaid'
scheduled_at         DATETIME         NULL
delivery_address     TEXT             NULL   (stored JSON-encoded, orders-module.php:371)
special_instructions TEXT             NULL
pos_session_id       BIGINT           NULL
table_id             BIGINT           NULL
created_at           DATETIME         DEFAULT CURRENT_TIMESTAMP
updated_at           DATETIME         ON UPDATE CURRENT_TIMESTAMP
confirmed_at         DATETIME         NULL
ready_at             DATETIME         NULL
delivered_at         DATETIME         NULL
cancelled_at         DATETIME         NULL
is_test              TINYINT(1)       DEFAULT 0
platform_fee         INT UNSIGNED     DEFAULT 0
Keys: PK(id), UNIQUE(order_number), KEY branch_id, customer_id, status, created_at, is_test,
      branch_status(branch_id,status)
```

### 17. Is there a status/payment-claim state to reuse, or is a new one needed?
- **`status`** values used: `pending`, `confirmed`, `ready`, `delivered`, `cancelled` (§10). No "paid",
  "claimed", or "awaiting-verification" value exists.
- **`payment_status`** values used in code: `unpaid` (default at insert, `orders-module.php:370`) and
  `paid` (set by the MoMo/PesaPal confirm handlers, `:1222`/`:1289`; IremboPay `:1342`). It's a free-text
  VARCHAR(50) — additional values (e.g. `claimed`) are storable **without a schema change**.
- **Recommendation surface (not a decision):** the manual-QR "I have paid" claim maps naturally to
  `payment_status` (e.g. leave `status='pending'` for the kitchen queue and set
  `payment_status='claimed'` or reuse `'paid'`). This avoids touching the transition gate. A brand-new
  `status` value would require edits to *both* `dd_order_status_label()` and
  `dd_order_status_transitions()` (a hard gate) and to admin UI that lists statuses. **No new column is
  strictly required** if `payment_status` is reused; if a distinct audit trail is wanted, one new
  nullable column (e.g. `paid_claimed_at DATETIME`) would be the clean addition.

### 18. Auto-migration mechanism — confirmed (memory about manual WP-CLI ALTER is STALE)
The manual-WP-CLI note is **out of date for column/table adds.** Verified:
- `install.php` is the single canonical installer; all `CREATE TABLE` live in `DD_Install::create_tables()`.
- **Auto-migration guard** — `dish-dash.php:137-154`: on `admin_init` (priority 5) it compares
  `DD_VERSION` (`:47`, currently `3.10.49`) against the stored `dd_db_version` option; if
  `dd_db_version < DD_VERSION` it requires `install.php` and calls `DD_Install::create_tables()` (runs
  `dbDelta()`), then writes `dd_db_version = DD_VERSION`. So **adding a nullable column or index needs
  only: (1) edit the `CREATE TABLE` in `install.php`, (2) bump `DD_VERSION` in `dish-dash.php`** — the
  next admin page load migrates automatically. No WP-CLI step.
- **Limits (dbDelta):** can ADD tables/columns/indexes; **cannot** drop columns, do destructive type
  changes, or rename columns — those still need a manual `ALTER TABLE`. (Matches CLAUDE.md "Schema
  Changes" section; the "manual WP-CLI for new tables" line in Lessons/DB-Rules is the stale bit —
  superseded by the v3.4.92 guard.)

---

## OPEN QUESTIONS / RISKS (things that could regress existing behavior)

1. **The manual MoMo QR must be a NEW, separate path — do not repurpose `DD_MoMo`.** `DD_MoMo` is the
   Collections API (2.6%, auto-verified push). The manual QR (`*182*8*1*{merchant}*{amount}#`, 0.5%) has
   **no automated confirmation** — payment is verified by the customer tapping "I have paid" (an
   attestation, not a callback). This changes the trust model: unlike MoMo/PesaPal Option B, the order
   would be **stored before/without verified payment**. That is consistent with the new requirement
   "every order is ALWAYS stored," but it means `payment_status` for manual-MoMo starts `unpaid` and flips
   to a *claimed* state on the customer's word — not a real settlement. The restaurant reconciles against
   their MoMo statement. Confirm this is acceptable.

2. **"Every order always stored" contradicts current MoMo/PesaPal Option B**, which deliberately does NOT
   store on failure/abandon (transient-only). For the manual-QR path you must call `place_order()` up
   front (like the offline/COD path at `:1073`), not the deferred-transient pattern. Ensure the AI layer's
   data requirement is satisfied for the *manual* path specifically without breaking the existing
   Collections-API ghost-order fix for the *other* gateways.

3. **Dashboard-notification toggle must gate only the notification/status surface, never `place_order()`.**
   Polling (`admin.js` + `ajax_poll_notifications`), the bell/beep/browser alert, AND the customer-facing
   `/track-order/` timeline should be conditional; order INSERT + item INSERT + customer upsert + tracking
   event must remain unconditional. Gating in the wrong place would lose AI data (violates a STRICT dev
   principle) or break the kitchen queue.

4. **WhatsApp handoff re-add is JS-only but watch the escaping lesson.** The server already emits
   `whatsapp_url` / `whatsapp_customer_url` (§6). Re-adding a button/auto-open in `cart.js` must set the
   href **as-is** (the URL is already `rawurlencode`d in PHP). Do **not** pass it through `esc_url()` in
   PHP or any URL-sanitizer that strips `%0A`, or line breaks in the ticket die (documented v3.4.51 bug).
   The "one tap = flip status + open WhatsApp" needs the status-flip AJAX to resolve *before or in
   parallel with* the anchor click; mobile popup blockers historically forced the `<a target=_blank>`
   click-in-user-gesture trick (the exact code that was removed) — keep it inside the click handler.

5. **A new order status vs. reusing `payment_status`.** If the "claimed" state is modeled as a new
   `status` value it will silently break `update_status()` unless added to BOTH the labels and the
   **transitions** map (`class-dd-helpers.php:112-134`), and will need admin-list/filters/KPI updates
   (many `status IN (...)` queries, e.g. `:1423`, `:1489`, `:1697`, notification poll `:1421`). Reusing
   `payment_status` is far lower-risk. This is a design decision to confirm before building.

6. **Payment method rendering is WooCommerce-gateway-driven** (§1). The manual MoMo QR is *not* a WC
   gateway today. Decide whether to (a) register a lightweight WC gateway so it appears via
   `get_available_payment_gateways()` like the others, or (b) inject it as a synthetic entry into
   `ddCartData.paymentGateways` (`template-module.php:269`) / handle a new `payment_method` value in
   `ajax_place_order()`. Option (b) keeps it self-contained but diverges from how every current method is
   surfaced.

7. **Two independent settings, four combinations.** dashboard-notify {on,off} × whatsapp-handoff {on,off}
   must all be coherent — e.g. dashboard OFF + WhatsApp OFF means the restaurant gets the order via
   *neither* channel (only the stored row + their POS/EBM). Confirm that "silent store only" is a valid
   intended mode (implied by the Khana-Khazana driver), and that the customer still gets a confirmation
   screen in that mode.
