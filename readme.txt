=== Dish Dash ===
Contributors: frisoftltd
Tags: restaurant, ordering, menu, delivery, reservations, woocommerce, food
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 3.4.9
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The smart restaurant ordering system that learns customer behavior — menus, cart, delivery, reservations, WhatsApp notifications, and AI analytics, built on WooCommerce.

== Description ==

Dish Dash is a behavior-driven restaurant ordering and management system built on WordPress and WooCommerce. It is designed to reduce time-to-order, increase repeat orders, and personalize the ordering experience through AI — without relying on third-party delivery marketplaces.

Dish Dash is NOT a generic WooCommerce storefront. It is a fully custom ordering experience with its own frontend templates, mobile-first UI, and a behavior tracking engine that collects data for AI-powered personalization.

**What makes Dish Dash different:**
* Commission-free — no 30% fees to Uber Eats or Glovo
* Behavior-driven — every user action is tracked and used to improve future orders
* AI-ready — collecting the data now so the AI layer can personalize later
* Mobile-first — built for Africa where ordering happens on phones, not desktops
* WhatsApp-native — order notifications and customer communication via WhatsApp

**Core Features (Live):**
* Mobile 3-screen menu UI — category list → product list → single product
* Desktop menu with category filter, live search, and deep links
* Cart panel with floating button, live totals, and delivery progress nudge
* Checkout with WhatsApp number as primary customer identity
* Threshold-based delivery fee calculation (free delivery above configurable amount)
* WhatsApp order notifications — admin receives full order, customer receives confirmation
* Birthday collection flow — second WhatsApp message after first order only
* Table reservations with WhatsApp notifications and admin management
* Customer profiles — tier system (Regular, VIP, Champion, Diamond) based on lifetime spend
* Behavior tracking engine — view_product, add_to_cart, search, cart_open, order, reservation_made, and more
* Google Reviews integration with 12-hour caching
* Email authentication with SMTP verification
* Opening hours system with split sessions and closed-state UI

**Coming in Future Phases:**
* Backend analytics dashboard — top products, peak hours, repeat customers, revenue trends
* AI Rules Engine — behavior-based personalization and smart nudges
* Loyalty & QR — points system and QR scan ordering
* SaaS Platform — multi-tenant white-label for other restaurants

== Installation ==

1. Upload the `dish-dash` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Ensure WooCommerce is installed and active
4. Go to **Dish Dash → Settings** to configure your restaurant details
5. Add menu items as WooCommerce products with categories
6. Add the shortcode `[dd_menu]` to your menu page

== Frequently Asked Questions ==

= Does this require WooCommerce? =
Yes. WooCommerce handles products, cart sessions, and payment processing. Dish Dash sits on top of WooCommerce as an ordering layer.

= What PHP version is required? =
PHP 8.2 or higher is required.

= Does this work with any WordPress theme? =
Dish Dash ships with its own custom theme (dish-dash-theme) and its own frontend templates. It is designed to run as a standalone ordering site, not alongside a generic WordPress theme.

= How does WhatsApp notification work? =
Dish Dash uses WhatsApp wa.me links (Mode A). When an order is placed, the customer is shown a WhatsApp link pre-filled with their order confirmation. Admin receives a separate notification. WhatsApp Business API (Mode B) is planned for a future release.

= Is customer data stored? =
Yes. Customer records are stored in a custom database table using WhatsApp number as the primary identity. Order history, delivery address, birthday (if provided), and spend tier are tracked per customer.

== Changelog ==

= 3.4.9 =
* Pre-Phase 5 audit fixes: frontend asset gating, N+1 query eliminated in menu grid, cross-module DB violation resolved, dead tracking schemas removed

= 3.4.8 =
* Phase 4 complete: reservations with WhatsApp notifications, deposit scaffolding, customer tier system, Google Reviews integration, auth module with email verification

= 3.2.12 =
* Phase 2 complete: mobile 3-screen menu UI, desktop menu polish, deep links, behavior tracking validated, DD_API data layer live

= 3.1.17 =
* Schema versioning, DD_API facade, event health check, 0% tracking failure rate

== Upgrade Notice ==

= 3.4.9 =
Audit fix release. No database changes. Safe to update.
