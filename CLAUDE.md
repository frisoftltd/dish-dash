# 🧠 Dish Dash — Session Context & Workflow

> **This file is the single source of truth for every AI coding session.**
> Read this ENTIRELY before doing any work.
>
> ⚠️ MANDATORY RULE: This file MUST be updated in the same commit as every
> version bump. The `Last updated` line must always match `DD_VERSION` in
> `dish-dash.php`. A release that ships code without updating this file
> is incomplete. No exceptions.
>
> Last updated: v3.5.26 (2026-06-08)

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
| **Deployed version** | v3.5.26 |
| **Current phase** | Phase 5D — Full Admin Redesign + Frontend Template System |
| **Current sub-phase** | Part 1 — Admin Pages Redesign (in progress) |
| **Next task** | v3.5.27 — TBD |
| **Last working state** | v3.5.26: reservation confirmation screen — "Notify Restaurant" button removed, ghost "Confirming..." button hidden on success (btn.style.display=none before showWhatsAppButtons at both call sites). |
| **GitHub** | github.com/frisoftltd/dish-dash |
| **Live site** | dishdash.khanakhazana.rw |
| **Server** | cPanel at server372.web-hosting.com (user: imitjsiy) |
| **Plugin path** | /home/imitjsiy/dishdash.khanakhazana.rw/wp-content/plugins/dish-dash/ |
| **Theme** | dish-dash-theme (custom blank theme — NOT Astra, NOT any other theme) |
| **Stack** | WordPress 6+, WooCommerce, PHP 8.2, vanilla JS (no jQuery, no build step), MySQL, LiteSpeed Cache |

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
| **Developer** (human) | GitHub releases, deployment, testing, feedback |

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
Developer commits → creates GitHub release → deploys → tests
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
3. **CLAUDE.md updated in the same commit** — `Last updated` line + Current State table
4. Developer creates GitHub release with tag `vX.X.X` (**WITH the `v` prefix** — without it, Actions will not build the zip)
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
- Update `Last updated` line and Current State table in CLAUDE.md
- `git add [all changed files] dish-dash.php CLAUDE.md`
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
- **Do NOT create release tags** — developer does that via GitHub UI

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
| **Phase 5D** | 🔄 | Full admin redesign + frontend template system ← CURRENT |
| **Phase 6** | ⏳ | Analytics + AI — Python microservice, behavior engine, recommendations |
| **Phase 7** | ⏳ | Loyalty & QR — points system, QR scan ordering |
| **Phase 8** | ⏳ | Testing + Optimization |
| **Phase 9** | ⏳ | SaaS Platform — multi-tenant, subscription billing, white-label |

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

| Release | What shipped |
|---|---|
| v3.4.16 | Removed all WP notification noise |
| v3.4.17 | Replaced WP logo with restaurant logo |
| v3.4.18 | Hidden irrelevant WP menus, removed DD submenus |
| v3.4.19 | Custom admin URL — `/wp-admin` → 404, recovery via email |

**Key implementation notes:**
- `admin_menu` (priority 999) strips update count bubbles from sidebar
- `remove_all_actions('admin_notices')` + `remove_all_actions('all_admin_notices')`
- Exception: `get_current_screen()->id === 'update-core'` — never suppress on Updates page
- `add_filter('woocommerce_helper_suppress_admin_notices', '__return_true')` for WC notices
- Custom path stored in `dd_admin_custom_path` wp_option, superadmin only

---

### Phase 5B — General Layout ✅ Complete

| Release | What shipped |
|---|---|
| v3.4.20 | Layout shell — sidebar (60px collapsed / 240px expanded), top bar (56px), content wrapper |
| v3.4.21 | Brand injection — restaurant logo + primary color as CSS variables |
| v3.4.22 | Branded login page — restaurant logo, primary color, zero WP styling |
| v3.4.23 | Global typography + card system — Inter font, spacing tokens |

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

| Release | What shipped |
|---|---|
| v3.4.24 | Brand Identity page — logo, color pickers, font, contact info, social media |
| v3.4.25 | Template page — card-based template library picker |

---

### Phase 5D — Full Admin Redesign + Frontend Template System 🔄 Current

#### Part 1 — Admin Pages Redesign

Every page before shipping must pass:
- ✅ No WP grey or WP blue anywhere
- ✅ Restaurant logo in sidebar
- ✅ Brand color on active states and CTAs (from `--dd-brand` — never hardcoded)
- ✅ Inter font loaded
- ✅ Spacious cards, 12px radius, soft shadows
- ✅ Dashboard content fills full width — no max-width cap killing right side

| Release | Status | What ships |
|---|---|---|
| v3.4.39–v3.4.43 | ✅ Done | Orders, Analytics, Customers, Reservations pages redesigned |
| v3.4.44 | ✅ Done | Dashboard — live KPIs, date filter, revenue chart, top items, customer tiers |
| v3.4.45 | ✅ Done | Fix dashboard Top Items column name and opening hours session keys |
| v3.4.46 | ✅ Done | Orders page redesign + remove global max-width constraint |
| v3.4.47 | ✅ Done | Orders status dropdown with POST handler, replaces dead GET links |
| v3.4.48 | ✅ Done | Friendly status names, kitchen WhatsApp notification, stale order warning |
| v3.4.49 | ✅ Done | 4-status order flow, button-based actions, rider management, customer on-the-way notification |
| v3.4.50 | ✅ Done | Clean WhatsApp message format, Mark Ready/Delivered locked until notified |
| v3.4.51 | ✅ Done | Fix WhatsApp line breaks (esc_url strips %0A), fix addon JSON in kitchen message |
| v3.4.52 | ✅ Done | Remove emoji from WhatsApp messages, fix variation JSON decode |
| v3.4.53 | ✅ Done | Fix variation JSON decode with stripslashes, strip stray braces from plain text |
| **v3.4.54** | ✅ **Done** | **Sort orders by urgency: Pending first, then Confirmed, Ready, Delivered, Cancelled** |
| **v3.4.55** | ✅ **Done** | **Fix revenue/AOV to use delivered orders, fix chart Y-axis integers, hide chart when empty** |
| **v3.4.56** | ✅ **Done** | **Fix order status forms: explicit action URL, nonce failure notice** |
| **v3.4.57** | ✅ **Done** | **Remove ghost admin-orders.css/js enqueues, remove debug logging** |
| **v3.4.58** | ✅ **Done** | **Order detail modal with AJAX status updates, remove form-based action buttons** |
| **v3.4.59** | ✅ **Done** | **Fix modal nonce: dish_dash_frontend for get_order, dish_dash_admin for update_status** |
| **v3.4.60** | ✅ **Done** | **Fix modal data rendering: unwrap double-nested AJAX response** |
| **v3.4.61** | ✅ **Done** | **Modal stays open on status change, Mark Ready lock fix, orders table full width** |
| **v3.4.62** | ✅ **Done** | **Add View button to order rows, fix WP right padding dead zone** |
| **v3.4.63** | ✅ **Done** | **Top Items all-time query, stale bulk deliver button, recent orders rows clickable** |
| **v3.4.64** | ✅ **Done** | **Test order flag (is_test), bulk status change, test orders excluded from all reports** |
| **v3.4.65** | ✅ **Done** | **Fix View button: type="button" + stopPropagation prevents modal disappearing** |
| **v3.4.66** | ✅ **Done** | **Fix View button calling openModal() directly with order ID** |
| **v3.4.67** | ✅ **Done** | **Expose openModal to global scope for View button inline onclick** |
| **v3.4.68** | ✅ **Done** | **Fix Pending Orders KPI to include confirmed and ready statuses** |
| **v3.4.69** | ✅ **Done** | **Fix modal re-render using local state instead of re-fetching** |
| **v3.4.70** | ✅ **Done** | **Rename Pending Orders KPI to Active Orders** |
| **v3.4.71** | ✅ **Done** | **Real-time notifications — opt-in banner, 30s polling, browser alerts, sidebar badge** |
| **v3.4.72** | ✅ **Done** | **Clean admin bar, bell notification icon with dropdown panel** |
| **v3.4.73** | ✅ **Done** | **Bell panel polish, click notification opens order modal, mark as read** |
| **v3.4.74** | ✅ **Done** | **Fix bell panel HTML rendering, notification click opens order modal** |
| **v3.4.75** | ✅ **Done** | **Remove stale banner, need-action badge link, server-side notification read persistence** |
| **v3.4.76** | ✅ **Done** | **Add status timestamp columns, kitchen prep time setting, record timestamps on status change** |
| **v3.4.77** | ✅ **Done** | **Bell pending-only, kitchen queue with live timer and Mark Ready, dd_kitchen_queue endpoint** |
| **v3.4.78** | ✅ **Done** | **Bell dedup — merge approach, remove accepted orders, badge from DOM state; remove need-action badge** |
| **v3.4.79** | ✅ **Done** | **Beep only on genuinely new bell items, not every poll cycle** |
| **v3.4.80** | ✅ **Done** | **Mark Ready navigates to order modal instead of silent AJAX change** |
| **v3.4.81** | ✅ **Done** | **Fix waUrls missing for cross-page modal opens, fix Mark Ready disabled when no kitchen phone** |
| **v3.4.82** | ✅ **Done** | **Reopen cancelled/delivered orders, modal stays open on terminal status, reverse transitions** |
| **v3.4.83** | ✅ **Done** | **Orders pagination — 25/50/75/All per-page, page number nav, "Showing X–Y of Z" info** |
| **v3.4.84** | ✅ **Done** | **Fix division by zero and undefined per_page on orders pagination** |
| **v3.4.85** | ✅ **Done** | **Remove Menu Items from admin sidebar** |
| **v3.4.86** | ✅ **Done** | **Remove Delivery, Branches, POS Terminal from admin sidebar** |
| **v3.4.87** | ✅ **Done** | **Analytics pages — Orders Analytics, Reservations Analytics, AI Insights engine (DD_Insights), Chart.js charts, speed metrics, customer breakdown** |
| **v3.4.88** | ✅ **Done** | **Fix oi.price DB error (line_total), insights horizontal scroll, remove orders tab, reservations KPI padding** |
| **v3.4.89** | ✅ **Done** | **Analytics padding overhaul — consistent 24px card spacing, KPI sizing, chart wrap, speed section, hbars, two-column gaps** |
| **v3.4.90** | ✅ **Done** | **Analytics merged into single page — Orders + Reservations tabs, sidebar sub-item removed, both JS loaded on one page, max-width removed** |
| **v3.4.91** | ✅ **Done** | **Settings page redesign (card layout, CSS grid); Pricing & Fees section (flat fee, minimum order advisory, payment method toggles, platform fee stamped on every order)** |
| **v3.4.92** | ✅ **Done** | **Installer consolidation: single canonical install.php (13 tables), class-name conflict resolved (DD_Schema_Upgrader), auto-migration guard in dish-dash.php, no more manual WP-CLI for schema updates** |
| **v3.4.93** | ✅ **Done** | **Billing page (💳 sidebar between Analytics and Settings, monthly history, status breakdown, all-time fees) + Fees This Month KPI card on dashboard** |
| **v3.4.94** | ✅ **Done** | **Fee enable/disable toggle in Settings, dashboard card fixed (dd-kpi-meta layout), Analytics fees KPI card, Billing disabled notice** |
| **v3.4.95** | ✅ **Done** | **Billing fixes: Monthly History 3-column (no Fee Per Order), Status Breakdown delivered+cancelled only, dashboard fees card layout fix (no RWF prefix in value)** |
| **v3.4.96** | ✅ **Done** | **Dashboard: fees KPI card replaced with inline line below revenue chart** |
| **v3.4.97** | ✅ **Done** | **Phase C — deleted dishdash-core/class-dd-install.php (deprecated DD_Schema_Upgrader), updated stale comments** |
| **v3.4.98** | ✅ **Done** | **Phase D — fee reversal on cancel/revert: recalculate_fee_for_status_change() helper wired into all 6 status-change sites** |
| **v3.4.99** | ✅ **Done** | **Orders page: recent-first sort, live search (order number/name/phone), date range filter, payment method filter** |
| **v3.5.00** | ✅ **Done** | **Orders search fix: order number only (removes name/phone false matches), debounce 350ms → 800ms** |
| **v3.5.01** | ✅ **Done** | **Order reopen 24h expiry — Reopen as Ready hidden after 24h, replaced with locked message** |
| **v3.5.02** | ✅ **Done** | **Billing paid/unpaid tracking: new dd_billing_payments table, Mark as Paid button on monthly history, dashboard fees line shows paid/unpaid badge** |
| **v3.5.03** | ✅ **Done** | **Customers page redesign: new header pattern, dd-kpi-grid--4 KPI cards, dd-card wrappers for tiers/filters/table, dead dd-cust-kpi-* CSS removed, hardcoded hex fixed** |
| **v3.5.04** | ✅ **Done** | **Customers page full width, per-page selector 25/50/75/All, pagination carries per_page param, inline count removed** |
| **v3.5.05** | ✅ **Done** | **Customers page: Orders/Reservations tabs, tab-filtered rows, paginate_links carries tab param** |
| **v3.5.06** | ✅ **Done** | **Customers — per-tab KPI cards, hide tiers on reservations tab** |
| **v3.5.07** | ✅ **Done** | **Fix: restore tier counts to orders-tab stats query (tier_new/regular/vip/champion/diamond)** |
| **v3.5.08** | ✅ **Done** | **Customers — date range filter today/7d/30d/90d/custom, KPIs range-aware, filter preserves tab+per_page** |
| **v3.5.09** | ✅ **Done** | **Fix: default range=All, orders tab filters on last_order_at, no prepare() with empty params** |
| **v3.5.10** | ✅ **Done** | **Fix: skip prepare() when no date params — conditional pattern on all 3 stats queries** |
| **v3.5.11** | ✅ **Done** | **Reservations admin redesign: DD design system, KPI cards, pill tabs, AJAX status updates, badge statuses, toast** |
| **v3.5.12** | ✅ **Done** | **Tab consolidation: Tables + Sections into page tabs, date range pills, per-page pills, action button refinement** |
| **v3.5.13** | ✅ **Done** | **Fix: CSS-only sidebar hiding replaces remove_submenu_page(), dashicons enqueued on Tables + Sections** |
| **v3.5.14** | ✅ **Done** | **Fix: add dashicons enqueue to reservations admin page** |
| **v3.5.15** | ✅ **Done** | **Fix: dashicons color #fff on dark reservation header** |
| **v3.5.16** | ✅ **Done** | **Fix: add 📅 emoji icon to Reservations sidebar menu item** |
| **v3.5.17** | ✅ **Done** | **Reservations table: remove Time/Session columns, explicit widths, overflow fix, responsive KPI font size** |
| **v3.5.18** | ✅ **Done** | **Fix: reservation notification slug, beep on new reservations, badge persistence on reload** |
| **v3.5.19** | ✅ **Done** | **Fix: persist notification panel items across page reloads (localStorage + panel rebuild)** |
| **v3.5.20** | ✅ **Done** | **Feat: reservation notification deep link + mark as read on click** |
| **v3.5.21** | ✅ **Done** | **Feat: focused single-reservation view when opening from notification** |
| **v3.5.22** | ✅ **Done** | **Bulk actions + test flag for reservations: is_test column + migration guard, checkbox column, bulk bar, Test tab, test badge, KPI queries exclude test rows** |
| **v3.5.23** | ✅ **Done** | **Fix: nonce field name in ajax_bulk_action — check_ajax_referer missing 'nonce' arg caused all bulk actions to return 403** |
| **v3.5.24** | ✅ **Done** | **Fix: exclude test reservations from all 21 analytics queries in reservations tab — AND is_test=0 added to every {$rt} query** |
| **v3.5.25** | ✅ **Done** | **Remove "Notify Restaurant" WhatsApp button from customer booking confirmation; remove auto-open WhatsApp from order confirmation** |
| **v3.5.26** | ✅ **Done** | **Fix: hide ghost "Confirming..." button + clean reservation confirmation screen (only ✅ message + Close)** |
| v3.5.27 | ⏳ **NEXT** | TBD |

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
| Additional templates | ⏳ Post-MVP |

---

## 🗄 Key Database Tables

| Table | Key Columns |
|---|---|
| `wp_dishdash_orders` | id, wc_order_id, customer_name, customer_phone, total, status, payment_status, payment_method, order_type, created_at |
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

---

## 📝 Session History

| Date | Versions | What was accomplished |
|---|---|---|
| 2026-04-13/14 | v3.1.9 → v3.1.13 | Menu page fixes, GitHub Actions restored, `main` branch discipline |
| 2026-04-14 | docs only | Architecture docs (5 files), file headers (56 files) |
| 2026-04-14/16 | v3.1.14 → v3.1.17 | DD_API, schema versioning, validation, health check |
| 2026-04-20/21 | v3.2.5 → v3.2.12 | Mobile 3-screen UI complete, cart AJAX wired |
| 2026-05-20 | v3.4.15 | Phase 5 full plan written, admin transformation design system established |
| 2026-05-20/24 | v3.4.16 → v3.4.43 | Phase 5A/5B/5C complete. Orders, Analytics, Customers, Reservations redesigned. |
| **NEXT** | **v3.4.44** | **Dashboard full redesign — live KPIs, chart, top items, customer tiers** |
