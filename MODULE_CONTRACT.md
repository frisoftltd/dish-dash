# MODULE_CONTRACT.md — Dish Dash Module Interfaces
> Version 3.1.13 | Generated April 2026 | Defines what each module owns, fires, and listens to.

The core architecture rule: **modules NEVER call each other's methods directly**. All cross-module communication goes through WordPress hooks (`do_action` / `apply_filters`). Any violation is flagged as ❌ TECH DEBT in the Cross-Module Communication Audit at the bottom.

---

## DD_Module (Abstract Base Class)

| Aspect | Detail |
|---|---|
| File | `dishdash-core/class-dd-module.php` |
| Extends | None (abstract class) |
| Purpose | Base class that all feature modules must extend. Provides shared utilities. |
| Key methods | `init()` (abstract — must implement), `get_option(key, default)`, `update_option(key, value)`, `enqueue_style(handle, file, deps, ver)`, `enqueue_script(handle, file, deps, ver, in_footer)`, `render_template(template, args)`, `get_template(template, args)` |
| Option scoping | `get_option()` / `update_option()` auto-prefix keys as `dish_dash_{module_id}_{key}` |
| Template loading | Supports theme overrides via `/dish-dash/` subdirectory in active theme (WooCommerce-style) |

---

## DD_Loader

| Aspect | Detail |
|---|---|
| File | `dishdash-core/class-dd-loader.php` |
| Pattern | Singleton |
| Purpose | Bootstraps the entire plugin — loads core files, registers all modules, runs `init()` on each. |
| Boot sequence | `load_core_files()` → `register_modules()` → `DD_Cart::register_ajax()` → `init_modules()` → `load_textdomain()` → `do_action('dish_dash_loaded')` |
| Modules registered | DD_Admin, DD_Template_Module, DD_Homepage_Module, DD_Auth_Module, DD_Customers_Module, DD_Tracking_Module, DD_Menu_Module, DD_Orders_Module |
| Hooks fired | `dish_dash_loaded` (after all modules initialized) |

---

## DD_Ajax (Static Utility Class)

| Aspect | Detail |
|---|---|
| File | `dishdash-core/class-dd-ajax.php` |
| Pattern | Static class (not a module, not instantiated) |
| Purpose | Centralized AJAX registration and nonce verification |
| Key methods | `register(action, callback, nopriv=true)` — registers both `wp_ajax_{action}` and optionally `wp_ajax_nopriv_{action}` |
| Nonce verification | `verify_nonce(field='nonce', action='dish_dash_frontend')` — dies with JSON error on failure |

---

## DD_Settings (Static Utility Class)

| Aspect | Detail |
|---|---|
| File | `dishdash-core/class-dd-settings.php` |
| Pattern | Static class |
| Purpose | Centralized wp_options wrapper — all keys auto-prefixed with `dish_dash_` |
| Key methods | `get(key, default)`, `set(key, value)`, `get_public_settings()` |
| All known keys | `currency`, `currency_symbol`, `currency_position`, `tax_rate`, `tax_label`, `min_order`, `order_prefix`, `order_counter`, `google_maps_key`, `claude_api_key`, `enable_pickup`, `enable_delivery`, `enable_dinein`, `enable_reservations`, `enable_pos`, `menu_page_id`, `cart_page_id`, `checkout_page_id`, `track_page_id`, `primary_color`, `dark_color`, `restaurant_name` |

---

## DD_Helpers (Global Functions)

| Aspect | Detail |
|---|---|
| File | `dishdash-core/class-dd-helpers.php` |
| Pattern | Global functions (all wrapped in `if (!function_exists())`) |
| Key functions | `dd_price(amount)`, `dd_generate_order_number()`, `dd_get_branches()`, `dd_get_branch(id)`, `dd_get_current_branch_id()`, `dd_is_enabled(feature)`, `dd_valid_order_type(type)`, `dd_order_status_transitions()`, `dd_order_status_label(status)`, `dd_log(data, label)`, `dd_menu_url()`, `dd_cart_url()`, `dd_checkout_url()`, `dd_track_url()` |
| Database reads | `{prefix}dishdash_branches` (via `dd_get_branches()`, `dd_get_branch()`) |
| Cookie reads | `dd_branch_id` (via `dd_get_current_branch_id()`) |

---

## DD_Admin

| Aspect | Detail |
|---|---|
| File | `admin/class-dd-admin.php` |
| Extends | `DD_Module` |
| Module ID | `admin` |
| Admin submenu | Top-level menu only — all feature submenus are registered by their own modules |
| Shortcodes registered | None |
| Hooks listened to | `admin_menu` → `register_admin_menus()`, `admin_enqueue_scripts` → `enqueue_admin_assets()` |
| Hooks fired | None |
| AJAX actions registered | None |
| Admin pages owned | `dish-dash` (Dashboard), `dish-dash-menu` (redirect to post editor), `dish-dash-reservations` (coming soon), `dish-dash-delivery` (coming soon), `dish-dash-branches` (coming soon), `dish-dash-pos` (coming soon), `dish-dash-analytics` (coming soon) |
| Templates | `admin/pages/dashboard.php`, `admin/pages/coming-soon.php` |
| Database tables owned | None |
| WP options owned | None (reads only: `dish_dash_menu_page_id`, `dish_dash_google_maps_key`) |
| Depends on (modules) | NONE |
| Depends on (plugins) | None |

---

## DD_Template_Module

| Aspect | Detail |
|---|---|
| File | `modules/template/class-dd-template-module.php` |
| Extends | `DD_Module` |
| Module ID | `template` |
| Admin submenu | `dish-dash-settings` (Plugin Settings) |
| Shortcodes registered | None |
| Hooks listened to | `admin_menu`, `admin_init` (save settings), `admin_enqueue_scripts`, `theme_page_templates` (filter), `template_include` (filter), `after_setup_theme` (register nav menus), `wp_enqueue_scripts` (assets + theme conflict removal), `wp_footer` (inject cart sidebar + global footer + product modal), `wp_body_open` (inject global header), `wp_head` (inject header styles), `init` (remove theme header hooks) |
| Hooks fired | None directly (removes Astra hooks via `remove_action`) |
| AJAX actions registered | None |
| Page template | `templates/page-dishdash.php` registered as "Dish Dash Full Page" |
| Nav menus registered | `dd-primary` (Main Navigation), `dd-footer` (Footer Navigation) |
| Global header injected on | `/reserve-table/`, `/cart-dd/`, `/checkout-dd/`, `/restaurant-menu/`, `/my-account/`, `/my-restaurant-account/`, `/track-order/` |
| Frontend assets enqueued | `assets/css/theme.css`, `assets/css/cart.css`, `assets/css/menu-page.css` (conditional), `assets/js/frontend.js`, `assets/js/cart.js` |
| Localized JS data | `dishDash` (window object) with ajaxUrl, nonce, cartUrl, checkoutUrl, trackUrl, currency settings, page IDs, primary color |
| Database tables owned | None |
| WP options owned | See Admin > Settings URL in ARCHITECTURE.md |
| WP options read | `dish_dash_menu_page_id`, `dish_dash_primary_color`, `dish_dash_dark_color`, `dish_dash_restaurant_name`, `dish_dash_logo_url`, plus all homepage section settings |
| Depends on (modules) | NONE |
| Depends on (plugins) | None (WooCommerce-aware but degrades gracefully) |

---

## DD_Homepage_Module

| Aspect | Detail |
|---|---|
| File | `modules/homepage/class-dd-homepage-module.php` |
| Extends | `DD_Module` |
| Module ID | `homepage` |
| Admin submenu | `dish-dash-homepage` (Homepage Settings) |
| Shortcodes registered | None |
| Hooks listened to | `admin_menu`, `admin_init` (save settings), `admin_enqueue_scripts` |
| Hooks fired | None |
| AJAX actions registered | `dd_get_reviews` (public — fetches Google Places reviews for preview) |
| Database tables owned | None |
| WP options owned | `dd_header_show_track_order`, `dd_header_show_cart`, `dish_dash_hero_title`, `dish_dash_hero_subtitle`, `dd_hero_bg_image`, `dd_hero_btn{1,2,3}_{label,url,style}`, `dd_hero_chip_{1-4}_{text,show}`, `dd_categories_show`, `dd_categories_title`, `dd_categories_count`, `dd_featured_show`, `dd_featured_title`, `dd_featured_count`, `dd_featured_tag`, `dd_featured_chip_tags[]`, `dd_selcat_{show,title,count,ids[]}`, `dd_reserve_bg_image`, `dd_reviews_{show,title,source,google_place_id,google_api_key,min_rating,count,manual}`, `dd_footer_show_{description,social,explore,contact,hours}`, `dish_dash_opening_hours` |
| Depends on (modules) | NONE |
| Depends on (plugins) | WooCommerce (product_cat taxonomy for category settings) |

---

## DD_Menu_Module

| Aspect | Detail |
|---|---|
| File | `modules/menu/class-dd-menu-module.php` |
| Extends | `DD_Module` |
| Module ID | `menu` |
| Admin submenu | None |
| Shortcodes registered | `[dish_dash_menu]`, `[dish_dash_cart]`, `[dish_dash_checkout]`, `[dish_dash_reserve]`, `[dish_dash_track]` ⚠️, `[dish_dash_account]` ⚠️ |
| Hooks listened to | `wp_enqueue_scripts` (conditional menu assets), `wp_ajax_dd_menu_load_products`, `wp_ajax_nopriv_dd_menu_load_products` |
| Hooks fired | `dish_dash_before_menu_render(args)` (before menu query), `apply_filters('dish_dash_menu_query_args', args)` (allows modifying WP_Query) |
| AJAX actions registered | `dd_menu_load_products` (public — paginated product grid for desktop menu page) |
| Templates | `templates/menu/grid.php`, `templates/cart/cart.php`, `templates/checkout/checkout.php` |
| Database tables owned | None (reads WooCommerce product tables) |
| WP options read | `dish_dash_menu_page_id`, `dish_dash_primary_color`, `dish_dash_dark_color`, `dish_dash_restaurant_name` |
| Depends on (modules) | NONE |
| Depends on (plugins) | WooCommerce (`product` post type, `product_cat` taxonomy, `wc_get_product()`) |
| ⚠️ Shortcode conflict | `[dish_dash_track]` and `[dish_dash_account]` are also registered by `DD_Orders_Module` — see Cross-Module Audit |

---

## DD_Orders_Module

| Aspect | Detail |
|---|---|
| File | `modules/orders/class-dd-orders-module.php` |
| Extends | `DD_Module` |
| Module ID | `orders` |
| Admin submenu | `dish-dash-orders` (Orders) |
| Shortcodes registered | `[dish_dash_track]` ⚠️, `[dish_dash_account]` ⚠️ |
| Hooks listened to | `rest_api_init`, `woocommerce_order_status_completed` → `wc_payment_completed()`, `woocommerce_order_status_cancelled` → `wc_payment_cancelled()`, `admin_enqueue_scripts` |
| Hooks fired | `dish_dash_order_placed(order_id, order_data)`, `dish_dash_order_status_changed(order_id, old_status, new_status)`, `dish_dash_order_delivered(order_id)` |
| AJAX actions registered | `dd_place_order` (public), `dd_get_order` (public), `dd_cancel_order` (public), `dd_update_status` (admin only — requires `dd_manage_orders` capability) |
| REST API routes | `GET /dish-dash/v1/orders`, `GET /dish-dash/v1/orders/{id}`, `PUT /dish-dash/v1/orders/{id}/status` |
| Templates | `admin/pages/orders.php` |
| Database tables owned | `{prefix}dishdash_orders`, `{prefix}dishdash_order_items` |
| WP options read | Via `DD_Settings` — currency, tax rate, order prefix, order counter |
| Depends on (modules) | NONE |
| Depends on (plugins) | WooCommerce (creates/links WC orders via `wc_create_order()`, hooks into WC status events) |
| Order status machine | `pending → confirmed → preparing → ready → out_for_delivery → delivered` / `cancelled` (any non-delivered state) |

---

## DD_Cart (Static Class — Not a Module)

| Aspect | Detail |
|---|---|
| File | `modules/orders/class-dd-cart.php` |
| Pattern | Static class — instantiated and registered via `DD_Cart::register_ajax()` from `DD_Loader` |
| Extends | Nothing (not a `DD_Module`) |
| Admin submenu | None |
| Shortcodes registered | None |
| Hooks listened to | None (registration happens via `DD_Ajax::register()` calls in `register_ajax()`) |
| Hooks fired | None |
| AJAX actions registered | `dd_cart_add` (public), `dd_cart_update` (public), `dd_cart_remove` (public), `dd_cart_get` (public), `dd_cart_clear` (public) |
| Storage mechanism | WP transients: `dd_cart_user_{user_id}` (logged-in, 3-day TTL) or `dd_cart_{session_id}` (guest, 3-day TTL) |
| Session cookie | `dd_session` (32-char hash, 7-day TTL, set on first cart interaction) |
| Cart key | MD5 hash of `product_id + variation + addons JSON` — enables deduplication |
| Database tables owned | None (transient-based) |
| WP options read | `dish_dash_tax_rate` (via `DD_Settings`) |
| Depends on (modules) | NONE |
| Depends on (plugins) | None (standalone transient cart — does NOT use WooCommerce session) |

---

## DD_Auth_Module

| Aspect | Detail |
|---|---|
| File | `modules/auth/class-dd-auth-module.php` |
| Extends | `DD_Module` |
| Module ID | `auth` |
| Admin submenu | `dish-dash-auth` (Auth & Email Settings) |
| Shortcodes registered | None |
| Hooks listened to | `admin_menu`, `admin_init` (save settings), `admin_enqueue_scripts`, `wp_head` (priority 5, inject auth JS data), `phpmailer_init` (configure SMTP), `wp_footer` (inject auth modal + email verify banner), `wp_ajax_nopriv_dd_login` + `wp_ajax_dd_login`, `wp_ajax_nopriv_dd_register` + `wp_ajax_dd_register`, `wp_ajax_dd_logout`, `wp_ajax_dd_test_email` (admin only), `init` (handle Google OAuth callback, handle email verification query params) |
| Hooks fired | `wp_login` (WordPress core action — fires on successful auth module login) |
| AJAX actions registered | `dd_login` (public), `dd_register` (public), `dd_logout` (logged-in), `dd_test_email` (admin) |
| Google OAuth flow | Intercepts `?dd_google_callback=1` on `init` → exchanges code → gets userinfo → WP login/register |
| Email verification | Intercepts `?dd_verify_email={token}` on `init` → validates → marks user verified |
| SMTP | Configures `phpmailer_init` with stored SMTP credentials |
| Database tables owned | None |
| User meta written | `dd_email_verified` (boolean), `dd_google_id` (Google user ID) |
| WP options owned | `dd_google_client_id`, `dd_google_client_secret`, `dd_smtp_host`, `dd_smtp_port`, `dd_smtp_username`, `dd_smtp_password`, `dd_smtp_encryption`, `dd_noreply_email`, `dd_from_name` |
| Depends on (modules) | NONE |
| Depends on (plugins) | None |

---

## DD_Customers_Module

| Aspect | Detail |
|---|---|
| File | `modules/customers/class-dd-customers-module.php` |
| Extends | `DD_Module` |
| Module ID | `customers` |
| Admin submenu | `dish-dash-customers` (Customers CRM) |
| Shortcodes registered | None |
| Hooks listened to | `admin_menu`, `admin_enqueue_scripts`, `wp_ajax_dd_save_customer` (admin only) |
| Hooks fired | None |
| AJAX actions registered | `dd_save_customer` (admin only) |
| Customer tiers | New (0 orders), Regular (≥1 order), VIP (≥ 50,000 RWF lifetime), Champion (≥ 200,000 RWF lifetime) |
| Data sources | `get_users()`, `wc_get_orders()`, `{prefix}dishdash_reservations` table |
| User meta read | `billing_phone`, `billing_address_1`, `dd_phone`, `dd_address`, `dd_birthday` |
| User meta written | `dd_phone`, `dd_address`, `dd_birthday` |
| Database tables owned | None (reads `{prefix}dishdash_reservations`) |
| WP options owned | None |
| Depends on (modules) | NONE |
| Depends on (plugins) | WooCommerce (`wc_get_orders()` for spend calculation — degrades gracefully if absent) |

---

## DD_Tracking_Module

| Aspect | Detail |
|---|---|
| File | `modules/tracking/class-dd-tracking-module.php` |
| Extends | `DD_Module` |
| Module ID | `tracking` |
| Admin submenu | None |
| Shortcodes registered | None |
| Hooks listened to | `wp_enqueue_scripts` (frontend only) |
| Hooks fired | None |
| AJAX actions registered | `dd_track_event` (public), `dd_get_recent_searches` (public), `dd_get_search_products` (public) |
| Frontend asset | `assets/js/tracking.js` — localized as `DDTrackConfig` with ajaxUrl, nonce, sessionId |
| Session cookie | `dd_session` (90-day TTL — shared with `DD_Cart` but set independently here) |
| Allowed event types | `view_product`, `view_category`, `search`, `add_to_cart`, `remove_from_cart`, `order`, `reorder`, `page_view` |
| Database tables owned | `{prefix}dishdash_user_events`, `{prefix}dishdash_user_profiles` |
| WP options owned | None |
| Depends on (modules) | NONE |
| Depends on (plugins) | None |

---

## DD_REST_API

| Aspect | Detail |
|---|---|
| File | `api/class-dd-rest-api.php` |
| Pattern | Singleton registered on `rest_api_init` |
| Purpose | API namespace registration + response helpers |
| Namespace | `dish-dash/v1` |
| Hooks fired | `dish_dash_register_rest_routes(namespace)` — allows modules to register their own routes |
| Response helpers | `success(data, message, status)` → `WP_REST_Response`, `error(code, message, status)` → `WP_Error` |
| Note | Routes themselves are registered by `DD_Orders_Module` (and future modules via the action hook) |

---

## All AJAX Actions Reference

| Action | Module | Public? | Notes |
|---|---|---|---|
| `dd_cart_add` | `DD_Cart` | Yes | |
| `dd_cart_update` | `DD_Cart` | Yes | |
| `dd_cart_remove` | `DD_Cart` | Yes | |
| `dd_cart_get` | `DD_Cart` | Yes | |
| `dd_cart_clear` | `DD_Cart` | Yes | |
| `dd_place_order` | `DD_Orders_Module` | Yes | |
| `dd_get_order` | `DD_Orders_Module` | Yes | |
| `dd_cancel_order` | `DD_Orders_Module` | Yes | |
| `dd_update_status` | `DD_Orders_Module` | Admin only | Requires `dd_manage_orders` capability |
| `dd_menu_load_products` | `DD_Menu_Module` | Yes | Paginated product grid (desktop menu page) |
| `dd_get_reviews` | `DD_Homepage_Module` | Yes | Google Places API proxy |
| `dd_track_event` | `DD_Tracking_Module` | Yes | |
| `dd_get_recent_searches` | `DD_Tracking_Module` | Yes | |
| `dd_get_search_products` | `DD_Tracking_Module` | Yes | |
| `dd_login` | `DD_Auth_Module` | Yes | |
| `dd_register` | `DD_Auth_Module` | Yes | |
| `dd_logout` | `DD_Auth_Module` | Logged-in | |
| `dd_test_email` | `DD_Auth_Module` | Admin only | |
| `dd_save_customer` | `DD_Customers_Module` | Admin only | |

---

## All Custom Hooks Reference

### Actions (do_action)

| Hook | Fired by | Arguments | Purpose |
|---|---|---|---|
| `dish_dash_loaded` | `DD_Loader` | none | All modules initialized — safe to use plugin features |
| `dish_dash_order_placed` | `DD_Orders_Module` | `$order_id`, `$order_data` | New order saved to DB |
| `dish_dash_order_status_changed` | `DD_Orders_Module` | `$order_id`, `$old_status`, `$new_status` | Any status transition |
| `dish_dash_order_delivered` | `DD_Orders_Module` | `$order_id` | Status reached 'delivered' |
| `dish_dash_reservation_created` | (reservations module — planned) | `$reservation_id` | New table reservation |
| `dish_dash_before_menu_render` | `DD_Menu_Module` | `$args` | Before WP_Query for menu |
| `dish_dash_register_rest_routes` | `DD_REST_API` | `$namespace` | Modules can add REST routes |

### Filters (apply_filters)

| Filter | Applied by | Arguments | Purpose |
|---|---|---|---|
| `dish_dash_menu_query_args` | `DD_Menu_Module` | `$args` (WP_Query args) | Modify product query before execution |
| `dish_dash_order_data` | `DD_Orders_Module` | `$data`, `$order_id` | Modify order data before DB insert |
| `dish_dash_delivery_fee` | `DD_Orders_Module` | `$fee`, `$zone_id`, `$subtotal` | Override delivery fee calculation |
| `dish_dash_price` | `dd_price()` helper | `$formatted`, `$raw` | Modify price display string |
| `dish_dash_email_template` | `DD_Orders_Module` | `$html`, `$type`, `$data` | Override email HTML output |
| `theme_page_templates` | `DD_Template_Module` | `$templates` | Adds "Dish Dash Full Page" to WP template list |
| `template_include` | `DD_Template_Module` | `$template` | Loads `page-dishdash.php` when template selected |

---

## Cross-Module Communication Audit

Searched for direct method calls from one module to another (e.g., `$this->some_module->method()`, `DD_SomeModule::method()`).

### ✅ CLEAN — No direct module-to-module method calls found

All cross-module communication uses WordPress hooks as required by the architecture rules.

---

### ❌ TECH DEBT: Duplicate Shortcode Registration

**Location 1:** `modules/menu/class-dd-menu-module.php`
```php
add_shortcode( 'dish_dash_track', [ $this, 'shortcode_track' ] );
add_shortcode( 'dish_dash_account', [ $this, 'shortcode_account' ] );
```

**Location 2:** `modules/orders/class-dd-orders-module.php`
```php
add_shortcode( 'dish_dash_track', [ $this, 'render_track_page' ] );
add_shortcode( 'dish_dash_account', [ $this, 'render_account_page' ] );
```

**Violation:** Two modules claim ownership of the same shortcodes. WordPress's `add_shortcode()` allows silent override — the last call wins. The winning module depends entirely on load order in `DD_Loader`, which is never guaranteed.

**Correct ownership:**
- `[dish_dash_track]` should be owned by `DD_Orders_Module` (order tracking belongs in orders)
- `[dish_dash_account]` should be owned by `DD_Auth_Module` (account = auth feature)
- Remove both from `DD_Menu_Module`

---

### ❌ TECH DEBT: Dual Cart Implementations

**Location 1:** `assets/js/menu.js`
- Maintains its own localStorage cart under key `dd_cart`
- Fires custom event `dd_cart_updated`
- **This is a client-side-only cart** — never syncs with server

**Location 2:** `assets/js/cart.js` + `modules/orders/class-dd-cart.php`
- Server-side cart via WP transients
- All operations via AJAX (`dd_cart_add`, etc.)
- **This is the canonical cart**

**Violation:** Any page using the `[dish_dash_menu]` shortcode (not the menu page) uses the localStorage-only cart. Any page using the full page template or `/restaurant-menu/` uses the AJAX cart. A user who adds items on the shortcode widget page will see different cart state than the cart sidebar.

**Correct behavior:** All Add-to-Cart interactions must call `dd_cart_add` AJAX. Remove localStorage cart logic from `menu.js` entirely.

---

*Last audited: v3.1.13, April 2026*
