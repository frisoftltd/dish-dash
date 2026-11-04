# 🧠 Dish Dash — Session Context & Workflow

> **This file is the single source of truth for every AI coding session.**
> Read this ENTIRELY before doing any work.
>
> ⚠️ MANDATORY RULE: This file MUST be updated in the same commit as every
> version bump. The `Last updated` line must always match `DD_VERSION` in
> `dish-dash.php`. A release that ships code without updating this file
> is incomplete. No exceptions. Version-specific changelog entries go in
> `RELEASE.md`, not here — see RELEASE.md for the full per-version history.
>
> Last updated: v3.18.61 (2026-11-04)

---

## 🍽 CORE MISSION (READ THIS FIRST)

**DishDash is a smart ordering system that learns customer behavior and makes ordering faster, easier, and more personalized every time.**

### Core Value Proposition

- Reduce time to order
- Increase repeat orders
- Increase cart value
- Learn user behavior continuously

### Product Identity

DishDash IS:
- ✅ An ordering system (not a generic website)
- ✅ Behavior-driven (every feature tracks data)
- ✅ AI-powered (subtle, invisible to users)
- ✅ A white-label SaaS platform — each restaurant feels like they own the system

DishDash is NOT:
- ❌ A basic WooCommerce storefront
- ❌ A visible "AI system" (users should feel fast/easy/natural, not "AI")
- ❌ A clone of Uber Eats or Glovo
- ❌ A generic WordPress admin — the backend must feel like a premium SaaS product

### Development Principles (STRICT)

1. **Every feature must answer:** "How does this help understand user behavior?"
2. **No feature without tracking.** Every user action must be recorded.
3. **AI is invisible.** Users feel fast, easy, natural — never "AI system."
4. **Mobile-first always.** Africa is mobile, not desktop.
5. **Keep architecture clean.** Follow the modular system. No shortcuts.
6. **Speed is addictive.** Optimize everything for performance. Fast = repeat usage.

### Architecture Flow
```
User
↓
UI (DishDash frontend)
↓
🧠 AI Layer (behavior tracking + rules engine)
↓
Cart
↓
WooCommerce (payment processing)
```

---

## 🗄️ Schema Changes — IMPORTANT

There is **one** installer file: `install.php` at the repo root. All `CREATE TABLE` definitions live there. `dishdash-core/class-dd-install.php` was deleted in v3.4.97 — it had been deprecated since v3.4.92 (renamed `DD_Install` → `DD_Schema_Upgrader`, no live schema declarations). The canonical installer is `install.php` exclusively.

### How to add a new table or column

1. Edit `install.php` — add the column to the `CREATE TABLE` block or append a new `CREATE TABLE` block inside `create_tables()`
2. Bump `DD_VERSION` in `dish-dash.php` (both the header comment and the constant)
3. Commit, tag, release as usual

**No WP-CLI step needed.** The auto-migration guard in `dish-dash.php` runs `dbDelta()` on the next admin page load after a version mismatch is detected, and updates `dd_db_version` to match.

### What auto-migration can and can't do

- ✅ Add new tables
- ✅ Add new columns to existing tables
- ✅ Add new indexes
- ❌ Drop columns (dbDelta limitation — never drops anything)
- ❌ Change column types in destructive ways (dbDelta is conservative)
- ❌ Rename columns (must be a manual ALTER TABLE via WP-CLI)

For drops/renames, use a manual migration step and document it in the release notes.

---

## 📌 Current State

| Field | Value |
|---|---|
| **Deployed version** | v3.18.58 |
| **Current phase** | Phase 7 — Role Cleanup & Access Control |
| **Current sub-phase** | **Phase 9A — WhatsApp Cloud API Foundation, Step 5a (kitchen new-order alert) shipped in v3.18.60** — a second, independent automatic outbound send (kitchen WhatsApp alert on order-confirmed), default OFF pending Meta template approval for `dd_kitchen_new_order_v1`, sharing the Step 4 send/retry/alert pipeline via a refactor (`attempt_whatsapp_send()`/`finish_whatsapp_attempt()`) — see Last working state. Step 4 shipped in v3.18.58 — the first real outbound send, order-confirmed, default OFF pending Meta template approval (see Last working state). Step 3 shipped in v3.18.50 (Step 1 shipped in v3.18.48, verified with a real test send; Step 2 shipped in v3.18.49, both tables confirmed in the demo database — columns, defaults, and the `wa_number`/`wa_message_id` unique indexes all match the data model; started 2026-10-29 — see `investigation-whatsapp-cloud-api.md` and `investigation-whatsapp-inbox.md` for the pre-work investigations). **Per-restaurant model decision** (supersedes an earlier, never-committed-to-CLAUDE.md plan for Fri Soft to act as a Meta Tech Provider via Embedded Signup — dropped for now, revisitable later, nothing in this design blocks it): each restaurant's WordPress install holds its own Meta app, WhatsApp Business Account, dedicated number, system-user token, and webhook — configured independently per install via the new WhatsApp admin page, never shared or routed between restaurants. Direct Cloud API, no business solution provider. Outbound scope is four messages (order confirmed, reservation confirmed, deposit payment link, birthday) — admin/kitchen/rider notifications stay on `wa.me` links, excluded on cost grounds. Birthday is deferred to Phase 9D (no day-of greeting exists anywhere in the codebase today — only an unrelated ask-for-birthday flow). The inbox screens that read `dishdash_message_log` are Phase 9E, not started. Minimal Light header/hero redesign (prior sub-phase, v3.18.44–v3.18.45) is complete and unchanged — see RELEASE.md for that history. |
| **Next task** | **v3.18.61 (PesaPal failed→paid recovery) needs a real-site manual test pass** — not yet run against a live WordPress/MySQL install (no such instance exists in the authoring environment); run the 6-item manual test list under v3.18.61 in RELEASE.md (FAILED-then-COMPLETED-on-retry recovery, REVERSED staying non-promotable, admin Recheck Payment on a failed order, the reconcile sweep's cancelled-order exclusion, the client drawer's new 5-minute grace-window message, and the new `wp dishdash pesapal recheck-failed` CLI command both with and without `--dry-run`). This is independent of the Phase 9A WhatsApp work below — a bug-fix release interleaved with it, same as v3.18.59's spice-variation fix was. **v3.18.60 ships gated OFF and needs Meta template approval before it can do anything live** — the `dd_kitchen_new_order_v1` template must be approved by Meta first (default setting is off regardless); once approved and the switch is turned on, run v3.18.60's manual test list in RELEASE.md (kitchen alert on, kitchen alert off, empty kitchen number, a long note with line breaks, a failed send that retries, customer confirmation still working). Per the brief's own Condition E, also confirm the `role` column migration on `dishdash_wa_conversations` actually ran on the demo site before that manual test — verify via `DESCRIBE wp_dishdash_wa_conversations` (or any admin-page load after deploy, which triggers the existing auto-migration guard) showing the new nullable `role` column. **v3.18.58's own order-confirmed template approval is a separate, still-open item** — the `dd_order_confirmed_v1` template must be approved by Meta first (default setting is off regardless); once approved and the switch is turned on, run the full manual test list in RELEASE.md (own number through each phone format, duplicate confirm, switch off, a deliberately-wrong token to force a retry+alert, an invalid number). **Phase 9A Step 5b (next)** — reservation-confirmed, then the deposit-payment-link message, each its own release, reusing the same send/retry/alert machinery Step 4/5a built; deciding whether to remove the admin's manual "💬 Send Confirmation" button (`class-dd-reservations-admin.php`) once reservation-confirmed sends automatically is an open question for that step. **A residual, narrow race was found and accepted, not fixed**: two near-simultaneous WP-Cron ticks processing the same scheduled `dd_whatsapp_send_order_confirmed` event (both see "no existing row," both call Meta independently) could in theory produce two distinct `wa_message_id`s for the same order, since the idempotency lookup is by `order_id`+`template_name`, not a DB-level unique constraint — the exact same residual-race class every other cron-driven writer in this codebase already carries (e.g. the PesaPal reconciliation sweep's own overlap lock). Not hardened further this release since the column additions approved for this brief were explicitly just `order_id` + `retry_count`, not a new unique constraint. **v3.18.57 — shipped, small follow-up to v3.18.56**: "Confirmed" removed from the Orders page bulk-action dropdown and from the server-side whitelist that gates it — restaurants must open and confirm each order individually, never in bulk; Ready/Delivered/Cancelled unchanged. See Last working state for detail. **Kitchen screen (v3.18.54 + v3.18.55) needs a real-device manual test pass** — not yet run against a live WordPress/MySQL install (see Last working state); full list under v3.18.55's manual test list in RELEASE.md, covering New → Start Preparing → Mark Ready, cancel at each stage, two devices, mute, and tap-to-silence. **v3.18.56 — shipped, scope cut down from the original plan (see Last working state for full detail)**: `update_status()` gained a backward-compatible `bool $send_email = true` 3rd param; the Orders page bulk-action handler now routes each selected row through it individually with `$send_email = false` and surfaces an "N updated, M skipped (invalid for current status)" admin notice instead of one blind multi-row `UPDATE`; `dd_bulk_deliver_stale` (`admin/pages/dashboard.php`) stays a direct writer (routing it through `update_status()` would reject most of the stuck orders it exists to recover, since the transitions map only allows `delivered` from `ready`) but now manually fires `dish_dash_order_status_changed` + `dish_dash_order_delivered` and sets `delivered_at` per row, using each row's real prior status. **`ajax_irembopay_confirm()` was explicitly skipped this release, including its late-payment/already-cancelled edge case** — stays exactly as it was before v3.18.54/55/56 (direct write, `confirmed_at` still never set, still fires `dish_dash_order_confirmed` not `dish_dash_order_status_changed`), deferred rather than fixed, per explicit instruction. **`on_order_received_page()` and `wc_payment_cancelled()` were NOT modified** (Dish Dash uses its own checkout, not WooCommerce, for every named gateway) **but a verification was requested and completed**: both only ever fire for an order that has a `wc_order_id`, which is only set by `create_wc_order()` inside `ajax_place_order()`'s generic "ONLINE GATEWAY FLOW" branch — reached only when `$payment_method` is none of `mtn_momo`/`irembopay`/`pesapal`/`momo_manual` and not in `OFFLINE_GATEWAYS` (`cod`/`bacs`/`cheque`). The actual list of selectable payment methods at checkout (`class-dd-template-module.php`'s `paymentGateways` closure) is **not a fixed DD-aware list — it's `WC()->payment_gateways->get_available_payment_gateways()`, i.e. whatever WooCommerce gateways are toggled on in wp-admin → WooCommerce → Settings → Payments on that specific site**, live and current, not something this codebase controls. So: for the four gateways Dish Dash actually builds around, this path is correctly dead — but it is **not statically dead code**, and whether it's ever actually reachable on a given deployed site depends on a live WooCommerce setting this investigation has no access to check (no site access from the authoring environment). Reported per the brief's own "if live, tell me and stop" instruction rather than assumed either way — recommend checking WooCommerce → Settings → Payments on each live site (demo/nyarutarama/kiyovu) for any gateway beyond those four before ever treating `on_order_received_page()`/`wc_payment_cancelled()` as safe-to-touch dead code. **Payment-path audit completed, not fixed, per the v3.18.54 brief's explicit instruction**: every direct `$wpdb->update()` on `dishdash_orders` across PesaPal (submit/IPN/client poll/`promote_pesapal_order()`/`run_pesapal_reconcile_sweep()`), MoMo (manual claim/confirm, MTN create), and IremboPay was checked for writes of `status='confirmed'` that bypass `update_status()`. Only `ajax_irembopay_confirm()` does this — sets `status='confirmed'` directly, fires `dish_dash_order_confirmed` instead of `dish_dash_order_status_changed`, and never sets `confirmed_at`. Every PesaPal/MoMo auto-promotion path lands on `status='pending'` instead, only reaching `confirmed` later via the normal manual Confirm click (which does go through `update_status()` correctly). The kitchen screen's own query defends against this one gap with `COALESCE(confirmed_at, created_at)` rather than touching `ajax_irembopay_confirm()`. A second, v3.18.55 audit of the same kind (bulk handler + the two Confirm-adjacent writes) and the v3.18.56 build it led to are both summarized above under "v3.18.56 — shipped." **PesaPal reservation-deposit IPN likely has the same raw-JSON-to-browser bug** — v3.18.46 fixed the orders module's customer-facing PesaPal redirect (see Last working state), but `class-dd-reservations-module.php`'s own `handle_pesapal_ipn()`/`pesapal_ipn_respond()` is a separate, duplicated implementation registered on the identical shared `woocommerce_api_wc_pesapal_gateway` hook for `RES-`-prefixed merchant refs, with the identical shared-callback-URL structure — not touched this release (different file, out of this brief's scope), needs its own brief. **PesaPal credential-logging leak not resolved** — v3.18.46 confirmed by full-repo search that no `dish-dash` code prints `consumer_key`/`secret_key`; the leak reported in `report.md` ("PesaPal keys in debug.log") must originate in the separate WooCommerce PesaPal gateway plugin (populates `woocommerce_pesapal_settings`, not part of this repo) or WooCommerce core's own gateway debug logging — needs a server-side check (WooCommerce → Settings → Payments → PesaPal → Debug Log, and that plugin's own source), not a dish-dash code change. **demo.dishdash.rw Brand Identity → Accent Color is misconfigured (data, not code)** — confirmed live during v3.18.45's investigation: `dish_dash_accent_color` is stored as `#ffffff` on this tenant (along with `--bg`/`--brand-dark` also at unusual values — reads like leftover color-picker testing, not a deliberate palette). This is why the new v3.18.44 nav hover/accent styling looked wrong there — the code renders whatever's configured, correctly. Needs a wp-admin fix (Brand Identity page), not a release — flagging so it isn't mistaken for a recurring bug if reported again. **Minimal Light header consolidation** — `page-home.php`'s inline header and `render_minimal_light_header()` (Menu page) are still two independent copies of the same markup; v3.18.44 added a third near-duplicate of the `window.DD` JS bridge pattern between them (worked around via the `dd_get_hours_state` AJAX endpoint instead of adding a third copy — see RELEASE.md v3.18.44). Consolidating into one shared render function/partial was assessed as feasible but deliberately deferred to its own dedicated release rather than bundled with v3.18.44's already-large feature set — see that release's report for the specific structural differences to account for (page-home.php writes a full standalone `<html>` document and skips `wp_body_open()`; `render_minimal_light_header()` is a private method hooked on `wp_body_open` for the Menu page only). **`dd_place_order` client-side retry** — now that every payment branch has real idempotency protection (v3.18.35–36), a future release could add the standard `ajaxWithRetry()`/`fetchFreshNonce()` pattern to it, same as every other endpoint got in v3.18.31–33. **`/reserve-table/`'s standalone inline form** (`shortcode_reserve()`, `modules/menu/class-dd-menu-module.php`) — separate, unfixed bug found while investigating v3.18.37: its form fields (`#ddReserveBtn`, `#ddResDate`, etc.) have zero JS wired to them anywhere in the codebase, so submitting it does nothing; the actual working reservation flow is the `#dd-res-overlay` modal (triggered via `.js-open-reservation` links on other pages), a completely separate piece of markup — needs a decision on whether to wire up the standalone form or replace it with a modal trigger. **`dd_track_event` client-side retry (optional, low priority)** — now that `ajax_track_event()` reports real failures (v3.18.34), likely low priority since these are best-effort analytics. Also separate/unrelated: the pre-existing `dd_get_reviews` bug (registered `nopriv=false`, needs its own decision), and the remainder of the GA4/tracking investigation's Release B–F queue (internal `DDTrack.event('order')` calls on async payment paths, GA4 on reservations). **Phase 9A Step 2 — done in v3.18.49**: `dishdash_wa_conversations` and `dishdash_message_log` added to `install.php` as tables #17/#18, same dbDelta block style as every other table; no separate version-gated migration code was needed — the existing `dish-dash.php` auto-migration guard (compares `DD_VERSION` to the stored `dd_db_version` option and re-runs `DD_Install::create_tables()` on mismatch) already re-runs `dbDelta()` against all 18 tables the next time an admin loads any `wp-admin` page after this version bump, which is sufficient per CLAUDE.md's own "✅ Add new tables" auto-migration guarantee. **Phase 9A Step 3 — done in v3.18.50**: public REST webhook (`dish-dash/v1/whatsapp-webhook`, the first unauthenticated route in this codebase) live — GET verification handshake, HMAC-verified POST events (`X-Hub-Signature-256` against `dd_whatsapp_app_secret`), idempotent inbound-message/status storage, `do_action('dd_whatsapp_message_received', ...)` fired on every stored inbound message. A background security review of this step found no High/Medium findings. **GET verify handshake 403'd on a correct token — fixed in v3.18.51**: a manual browser test of the handshake returned `{"error":"Verification failed."}` even with a freshly-saved token; investigation found the stored token (trimmed by `sanitize_text_field()` on save) was being compared against an untrimmed incoming `hub_verify_token`, so any stray whitespace picked up when pasting the token into a URL bar broke `hash_equals()` — and because all three failure branches (no stored token / wrong `hub.mode` / token mismatch) returned the same generic 403, there was no way to tell which one was firing. v3.18.51 trims both sides before comparing and added one `error_log()` line per failure branch (`no_stored_token`, `bad_mode`, `token_mismatch` — the latter logging only the two token *lengths*, never the values) so a future repeat of this is diagnosable without guessing. **Still needs manual verification against the real Meta dashboard** (register the callback URL `https://demo.dishdash.rw/wp-json/dish-dash/v1/whatsapp-webhook` + a freshly-generated verify token — the one used during the v3.18.50/51 investigation chat is considered exposed and must not be reused, confirm the handshake succeeds, send a real test reply) — not yet done as of this entry, see acceptance checklist. **Two small fixes in v3.18.52** (templates-approved cleanup release, Step 3 polish, not new scope): `dishdash_wa_conversations.created_at` and `dishdash_message_log.created_at` were drifting by several hours for the same inbound event because the conversations insert never passed `created_at` and fell back to the column's `DEFAULT CURRENT_TIMESTAMP` (DB server clock/timezone) while the message-log insert set it explicitly via `current_time('mysql')` (WordPress's configured timezone) — both tables now use `current_time('mysql')`. `process_payload()`'s POST handler also had no check on `$change['field']`, so it read `value['messages']`/`value['statuses']` out of every change regardless of field type — added an explicit allow-list so only the `messages` field is processed; `message_template_status_update` and any other field are ignored (Meta still always gets 200, unchanged). **"Send test webhook" tool shipped in v3.18.53** (admin tooling only — `class-dd-whatsapp-webhook.php`'s handling logic, signature check, and tables are untouched): Dish Dash → WhatsApp now has a "Webhook Test" panel below the existing Test Send panel. "Send Test Webhook" (`ajax_test_webhook()`, new `dd_whatsapp_test_webhook` nonce) builds a sample inbound-text payload shaped exactly like Meta's real `messages`-field payload (fake sender `DD_WhatsApp_Module::TEST_WA_NUMBER` = `250000000000`, name "Dish Dash test", a fresh `ddtest_`-prefixed UUID `wa_message_id` per click so the webhook's own idempotency check never skips a repeat click), signs the raw JSON body with the saved App Secret the same way `signature_matches()` does, and POSTs it to `rest_url('dish-dash/v1/whatsapp-webhook')` — the real route, never the handler class directly. Reports the HTTP status plus the stored `dishdash_wa_conversations.created_at`/`dishdash_message_log.created_at` and their difference in minutes — the same comparison previously done by hand in phpMyAdmin — or a plain-language error (App Secret not saved; webhook rejected the request, with the status shown; or the loopback call failed, with a note that some hosts block a site calling itself). "Delete Test Messages" (`ajax_delete_test_messages()`, new `dd_whatsapp_cleanup_test` nonce) removes only rows tied to `TEST_WA_NUMBER` from both tables — a number no real customer can have, so it never touches a real conversation. Both new AJAX actions are admin-only (`dd_manage_settings`, same as the existing test-send), registered `nopriv=false`. The App Secret itself is never echoed or logged by either action. **Not run end-to-end by Claude Code** — no local WordPress/MySQL environment exists in the dev sandbox this was written in, only the plugin source tree; PHP syntax was checked (`php -l`, clean) but the actual HTTP round-trip and DB-read comparison still need a real run on a deployed site. **Phase 9A Step 4 — done in v3.18.58** (see the top of this cell and Last working state) — order-confirmed template wired through `DD_WhatsApp_Client::send_template()`, capped retry via `dd_whatsapp_retry_sweep` shaped after `dd_pesapal_reconcile_sweep`, failures recorded in `dishdash_message_log`, one throttled admin email per hour listing every failure since the last one. No `wa.me` fallback for automatic sends (a missed tap would lose the notice) — not needed, since the retry sweep is the fallback. **Phase 9A Step 5 (next)** — reservation-confirmed, then the deposit-payment-link message, each its own release; deciding whether to remove the admin's manual "💬 Send Confirmation" button (`class-dd-reservations-admin.php`) once reservation-confirmed sends automatically is an open question for that step. Birthday greeting moves to Phase 9D (new functionality, not a replacement — see Current sub-phase). Phase 9E (inbox screens reading `dishdash_message_log`) starts only after Phase 9A ships. |
| **Last working state** | v3.18.61 — PesaPal FAILED is not final: allow failed → paid recovery. Built per an explicit brief, driven by a real live miss on nyarutarama (DD-00262 / order 97): PesaPal's IPN reported FAILED at 15:51:31 UTC, the order was marked `payment_status='failed'`, the customer retried on the SAME PesaPal tracking id, PesaPal reported COMPLETED at 15:53:33, but `promote_pesapal_order()`'s conditional UPDATE required `payment_status='unpaid'`, so it logged "PROMOTE skipped (already paid / raced)" and never promoted the row — the customer had paid but the order stayed `pending_payment` with no admin email. Three files touched (`modules/orders/class-dd-orders-module.php`, `assets/js/cart.js`) plus one new file (`modules/orders/class-dd-orders-cli.php`); nothing else in this brief's scope. **`promote_pesapal_order()`** — its WHERE clause now accepts `payment_status IN ('unpaid','failed')` (rewritten as a prepared raw `UPDATE`, since `$wpdb->update()`'s WHERE array syntax can't express an `IN`); REVERSED stays permanently non-promotable, because FAILED and REVERSED are now stored as two **distinct** `payment_status` values (`'failed'` vs `'reversed'`) rather than both collapsing into `'failed'` as before — split at both existing write sites, `handle_pesapal_ipn()` and `run_pesapal_reconcile_sweep()`. Skip-logging split exactly as asked: a 0-row UPDATE now re-reads the row's current `payment_status` and logs `PROMOTE skipped: already paid` (a racing caller won) vs `PROMOTE skipped: status=<x> not promotable` (anything else, e.g. `reversed`) — the old single ambiguous message is gone. **Reconcile sweep** — candidate query widened to `payment_status IN ('unpaid','failed')` and gained `AND status != 'cancelled'` (the previously-requested fix, so a staff cancellation is never silently reopened by a late PesaPal confirmation); 48h window and 20-row cap unchanged. **Admin Recheck Payment button** (`admin/pages/orders.php`) — confirmed by reading, not changed: it already renders for any `status === 'pending_payment'` row regardless of `payment_status`, and already calls the same `dd_pesapal_check_status` → `promote_pesapal_order()` path, so the backend widening alone makes it work on failed orders; no JS/PHP edit was needed there. **Client poll** (`assets/js/cart.js`) — previously `clearInterval()`'d immediately on FAILED/REVERSED and showed a final "Payment failed" message, which would have permanently stranded the exact DD-00262 scenario even after the server-side fix (the drawer would've stopped asking before the successful retry ~2 minutes later arrived). Now keeps polling for a 5-minute grace window (`PESAPAL_FAILED_GRACE_MS`), showing "Payment failed. If you retry on PesaPal now, we'll detect it automatically…", and only shows the final failed message and stops once that window elapses with no recovery. **Optional one-off, built**: `wp dishdash pesapal recheck-failed [--dry-run]` — new `DD_PesaPal_CLI`, registered from `DD_Orders_Module::init()` the same way the existing `dishdash audit` command registers itself; lists every `payment_status='failed'` PesaPal order (`status != 'cancelled'`, no 48h limit — for historical cases outside the sweep's own window) against PesaPal's live API via a new public `DD_Orders_Module::recheck_failed_pesapal_orders( $dry_run )`, and promotes any now COMPLETED through the same `promote_pesapal_order()` every other caller uses. **Verification done**: `php -l` clean on both touched/new PHP files, `node -c` clean on `cart.js`. **Verification NOT done** — same constraint as every recent release: no WordPress/MySQL instance in the authoring environment, so none of the above (the widened promotion, the split sweep query, the grace-window poll behavior, the CLI command) has been exercised against a real site — see Next task and RELEASE.md's manual test list. Previous v3.18.60 entry: Phase 9A Step 5a: kitchen new-order WhatsApp alert, default OFF, built per an explicit brief (git commands withheld pending developer approval). Four files touched: `install.php` (nullable `role VARCHAR(20)` + index added to `dishdash_wa_conversations`, picked up by the existing auto-migration guard — no new table), `modules/whatsapp/class-dd-whatsapp-webhook.php` (`get_or_create_conversation()` gained an optional, backward-compatible `$role = ''` 3rd param — an existing row's still-NULL role is backfilled via a conditional `UPDATE ... WHERE role IS NULL`; every existing call site, including the inbound-message path, passes no 3rd arg and is unaffected), `modules/whatsapp/class-dd-whatsapp-module.php` (the bulk of it — see below), `admin/pages/whatsapp-settings.php` (new "🍳 Kitchen Alert Message" card: Enabled switch default OFF, template name default `dd_kitchen_new_order_v1`, language default `en` — same default the existing Order Confirmed card uses, per Condition D; no new phone-format field, reuses the existing `dd_whatsapp_kitchen` option already used by the pre-existing manual wa.me kitchen link). **Not touched, per the brief**: the orders module, the kitchen screen, `kitchen_notified_at`, and the customer wrapper's own param-building/recipient logic. **The send/retry/alert pipeline was refactored to be shared, not duplicated**: `attempt_order_confirmed_send()` is now a thin wrapper (reads `customer_phone`, builds the same 4 params in the same order as v3.18.58, unchanged) that calls a new generic `attempt_whatsapp_send($order, $template_name, $language_code, $to_raw, $params, $existing, $is_staff, $message_kind)`; the old `finish_order_confirmed_attempt()` is now the generic `finish_whatsapp_attempt()`, carrying `$is_staff` (picks the conversation's display name — `'Kitchen'` vs the customer's name — and whether its `role` is `'staff'`) and `$message_kind` (`'customer'`\|`'kitchen'`, reaches only `queue_failure_alert()`). The old `find_order_confirmed_log_row()` was renamed `find_whatsapp_log_row()`, no logic changed, now shared by both sends' `(order_id, template_name)` idempotency lookup. **New kitchen-specific code**: `maybe_schedule_kitchen_alert()` is a second, independent listener added via its own `add_action('dish_dash_order_status_changed', ...)` call in `init()` — the Step 4 listener's own registration and body are untouched; `send_kitchen_alert()` (cron handler) resolves the order, then `get_option('dd_whatsapp_kitchen', '')` run through the existing `normalize_phone()` — empty or unnormalizable is a silent skip (one `DD_DIAG` log line, no `message_log` row, no alert, since there's nothing actionable to alert about until a number is configured); `attempt_kitchen_send()` builds the 3 template params (order number; order type via a new `format_order_type()` — `str_replace('_',' ',...)` then `ucfirst()`, literal per the brief, flagged in the report that the live `order_type` ENUM actually uses hyphens not underscores so `'dine-in'` passes through as `'Dine-in'` rather than reworded; and the items line via a new `build_kitchen_items_line()`). **Items line**: `"{qty}x {item_name} (variation/addon values)"` per item, comma-joined, decoding `dishdash_order_items.variation`/`addons` JSON with a new independent local decode (`decode_variation_values()` — attribute values only, no keys, e.g. `"(Large)"`; `decode_addon_values()` — defensively handles both an array-of-`{name,price}`-objects shape and a name⇒value map shape, since the codebase's other addon readers assume the latter while `insert_order_items()` actually writes the former) — no call into the orders module's class or `DD_Notifications`, a direct `SELECT` on `dishdash_order_items`, same cross-module-read-only pattern already used elsewhere in this file. Every item name/variation value/addon value/note is passed through a new `collapse_whitespace()` (collapses newlines, tabs, and runs of spaces to one space) **before** truncation, per Condition C, since Meta rejects a parameter containing any of those; all non-empty notes (each item's own `special_note` plus the order's own `special_instructions`) are collected into one trailing `"| Note: ..."` segment; the whole line is truncated to 300 characters total (ellipsis included) via a new `truncate_param()`, which also falls back to a non-empty placeholder rather than ever sending blank. **Retry sweep generalized** (`run_retry_sweep()`): no longer filters on one hardcoded template — scans rows whose `template_name` matches either configured template-name option; a row with a null/unknown `template_name` is structurally never matched. **Condition B/A**: kitchen-alert rows additionally stop retrying once they've been failing-transiently for more than 10 minutes — that age comparison runs entirely inside MySQL via `NOW() - INTERVAL 10 MINUTE` against `created_at`, never PHP's `time()`/`current_time()`, because this host's PHP clock and DB clock differ by several hours (Condition A); an expired row is force-relabeled `'permanent: exhausted retries after 10 minutes — ...'` by a new `expire_retry_row()` and alerted on, never retried again. Customer-confirmed rows keep their original count-only (3-attempt) cap, no age cutoff, unchanged. **Alert emails are independent per kind** (`queue_failure_alert()` gained `$message_kind`): customer alerts keep the exact `dd_whatsapp_alert_throttle` transient and `dd_whatsapp_last_alert_at` option (byte-identical subject/body text to v3.18.58, confirmed by construction — the `'customer'` label reproduces the old hardcoded strings exactly); kitchen alerts get their own new `dd_whatsapp_kitchen_alert_throttle` transient and `dd_whatsapp_kitchen_last_alert_at` option, so one kind's hourly cadence never suppresses or advances the other's. **Condition D answered**: the kitchen alert's language default is `en` — the same literal default the existing Order Confirmed card's `dd_whatsapp_order_confirmed_lang` option already uses — flagged for the developer to check against Meta's approved language code for `dd_kitchen_new_order_v1`. **Condition E**: the `role`-column migration is picked up by the existing `dish-dash.php` auto-migration guard (`dbDelta()` re-runs on the next `wp-admin` page load after the `DD_VERSION` bump) — verify on the demo site via `DESCRIBE wp_dishdash_wa_conversations` showing the new nullable `role` column, or by confirming a kitchen-alert test send creates a `dishdash_wa_conversations` row with `role = 'staff'`. **Condition F — customer path verified unchanged**: every behavioral check (invalid-phone error text, empty-required-parameter error text, alert email subject/body, option/transient names, `get_or_create_conversation()`'s call shape for the customer path) was traced and confirmed byte-identical to v3.18.58; the only change to the customer path's call graph is that `attempt_order_confirmed_send()` now calls through the shared `attempt_whatsapp_send()`/`finish_whatsapp_attempt()` instead of doing the same work inline — a structural extraction, not a behavior change. **Verification done**: `php -l` clean on all four touched files. **Verification NOT done** — same constraint as every release this week: no WordPress/MySQL instance in the authoring environment, so the migration, the scheduled send, the retry sweep, the 10-minute expiry, and the alert email have not been exercised against a real site; separately, outside this environment's control, the `dd_kitchen_new_order_v1` template must be approved by Meta before any of this can send a real message regardless of code correctness. **Manual test list**: (1) enable the kitchen alert with a valid kitchen number, confirm an order → the kitchen number receives the template with the right order number/order type/items line; (2) leave it off, confirm an order → no message, no row, one quiet log line; (3) enable it with `dd_whatsapp_kitchen` left blank → no message, no row, no alert, one quiet log line; (4) an item with a note containing line breaks and extra spaces → the delivered message shows it collapsed to single spaces, trailing `"| Note: ..."`; (5) a deliberately-wrong token → the send fails, `error_detail` is `transient:`/`permanent:`-prefixed appropriately, the retry sweep picks up a transient failure (within 10 minutes) or force-exhausts it (after 10 minutes) and the kitchen alert email arrives within the hour; (6) with both switches on, confirm an order and verify the customer still receives their own v3.18.58 confirmation message exactly as before, on its own independent throttle/alert window. Previous v3.18.58 entry: Phase 9A Step 4: customer order-confirmed WhatsApp message. Five files: `install.php` (schema), `modules/whatsapp/class-dd-whatsapp-client.php`, `modules/whatsapp/class-dd-whatsapp-webhook.php`, `modules/whatsapp/class-dd-whatsapp-module.php` (the bulk of it), `admin/pages/whatsapp-settings.php`. Untouched per the brief: the orders module, the kitchen screen, the wa.me links, `ajax_irembopay_confirm()`, every other template. **Schema**: `dishdash_message_log` gains `order_id BIGINT UNSIGNED NULL` (indexed, id-reference only, no FK) and `retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0` — picked up by the existing auto-migration guard. **Trigger**: `DD_WhatsApp_Module::maybe_schedule_order_confirmed()` listens to the orders module's existing `dish_dash_order_status_changed` (new_status==='confirmed' only) and only ever calls `wp_schedule_single_event(time(), ...)` — the Meta API is never called inline on the Confirm click. **Idempotency**: keyed by `(order_id, template_name)`, not `wa_message_id` (unknown before the first attempt) — "at most one confirmation message, ever," regardless of how many times the hook fires or the order re-cycles through confirmed later. **Cross-module reads, never writes or method calls**: this module reads `dishdash_orders` directly via `$wpdb`, the same SELECT-only pattern `DD_WhatsApp_Webhook::resolve_customer_id()` already uses against `dishdash_customers` — the orders module has zero reference to this one anywhere in `modules/orders/`. **Reused rather than duplicated**: `DD_WhatsApp_Webhook::get_or_create_conversation()`/`insert_message_log()`/`apply_status_update()`/`MESSAGE_LOG_FORMATS` bumped `private`→`public` (zero logic touched) so the sender calls the exact same idempotent conversation/log code the webhook already uses, instead of a second copy; `insert_message_log()`'s per-column format map gained `order_id`/`retry_count` entries (`%d` each). After a successful send, checks `get_transient('dd_wa_orphan_status_{id}')` and applies it via the EXISTING `apply_status_update()` if Meta's delivery-status webhook raced ahead of our own HTTP response — exactly the scenario that method's own docblock already anticipated for "Step 4's send path." **Phone normalization**: a new, fully independent `normalize_phone()` — deliberately does NOT call `DD_Customer_Manager::normalize_phone()` (orders module), since that method's output format depends on the site-wide `dd_phone_format` option and this module needs one fixed shape regardless (digits-only-with-country-code, no `+`, matching `send_template()`'s own `$to` contract). New setting `dd_whatsapp_default_country_code` (default `250`) backs the bare-number case. Handles `0788123456`, `788123456`, `+250 788 123 456`, `250788123456`, a leading `00` international prefix (treated identically to an explicit `+`), and any other `+`-prefixed 8-15-digit number (kept foreign); everything else returns `''` → `permanent: invalid_phone`. **`send_template()` extended** (`class-dd-whatsapp-client.php`) with `http_status`/`error_code` in its failure return — purely additive, the one existing caller (`ajax_test_send()`) only ever read `success`/`message_id`/`error` and is unaffected. **Failure classification** (`is_transient_failure()`) is a tight allow-list, not a deny-list — matching the brief's own "transient failures *only*: network errors, server errors and rate limits" wording: `http_status===0` (no response at all) or `429`/`5xx`, or one of 13 specific Meta error codes (rate-limit: 4, 80007, 130429, 131048, 131056, 133016, 131064; temporary/server: 2, 131000, 131016, 131057, 133004, 2494100) — sourced from a **live fetch of Meta's own current WhatsApp Cloud API error-code documentation on 2026-10-31, not from memory**, specifically because getting this wrong would silently misroute retries. Meta's own docs describe generic code `1` as ambiguous ("invalid request or possible server error") — deliberately left off the retryable list, so an ambiguous/unrecognized code defaults to the safer, non-retrying side. **Retry**: a recurring `dd_whatsapp_retry_sweep` (every 5 minutes, new `dd_five_minutes` cron interval) shaped exactly after `DD_Orders_Module::run_pesapal_reconcile_sweep()` — a transient overlap lock (`dd_whatsapp_retry_lock`), a bounded `LIMIT 20` batch — but instead of a dedicated classification column (only `order_id`+`retry_count` were approved for this release), eligibility is encoded as a parseable `'transient:'`/`'permanent:'` prefix on `error_detail` itself, and the sweep's own `WHERE error_detail LIKE 'transient:%'` clause is the only thing that can ever pick a row back up — a permanent or `invalid_phone`-failed row is structurally excluded, by construction, not by a runtime check. Three total attempts: the first (triggered by the hook) plus up to two sweep retries; the 3rd failure relabels the row `'permanent: exhausted retries after 3 attempts — …'` so neither the idempotency check nor the sweep ever touch it again. **Alert**: `queue_failure_alert()` runs after every failed attempt (transient or permanent — "every failed send," per the brief, not just final ones), gated by a 1-hour transient throttle; when not throttled, it queries every `status='failed'` row with `status_at` after `dd_whatsapp_last_alert_at` (a plain option, not a column — avoids needing yet another schema addition) and sends ONE email listing all of them (order number, customer phone, reason, time), then advances that option to `current_time('mysql')`. Confirmed by reading `send_template()` that `error_detail` can never contain a token or secret — it only ever surfaces Meta's own `error.message`, a `WP_Error` message, or this module's own two label prefixes, never `$this->token`/`$this->phone_number_id`. **Settings** (new "📨 Order Confirmed Message" card, same form/Save button as Credentials): on/off switch default OFF (`dd_whatsapp_order_confirmed_enabled`), template name default `dd_order_confirmed_v1`, language default `en`, plus the Default Country Code field in the Credentials card. Off or not-configured → one quiet `DD_DIAG` log line, no row written, no alert — checked both before scheduling (cheap, skips the cron entirely) and again inside the cron handler (in case the setting changes in the few seconds between). **A known, accepted, narrow residual race**: two near-simultaneous cron ticks for the same order could in theory both see "no existing row" and each send independently, producing two distinct `wa_message_id`s — no DB-level unique constraint across `(order_id, template_name)` was added (only `order_id` indexed + `retry_count` were approved this release) — same residual-race class every other cron-driven writer in this codebase already carries (e.g. the PesaPal sweep's own overlap lock isn't airtight either). **Verification done**: `php -l` clean on all five touched files. **Verification NOT done** — same constraint as every release this week: no WordPress/MySQL instance in the authoring environment (confirmed no `wp`/`mysql`/`docker`), so the migration, the actual scheduled send, the retry sweep, and the alert email have not been exercised against a real site, and — separately, outside this environment's control entirely — the `dd_order_confirmed_v1` template must be approved by Meta before any of this can send a real message regardless of code correctness. See RELEASE.md's manual test list. Previous v3.18.57 entry: one small follow-up to v3.18.56, scoped entirely to `admin/pages/orders.php`: `<option value="confirmed">Confirmed</option>` removed from the bulk-action `<select>`, and `'confirmed'` removed from that handler's own server-side `$allowed` whitelist (now `['pending','ready','delivered','cancelled']`) so a hand-built POST with `dd_bulk_action=confirmed` can't bulk-confirm by bypassing the missing dropdown option — the whitelist check runs before anything is ever passed to `update_status()`. `'pending'` stays in that whitelist untouched (it was never offered as a dropdown option either, a pre-existing, separate gap — not in scope for this request, not touched). Reasoning: restaurants must open and confirm each order individually, never in bulk; Ready/Delivered/Cancelled are unaffected. **Verification**: `php -l` clean. **Not run end-to-end** — same constraint as every release this week, no WordPress/MySQL instance in the authoring environment; needs a quick manual check that the dropdown no longer offers Confirmed and that a hand-built `action=confirmed` request is rejected. Previous v3.18.56 entry: route order-status writes through `update_status()` where it's actually safe to, scope narrowed from the original brief after investigation (see Next task for the full history). Three files touched: `modules/orders/class-dd-orders-module.php`, `admin/pages/orders.php`, `admin/pages/dashboard.php` — the WhatsApp module, the kitchen screen, payment gateway logic beyond the status write itself, and all message copy are untouched, per the brief. **(1) `update_status( int $order_id, string $new_status, bool $send_email = true )`** — new 3rd param, fully backward compatible (every existing caller passes exactly 2 args, so default `true` preserves current behavior byte-for-byte); the one `send_status_update()` call inside is now gated `if ( $send_email )`. **(2) Orders page bulk-action handler** — the old code did one raw multi-row `UPDATE ... SET status = %s WHERE id IN (...)` with no hook, no `*_at` timestamp, and no transition validation for any row. Now loops the pre-fetched rows and calls `$orders_module->update_status( $row->id, $action, false )` per row (a fresh `new DD_Orders_Module()` instance — the template already referenced this class statically for fee-recalc, so this is consistent, and `update_status()` doesn't depend on `init()` having run). `$send_email = false` so one click changing many orders doesn't silently email every affected customer. Counts successes/failures (`update_status()` returns `false` when `dd_order_status_transitions()` rejects a row's specific current→target transition — e.g. bulk "Ready" on a still-`pending` row) and carries `dd_bulk_updated`/`dd_bulk_skipped` through the existing redirect's query args to a new `admin_notices` block reading `"N updated, M skipped (invalid for current status)"` — confirmed safe to add a fresh `add_action('admin_notices', ...)` from inside a page template, since `class-dd-hooks.php`'s global `remove_all_actions('admin_notices')` runs on `current_screen` (priority 999), which fires well before this template is ever included, so a notice registered this late survives (same pattern already used elsewhere in this exact file for the nonce-failure notice). The old manual `recalculate_fee_for_status_change()` loop was removed, not left to double-fire — `update_status()` already calls it internally. **(3) `dd_bulk_deliver_stale`** (`admin/pages/dashboard.php`) — deliberately kept as a direct writer, not routed through `update_status()`: this sweep's entire purpose is forcing ANY stuck order (`pending`, `confirmed`, `preparing`, `processing`, `pending_payment` — not just `ready`) straight to `delivered`, but `dd_order_status_transitions()` only lists `delivered` as reachable from `ready` — routing it through the validated path would silently reject most of the orders it exists to recover, defeating the tool. Instead its one raw `UPDATE` now also sets `delivered_at = NOW()` (same single-statement DB-server-clock snapshot as the pre-existing `updated_at = NOW()` in that query — consistent, not a second clock reading), and the per-row loop (already iterating `$stale_rows` for the fee-recalc call) now also fires `do_action('dish_dash_order_status_changed', $row->id, $row->status, 'delivered')` using each row's own real prior status already captured in that same pre-fetch. **Addition beyond the literal instruction, verified and kept**: the same loop also fires `do_action('dish_dash_order_delivered', $row->id)` — not explicitly asked for, but it's the second half of what `update_status()` would have fired on reaching `delivered`. Per explicit condition, checked for listeners before keeping it: a full-repo search for `add_action('dish_dash_order_delivered', ...)` found **zero** — no customer message/email, no loyalty/rewards (Phase 10 doesn't exist yet), no billing/ledger/commission entry, no external call, nothing at all. It's a pure no-op today; kept so the sweep matches what a normal single-order delivery already fires, in case something listens later. **`ajax_irembopay_confirm()`**: skipped entirely per explicit instruction, no changes, deferred (see Next task). **WooCommerce-liveness verification (requested, completed, nothing found that required stopping)**: searched for anything that creates a real `WC_Order` from a Dish Dash order — found exactly one path, `create_wc_order()`, called only from `ajax_place_order()`'s generic "ONLINE GATEWAY FLOW" branch, reached only when the submitted `payment_method` is none of `mtn_momo`/`irembopay`/`pesapal`/`momo_manual` and not in `OFFLINE_GATEWAYS` (`cod`/`bacs`/`cheque`) — i.e. only for some *other* WooCommerce gateway. The checkout drawer's own payment-method list (`class-dd-template-module.php`'s `paymentGateways` closure) is built from the live `WC()->payment_gateways->get_available_payment_gateways()` call, not a fixed Dish-Dash-aware list — so whether this path (and therefore `on_order_received_page()`/`wc_payment_cancelled()`, which only ever fire for an order carrying a `wc_order_id`, which only `create_wc_order()` ever sets) is reachable on a given site depends entirely on which WooCommerce gateways are toggled on in that site's own wp-admin → WooCommerce → Settings → Payments — a live setting this investigation has no access to check, not something determinable from the code alone. Reported rather than assumed, per the brief's own instruction; recommend checking that settings screen on each live site before ever treating those two methods as dead code for a future cleanup. Neither method was touched. **Verification done**: `php -l` clean on all four touched/bumped PHP files; the modal-JS block of `orders.php` re-extracted and confirmed still syntactically valid (this release's edits were PHP-only, outside that block). **Verification NOT done** — same constraint as the last four releases: no WordPress/MySQL instance in the authoring environment, so the bulk-action notice, the per-row transition-rejection counting, and the stale-sweep's hook firing have not been exercised against a real site — see RELEASE.md's manual test list. Previous v3.18.55 entry: Kitchen screen refinements: a real `preparing` status + quieter, controllable sound. **Schema**: nullable `preparing_at DATETIME` added to `dishdash_orders` (`install.php`), same dbDelta pattern as `kitchen_notified_at`. **Transitions** (`dd_order_status_transitions()`, `class-dd-helpers.php`): `confirmed → [preparing, ready, cancelled]` (direct confirmed→ready kept, so the Orders modal can still skip Start Preparing entirely), `preparing → [ready, cancelled]`. `update_status()` gained one more `case` in its existing timestamp switch (`preparing_at`) — zero new logic, same mechanism `confirmed_at`/`ready_at`/etc. already use. `dd_order_status_label()` gained a `'preparing' => 'Preparing'` entry. **`processing` was deliberately NOT repurposed** — investigated and confirmed it's a WooCommerce payment-flow marker (`on_order_received_page()`, "paid via WC-native checkout, awaiting Confirm"), not a kitchen-prep concept; reusing it would have broken the Orders page's own `processing` → Confirm/Cancel button branch and the WC bridge. **Every status-filtering/display surface was surveyed** (full list in the chat history) and updated only where a `preparing` order would otherwise be lost or mislabelled: `ajax_kitchen_feed()` (3-way split — `confirmed`/`preparing` now both carry full card data including `preparing_at`; `ready`/`cancelled` stay removal-only signals), the dashboard's Kitchen Queue card + `ajax_kitchen_queue()` (both widened to `status IN ('confirmed','preparing')` — explicitly in scope this release, unlike v3.18.54 where that card was off-limits), the dashboard's Active/Pending KPI, the Orders page's two badge color maps (PHP `dd_orders_status_badge()` + JS `renderBadge()`), its modal status-pill `statusLabels` object, its filter `$allowed`/`$filter_tabs`, a new `.dd-status-preparing` CSS rule (the modal pill had no rule at all for an unlisted status, unlike the badges which gracefully fall back), the analytics status-breakdown label map (cosmetic — the breakdown itself is already dynamic), and (per explicit approval) `shortcode_track()`'s customer-facing "active orders" list in `class-dd-orders-module.php` — without that one, a customer's own order would vanish from their tracker the moment it left `confirmed`. **Deliberately left alone**: the Orders page's bulk-action status dropdown (a separate, already-bypasses-`update_status()` path — adding `preparing` there was assessed and declined, per approval) and its dead single-order `dd_update_order_status` POST handler (no form targets it anymore). **Orders page modal**: new `if (status === 'preparing')` branch — unconditional Mark Ready (by definition `kitchen_notified_at` is already set by the time an order reaches `preparing`) + Cancel; the existing generic row-badge/modal re-render after any `dd_update_status` call already covers `preparing` with zero further change (confirmed by reading, not assumed) — satisfies "sees New, Preparing and Ready without a refresh." **Kitchen screen sound, rewritten**: v3.18.54 played a `new Audio()` on an independent `setInterval` per unacknowledged card — with 2+ new cards that's 2+ genuinely overlapping audio streams. Replaced with one shared `Audio` element and one shared interval (`alertTick()`) that plays (resetting `currentTime` first) only when sound is enabled, not muted, and at least one visible card is unacknowledged — structurally impossible to overlap now, since there's only ever one element and one timer for the whole page. **Acknowledgment** moved from an in-memory/CSS-only flag to `localStorage` keyed per order id (`dd_ks_ack_<id>`) — per-device by construction (matches "other devices keep alerting"), and now survives a page reload (a card acknowledged before a refresh doesn't start alerting again); cleared when a card is removed (ready/cancelled) so the key doesn't linger forever. **Mute**: new header button, 5-minute window with a live countdown rendered on the button itself, tapping it again ends the mute early (tapping a *card* only acknowledges that card, per approval — the two gestures are deliberately independent), and ending (by timeout or by tap) needs no explicit "resume" call — the next `alertTick()` just sees `isMuted()` return false again. A new card during a mute still gets the `--new` highlight class (highlighting and sound were already decoupled — only `alertTick()` ever calls `.play()`), it just doesn't sound. **Stages**: a `confirmed` card shows "New" + a large "▶ Start Preparing" button; a `preparing` card shows "Preparing" + "✓ Mark Ready". Both buttons call `dd_update_status` directly (`changeStatus()`, one shared function) — no duplicated status logic. Mark Ready removes the card immediately (optimistic, same as before); Start Preparing optimistically re-renders the card as Preparing right away but does **not** fabricate a `preparing_at` client-side — it shows "Just started preparing…" until the real value arrives on the next poll (≤5s), consistent with this codebase's existing carefulness about not inventing server timestamps. **Late-state computation is unchanged at every stage** — still `confirmed_at + dd_kitchen_prep_time`, regardless of whether the card is New or Preparing, per explicit approval; only the *visible* counter text is stage-specific (Preparing shows "time since preparing" as the main line plus a small "total since confirmed" sub-line). **Not changed, per the brief**: `kitchen_notified_at` behavior itself, the server-sourced poll-cursor mechanism, the WhatsApp module, rider flow, any payment-method file including `ajax_irembopay_confirm()`, and `send_status_update()`'s own logic. **A second payment-path-style audit was requested and completed for the next release (v3.18.56), not fixed here** — see Next task for the three direct-write sites found (`ajax_irembopay_confirm()`, `on_order_received_page()`, the Orders page bulk-action handler) and the customer-email/hook/fee-recalculation/transition-validation side-effects to weigh before routing them through `update_status()`. **Verification done**: `php -l` clean on all seven touched/new PHP files, `node -c` clean on the rewritten `kitchen-screen.js` and the re-extracted modal-JS block of `orders.php`, CSS brace-balance checked. **Verification NOT done** — same constraint as the last three releases: no WordPress/MySQL instance in the authoring environment (confirmed no `wp`/`mysql`/`docker`), so the dbDelta migration, the live poll/stage transitions, two-device sync, and the sound/mute/Wake-Lock browser behavior have not been exercised on a real site — see Next task and RELEASE.md's manual test list. Previous v3.18.54 entry: Kitchen screen + server-side kitchen flag (Phase 8 backlog item, independent of the Phase 9A WhatsApp work above). Scoped to `modules/orders/class-dd-orders-module.php`, `admin/pages/orders.php`, `install.php`, plus new files `admin/pages/kitchen.php`, `assets/css/kitchen-screen.css`, `assets/js/kitchen-screen.js`, `assets/sounds/kitchen-alert.wav` — the WhatsApp module, rider flow (`LS_RIDER`), and the dashboard's own Kitchen Queue card/`ajax_kitchen_queue()` are untouched, per the brief. **Schema**: `install.php` adds nullable `kitchen_notified_at DATETIME` and a `KEY updated_at (updated_at)` index to `dishdash_orders` — picked up by the existing auto-migration guard on the next admin page load, no new table. **Reused rather than duplicated**: the existing `dish_dash_order_status_changed` hook (already fired by `update_status()`), the existing `dd_manage_orders` capability (not `dd_is_platform_admin()`, which this codebase reserves for platform-only billing views — doesn't apply to a restaurant day-to-day screen), the existing `dd_kitchen_prep_time` option, and the existing `dd_update_status` AJAX action itself — the kitchen screen's Mark Ready button calls it directly, so status validation/timestamps/customer email/fee recalculation all run exactly as they do from the Orders page, nothing duplicated. **Kitchen submenu**: registered from `DD_Orders_Module::init()` itself (`register_kitchen_menu()`), not `DD_Admin` — matches the architecture rule that each module owns its own submenu (same pattern `DD_WhatsApp_Module` already uses), even though the existing "Orders" submenu is actually registered by `DD_Admin` (pre-existing inconsistency, not touched). **Standalone chrome-free page**: `maybe_render_kitchen_screen()` intercepts on `admin_init` when `?page=dish-dash-kitchen`, before WordPress ever prints wp-admin chrome — the same escape-hatch pattern `DD_Admin::maybe_serve_invoice()`/`dd_invoice_render_page()` already use for the standalone invoice page — then `require`s `admin/pages/kitchen.php` (a bare `<!DOCTYPE html>` document) and exits. Explicit `is_user_logged_in()` + `auth_redirect()` check (so a logged-out tablet is deliberately redirected to login and back to this exact page, not just by accident of WordPress's own earlier auth check) and `current_user_can('dd_manage_orders')` (WordPress's own per-page capability gate hasn't run yet at this point in the request) both run before the require. The page has a "← Back to admin" link. `register_kitchen_menu()`'s own submenu callback (`render_kitchen()`) is unreachable in normal operation (the admin_init intercept always fires first for this page) — kept only as a safe fallback that redirects rather than risk rendering a second `<html>` document nested inside wp-admin's chrome. **Poll cursor — server-clock-only, per explicit instruction**: `ajax_kitchen_feed()` (new AJAX action, `dd_manage_orders` + `dish_dash_admin` nonce) takes an optional `since` (a previously-returned cursor) and returns `cursor` = the highest `updated_at` actually read from a row in that response, or `since` echoed back unchanged if nothing qualified — never `current_time()`/`time()`/a browser clock, because `updated_at` (`ON UPDATE CURRENT_TIMESTAMP`) is stamped by the DB server's own clock, which can differ from the WordPress clock on this host. The query (`WHERE updated_at >= %s AND status IN ('confirmed','ready','cancelled') AND is_test = 0`) uses `>=` rather than `>` by design — the boundary row is deliberately re-sent every tick, so the client must de-duplicate by order id (an upsert keyed by id, not an append) rather than treat a repeat as a new card. Only `confirmed` rows carry full card data (order number, type, customer name, special instructions, `COALESCE(confirmed_at, created_at)`, and a batched items fetch — one query for every order id in the response, not N+1); `ready`/`cancelled` rows are removal-only signals, kept deliberately cheap. Addon decoding (no shared helper exists in this codebase for it — only inlined twice in `class-dd-notifications.php`) is duplicated locally in a new private `get_kitchen_items_for_orders()` rather than editing that file, which is outside this release's scope; variation decoding reuses the existing `DD_Notifications::variation_lines()` helper. **kitchen_notified_at**: set via one idempotent `UPDATE ... WHERE id IN (...) AND kitchen_notified_at IS NULL` every time `ajax_kitchen_feed()` returns a `confirmed` row — "the first time any screen displays it," satisfied regardless of which tablet polls first, and safe under two tablets racing since the `IS NULL` guard means only the first writer's timestamp sticks. The manual "Notify Kitchen" button (`admin/pages/orders.php`) now calls a new `ajax_mark_kitchen_notified()` AJAX action instead of writing `localStorage` — same idempotent `IS NULL` guard. The reception order modal's Mark Ready unlock (`admin/pages/orders.php`, the `status === 'confirmed'` branch) now reads `order.kitchen_notified_at` (present for free via `ajax_get_order()`'s existing `SELECT *`) instead of `localStorage.getItem(LS_KITCHEN + id)` — unlocks correctly from a different device than the one that notified the kitchen. The now-fully-dead `LS_KITCHEN` localStorage key (declaration + its one remaining `removeItem` cleanup call) was removed as the direct, necessary completion of "stop reading it," not a separate cleanup pass; `LS_RIDER` and all rider logic are completely untouched. **Test-order exclusion**: `is_test = 0`, matching the dashboard Kitchen Queue card's own convention, per approval. **Sound/Wake Lock**: an "Enable Sound" overlay gates audio behind one explicit tap (browser autoplay policy), remembered for the tab's session via `sessionStorage` (not `localStorage`, matching the brief's "for that session" wording exactly); the bundled alert is a short, locally-synthesized two-tone WAV (`assets/sounds/kitchen-alert.wav`, generated by a one-off script, not downloaded from anywhere) — no external asset load anywhere on this page. `navigator.wakeLock.request('screen')` is feature-detected and wrapped in a `.catch()` that does nothing, so unsupported browsers fail quietly as required; re-requested on `visibilitychange` back to visible, since a wake lock is released whenever a tab is hidden. **Offline indicator**: a poll failure flips the topbar status pill to "Offline, retrying…" and sets a `needsFullResync` flag so the next successful poll after an outage omits `since` entirely — a full bootstrap-style resync on reconnect, not a possibly-stale delta. **Verification done**: `php -l` clean on all four PHP files (`class-dd-orders-module.php`, `admin/pages/orders.php`, `admin/pages/kitchen.php`, `install.php`); `node -c` clean on `kitchen-screen.js` and the extracted modal-JS block of `orders.php`; the generated WAV's header bytes were inspected and confirmed valid. **Verification NOT done** — same constraint as v3.18.53: no WordPress/MySQL instance exists in the environment this was written in (confirmed no `wp`/`mysql`/`docker`), so the dbDelta migration, the actual 5-second poll cycle, two-tablet sync, the sound/Wake-Lock browser APIs, and the full manual test list above have not been exercised against a real site — see Next task. Previous v3.18.53 entry: "Send test webhook" admin tool, scoped to `modules/whatsapp/class-dd-whatsapp-module.php`, `admin/pages/whatsapp-settings.php`, and `assets/js/whatsapp-settings.js` only; `class-dd-whatsapp-webhook.php`'s handling logic, signature check, and the tables are untouched, per the brief. New constant `DD_WhatsApp_Module::TEST_WA_NUMBER` (`250000000000`) is the one and only identifier used to scope both the test send and the cleanup delete — chosen because no real WhatsApp customer can have it, so a lookup/delete keyed on it structurally cannot touch a real conversation. `ajax_test_webhook()`: builds a sample inbound-message payload (`build_test_webhook_payload()`) shaped exactly like Meta's real `{"field":"messages", "value":{"contacts":[...], "messages":[...]}}` shape — required since v3.18.52 added the `'messages' !== $field` allow-list, so a test payload without an explicit `field` key would now be silently ignored; name "Dish Dash test", message id `'ddtest_' . wp_generate_uuid4()` (fresh every click, so the webhook's own `wa_message_id` idempotency check in `insert_message_log()` never treats a repeat test click as an already-stored duplicate). Signs the exact `wp_json_encode()`'d raw body with the saved `dd_whatsapp_app_secret` via `hash_hmac('sha256', ...)` + `sha256=` prefix — identical construction to `DD_WhatsApp_Webhook::signature_matches()` — then `wp_remote_post()`s that exact raw string (not re-encoded) to `rest_url('dish-dash/v1/whatsapp-webhook')`, so the real route, the real signature check, and the real DB writes all run, never calling any webhook method directly. On success, reads back `created_at` from both tables (conversation by `TEST_WA_NUMBER`, message by this click's own `wa_message_id`) and returns the HTTP status, both timestamps, and their difference in minutes to the admin page — the exact comparison that was previously a manual phpMyAdmin check. Three plain-language failure paths, each checked before the next: App Secret not saved (checked up front, before any request is sent) → "The App Secret is not saved — enter and save it above before testing the webhook."; `is_wp_error()` on the `wp_remote_post()` call (loopback failure, e.g. a host blocking a site from calling its own URL) → error message includes the underlying `WP_Error` message and says so explicitly; non-200 HTTP status from the webhook itself → "The webhook rejected the request — HTTP {status}." The App Secret's value is never included in any response or log line in any of these paths — only whether it's set. `ajax_delete_test_messages()`: looks up the one conversation row by `TEST_WA_NUMBER`, deletes `dishdash_message_log` rows by that row's `conversation_id`, then deletes the conversation row itself by `wa_number`, reporting both delete counts; a conversation that doesn't exist (never tested, or already cleaned up) returns zero counts rather than erroring. Both new AJAX actions (`dd_whatsapp_test_webhook`, `dd_whatsapp_delete_test_messages`) are registered `nopriv=false` and gated identically to the existing `dd_whatsapp_test_send` (nonce + `dd_manage_settings` capability check). Settings page gets a new "🔌 Webhook Test" card below the existing Test Send card, with "Send Test Webhook" and "Delete Test Messages" buttons sharing one result/spinner area; `whatsapp-settings.js` gets two new handler functions following the exact fetch/FormData/finally pattern the existing `sendTest()` already uses. **Verification done**: `php -l` clean on both edited PHP files. **Verification NOT done**: no actual HTTP round-trip, DB write, or admin-page click — there is no WordPress/MySQL instance available in the environment this was written in (confirmed: no `wp`, `mysql`, or `docker` on the machine), only the plugin source tree, so the result-panel output (HTTP status + both `created_at` values + the minute difference) has not been observed from a real run. This needs to happen after deployment, same as every other manual-verification item already tracked under Next task. Previous v3.18.52 entry: two small, templates-approved fixes, both scoped to `modules/whatsapp/class-dd-whatsapp-webhook.php` only (settings page, signature check, GET handshake untouched). (1) `get_or_create_conversation()`'s `$wpdb->insert()` now passes `created_at => current_time('mysql')` explicitly (same value already used for `last_message_at` in that call) instead of letting the column fall back to its own `DEFAULT CURRENT_TIMESTAMP` — that default reads the DB server's clock/timezone, while `dishdash_message_log.created_at` was already set via `current_time('mysql')` (WordPress's configured timezone), so the two tables were silently using two different time sources for the same inbound event. Both now share one source. (2) `process_payload()` now checks `$change['field']` before reading `value['messages']`/`value['statuses']` — previously it read those keys out of every change regardless of field type. Only `'messages' === $field` is processed (unchanged logic); `message_template_status_update` (a real Meta field, not yet handled — template approvals) and any other/unknown field are explicitly skipped. `handle_event()`'s existing try/catch already answered every signature-valid payload with 200 regardless of field — confirmed by reading, not changed. Previous v3.18.51 entry: GET verify-handshake fix — both the incoming `hub_verify_token` and the stored `dd_whatsapp_verify_token` option are `trim()`-ed before `hash_equals()`; the single shared-message `if` was split into three explicit branches (`no_stored_token`, `bad_mode`, `token_mismatch`), each logging its reason code via `error_log()` (the `token_mismatch` branch logs only `strlen()` of each token — never the token values) while the HTTP response to Meta stays the same generic `{"error":"Verification failed."}` 403 in all three cases, so no internal state leaks externally. Previous v3.18.50 entry: Phase 9A Step 3 — the public webhook, no outbound sends yet (Step 4, see Next task). New `modules/whatsapp/class-dd-whatsapp-webhook.php` (`DD_WhatsApp_Webhook`, instantiated from `DD_WhatsApp_Module::init()` — a sibling class within the same module folder, not a separate loader-registered module, same split-within-one-module pattern reservations already uses). Registers `dish-dash/v1/whatsapp-webhook` on `rest_api_init` with `permission_callback => '__return_true'` — deliberately public, since Meta's GET/POST calls carry no WordPress auth; chose the REST API over the `wc-api` pattern PesaPal's IPN uses specifically so `WP_REST_Request::get_body()` gives the exact raw bytes HMAC verification needs. **GET** answers Meta's verification handshake: reads `hub_mode`/`hub_verify_token`/`hub_challenge` (PHP converts the dotted `hub.*` query keys to underscores automatically — not a bug), compares the stored `dd_whatsapp_verify_token` via `hash_equals()`, echoes the challenge back as an int (JSON-encodes unquoted, byte-identical to the raw text Meta expects) on match, 403 otherwise — fails closed if no verify token is configured yet. **POST** verifies `X-Hub-Signature-256` (`sha256=` + `hash_hmac('sha256', $raw_body, $app_secret)`, `hash_equals()`) before touching the database at all — no signature, no app secret configured, or a mismatch all reject with 403 and store nothing; this is the first HMAC check anywhere in this codebase (PesaPal's IPN re-verifies server-to-server instead of checking a signature, a different trust model that doesn't apply to Meta's design). On a valid signature, walks `entry[].changes[].value` for `messages[]` (inbound — resolves/creates a `dishdash_wa_conversations` row by digits-only `wa_number`, matching `dishdash_customers.whatsapp` with `'+'` stripped on both sides since the stored format isn't consistent across the codebase, then inserts into `dishdash_message_log` and fires `do_action('dd_whatsapp_message_received', $conversation_id, $log_id)`) and `statuses[]` (status updates — a `sent(1) < delivered(2) < read(3) < failed(4)` rank map means a webhook can only move a row's status forward, and a replayed identical status is naturally a no-op under the same rank check; an unknown `wa_message_id` — the status arriving before the triggering send's own HTTP response comes back — is parked in a `dd_wa_orphan_status_{id}` transient for 24h rather than dropped, for Step 4's send path to consult). Every insert is idempotency-checked against `wa_message_id`/`wa_number` first (existing-row lookup, same as the orders module's `place_order()` pattern), with a `Duplicate entry`-detecting fallback for the race where two deliveries both miss that check simultaneously — a redelivered webhook stores one row, not two. All `$wpdb` calls use `%s`/`%d` placeholders throughout, no string concatenation of payload data into SQL. **A background security review of this exact diff found no High/Medium findings** — checked signature timing-safety, raw-body/parsed-body consistency, the fail-closed gate, and every query for injection. **Not yet verified against the real Meta dashboard** — the callback URL (`https://demo.dishdash.rw/wp-json/dish-dash/v1/whatsapp-webhook`) and verify token still need to be entered in Meta's App Dashboard and the handshake clicked through; see Next task and the acceptance checklist. Previous v3.18.49 entry: Phase 9A Step 2 — the two WhatsApp tables added to `install.php`, confirmed matching the data model in the demo database — full detail in RELEASE.md. |
| **GitHub** | github.com/frisoftltd/dish-dash |
| **Live site** | dishdash.khanakhazana.rw |
| **Server** | cPanel at server372.web-hosting.com (user: imitjsiy) |
| **Plugin path** | /home/imitjsiy/dishdash.khanakhazana.rw/wp-content/plugins/dish-dash/ |
| **Theme** | dish-dash-theme (custom blank theme — NOT Astra, NOT any other theme) |
| **Stack** | WordPress 6+, WooCommerce, PHP 8.2, vanilla JS (no jQuery, no build step), MySQL, LiteSpeed Cache |

---

## 🌐 Domains & Environments

| Environment | Domain | Server Path | Server | cPanel User |
|---|---|---|---|---|
| Marketing site | dishdash.rw | /home/theittav/dishdash.rw | server107 | theittav |
| Demo | demo.dishdash.rw | /home/theittav/demo.dishdash.rw | server107 | theittav |
| Dev | dev.dishdash.rw | /home/theittav/dev.dishdash.rw | server107 | theittav |
| Client — Khana Khazana (Nyarutarama) | nyarutarama.khanakhazana.rw | /home/imitjsiy/nyarutarama.khanakhazana.rw | server372 | imitjsiy |
| Client — Khana Khazana (Kiyovu) | kiyovu.khanakhazana.rw | /home/imitjsiy/kiyovu.khanakhazana.rw | server372 | imitjsiy |

Notes:
- nyarutarama.khanakhazana.rw is the CORRECT live client domain — any prior
  reference to "dishdash.khanakhazana.rw" elsewhere in this file or other docs
  is stale and should be corrected to nyarutarama.khanakhazana.rw wherever found.
- kiyovu.khanakhazana.rw directory exists but is not yet deployed — deployment
  is scheduled AFTER the current feature backlog ships. Do not deploy to Kiyovu
  until explicitly instructed.
- dishdash.rw is a static marketing site, NOT WordPress — separate build/deploy
  process from the plugin.
- demo.dishdash.rw / dev.dishdash.rw ARE WordPress + Dish Dash plugin, used for
  demos and pre-client testing.

---

## 🎨 Brand Colors

Brand colors are **always dynamic** — set by each restaurant in Dish Dash → Brand
Identity and stored in wp_options. All code must read colors from wp_options or
CSS variables. **Never hardcode hex values anywhere in the codebase.**

| Option Key | Description |
|---|---|
| `dish_dash_primary_color` | Header, buttons, active states, CTAs |
| `dish_dash_dark_color` | Secondary elements, text accents |
| `dish_dash_background_color` | Page background |
| `dish_dash_font` | Typography (Inter default) |

**Admin CSS variables (set on `<body>` by PHP in every admin page):**
```css
--dd-brand:       [restaurant primary color];
--dd-brand-rgb:   [R,G,B components for rgba() usage];
--dd-brand-light: [10% opacity version];
--dd-brand-dark:  [darkened 15%];
```

**Rule:** Khana Khazana uses `#65040d` as primary — this is one restaurant's
config, not a hardcoded value. What you see in the mockup is a placeholder.
Always read from `get_option('dish_dash_primary_color')` in PHP.

---

## 🔄 Workflow — How We Work

### Roles

| Role | Responsibilities |
|---|---|
| **Claude** (claude.ai) | Planning, architecture, investigation briefs, fix briefs, release notes |
| **Claude Code** (CLI terminal) | Executes file edits based on briefs — never infers tasks, never acts without a brief |
| **Developer** (human) | Deployment, testing, feedback |

> **Communication style:** Claude (chat) gives SHORT, straight-to-the-point answers, uses lists, no long paragraphs.

### The Loop

```
Claude writes Investigation Brief
↓
Claude Code investigates → reports findings (NO edits yet)
↓
Developer pastes findings to Claude
↓
Claude reviews → writes Fix Brief
↓
Claude Code edits files → reports complete
↓
Developer pastes report to Claude
↓
Claude gives release instructions (tag, title, description)
↓
Claude Code pushes → creates GitHub release (gh release create)
↓
Developer deploys → tests
↓
Developer reports result with screenshot → Claude writes next brief
↓
Repeat
```

### Claude Code Session Setup

Every Claude Code session MUST start with:
> Read `CLAUDE.md` from the repo root at github.com/frisoftltd/dish-dash
> before doing any work. This file contains the full project context,
> rules, architecture, and current state.

### Release Process

1. All files committed to `main` branch (lowercase — **NEVER `Main`**)
2. Version bumped in `dish-dash.php` in **BOTH** locations:
   - `* Version: X.X.X` in the plugin header comment
   - `define('DD_VERSION', 'X.X.X');` constant
3. **CLAUDE.md updated in the same commit** — `Last updated` line in CLAUDE.md updated + new row appended to RELEASE.md
4. Claude Code creates the GitHub release itself via `gh release create vX.X.X --title "..." --notes "..."` immediately after a successful push (**WITH the `v` prefix** — without it, Actions will not build the zip), using GitHub CLI (already installed and authenticated as frisoftltd on this machine). Confirm success with `gh release view vX.X.X` and report the release URL back.
5. GitHub Actions builds `dish-dash.zip` automatically (~30 seconds)
6. Deploy via ONE of:

**Method A — cPanel Terminal:**
```bash
cd /tmp && wget https://github.com/frisoftltd/dish-dash/releases/latest/download/dish-dash.zip && unzip -o dish-dash.zip -d /tmp/dd-update && cp -r /tmp/dd-update/dish-dash/* /home/imitjsiy/dishdash.khanakhazana.rw/wp-content/plugins/dish-dash/ && rm -rf /tmp/dd-update /tmp/dish-dash.zip && echo "Done!"
```

**Method B — WordPress auto-update:**
WP Admin → Plugins → Check for Updates → Update Now

7. Purge LiteSpeed Cache: WP Admin → LiteSpeed Cache → Toolbox → Purge All
8. Test in incognito window
9. Verify: `grep "DD_VERSION" /home/imitjsiy/dishdash.khanakhazana.rw/wp-content/plugins/dish-dash/dish-dash.php`

---

## 🚨 Rules — NEVER Break These

### Claude Code Operating Rules

**Rule 0 — Version bump + CLAUDE.md update is mandatory on every release.**
Every brief that ships code MUST end with:
- Bump `* Version: X.X.X` in dish-dash.php header
- Bump `define( 'DD_VERSION', 'X.X.X' );` in dish-dash.php
- Update `Last updated` line in CLAUDE.md + append new row to RELEASE.md
- `git add [all changed files] dish-dash.php CLAUDE.md RELEASE.md`
- `git commit -m "release: vX.X.X — [description]"`
- `git push origin HEAD:main`

Never commit changed files without dish-dash.php and CLAUDE.md.
Never push without the version bumped and CLAUDE.md updated.

**Rule 1a — Scope is a hard wall, not a guideline.**
If a brief says "fix X in file Y", touch ONLY file Y, ONLY the lines
that fix X. If you notice another bug while reading the file — REPORT IT.
Do not fix it. Do not "clean it up". Do not refactor "while you're in there".
Write it in your report and wait for a new brief.

**Rule 1b — Never touch a file not listed in the brief.**
If fixing X requires understanding file Z, you may READ file Z.
You may NOT edit file Z unless it is explicitly listed.
If you believe file Z also needs changing, REPORT IT and stop.
Wait for explicit instruction before touching it.

**Rule 2 — Always start in Plan Mode.**
`claude --permission-mode plan`
Analyze first, never edit without approval.

**Rule 3 — Never infer a task.**
Wait for a brief. Never assume what comes next.

**Rule 4 — NEVER run git add, commit, or push without explicit instruction from the developer.**

**Rule 5 — Use @mentions for exact files.**
Never read the whole codebase. Target only the files you need.

**Rule 6 — Run /compact between tasks.**

**Rule 7 — Be concise in reports.**
Root cause, files changed, test steps only.

### Code Rules

- **Always provide complete files** — never partial snippets
- **Always include exact GitHub path** for every file
- **Always state CREATE new or EDIT existing** for each file
- **Never change code outside the scope of the current task** — scope creep causes regressions
- **Always check current file state before editing** — read the file first
- **Investigation findings BEFORE writing code** — always diagnose, then fix
- **Always check inline styles in PHP templates before CSS files** — past bugs caused by inline styles, not CSS
- **Verify which template renders a given URL before editing** — wrong file = wasted release
- **Push to `main` (lowercase)** — NEVER `Main` (capital M creates orphan branch)
- **Claude Code creates release tags** via `gh release create vX.X.X --title "..." --notes "..."` immediately after a successful push — confirm with `gh release view vX.X.X` and report the release URL back

### Architecture Rules

Each module MUST be completely independent:
- Own folder: `modules/feature/`
- Own class: `class-dd-feature-module.php`
- Extends `DD_Module` base class
- Registers its own admin submenu independently
- Communicates with other modules ONLY via `do_action()` and `apply_filters()`
- NEVER directly calls another module's methods
- NEVER writes to another module's database table
- Template module uses `DD_PLUGIN_DIR` constant (not `plugin_dir_path(__FILE__)`)

### Data Access Rules

- **All NEW code** must use `DD_API::` for data access — no direct `wc_get_product()` or raw `$wpdb` calls in new features
- Existing code keeps working as-is — migrate gradually when files are touched
- `DD_API` returns normalized arrays, NOT WC_Product objects
- `DD_API` has built-in transient caching (5-min TTL, auto-invalidated on product save)

### DB Rules

- `dbDelta()` for all DB table creation — exclusively
- `dbDelta()` does NOT run on zip updates — any release adding new tables must manually call `DD_Install::create_tables()` via WP-CLI immediately after deploy

### Admin UI Rules (Phase 5 — enforced from v3.4.20 onward)

- No WP grey or WP blue (`#2271b1`) anywhere on any Dish Dash admin page
- No hardcoded hex colors — all colors from `var(--dd-brand)` CSS variable
- Restaurant logo must show in sidebar
- Inter font loaded on all admin pages
- `--dd-brand` and `--dd-brand-rgb` output on `:root` in `get_admin_styles()`
- Cards: 12px border-radius, `box-shadow: 0 1px 4px rgba(0,0,0,0.06)`, padding 24px

### Tracking Rules

- No feature without tracking — every user action must be recorded
- New tracking events MUST be added to `modules/tracking/event-schemas.php` FIRST
- `meta` JSON field contains ONLY metadata — dedicated DB columns are NOT listed in schema
- Validation mode: `warn` — events logged but not rejected

---

## 🏗 File Structure

```
dish-dash/
├── .github/workflows/release.yml
├── admin/
│   ├── pages/
│   │   ├── dashboard.php
│   │   ├── orders.php
│   │   ├── analytics.php
│   │   ├── customers.php
│   │   ├── reservations.php
│   │   ├── tables.php
│   │   ├── seating-sections.php
│   │   ├── settings.php
│   │   ├── brand-identity.php
│   │   ├── template.php
│   │   ├── homepage.php
│   │   ├── auth-login.php
│   │   ├── tools.php
│   │   ├── coming-soon.php
│   │   └── event-health.php
│   └── class-dd-admin.php
├── assets/
│   ├── css/ (admin, cart, menu, theme, frontend, menu-page)
│   └── js/  (admin, cart, menu, frontend, search, tracking, menu-page)
├── dishdash-core/
│   ├── class-dd-ajax.php
│   ├── class-dd-api.php               ← Normalized data facade (12 methods)
│   ├── class-dd-github-updater.php
│   ├── class-dd-helpers.php
│   ├── class-dd-hooks.php
│   ├── class-dd-install.php
│   ├── class-dd-loader.php
│   ├── class-dd-module.php
│   └── class-dd-settings.php
├── modules/
│   ├── menu/class-dd-menu-module.php
│   ├── orders/(class-dd-orders-module.php, class-dd-cart.php)
│   ├── template/class-dd-template-module.php
│   └── tracking/(class-dd-tracking-module.php, event-schemas.php)
├── templates/
│   ├── cart/cart.php
│   ├── checkout/checkout.php
│   ├── menu/grid.php                   ← Menu page content (shortcode)
│   ├── partials/product-card.php
│   ├── page-dishdash.php               ← Homepage template
│   └── themes/
│       └── khana-khazana/              ← Default frontend template (Phase 5D Part 2)
├── theme/dish-dash-theme/
│   ├── functions.php
│   ├── page.php
│   ├── singular.php
│   ├── index.php
│   └── style.css
├── ARCHITECTURE.md
├── CSS_REGISTRY.md
├── MODULE_CONTRACT.md
├── TRACKING_ROADMAP.md
├── TECHNICAL_ARCHITECTURE_VISION.md
├── CLAUDE.md                           ← THIS FILE — updated every release
└── dish-dash.php                       ← Main plugin file
```

---

## 🗺 URL → Template Mapping

| URL | Template | Primary CSS | Primary JS |
|---|---|---|---|
| `/` | `templates/page-dishdash.php` (via `template_include`) | `theme.css` | `frontend.js` |
| `/restaurant-menu/` | `theme/page.php` → `[dd_menu]` → `templates/menu/grid.php` | `menu-page.css` | `menu-page.js` |
| `/cart/` | `templates/cart/cart.php` | `cart.css` | `cart.js` |
| `/checkout/` | `templates/checkout/checkout.php` | — | — |

---

## 📆 Development Phases

| Phase | Status | Description |
|---|---|---|
| **Phase 1** | ✅ | Foundation — plugin, GitHub updater, WooCommerce integration |
| **Phase 2** | ✅ | Template system — header, hero, footer, branding, mobile 3-screen menu |
| **Phase 3** | ✅ | Cart, Orders, WhatsApp notifications, Opening Hours |
| **Phase 4** | ✅ | Reservations — table booking, notifications, tables, seating sections |
| **Phase 5A** | ✅ | Clean & Secure — WP noise removed, custom admin URL `/khazana`, `/wp-admin` → 404 |
| **Phase 5B** | ✅ | Admin layout shell — dark sidebar, top bar, brand injection |
| **Phase 5C** | ✅ | Brand Identity page, Template card picker |
| **Phase 5D** | ✅ | Full admin redesign + frontend template system |
| **Phase 6** | ✅ | MoMo Payment Integration — MTN Mobile Money payment gateway, in-drawer payment flow |
| **Phase 7** | ✅ | User Access Control — customer profiles, roles, permissions, order history |
| **Phase 8** | ⏳ CURRENT | Feature Backlog — paid reservations (flat fee, per-person scaling, PesaPal deposit, accept modal) ✅, CSV menu import/export tool ✅, admin dashboard cleanup, test customer flag, MoMo payment proof upload ✅, ordering/reservation toggle, Minimal Light template ✅, Modern Dark template, dishdash.rw marketing site redesign, WhatsApp Business API integration, Kitchen screen + server-side kitchen flag ✅ (v3.18.54, refined in v3.18.55 — Preparing stage, quieter/controllable sound), Kiyovu branch deployment |
| **Phase 9** | ⏳ | Analytics + AI — Python microservice, behavior engine, recommendations |
| **Phase 10** | ⏳ | Loyalty & QR — points system, QR scan ordering |
| **Phase 11** | ⏳ | Testing + Optimization |
| **Phase 12** | ⏳ | SaaS Platform — multi-tenant, subscription billing, white-label |

---

## 🖥 Phase 5 — Backend Dashboard & Admin Transformation

### Vision

The WordPress admin is completely transformed into a professional SaaS product.
Each restaurant feels like they own the system — not a generic WordPress install.
Structure is universal. Colors, logo, fonts come from Brand Identity settings.

**What must NEVER appear in the admin:**
- WordPress logo anywhere
- WordPress blue (`#2271b1`) or default WP grey
- Plugin update badges or notification banners (except the Updates page)
- Any hint this is built on WordPress

---

### Dish Dash Admin Sidebar — Final Menu (in order)

| # | Item | Status |
|---|---|---|
| 1 | 📊 Dashboard | ✅ |
| 2 | 🧾 Orders | ✅ |
| 3 | 📈 Analytics | ✅ |
| 4 | 👥 Customers | ✅ |
| 5 | 📅 Reservations | ✅ |
| 6 | 🪑 Tables | ✅ |
| 7 | 🪟 Seating Sections | ✅ |
| 8 | ⚙️ Settings | ✅ |
| 9 | 🎨 Brand Identity | ✅ |
| 10 | 🖼 Template | ✅ |
| 11 | 🏠 Homepage | ✅ |
| 12 | 🔐 Auth & Login | ✅ |
| 13 | 🔧 Tools | ✅ |

**Removed from Dish Dash menu:** Menu Items, Delivery, Branches, POS Terminal

**WordPress native menus visible to restaurant owner:** Media, Pages, Users only.
Everything else hidden.

---

### Phase 5A — Clean & Secure ✅ Complete

**Key implementation notes:**
- `admin_menu` (priority 999) strips update count bubbles from sidebar
- `remove_all_actions('admin_notices')` + `remove_all_actions('all_admin_notices')`
- Exception: `get_current_screen()->id === 'update-core'` — never suppress on Updates page
- `add_filter('woocommerce_helper_suppress_admin_notices', '__return_true')` for WC notices
- Custom path stored in `dd_admin_custom_path` wp_option, superadmin only

---

### Phase 5B — General Layout ✅ Complete

**Layout specs (reference):**

*Sidebar:*
- Collapsed: 60px, background `#1a1a1a`, icon only, 48px hit area
- Active: 3px left border + icon in `var(--dd-brand)`
- Expanded: 240px, 200ms ease transition, full logo + label

*Top bar:*
- Height 56px, background `#ffffff`, border-bottom `1px solid #eeeeee`
- Left: page title (20px Inter semibold)
- Right: notification bell + admin avatar + restaurant name
- No WordPress toolbar

*Content area:*
- Background `#f8f8f8`, padding 32px, full width (no max-width on content)

*Cards:*
- Background `#ffffff`, border-radius 12px, box-shadow `0 1px 4px rgba(0,0,0,0.06)`, padding 24px

*Typography:*
- Font: Inter (Google Fonts)
- Page title: 20px 600 `#111111`
- Section title: 16px 600 `#111111`
- Body: 14px 400 `#444444`
- Label: 12px 500 `#888888`
- KPI numbers: 28–32px 700 `#111111`

---

### Phase 5C — New Pages ✅ Complete

---

### Phase 5D — Full Admin Redesign + Frontend Template System ✅ Complete

#### Part 1 — Admin Pages Redesign

Every page before shipping must pass:
- ✅ No WP grey or WP blue anywhere
- ✅ Restaurant logo in sidebar
- ✅ Brand color on active states and CTAs (from `--dd-brand` — never hardcoded)
- ✅ Inter font loaded
- ✅ Spacious cards, 12px radius, soft shadows
- ✅ Dashboard content fills full width — no max-width cap killing right side

**Dashboard v3.4.44 spec (agreed design):**
- Header: page title + open/closed status dot + date range filter (Today/7d/30d/All)
- KPI row: 6 cards with colored left accent strips — Orders (indigo), Revenue (emerald), Pending (amber), AOV (blue), New Customers (purple), Reservations Today (rose)
- Each KPI card: icon + label + big number + delta badge (↑↓%)
- Revenue chart: bar chart, brand color bars, Chart.js, range-aware (hourly for Today, daily for 7d/30d)
- Left column (60%): Recent Orders list + Today's Reservations list
- Right column (40%): Top Menu Items (ranked + progress bars) + Customer Tiers (stacked bar)
- Quick Actions bar: Add Menu Item · View Orders · Preview Menu · Settings
- All colors from `--dd-brand` — zero hardcoded hex
- Content fills 100% available width

#### Part 2 — Frontend Template System (v3.4.48+)

- DishDash pages registered as proper WordPress page templates
- Folder: `templates/themes/khana-khazana/` (SaaS-ready — multiple templates post-MVP)
- Active template controlled by `dd_active_template` wp_option
- Specific page decisions (keep/delete/redirect) made at implementation time

---

### Phase 5E — Template Library (Post-MVP)

| Item | Status |
|---|---|
| Khana Khazana template | ✅ Default — built |
| Minimal Light template | ✅ Built v3.18.5 — `templates/layouts/minimal-light/`, `assets/css/layouts/minimal-light.css` |
| Modern Dark template | ⏳ Post-MVP — next release, intended to reuse Minimal Light's markup/PHP against a different `--ml-*`-equivalent token block |

---

## 🗄 Key Database Tables

| Table | Key Columns |
|---|---|
| `wp_dishdash_orders` | id, wc_order_id, customer_name, customer_phone, total, status (now includes `preparing`, v3.18.55), payment_status, payment_method, order_type, created_at, updated_at (indexed, v3.18.54), kitchen_notified_at (v3.18.54 — set when the kitchen screen first displays the order, or the manual Notify Kitchen button), preparing_at (v3.18.55, nullable — set by `update_status()`'s existing timestamp pattern when status becomes `preparing`) |
| `wp_dishdash_order_items` | order_id, product_name, quantity, price |
| `wp_dishdash_customers` | whatsapp (primary identity), name, total_orders, total_spent, first_order_at, last_order_at, birthday, delivery_address, dd_birthday_asked |
| `wp_dishdash_reservations` | date, time, guests, name, whatsapp, status, session |
| `wp_dishdash_user_events` | event_type, product_id, category_id, meta JSON, schema_version, created_at |
| `wp_dishdash_user_profiles` | Built in Phase 6 |
| `wp_dishdash_birthday_tokens` | token, customer_id, used, expires_at |
| `wp_dishdash_delivery_zones` | Future — created now, not yet used |

**Customer tier thresholds:**
| Tier | Condition |
|---|---|
| New | 0 orders |
| Regular | ≥1 order, total_spent < RWF 100,000 |
| VIP | total_spent ≥ RWF 100,000 |
| Champion | total_spent ≥ RWF 250,000 |
| Diamond | total_spent ≥ RWF 500,000 |

---

## ⚙️ wp_options Keys Reference

**Brand / Template:**
`dish_dash_restaurant_name`, `dish_dash_logo_url`, `dish_dash_primary_color`,
`dish_dash_dark_color`, `dish_dash_background_color`, `dish_dash_font`,
`dish_dash_hero_title`, `dish_dash_hero_subtitle`, `dish_dash_hero_image`,
`dish_dash_address`, `dish_dash_phone`, `dish_dash_contact_email`,
`dish_dash_opening_hours`, `dish_dash_facebook`, `dish_dash_instagram`,
`dish_dash_whatsapp`, `dish_dash_tiktok`

**Delivery:**
`dd_free_delivery_threshold` (10000), `dd_delivery_fee` (1500), `dd_delivery_eta`

**WhatsApp:**
`dd_whatsapp_admin`

**Hours:**
`dd_opening_hours`, `dd_closing_soon_minutes` (30), `dd_timezone` (Africa/Kigali)

**Pricing & Fees (Phase 5D v3.4.91+):**
`dd_per_order_fee` (750, INT) — flat fee (RWF) charged per confirmed order for Dish Dash invoicing
`dd_minimum_order_amount` (10000, INT) — advisory minimum shown to customers at checkout
`dd_payment_card_enabled` ('1', '0'|'1') — whether Pesapal card is offered
`dd_payment_momo_enabled` ('1', '0'|'1') — whether MTN MoMo Pay is offered
`dd_payment_cod_enabled` ('1', '0'|'1') — whether Cash on Delivery is offered

**Admin (Phase 5):**
`dd_admin_custom_path` — custom admin URL path, superadmin only

**Frontend:**
`dd_active_template` — active frontend template slug (default: khana-khazana)

---

## 🧠 AI Core Systems (Build in Phase 6)

### 1. Behavior Tracking Engine ✅ Already Live
- Table: `wp_dishdash_user_events`
- Events tracked: view_product, view_category, search, add_to_cart, page_view, order, reorder
- Validation: runtime schema enforcement — 0% failure rate
- Health check: WP Admin → Dish Dash → Tools

### 2. User Profile Engine (Phase 6)
- Table: `wp_dishdash_user_profiles` (exists in DB, not yet populated)

### 3. AI Rules Engine (Phase 6)
- Simple IF/THEN rules first — no ML yet
- Module: `modules/ai/class-dd-ai-module.php`

### 4. Smart Nudges System (Phase 6)
- Module: `modules/nudges/class-dd-nudges-module.php`

---

## 📊 Tracking Status

| Event | Source | Status |
|---|---|---|
| `view_product` | tracking.js (IntersectionObserver) | ✅ Live |
| `view_category` | tracking.js + menu-page.js | ✅ Live |
| `search` | tracking.js | ✅ Live |
| `add_to_cart` | tracking.js | ✅ Live |
| `page_view` | tracking.js | ✅ Live |
| `order` | DDTrack.order() | ✅ Schema defined |
| `reorder` | PHP only | ✅ Schema defined |
| `remind_me_open` | frontend.js | ⏳ Phase 6 |

**Health Check:** 0 failures / 189 events sampled. Validation mode: `warn`.

---

## 🧠 Key Lessons Learned (Hard-Won)

| Lesson | Context |
|---|---|
| Always check inline styles in PHP templates before CSS files | 800px width was an inline style in `grid.php` — caused 4 wrong fixes |
| `page-dishdash.php` is the HOMEPAGE template, not the menu page | Shortcode in `grid.php` renders `/restaurant-menu/` |
| `dish-dash-theme` is the active theme, NOT Astra | Only `dish-dash-theme` exists on server |
| `display: flex !important` overrides HTML `hidden` attribute | Use `.dd-cat-row:not([hidden])` instead |
| Unchecked HTML checkboxes don't submit in forms | Must use `isset($_POST[$key]) ? '1' : '0'` |
| LiteSpeed Cache masks frontend changes | Always purge explicitly when debugging UI |
| `git push origin Main` creates an orphan branch | Always lowercase `main` |
| Functions inside containing functions cause JS scope conflicts | Extract into independent modules |
| `dbDelta()` does not run on zip updates | New DB tables need manual `DD_Install::create_tables()` via WP-CLI post-deploy |
| `remove_all_actions('admin_notices')` suppresses all plugin banners | Exception: check `get_current_screen()->id === 'update-core'` first |
| Dashboard content width was capped — right side dead zone | Check for `max-width` in admin.css AND inline styles in dashboard.php |
| Google Reviews `(array) $r` deep-cast bug | Only converts outer level — fix requires recursive `dd_to_array()` |
| WhatsApp notifications use `window.location.href` not `window.open` | Avoids mobile browser popup blocking |
| `woocommerce_payment_complete` hook wired to `DD_Notifications` | Any future gateway fires notifications automatically |
| OPcache/auto-update race condition causes fatal errors | Mitigated with `class_exists` guard + `opcache_reset()` on `upgrader_process_complete` |
| Minification removed in v3.10.20 | `asset_url()` now returns source files directly — no `.min` lookup. GitHub Actions no longer generates `.min` files. LiteSpeed Cache handles production compression. The minifier was failing silently (copying originals), so `.min` files provided no benefit. |

---

## ⚠️ Known Issues

| Issue | Detail |
|---|---|
| `wp_dishdash_orders.pesapal_tracking_id` missing from `install.php` | The column (idempotency key for the PesaPal IPN/poll promote path, `VARCHAR(64) NULL` + `UNIQUE KEY`) only exists on the live DB via a manual `ALTER TABLE` documented in `report.md` (v3.11.2) — it was never added to `install.php`'s `CREATE TABLE dishdash_orders`. Fresh installs run with PesaPal order idempotency **off** by default (safe — `has_pesapal_tracking_column()` gates every reference, degrading gracefully) until someone runs the ALTER manually. Found during Paid Reservations v1 (v3.13.x) while adding the equivalent column to `wp_dishdash_reservations` — that one WAS added to `install.php` correctly (see `CREATE TABLE dishdash_reservations`). Not fixed here — flagged for a future cleanup release: add `pesapal_tracking_id` to `install.php`'s orders table definition so new installs get the column via the normal auto-migration guard instead of a manual step. |

---

## 🚀 Multi-Tenant Deploy Checklist

Run before handing any site to a restaurant:

1. AJAX smoke test:
```bash
curl -s -X POST https://[site]/wp-admin/admin-ajax.php \
  -d "action=dd_cart_get" | grep -q "success" \
  && echo "AJAX ✅" || echo "AJAX ❌ BROKEN"
```
2. Confirm response is not 404
3. Set up UptimeRobot monitor on `https://[site]/wp-admin/admin-ajax.php`
   — POST method, 5-min interval, SMS + email alert on failure
4. Only hand site to restaurant after both checks pass

---

## 📋 Related Documentation

| Document | Purpose |
|---|---|
| `ARCHITECTURE.md` | URL → file mapping, module dependency graph |
| `CSS_REGISTRY.md` | Every `dd-` CSS class: where defined, where used |
| `MODULE_CONTRACT.md` | Module isolation rules, hooks registered/fired |
| `TRACKING_ROADMAP.md` | Tracking expansion plan |
| `TECHNICAL_ARCHITECTURE_VISION.md` | PHP → Python hybrid migration roadmap |
| `modules/tracking/event-schemas.php` | Living schema contract for event metadata |

