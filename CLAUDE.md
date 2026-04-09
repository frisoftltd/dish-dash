# CLAUDE.md — Dish Dash WordPress Plugin
## Complete Project Reference for Claude Code

---

## 🏢 PROJECT IDENTITY

| Property | Value |
|---|---|
| Plugin Name | Dish Dash |
| Company | Fri Soft Ltd |
| GitHub | github.com/frisoftltd/dish-dash (Public) |
| Live Demo | dishdash.khanakhazana.rw |
| Current Version | 2.5.x |
| WordPress | 6.0+ required |
| PHP | 8.0+ required |
| WooCommerce | 7.0+ required |
| License | GPL-2.0+ |
| Theme | Dish Dash Theme (bundled blank theme, auto-installs) |

---

## 🎯 CORE GOAL

One plugin — everything built in — no bloat — fast loading.

Dish Dash controls its own header, hero, footer, cart, checkout, orders, and all restaurant features WITHOUT requiring any other plugins or theme customization. It is designed to be installed on any WordPress site and turn it into a fully functional food ordering platform in minutes.

**Business Model:** Install on unlimited restaurant websites. Each restaurant customizes branding via Template Settings (logo, colors, hero, contact). Plugin auto-updates via GitHub Releases — one push updates all installed sites. Future: SaaS licensing per restaurant site.

---

## 📁 COMPLETE FILE STRUCTURE

```
dish-dash/
├── .github/
│   └── workflows/release.yml          ← Auto-builds zip on vX.X.X tag
├── admin/
│   ├── pages/                         ← Admin page HTML templates
│   │   ├── dashboard.php
│   │   ├── orders.php
│   │   ├── settings.php
│   │   └── coming-soon.php
│   └── class-dd-admin.php             ← Core admin menus ONLY
├── api/
│   └── class-dd-rest-api.php          ← REST API endpoints
├── assets/
│   ├── css/
│   │   ├── admin.css                  ← Admin panel styles
│   │   ├── cart.css                   ← Cart sidebar styles
│   │   ├── menu.css                   ← Menu display styles
│   │   ├── theme.css                  ← Full page template styles ← MAIN CSS
│   │   └── frontend.css               ← Additional frontend styles
│   └── js/
│       ├── admin.js                   ← Admin panel JS
│       ├── cart.js                    ← Cart AJAX and interactions
│       ├── menu.js                    ← Menu filter and display
│       └── frontend.js                ← Full page template JS ← MAIN JS
├── dishdash-core/
│   ├── class-dd-ajax.php              ← All AJAX handlers
│   ├── class-dd-github-updater.php    ← Auto-update system
│   ├── class-dd-helpers.php           ← Shared helper functions
│   ├── class-dd-hooks.php             ← Hook registration
│   ├── class-dd-loader.php            ← Registers all modules
│   ├── class-dd-module.php            ← Abstract base class for all modules
│   └── class-dd-settings.php         ← Settings API
├── modules/
│   ├── menu/
│   │   └── class-dd-menu-module.php   ← Shortcodes ONLY, no assets
│   ├── orders/
│   │   ├── class-dd-orders-module.php ← Order placement, status, emails
│   │   └── class-dd-cart.php          ← Cart state management
│   └── template/
│       └── class-dd-template-module.php ← Header, hero, footer, page template
├── templates/
│   ├── cart/cart.php                  ← Cart template
│   ├── checkout/checkout.php          ← Checkout template
│   ├── menu/grid.php                  ← Menu grid template
│   └── page-dishdash.php             ← Full page template ← MAIN TEMPLATE
└── dish-dash.php                      ← Main plugin file (version here)
```

---

## 🏗️ ARCHITECTURE RULES — NEVER BREAK THESE

### Module Independence
Every module MUST be completely independent:
- Own folder: `modules/feature/`
- Own class: `class-dd-feature-module.php`
- Own CSS: `assets/css/feature.css`
- Own JS: `assets/js/feature.js`
- Own templates: `templates/feature/`
- Extends `DD_Module` base class
- Registers its own admin submenu independently
- Communicates ONLY via `do_action()` and `apply_filters()`
- NEVER directly calls another module's methods
- NEVER writes to another module's database table

### Module Responsibilities
| Module | Responsible For | NOT Responsible For |
|---|---|---|
| `DD_Admin` | Core menu items only | Feature submenus |
| `DD_Template_Module` | Page template, nav menus, frontend assets, cart injection, global header | Menu shortcodes |
| `DD_Menu_Module` | Shortcodes only | Assets, templates |
| `DD_Orders_Module` | Cart, orders, emails | UI templates |

---

## 🔄 DEVELOPMENT WORKFLOW

### How We Work

There are two tools used in this project:

| Tool | Role |
|---|---|
| **Claude.ai (web)** | Planning, design decisions, reviewing screenshots, feedback |
| **Claude Code** | Writing code, committing to GitHub, creating releases |

The developer does NOT write code manually or use command line locally.

---

### Step by Step Workflow

```
1. Developer describes what they want in Claude.ai (web)
        ↓
2. Claude.ai plans the solution and gives instructions
        ↓
3. Claude Code reads CLAUDE.md + all project files
        ↓
4. Claude Code writes all code changes
        ↓
5. Claude Code commits all changed files to GitHub
        ↓
6. Claude Code bumps version in dish-dash.php (both lines)
        ↓
7. Claude Code creates release tag vX.X.X
        ↓
8. GitHub Actions automatically builds dish-dash.zip
        ↓
9. Developer goes to WordPress → Plugins → Check for Updates → Update Now
        ↓
10. Developer screenshots the result and shares in Claude.ai
        ↓
11. Claude.ai reviews and gives next instructions to Claude Code
```

---

### Claude Code Access Requirements

Claude Code needs access to the GitHub repo:
- Repo: **github.com/frisoftltd/dish-dash**
- Needs: **read + write access** to commit files and create releases

---

### Release Rules for Claude Code

When making any code change, Claude Code MUST:
1. Update `* Version: X.X.X` in `dish-dash.php` header
2. Update `define('DD_VERSION', 'X.X.X')` in `dish-dash.php`
3. Commit ALL changed files in one commit with a clear message
4. Create release tag `vX.X.X` (WITH the `v` prefix — required for GitHub Actions)
5. Write a clear release title and description

**Version numbering:**
- Bug fix → increment patch: `2.3.x`
- New feature → increment minor: `2.x.0`
- Breaking change → increment major: `x.0.0`

---

### Manual Server Update (if WordPress updater times out)

Run this in cPanel Terminal at server347.web-hosting.com:

```bash
cd /tmp && wget https://github.com/frisoftltd/dish-dash/releases/latest/download/dish-dash.zip && unzip -o dish-dash.zip -d /tmp/dd-update && cp -r /tmp/dd-update/dish-dash/* /home/khansqtg/dishdash.khanakhazana.rw/wp-content/plugins/dish-dash/ && rm -rf /tmp/dd-update /tmp/dish-dash.zip && echo "Done!"
```

---

### What Claude.ai (Web) Does
- Reviews screenshots from the live site
- Makes design and architecture decisions
- Plans new features and phases
- Gives Claude Code clear instructions on what to build
- Reviews output and gives feedback

### What Claude Code Does
- Reads CLAUDE.md to understand the full project
- Writes all PHP, CSS, JS, and template files
- Commits changes directly to GitHub
- Creates version tags and releases
- Never asks the developer to write code manually


---

## 🖥️ SERVER DETAILS

| Property | Value |
|---|---|
| Live site | dishdash.khanakhazana.rw |
| Hosting | cPanel at server347.web-hosting.com |
| cPanel user | khansqtg |
| Plugin path | /home/khansqtg/dishdash.khanakhazana.rw/wp-content/plugins/dish-dash/ |
| PHP version | 8.2 |
| WP-CLI | `wp --path=/home/khansqtg/dishdash.khanakhazana.rw [command]` |

---

## 🎨 TEMPLATE SYSTEM

### Full Page Template (`templates/page-dishdash.php`)
The main restaurant homepage. Completely bypasses Astra theme — outputs its own full HTML document.

**Page Sections (in order):**
1. Header (sticky, shrinks on scroll)
2. Hero section (dark bg, food image, CTA buttons)
3. Quick Order Bar (Delivery/Pickup toggle + search)
4. Browse by Category (circular category images, scrollable)
5. Featured Dishes / Best Sellers (horizontal scroll row)
6. Filter Chips (All, Featured, Veg, Popular)
7. Reserve Table section
8. Selected Category dishes (horizontal scroll, changes when category clicked)
9. Reviews section
10. Footer (4 columns)
11. Cart Drawer (slide-in from right)
12. Floating Cart button
13. Mobile Bottom Nav (4 tabs)

### CSS Custom Properties (set dynamically from plugin settings)
```css
:root {
    --brand:      /* dish_dash_primary_color — default #6B1D1D */
    --brand-dark: /* dish_dash_dark_color — default #160F0D */
    --dd-bg:        #F5EFE6
    --dd-surface:   #FBF7F1
    --dd-surface-2: #FFF7EA
    --dd-gold:      #C9A24A
    --dd-gold-soft: #E6C77A
    --dd-line:      #EADfCE
    --dd-hero:      #1A1A1A  /* dark hero section bg */
}
```

### Theme Conflict Prevention
`DD_Template_Module::remove_theme_conflicts()` runs at priority 999 on `wp_enqueue_scripts` and dequeues ALL Astra, WooCommerce, and WordPress block styles when our page template is active. This prevents any external CSS conflicts.

### Global Header
`DD_Template_Module::inject_global_header()` injects our branded header on these pages:
- `/reserve-table/`
- `/cart/`
- `/restaurant-menu/`
- `/my-restaurant-account/`
- `/my-account/`
- `/track-order/`
- `/checkout/`

---

## ⚙️ SETTINGS REFERENCE

### Template Settings (stored in `wp_options`)
| Option Key | Description | Default |
|---|---|---|
| `dish_dash_restaurant_name` | Restaurant display name | Site name |
| `dish_dash_logo_url` | Logo image URL | '' |
| `dish_dash_primary_color` | Primary brand color | #6B1D1D |
| `dish_dash_dark_color` | Dark brand color | #160F0D |
| `dish_dash_hero_title` | Hero main title (HTML allowed) | 'Best Indian Flavor...' |
| `dish_dash_hero_subtitle` | Hero subtitle | '...' |
| `dish_dash_hero_image` | Hero banner image URL | '' |
| `dish_dash_address` | Restaurant address | '' |
| `dish_dash_phone` | Contact phone | '' |
| `dish_dash_contact_email` | Contact email | '' |
| `dish_dash_opening_hours` | Opening hours (newline separated) | '' |
| `dish_dash_facebook` | Facebook URL | '' |
| `dish_dash_instagram` | Instagram URL | '' |
| `dish_dash_whatsapp` | WhatsApp number | '' |
| `dish_dash_tiktok` | TikTok URL | '' |
| `dish_dash_delivery_fee` | Default delivery fee (RWF) | 2000 |

### WordPress Nav Menu Locations
| Location | Description |
|---|---|
| `dd-primary` | Main navigation in header |
| `dd-footer` | Footer navigation links |

---

## 🔌 AJAX ACTIONS

### Cart
| Action | Handler | Description |
|---|---|---|
| `dd_add_to_cart` | `DD_Cart` | Add product to cart |
| `dd_remove_from_cart` | `DD_Cart` | Remove item from cart |
| `dd_cart_update` | `DD_Cart` | Update item quantity |
| `dd_cart_get` | `DD_Cart` | Get current cart |
| `dd_cart_clear` | `DD_Cart` | Empty the cart |
| `dd_get_cart_count` | `DD_Cart` | Get cart item count |

### Orders
| Action | Handler | Description |
|---|---|---|
| `dd_place_order` | `DD_Orders_Module` | Place a new order |
| `dd_get_order` | `DD_Orders_Module` | Get order details |
| `dd_cancel_order` | `DD_Orders_Module` | Cancel an order |
| `dd_update_status` | `DD_Orders_Module` | Update order status (admin) |

---

## 🪝 WORDPRESS HOOKS

### Actions
| Hook | When It Fires |
|---|---|
| `dish_dash_loaded` | After all modules initialized |
| `dish_dash_order_placed` | After new order saved |
| `dish_dash_order_status_changed` | When order status changes |
| `dish_dash_order_delivered` | When order marked delivered |

### Filters
| Filter | What It Modifies |
|---|---|
| `dish_dash_menu_query_args` | WP_Query args for menu display |
| `theme_page_templates` | Adds Dish Dash Full Page to template list |
| `template_include` | Loads plugin template file |

---

## 🗺️ DEVELOPMENT ROADMAP

| Phase | Name | Key Features | Status |
|---|---|---|---|
| 1 | Foundation | Plugin bootstrap, GitHub updater, WooCommerce integration | ✅ Complete |
| 2 | Cart & Orders | Cart sidebar, checkout, order placement, email notifications | ✅ Complete |
| 3 | Template System | Custom page template, header, hero, footer, branding | 🔄 In Progress |
| 4 | Custom Cart & Checkout Flow | Beautiful custom cart UI, custom checkout form, WooCommerce payment bridge only | ⏳ Next |
| 5 | Delivery System | Delivery zones, fees, driver assignment, real-time tracking | ⏳ Planned |
| 6 | Multi-Branch | Multiple locations, branch menus, location selector | ⏳ Planned |
| 7 | Reservations | Table booking, time slots, capacity management | ⏳ Planned |
| 8 | POS Terminal | In-restaurant ordering, kitchen display, table management | ⏳ Planned |
| 9 | Analytics + AI | Sales reports, popular items, Claude AI insights | ⏳ Planned |
| 10 | Loyalty & QR | Points system, QR menu, digital loyalty cards | ⏳ Planned |
| 11 | SaaS Platform | Cloud licensing, multi-tenant, white label, API | ⏳ Planned |

---

## 🛒 PHASE 4 — CUSTOM CART & CHECKOUT (NEXT PRIORITY)

### Goal
Build a beautiful custom ordering flow that feels like a real food app. WooCommerce is ONLY used at the final payment step — everything else is our own UI.

### Flow
```
Menu Page
    ↓ Add to Cart (AJAX — no page reload)
Cart Drawer / Cart Page (our UI)
    ↓ "Proceed to Order"
Custom Checkout Page (our UI)
    - Delivery address
    - Phone number
    - Special instructions
    - Delivery / Pickup selection
    - Order summary
    ↓ "Place Order"
WooCommerce Payment Page (WooCommerce handles payment only)
    ↓ Payment complete
Order Confirmation Page (our UI)
    - Order number
    - Estimated time
    - Track order button
```

### Pages to Build
- `/cart/` — full cart page with our UI
- `/checkout/` — custom checkout form (NOT WooCommerce checkout)
- `/order-confirmation/` — thank you / confirmation page
- `/track-order/` — order status tracker

### Key Requirements
- Cart persists across page loads (server-side via WooCommerce session)
- Cart count badge updates in real-time on all pages
- Custom checkout collects: name, phone, address, delivery type, notes
- On submit: creates WooCommerce order programmatically, redirects to WC payment
- After payment: WooCommerce webhook fires `dish_dash_order_placed` hook
- Restaurant receives email notification with order details
- Customer receives confirmation SMS/email

---

## 🎨 DESIGN SYSTEM

### Typography
- **Display/Headings:** Cormorant Garamond (serif) — elegant, restaurant feel
- **Body:** Inter (sans-serif) — clean, readable

### Colors
| Name | Hex | Usage |
|---|---|---|
| Brand Red | `#6B1D1D` | Primary buttons, active states, prices |
| Brand Dark | `#160F0D` | Hero background, footer |
| Gold | `#C9A24A` | Accent, cart badge, checkout button |
| Gold Soft | `#E6C77A` | Gold hover states |
| Background | `#F5EFE6` | Page background |
| Surface | `#FBF7F1` | Card backgrounds |
| Surface 2 | `#FFF7EA` | Active card backgrounds |
| Text | `#221B19` | Primary text |
| Muted | `#6E5B4C` | Secondary text |
| Line | `#EADfCE` | Borders, dividers |

### Component Classes (all prefixed `dd-`)
| Class | Description |
|---|---|
| `.dd-btn--brand` | Primary red button |
| `.dd-btn--gold` | Gold checkout/CTA button |
| `.dd-btn--outline` | Transparent border button (on dark bg) |
| `.dd-btn--soft` | Semi-transparent button (on dark bg) |
| `.dd-btn--light` | White button (on light bg) |
| `.dd-dish-card` | Food product card (290px wide, scroll row) |
| `.dd-cat-card` | Category circle (200px, with name below) |
| `.dd-scroll-row` | Horizontal scroll container |
| `.dd-section` | Standard page section (padding-top: 72px) |
| `.dd-summary` | Cart summary sidebar (dark bg) |
| `.dd-cart-drawer` | Slide-in cart drawer |
| `.dd-floating-cart` | Fixed position cart button |
| `.dd-bottom-nav` | Mobile bottom tab bar |

---

## 📱 MOBILE DESIGN

### Breakpoints
| Breakpoint | Target |
|---|---|
| `≤ 1100px` | Tablet — stack hero, single column layouts |
| `≤ 860px` | Mobile — show hamburger, hide desktop nav |
| `≤ 680px` | Small mobile — show bottom nav, hide floating cart |

### Mobile Bottom Nav (4 tabs)
- Home → scrolls to `#home`
- Menu → scrolls to `#menu`
- Reserve → scrolls to `#reserve`
- Cart → opens cart drawer

### Mobile Nav Dropdown
- Drops down from header (not fullscreen)
- Background: `#f9f5ef`
- Full width, each link 14px padding
- Brand color top border

---

## 🔐 SECURITY RULES

Always follow these in every PHP file:
```php
// 1. Check for ABSPATH
if ( ! defined( 'ABSPATH' ) ) exit;

// 2. Verify nonces on all form submissions
check_admin_referer( 'dd_action_name', 'dd_nonce' );

// 3. Check capabilities
if ( ! current_user_can( 'manage_options' ) ) return;

// 4. Sanitize all input
$value = sanitize_text_field( $_POST['field'] );

// 5. Escape all output
echo esc_html( $value );
echo esc_url( $url );
echo esc_attr( $attr );
echo wp_kses_post( $html );
```

---

## 🛠️ CODE STYLE

```php
// Class naming
class DD_Feature_Module extends DD_Module { }

// Function naming
function dd_helper_function() { }

// Hook naming
do_action( 'dish_dash_event_name', $data );
apply_filters( 'dish_dash_filter_name', $value );

// Always use type declarations (PHP 8.0+)
public function method_name( string $param ): void { }

// Use early returns
public function my_method(): void {
    if ( is_admin() ) return;
    if ( ! is_page() ) return;
    // main logic here
}
```

---

## ✅ FEATURES BUILT (as of v2.5.x)

### 🎨 Template & Theme
- Bundled **Dish Dash blank theme** — auto-installs on plugin activation
- Zero conflicts with any WordPress theme
- Theme supports `wp_body_open` for global header injection
- Google Fonts (Cormorant Garamond + Inter) loaded globally via `@import`
- CSS variables in `:root` so they apply on all pages

### 🔝 Global Header
- Sticky header with shrink-on-scroll behavior
- Compact elegant design — 72px default, shrinks to 58px on scroll
- Logo, navigation, Track Order and Cart buttons
- Mobile hamburger menu with dropdown (background `#f9f5ef`)
- Header injected globally on: `/reserve-table/`, `/cart-dd/`, `/checkout-dd/`, `/restaurant-menu/`, `/my-account/`, `/track-order/`
- Astra and all theme headers hidden via PHP hooks + CSS on those pages

### 🦸 Hero Section
- Dark background with restaurant image + brand color overlay
- Dynamic overlay color picker + opacity slider (0–100%)
- Separate background image field
- CTA buttons (3 buttons with custom labels + links)
- Feature chips (show/hide, 4 customizable chips)
- All content dynamic from Homepage Settings

### 🍽️ Browse by Category
- Category circles with brand color border on ALL circles
- Horizontal scroll with arrows
- Clicking circle navigates to WooCommerce category page
- Show/hide, custom title, max count — all from backend

### ⭐ Featured Dishes
- Desktop: 4×2 grid with **Load More** button
- Mobile: horizontal scroll row (unchanged)
- Dynamic product count (4, 6, 8, 12, All)
- Sort by: popularity, latest, price, random
- Filter chips from **WooCommerce product tags** (select up to 8)
- Load More resets correctly when switching filter chips

### 📂 Selected Category Section
- Title: **"Find Your Favorite Dish"** with gold highlight
- Category tab pills (horizontal scroll on mobile, wrap on desktop)
- ← → scroll arrows on the right (mobile only)
- Multi-select categories from backend (or show all)
- Desktop: 4×2 grid with Load More
- Mobile: horizontal scroll row
- Products per category: 4, 6, 8, 12, All

### 📅 Reserve Table Section
- Dark background with restaurant dining image + overlay
- Dynamic background image changeable from backend
- Form fields with visible labels (universal mobile compatibility)
- Single column on mobile, 2-column grid on desktop

### ⭐ Google Reviews Section
- Show/hide toggle
- Custom title
- Source: Manual OR Google Places API
- Google Place ID + API Key fields
- Min star rating filter (3, 4, 5+)
- Number of reviews to show
- Manual reviews entry (up to 3 text areas)

### 🦶 Footer
- SVG social media icons (Facebook, Instagram, WhatsApp, TikTok)
- Show/hide: description, social icons, Explore, Contact, Opening Hours columns
- Dynamic footer description text
- Opening hours from backend

### 📱 Mobile
- Bottom navigation (4 tabs: Home, Menu, Reserve, Cart)
- Hamburger menu dropdown
- Mobile-friendly reserve form (font-size 16px prevents iOS zoom)
- Responsive breakpoints: 1100px (tablet), 860px (mobile), 680px (small mobile)

### 🛒 Dish Cards
- No description shown (shows on product page click)
- Price and Add to Cart on same row
- Smaller modern typography
- No sticky Popular/Best Seller tag

### 🏠 Homepage Settings Admin Page
7 sections fully controllable from backend:
1. **Header** — show/hide Track Order and Cart
2. **Hero** — title, subtitle, image, background, overlay color/opacity, buttons, chips
3. **Browse by Category** — show/hide, title, count
4. **Featured Dishes** — count, sort, tag filters, chips
5. **Selected Category** — categories multi-select, count
6. **Google Reviews** — source, API keys, count, rating
7. **Footer** — description, social, columns

### 🎛️ Admin UI
- Custom SVG icon for Dish Dash sidebar
- Emoji icons for all menu items (📊🛒🍽️📅🚗🏪🖥️📈⚙️🏠)
- Modern gradient header on all admin pages
- Rounded inputs with brand color focus states
- Brand color save button with shadow

---

## 🚨 RULES — NEVER BREAK THESE

### Code Rules
1. **Always give complete files** — never partial snippets or line changes
2. **Always give the full GitHub path** — e.g. `modules/homepage/class-dd-homepage-module.php`
3. **Never touch what wasn't asked** — fix only the reported issue
4. **Every module must be independent** — communicate only via `do_action()` and `apply_filters()`
5. **Never call another module's methods directly**
6. **Always wrap WooCommerce functions** in `function_exists()` checks
7. **Always wrap helper functions** in `if (!function_exists())` blocks
8. **Unchecked HTML checkboxes don't submit** — always use `isset($_POST[$key]) ? '1' : '0'` logic
9. **Never hardcode data** — everything must be configurable from settings

### CSS Rules
10. **`display: flex !important` overrides HTML `hidden` attribute** — always use `.dd-cat-row:not([hidden])` not `.dd-cat-row`
11. **All classes prefixed `dd-`** to avoid conflicts
12. **Test in incognito** — regular browser caches old CSS
13. **Purge LiteSpeed Cache** after every update when testing

### Release Rules
14. **Always update BOTH version lines** in `dish-dash.php` — header comment AND `DD_VERSION` constant
15. **Tag MUST use `v` prefix** — `v2.5.x` not `2.5.x` — GitHub Actions won't build without it
16. **Commit ALL files BEFORE creating release tag**
17. **Run manual command** — WordPress auto-updater is unreliable

### Debugging Rules
18. **Always check debug log first**: `tail -20 /home/khansqtg/.../wp-content/debug.log`
19. **Always verify file on server**: `grep -n "keyword" /path/to/file.php`
20. **Always test logged out** — admin bar changes page structure and masks real issues

---

## 📝 VERSION HISTORY

| Version | Changes |
|---|---|
| v1.0.0–v1.0.3 | Initial release, plugin bootstrap, GitHub Actions workflow |
| v2.0.0 | Phase 2: Cart, orders, WooCommerce bridge, email notifications |
| v2.0.1–v2.1.3 | Various fixes: menu cards, cart positioning, nav, hero |
| v2.2.0 | New full page template matching UI mockup |
| v2.3.x | Header, nav, mobile, category, featured dishes fixes |
| v2.4.0–v2.4.13 | Bundled Dish Dash theme, global header, footer, fonts |
| v2.5.0–v2.5.29 | Homepage Settings module, all 7 sections dynamic, Google Reviews, desktop grid, Load More, category tabs |

---

*This document is maintained by Fri Soft Ltd.*
*Last updated: March 2026 — v2.5.x*
