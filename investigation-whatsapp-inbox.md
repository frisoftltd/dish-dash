# Investigation: Phase 9E — WhatsApp Inbox (Phase 1, read-only)

Date: 2026-10-29
Current state per CLAUDE.md: deployed v3.18.47, "Current phase" line says Phase 7, but
the Development Phases table lists **Phase 8 (Feature Backlog) as ⏳ CURRENT**. Neither
mentions Phase 9 sub-phases (9A/9E) anywhere. See Finding 2 — this matters because the
brief assumes 9A already exists; it does not.

---

## 1. Roles and access

**Roles are WordPress roles, defined in `install.php`'s `register_roles()`** (called via
`create_roles()` on activation, and by `migrate_roles_v2()` on upgrade):

- `dd_restaurant_owner` and `dd_restaurant_manager` — **identical capability sets**
  (`install.php:583-620`). Both are granted `manage_options` directly, plus
  `dd_manage_orders`, `dd_manage_menu`, `dd_manage_reservations`, `dd_view_analytics`,
  `dd_view_billing`, `dd_view_customers`, `dd_manage_brand_identity`,
  `dd_manage_homepage`, `dd_manage_template`, `dd_manage_settings`,
  `dd_view_activity_log`. No capability currently distinguishes owner from manager —
  any UI-level difference (CLAUDE.md mentions Settings/Tools/Template hidden from both)
  is menu-visibility only, not a capability gate.
- **Platform admin** is a separate concept: `dd_is_platform_admin()`
  (`dishdash-core/class-dd-helpers.php:71-75`) returns true only when the current user
  has `manage_options` **and** does NOT have either `dd_restaurant_owner` or
  `dd_restaurant_manager` role. This exists precisely because both DD roles already carry
  `manage_options`, so a plain `current_user_can('manage_options')` can't tell a
  restaurant owner apart from a true WP/Fri-Soft admin. The brief's instruction to use
  `dd_is_platform_admin()` for platform-level checks (not `manage_options`) matches this
  exactly — it's an existing, proven helper (used in `admin/class-dd-admin.php` and
  `admin/pages/billing.php`).
- **No `dd_restaurant_owner`-vs-`dd_restaurant_manager` capability split exists today.**
  A new `dd_view_inbox` / `dd_manage_inbox` capability would need to be added to both
  roles' arrays in `register_roles()` and bumped through `migrate_roles_v2()`'s version
  gate (`dish_dash_roles_version`), per the existing pattern.

**Branch scoping is effectively unused for access control.** `dishdash_branches`,
`branch_id` columns, and `dd_get_current_branch_id()` exist (`dishdash-core/class-dd-helpers.php:376-381`)
but the latter just reads a `dd_branch_id` cookie, defaulting to `1` — there is no
user-to-branch assignment anywhere in the codebase (no usermeta, no capability keyed by
branch). This is consistent with the real deployment model in CLAUDE.md: each restaurant
(Nyarutarama, Kiyovu) is a **separate WordPress install on a separate domain/cPanel
account**, not multiple tenants sharing one database. So "each restaurant's own staff see
only their own customers' chats" is **already true by construction** for any new table —
there is only ever one restaurant's data in a given install's DB. No per-restaurant
`WHERE` filter is needed; only the owner/manager-vs-platform-admin distinction matters,
and only for whether platform admins (Fri Soft staff debugging via a shared super-admin
account, if that pattern exists) should see inbox data they didn't technically need to —
not found evidence either way; flagging as a question.

---

## 2. Phase 9A state — does NOT exist yet

Searched the full repo for `whatsapp`, `message_log`, `wa_business`, `cloud_api`, `9A` (case-insensitive):
no PHP, JS, or CSS file implements a WhatsApp Cloud API client, no webhook endpoint is
registered, and `install.php`'s 17-table schema (`branches`, `orders`, `order_items`,
`delivery_zones`, `tables`, `reservations`, `pos_sessions`, `analytics`, `user_events`,
`user_profiles`, `customers`, `birthday_tokens`, `reservation_refunds`,
`dd_billing_payments`, `dishdash_tracking_events`, `dishdash_billing_ledger`, plus the
version-gated tracking-events migration) has **no `wp_dishdash_message_log` table**.
RELEASE.md's only WhatsApp-related entries are the existing *outbound* notification
features (kitchen/customer WhatsApp links via `wa.me` deep links — see
`modules/orders/class-dd-notifications.php`), which are plain `window.location.href`
redirects to WhatsApp, not Cloud API calls. There is no API client, no webhook, no
message log, no conversation concept anywhere in the repo today.

**This means Phase 9E as briefed cannot start at Step 1 as if 9A is done** — Phase 9A
(API client + webhook + `wp_dishdash_message_log`) is a prerequisite that has not been
built. The brief already flags this dependency ("Phase 2 depends on Phase 9A being
built"); this confirms it's not just "waiting on credentials" but genuinely unbuilt code.

**Proposed schema** (new, since nothing to extend): a message log alone is not enough —
an inbox needs fast "list conversations ordered by latest activity" queries, which a flat
message log makes expensive (needs a GROUP BY over potentially large tables). Recommend
**two tables**, modeled on the `dishdash_billing_ledger` pattern (append-only ledger +
derived/aggregated pointer row), both created in `install.php`:

```sql
-- wp_dishdash_wa_conversations — one row per customer phone number, the inbox list source
CREATE TABLE {$wpdb->prefix}dishdash_wa_conversations (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    wa_number          VARCHAR(20)     NOT NULL,       -- E.164, matches dishdash_customers.whatsapp format
    customer_id        BIGINT UNSIGNED          DEFAULT NULL, -- resolved wp_dishdash_customers.id, nullable (unknown number)
    customer_name      VARCHAR(255)    NOT NULL DEFAULT '',   -- denormalized snapshot for list rendering without a join
    last_message_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_message_preview VARCHAR(255)  NOT NULL DEFAULT '',
    last_inbound_at    DATETIME                 DEFAULT NULL, -- drives the 24h window calc
    unread_count       INT UNSIGNED    NOT NULL DEFAULT 0,
    auto_reply_sent_at DATETIME                 DEFAULT NULL, -- "once, not repeatedly" gate for out-of-hours auto-reply
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY  wa_number (wa_number),
    KEY         customer_id (customer_id),
    KEY         last_message_at (last_message_at)
) $charset_collate;

-- wp_dishdash_message_log — append-only, one row per inbound/outbound message + status transitions
CREATE TABLE {$wpdb->prefix}dishdash_message_log (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id  BIGINT UNSIGNED NOT NULL,
    direction        ENUM('inbound','outbound') NOT NULL,
    wa_message_id    VARCHAR(100)             DEFAULT NULL,  -- Meta's message id, for status-webhook matching
    message_type     VARCHAR(20)     NOT NULL DEFAULT 'text',-- text | template, media out of scope this phase
    body             TEXT                     DEFAULT NULL,
    template_name    VARCHAR(100)             DEFAULT NULL,  -- which of the 4 approved templates, if outbound+template
    status           VARCHAR(20)     NOT NULL DEFAULT 'sent',-- sent|delivered|read|failed|received
    error_detail     TEXT                     DEFAULT NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status_at        DATETIME                 DEFAULT NULL,  -- last status-webhook update time
    PRIMARY KEY (id),
    KEY  conversation_id (conversation_id),
    UNIQUE KEY wa_message_id (wa_message_id),
    KEY  created_at (created_at)
) $charset_collate;
```

Rationale: `dd_billing_ledger` already proves the "ledger table + `UNIQUE KEY` as a
dedupe/idempotency guard" pattern works on this host; `wa_message_id` as a `UNIQUE KEY`
gives the same idempotent-webhook-replay protection the PesaPal IPN handler relies on
(`has_pesapal_tracking_column()` / conditional-UPDATE pattern) — Meta's webhooks can and
do redeliver. `wa_conversations` is the thing the inbox list screen queries directly (no
GROUP BY over message_log needed), same reasoning as why `dishdash_customers` carries
`total_orders`/`total_spent` as maintained aggregates rather than recomputing from
`dishdash_orders` on every page load.

Single-tenant-per-install (Finding 1) means no `branch_id` is needed on either table —
consistent with how `dishdash_customers` has no `branch_id` either.

---

## 3. Customer linking

Phone number fields found, by table:

| Table | Column | Format notes |
|---|---|---|
| `dishdash_customers` | `whatsapp` | **Primary identity**, `VARCHAR(20)`, `UNIQUE KEY` — this table is explicitly "WhatsApp-based customer identity" per its own header comment in `install.php:380` |
| `dishdash_orders` | `customer_phone` | `VARCHAR(50)`, free-text, not unique |
| `dishdash_reservations` | `customer_phone` (legacy) and `whatsapp` (new) | union schema, both present, `whatsapp VARCHAR(30)` |

No column is guaranteed to be stored in strict E.164 format today — `customer_phone` on
orders is user-typed. `dishdash_customers.whatsapp` is the cleanest match target since
it's already the unique WhatsApp identity and existing code (`DD_Customer_Profile`,
reservation booking flow per `dd_resolve_customer_id` filter mentioned in
`install.php`'s orders-table comments) already normalizes to it. **Recommendation:**
match an incoming webhook's `wa_id` (Meta sends E.164 digits, no `+`) against
`dishdash_customers.whatsapp` after normalizing both sides to digits-only — don't trust
`dishdash_orders.customer_phone` as a join key, it's not unique and not normalized.
Where no match exists, store the conversation with `customer_id = NULL` and
`customer_name` from WhatsApp's own profile name field (Meta sends this on first contact)
as a fallback label.

---

## 4. Admin UI patterns

- **Module scaffold**: every module extends abstract `DD_Module`
  (`dishdash-core/class-dd-module.php`) — `init()` is the only required method; it
  provides `get_option()`/`update_option()` (auto-prefixed `dish_dash_{id}_{key}`),
  `enqueue_style()`/`enqueue_script()` (resolve to `assets/css|js/{file}` with
  module-version cache-busting), `render_template()` (theme-override-aware, checks
  `get_stylesheet_directory()/dish-dash/{template}` first), and `json_success()`/
  `json_error()` helpers. A new `modules/inbox/class-dd-inbox-module.php` would follow
  this exactly.
- **AJAX registration**: `DD_Ajax::register($action, $callback, $nopriv = true)`
  (`dishdash-core/class-dd-ajax.php`) wraps `add_action('wp_ajax_...')` +
  conditionally `wp_ajax_nopriv_...`. For an admin-only inbox, register with
  `$nopriv = false`. Nonce verification is `DD_Ajax::verify_nonce($field, $action)` or
  the module's own `$this->verify_nonce()` wrapper — both call `wp_verify_nonce()` and
  `wp_send_json_error(..., 403)` on failure. Existing admin-only modules
  (`class-dd-customers-module.php`'s `wp_ajax_dd_save_customer`) follow the same
  one-action-per-capability-gated-callback convention.
- **Admin submenu registration**: `admin/class-dd-admin.php` uses a flat sequence of
  `add_submenu_page('dish-dash', ...)` calls (lines ~100-195) — a new Inbox entry slots
  in the same way. **Critical constraint already enforced by the audit runner**
  (`modules/audit/class-dd-audit-runner.php:375-387`): `remove_submenu_page()` is
  explicitly flagged as a failure by the automated audit (root-caused a sidebar-collapse
  bug in v3.5.13) — menu-item visibility must be CSS-only via
  `dishdash-core/class-dd-hooks.php`'s `hide_irrelevant_menu_items()` pattern
  (`admin_head` + `.wp-submenu a[href*=...]` selectors, **not** `:has()` — that also
  broke the whole menu in v3.10.13→v3.10.16). This matches the brief's rule exactly.
- **List screens**: Orders/Reservations/Billing admin pages (`admin/pages/orders.php`,
  `reservations.php`, `billing.php`) use card-based rows with a brand-colored left accent
  strip, "whole row clickable → opens a modal with all actions" (the v3.14.6 pattern
  note in RELEASE.md), not separate edit pages. A thread view as a modal-or-panel
  triggered from a conversation-list row would match this convention.
- **Mobile**: `assets/css/admin.css` has no dedicated mobile-first grid system but does
  use `@media (max-width: …)` breakpoints throughout (e.g. `.dd-kpi-row` at 800px,
  `.dd-dash-cols` flex-direction column at 960px) to collapse multi-column admin layouts
  to single-column. A conversation-list + thread-view two-pane layout would need its own
  breakpoint to stack into list-then-thread (standard mobile chat UX) rather than
  side-by-side, consistent with existing practice rather than introducing a new pattern.

---

## 5. Refresh method — polling, not WebSockets (confirm as assumption)

No WebSocket/SSE infrastructure exists anywhere in the codebase, and shared cPanel
hosting (confirmed stack: LiteSpeed Cache, PHP-FPM via cPanel, no mention of a persistent
Node process) makes WebSockets impractical without a separate always-on process the
current hosting plan doesn't appear to support — consistent with the brief's own
assumption.

**Existing precedent for polling cadence on this exact host**: `assets/js/admin.js`
already runs a production AJAX poll for the admin notification bell
(`setInterval(poll, config.pollInterval || 30000)` — 30 seconds, gated by a
dashboard-notify setting default-on) and `assets/js/order-tracking.js` polls customer-facing
order status every 30 seconds (`setInterval(poll, 30000)`). Both are live, proven-working
patterns on this stack today. **Recommendation: reuse 30s** as the inbox's default
conversation-list poll interval (same AJAX-not-page-cached rationale `ajax_get_hours_state`
already relies on — admin-ajax.php responses bypass LiteSpeed/QUIC.cloud page caching).
An open thread view could poll faster (e.g. 10-15s) since it's a single conversation, not
a full list — but this is a cost/responsiveness tradeoff for the owner to weigh, not
dictated by any existing code. Flagging as confirmation-needed per the brief's instruction,
not deciding it here.

---

## 6. Business hours — already exists, directly reusable

`DD_Hours` (`dishdash-core/class-dd-helpers.php:534-779`) is a complete opening-hours
state engine already used by the closed-banner and `dd_remind_me_open` AJAX handler:

- `DD_Hours::get_state()` → `'open' | 'closing_soon' | 'break' | 'closed'`, reading a
  **structured JSON schedule** stored under the `dd_opening_hours` option (per-day
  `{open: bool, sessions: [[start,end], ...]}`, supports split/mid-day-break hours) —
  **not** the free-text `dish_dash_opening_hours` textarea (note the name collision risk:
  `dd_opening_hours` vs `dish_dash_opening_hours` are two different options; the inbox
  must read `dd_opening_hours`, the structured one).
- `DD_Hours::get_next_open_info()` / `get_next_open_info_ts()` give the next-open day/time,
  directly useful for an out-of-hours auto-reply's message text ("We're closed — back
  Monday at 9:00 AM").
- Timezone comes from `dd_timezone` option (default `Africa/Kigali`), `dd_closing_soon_minutes`
  (default 30) already gates the `closing_soon` state.

**The auto-reply can call `DD_Hours::get_state() !== 'open'` directly** — no new
business-hours infrastructure needed, just reading this existing class from the new
inbox module (allowed under the architecture rules: `DD_Hours` lives in core helpers,
not inside another feature module, so this isn't a cross-module dependency).

---

## Proposed module

- **Name**: `DD_Inbox_Module`
- **Folder**: `modules/inbox/`
  - `class-dd-inbox-module.php` (extends `DD_Module`, `id = 'inbox'`)
  - own admin submenu page(s) under `admin/pages/inbox.php` (or split list/thread)
  - `assets/css/inbox.css`, `assets/js/inbox.js` (per existing per-feature asset
    convention — not a shared admin.css addition)
- Fires a `do_action('dd_whatsapp_message_received', $conversation_id, $message_id)`
  hook from the Phase 9A webhook handler (lives wherever 9A's API-client module ends up,
  **not** inside this module) so the inbox listens without a direct class dependency,
  matching the brief's Step 1 instruction and the project's module-isolation rule.

---

## Risks

1. **Phase 9A doesn't exist** — biggest risk. Phase 2 Step 1 ("the webhook stores
   incoming messages...") implicitly requires building the webhook endpoint and API
   client first, which the brief frames as already-done infrastructure Phase 9E sits on
   top of. Needs explicit scoping: is building 9A in scope for this engagement, or does
   it need its own brief first?
2. **No owner/manager capability split exists** — if the eventual product needs
   "manager can read, only owner can see billing-relevant phone numbers" or similar,
   that's new ground; today both roles are capability-identical.
3. **`dd_opening_hours` vs `dish_dash_opening_hours` naming collision** — easy to grab the
   wrong option when wiring the auto-reply; must use the structured JSON one (`dd_opening_hours`)
   consumed by `DD_Hours`, not the free-text display string.
4. **No E.164 normalization guarantee today** — `dishdash_customers.whatsapp` is the
   unique identity but nothing in the current codebase enforces a single phone format at
   write time across all three tables that store numbers; matching logic needs its own
   normalization, not a naive string `=`.
5. **Credential/production webhook delivery dependency** — per the brief's own
   dependency table, production webhook delivery requires the Meta app to be published,
   which is "Not published" — Phase 2 Step 1 can be built and smoke-tested with Meta's
   *test* webhook delivery (works unpublished per the brief), but can't be verified
   end-to-end against the real dedicated number until those external steps land.

---

## Questions

1. Is building Phase 9A (API client + webhook registration + token storage) in scope
   for this engagement, or does it need to be briefed and built first as its own
   release before Phase 9E Step 1 can start?
2. Should platform admins (`dd_is_platform_admin() === true`) be able to view any
   restaurant's inbox for support purposes, or should the inbox be owner/manager-only
   with zero platform-admin access even for debugging?
3. Confirm the 30-second polling interval (matching `admin.js`'s existing notification
   poll) is acceptable for the conversation list, and ask whether a faster interval is
   wanted for an open thread view specifically.
4. Do we need a capability split between `dd_restaurant_owner` and `dd_restaurant_manager`
   for the inbox (e.g., only the owner can see raw customer phone numbers), or should it
   follow the existing precedent of treating both roles identically?
5. Open questions already listed in the brief (out-of-hours wording, staff new-message
   notification, first restaurant = Khana Khazana) are still open — not something this
   investigation can resolve from code.

---

Stopping here per Phase 1 scope. No files were modified. Waiting for approval before
any Phase 2 work (including whether Phase 9A needs its own brief first).
