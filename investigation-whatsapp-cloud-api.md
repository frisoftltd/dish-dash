# Investigation: Phase 9A — WhatsApp Cloud API Foundation (Phase 1, read-only)

Date: 2026-10-29
Current state per CLAUDE.md: deployed v3.18.47. "Current phase" line still says Phase 7;
the Development Phases table lists Phase 8 (Feature Backlog) as current and Phase 9 only
as "Analytics + AI." **CLAUDE.md contains no Phase 9A/9E section and no mention of "Tech
Provider" or "Embedded Signup" anywhere** — the decision this brief says it's replacing
was never actually written into CLAUDE.md, only stated in the prior chat brief. There is
nothing in the file to correct today. Per Rule 0 (CLAUDE.md updates travel with a version
bump/commit, not as a standalone edit) and the explicit Phase 1 "no code changes" scope,
I have **not** touched CLAUDE.md — recommend the per-restaurant-model note gets written
in as part of Phase 2 Step 1's release commit. Flagging as a question below rather than
deciding it.

Reusing [`investigation-whatsapp-inbox.md`](investigation-whatsapp-inbox.md)'s findings
on roles/`dd_is_platform_admin()`, single-tenant-per-install architecture, and the
`DD_Module`/`DD_Ajax`/module-scaffold patterns — not repeated here.

---

## 1. Existing WhatsApp touchpoints

Repo-wide, **21 PHP files and 3 JS files** reference `wa.me` or "whatsapp" in actual
plugin code (not counting `investigation-*.md`, `RELEASE.md`, `AUDIT.md`, vendor, or
other docs) — close to the brief's "20 PHP and 3 JS." All message-sending today works the
same way: build a `wa.me/<number>?text=<urlencoded message>` URL server-side, then either
auto-redirect the browser to it or render it as a tap-to-open link/button. **There is no
existing code path that actually delivers a WhatsApp message without a human tapping
something** — this has a direct consequence for item 2.

Builder inventory and what the four new API-sent messages do to each:

| Builder / location | Direction & today's trigger | Fate under Phase 9A |
|---|---|---|
| `DD_Notifications::build_customer_whatsapp_url()` (`class-dd-notifications.php:323`) | Restaurant → customer, "Order Confirmed". Built by `on_order_created()` (offline gateways: COD/bank/cheque) and returned as `whatsapp_customer_url` in the AJAX response — **but grep confirms no JS anywhere reads `whatsapp_customer_url`/`data.customer_url` for orders**. Dead code today. | **Replaced** by the new "order confirmed" API template. This is the one case where "replace" is generous — there's nothing live to replace, just an unused builder to retire. |
| `DD_Notifications::build_admin_whatsapp_url()` (`:353`) | Customer's own browser → restaurant admin. Fired two ways: (a) returned as `whatsapp_url` to cart.js, rendered as an opt-in "Send my order to the restaurant on WhatsApp" tap button (never auto-opened); (b) stored as a transient and auto-opened via the 800ms thank-you redirect for **online**-gateway orders (see item 2). | **Kept as-is.** Admin notifications are explicitly out of the four-message scope on cost grounds. |
| `DD_Notifications::build_kitchen_whatsapp_url()` (`:399`) | Admin → kitchen, manual tap from Orders page (`admin/pages/orders.php:301`). | Kept — kitchen notifications excluded. |
| `DD_Notifications::build_customer_paid_whatsapp_url()` (`:488`) | Customer → admin, "I have paid" PesaPal claim handoff (`class-dd-orders-module.php:2124`). | Kept — this is customer-initiated contact to admin, not one of the four restaurant→customer messages. |
| `DD_Notifications::build_rider_whatsapp_url()` (`:571`) | Admin → rider, manual tap (`admin/pages/orders.php:305`). | Kept — rider notifications excluded. |
| `DD_Notifications::build_customer_ontheway_url()` (`:615`) | Admin → customer, manual tap from Orders page when status → Ready. | **Kept** — "on the way" is not one of the four in-scope messages; stays a manual wa.me tap. |
| `on_reservation_created()`'s two inline message bodies (`:43-111`) | Builds both `admin_url` and `customer_url` ("RESERVATION CONFIRMED ✅..."). Returned to `reservations.js`'s `showWhatsAppButtons(adminUrl, customerUrl)` — **which only renders `adminUrl`** (an opt-in "Send my booking to the restaurant" button, same pattern as orders). `customerUrl` is accepted as a parameter and never used. Dead code, same shape as the orders customer message. | **Replaced** by the new "reservation confirmed" API template — again, nothing live is actually replaced for the *customer-url* side. |
| **Admin-side "💬 Send Confirmation" button**, inline JS in `class-dd-reservations-admin.php:960-997` | Admin → customer, manual tap from the reservation "View" modal — a **third, independent copy** of the "RESERVATION CONFIRMED" text (plus parallel Cancelled/No-show/Deposit-pending variants), built client-side, separate from `on_reservation_created()`'s PHP copy. This is the actual working mechanism staff use today to notify a customer a reservation is confirmed. | **This is the one genuine behavior change**: once the API sends "reservation confirmed" automatically, staff's manual "💬 Send Confirmation" tap becomes redundant for that specific status (Cancelled/No-show/Deposit-pending variants are untouched — not in the four-message scope). Flag for the Fix Brief: decide whether to remove/hide that one button or leave it as a manual resend option. |
| Deposit-link builder, inline in `ajax_pesapal_request_deposit()` (`class-dd-reservations-module.php:973-1032`) | Staff clicks "Request PesaPal Payment" in the accept modal → returns a `redirect_url` + a `whatsapp_url` staff must tap-send manually. | **Replaced**: staff still clicks "Request Payment," but the backend sends the WhatsApp template automatically instead of handing staff a link to forward. This is a real, currently-functioning flow — the cleanest "replace" of the four. |
| Birthday-ask builder, inline in `send_birthday_whatsapp()` (`class-dd-orders-module.php:160-203`) | See item 2 — **this is not the message the brief's "birthday" scope item most likely means.** | See item 2 / Questions. |
| `admin/pages/orders.php` JS (`:870-872`) | Client-side-only rebuild of kitchen/customer/admin wa.me URLs for the Orders list's quick-action buttons — a parallel, JS-native copy of some of the above, not calling `DD_Notifications` at all. | Kept, unaffected — admin/kitchen tooling, out of scope. |
| `templates/profile/my-profile.php:99-108` | Plain "Contact the restaurant" link, no pre-filled text, customer-initiated. | Kept, unaffected. |

**Net effect for item 1**: of the four in-scope outbound messages, only **deposit payment
link** and the reservation-confirmation's **staff-tap button** are replacing something
genuinely live today. "Order confirmed" and the *customer*-facing half of "reservation
confirmed" are replacing dead/unused code, not an active flow. "Birthday" doesn't map
cleanly onto any existing message at all (next item).

---

## 2. Auto-redirect paths

**The WooCommerce thank-you redirect, 800ms** —
`DD_Orders_Module::on_order_received_page()` (`class-dd-orders-module.php:271-330`), hooked
on `woocommerce_thankyou` (fires only for **online** gateways — PesaPal/card; offline
orders never hit this page the same way). It reads the `dd_whatsapp_{$wc_order_id}`
transient — which `DD_Notifications::on_payment_complete()` populates with
**`build_admin_whatsapp_url()`, the admin notification, not a customer confirmation.**
Mechanism: the page shows a "Payment Confirmed!" panel, waits 800ms, then
`window.location.href`-redirects **the customer's own browser** to a wa.me link addressed
to the **restaurant's** WhatsApp number, pre-filled with the new-order details — i.e. it
hijacks the paying customer's own phone to get them to tap-send the order notification to
the restaurant on the restaurant's behalf. This is the real mechanism by which the
restaurant currently learns about a new online-paid order over WhatsApp (the "I have paid"
PesaPal builder, item 1, is a related but separate path for the manual-claim flow).

**What changes for the customer**: nothing, if this redirect is left alone — it's an admin
notification, explicitly out of the four-message scope. The new customer-facing
"order confirmed" API message is **additional**, not a replacement of this redirect. Worth
flagging to the Fix Brief author: don't confuse "add the order-confirmed API send" with
"remove the 800ms redirect" — they serve different recipients. If the owner would rather
stop asking customers to forward the admin notification themselves once the API can
message admin too, that's a separate, later cost/scope decision (admin notifications are
explicitly excluded "on cost grounds").

**The birthday footer injector, 1500ms** —
`DD_Template_Module::inject_birthday_whatsapp()` (`class-dd-template-module.php:1629-1645`),
reads a `dd_birthday_wa_{$customer_id}` transient set by
`send_birthday_whatsapp()` (`class-dd-orders-module.php:160-203`, fired by WP-Cron 2
minutes after a customer's **first order**). The message text ("One more thing, {name}!
...Share it here...") is written as restaurant→customer, but the `wa.me` target number
traced through the call chain is **the customer's own WhatsApp number**, not the admin's.
Opening `wa.me/<own number>?text=...` on the customer's own device opens a WhatsApp
self-chat with that pre-filled text — it does not notify the restaurant and does not
deliver anything to the customer from the restaurant (they're both the same person/device
here). This looks like it was never actually effective at collecting birthdays via
WhatsApp — flagging as a pre-existing oddity, **not fixing it** (out of this
investigation's scope), but see Question 1: this is the *ask-for-birthday* flow, not a
day-of congratulations message, and no code anywhere checks "whose birthday is today" and
sends a greeting. The brief's four-message "birthday" item most likely means that
congratulations send — which **does not exist in any form today**, live or dead. It would
be new functionality (a new daily WP-Cron check against `dishdash_customers.birthday`),
not a replacement of this 1500ms flow.

---

## 3. Webhook route — precedent and the PesaPal IPN handler

**Two existing public-endpoint patterns**, pick one:

1. **WooCommerce `wc-api` query var** — PesaPal's IPN is registered via
   `add_action('woocommerce_api_wc_pesapal_gateway', [...])` (`class-dd-orders-module.php:116`),
   resolving requests to `/wc-api/wc_pesapal_gateway/`. Handles both GET and POST through
   `$_REQUEST`. This hook is already shared by two independent handlers on the identical
   URL (orders module filters out `RES-`-prefixed refs and returns so the reservations
   module's handler also fires) — a precedent for "route by payload shape," though messy.
2. **WP REST API** — `register_rest_route('dish-dash/v1', ...)` already exists
   (`class-dd-orders-module.php:335-353`) with per-route `permission_callback`s
   (`admin_permission`, `order_permission`). **This is the better fit for the WhatsApp
   webhook**: Meta's GET verification and POST event delivery both need to be public
   (no WP capability check — auth is the verify-token/signature check, not a logged-in
   user), which a REST route with `permission_callback => '__return_true'` models cleanly;
   `WP_REST_Request::get_body()` gives the raw bytes needed for HMAC signature
   verification (`wc-api`'s `$_REQUEST` does not preserve exact raw body bytes the same
   way). Recommend `register_rest_route('dish-dash/v1', '/whatsapp-webhook', [...])` over
   reusing the `wc-api` pattern.

**What the PesaPal IPN handler does for auth and idempotency**
(`handle_pesapal_ipn()`, `class-dd-orders-module.php:1856-1964`) — useful precedent, but
**does not have a signature-verification step to copy**: PesaPal's IPN carries no HMAC;
this codebase instead treats the IPN payload as an untrusted ping and always re-verifies
by calling `DD_PesaPal::get_transaction_status()` server-to-server before acting
("Never trust the IPN payload for this" — stated twice in comments). Idempotency comes
from looking up the existing order by `tracking_id` (unique-keyed column) and only
promoting via a conditional `UPDATE ... WHERE payment_status = 'unpaid'`, so a replayed
IPN affecting an already-`paid` row is a no-op. **This codebase has never implemented an
HMAC/signature check anywhere** — Meta's `X-Hub-Signature-256` verification
(`hash_hmac('sha256', $raw_body, $app_secret)` + `hash_equals()`) will be new code with no
existing helper to reuse, though the *idempotency* half (unique key + conditional
update/insert, matching the data model's `wa_message_id UNIQUE KEY`) directly mirrors the
PesaPal pattern and the billing-ledger pattern already cited in the brief.

---

## 4. Secret storage

**PesaPal and MoMo credentials are not stored by Dish Dash's own settings screens at
all** — `DD_PesaPal::__construct()` reads `get_option('woocommerce_pesapal_settings', [])`
and MoMo reads `get_option('woocommerce_mtn_momo_settings', [])`
(`class-dd-momo.php:33`) — both are **WooCommerce payment-gateway plugins'** own settings
arrays (WooCommerce → Settings → Payments → [gateway] → their own credential fields),
external to this repo entirely. This matches CLAUDE.md's existing known-issue note about
the separate WooCommerce PesaPal gateway plugin. **So there is no existing Dish-Dash-owned
"enter your payment API credentials here" screen to copy** — Phase 9A's settings screen
(Phone Number ID, WABA ID, token, verify token, app secret, all owned by Dish Dash itself)
is a new pattern for this codebase, not a reuse of the PesaPal/MoMo approach.

**The actual precedent for secrets Dish Dash *does* own**: `admin/pages/settings.php`
already stores `dish_dash_claude_api_key` and `dish_dash_google_maps_key` as plain
`wp_options` via `sanitize_text_field()` + `update_option()`, rendered back into the form
with `type="password"` (visual masking only — the value is still plaintext in the
page's HTML source, same residual exposure as any admin-only settings form; no encryption
layer exists anywhere in this codebase for any secret, by precedent). **Recommend the same
for the four new WhatsApp fields** (e.g. `dd_whatsapp_token`, `dd_whatsapp_verify_token`,
`dd_whatsapp_app_secret`, plus non-secret `dd_whatsapp_phone_number_id` /
`dd_whatsapp_waba_id`) — plain wp_options, `type="password"` inputs for the three secrets,
admin-capability-gated page, nonce-checked save. This satisfies "server-side, never in the
repo, never in front-end JavaScript": the values never get `wp_localize_script()`'d into
any `window.*` JS object (confirmed no such pattern exists for Claude/Maps keys either),
only rendered into the settings form's own HTML on an admin-only page. "Never in logs" is
a code-discipline requirement for the new webhook/client code specifically — the existing
PesaPal IPN handler's `error_log('DD_DIAG: ...')` habit must NOT be copied verbatim onto
anything that touches the token/app secret/signature (it logs tracking IDs and statuses,
never credentials, which is fine — just be deliberate about not extending that pattern to
touch secrets in the new code).

---

## 5. Send reliability

**Confirmed: no queue or retry infrastructure exists anywhere in this codebase.** Every
outbound action today is either synchronous (built and returned/redirected in the same
request) or a one-shot `wp_schedule_single_event()` with no retry on failure (the birthday
ask). The closest thing to a retry *sweep* is `dd_pesapal_reconcile_sweep`
(`class-dd-orders-module.php:130-135`, added v3.18.46/47): a custom `dd_one_minute`
WP-Cron interval, gated by a 55-second transient lock to prevent overlapping runs, capped
at 20 orders/run, bounded to orders created ≤48h ago, idempotent re-check-and-promote
logic per row.

**Proposed smallest-safe approach for WhatsApp sends — copy that exact shape**: on a send
failure (API error, timeout, non-2xx from Graph API), write the attempt to
`dishdash_message_log` with `status = 'failed'` and `error_detail` set, then rely on a new
`dd_whatsapp_retry_sweep` WP-Cron job (reuse `dd_one_minute` or a new interval, same
transient-lock pattern) that re-sends failed rows **up to a capped retry count** (e.g. 3
attempts, tracked via a new counter or by checking `created_at` age) before giving up and
leaving the row `failed` permanently. This is a genuinely new piece of infrastructure —
unlike items 3/4, there's no existing retry *mechanism* to extend, only the WP-Cron-sweep
*shape* to reuse. Given the brief's Step 4 "fall back to the existing wa.me link behavior"
instruction, the realistic design is: **try the API send once, synchronously, inline with
the triggering action (order confirmed, etc.); on failure, fall back immediately to
building the wa.me link (same builder that already exists) instead of queuing a retry for
that specific send.** A background retry sweep is a reasonable *addition* for messages
that fail mid-sequence (e.g. an infra blip) but duplicating both an inline fallback and a
background retry for every message type adds real complexity — flagging as a design
question rather than deciding it.

---

## 6. Settings screen

No existing Dish-Dash-owned credential-entry screen to copy (item 4) — the closest
structural precedent is `admin/pages/settings.php` itself: a single top-level settings
page with multiple `<div class="dd-settings-section">`-style fieldsets (General,
Delivery, Opening Hours, Reservations, Pricing & Fees, Security), one shared
`check_admin_referer('dd_settings_save')` nonce, fields read directly off `$_POST` and
written via `update_option()` in a block at the top of the file before any HTML renders.

**Two structural choices** for where the WhatsApp fields live, matching two different
existing precedents:
- **A new top-level admin page** (`admin/pages/whatsapp-settings.php`), following the
  `admin/class-dd-admin.php` `add_submenu_page()` pattern used by Billing/Reservations/etc
  — consistent with the inbox module (Phase 9E) also wanting its own submenu, and keeps
  the new module's settings fully self-contained (no edits to `settings.php`, satisfying
  the "zero cross-module dependencies" rule cleanly since nothing needs to touch another
  module's file).
- **A new section inside the existing `settings.php`** — faster to find for an admin
  already used to one settings page, but `settings.php`'s docblock explicitly enumerates
  every option key it owns, and adding WhatsApp fields there would mean editing a file
  that belongs to the template module (`DD_Template_Module::render_settings()` is the
  dependent listed in that docblock), which cuts against the module-isolation rule more
  than a new page would.

**Recommend the new top-level page**, inside the new `modules/whatsapp/` module (own
submenu, own settings render + save, own nonce action e.g. `dd_whatsapp_settings_save`) —
matches the brief's "zero cross-module dependencies" instruction most directly, and gives
Phase 9E's inbox a natural sibling page under the same module if the two ever want to
share a top-level "WhatsApp" menu group.

---

## Proposed module

- **Name**: `DD_WhatsApp_Module` (class `class-dd-whatsapp-module.php`)
- **Folder**: `modules/whatsapp/`
  - `class-dd-whatsapp-module.php` — extends `DD_Module`, `id = 'whatsapp'`; owns the
    settings page, the REST webhook route registration, and the test-send AJAX action.
  - `class-dd-whatsapp-client.php` — the API client, shaped after `DD_PesaPal`: no HTTP
    calls on instantiation, credentials pulled from `get_option()` in the constructor,
    public methods like `send_template()`, `send_text()` (if ever needed inside the 24h
    window), `is_configured()`.
  - `admin/pages/whatsapp-settings.php` — credentials form + test-send button.
  - `assets/js/whatsapp-settings.js` — test-send button's AJAX call (per-feature asset
    convention, not bolted onto `admin.js`).
- Webhook handler lives in `class-dd-whatsapp-module.php` (or a small
  `class-dd-whatsapp-webhook.php` if it gets large) and, on a verified inbound event,
  fires `do_action('dd_whatsapp_message_received', $conversation_id, $message_id)` for
  Phase 9E's inbox module to listen to — no direct dependency either direction.
- Tables (`dishdash_wa_conversations`, `dishdash_message_log`) created in `install.php`,
  per the brief's data model — this module's `init()` never creates tables itself,
  consistent with "`install.php` is the only file that defines DB table schemas."

---

## Risks

1. **Birthday scope is unclear** (items 1–2): the only birthday code that exists is the
   ask-for-birthday flow, which has its own pre-existing targeting oddity (wa.me pointed
   at the customer's own number). The brief's fourth outbound message likely means a
   different, not-yet-built day-of congratulations send. Building the wrong one wastes a
   release.
2. **No HMAC/signature verification precedent anywhere in this codebase** — the webhook's
   security-critical code (Meta signature check) has nothing to copy from and needs
   careful review; the PesaPal IPN's "trust nothing, re-verify server-to-server" pattern
   is a different (also valid, but different) trust model that doesn't map onto Meta's
   signature-based design.
3. **Removing/hiding the reservation "💬 Send Confirmation" button** (item 1) is a UI
   decision with no code casualty if skipped, but leaving it in place means a reservation
   can get the automated API message AND a manual duplicate if staff doesn't know to stop
   clicking it — worth an explicit call either way in the Fix Brief.
4. **wc-api vs REST route** — recommended REST, but this is a genuinely new pattern for
   a *public, unauthenticated* route in this codebase (the two existing `dish-dash/v1`
   routes both require `admin_permission`/`order_permission`); `__return_true` permission
   callbacks are correct for a Meta webhook but worth double-checking against whatever
   security-review tooling this project runs before shipping.
5. **No retry infrastructure exists** (item 5) — whatever gets built is new surface area,
   not a proven pattern, unlike most of this plugin's other integrations.
6. **CLAUDE.md has no Phase 9A/9E content yet** — the "update CLAUDE.md to match" brief
   instruction has nothing concrete to amend today; needs to be written fresh as part of
   Phase 2 Step 1's commit rather than corrected.

---

## Questions

1. Does "birthday" in the four-message outbound scope mean (a) a new day-of
   congratulations send (doesn't exist in any form today), or (b) replacing the existing
   ask-for-birthday flow (exists, but appears to target the wrong number already)? These
   are different releases.
2. Should the admin-side "💬 Send Confirmation" manual button for reservations be removed,
   hidden, or left as a manual-resend fallback once the API sends reservation-confirmed
   automatically?
3. For send failures: inline wa.me fallback only (per the brief's literal Step 4 wording),
   a background retry sweep only, or both? Doing both for all four message types is real
   added complexity worth confirming is wanted.
4. Should the WhatsApp settings page live under its own new "WhatsApp" top-level admin
   menu entry (shared with Phase 9E's inbox), or folder under an existing one?
5. Who should see/approve the exact template wording and parameter order for the four
   Meta-approved templates before they're submitted — this blocks Phase 2 Step 1's
   test-send button regardless of code readiness.

---

Stopping here per Phase 1 scope. No files were modified (including CLAUDE.md — see note
at top). Waiting for approval before any Phase 2 work.
