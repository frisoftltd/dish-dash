# ARCHITECTURE.md — Dish Dash Plugin URL & Module Map
> Version 3.1.13 | Generated April 2026 | Do NOT modify by hand — regenerate from code audit.

Any developer (human or AI) should be able to answer "what file controls what" in 30 seconds using this document.

---

## URL: / (Homepage)

| Aspect | Detail |
|---|---|
| Page type | WordPress page with custom page template |
| Template file | `templates/page-dishdash.php` (full HTML document, bypasses theme) |
| Template registered by | `DD_Template_Module` via `theme_page_templates` + `template_include` filters |
| Template chain | WordPress `template_include` filter → `templates/page-dishdash.php` → loads sections inline |
| Page sections | Header → Hero → Quick Order Bar → Browse by Category → Featured Dishes → Filter Chips → Reserve Table → Selected Category → Reviews → Footer → Cart Drawer → Floating Cart → Mobile Bottom Nav → Product Modal |
| Primary CSS | `assets/css/theme.css` (82 KB — all homepage component styles) |
| Secondary CSS | Google Fonts (`@import` in theme.css) |
| Primary JS | `assets/js/frontend.js` (65 KB — all homepage interactivity) |
| Secondary JS | `assets/js/tracking.js` (session event tracking) |
| AJAX actions | `dd_get_reviews`, `dd_track_event`, `dd_get_recent_searches`, `dd_get_search_products`, `dd_cart_add`, `dd_cart_get`, `dd_cart_update`, `dd_cart_remove`, `dd_cart_clear` |
| Query params | None |
| Active state classes | `.dd-mode-btn.active` (Delivery/Pickup toggle), `.dd-cat-card.active` (category pills), `.dd-filter-btn--active` (filter chips) |
| Module owner | `DD_Template_Module` (`modules/template/class-dd-template-module.php`) — asset enqueue + template load |
| Content owner | `DD_Homepage_Module` (`modules/homepage/class-dd-homepage-module.php`) — all 7 dynamic sections |
| Database tables | `{prefix}dishdash_user_events` (write via tracking), `{prefix}dishdash_user_profiles` (write via tracking) |
| WP options read | `dish_dash_hero_title`, `dish_dash_hero_subtitle`, `dd_hero_bg_image`, `dd_hero_btn{1,2,3}_*`, `dd_hero_chip_{1-4}`, `dd_categories_*`, `dd_featured_*`, `dd_selcat_*`, `dd_reviews_*`, `dd_footer_*`, `dd_header_*`, `dish_dash_primary_color`, `dish_dash_dark_color`, `dish_dash_restaurant_name` |

---

## URL: /restaurant-menu/

| Aspect | Detail |
|---|---|
| Page type | Standard WordPress page (slug: `restaurant-menu`, uses default theme template) |
| Template chain | Theme `page.php` → post content → `[dish_dash_menu]` shortcode → `templates/menu/grid.php` |
| Shortcode | `[dish_dash_menu category="" columns="3" show_filter="yes" show_search="yes" items_per_page="-1"]` |
| Shortcode registered by | `DD_Menu_Module` (`modules/menu/class-dd-menu-module.php`) |
| Desktop layout class | `.dd-menu-page--desktop` (visible ≥ 860px via `menu-page.css`) |
| Mobile layout class | `.dd-menu-page--mobile` (visible ≤ 860px) |
| Container class | `.dd-menu-container` (centered max-width wrapper, `menu-page.css` line 11) |
| Category carousel class | `.dd-menu-cats` (horizontal scroll, `menu-page.css`) |
| Active category class | `.dd-menu-cat.is-active` |
| Grid section class | `.dd-menu-grid-section`, `.dd-menu-grid` (desktop product grid) |
| Primary CSS | `assets/css/menu-page.css` (desktop layout, loaded only on menu page) |
| Secondary CSS | `assets/css/menu.css` (product card styles, shared with shortcode widget) |
| Tertiary CSS | `assets/css/theme.css` (header + global utilities) |
| Primary JS | `assets/js/frontend.js` (product modal, smart search, cart) |
| Secondary JS | `assets/js/tracking.js` |
| AJAX actions | `dd_menu_load_products` (paginated product grid), `dd_cart_add`, `dd_track_event`, `dd_get_recent_searches`, `dd_get_search_products` |
| Query params | `?cat={slug}` (deep-link from homepage category circles — sets active category on load) |
| Module owner | `DD_Menu_Module` (shortcode + AJAX) + `DD_Template_Module` (CSS/JS enqueue detection via `dish_dash_menu_page_id`) |
| Database tables | `{prefix}dishdash_user_events` (write, tracking), WooCommerce product tables (read) |
| WP options read | `dish_dash_menu_page_id`, `dish_dash_primary_color`, `dish_dash_dark_color`, `dish_dash_restaurant_name` |
| WooCommerce dependency | Queries `product` post type and `product_cat` taxonomy |

---

## URL: /cart-dd/ (Cart Page)

| Aspect | Detail |
|---|---|
| Page type | Standard WordPress page (slug: `cart-dd`, configurable via `dish_dash_cart_page_id` option) |
| Template chain | Theme `page.php` → post content → `[dish_dash_cart]` shortcode → `templates/cart/cart.php` |
| Shortcode | `[dish_dash_cart]` (registered by `DD_Menu_Module`) |
| Wrapper class | `.dd-checkout-wrap` |
| Left column class | `.dd-checkout-left` |
| Summary class | `.dd-checkout-summary` |
| Primary CSS | `assets/css/cart.css` |
| Secondary CSS | `assets/css/theme.css` (global header + utilities) |
| Primary JS | `assets/js/cart.js` (cart sidebar, checkout form binding, AJAX cart ops) |
| AJAX actions | `dd_cart_get`, `dd_cart_update`, `dd_cart_remove`, `dd_cart_clear` |
| Cart storage | Server-side WP transients: `dd_cart_user_{user_id}` (logged-in) or `dd_cart_{session_id}` (guest, 3-day TTL) |
| Session cookie | `dd_session` (32-char hash, 7-day TTL) |
| Module owner | `DD_Menu_Module` (shortcode) + `DD_Cart` (AJAX handlers, `modules/orders/class-dd-cart.php`) |
| Database tables | None — transient-based storage |
| WP options read | `dish_dash_cart_page_id`, `dish_dash_checkout_page_id`, `dish_dash_currency_symbol`, `dish_dash_tax_rate` |

---

## URL: /checkout-dd/ (Checkout Page)

| Aspect | Detail |
|---|---|
| Page type | Standard WordPress page (slug: `checkout-dd`, configurable via `dish_dash_checkout_page_id` option) |
| Template chain | Theme `page.php` → post content → `[dish_dash_checkout]` shortcode → `templates/checkout/checkout.php` |
| Shortcode | `[dish_dash_checkout]` (registered by `DD_Menu_Module`) |
| Wrapper class | `.dd-checkout-wrap` |
| Form class | `.dd-checkout-form` |
| Order type class | `.dd-order-type-btn`, `.dd-order-type-btn--active` |
| Submit class | `.dd-submit-btn` |
| Primary CSS | `assets/css/cart.css` (checkout styles share cart.css) |
| Secondary CSS | `assets/css/theme.css` |
| Primary JS | `assets/js/cart.js` (checkout form submit, order placement) |
| AJAX actions | `dd_place_order`, `dd_cart_get` |
| Form fields | `order_type` (delivery/pickup/dine-in), `delivery_street`, `delivery_city`, `delivery_postcode`, `customer_name`, `customer_phone`, `customer_email`, `special_instructions`, `payment_method` |
| Module owner | `DD_Menu_Module` (shortcode) + `DD_Orders_Module` (order placement AJAX) |
| Database tables | `{prefix}dishdash_orders` (write), `{prefix}dishdash_order_items` (write) |
| WP options read | `dish_dash_checkout_page_id`, `dish_dash_currency_symbol`, `dish_dash_delivery_fee`, `dish_dash_enable_delivery`, `dish_dash_enable_pickup`, `dish_dash_enable_dinein` |

---

## URL: /track-order/

| Aspect | Detail |
|---|---|
| Page type | Standard WordPress page (slug: `track-order`, configurable via `dish_dash_track_page_id`) |
| Template chain | Theme `page.php` → `[dish_dash_track]` shortcode → inline HTML from shortcode handler |
| Shortcode | `[dish_dash_track]` — ⚠️ registered by BOTH `DD_Menu_Module` AND `DD_Orders_Module` (last registration wins) |
| Primary CSS | `assets/css/theme.css` (no dedicated CSS file) |
| Primary JS | `assets/js/cart.js` (order status AJAX calls) |
| AJAX actions | `dd_get_order` |
| Query params | `?order={order_id}` (pre-fills order lookup) |
| Global header | Injected by `DD_Template_Module::inject_global_header()` (`wp_body_open` hook) |
| Module owner | `DD_Menu_Module` (shortcode) + `DD_Orders_Module` (AJAX) |
| Database tables | `{prefix}dishdash_orders` (read), `{prefix}dishdash_order_items` (read) |
| WP options read | `dish_dash_track_page_id` |

---

## URL: /reserve-table/

| Aspect | Detail |
|---|---|
| Page type | Standard WordPress page (slug: `reserve-table`) |
| Template chain | Theme `page.php` → `[dish_dash_reserve]` shortcode → inline HTML from shortcode handler |
| Shortcode | `[dish_dash_reserve]` (registered by `DD_Menu_Module`) |
| Primary CSS | Inline styles in shortcode output (no dedicated CSS file) |
| Primary JS | Inline script in shortcode output |
| Global header | Injected by `DD_Template_Module::inject_global_header()` (`wp_body_open` hook) |
| Module owner | `DD_Menu_Module` (shortcode — form is fully hardcoded inline) |
| Database tables | `{prefix}dishdash_reservations` (write — via form submission) |
| WP options read | `dish_dash_restaurant_name` |

---

## URL: /my-account/ and /my-restaurant-account/

| Aspect | Detail |
|---|---|
| Page type | Standard WordPress page — `[dish_dash_account]` shortcode wraps WooCommerce account |
| Template chain | Theme `page.php` → `[dish_dash_account]` shortcode → WooCommerce account content |
| Shortcode | `[dish_dash_account]` — ⚠️ registered by BOTH `DD_Menu_Module` AND `DD_Orders_Module` (last wins) |
| Global header | Injected by `DD_Template_Module::inject_global_header()` (`wp_body_open` hook) |
| Primary CSS | `assets/css/theme.css` |
| Primary JS | `assets/js/frontend.js` |
| AJAX actions | `dd_login`, `dd_register`, `dd_logout` (via `DD_Auth_Module`) |
| Auth modal class | `.dd-auth-modal`, `.dd-auth-modal.open` |
| Module owner | `DD_Auth_Module` (`modules/auth/class-dd-auth-module.php`) — login/register/OAuth |
| WP options read | `dd_google_client_id`, `dd_noreply_email`, `dd_from_name` |

---

## URL: ?dd_google_callback=1 (Google OAuth Callback)

| Aspect | Detail |
|---|---|
| Page type | Query parameter intercepted on `init` hook — no dedicated page |
| Handler | `DD_Auth_Module::handle_google_oauth()` |
| Flow | Receives Google auth code → exchanges for token → fetches user info → logs in or registers user → redirects |
| Module owner | `DD_Auth_Module` |

---

## URL: ?dd_verify_email=token (Email Verification)

| Aspect | Detail |
|---|---|
| Page type | Query parameter intercepted on `init` hook — no dedicated page |
| Handler | `DD_Auth_Module::handle_email_verification()` |
| Module owner | `DD_Auth_Module` |

---

## Admin: Dish Dash > Dashboard (`admin.php?page=dish-dash`)

| Aspect | Detail |
|---|---|
| Template | `admin/pages/dashboard.php` |
| Renderer | `DD_Admin::render_dashboard()` (`admin/class-dd-admin.php`) |
| Content | KPI cards (orders, revenue, pending, menu items), quick links, setup checklist |
| Primary CSS | `assets/css/admin.css` + inline styles from `DD_Admin::get_admin_styles()` |
| Primary JS | `assets/js/admin.js` |
| WP options read | `dish_dash_menu_page_id`, `dish_dash_google_maps_key`, `dd_menu_item` post count |
| Module owner | `DD_Admin` |

---

## Admin: Dish Dash > Orders (`admin.php?page=dish-dash-orders`)

| Aspect | Detail |
|---|---|
| Template | `admin/pages/orders.php` |
| Renderer | `DD_Orders_Module` (registers submenu independently) |
| Content | Filterable order table with status tabs, status update buttons |
| Primary CSS | `assets/css/admin.css` |
| Primary JS | `assets/js/admin.js` + inline jQuery in orders.php |
| AJAX actions | `dd_update_status` (admin-only nonce required) |
| Status tabs | all, pending, confirmed, preparing, ready, out_for_delivery, delivered, cancelled |
| Database tables | `{prefix}dishdash_orders` (read + status update), `{prefix}dishdash_order_items` (read) |
| Module owner | `DD_Orders_Module` (`modules/orders/class-dd-orders-module.php`) |

---

## Admin: Dish Dash > Menu (`admin.php?page=dish-dash-menu`)

| Aspect | Detail |
|---|---|
| Behavior | Redirects immediately to `edit.php?post_type=dd_menu_item` (standard WP editor) |
| Module owner | `DD_Admin` |

---

## Admin: Dish Dash > Settings (`admin.php?page=dish-dash-settings`)

| Aspect | Detail |
|---|---|
| Template | `admin/pages/settings.php` |
| Renderer | `DD_Template_Module::render_settings()` (registers submenu independently) |
| Content | Currency, tax, min order, order prefix, Google Maps API key, Claude API key, feature toggles |
| AJAX actions | None — standard form POST with nonce |
| WP options written | `dish_dash_currency_symbol`, `dish_dash_currency_position`, `dish_dash_tax_rate`, `dish_dash_tax_label`, `dish_dash_min_order`, `dish_dash_order_prefix`, `dish_dash_google_maps_key`, `dish_dash_claude_api_key`, `dish_dash_enable_pickup`, `dish_dash_enable_delivery`, `dish_dash_enable_dinein`, `dish_dash_enable_reservations`, `dish_dash_enable_pos` |
| Module owner | `DD_Template_Module` |

---

## Admin: Dish Dash > Homepage (`admin.php?page=dish-dash-homepage`)

| Aspect | Detail |
|---|---|
| Template | Rendered inline by `DD_Homepage_Module` |
| Content | 7 sections: Header, Hero, Browse by Category, Featured Dishes, Selected Category, Google Reviews, Footer |
| AJAX actions | `dd_get_reviews` (test/preview Google reviews) |
| WP options written | `dd_header_*`, `dish_dash_hero_*`, `dd_hero_*`, `dd_categories_*`, `dd_featured_*`, `dd_selcat_*`, `dd_reserve_bg_image`, `dd_reviews_*`, `dd_footer_*`, `dish_dash_opening_hours` |
| Module owner | `DD_Homepage_Module` (`modules/homepage/class-dd-homepage-module.php`) |

---

## Admin: Dish Dash > Auth (`admin.php?page=dish-dash-auth`)

| Aspect | Detail |
|---|---|
| Content | Google OAuth credentials, SMTP configuration, test email button |
| AJAX actions | `dd_test_email` (admin-only) |
| WP options written | `dd_google_client_id`, `dd_google_client_secret`, `dd_smtp_host`, `dd_smtp_port`, `dd_smtp_username`, `dd_smtp_password`, `dd_smtp_encryption`, `dd_noreply_email`, `dd_from_name` |
| Module owner | `DD_Auth_Module` (`modules/auth/class-dd-auth-module.php`) |

---

## Admin: Dish Dash > Customers (`admin.php?page=dish-dash-customers`)

| Aspect | Detail |
|---|---|
| Content | Customer CRM — list of WP users with spend, order count, reservation count, status tier |
| AJAX actions | `dd_save_customer` (admin-only, updates user meta) |
| Data sources | `get_users()`, `wc_get_orders()`, `{prefix}dishdash_reservations` table |
| User meta written | `dd_phone`, `dd_address`, `dd_birthday` |
| Module owner | `DD_Customers_Module` (`modules/customers/class-dd-customers-module.php`) |

---

## Admin: Coming-Soon Pages

| Page slug | Status |
|---|---|
| `dish-dash-reservations` | Coming soon placeholder |
| `dish-dash-delivery` | Coming soon placeholder |
| `dish-dash-branches` | Coming soon placeholder |
| `dish-dash-pos` | Coming soon placeholder |
| `dish-dash-analytics` | Coming soon placeholder |

---

## REST API Endpoints

| Method | Endpoint | Handler | Permission |
|---|---|---|---|
| GET | `/wp-json/dish-dash/v1/orders` | `DD_Orders_Module::get_orders()` | Admin only |
| GET | `/wp-json/dish-dash/v1/orders/{id}` | `DD_Orders_Module::get_order()` | Admin only |
| PUT | `/wp-json/dish-dash/v1/orders/{id}/status` | `DD_Orders_Module::update_status()` | Admin only |

---

## Module Dependency Graph

```
┌─────────────────────────────────────────────────────────────────┐
│                      dish-dash.php (bootstrap)                   │
│                    DD_Loader::instance()->boot()                  │
└───────────────────────────┬─────────────────────────────────────┘
                            │ loads & inits all modules
          ┌─────────────────┼─────────────────────────┐
          ▼                 ▼                          ▼
   DD_Module (abstract base class — all modules extend this)

┌──────────────┐  ┌───────────────────┐  ┌───────────────────┐
│  DD_Admin    │  │ DD_Template_Module│  │ DD_Homepage_Module│
│ (core menus) │  │ (page tmpl, header│  │ (7 homepage sects)│
└──────────────┘  │  footer, assets)  │  └───────────────────┘
                  └───────────────────┘
┌──────────────┐  ┌───────────────────┐  ┌───────────────────┐
│DD_Menu_Module│  │DD_Orders_Module   │  │  DD_Cart (static) │
│(shortcodes   │  │(REST API, AJAX    │  │(AJAX cart ops,    │
│  only)       │  │ order lifecycle)  │  │ transient storage)│
└──────────────┘  └───────────────────┘  └───────────────────┘
┌──────────────┐  ┌───────────────────┐  ┌───────────────────┐
│DD_Auth_Module│  │ DD_Customers_Module│ │ DD_Tracking_Module│
│(login, OAuth,│  │(CRM, user tiers)  │  │(events, sessions) │
│  SMTP)       │  └───────────────────┘  └───────────────────┘
└──────────────┘

Cross-module communication: ONLY via WordPress hooks
───────────────────────────────────────────────────
do_action('dish_dash_order_placed', $order_id, $data)
do_action('dish_dash_order_status_changed', $id, $old, $new)
do_action('dish_dash_order_delivered', $order_id)
do_action('dish_dash_reservation_created', $id)
do_action('dish_dash_register_rest_routes', $namespace)

apply_filters('dish_dash_menu_query_args', $args)
apply_filters('dish_dash_order_data', $data, $order_id)
apply_filters('dish_dash_delivery_fee', $fee, $zone_id, $subtotal)
apply_filters('dish_dash_price', $formatted, $raw)
apply_filters('dish_dash_email_template', $html, $type, $data)
```

---

## ⚠️ Architecture Debt Found

### DEBT-001: Duplicate Shortcode Registration (CONFLICT)

**Files involved:**
- `modules/menu/class-dd-menu-module.php`
- `modules/orders/class-dd-orders-module.php`

**Problem:** Both modules call `add_shortcode('dish_dash_track', ...)` and `add_shortcode('dish_dash_account', ...)`. WordPress only keeps the last registration. The winning module depends on load order in `DD_Loader`, which is fragile.

**Current behavior:** `DD_Orders_Module` loads after `DD_Menu_Module`, so its registrations win for `[dish_dash_track]` and `[dish_dash_account]`. If load order ever changes, these shortcodes silently switch behavior.

**Resolution needed:** Pick one owner per shortcode. `[dish_dash_track]` belongs in `DD_Orders_Module`. `[dish_dash_account]` belongs in `DD_Auth_Module`. Remove duplicates from `DD_Menu_Module`.

---

### DEBT-002: Dual Cart Systems (SILENT DATA MISMATCH)

**Files involved:**
- `assets/js/menu.js` (lines 39–60): writes to `localStorage` key `dd_cart`
- `assets/js/cart.js` + `modules/orders/class-dd-cart.php`: uses server-side WP transients via AJAX

**Problem:** `menu.js` maintains its own localStorage cart for the `[dish_dash_menu]` shortcode widget. The canonical cart system in `cart.js` and `DD_Cart` uses server-side transients. These two systems do NOT sync. A user who adds items via `[dish_dash_menu]` shortcode page may see different cart contents than the cart sidebar.

**Resolution needed:** Remove localStorage cart logic from `menu.js`. Have Add-to-Cart buttons on the shortcode widget call `dd_cart_add` AJAX (same as `frontend.js` does) and listen for `dd_cart_updated` event to refresh badge.

---

### DEBT-003: Admin Styles Delivered as Inline PHP String

**Files involved:**
- `admin/class-dd-admin.php`: `get_admin_styles()` method outputs ~100 lines of CSS as a PHP heredoc, then echoes via `<style>` tag in admin head

**Problem:** These styles cannot be cached, minified, or easily overridden. They override any external admin stylesheet because they're inline. Editor tooling (linters, formatters) cannot process them.

**Resolution needed:** Move all inline admin CSS into `assets/css/admin.css` and load via `wp_enqueue_style()`.

---

### DEBT-004: Reserve Table Shortcode Has No Dedicated Module

**Files involved:**
- `modules/menu/class-dd-menu-module.php`: `shortcode_reserve()` outputs a full reservation form with inline styles and inline JS

**Problem:** The reserve form is hardcoded inside `DD_Menu_Module`, which is supposed to own "shortcodes only". The form has its own styling (not in any CSS file), its own JS (not in any JS file), and presumably writes to `dishdash_reservations` table — yet no module officially owns that table. `DD_Menu_Module` should not contain UI with inline styles.

**Resolution needed:** Create a `DD_Reservations_Module` (Phase 7 in roadmap) that owns the reserve form, the reservations table, and the `dish-dash-reservations` admin page.

---

*Last audited: v3.1.13, April 2026*
