# Investigation: WhatsApp Touchpoints (Mode B Planning — WhatsApp Business Cloud API)

**Type:** Read-only investigation. No files edited, no DB writes, no commits.
**Date:** 2026-09-07

Goal: enumerate every WhatsApp touchpoint in the codebase to plan the migration
from `wa.me` click-to-chat links to WhatsApp Business Cloud API (Mode B).

---

## 1. Full file:line inventory (`wa.me`, `whatsapp`, `WhatsApp`, `dd_whatsapp_admin`, `dd_whatsapp_kitchen`)

### PHP

| File | Lines |
|---|---|
| `scripts\dd-r3-migrate.php` | 66, 76-77, 156, 160, 168, 184-185, 194 — customer identity migration script, references `customers.whatsapp` column |
| `admin\pages\settings.php` | 52-53 (save `dd_whatsapp_admin`/`dd_whatsapp_kitchen`), 56-64 (save `dd_rider_whatsapp[]` array), 105 (save `dd_reservation_handoff_whatsapp` checkbox), 115 (save `dish_dash_order_handoff_whatsapp` checkbox), 484-490 (Admin WhatsApp Number field), 495-508 (Kitchen WhatsApp Number field + rider intro), 520-535 (per-rider WhatsApp inputs), 748-757 (reservation WhatsApp Handoff checkbox), 890-900 (order WhatsApp Handoff checkbox) |
| `admin\pages\orders.php` | 291-298 (build kitchen/rider wa.me URLs server-side for modal), 651-652 (localize `kitchenPhone`/`adminPhone` into JS), 863-865 (JS builds `wa.me` URLs client-side for kitchen/customer/admin), 964/974/977/984 (render Notify Kitchen / Notify Rider / Customer / Rider Notified buttons) |
| `admin\pages\brand-identity.php` | 20, 46, 108, 413-415 — `dish_dash_whatsapp` public brand/footer contact number field |
| `admin\pages\audit.php` | 26 — audit checklist line item "WhatsApp notification fires after order placed" (manual QA checkbox, not code) |
| `modules\customers\class-dd-customers-module.php` | 269 (search WHERE clause), 484 (search placeholder), 600/639 (customer table column), 747/757/764 (CSV export) |
| `modules\template\class-dd-template-module.php` | 114 (`add_action('wp_footer', 'inject_birthday_whatsapp')`), 365-368 (`wp_localize_script` → `ddCartData.whatsappAdmin`/`whatsappHandoff`), 441-443 (`wp_localize_script` → `ddReservations.whatsappHandoff`), 1166 (`window.DD.whatsapp_admin` — Menu-page header bridge), 1331 (same, second header copy), 1480/1533 (footer social icon, reads `dish_dash_whatsapp`), 1626-1645 (`inject_birthday_whatsapp()`) |
| `install.php` | 223, 263 (reservations table `whatsapp VARCHAR(30)`), 380, 385, 398 (customers table `whatsapp VARCHAR(20)` + `UNIQUE KEY whatsapp`) |
| `modules\audit\class-dd-audit-runner.php` | 360-372 — automated audit check that `wa.me` links use `esc_attr()` not `esc_url()` (esc_url strips the `%0A` newline encoding needed in `?text=`) |
| `modules\reservations\class-dd-reservations-module.php` | 74/84/101 (read+validate `$_POST['whatsapp']` on submit), 162 (insert into reservations row), 209-231 (`DD_Notifications::on_reservation_created()` call), 284-325 (admin confirmation email's inline wa.me click-to-chat button), 383-384 (reservation summary table row in email), 606-658 (deposit claim: SELECT whatsapp, build booking ref from it), 975-1030 (`ajax_pesapal_request_deposit()` — builds a wa.me deposit-payment-link message) |
| `modules\reservations\class-dd-reservations-admin.php` | 134/150 (admin list search), 329/393/404/416-421 (admin list WhatsApp column + wa.me link), 560/594/718/827 (admin detail modal), 875-1066 (JS: `buildResWhatsAppLink()` builds wa.me client-side from row data; deposit payment-link button) |
| `modules\profile\class-dd-profile-module.php` | 6, 163 — customer-facing profile module reads `customer->whatsapp` as the user's own phone-link identity |
| `modules\orders\class-dd-orders-module.php` | 116-117 (register `dd_send_birthday_whatsapp` cron hook), 133-179 (`send_birthday_whatsapp()` handler), 189/210 (order data mapping), 242-306 (`on_order_received_page()` — reads transient, auto-redirects), 532 (comment, kitchen/admin/email/modal readers), 957-975 (AJAX validate `$_POST['whatsapp']`), 1025/1160-1421 (order creation: customer upsert, ref generation, schedule birthday cron, response payload `whatsapp_url`/`whatsapp_customer_url`), 1754/1798 (`whatsapp_paid_url` in PesaPal poll/IPN response), 1985-2057 (`build_notification_urls_after_paid()` — builds "I have paid" handoff URL), 2112/2210 (payment-complete notification comments), 2647-2649 (customer phone lookup for order-status filters) |
| `modules\orders\class-dd-notifications.php` | Entire file — see §2/§6 below. Class docblock (lines 4, 10-11) explicitly flags this file as the Mode B swap point. |
| `modules\orders\class-dd-customer-profile.php` | 12, 67, 78, 103-105, 178-180 — customer profile page reads `customer->whatsapp` (own identity) and `dish_dash_whatsapp` option (restaurant's public contact, exposed as `whatsapp_contact`) |
| `modules\orders\class-dd-customer-manager.php` | 5, 10, 26, 33-34, 349-420 (`normalize_phone()` — canonical phone normalizer, see §5) |
| `templates\reservations\modal.php` | 103-104 — customer-facing reservation form WhatsApp number input field |
| `templates\profile\my-profile.php` | 99-104 — customer's own profile page, static wa.me link to restaurant (`whatsapp_contact`) |
| `templates\page-dishdash.php` | 100 — homepage reads `dish_dash_whatsapp` option |
| `templates\cart\cart.php` | 161-162 (checkout form WhatsApp number field), 206-216 (order-confirmation opt-in handoff button + "I have paid" handoff button markup) |

### JS

| File | Lines |
|---|---|
| `assets\js\reservations.js` | 34 (state init), 102/116/143/370 (phone-picker field wiring), 448/458 (validation + read), 480 (summary display), 520 (form submit), 596-609 (`showWhatsAppButtons()` call sites), 629-660 (`showWhatsAppButtons()` definition — tap-only handoff button), 852/951-983 (deposit-claim flow, retry note) |
| `assets\js\cart.js` | 219-255 ("I have paid" claim handler, handoff open-in-gesture), 829 (`momoManualWhatsappUrl` from AJAX response), 894 (country-code picker attach), 1092/1127 (validation + payload field), 1277-1290 (PesaPal poll success → reveal "I have paid" wa.me button), 1354-1382 (order-confirmation handoff button reveal + birthday-cookie set) |
| `assets\js\frontend.js` | 541 (`DD.whatsapp_admin` read for closed-banner), 700 (closed-store modal — client-built wa.me link, auto-encoded static message) |

---

## 2. Every WhatsApp message currently built

All message-building lives in `modules\orders\class-dd-notifications.php` (order-related) and inline in `modules\reservations\class-dd-reservations-module.php` (reservation-related), plus two smaller one-offs in `class-dd-orders-module.php` (birthday) and `admin\pages\orders.php` (kitchen/rider, built client-side in JS from server-supplied numbers/order data).

### 2.1 `DD_Notifications::build_admin_whatsapp_url()` — `class-dd-notifications.php:353-390`
- **Trigger:** Called from `DD_Notifications::on_order_created()` (offline gateways: cod/bacs/cheque, fired synchronously in `ajax_place_order()`) and from `DD_Notifications::on_payment_complete()` (hooked to `woocommerce_payment_complete`, online gateways).
- **Recipient:** Admin/restaurant (`dd_whatsapp_admin` option).
- **Message:**
  ```
  🔔 New Order {order_number} — {restaurant_name}
  ──────────────────
  {qty}× {item_name}
     {variation lines}
     Note: {special_note}
  ──────────────────
  Total: {total} RWF
  Delivery: {delivery_fee formatted or "FREE"}
  Payment: {payment_method}
  📍 {delivery_address}
  📞 {customer_phone}
  👤 {customer_name}
  ```
- **Server-generated then opened by JS.** Returns empty string if `dd_whatsapp_admin` unset.

### 2.2 `DD_Notifications::build_customer_whatsapp_url()` — `class-dd-notifications.php:323-345`
- **Trigger:** Same call sites as 2.1 (`on_order_created()`).
- **Recipient:** Customer (their own submitted WhatsApp number, from order data).
- **Message:**
  ```
  ✅ Order Confirmed! — {restaurant_name}
  ──────────────────
  Order {order_number}
  Estimated time: {dd_delivery_eta}
  Payment: {payment_method}
  Questions? Call us: +{admin_phone}
  ```
- **Server-generated then opened by JS** (tap-only button, `#ddConfirmWhatsapp` in `cart.php`, revealed only if `dish_dash_order_handoff_whatsapp` opt-in is on).

### 2.3 `DD_Notifications::build_kitchen_whatsapp_url()` — `class-dd-notifications.php:399-475`
- **Trigger:** Called inline from `admin\pages\orders.php:294` when rendering the admin order modal (button click, "Notify Kitchen").
- **Recipient:** Kitchen (`dd_whatsapp_kitchen` option).
- **Message:**
  ```
  NEW ORDER — {restaurant_name}

  Order:  {order_number}
  Time:   {created_at formatted}

  {qty}x {item_name} ({variation/addon k:v pairs})
     Note: {special_note}
  ...

  Deliver to: {delivery_address}
  Note: {special_instructions}
  ```
- **Server-generated (PHP builds URL on page render), opened by JS** (`<a href target="_blank">` in the admin order modal, click-driven).

### 2.4 `DD_Notifications::build_customer_paid_whatsapp_url()` — `class-dd-notifications.php:488-562`
- **Trigger:** `DD_Orders_Module` build_notification_urls_after_paid() (~`class-dd-orders-module.php:2033-2057`), called after PesaPal payment completes (poll success or IPN), and reused by the manual MoMo "I have paid" claim path.
- **Recipient:** Admin (`dd_whatsapp_admin`) — customer's device sends it, but it's addressed to the restaurant.
- **Message:**
  ```
  ✅ I have paid — {restaurant_name}
  ──────────────────
  Order {order_number}
  {qty}x {item_name} (...)
     Note: {special_note}
  ──────────────────
  Total: {total} RWF
  Payment: {payment_method}
  📍 {delivery_address}
  👤 {customer_name}
  ```
- **Server-generated then opened by JS**, tap-only (`#ddConfirmPaidWhatsapp`, `cart.js:1277-1290`), opened "in-gesture" per comments to dodge popup blockers.

### 2.5 `DD_Notifications::build_rider_whatsapp_url()` — `class-dd-notifications.php:571-606`
- **Trigger:** Called inline from `admin\pages\orders.php:298` in the admin order modal per-rider ("Notify Rider" button), when order status → Ready.
- **Recipient:** Rider (`dd_rider_whatsapp[]` — per-rider number from Settings).
- **Message:**
  ```
  PICKUP READY — {restaurant_name}

  Order:   {order_number}
  Action:  Collect from kitchen NOW

  Deliver to: {delivery_address}
  Customer:   {customer_name}
  Phone:      {customer_phone}
  Collect:    {total} RWF ({payment method label})

  — {restaurant_name}
  ```
- **Server-generated (built inline in PHP on modal render), opened by JS.**

### 2.6 `DD_Notifications::build_customer_ontheway_url()` — `class-dd-notifications.php:615-649`
- **Trigger:** Manually triggered from the admin Orders page when status moves to Ready (button click, not auto-fired on status change — no hook wiring found for this one beyond manual link render).
- **Recipient:** Customer.
- **Message:**
  ```
  YOUR ORDER IS ON THE WAY!

  Hi {customer_name}, your order {order_number} has left our kitchen.

  Estimated arrival: {eta}

  Questions? Call us: {phone_clean}
  — {restaurant_name}
  ```
- **Server-generated, opened by JS.**

### 2.7 `DD_Notifications::on_reservation_created()` — `class-dd-notifications.php:43-111`
- **Trigger:** `ajax_submit_reservation()` (`class-dd-reservations-module.php:209-220`), fires on every new reservation (free-booking path).
- **Recipient:** Both — returns `admin_url` and `customer_url`.
  - Admin message:
    ```
    NEW RESERVATION 🔔
    {restaurant_name}

    Ref:    {booking_ref}
    Date:   {date, "D, d M Y"}
    Time:   {time} ({session})
    Guests: {guests} guest(s)
    Table:  {table_pref or "No preference"}

    Name:     {name}
    WhatsApp: {whatsapp}
    Requests: {special_requests}     (only if present)
    ```
  - Customer message:
    ```
    RESERVATION CONFIRMED ✅
    {restaurant_name}

    Hi {name}, your table is booked! 🎉

    Ref:    {booking_ref}
    Date:   {date}
    Time:   {time} ({session})
    Guests: {guests} guest(s)

    We look forward to welcoming you! 🍽️

    💳 Deposit paid: {deposit_amount} RWF     (only if deposit paid)

    Need to change anything? Call us: {admin_phone}     (only if phone set)
    ```
- **Server-generated then opened by JS** — `showWhatsAppButtons(adminUrl, customerUrl)` in `reservations.js:629-660`; the admin-side button is tap-only and gated by `dd_reservation_handoff_whatsapp` opt-in.

### 2.8 Admin reservation-confirmation email's inline wa.me button — `class-dd-reservations-module.php:284-325`
- **Trigger:** `send_admin_email()`, fired on reservation creation/status change (part of the admin notification email, not a customer-facing wa.me send).
- **Recipient:** Admin — it's a click-to-chat button *inside* the admin's email, targeting the *customer's* number, letting the restaurant one-tap message the guest.
- **Message** (status-driven, v3.18.43):
  - If confirmed: `Hello {name}, this is {restaurant}. Your reservation {ref} on {date} at {time} has been confirmed. We look forward to seeing you.`
  - If pending: `Hello {name}, this is {restaurant} about your reservation {ref} on {date} at {time}.`
- **Server-generated**, embedded directly as an `<a href>` in the HTML email body (not returned via AJAX/JS at all — this one lives purely inside an email template).

### 2.9 `ajax_pesapal_request_deposit()` deposit-payment-link message — `class-dd-reservations-module.php:1008-1031`
- **Trigger:** Admin AJAX action `dish_dash_reservation_deposit` PesaPal-deposit-request handler (staff clicks a button in the reservation accept modal).
- **Recipient:** Customer (booking's `whatsapp` field), but the send is manual — staff clicks the resulting wa.me link.
- **Message:**
  ```
  DEPOSIT PAYMENT LINK 💳
  {restaurant_name}

  Hi {name}, please complete your deposit payment to secure your table:

  Ref: {booking_ref}
  Amount: {deposit_amount} RWF

  Pay here: {pesapal redirect_url}

  Your booking will be confirmed once payment is received.
  ```
- **Server-generated then opened by JS** — `reservations-admin.php:1058-1066` sets `link.href = data.whatsapp_url`.

### 2.10 `send_birthday_whatsapp()` — `class-dd-orders-module.php:136-179`
- See full detail in §4 below.

### 2.11 Closed-store banner message — `frontend.js:690-701` (inline JS string, no PHP builder)
- **Trigger:** Client-side only — fires when `window.DD.hours_state` indicates the restaurant is closed and the visitor opens the closed-modal.
- **Recipient:** Admin (`DD.whatsapp_admin`, sourced from `dd_whatsapp_admin`).
- **Message (static, not templated with any order/reservation data):**
  `"Hi! I just visited your website. Please notify me when you're open so I can place my order 🍽️"`
- **Fully client-side** — the only touchpoint in the whole inventory with zero PHP message-building; JS reads `DD.whatsapp_admin` directly and builds the `wa.me` URL and `encodeURIComponent()`'d text itself.

### 2.12 Admin reservation-list "Notify" button — `class-dd-reservations-admin.php:937-1000` (`buildResWhatsAppLink()`)
- **Trigger:** Admin clicks a per-row "WhatsApp" action in the reservations admin list/modal.
- **Recipient:** Customer.
- **Message:** Built entirely client-side in JS from the row's already-fetched data (`r.whatsapp`, `r.name`, `r.booking_ref`, etc.) — ported "verbatim from the row's old PHP" per the inline comment at line 937, i.e. duplicate logic of a formerly-server-side builder, now live only in JS.
- **Fully client-side.**

---

## 3. Frontend consumption of returned `wa.me` URLs

| JS file | Handler | DOM target | Tap-only vs auto-open |
|---|---|---|---|
| `assets\js\cart.js:1358-1368` | Order-placed AJAX success handler | `#ddConfirmWhatsapp` — sets `.href`, toggles `.hidden` | **Tap-only** (`<a>`, gated by `whatsappHandoff` flag) |
| `assets\js\cart.js:1277-1290` | PesaPal poll-success handler | `#ddConfirmPaidWhatsapp` — sets `.href`, `.textContent`, `.hidden` | **Tap-only**, but comment notes it's revealed "in-gesture" specifically so a follow-up tap isn't popup-blocked |
| `assets\js\cart.js:829` | Manual MoMo response handler | `momoManualWhatsappUrl` var, consumed by `renderMomoManualPanel()` (not shown in matched lines, presumably sets an `<a href>` in that panel) | Tap-only (pattern consistent with rest of file) |
| `assets\js\reservations.js:629-660` (`showWhatsAppButtons`) | Called after reservation AJAX success (line 596-609) and after deposit-claim success (line 852, 981) | Injected `<a class="dd-confirm-panel__whatsapp">` inside `.dd-res-confirm-area` | **Tap-only**, `target="_blank" rel="noopener noreferrer"`, explicitly commented "NEVER auto-open" |
| `assets\js\reservations.js:959-961` | Deposit-claim success handler | `depositWhatsappUrl` — opened directly | **Auto-opened in-gesture** (comment: "open in-gesture so it isn't popup-blocked") — the one reservation-side exception to tap-only |
| `assets\js\frontend.js:541,700` | Closed-banner modal render | Inline `<a href="https://wa.me/...">` built into modal HTML string | Tap-only (`target="_blank"`) |
| `modules\orders\class-dd-orders-module.php:299-304` (inline `<script>`, not a separate JS file) | `on_order_received_page()` — WooCommerce thank-you page | `window.location.href` | **Fully auto-opened**, 800ms `setTimeout`, no user gesture — this is a server-rendered inline script, not asset JS |
| `modules\template\class-dd-template-module.php:1638-1643` (inline `<script>` from `inject_birthday_whatsapp()`) | Fires on `wp_footer` when birthday transient + cookie both present | `window.location.href` | **Fully auto-opened**, 1500ms `setTimeout`, no user gesture |
| `admin\pages\orders.php:863-865, 964-984` | Admin order modal render (JS builds `wa.me` client-side from localized `kitchenPhone`/`adminPhone` + order data) | `<a href target="_blank">` for Notify Kitchen / Notify Rider / Customer buttons | Tap-only |
| `modules\reservations\class-dd-reservations-admin.php:1058-1066` | PesaPal-deposit-request AJAX success | `link.href = data.whatsapp_url` | Tap-only |

**Summary:** every *customer-facing* touchpoint reachable from an async AJAX response is tap-only (`<a>` with `.hidden` toggling). The two exceptions that auto-redirect via `window.location.href` are both **server-rendered inline `<script>` blocks** injected on full page loads (WooCommerce thank-you page, and the birthday footer injector) — not the async cart/reservation JS flows.

---

## 4. `dd_send_birthday_whatsapp` — scheduling and message

- **Scheduled by:** `wp_schedule_single_event()` at `class-dd-orders-module.php:1406-1414`, called from inside the order-placement flow (`ajax_place_order()`), gated on `$customer_result['is_first_order']` — i.e. it only fires once, on a customer's very first order.
  ```php
  wp_schedule_single_event(
      time() + 120,                    // fires 2 minutes after order placement
      'dd_send_birthday_whatsapp',
      [ $customer_result['customer_id'], $whatsapp, $customer_name ]
  );
  ```
- **Hook registration:** `add_action( 'dd_send_birthday_whatsapp', [ $this, 'send_birthday_whatsapp' ], 10, 3 )` — `class-dd-orders-module.php:117`.
- **Handler:** `send_birthday_whatsapp( int $customer_id, string $whatsapp, string $name )` — `class-dd-orders-module.php:136-179`.
  - Guards: re-reads `dd_birthday_asked` flag from `wp_dishdash_customers` and returns early if already set (never sends twice) — line 144-148.
  - Normalizes phone via `DD_Customer_Manager::normalize_phone()`.
  - Generates a one-time birthday token + URL: `home_url('/birthday/?c=' . $token)`.
  - **Message:**
    ```
    🎁 One more thing, {first_name}!
    We'd love to surprise you on your birthday.
    Share it here (10 sec):
    👉 {birthday_url}
    — {restaurant_name} 🍽
    ```
  - Stores the built `wa.me` URL as a transient: `set_transient('dd_birthday_wa_' . $customer_id, $wa_url, 2 * HOUR_IN_SECONDS)`.
  - Marks `dd_birthday_asked` via `DD_Customer_Manager::mark_birthday_asked()`.
- **Delivery to the browser:** `inject_birthday_whatsapp()` (`class-dd-template-module.php:1629-1645`), hooked on `wp_footer` (registered at line 114). On every page load it reads the `dd_customer_id` cookie (set by `cart.js:1378-1382` right after order placement), looks up the matching transient, and if present, **auto-redirects** via `window.location.href` after a 1500ms `setTimeout`, then deletes the transient (one-time only).
- **Recipient:** Customer (their own number).

---

## 5. `wp_options` storing WhatsApp numbers

| Option key | Set on | Read at (file:line) |
|---|---|---|
| `dd_whatsapp_admin` | `admin\pages\settings.php:52` (Settings page) | `admin\pages\settings.php:487`; `admin\pages\orders.php:652`; `modules\template\class-dd-template-module.php:365, 1166, 1331`; `modules\orders\class-dd-notifications.php:45, 101, 332, 354, 489`; `assets\js\frontend.js:541` (via `window.DD.whatsapp_admin`, localized from the above) |
| `dd_whatsapp_kitchen` | `admin\pages\settings.php:53` | `admin\pages\settings.php:500`; `admin\pages\orders.php:651`; `modules\orders\class-dd-notifications.php:400` |
| `dd_rider_whatsapp[]` (part of the `dd_riders` array option, rebuilt from `dd_rider_whatsapp[]` POST array) | `admin\pages\settings.php:56-64` | `admin\pages\orders.php:298` (`$rider['whatsapp']` passed into `build_rider_whatsapp_url()`) |
| `dish_dash_whatsapp` | `admin\pages\brand-identity.php:414` (Brand Identity page — public/footer contact number) | `admin\pages\brand-identity.php:108`; `modules\template\class-dd-template-module.php:1480, 1533` (footer social icon); `modules\orders\class-dd-customer-profile.php:179` (exposed to customer profile as `whatsapp_contact`); `templates\page-dishdash.php:100` |
| `dd_reservation_handoff_whatsapp` (boolean opt-in, not a number) | `admin\pages\settings.php:105` | `modules\template\class-dd-template-module.php:443`; consumed in `reservations.js:644, 961` |
| `dish_dash_order_handoff_whatsapp` (boolean opt-in, not a number) | `admin\pages\settings.php:115` | `modules\template\class-dd-template-module.php:368`; consumed in `cart.js:230, 1360` |

Per-customer/per-reservation numbers (`wp_dishdash_customers.whatsapp`, `wp_dishdash_reservations.whatsapp`) are DB table columns, not `wp_options` — listed separately in the Key Database Tables reference (CLAUDE.md) and used as the message recipient identity throughout §2.

---

## 6. Outbound HTTP client abstraction — does one exist?

**No shared `wp_remote_post`/`wp_remote_get` wrapper class exists anywhere in the codebase.** Every module that talks to an external HTTP API builds its own calls directly:

- `dishdash-core\class-dd-github-updater.php:254` — `wp_remote_get()` inline, for release-zip checks.
- `modules\homepage\class-dd-homepage-module.php:397` — `wp_remote_get()` inline.
- `modules\audit\class-dd-audit-cli.php:80` — `wp_remote_post()` inline, hits the site's own `admin-ajax.php`.
- `modules\auth\class-dd-auth-module.php:1179, 1203` — `wp_remote_post()`/`wp_remote_get()` inline, for Google OAuth token/userinfo exchange.
- `modules\payments\class-dd-pesapal.php` — see below.
- `modules\payments\class-dd-momo.php` — same shape as PesaPal (own `wp_remote_post`/`get` calls, own settings read, no shared base class).

**`DD_PesaPal` (`modules\payments\class-dd-pesapal.php`, 182 lines) is the closest thing to a template pattern** for what a Cloud API client class would look like:
- Class docblock states explicitly: *"This class makes NO HTTP calls on instantiation. All API calls happen only when `submit_order()` or `get_transaction_status()` are called."*
- **Constructor** (`:15-27`) reads credentials from a WooCommerce gateway settings option (`woocommerce_pesapal_settings`), branches test/live mode, sets `base_url`.
- **`is_configured(): bool`** (`:29-31`) — cheap guard other code calls before attempting anything.
- **`get_access_token()`** (private, `:33-48`) — `wp_remote_post()` with `Content-Type/Accept: application/json`, JSON body, 30s timeout; returns `false` on `is_wp_error()` or missing token field.
- **`get_or_register_ipn()`** (private, `:50-88`) — `wp_remote_get()` to check existing registration, falls back to `wp_remote_post()` to register; both guarded by `is_wp_error()`.
- **`submit_order()`** (public, `:90-145`) — orchestrates: get token → get/register IPN → `wp_remote_post()` the actual transaction, `Authorization: Bearer {token}` header, JSON body, 30s timeout. Returns a normalized `['success' => bool, ...]` array — never throws, never returns a raw WP_Error to the caller.
- **`get_transaction_status()`** (public, `:147-180`) — `wp_remote_get()` with Bearer auth, 15s timeout, maps a provider-specific numeric `status_code` to a small set of normalized string constants (`COMPLETED`/`FAILED`/`REVERSED`/`INVALID`), with an explicit comment about not trusting the text description field over the numeric code.

This is a self-contained, non-shared, per-gateway class — there is no abstract/base HTTP client it extends. `DD_MoMo` (`modules\payments\class-dd-momo.php`) follows an equivalent shape independently (own constructor reading `woocommerce_mtn_momo_settings`, own `wp_remote_post`/`get` calls at lines 55, 108, 153) — confirms this is a repeated-by-hand pattern, not a shared abstraction.

`modules\orders\class-dd-notifications.php`'s own docblock (lines 10-11) already flags the intended integration point: *"To swap WhatsApp to API (Phase 3.5 Mode B): Replace `build_admin_whatsapp_url()` only — nothing else changes."* — implying the original design intent was a drop-in replacement of the URL-builder return value, not a wrapper class.

---

## 7. Existing retry/idempotency infrastructure

### 7.1 Order idempotency key
- `wp_dishdash_orders` has `idempotency_key` with `UNIQUE KEY idempotency_key (idempotency_key)` — `install.php:151-152`.
- Write path: `class-dd-orders-module.php:412-414` conditionally includes `idempotency_key` in the insert if provided and the column exists (`has_idempotency_key_column()` — feature-detection gate, mirrors the PesaPal-column gate below).
- Race handling: `class-dd-orders-module.php:423-439` — on insert failure, specifically checks `$wpdb->last_error` for `"Duplicate entry"` **and** `"idempotency_key"` substrings (so unrelated insert failures aren't misclassified), then looks up and returns the winning request's existing row via `find_order_by_idempotency_key()` instead of surfacing an error. This is the exact "two racing requests, DB UNIQUE KEY as the real guard" pattern a WhatsApp Cloud API send-queue would want for "don't double-send this message."

### 7.2 PesaPal tracking-column idempotency (dual-implemented in orders and reservations)
- `wp_dishdash_orders.pesapal_tracking_id` — **not in `install.php`** (documented gap, see CLAUDE.md "Known Issues"); only exists on live DB via manual `ALTER TABLE`. Guarded everywhere by `has_pesapal_tracking_column()` (`class-dd-orders-module.php:2081-2093`), a static-memoized `SHOW COLUMNS` check — every reference degrades gracefully (falls back to transient-only) if the column is absent, per the inline `error_log('DD_DIAG: ... transient-only fallback')` at line 1241.
- `wp_dishdash_reservations.pesapal_tracking_id` — **is** in `install.php:272, 278` with its own `UNIQUE KEY pesapal_tracking_id (pesapal_tracking_id)`. Same gating pattern: `has_pesapal_tracking_column()` (`class-dd-reservations-module.php:582-590`), used at lines 601, 642, 666, 669 to find-or-create idempotently across the IPN vs. client-poll race (per the comment at `class-dd-orders-module.php:106-110`: "PesaPal server-to-server IPN is authoritative; the client-side status poll remains a fast-path but is no longer load-bearing; both share one idempotent creation routine.")
- **Pattern takeaway for a message queue:** a nullable `VARCHAR` tracking-id column + `UNIQUE KEY` + a `has_column()` feature-detection helper is the established way to add a new external-system correlation ID to an existing table without a hard migration dependency — directly reusable for e.g. a `whatsapp_message_id` column to dedupe Cloud API sends.

### 7.3 Billing ledger dedup pattern (`modules\billing-ledger\class-dd-billing-ledger-module.php`)
- Table: `wp_dishdash_billing_ledger`, `UNIQUE KEY source (source_type, source_id)` — `install.php:486-500`.
- Write method `DD_Billing_Ledger_Module::log()` (`:115-147`) uses **`INSERT IGNORE`** (not `$wpdb->insert()`) specifically so a duplicate `(source_type, source_id)` pair is a **silent no-op**, not a surfaced error — docblock at lines 85-90 states callers are "not expected to check 'have I already logged this' themselves — the table does that."
- Decoupled entry point: any module fires `do_action('dd_log_billing_event', [...])` — the ledger module listens via `add_action('dd_log_billing_event', [__CLASS__, 'log'], 10, 1)` (`:70`), so producers never call the ledger class directly (module-isolation rule).
- Includes a **reconciliation sweep cron** (`run_reconcile_sweep()`, `:164-198`) — `wp_schedule_event(time(), 'daily', 'dd_billing_reconcile_sweep')` (`:73-75`) — that re-scans for rows that should exist but don't yet (`NOT EXISTS` subquery against the ledger table) and backfills them. This is the closest existing analog to a delivery-confirmation reconciliation job a WhatsApp Cloud API queue might need (e.g. re-checking message-status webhooks that never arrived).

### 7.4 No existing send-queue, retry-on-failure, or webhook-delivery-status infrastructure
- All current "WhatsApp sends" are `wa.me` URL opens (client or server redirect) — there is no outbound send call to retry, no delivery webhook to receive, and therefore no existing retry/backoff logic for a WhatsApp message specifically. The `idempotency_key` (7.1), `pesapal_tracking_id` (7.2), and billing-ledger `INSERT IGNORE` + reconcile-sweep (7.3) patterns are the three reusable *building blocks*, not a ready-made queue.
