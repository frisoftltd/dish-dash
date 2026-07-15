# Investigation — Reservations: apply the 4 Order-Delivery capabilities
Deployed: **v3.10.57** / Phase 7 (Order Delivery Modes / Manual-MoMo track for ORDERS is COMPLETE).
READ-ONLY. No edits. Overwrites the prior order-track investigation.md.

Scope: assess bringing to RESERVATIONS the four capabilities shipped for orders —
(1) dashboard-notify gating, (2) customer WhatsApp handoff, (3) scan-&-pay MoMo (only when
"Require Deposit" is on; QR = deposit amount), (4) single-tap "I have paid" + sticky mobile-safe panel.
DECIDED: reservation settings are SEPARATE (`dish_dash_reservation_*`), not reused from orders.

---

## A. BOOKING FLOW

- **Form:** `templates/reservations/modal.php` — a 4-screen modal (`#dd-res-overlay` / `.dd-res-modal`),
  **separate from the cart drawer**. Screens: Date → Guests → Details → Confirm (screen 4).
- **Submit:** `assets/js/reservations.js` `submitReservation()` (:405) → AJAX `dd_submit_reservation` →
  `DD_Reservations_Module::ajax_submit_reservation()` (`modules/reservations/class-dd-reservations-module.php:48`).
- **DB row — written UP FRONT (like COD), NOT transient-then-confirm.** `$wpdb->insert()` at
  `class-dd-reservations-module.php:109` runs immediately on submit with `status = 'pending'`,
  `deposit_status = 'none'`. There is no "create only after payment" path in effect (see B).
- **Deposit branch is DEAD CODE.** `class-dd-reservations-module.php:103` hardcodes
  `$deposit_enabled = 0; // Deposit system deferred post-MVP`, so the deposit block (7B, :141 —
  `create_deposit_wc_order()` + autocancel scheduling + `requires_payment` response) never runs. The
  client mirrors this: `reservations.js:36` `const depositActive = false; // was: ddRes.depositEnabled`.
- **Confirmation:** inline in the modal (screen 4 `.dd-res-confirm-area`); `showWhatsAppButtons()`
  (`reservations.js:504`) renders "✅ Booking received!" + a Close button.
- **Table def:** `install.php:209` `dishdash_reservations` — a DUAL schema (legacy `table_id`,
  `customer_name`, `party_size`, `reservation_date/time`, `notes`, `duration_minutes` **plus** the live
  columns the module uses: `booking_ref` UNIQUE, `date`, `time` VARCHAR(5), `session`, `guests`, `name`,
  `whatsapp`, `special_requests`, `source`, `status` VARCHAR(20), `deposit_required` TINYINT,
  `deposit_amount` INT, `deposit_status` VARCHAR(20) DEFAULT 'none', `deposit_paid_at`, `payment_ref`,
  `is_test`, `customer_id`). **There is NO `payment_status` column** (unlike orders) — the deposit state
  lives in `deposit_status` (free-text VARCHAR(20) → a new `claimed_pending`/`claimed` value needs NO
  schema change).
- **Statuses & transitions:**
  - Live set (admin `ajax_update_status` allowed list, `class-dd-reservations-module.php:367`, and the
    POST fallback `class-dd-reservations-admin.php:50`): `pending`, `confirmed`, `cancelled`, `no_show`.
    No transition-map/gate — any allowed status can be set directly (admin-driven).
  - Deposit-path-only (dead): `pending_payment` (never actually written — see B), `auto_cancelled`.
  - `deposit_status` values: `none` (default), `paid` (`on_deposit_payment_complete`), `failed`
    (`run_autocancel`).

## B. DEPOSIT MODEL (critical)

- **"Require Deposit" ON today → NOTHING happens.** The setting `dd_reservation_deposit_enabled` exists
  and the Settings UI writes it, but `ajax_submit_reservation()` ignores it (`$deposit_enabled = 0`
  hardcoded, `:103`) and `reservations.js` forces `depositActive = false` (`:36`). So a booking is always
  created up front, free, `deposit_status = 'none'`.
- **If the hardcode were removed** (`$deposit_enabled` read from the option), the existing path is:
  insert row up front → `create_deposit_wc_order()` (`:399`) builds a **WooCommerce order with
  `payment_method = 'pesapal'`** and a deposit fee → returns `payment_url` for a WC redirect →
  `on_deposit_payment_complete()` (`:428`, on `woocommerce_payment_complete`) sets
  `deposit_status = 'paid'`, `status = 'confirmed'`. **This is a redirect/gateway path, NOT the
  in-drawer manual-MoMo model** — it collects via PesaPal (2.6%), not a USSD QR.
- **Auto-cancel:** scheduled ONLY inside the (dead) deposit branch —
  `wp_schedule_single_event( time()+hours, 'dd_reservation_autocancel', [id] )` (`:154`). Callback
  `run_autocancel()` (`:514`) selects `WHERE ... AND status = 'pending_payment'` and sets
  `status='auto_cancelled', deposit_status='failed'`. **Two problems:** (1) it's never scheduled today
  (deposit off); (2) even if enabled, the insert writes `status='pending'` (`:106`), never
  `'pending_payment'`, so `run_autocancel` would **match nothing** — a latent bug. There is no on-load
  sweep; it's purely the single cron event.
- **Ghost-booking equivalent?** The free path (today) always writes up front — that's intended, not a
  ghost. The deposit path (if enabled) writes the row BEFORE payment (up front) and schedules autocancel
  — so an abandoned deposit booking WOULD leave a row (a ghost-booking), unlike orders' Option-B
  transient model. But it's disabled, so no live ghost today.
- **Deposit AMOUNT (needed for the QR):** `calculate_deposit_amount()` (`:393`) returns
  `(int) get_option('dd_reservation_deposit_amount', 2000)` — **fixed only**; percent is explicitly
  unimplemented ("needs a base order value not available at booking time"). And it is **not even called**
  in submit — `ajax_submit_reservation` hardcodes `$deposit_amount = 0` (`:104`). So today the stored
  amount is always 0. For a QR, the amount would come from `get_option('dd_reservation_deposit_amount')`
  (fixed); percent type has no computable amount at booking time.

## C. RESERVATION NOTIFICATIONS (vs orders)

- **Reservations have NO own polling JS.** `DD_Reservations_Admin::enqueue_assets()`
  (`class-dd-reservations-admin.php:30`) enqueues only `dashicons` + `reservations-admin.css` — no script.
- **Dashboard reservation alerts come through the UNIFIED admin bell**, owned by the ORDERS module:
  `ajax_poll_notifications()` (`class-dd-orders-module.php:1478`) queries pending reservations
  (`:1511-1517`, `WHERE status='pending'`) alongside pending orders and returns a merged `pending_items`;
  `assets/js/admin.js` renders them (`item.type === 'reservation'`), beeps, and badges. The reservations
  admin page consumes deep-links (`?open_reservation=`, `class-dd-reservations-admin.php:61`) but does no
  polling itself.
- **Currently gated by the ORDER flag.** R2 made `ajax_poll_notifications()` early-return empty when
  `get_option('dish_dash_order_notify_dashboard','1') !== '1'` (`:1490`), and `admin.js` `initPolling()`
  runs only when `config.notifyEnabled` (the order flag, set in `class-dd-admin.php`). **So today,
  turning OFF order-notify also silences reservation alerts** — they are coupled.
- **Where `dish_dash_reservation_notify_dashboard` would hook in (mirror R2, gate ALERTS only):**
  - **Server:** `ajax_poll_notifications()` must be DECOUPLED — include the orders query only when
    `dish_dash_order_notify_dashboard` is on, and the reservations query only when
    `dish_dash_reservation_notify_dashboard` is on (instead of the current single all-or-nothing early
    return). Build `pending_items` from whichever types are enabled.
  - **Enqueue (client):** `class-dd-admin.php` currently sets `notifyEnabled = (order flag)`; it must
    become `notifyEnabled = (order flag OR reservation flag)` so `admin.js` still polls when only
    reservations are enabled. (The bell then shows only the enabled types via the server split above.)
  - The Reservations admin PAGE (list/filters/status updates/deep-links) is independent of the poll and
    stays functional regardless — same guarantee as R2 for the Orders page.

## D. THE v3.5.25 REMOVAL

- **What was removed:** the WhatsApp buttons that `showWhatsAppButtons()` used to render on the booking
  confirmation. Current `showWhatsAppButtons(adminUrl, customerUrl)` (`reservations.js:504-517`) **ignores
  its two URL params** and renders only "✅ Booking received!" + a Close button. (Commits `539b13f` +
  `9841a8f`, 2026-06-08 — the cart.js half was the order auto-open covered in the prior track.)
- **Server still builds + returns the WhatsApp URLs.** `ajax_submit_reservation()` calls
  `DD_Notifications::on_reservation_created(...)` (`class-dd-reservations-module.php:177`) and returns
  `admin_url` + `customer_url` (`:205-206`); `submitReservation()` still PASSES them into the function
  (`reservations.js:475` `showWhatsAppButtons( data.admin_url, data.customer_url )`). So the restaurant
  ticket URL is **on the wire and in the JS**, just not rendered — exactly analogous to orders'
  `whatsapp_url`. Re-adding a tap-only button is a JS-only change.
- **Builder + ticket contents:** `DD_Notifications::on_reservation_created()`
  (`modules/orders/class-dd-notifications.php:43`) → `admin_url` = the RESTAURANT ticket (number from
  `dd_whatsapp_admin`), `customer_url` = the customer's own number. Both `rawurlencode`d (not `esc_url`).
  Admin ticket lines: "NEW RESERVATION 🔔", restaurant name, `Ref`, `Date`, `Time (session)`, `Guests`,
  `Table`, `Name`, `WhatsApp`, and `Requests` (if any).
- **Stated reason for removal:** **no stated reason found.** The commit messages + the CLAUDE.md v3.5.25
  entry describe WHAT ("remove Notify Restaurant WhatsApp button from customer booking confirmation") but
  give no WHY (same finding as the order-track investigation).

## E. CONFIRMATION SCREEN

- **Rendering:** inline inside the modal — `templates/reservations/modal.php` screen 4
  (`#dd-res-screen-4` / `.dd-res-confirm-area`); populated by `reservations.js` `populateConfirm()` (:381)
  and, after submit, `showWhatsAppButtons()` (:504).
- **Modal, NOT the cart drawer** — its own `#dd-res-overlay`. It does **not** share the cart drawer's
  close/overlay/Escape logic; it has its OWN in `reservations.js`:
  - `#dd-res-close` click → `closeModal()` (:135)
  - overlay backdrop click (target === overlay) → `closeModal()` (:137-139)
  - `Escape` keydown → `closeModal()` (:141-143)
  - **No `blur` / `visibilitychange` / `pagehide` / timeout close** → app-switching already survives (same
    as the order panel). For a sticky reservation pay-panel, only the overlay-click + Escape would need
    guarding, scoped to that state.
- **Data available client-side at confirmation:** `data.booking_ref`, `data.admin_url`,
  `data.customer_url` (`reservations.js:456-475`). **NOT returned:** the reservation DB `id`, and (since
  deposit is off) no deposit amount. A claim endpoint would key on `booking_ref` (UNIQUE) unless the
  response is extended to include the `id`.

## F. SETTINGS INFRASTRUCTURE

- **Card render:** `admin/pages/settings.php:585` ("📅 Reservations"): Require Deposit, Deposit Type,
  Deposit Amount, Auto-Cancel After, Allow Refunds, Refund Window, Refund Policy.
- **Save handler** (`admin/pages/settings.php:86-92`, inside the `dd_save_settings` + nonce block):
  ```php
  update_option( 'dd_reservation_deposit_enabled',    isset( $_POST['dd_reservation_deposit_enabled'] ) ? 1 : 0 );
  update_option( 'dd_reservation_deposit_type',       sanitize_text_field( $_POST['dd_reservation_deposit_type'] ?? 'fixed' ) );
  update_option( 'dd_reservation_deposit_amount',     absint( $_POST['dd_reservation_deposit_amount'] ?? 2000 ) );
  update_option( 'dd_reservation_autocancel_hours',   absint( $_POST['dd_reservation_autocancel_hours'] ?? 2 ) );
  update_option( 'dd_reservation_refund_enabled',     isset( $_POST['dd_reservation_refund_enabled'] ) ? 1 : 0 );
  update_option( 'dd_reservation_refund_hours',       absint( $_POST['dd_reservation_refund_hours'] ?? 24 ) );
  update_option( 'dd_reservation_refund_policy_text', sanitize_textarea_field( $_POST['dd_reservation_refund_policy_text'] ?? '' ) );
  ```
- **Existing reservation/deposit option keys** (note the prefix): `dd_reservation_deposit_enabled` (0/1),
  `dd_reservation_deposit_type` (fixed|percent), `dd_reservation_deposit_amount` (2000),
  `dd_reservation_autocancel_hours` (2), `dd_reservation_refund_enabled` (0/1),
  `dd_reservation_refund_hours` (24), `dd_reservation_refund_policy_text`, plus
  `dd_reservation_sections` (JSON section names).
- **Checkbox pattern:** reservations use `isset($_POST[$k]) ? 1 : 0` (**INT**), whereas orders'
  `dd_fees_enabled` / R1 order-mode toggles use `isset($_POST[$k]) ? '1' : '0'` (**string**). Both render
  correctly with `checked()`. ⚠️ **Prefix mismatch to decide:** existing reservation keys are
  `dd_reservation_*`; the brief mandates NEW keys as `dish_dash_reservation_*` (matching the order-mode
  `dish_dash_order_*` naming). So the new toggles would be e.g. `dish_dash_reservation_notify_dashboard`,
  `dish_dash_reservation_handoff_whatsapp`, sitting beside the older `dd_reservation_*` deposit keys —
  functional but inconsistent; confirm naming.

## G. QR REUSE

- **Vendored lib is reusable as-is.** `assets/vendor/qrcode/qrcode.js` exposes a global `qrcode`, enqueued
  as `dd-qrcode` in `class-dd-template-module.php` on every DishDash frontend page (the reservation modal
  lives on the homepage, which loads it), so the `qrcode` global IS available to `reservations.js`.
- **But the cart.js QR CODE is welded into cart.js.** `makeQrDataUrl()`, `renderMomoManualPanel()`,
  `copyText()`/`legacyCopy()` are private functions inside the `cart.js` IIFE — not globally exposed, so
  `reservations.js` cannot call them. Reuse options: (a) reimplement a tiny `makeQrDataUrl` wrapper over
  the shared `qrcode` global + rebuild the one-line payload `tel:*182*8*1*{merchant}*{amount}%23` in
  reservations.js (small duplication), or (b) refactor those helpers into a shared standalone script
  enqueued for both. The QR PAYLOAD format and the vendored lib are fully reusable; only the JS helpers
  are not shared.
- **Merchant code on the reservation surface:** `ddReservations` (localized at
  `class-dd-template-module.php:331`) exposes `ajax_url`, `nonce` (`dish_dash_frontend`), `depositEnabled`,
  `depositAmount`, `refundPolicy` — **NOT `momoMerchantCode`**. It IS in `ddCartData` (added R7), and on
  the homepage both scripts load, so `window.ddCartData.momoMerchantCode` is technically reachable — but
  the clean fix is to add `momoMerchantCode` (and the amount) to the `ddReservations` localize.

---

## OPEN QUESTIONS / RISKS

1. **"I have paid" vs Auto-Cancel — the table-hold problem.** Auto-cancel today is (a) never scheduled
   (deposit off) and (b) keyed on `status='pending_payment'`, which is never written — so it matches
   nothing. A manual-deposit booking placed up front with `deposit_status='claimed_pending'` would be
   caught by **neither** the existing cron (wrong key) nor any sweep, so an **un-paid, un-claimed booking
   would hold the slot indefinitely**. Decisions needed: does the new manual path (i) schedule an
   auto-cancel against the `claimed_pending` *deposit* state (cancel the booking if not claimed within N
   hours), and (ii) does a customer tapping "I have paid" (→ `deposit_status='claimed'`) STOP that
   auto-cancel? Note this is an ATTESTATION (like orders): a customer can claim without paying → booking
   looks confirmed; the restaurant reconciles via their MoMo statement. Never mark it verified/`paid`.

2. **"Require Deposit" flag collision.** `dd_reservation_deposit_enabled` currently (nominally) drives the
   PesaPal WC-order deposit path (dead via the `:103` hardcode). Gating the new manual QR on the SAME flag
   means: if the `:103`/`:36` hardcodes are removed to enable anything, the OLD WC/pesapal path also comes
   alive. Must decide **manual-MoMo replaces the WC deposit path** vs **coexists** (e.g. deposit *method*
   selector). Both the PHP (`:103`) and JS (`:36`) force-off switches must be addressed to enable any
   deposit UI, and `on_deposit_payment_complete` (WC hook) should not fight a manual claim.

3. **Percent deposit type has no amount at booking time.** `calculate_deposit_amount()` returns the fixed
   option regardless; percent is unimplemented (no base order value at booking). A QR needs a concrete
   integer → the manual path must use `deposit_type='fixed'` (encode `dd_reservation_deposit_amount`) and
   fall back / block for `percent`.

4. **Amount is currently stored as 0 and never computed** in `ajax_submit_reservation` (`:104`).
   Enabling a manual deposit requires wiring the fixed amount into the insert, the AJAX response, AND the
   QR — three sites — consistently.

5. **No reservation `id` reaches the client.** The confirmation response returns only `booking_ref`. A
   reservation "I have paid" claim endpoint must target by `booking_ref` (UNIQUE, safe) OR the response
   must be extended to include the row `id`. (Mirror the order claim's guard: exists + is-a-deposit-
   reservation + idempotent flip `claimed_pending`→`claimed`; nopriv, `verify_nonce()`.)

6. **Notification decoupling must not break orders.** The reservation-notify guard cannot simply reuse the
   R2 all-or-nothing early return (that returns BOTH types empty). `ajax_poll_notifications()` and the
   `admin.js` enqueue flag must be split per-type (orders gated by the order flag, reservations by the new
   reservation flag; poll if either is on) — otherwise enabling one silences the other.

7. **Confirmation UX differs from orders.** The reservation flow is a 4-screen MODAL with its own
   close/overlay/Escape (not the cart drawer). The sticky + mobile-safe pay panel from R8 cannot be
   reused literally — it would be a NEW panel/state inside the reservation modal, guarding the modal's own
   overlay-click + Escape (no blur/visibility close exists there either).

8. **WhatsApp handoff builder differs.** Reuse `on_reservation_created()`'s `admin_url` (restaurant
   ticket) — NOT the order `whatsapp_url`. It's already returned; only a JS consumer + the new
   `dish_dash_reservation_handoff_whatsapp` gate are needed.
