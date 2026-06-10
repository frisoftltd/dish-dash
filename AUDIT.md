# 🔍 Dish Dash — Software Engineering Audit Framework

> **This document is a living standard.**
> Run it before every major phase and before every major release. Refine it after every audit. Never delete findings — mark them resolved.
>
> Last audited: v3.5.42 (2026-06-09)
> Next audit: before Phase 8 begins

---

## 🎯 Audit Philosophy

Every audit must answer one question first:

> **"Is this codebase faithfully serving the core mission?"**

**Core mission:** DishDash is a smart ordering system that learns customer behavior and makes ordering faster, easier, and more personalized every time.

An audit that only checks security and architecture but misses dead tracking events is a failed audit. Behavior data is the product. Gaps in tracking data = gaps in the AI that Phase 8 depends on.

---

## 📋 How to Run This Audit

### When to run
- Before starting a new phase
- Before every major release (any release adding DB tables, AJAX handlers, or auth changes)
- After a major feature sprint (every 5–10 releases)
- When a regression or bug is found that shouldn't have passed review
- When a new engineer joins the project

### Who runs it
Claude Code CLI reads this file and executes every check. Claude Code runs the mechanical scan. Claude (claude.ai) reviews the findings and writes the fix brief. Developer deploys and verifies.

### How to run
1. Open this file and run the scan brief for each pillar in your terminal
2. Claude Code produces a structured report per pillar
3. Paste the combined report back to Claude for review
4. Claude produces a prioritized fix brief using the severity system below
5. Fix sprint ships as a single "audit fix" release before the next phase

---

## 🚦 Severity Scoring System

All findings are classified using this system. Traffic-light scores (🟢🟡🔴) in the history table map to the highest severity found in that pillar.

| Level | Symbol | Definition | Action |
|---|---|---|---|
| **Critical** | 🔴 | Exploitable now — data loss, account takeover, or payment risk | Block release immediately |
| **High** | 🟠 | Significant risk or data quality gap — will cause visible damage | Fix before next release |
| **Medium** | 🟡 | Degrades quality, performance, or tracking fidelity | Fix within current phase |
| **Low** | 🔵 | Technical debt, naming inconsistency, minor violation | Fix when convenient |
| **Info** | ⚪ | Noted for awareness — no immediate action required | Log and monitor |

**History table legend:** 🟢 = no Medium+ findings | 🟡 = Medium findings present | 🔴 = Critical or High findings present

---

## 🏛 The 7 Audit Pillars

---

### PILLAR 1 — Mission Alignment

**Question:** Is every user action tracked? Is every tracked event actually firing?

**Why this matters:** Phase 8 AI runs on the data collected in Phases 1–7. Dead tracking events = silent data gaps = broken AI recommendations.

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
Score: [highest severity found]
```

#### Pass criteria
- 🟢 Zero dead events. Zero untracked primary user actions (add to cart, checkout, order, reservation).
- 🟡 Dead events exist but intentionally deferred (marked with comment in schema file). Minor secondary actions untracked.
- 🔴 Dead events with no explanation. Core user actions (order, add_to_cart) have no tracking.

#### Known history
| Version | Finding | Severity | Resolution |
|---|---|---|---|
| v3.4.8 | `reorder` and `deposit_failed` defined in schema, never fired | 🟡 Medium | Removed from schema in v3.4.9 |
| v3.4.8 | `deposit_initiated`, `deposit_paid`, `booking_auto_cancelled` — PHP calls exist but unreachable | ⚪ Info | Retained with comment — deposit feature disabled |
| v3.4.8 | Auth events (login, register) not in schema at all | 🟡 Medium | Deferred — add before Phase 8 (D1) |
| v3.4.8 | Closed-state banner buttons ("Browse Menu", "Message Us") not tracked | 🔵 Low | Deferred — add in Phase 3D polish (D2) |
| v3.5.42 | `add_to_cart` only tracked from menu page — not from homepage cards/modal | 🟡 Medium | Deferred — D9 |

---

### PILLAR 2 — Architecture & Code Quality

**Question:** Are all modules isolated? Is DD_API the only data access layer? Is the codebase free of hardcoded values and stale artifacts?

**Why this matters:** Architecture violations cause cascading regressions. Hardcoded values break multi-tenant support. Stale minified files serve outdated code silently.

#### What to check
- Every module class extends `DD_Module`
- No module calls another module's methods directly
- No module writes to another module's DB table
- All product/category data access uses `DD_API::` not raw `wc_get_product()` or `$wpdb`
- No hardcoded hex colors — all colors from CSS variables (`var(--dd-brand)`, `var(--brand)`)
- No `.min.js` or `.min.css` files on disk (they shadow source files silently)
- Version number consistent in both locations in `dish-dash.php` (header comment AND constant)
- `CLAUDE.md` `Last updated` version matches `DD_VERSION` in `dish-dash.php`
- New PHP functions use `isset($_POST['key']) ? $_POST['key'] : ''` not `$_POST['key'] ?? ''` where WordPress 5.x compatibility matters (note: `??` is fine for PHP 7+ targets)

#### Claude Code scan brief
```
Read CLAUDE.md first.

PILLAR 2 SCAN — Architecture & Code Quality. READ ONLY. Do not edit anything.

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

5. Hardcoded hex colors — grep all PHP and CSS files for #[0-9a-fA-F]{3,6}
   that are NOT inside var(--) declarations, not in comments, not in SVG data URIs.
   List any found in files that should be using CSS variables.

6. Stale minified files — run: find assets/ -name "*.min.js" -o -name "*.min.css"
   List any found. All should be absent (LiteSpeed handles compression in production).

7. Version consistency:
   - grep "Version:" dish-dash.php
   - grep "DD_VERSION" dish-dash.php
   - grep "Last updated" CLAUDE.md
   All three must match.

8. List all public static methods on DD_API (dishdash-core/class-dd-api.php).

OUTPUT:
## PILLAR 2 — ARCHITECTURE & CODE QUALITY
Module inventory: [table: file | class | extends DD_Module? | own submenu?]
Cross-module method violations: [list or "None"]
Cross-module DB violations: [list or "None"]
Data access violations: [list or "None"]
Hardcoded hex colors: [list or "None"]
Stale .min files: [list or "None"]
Version consistency: [header | constant | CLAUDE.md — match or mismatch]
DD_API methods: [list]
Score: [highest severity found]
```

#### Pass criteria
- 🟢 No cross-module violations in new code. No .min files. Versions consistent. No hardcoded hex in new files.
- 🟡 Legacy direct WC calls in old files. Minor hex hardcoding in non-critical files.
- 🔴 New code (written after v3.1.15) uses direct WC calls. Any module writing to another module's table. Version mismatch. .min files shadowing source.

#### Known history
| Version | Finding | Severity | Resolution |
|---|---|---|---|
| v3.4.8 | `DD_Reservations_Module` writing directly to `dishdash_customers` | 🔴 Critical | Fixed in v3.4.9 — replaced with `apply_filters('dd_resolve_customer_id')` |
| v3.4.8 | `class-dd-api.php:358` calls `DD_Tracking_Module::get_session_id()` | 🔵 Low | ✅ Resolved v3.5.42 — confirmed comment only, not live code |
| v3.4.8 | 6 direct `wc_get_product()` calls in module files | 🔵 Low | Legacy — reduced to 4 in v3.5.42 audit — migrate when files touched (D4) |
| v3.5.28 | Stale `cart.min.js` + `reservations.min.js` serving outdated code | 🟠 High | Fixed in v3.5.28 — deleted via `git rm` |
| v3.5.42 | `dd_billing_payments` uses `dd_` prefix instead of `dishdash_` | 🔵 Low | Deferred — cosmetic (D10) |

---

### PILLAR 3 — Data Integrity & Privacy

**Question:** Are all DB tables and columns present exactly as specified? Is customer data handled responsibly?

**Why this matters:** Missing columns cause silent data loss. PII sent over WhatsApp or logged to error logs creates GDPR exposure. These are invisible bugs that hurt customer trust and legal compliance.

#### What to check
- All tables in `install.php` match the CLAUDE.md spec
- All required columns exist with correct types
- All tables use `dbDelta()` — no raw `CREATE TABLE` bypasses
- Schema migrations use `ALTER TABLE` safely (column-existence guard before ALTER)
- GDPR: what customer PII is stored, is there a deletion mechanism, is data minimised
- WhatsApp notifications: is PII (names, phone numbers, addresses) sent? Is it necessary?
- PHP error log / WP debug log: no customer PII written with `error_log()` or `trigger_error()`

#### Claude Code scan brief
```
Read CLAUDE.md first.

PILLAR 3 SCAN — Data Integrity & Privacy. READ ONLY. Do not edit anything.

1. Open install.php — list every table created via dbDelta() with its columns and types.

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

5. GDPR inventory — search dishdash_customers for PII columns:
   List all columns that contain personal data (name, phone, address, birthday).
   Is there a customer deletion function anywhere in the codebase?
   Is birthday stored only after explicit consent (dd_birthday_asked = 1)?

6. WhatsApp PII check — search modules/ for WhatsApp message construction
   (look for wa.me links, $message variables sent to WhatsApp numbers).
   List what PII is included in each message type (kitchen, customer, reservation).
   Is the PII necessary for the business function?

7. Error log PII check — grep all PHP files for error_log( containing
   customer_name, whatsapp, phone, email, address, or password.
   List any found.

OUTPUT:
## PILLAR 3 — DATA INTEGRITY & PRIVACY
Tables defined: [list]
Missing tables vs spec: [list or "All present"]
Missing columns vs spec: [list or "All present"]
Raw CREATE TABLE bypassing dbDelta: [list or "None"]
ALTER TABLE calls: [list with safety check status]
GDPR — PII columns: [list]
GDPR — deletion mechanism: [exists / missing]
GDPR — birthday consent guard: [present / missing]
WhatsApp PII audit: [list message types + PII included + necessity verdict]
Error log PII: [list or "None found"]
Score: [highest severity found]
```

#### Pass criteria
- 🟢 All tables/columns present. All ALTERs guarded. No PII in error logs. WhatsApp PII limited to operational necessity.
- 🟡 No customer deletion function (common for MVP — log as deferred). Minor WhatsApp PII that is operationally justified.
- 🔴 Missing columns causing silent data loss. Raw CREATE TABLE calls. PII in error logs. WhatsApp leaking data not needed for the operation.

#### Known history
| Version | Finding | Severity | Resolution |
|---|---|---|---|
| v3.4.8 | All Phase 3 tables and columns present | ✅ Clean | — |
| General | `dbDelta()` does not run on zip updates | ⚪ Info | Auto-migration guard added in v3.4.92 — runs on admin_init |
| v3.5.22 | `is_test` column added to reservations without guard | 🟡 Medium | Fixed in v3.5.22 — DESCRIBE guard added before ALTER |
| v3.5.42 | No customer data deletion mechanism found | 🟡 Medium | Deferred — GDPR deletion API needed before Phase 9 SaaS launch |

---

### PILLAR 4 — Performance & Core Web Vitals

**Question:** Is the site fast on mobile? Are we serving excessive assets, N+1 queries, or unoptimized images?

**Why this matters:** Speed is addictive. Every 100ms of extra load time reduces repeat orders. Africa is mobile-first — bandwidth is expensive. Poor Core Web Vitals hurt SEO and user retention equally.

#### What to check
- Assets only load on pages that need them (`is_dishdash_page()` guard)
- No N+1 query patterns in frontend render paths
- Expensive queries are transient-cached
- File sizes are reasonable (unminified — production uses LiteSpeed compression)
- Core Web Vitals: LCP < 2.5s, CLS < 0.1, FID < 100ms on mobile
- Product images served at appropriate dimensions (no 2000px images in 300px slots)
- LiteSpeed Cache active and configured with page caching + image optimization

#### Claude Code scan brief
```
Read CLAUDE.md first.

PILLAR 4 SCAN — Performance & Core Web Vitals. READ ONLY. Do not edit anything.

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

4. Run: ls -lh assets/css/ && ls -lh assets/js/
   List all files with sizes.

5. Check file thresholds:
   CSS: warn if any single file > 50KB unminified
   JS: warn if any single file > 50KB unminified
   Total CSS on menu page (theme + frontend + menu-page + cart + reservations): flag if > 150KB
   Total JS on menu page (frontend + menu-page + cart + tracking + search): flag if > 150KB

6. Image dimensions check — search templates/ for <img tags.
   Are product images using srcset or explicit width/height attributes?
   Are hero images using CSS background-image (preferred) or inline <img>?

7. LiteSpeed Cache — search wp-config.php or active plugins for
   LSCWP or LiteSpeed. Is page caching enabled? Is image optimization enabled?
   (Note: verify manually on server — cannot be reliably checked from code alone.)

OUTPUT:
## PILLAR 4 — PERFORMANCE & CORE WEB VITALS
Asset gating: [is_dishdash_page() present? yes/no]
Globally-loaded assets (not gated): [list or "None beyond intentional globals"]
N+1 patterns: [list or "None found"]
Uncached frontend DB queries: [list or "None"]
CSS files: [name | size | over 50KB?]
JS files: [name | size | over 50KB?]
Total CSS (menu page): [sum | over 150KB?]
Total JS (menu page): [sum | over 150KB?]
Image dimension issues: [list or "None found"]
LiteSpeed status: [active / unknown — manual verification needed]
Score: [highest severity found]
```

#### Pass criteria
- 🟢 All assets gated. No N+1 patterns. No uncached hot-path queries. Files within threshold. LiteSpeed active.
- 🟡 One or two legacy uncached queries. File sizes over threshold (LiteSpeed mitigates). No image srcset.
- 🔴 Assets loading globally on all pages. N+1 in menu render. Multiple uncached hot-path queries. LiteSpeed not active.

#### Thresholds (unminified — LiteSpeed compresses in production)
| Asset type | Medium 🟡 | Critical 🔴 |
|---|---|---|
| Single CSS file | > 50KB | > 100KB |
| Single JS file | > 50KB | > 100KB |
| Total CSS per page | > 150KB | > 300KB |
| Total JS per page | > 150KB | > 300KB |

#### Core Web Vitals targets (mobile, 4G)
| Metric | Target | Fail |
|---|---|---|
| LCP (Largest Contentful Paint) | < 2.5s | > 4.0s |
| CLS (Cumulative Layout Shift) | < 0.1 | > 0.25 |
| FID / INP (Interaction to Next Paint) | < 100ms | > 300ms |

#### Known history
| Version | Finding | Severity | Resolution |
|---|---|---|---|
| v3.4.8 | ~405KB CSS+JS loading on every WP page — no page gating | 🔴 Critical | Fixed in v3.4.9 — `is_dishdash_page()` guard added |
| v3.4.8 | N+1: `wc_get_product()` inside WP_Query loop in `grid.php` | 🟠 High | Fixed in v3.4.9 — pre-fetch with `wc_get_products(['include' => $ids])` |
| v3.4.8 | `theme.css` at 83KB unminified | 🔵 Low | Monitor — LiteSpeed compresses. Deferred D7 (Phase 10) |
| v3.4.8 | `frontend.js` at 65KB unminified | 🔵 Low | Monitor. Deferred D8 (Phase 8+) |
| v3.4.8 | Tracking event profile queries uncached | 🔵 Low | Deferred D6 — acceptable at current scale (Phase 8) |
| v3.5.42 | Total CSS on menu page: ~163KB; total JS: ~157KB | 🟡 Medium | Deferred D12 — LiteSpeed mitigates in production (Phase 10) |

---

### PILLAR 5 — Security — OWASP Top 10 + WordPress Hardening

**Question:** Is every attack surface protected? Are we compliant with OWASP Top 10?

**Why this matters:** A live restaurant system handles payment data and customer PII. One broken admin endpoint can allow order manipulation, fake deliveries, or customer data exfiltration. These are real-world threats, not theoretical.

#### OWASP Top 10 Checklist

**A01 — Broken Access Control**
- Every `wp_ajax_` admin handler has `current_user_can()` check
- No admin page accessible without `manage_options` or custom capability
- Order status changes require `dd_manage_orders` capability
- No IDOR — order/reservation lookups validate ownership or admin role

**A02 — Cryptographic Failures**
- No plaintext secrets in PHP files (`DD_GITHUB_TOKEN` must be empty or environment-injected)
- Passwords handled by WordPress core (`wp_hash_password`) — never custom hashed
- No MD5 for security purposes (MD5 for cache keys only is acceptable)
- Birthday tokens use `bin2hex(random_bytes(32))` — cryptographically secure

**A03 — Injection**
- All `$wpdb` queries use `$wpdb->prepare()` for user-controlled input
- `$wpdb->query()` calls use only server-controlled variables (table names from `$wpdb->prefix`)
- All output HTML-escaped: `esc_html()`, `esc_attr()`, `esc_url()` in templates
- No `eval()`, `system()`, `exec()`, `shell_exec()` calls

**A04 — Insecure Design**
- COD (Cash on Delivery) orders: is there a cap on outstanding balance per customer?
- Reservation spam prevention: rate limiting or CAPTCHA on submission?
- Cart total validated server-side on order placement, not trusted from client

**A05 — Security Misconfiguration**
- `WP_DEBUG` should be `false` in production (`wp-config.php`)
- `WP_DEBUG_LOG` should be `false` in production
- No directory listing on `assets/` (`.htaccess` or server config)
- `DISALLOW_FILE_EDIT` set to `true` in `wp-config.php`

**A06 — Vulnerable & Outdated Components**
- WordPress version: should be on latest stable (6.x)
- WooCommerce version: should be on latest stable (8.x+)
- PHP version: should be 8.1+ (8.2 target per CLAUDE.md)
- No abandoned plugins with known CVEs

**A07 — Identification & Authentication Failures**
- Login rate limiting implemented (`dd_login` AJAX handler checks attempt count via transient)
- Session invalidated on logout (`wp_destroy_current_session()` called)
- Birthday token single-use enforced (`used = 1` set on redemption)
- No default admin username (admin, administrator)

**A08 — Software & Data Integrity Failures**
- GitHub Actions release zip is SHA-verified before deploy? (Manual check)
- Auto-update via WordPress plugin updater — updates come from verified GitHub release
- No `unserialize()` on user-controlled input

**A09 — Security Logging & Monitoring Failures**
- Critical actions logged: order status changes, reservation status changes, auth failures
- No customer PII (names, phone, address) in `error_log()` output
- Auth failure attempts logged to transient for rate limiting

**A10 — Server-Side Request Forgery (SSRF)**
- `dd_get_reviews` AJAX — Google Places API URL is constructed from admin-set `place_id` (wp_options), not user input
- No user-controlled URL passed to `wp_remote_get()` or `curl`
- Birthday WhatsApp redirect — URL constructed server-side from sanitized phone number, not user-submitted URL

#### WordPress-Specific Hardening Checklist
- [ ] `wp-config.php` not publicly accessible (server-level protection)
- [ ] `xmlrpc.php` disabled or IP-restricted
- [ ] User enumeration blocked (`?author=1` returns 404 or redirects)
- [ ] Default `admin` username not in use
- [ ] `DISALLOW_FILE_EDIT true` in `wp-config.php`
- [ ] REST API `/wp/v2/users` endpoint returns 401 for unauthenticated requests
- [ ] Custom admin URL active (`dd_admin_custom_path` option set)

#### Claude Code scan brief
```
Read CLAUDE.md first.

PILLAR 5 SCAN — Security (OWASP + WordPress). READ ONLY. Do not edit anything.

1. AJAX handler audit — for every add_action('wp_ajax_*') and add_action('wp_ajax_nopriv_*'):
   a. Find the handler method.
   b. Does it call check_ajax_referer(), wp_verify_nonce(), or DD_Ajax::verify_nonce()?
   c. For admin-only (not nopriv): does it check current_user_can()?
   d. Note if nonce check uses the third-arg=false (soft check — does not die on failure).
   List any handler missing nonce or capability.

2. SQL injection check:
   a. grep all PHP for $wpdb->query( without $wpdb->prepare( — list each.
   b. For each found: is the variable user-controlled or server-controlled?
   c. grep all PHP for $wpdb->get_results( and $wpdb->get_row( — same check.

3. Output escaping check — grep templates/ and admin/pages/ for:
   echo $  (unescaped variable output)
   print $
   List any without esc_html/esc_attr/esc_url wrapper.

4. Hardcoded secrets check:
   grep all PHP for variables named *key*, *token*, *secret*, *password*, *api*
   assigned to non-empty string literals.

5. eval/exec check:
   grep all PHP for eval(, system(, exec(, shell_exec(, passthru(
   List any found.

6. WordPress hardening — check wp-config.php (if readable) for:
   DISALLOW_FILE_EDIT, WP_DEBUG, WP_DEBUG_LOG

OUTPUT:
## PILLAR 5 — SECURITY
AJAX handlers missing nonce: [list or "All secure"]
Admin handlers missing capability: [list or "All secure"]
Soft nonce checks (won't die): [list or "None"]
Unsafe SQL: [list or "None"]
Unescaped output: [list or "None"]
Hardcoded secrets: [list or "None found"]
Dangerous PHP functions: [list or "None"]
WP hardening status: [DISALLOW_FILE_EDIT | WP_DEBUG | WP_DEBUG_LOG]
Score: [highest severity found]
```

#### Pass criteria
- 🟢 All handlers have nonce + capability. No unsafe SQL with user input. No hardcoded secrets. WP hardening in place.
- 🟡 One public read-only endpoint missing nonce (low exploitability). Minor soft nonce check that is intentional.
- 🔴 Admin endpoint missing nonce or capability. User-controlled variable in raw SQL. Hardcoded API key. eval() call.

#### Known history
| Version | Finding | Severity | Resolution |
|---|---|---|---|
| v3.4.8 | `dd_get_reviews` AJAX — no nonce check (read-only, public data) | 🔵 Low | Deferred D5 — low exploitability |
| v3.4.8 | `class-dd-cart.php:241` — `stripslashes` instead of `wp_unslash` | 🔵 Low | Deferred — functionally equivalent |
| v3.4.8 | `$password` not sanitized in auth module | ⚪ Info | Correct — passwords must not be sanitized before hashing |
| v3.4.8 | No hardcoded secrets found | ✅ Clean | — |
| v3.5.42 | `dd_track_event` uses `check_ajax_referer(..., false)` — soft nonce | 🔵 Low | Deferred D11 — intentional (tracking should degrade gracefully) |

---

### PILLAR 6 — AI-Generated Code Quality

**Question:** Does AI-generated code follow safe patterns? Are known mistake patterns absent?

**Why this matters:** AI assistants (Claude Code) write most of this codebase. AI has known blind spots: it over-trusts client input, under-escapes output, and repeats patterns from training data that may not match this project's conventions. This pillar catches systematic AI mistakes before they accumulate.

#### What to check

**Output escaping (XSS prevention)**
- All PHP `echo` in templates uses `esc_html()`, `esc_attr()`, or `esc_url()` as appropriate
- WhatsApp `href` URLs use `esc_attr()` not `esc_url()` — `esc_url()` strips `%0A` line breaks (known lesson from v3.4.51)
- No `echo $variable` without escaping wrapper in template files

**Input sanitization**
- All `$_POST`/`$_GET` values sanitized with `sanitize_text_field()`, `intval()`, `absint()`, `sanitize_email()` before DB write
- No raw `$_POST['key']` passed directly to `$wpdb->insert()` or `$wpdb->update()`

**Dangerous PHP functions**
- No `eval()`, `system()`, `exec()`, `shell_exec()`, `passthru()` anywhere in plugin code

**JavaScript date handling**
- No `new Date().toISOString()` used for Kigali-timezone date display (known lesson — use `getFullYear()/getMonth()/getDate()` to avoid UTC offset bugs)
- All date inputs/outputs explicitly timezone-aware

**Admin menu management**
- No `remove_submenu_page()` calls — CSS hiding only (known lesson from v3.5.13 — `remove_submenu_page()` causes menu rendering issues)

**Schema migrations**
- No `dbDelta()` used for adding columns to existing tables — use `ALTER TABLE` with column-existence guard (known lesson — `dbDelta()` on existing tables is unreliable for column additions)

**Module boundaries in new code**
- Any module file written after v3.1.15 must not call `wc_get_product()` directly — use `DD_API::get_product()` instead

#### Claude Code scan brief
```
Read CLAUDE.md first.

PILLAR 6 SCAN — AI-Generated Code Quality. READ ONLY. Do not edit anything.

1. XSS check — grep all PHP files in templates/ and admin/pages/ for:
   echo $ (unescaped)
   List each: file, line, variable name.
   Note: exclude lines that already have esc_html/esc_attr/esc_url on same line.

2. WhatsApp href check — grep all PHP files for:
   href="https://wa.me  and  href="<?php echo esc_url(
   For any WhatsApp link using esc_url() — flag as potential line-break issue.

3. Unsanitized DB writes — grep all PHP files for:
   $wpdb->insert(  and  $wpdb->update(
   For each, check if $_POST or $_GET values are sanitized before being passed.
   List any direct $_POST usage in insert/update calls.

4. Dangerous functions:
   grep all PHP for eval(  system(  exec(  shell_exec(  passthru(
   List any found.

5. JavaScript date issues — grep assets/js/ for:
   .toISOString(
   new Date(
   List each: file, line. Flag any used for display output (not just parsing).

6. remove_submenu_page check:
   grep all PHP for remove_submenu_page(
   List any found (should be zero — CSS hiding only).

7. New wc_get_product violations — grep modules/ for wc_get_product(
   For each, check the file's last-modified date or git blame.
   Flag any in files created/modified after v3.1.15 (2026-04-14).

OUTPUT:
## PILLAR 6 — AI-GENERATED CODE QUALITY
Unescaped echo in templates: [list or "None"]
WhatsApp links using esc_url(): [list or "None — all use esc_attr()"]
Unsanitized DB writes: [list or "None"]
Dangerous PHP functions: [list or "None"]
JavaScript toISOString() usage: [list or "None"]
remove_submenu_page() calls: [list or "None"]
New wc_get_product() violations: [list or "None"]
Score: [highest severity found]
```

#### Pass criteria
- 🟢 No unescaped output. No raw $_POST in DB writes. No dangerous functions. No known-bad patterns.
- 🟡 One or two minor escaping issues in admin-only pages (low XSS exposure). Legacy toISOString() in non-display context.
- 🔴 Unescaped user input in frontend template. Raw $_POST in DB write. eval() call. WhatsApp link using esc_url() with line breaks.

#### Known history
| Version | Finding | Severity | Resolution |
|---|---|---|---|
| v3.4.51 | `esc_url()` on WhatsApp href strips `%0A` newlines — messages arrive as one line | 🟠 High | Fixed in v3.4.51 — switched to `esc_attr()` |
| v3.5.13 | `remove_submenu_page()` caused sidebar rendering issues | 🟡 Medium | Fixed in v3.5.13 — CSS-only hiding adopted as standard |
| v3.5.22 | `dbDelta()` called for `is_test` column add — unreliable for existing tables | 🟡 Medium | Fixed in v3.5.22 — `ALTER TABLE` with `DESCRIBE` guard |

---

### PILLAR 7 — Regression Testing Checklist

**Question:** Do all critical user flows still work after this release?

**Why this matters:** Unit tests don't exist for this codebase. This checklist is the regression safety net. Every release that touches frontend code, AJAX handlers, or CSS must run through the relevant section before shipping.

**How to use:** Check off each item manually in an incognito window after deploying to the live site. Test on both mobile (iPhone or Android, real device) and desktop.

#### Frontend Flows

- [ ] **Homepage** — loads on mobile (≤ 1024px viewport), hero visible, CTA buttons work
- [ ] **Homepage** — loads on tablet (iPad Air 5, 820px), mobile layout renders (not desktop)
- [ ] **Homepage** — reservation section visible, "Reserve a Table" button opens modal
- [ ] **Menu page** — category list loads on mobile (Screen 1)
- [ ] **Menu page** — category search filters categories in real time (type 3+ chars)
- [ ] **Menu page** — product search finds dish by name (type 3+ chars, results appear)
- [ ] **Menu page** — tap dish card opens product detail (Screen 3 or modal)
- [ ] **Menu page** — quick-add button adds item to cart without zoom on iOS
- [ ] **Menu page** — tap Back returns to category list, search cleared
- [ ] **Cart** — add item, quantity badge updates in header, cart total correct
- [ ] **Cart** — remove item, total recalculates
- [ ] **Cart** — proceed to checkout button works
- [ ] **Checkout** — form submits, order confirmation shown
- [ ] **Reservation modal** — full flow: Date → Session → Guests → Details → Confirm
- [ ] **Reservation modal** — no iOS zoom on any input field or button
- [ ] **Reservation modal** — stepper +/− buttons work without double-tap zoom
- [ ] **Reservation modal** — Back buttons work on each screen
- [ ] **Footer** — Privacy Policy link opens correctly
- [ ] **Footer** — Refund & Returns link opens correctly
- [ ] **Policy pages** — render with Dish Dash Simple Page template (no WP default styling)
- [ ] **Notification bell (admin)** — new order appears within 30s, beep plays once
- [ ] **Notification bell** — clicking notification opens order modal

#### Admin Flows

- [ ] **Orders page** — loads, all columns visible, no dead zone on right side
- [ ] **Orders page** — clicking order row opens detail modal
- [ ] **Orders page** — status change in modal persists on reload
- [ ] **Orders page** — WhatsApp notification fires on Confirm
- [ ] **Reservations page** — loads, date range filter works
- [ ] **Reservations page** — status change (Confirm / Cancel) works via AJAX
- [ ] **Customers page** — loads, Orders tab and Reservations tab both work
- [ ] **Analytics page** — Orders tab loads charts, Reservations tab loads
- [ ] **Billing page** — monthly history shows correct order counts and fees
- [ ] **Settings** — brand color change saves and reflects on frontend after cache purge
- [ ] **Brand Identity** — logo change saves and appears in admin sidebar
- [ ] **Dashboard** — KPI cards show non-zero data, revenue chart renders

#### Post-Deploy Verification Script
```bash
# Run on cPanel terminal after every deploy
curl -s -X POST https://dishdash.khanakhazana.rw/wp-admin/admin-ajax.php \
  -d "action=dd_cart_get" | grep -q "success" \
  && echo "AJAX ✅" || echo "AJAX ❌ BROKEN"

grep "DD_VERSION" /home/imitjsiy/dishdash.khanakhazana.rw/wp-content/plugins/dish-dash/dish-dash.php
```

---

## 📊 Audit Score History

| Version | Date | Mission | Architecture | Data | Performance | Security | AI Code | Regression | Overall |
|---|---|---|---|---|---|---|---|---|---|
| v3.4.8 | 2026-05-18 | 🟡 | 🟡 | 🟢 | 🟡 | 🟢 | — | — | 🟡 |
| v3.4.9 | 2026-05-18 | 🟡 | 🟢 | 🟢 | 🟢 | 🟢 | — | — | 🟢 |
| v3.5.42 | 2026-06-09 | 🟡 | 🟡 | 🟢 | 🟡 | 🟢 | ⚪ | ⚪ | 🟡 |

**Score key:** 🟢 No Medium+ findings | 🟡 Medium findings present | 🔴 Critical/High findings present | ⚪ Not yet run

---

## 🔧 Deferred Issues Backlog

Issues found during audits but intentionally deferred. Review before each new phase.

| # | Issue | Pillar | Found in | Severity | Target | Status |
|---|---|---|---|---|---|---|
| D1 | Auth events (login, register) not in tracking schema | P1 | v3.4.8 | 🟡 Medium | Phase 8 | 🔴 Open |
| D2 | Closed-state banner buttons ("Browse Menu", "Message Us") not tracked | P1 | v3.4.8 | 🔵 Low | Phase 3D polish | 🔴 Open |
| D3 | ~~`class-dd-api.php:358` calls `DD_Tracking_Module::get_session_id()` — inverted dependency~~ | P2 | v3.4.8 | 🔵 Low | — | ✅ Resolved v3.5.42 |
| D4 | 4 direct `wc_get_product()` calls in legacy module files (menu, orders, cart, tracking) | P2 | v3.4.8 | 🔵 Low | Migrate when touched | 🔴 Open |
| D5 | `dd_get_reviews` AJAX missing nonce | P5 | v3.4.8 | 🔵 Low | Next security sprint | 🔴 Open |
| D6 | Tracking profile queries uncached (fires per event) | P4 | v3.4.8 | 🟡 Medium | Phase 8 (scale concern) | 🔴 Open |
| D7 | `theme.css` at 83KB unminified | P4 | v3.4.8 | 🔵 Low | Phase 10 optimization | 🔴 Open |
| D8 | `frontend.js` at 65KB — consider splitting | P4 | v3.4.8 | 🔵 Low | Phase 8+ | 🔴 Open |
| D9 | `add_to_cart` not tracked from homepage product cards / modal — only fires from menu page | P1 | v3.5.42 | 🟡 Medium | Phase 8 pre-work | 🔴 Open |
| D10 | `dd_billing_payments` table uses `dd_` prefix instead of `dishdash_` — naming inconsistency | P3 | v3.5.42 | 🔵 Low | Fix when convenient | 🔴 Open |
| D11 | `dd_track_event` uses `check_ajax_referer(..., false)` — soft nonce, does not die on failure | P5 | v3.5.42 | 🔵 Low | Document or harden before Phase 8 | 🔴 Open |
| D12 | Total CSS ~163KB + JS ~157KB on menu page — over 150KB threshold | P4 | v3.5.42 | 🟡 Medium | Phase 10 optimization (LiteSpeed mitigates) | 🔴 Open |

---

## ✅ Resolved Issues Log

| Issue | Pillar | Found in | Fixed in | Fix summary |
|---|---|---|---|---|
| 405KB assets loading on every WP page | P4 | v3.4.8 | v3.4.9 | `is_dishdash_page()` guard in `enqueue_frontend_assets()` |
| N+1 query in `grid.php` menu render | P4 | v3.4.8 | v3.4.9 | Pre-fetch with `wc_get_products(['include' => $ids])` |
| Reservations writing to customers table directly | P2 | v3.4.8 | v3.4.9 | Replaced with `apply_filters('dd_resolve_customer_id')` |
| Dead tracking schemas: `reorder`, `deposit_failed` | P1 | v3.4.8 | v3.4.9 | Removed from `event-schemas.php`, version bumped to v1.1 |
| D3: `class-dd-api.php:358` inverted dependency on DD_Tracking_Module | P2 | v3.4.8 | v3.5.42 | Confirmed comment-only in audit scan — not live code, no code change needed |
| `esc_url()` on WhatsApp href strips `%0A` newlines | P6 | v3.4.51 | v3.4.51 | Switched to `esc_attr()` for all WhatsApp hrefs |
| `remove_submenu_page()` causing sidebar render issues | P6 | v3.5.13 | v3.5.13 | CSS-only hiding adopted as project standard |
| Stale `cart.min.js` + `reservations.min.js` shadowing source files | P2 | v3.5.28 | v3.5.28 | Deleted via `git rm` — no .min files in repo |

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
- [ ] Run Pillar 2 scan — zero cross-module violations in new code, no .min files, versions consistent
- [ ] Run Pillar 3 scan — all tables and columns match spec, no PII in error logs
- [ ] Run Pillar 4 scan — `is_dishdash_page()` guard present, no new N+1 patterns, file sizes checked
- [ ] Run Pillar 5 scan — all new AJAX handlers have nonce + capability, no unsafe SQL
- [ ] Run Pillar 6 scan — no unescaped output, no AI-pattern violations, no WhatsApp esc_url() misuse
- [ ] Run Pillar 7 checklist — all critical flows pass on mobile and desktop
- [ ] Check deferred backlog — any D-items ready to fix this phase?
- [ ] Update score history table above
- [ ] Update CLAUDE.md `Last updated` line and `Current phase` to reflect audit completion

---

*This document lives at `AUDIT.md` in the repo root alongside `CLAUDE.md`, `ARCHITECTURE.md`, and `TRACKING_ROADMAP.md`.*
*Refine after every audit. Never delete findings — only mark them resolved.*
