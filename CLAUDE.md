# 🧠 Dish Dash — Session Context & Workflow

> **This file is the single source of truth for every AI coding session.**
> Read this ENTIRELY before doing any work. Updated after every release.
>
> Last updated: v3.4.15 (2026-05-20)

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

## 📌 Current State

| Field | Value |
|---|---|
| **Deployed version** | v3.4.15 |
| **Current phase** | Phase 5 — Backend Dashboard & Admin Transformation |
| **Current sub-phase** | 5A — Clean & Secure (starting now) |
| **Next task** | v3.4.16 — Remove WP notification noise |
| **Last working state** | Orders live (40 orders). Customers page live (11 customers). Reservations live (50 bookings). Tables + Seating Sections live. Auth & Login configured. Homepage + Template settings working. Dashboard KPIs unwired (placeholder HTML shells). |
| **GitHub** | github.com/frisoftltd/dish-dash |
| **Live site** | dishdash.khanakhazana.rw |
| **Server** | cPanel at server372.web-hosting.com (user: imitjsiy) |
| **Plugin path** | /home/imitjsiy/dishdash.khanakhazana.rw/wp-content/plugins/dish-dash/ |
| **Theme** | dish-dash-theme (custom theme, NOT Astra) |
| **WooCommerce** | Active — products used as menu items |

---

## 🎨 Brand Colors

Brand colors are dynamic — set by each restaurant in Dish Dash → Brand Identity
and stored in wp_options. All code must read colors from wp_options or CSS
variables — never hardcode hex values in templates or CSS.

| Option Key | Description |
|---|---|
| dish_dash_primary_color | Header, buttons, active states, CTAs |
| dish_dash_dark_color | Secondary elements, text accents |
| dish_dash_background_color | Page background |
| dish_dash_font | Typography (Inter default) |

**Rule:** Never hardcode hex color values anywhere in the codebase.
Always read from get_option('dish_dash_primary_color') in PHP or
from CSS variables in stylesheets. Each restaurant has its own
colors — what Khana Khazana uses is just one configuration.

---

## 🔄 Workflow — How We Work

### Roles
| Role | Responsibilities |
|---|---|
| **Claude** (claude.ai) | Planning, design, review, release notes |
| **Claude Code** (terminal) | Investigation, coding, git commits, push |
| **Developer** (human) | GitHub releases, deployment, testing, feedback |

### The Loop
```
Claude writes brief with investigation + coding instructions
Developer pastes brief to Claude Code
Claude Code investigates → reports findings → Developer pastes to Claude
Claude reviews findings → approves or corrects
Claude Code codes → reports complete files → Developer pastes to Claude
Claude reviews code report → gives release creation instructions
Developer: commits to GitHub, creates release, deploys, tests
Developer: screenshots/feedback to Claude
Claude writes next brief → loop repeats
```

### Claude Code Session Setup

Every Claude Code session MUST start with:
> Read `CLAUDE.md` from the repo root at github.com/frisoftltd/dish-dash before doing any work. This file contains the full project context, rules, architecture, and current state.

### Release Process

1. All files committed to `main` branch (lowercase, NEVER `Main`)
2. Version bumped in `dish-dash.php` in BOTH locations:
   - `* Version: X.X.X` in the header comment
   - `define('DD_VERSION', 'X.X.X');` constant
3. Developer creates GitHub release with tag `vX.X.X` (WITH the `v` prefix)
4. GitHub Actions builds `dish-dash.zip` automatically (~30 seconds)
5. Deploy via ONE of two methods:

**Method A — cPanel Terminal (when WP updater times out):**
```bash
cd /tmp && wget https://github.com/frisoftltd/dish-dash/releases/latest/download/dish-dash.zip && unzip -o dish-dash.zip -d /tmp/dd-update && cp -r /tmp/dd-update/dish-dash/* /home/imitjsiy/dishdash.khanakhazana.rw/wp-content/plugins/dish-dash/ && rm -rf /tmp/dd-update /tmp/dish-dash.zip && echo "Done!"
```

**Method B — WordPress auto-update:**
WP Admin → Plugins → Check for Updates → Update Now

6. Purge LiteSpeed Cache: WP Admin → LiteSpeed Cache → Toolbox → Purge All
7. Test in incognito window
8. Verify: `grep "DD_VERSION" /home/imitjsiy/.../dish-dash.php`

---

## 🚨 Rules — NEVER Break These

### Code Rules

- **Always provide complete files** — never partial snippets
- **Always include exact GitHub path** for every file
- **Always state CREATE new or EDIT existing** for each file
- **Never change code outside the scope of the current task** — scope creep causes regressions
- **Always check current file state before editing** — read the file first
- **Investigation findings BEFORE writing code** — always diagnose, then fix
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

### Data Access Rules (as of v3.1.15)

- **All NEW code** must use `DD_API::` for data access (not direct `wc_get_product()` or `$wpdb` calls)
- Existing code keeps working as-is — migrate gradually when files are touched
- `DD_API` returns normalized arrays, NOT WC_Product objects
- `DD_API` has built-in transient caching (5-min TTL, auto-invalidated on product save)

### Tracking Rules (as of v3.1.16)

- **No feature without tracking** — every user action must be recorded
- Any new tracking events MUST be added to `modules/tracking/event-schemas.php` FIRST
- `meta` JSON field contains ONLY metadata — dedicated DB columns (`product_id`, `category_id`) are NOT listed in schema
- Validation mode is currently `'warn'` — events are logged but not rejected
- Event Health Check: WP Admin → Dish Dash → Tools → should show 0% failures

---

## 🏗 File Structure
```
dish-dash/
├── .github/workflows/release.yml      ← GitHub Actions zip builder
├── admin/
│   ├── pages/ (dashboard, orders, settings, coming-soon, event-health)
│   └── class-dd-admin.php
├── assets/
│   ├── css/ (admin, cart, menu, theme, frontend, menu-page)
│   └── js/ (admin, cart, menu, frontend, search, tracking, menu-page)
├── dishdash-core/
│   ├── class-dd-ajax.php
│   ├── class-dd-api.php               ← Normalized data facade (12 methods)
│   ├── class-dd-github-updater.php
│   ├── class-dd-helpers.php
│   ├── class-dd-hooks.php
│   ├── class-dd-loader.php
│   ├── class-dd-module.php
│   ├── class-dd-settings.php
│   └── class-dd-install.php
├── modules/
│   ├── menu/class-dd-menu-module.php
│   ├── orders/ (class-dd-orders-module.php, class-dd-cart.php)
│   ├── template/class-dd-template-module.php
│   └── tracking/ (class-dd-tracking-module.php, event-schemas.php)
├── templates/
│   ├── cart/cart.php
│   ├── checkout/checkout.php
│   ├── menu/grid.php                   ← Menu page content (shortcode)
│   ├── partials/product-card.php
│   └── page-dishdash.php               ← Homepage template
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
├── CLAUDE.md                           ← THIS FILE
└── dish-dash.php                       ← Main plugin file
```

---

## 🗺 URL → Template Mapping

| URL | Template | Wrapper | Primary CSS | Primary JS |
|---|---|---|---|---|
| `/` (homepage) | `templates/page-dishdash.php` (via `template_include` filter) | `.dd-page` | `theme.css` | `frontend.js` |
| `/restaurant-menu/` | WP page → `theme/page.php` → `[dd_menu]` shortcode → `templates/menu/grid.php` | `.dd-menu-page.dd-menu-page--desktop` | `menu-page.css` | `menu-page.js` |
| `/cart/` | `templates/cart/cart.php` | — | `cart.css` | `cart.js` |
| `/checkout/` | `templates/checkout/checkout.php` | — | — | — |

---

## 📆 Development Phases

| Phase | Status | Description |
|---|---|---|
| **Phase 1** | ✅ | Foundation (plugin, GitHub updater, WooCommerce integration) |
| **Phase 2** | ✅ | Template System (header, hero, footer, branding, mobile 3-screen menu) |
| **Phase 3** | ✅ | Cart, Orders, Delivery & WhatsApp notifications |
| **Phase 4** | ✅ | Reservations (table booking, notifications, tables, seating sections) |
| **Phase 5** | 🔄 | Backend Dashboard & Admin Transformation ← CURRENT |
| **Phase 6** | ⏳ | Analytics + AI (Python microservice, behavior engine, recommendations) |
| **Phase 7** | ⏳ | Loyalty & QR (points system, QR scan ordering) |
| **Phase 8** | ⏳ | Testing + Optimization |
| **Phase 9** | ⏳ | SaaS Platform (multi-tenant, subscription billing, white-label) |

---

## 🖥 Phase 5 — Backend Dashboard & Admin Transformation

### Vision

The WordPress admin must be completely transformed into a professional SaaS product. Each restaurant feels like they own the system — not a generic WordPress install. The structure is universal. Colors, logo, and fonts come from each restaurant's Brand Identity settings.

**What must NEVER appear in the admin:**
- WordPress logo anywhere
- WordPress blue (`#2271b1`) or default WP grey
- Plugin update badges or notification banners anywhere except the Updates page
- Any hint this is built on WordPress

---

### Dish Dash Admin Sidebar — Final Menu (in order)

| # | Item | Status |
|---|---|---|
| 1 | 📊 Dashboard | ✅ Keep |
| 2 | 🧾 Orders | ✅ Keep |
| 3 | 📈 Analytics | ✅ Keep |
| 4 | 👥 Customers | ✅ Keep |
| 5 | 📅 Reservations | ✅ Keep |
| 6 | 🪑 Tables | ✅ Keep |
| 7 | 🪟 Seating Sections | ✅ Keep |
| 8 | ⚙️ Settings | ✅ Keep |
| 9 | 🎨 Brand Identity | ✅ Keep (new page — replaces Template branding fields) |
| 10 | 🖼 Template | ✅ Keep (redesigned — template library picker) |
| 11 | 🏠 Homepage | ✅ Keep |
| 12 | 🔐 Auth & Login | ✅ Keep |
| 13 | 🔧 Tools | ✅ Keep |

**Removed from Dish Dash menu:** Menu Items, Delivery, Branches, POS Terminal

**WordPress native menus visible to restaurant owner:** Media, Pages, Users only. Everything else hidden.

---

### Phase 5A — Clean & Secure

| Release | What ships |
|---|---|
| **v3.4.16** | Remove all WP notification noise — badges, banners everywhere except Dashboard → Updates page |
| **v3.4.17** | Replace WP logo with restaurant logo — admin bar top-left + login page (uses `dish_dash_logo_url`) |
| **v3.4.18** | Hide irrelevant WP menus + remove Dish Dash submenus (Menu Items, Delivery, Branches, POS Terminal) |
| **v3.4.19** | Custom admin URL per restaurant — set in Settings by superadmin only. `/wp-admin` → 404 for non-superadmin. Recovery via email if forgotten. |

**v3.4.16 implementation notes:**
- Hook into `admin_menu` (priority 999) to strip update count `<span>` bubbles from all sidebar items
- Hook into `admin_head` to `remove_all_actions('admin_notices')` and `remove_all_actions('all_admin_notices')`
- Exception: `get_current_screen()->id === 'update-core'` — never suppress on the actual Updates page
- Add `add_filter('woocommerce_helper_suppress_admin_notices', '__return_true')` for WC notices

**v3.4.19 custom URL rules:**
- Only users with `manage_options` + a superadmin flag can set the custom path
- Custom path stored in `dd_admin_custom_path` wp_option
- `/wp-admin` returns 404 for all non-superadmin users once custom path is set
- Recovery: email sent to admin email with their custom URL link

---

### Phase 5B — General Layout

Build the layout shell first. Every page inherits it. Get this right before touching any individual page.

| Release | What ships |
|---|---|
| **v3.4.20** | Layout shell — collapsed icon sidebar (60px), expanded (240px), top bar (56px), content area wrapper |
| **v3.4.21** | Brand injection — restaurant logo + primary color injected as CSS variables from `dish_dash_logo_url` + `dish_dash_primary_color` |
| **v3.4.22** | Branded login page — restaurant logo, primary color, clean form. Zero WP styling. |
| **v3.4.23** | Global typography + card system — Inter font, spacing tokens, shadow system, zero WP grey anywhere |

**Layout shell specs:**

*Sidebar collapsed (default):*
- Width: 60px
- Background: `#1a1a1a` (fixed — same all restaurants)
- Icon only, centered, 48px hit area
- Active: 3px left border + icon in `var(--dd-brand)`
- Hover: `rgba(255,255,255,0.06)` background
- Restaurant logo: 36×36px square, centered at top

*Sidebar expanded (hover/click):*
- Width: 240px, transition 200ms ease
- Full logo left-aligned, max-height 36px
- Restaurant name: 11px uppercase, `rgba(255,255,255,0.4)`
- Icon (20px) + label (14px Inter medium) side by side
- Inactive: icon `rgba(255,255,255,0.5)`, label `rgba(255,255,255,0.7)`
- Active: icon + label in `var(--dd-brand)`
- Bottom: logged-in user name + avatar + logout icon

*Top bar:*
- Height: 56px, background `#ffffff`, border-bottom `1px solid #eeeeee`
- Left: page title (20px Inter semibold `#111111`)
- Right: notification bell + admin avatar (initials) + restaurant name (13px `#888888`)
- No WordPress toolbar. Completely replaces WP admin bar.

*Content area:*
- Background: `#f8f8f8`, padding 32px, max-width 1200px centered

*Card component:*
- Background `#ffffff`, border-radius 12px, box-shadow `0 1px 4px rgba(0,0,0,0.06)`, padding 24px

*CSS variables (set at `<body>` by PHP):*
```css
--dd-brand: [restaurant primary color];
--dd-brand-light: [10% opacity];
--dd-brand-dark: [darkened 15%];
```

*Typography:*
- Font: Inter (Google Fonts)
- Page title: 20px 600 `#111111`
- Section title: 16px 600 `#111111`
- Body: 14px 400 `#444444`
- Label: 12px 500 `#888888` uppercase letter-spacing 0.5px
- KPI numbers: 28–32px 700 `#111111`

---

### Phase 5C — New Pages Structure

| Release | What ships |
|---|---|
| **v3.4.24** | Brand Identity page — logo upload, color pickers, font selector, restaurant name, contact info, social media. Content moved from Template Settings. |
| **v3.4.25** | Template page redesigned — shows Khana Khazana as default template card, placeholder slots for future templates (post-MVP) |

**Brand Identity page fields:**
- Restaurant Name
- Logo (upload)
- Primary Color (color picker)
- Dark Color (color picker)
- Font selection (Inter default — expandable post-MVP)
- Address, Phone, Email
- Facebook, Instagram, WhatsApp, TikTok URLs

**Template page vision:**
- Card-based UI — each template is a visual card with preview thumbnail
- Khana Khazana = default, marked as active
- Future templates: different layouts AND styles (post-MVP)
- Restaurant picks template → their Brand Identity colors/logo inject automatically

---

### Phase 5D — Full Admin Redesign + Frontend Template System

Phase 5D has two parts:
- Part 1: Every admin page redesigned to match the DishDash design system
- Part 2: Frontend page template system — proper WordPress templates, clean URLs, no WooCommerce junk pages

---

#### Part 1 — Admin Pages Redesign

Every page checklist before shipping:
✅ No WP grey or WP blue anywhere
✅ Restaurant logo showing in sidebar
✅ Brand color applied to active states and CTAs
✅ Inter font loaded
✅ Spacious cards, 12px radius, soft shadows
✅ Works for both owner (glance) and manager (daily use)

| Release | What ships |
|---|---|
| **v3.4.39** | Dashboard — Live KPIs, date filter (Today/7d/30d/All), revenue chart |
| **v3.4.40** | Orders page — redesigned list, live status badges, action buttons |
| **v3.4.41** | Analytics page — Top Products widget, Peak Hours heatmap, Customer Value widget |
| **v3.4.42** | Customers page — redesigned table, tier badges (New/Regular/VIP/Champion/Diamond), spend data |
| **v3.4.43** | Reservations page — redesigned booking list, session/date filters, confirm/cancel/no-show actions |
| **v3.4.44** | Tables + Seating Sections — clean management UI |
| **v3.4.45** | Settings page — grouped sections, clean inputs, no WP form styling |
| **v3.4.46** | Homepage + Auth & Login + Tools — remaining admin pages |

---

#### Part 2 — Frontend Template System

**Architecture:**
- All DishDash pages registered as proper WordPress page templates
- Restaurant owner selects template from Gutenberg Page Attributes → Template dropdown
- Template files live in `templates/themes/{active-template}/` folder
- Active template controlled by `dd_active_template` wp_option (default: `khana-khazana`)
- Switching template = one click, instant site redesign (SaaS-ready)

**Folder structure:**

```
templates/
└── themes/
    └── khana-khazana/       ← default template
        ├── home.php
        ├── menu.php
        ├── cart.php
        └── checkout.php
```

**Page template registry:**
Each DishDash page that needs a frontend template gets registered
via the `theme_page_templates` filter. The list of pages and their
templates is decided at implementation time (v3.4.48) based on
what pages exist and what the restaurant needs. No pages are
deleted or redirected until v3.4.49 — decisions made then.

---

### Phase 5E — Template Library (Post-MVP)

| Item | Status |
|---|---|
| Khana Khazana template | ✅ Default — already built |
| Additional templates (new layouts + styles) | ⏳ Post-MVP |

---

### Phase 5 Design Brief (for Claude Design)

**System:** White-label restaurant management system. Multiple restaurants, each feels like they own it. Structure universal. Colors, logo, fonts from restaurant Brand Identity settings.

**Never visible:** WP logo, WP blue (`#2271b1`), WP grey, plugin badges, any hint it's WordPress.

**Navigation:** Collapsed left sidebar — icons only (60px). Expands on hover to 240px. Restaurant logo at top. Sidebar background `#1a1a1a` fixed. Active/hover in brand color.

**Content:** `#f8f8f8` background, 1200px max-width, white cards 12px radius, Inter font, spacious whitespace.

**Brand:** Logo + primary color pulled automatically from Brand Identity settings. CSS variables on `<body>`.

**Tone:** Professional. Trustworthy. Premium SaaS. Not playful. Not generic. Not corporate-cold.

**Pages to design:** Dashboard, Orders, Analytics, Customers, Reservations, Tables, Seating Sections, Settings, Brand Identity, Template, Homepage, Auth & Login, Tools, Login Page.

**Login page:** Restaurant logo (from Brand Identity), primary color button, clean centered card on `#f8f8f8`. No WP logo. No "Powered by WordPress."

**Mockup placeholder brand:** `#65040d` red, `#C9A24A` gold — Khana Khazana.

---

## 🧠 AI Core Systems (Build in Phase 6)

### 1. Behavior Tracking Engine ✅ (ALREADY LIVE)
- Table: `wp_dishdash_user_events`
- Fields: id, user_id, session_id, event_type, product_id, category_id, meta (JSON), schema_version, created_at
- Events: view_product, view_category, search, add_to_cart, page_view
- Validation: runtime schema enforcement (0% failure rate as of v3.1.17)

### 2. User Profile Engine (Phase 6)
- Table: `wp_dishdash_user_profiles` (already exists in DB)

### 3. AI Rules Engine (Phase 6)
- Simple IF/THEN rules first — no ML yet
- Module: `modules/ai/class-dd-ai-module.php`

### 4. Smart Nudges System (Phase 6)
- Module: `modules/nudges/class-dd-nudges-module.php`

---

## 📊 Tracking Status

| Event | Source | Status |
|---|---|---|
| `view_product` | tracking.js | ✅ Live |
| `view_category` | tracking.js + menu-page.js | ✅ Live |
| `search` | tracking.js | ✅ Live |
| `add_to_cart` | tracking.js | ✅ Live |
| `page_view` | tracking.js | ✅ Live |
| `order` | DDTrack.order() | ✅ Schema defined |
| `reorder` | PHP only | ✅ Schema defined |

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

---

## 🎯 wp_options Keys Reference

**Template/Brand:**
`dish_dash_restaurant_name`, `dish_dash_logo_url`, `dish_dash_primary_color` (`#65040d`), `dish_dash_dark_color` (`#000000`), `dish_dash_hero_title`, `dish_dash_hero_subtitle`, `dish_dash_hero_image`, `dish_dash_address`, `dish_dash_phone`, `dish_dash_contact_email`, `dish_dash_opening_hours`, `dish_dash_facebook`, `dish_dash_instagram`, `dish_dash_whatsapp`, `dish_dash_tiktok`

**Delivery:**
`dd_free_delivery_threshold` (10000), `dd_delivery_fee` (1500), `dd_delivery_eta`

**WhatsApp:**
`dd_whatsapp_admin`

**Hours:**
`dd_opening_hours`, `dd_closing_soon_minutes` (30), `dd_timezone` (Africa/Kigali)

**Admin (Phase 5):**
`dd_admin_custom_path` — custom admin URL path, set by superadmin only

---

## 🏁 Final Product Vision

**DishDash = The only locally-built, intelligent, commission-free ordering platform in East Africa.**

- Customers order faster → repeat usage
- Restaurants get more repeat orders → behavior-driven personalization
- Average order value increases → smart nudges + suggestions
- Each restaurant owns their experience → white-label brand identity

**Long-term architecture:** PHP/WordPress frontend (Phases 1-5) → Hybrid PHP + Python microservice (Phase 6+).

---

## 📋 Related Documentation

| Document | Purpose |
|---|---|
| `ARCHITECTURE.md` | URL → file mapping, module dependency graph |
| `CSS_REGISTRY.md` | Every `dd-` CSS class: where defined, where used |
| `MODULE_CONTRACT.md` | Module isolation rules, hooks registered/fired |
| `TRACKING_ROADMAP.md` | 4-release tracking expansion plan |
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
| 2026-05-20 | planning | Phase 5 full plan: admin transformation, design system, layout brief written |
| **NEXT** | **v3.4.16** | **Phase 5A begins: Remove WP notification noise** |

---

## ⚡ Claude Code Operating Rules

0. **Every brief that changes code MUST end with a version bump + commit + push.**
   No exceptions. The sequence is always:
   - Bump `* Version: X.X.X` in dish-dash.php header
   - Bump `define( 'DD_VERSION', 'X.X.X' );` in dish-dash.php
   - `git add [all changed files] dish-dash.php`
   - `git commit -m "release: vX.X.X — [description]"`
   - `git push origin HEAD:main`
   Never commit changed files without dish-dash.php. Never push without the version bumped.
1. Always start in Plan Mode: `claude --permission-mode plan`
2. Analyze first, never edit without approval
3. Use @mentions for exact files — never read whole codebase
4. Run /compact between tasks
5. After every task: git add + commit + push origin HEAD:main
6. Be concise — root cause, files changed, test steps only
7. **NEVER run git add, commit, or push without explicit instruction from the developer**
