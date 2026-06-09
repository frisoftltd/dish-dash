# 🔍 Dish Dash — Software Engineering Audit Framework

> **This document is a living standard.**
> Run it before every major phase. Refine it after every audit. Never delete findings — mark them resolved.
>
> Last audited: v3.5.42 (2026-06-09)
> Next audit: before Phase 8 begins

---

## 🎯 Audit Philosophy

Every audit must answer one question first:

> **"Is this codebase faithfully serving the core mission?"**

**Core mission:** DishDash is a smart ordering system that learns customer behavior and makes ordering faster, easier, and more personalized every time.

An audit that only checks security and architecture but misses dead tracking events is a failed audit. Behavior data is the product. Gaps in tracking data = gaps in the AI that Phase 6 depends on.

---

## 📋 How to Run This Audit

### When to run
- Before starting a new phase
- After a major feature sprint (every 5–10 releases)
- When a regression or bug is found that shouldn't have passed review
- When a new engineer joins the project

### Who runs it
Claude Code runs the mechanical scan. Claude (claude.ai) reviews the findings and writes the fix brief. Developer deploys and verifies.

### How to run
1. Paste the Claude Code brief in the relevant pillar section below into your terminal
2. Claude Code produces a structured report
3. Paste the report back to Claude for review
4. Claude produces a prioritized fix brief
5. Fix sprint ships as a single "audit fix" release before the next phase

---

## 🏛 The 5 Audit Pillars

---

### PILLAR 1 — Mission Alignment

**Question:** Is every user action tracked? Is every tracked event actually firing?

**Why this matters:** Phase 6 AI runs on the data collected in Phases 1–5. Dead tracking events = silent data gaps = broken AI recommendations.

#### What to check
- All events defined in `event-schemas.php` must have at least one firing call (JS or PHP)
- All user-facing actions (button clicks, form submits, page loads) must have a tracking event attached
- No schema-defined event should have zero calls anywhere in the codebase

#### Claude Code scan brief
```
Read CLAUDE.md first.

PILLAR 1 SCAN — Mission Alignment. READ ONLY. Do not edit anything.

1. Open modules/tracking/event-schemas.php — list every defined event type and its schema version.

2. Search all JS files in assets/js/ for:
   - DDTrack.event(
   - DDTrack.track(
   - DDTrack.search(
   - DDTrack.addToCart(
   - DDTrack.cartOpen(
   - DDTrack.cartAbandon(
   - DDTrack.removeFromCart(
   - DDTrack.cartQuantityChange(
   - DDTrack.checkoutStart(
   - fetch('/wp-admin/admin-ajax.php') with action: 'dd_track_event'
   List each match: event name, file, line number.

3. Search all PHP files for:
   - do_action('dd_track'
   - DD_Tracking_Module::
   - 'dd_track_event'
   List each match: event name, file, line number.

4. Cross-reference: list any schema-defined event with ZERO JS or PHP firing calls.

5. List any user-facing actions in templates/ and assets/js/ (button clicks, 
   form submits, page transitions) with NO associated tracking call.

OUTPUT:
## PILLAR 1 — MISSION ALIGNMENT
Defined events: [list with schema version]
Events firing in JS: [table: event | file | line]
Events firing in PHP: [table: event | file | line]
DEAD EVENTS (defined, never fired): [list or "None"]
UNTRACKED USER ACTIONS: [list or "None found"]
Score: 🟢 Good / 🟡 Needs attention / 🔴 Critical
```

#### Pass criteria
- 🟢 Zero dead events. Zero untracked primary user actions (add to cart, checkout, order, reservation).
- 🟡 Dead events exist but are intentionally deferred (marked with comment in schema file).
- 🔴 Dead events with no explanation. Core user actions (order, add_to_cart) have no tracking.

#### Known history
| Version | Finding | Resolution |
|---|---|---|
| v3.4.8 | `reorder` and `deposit_failed` defined in schema, never fired | Removed from schema in v3.4.9 |
| v3.4.8 | `deposit_initiated`, `deposit_paid`, `booking_auto_cancelled` — PHP calls exist but unreachable | Retained with comment — deposit feature disabled |
| v3.4.8 | Auth events (login, register) not in schema at all | Deferred — add before Phase 6 |
| v3.4.8 | Closed-state banner buttons ("Browse Menu", "Message Us") not tracked | Deferred — add in Phase 3D polish |

---

### PILLAR 2 — Architecture Compliance

**Question:** Are all modules isolated? Is DD_API the only data access layer?

**Why this matters:** Architecture violations cause cascading regressions. One module writing to another module's table means a change in one feature silently breaks another. This is how bugs appear in unrelated features.

#### What to check
- Every module class extends `DD_Module`
- No module calls another module's methods directly
- No module writes to another module's DB table
- All product/category data access uses `DD_API::` not raw `wc_get_product()` or `$wpdb`

#### Claude Code scan brief
```
Read CLAUDE.md first.

PILLAR 2 SCAN — Architecture Compliance. READ ONLY. Do not edit anything.

1. List every PHP class file in modules/ — for each:
   - Does it extend DD_Module? (grep "extends DD_Module")
   - Does it register its own admin submenu?
   - Is it a helper class (acceptable to not extend DD_Module)?

2. Cross-module method violations — search entire codebase for any file 
   calling another module's static methods directly.
   Pattern: DD_[ModuleName]_Module:: appearing in a file that belongs to a 
   different module. List file, line, what it calls.

3. Cross-module DB violations — search for $wpdb->insert / $wpdb->update / 
   $wpdb->delete where the table name belongs to a different module's domain.
   Module → table ownership:
   - orders module → dishdash_orders, dishdash_order_items
   - customers module → dishdash_customers, dishdash_birthday_tokens
   - reservations module → dishdash_reservations, dishdash_reservation_refunds, dishdash_tables
   - tracking module → dishdash_user_events, dishdash_user_profiles
   List any violation: file, line, table written to.

4. Data access violations — search all PHP files for:
   - wc_get_product() calls outside of DD_API class itself
   - wc_get_products() calls outside of DD_API class itself
   - $wpdb->get_results on wp_posts or wp_postmeta outside of DD_API
   List each: file, line.

5. List all public static methods on DD_API (dishdash-core/class-dd-api.php).

OUTPUT:
## PILLAR 2 — ARCHITECTURE COMPLIANCE
Module inventory: [table: file | class | extends DD_Module? | own submenu?]
Cross-module method violations: [list or "None"]
Cross-module DB violations: [list or "None"]
Data access violations: [list or "None"]
DD_API methods: [list]
Score: 🟢 / 🟡 / 🔴
```

#### Pass criteria
- 🟢 No cross-module violations. All product data through DD_API.
- 🟡 Legacy direct calls exist in old files not yet migrated — acceptable if tracked.
- 🔴 New code (written after v3.1.15) still uses direct WC calls. Any module writing to another module's table.

#### Known history
| Version | Finding | Resolution |
|---|---|---|
| v3.4.8 | `DD_Reservations_Module` writing directly to `dishdash_customers` | Fixed in v3.4.9 — replaced with `apply_filters('dd_resolve_customer_id')` |
| v3.4.8 | `class-dd-api.php:358` calls `DD_Tracking_Module::get_session_id()` — inverted dependency | Deferred — low severity, fix in Phase 5 refactor |
| v3.4.8 | 6 direct `wc_get_product()` calls in module files | Legacy — migrate gradually when files are touched |

---

### PILLAR 3 — Data Integrity

**Question:** Are all DB tables and columns present exactly as specified?

**Why this matters:** Missing columns cause silent data loss. A birthday never saved. A delivery address never persisted. These are invisible bugs that hurt customer experience and Phase 6 training data quality.

#### What to check
- All tables in `class-dd-install.php` match the CLAUDE.md spec
- All required columns exist with correct types
- All tables use `dbDelta()` — no raw `CREATE TABLE` bypasses
- Schema migrations use `ALTER TABLE` safely

#### Claude Code scan brief
```
Read CLAUDE.md first.

PILLAR 3 SCAN — Data Integrity. READ ONLY. Do not edit anything.

1. Open dishdash-core/class-dd-install.php — list every table created 
   via dbDelta() with its columns and types.

2. Cross-reference against CLAUDE.md Phase 3 DB schema section:
   Required tables: dishdash_orders, dishdash_order_items, dishdash_customers,
   dishdash_birthday_tokens, dishdash_delivery_zones, dishdash_reservations,
   dishdash_reservation_refunds, dishdash_tables, dishdash_user_events,
   dishdash_user_profiles, dishdash_branches, dishdash_analytics
   
   Required columns in dishdash_customers:
   whatsapp VARCHAR(20), birthday DATE NULL, dd_birthday_asked TINYINT(1), 
   delivery_address TEXT NULL
   
   Required columns in dishdash_birthday_tokens:
   id, token VARCHAR(64) UNIQUE, customer_id INT, used TINYINT(1), 
   expires_at DATETIME, created_at DATETIME

3. Search all PHP files for $wpdb->query("CREATE TABLE") — flag any that 
   bypass dbDelta().

4. Search for ALTER TABLE calls — list each with file and line. 
   Are they safely guarded (checking if column exists before adding)?

OUTPUT:
## PILLAR 3 — DATA INTEGRITY
Tables defined: [list]
Missing tables vs spec: [list or "All present"]
Missing columns vs spec: [list or "All present"]
Raw CREATE TABLE bypassing dbDelta: [list or "None"]
ALTER TABLE calls: [list with safety check status]
Score: 🟢 / 🟡 / 🔴
```

#### Pass criteria
- 🟢 All tables and columns present. No `dbDelta` bypasses. All ALTERs guarded.
- 🟡 New table added in recent release but not yet in install.php — minor oversight.
- 🔴 Missing columns causing silent data loss. Raw CREATE TABLE calls.

#### Known history
| Version | Finding | Resolution |
|---|---|---|
| v3.4.8 | All Phase 3 tables and columns present | ✅ Clean |
| General | `dbDelta()` does not run on zip updates | Always run `DD_Install::create_tables()` via WP-CLI after any release that adds new tables |

---

### PILLAR 4 — Performance

**Question:** Is the site fast on mobile? Are we wasting queries and bandwidth?

**Why this matters:** Speed is addictive. Every 100ms of extra load time reduces repeat orders. Africa is mobile-first — bandwidth is expensive. Unnecessary assets and N+1 queries directly damage the core mission.

#### What to check
- Assets only load on pages that need them
- No N+1 query patterns in frontend render paths
- Expensive queries are transient-cached
- File sizes are reasonable (unminified — production uses LiteSpeed compression)

#### Claude Code scan brief
```
Read CLAUDE.md first.

PILLAR 4 SCAN — Performance. READ ONLY. Do not edit anything.

1. Open modules/template/class-dd-template-module.php.
   Find enqueue_frontend_assets(). Is is_dishdash_page() guard present?
   List all assets (CSS + JS) enqueued and whether each is gated to 
   specific pages or loads globally.

2. Search templates/ and assets/js/ for any PHP loop (foreach/while) or 
   JS loop that contains a DB call, wc_get_product(), or fetch() inside 
   the loop body. List each: file, line, what's inside the loop.

3. Search all PHP files in modules/ for $wpdb->get_results and $wpdb->get_row
   on frontend-facing or AJAX handler code paths. For each, check if the 
   result is wrapped in get_transient/set_transient. List uncached queries.

4. Run: ls -lh ~/Documents/dish-dash/assets/css/
   Run: ls -lh ~/Documents/dish-dash/assets/js/
   List all files with sizes.

5. Check if any JS or CSS file exceeds these thresholds:
   CSS: warn if any single file > 50KB unminified
   JS: warn if any single file > 50KB unminified

OUTPUT:
## PILLAR 4 — PERFORMANCE
Asset gating status: [is_dishdash_page() present? yes/no]
Assets loading globally (not gated): [list or "None"]
N+1 patterns: [list or "None found"]
Uncached frontend DB queries: [list or "None"]
CSS files: [name | size | over threshold?]
JS files: [name | size | over threshold?]
Score: 🟢 / 🟡 / 🔴
```

#### Pass criteria
- 🟢 All assets gated. No N+1 patterns. No uncached queries on hot paths.
- 🟡 One or two legacy uncached queries. File sizes approaching threshold.
- 🔴 Assets loading globally on all pages. N+1 in menu render. Multiple uncached hot-path queries.

#### Thresholds (unminified — LiteSpeed compresses in production)
| Asset type | Warning | Critical |
|---|---|---|
| Single CSS file | > 50KB | > 100KB |
| Single JS file | > 50KB | > 100KB |
| Total CSS per page | > 100KB | > 200KB |
| Total JS per page | > 100KB | > 200KB |

#### Known history
| Version | Finding | Resolution |
|---|---|---|
| v3.4.8 | ~405KB CSS+JS loading on every WP page — no page gating | Fixed in v3.4.9 — `is_dishdash_page()` guard added |
| v3.4.8 | N+1: `wc_get_product()` inside WP_Query loop in `grid.php` | Fixed in v3.4.9 — pre-fetch with `wc_get_products(['include' => $ids])` |
| v3.4.8 | `theme.css` at 83KB unminified | Monitor — acceptable while unminified, LiteSpeed compresses |
| v3.4.8 | `frontend.js` at 66KB unminified | Monitor — consider splitting in Phase 6 |
| v3.4.8 | Tracking event profile queries uncached (fires on every event) | Deferred — acceptable at current scale, revisit at Phase 6 |

---

### PILLAR 5 — Security

**Question:** Is every AJAX endpoint protected? Is user input sanitized?

**Why this matters:** An unprotected admin endpoint can allow order status manipulation or data exposure. Unsanitized input can corrupt the database. These are not theoretical — they are real attack vectors on live restaurant systems.

#### What to check
- Every `wp_ajax_` handler has nonce verification
- Admin-only handlers check `current_user_can()`
- All `$_POST`/`$_GET` input is sanitized before use
- No raw SQL string interpolation with user-controlled variables
- No hardcoded secrets in PHP files

#### Claude Code scan brief
```
Read CLAUDE.md first.

PILLAR 5 SCAN — Security. READ ONLY. Do not edit anything.

1. Search all PHP files for add_action('wp_ajax_ and add_action('wp_ajax_nopriv_.
   For each registered handler, find its method and check:
   a. Does it call check_ajax_referer(), wp_verify_nonce(), or DD_Ajax::verify_nonce()?
   b. For admin-only actions (not nopriv): does it check current_user_can()?
   List any handler missing either check.

2. Search all PHP files for $wpdb->query( $wpdb->prepare( — verify prepare() 
   is used. Then search for $wpdb->query( without prepare( — list each with 
   file and line. Is the variable user-controlled or internal only?

3. Search all PHP files for $_POST[, $_GET[, $_REQUEST[.
   For each, check if the value is passed through:
   sanitize_text_field(), intval(), absint(), sanitize_email(), 
   wp_kses(), wp_unslash(), or json_decode() with schema validation.
   List any direct use without sanitization.

4. Search all PHP files for hardcoded strings matching patterns:
   - API keys (long alphanumeric strings assigned to variables named *key*, *token*, *secret*)
   - Passwords assigned to variables named *pass*, *password*, *pwd*
   List any found.

OUTPUT:
## PILLAR 5 — SECURITY
AJAX handlers missing nonce: [list or "All secure"]
Admin handlers missing capability check: [list or "All secure"]
Unsafe SQL (user input not prepared): [list or "None"]
Unsanitized input: [list or "None"]
Hardcoded secrets: [list or "None found"]
Score: 🟢 / 🟡 / 🔴
```

#### Pass criteria
- 🟢 All handlers have nonce. All admin handlers have capability check. No unsafe SQL with user input. No hardcoded secrets.
- 🟡 One public read-only endpoint missing nonce (low exploitability). Minor sanitization inconsistency (stripslashes vs wp_unslash).
- 🔴 Admin endpoint missing nonce or capability check. User-controlled variable in raw SQL. Hardcoded API key.

#### Known history
| Version | Finding | Resolution |
|---|---|---|
| v3.4.8 | `dd_get_reviews` AJAX handler — no nonce check (read-only, public data) | Deferred — low risk, fix in next security sprint |
| v3.4.8 | `class-dd-cart.php:241` — `stripslashes` instead of `wp_unslash` | Deferred — functionally equivalent, cosmetic fix |
| v3.4.8 | `$password` not sanitized in auth module | Correct — passwords must not be sanitized before hashing |
| v3.4.8 | No hardcoded secrets found | ✅ Clean |

---

## 📊 Audit Score History

| Version | Date | Mission | Architecture | Data | Performance | Security | Overall |
|---|---|---|---|---|---|---|---|
| v3.4.8 | 2026-05-18 | 🟡 | 🟡 | 🟢 | 🟡 | 🟢 | 🟡 |
| v3.4.9 | 2026-05-18 | 🟡 | 🟢 | 🟢 | 🟢 | 🟢 | 🟢 |
| v3.5.42 | 2026-06-09 | 🟡 | 🟡 | 🟢 | 🟡 | 🟢 | 🟡 |

**Score key:** 🟢 Good — 🟡 Needs attention — 🔴 Critical issues

---

## 🔧 Deferred Issues Backlog

Issues found during audits but intentionally deferred. Review before each new phase.

| # | Issue | Found in | Priority | Target phase |
|---|---|---|---|---|
| D1 | Auth events (login, register) not in tracking schema | v3.4.8 | Medium | Phase 5 |
| D2 | Closed-state banner buttons ("Browse Menu", "Message Us") not tracked | v3.4.8 | Low | Phase 3D polish |
| D3 | ~~`class-dd-api.php:358` calls `DD_Tracking_Module::get_session_id()` — inverted dependency~~ | v3.4.8 | — | ✅ Resolved v3.5.42 — now a comment only, not live code |
| D4 | 4 direct `wc_get_product()` calls in legacy module files (menu, orders, cart, tracking) | v3.4.8 | Low | Migrate when files touched |
| D5 | `dd_get_reviews` AJAX missing nonce | v3.4.8 | Low | Next security sprint |
| D6 | Tracking profile queries uncached (fires per event) | v3.4.8 | Medium | Phase 8 (scale concern) |
| D7 | `theme.css` at 83KB unminified | v3.4.8 | Low | Phase 10 optimization |
| D8 | `frontend.js` at 65KB — consider splitting | v3.4.8 | Low | Phase 8+ |
| D9 | `add_to_cart` not tracked from homepage product cards / modal — only fires from menu page | v3.5.42 | Medium | Phase 8 pre-work |
| D10 | `dd_billing_payments` table uses `dd_` prefix instead of `dishdash_` — naming inconsistency | v3.5.42 | Low | Cosmetic — fix when convenient |
| D11 | `dd_track_event` uses `check_ajax_referer(..., false)` — soft nonce, does not die on failure | v3.5.42 | Low | Document or harden before Phase 8 |

---

## ✅ Resolved Issues Log

| Issue | Found in | Fixed in | Fix summary |
|---|---|---|---|
| 405KB assets loading on every WP page | v3.4.8 | v3.4.9 | `is_dishdash_page()` guard in `enqueue_frontend_assets()` |
| N+1 query in `grid.php` menu render | v3.4.8 | v3.4.9 | Pre-fetch with `wc_get_products(['include' => $ids])` |
| Reservations writing to customers table directly | v3.4.8 | v3.4.9 | Replaced with `apply_filters('dd_resolve_customer_id')` |
| Dead tracking schemas: `reorder`, `deposit_failed` | v3.4.8 | v3.4.9 | Removed from `event-schemas.php`, version bumped to v1.1 |
| D3: `class-dd-api.php:358` inverted dependency on DD_Tracking_Module | v3.4.8 | v3.5.42 | Confirmed comment-only in audit scan — not live code, no code change needed |

---

## 📐 Architecture Quick Reference

### Module ownership map

| Module | Owns tables | Fires hooks | Listens to hooks |
|---|---|---|---|
| orders | dishdash_orders, dishdash_order_items | `dd_order_created`, `dd_upsert_customer` | `woocommerce_payment_complete` |
| customers | dishdash_customers, dishdash_birthday_tokens | — | `dd_upsert_customer`, `dd_resolve_customer_id` |
| reservations | dishdash_reservations, dishdash_reservation_refunds, dishdash_tables | `dd_reservation_created` | `dd_resolve_customer_id` |
| tracking | dishdash_user_events, dishdash_user_profiles | — | `wp_ajax_dd_track_event` |
| menu | — | — | `woocommerce_*` |
| template | — | — | `template_include`, `wp_enqueue_scripts` |
| homepage | — | — | `wp_ajax_dd_get_reviews` |
| auth | — | `dd_user_registered` | — |

### Data access rule (as of v3.1.15)
```
All NEW code → DD_API::method()
Legacy code → migrate when file is touched
DD_API → wc_get_product() / $wpdb (only place this is allowed)
```

### Cross-module communication rule
```
✅ do_action('dd_event_name', $data)
✅ apply_filters('dd_filter_name', $value, $data)  
❌ DD_OtherModule::method()
❌ $wpdb->insert into another module's table
```

---

## 📝 Audit Checklist — Quick Run Sheet

Before every major phase, confirm all of these:

- [ ] Run Pillar 1 scan — zero dead tracking events
- [ ] Run Pillar 2 scan — zero cross-module violations in new code
- [ ] Run Pillar 3 scan — all tables and columns match spec
- [ ] Run Pillar 4 scan — `is_dishdash_page()` guard still in place, no new N+1 patterns
- [ ] Run Pillar 5 scan — all new AJAX handlers have nonce
- [ ] Check deferred backlog — any D-items ready to fix?
- [ ] Update score history table above
- [ ] Update CLAUDE.md session history with audit version

---

*This document lives at `AUDIT.md` in the repo root alongside `CLAUDE.md`, `ARCHITECTURE.md`, and `TRACKING_ROADMAP.md`.*
*Refine after every audit. Never delete findings — only mark them resolved.*
