<?php
/**
 * File:    install.php
 * Purpose: Canonical installer — the single source of truth for all custom DB
 *          tables. Creates/upgrades all 14 tables via dbDelta(), writes default
 *          wp_options, creates WP user roles, and auto-creates the six required
 *          WordPress pages (menu, cart, checkout, track, account, reserve).
 *
 * This is the ONLY file that defines DB table schemas. Do not add CREATE TABLE
 * statements anywhere else. See CLAUDE.md "Schema Changes" for how to add a
 * new table or column.
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core)
 *   - dbDelta() (wp-admin/includes/upgrade.php, loaded inline)
 *   - DD_VERSION constant (defined in dish-dash.php before activation)
 *
 * Dependents (files that need this):
 *   - dish-dash.php (register_activation_hook + auto-migration guard)
 *
 * DB tables created/upgraded (14 total):
 *   dishdash_branches, dishdash_orders, dishdash_order_items,
 *   dishdash_delivery_zones, dishdash_tables, dishdash_reservations,
 *   dishdash_pos_sessions, dishdash_analytics, dishdash_user_events,
 *   dishdash_user_profiles, dishdash_customers, dishdash_birthday_tokens,
 *   dishdash_reservation_refunds, dd_billing_payments
 *
 * WP options written (defaults only, add_option — never overwrites):
 *   dish_dash_currency, dish_dash_currency_symbol, dish_dash_tax_rate,
 *   dish_dash_order_prefix, dish_dash_enable_*, dish_dash_email_from
 *
 * Last modified: v3.4.92
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( class_exists( 'DD_Install' ) ) {
    return;
}

class DD_Install {

    /**
     * Main entry point called on activation hook.
     */
    public static function run(): void {
        self::create_tables();
        self::set_default_options();
        self::create_roles();
        self::create_pages();

        // Store the installed version so we can run upgrade
        // routines in future releases.
        update_option( 'dish_dash_version', DD_VERSION );
        update_option( 'dish_dash_installed_at', current_time( 'mysql' ) );
    }

    // ─────────────────────────────────────────
    //  DATABASE TABLES
    // ─────────────────────────────────────────

    /**
     * Create or upgrade all 13 Dish Dash custom DB tables.
     * Uses dbDelta() — safe to call multiple times (only adds, never drops).
     * Called on activation and by the auto-migration guard in dish-dash.php.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── 1. dishdash_branches ─────────────────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_branches (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name          VARCHAR(255)    NOT NULL,
                slug          VARCHAR(100)    NOT NULL,
                address       TEXT            NOT NULL DEFAULT '',
                latitude      DECIMAL(10,8)            DEFAULT NULL,
                longitude     DECIMAL(11,8)            DEFAULT NULL,
                phone         VARCHAR(50)     NOT NULL DEFAULT '',
                email         VARCHAR(255)    NOT NULL DEFAULT '',
                opening_hours LONGTEXT                 DEFAULT NULL,
                min_order     DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                is_active     TINYINT(1)      NOT NULL DEFAULT 1,
                settings      LONGTEXT                 DEFAULT NULL,
                created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   slug (slug),
                KEY          is_active (is_active)
            ) $charset_collate;
        " );

        // ── 2. dishdash_orders ───────────────────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_orders (
                id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_number         VARCHAR(50)     NOT NULL,
                wc_order_id          BIGINT UNSIGNED          DEFAULT NULL,
                branch_id            BIGINT UNSIGNED NOT NULL DEFAULT 1,
                customer_id          BIGINT UNSIGNED          DEFAULT NULL,
                customer_name        VARCHAR(255)    NOT NULL DEFAULT '',
                customer_phone       VARCHAR(50)     NOT NULL DEFAULT '',
                customer_email       VARCHAR(255)    NOT NULL DEFAULT '',
                order_type           ENUM('delivery','pickup','dine-in','pos') NOT NULL DEFAULT 'delivery',
                status               VARCHAR(50)     NOT NULL DEFAULT 'pending',
                subtotal             DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                delivery_fee         DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                discount             DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                tip                  DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                tax                  DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                total                DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                payment_method       VARCHAR(100)    NOT NULL DEFAULT '',
                payment_status       VARCHAR(50)     NOT NULL DEFAULT 'unpaid',
                scheduled_at         DATETIME                 DEFAULT NULL,
                delivery_address     TEXT                     DEFAULT NULL,
                special_instructions TEXT                     DEFAULT NULL,
                pos_session_id       BIGINT                   DEFAULT NULL,
                table_id             BIGINT                   DEFAULT NULL,
                created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                confirmed_at         DATETIME                 NULL,
                ready_at             DATETIME                 NULL,
                delivered_at         DATETIME                 NULL,
                cancelled_at         DATETIME                 NULL,
                is_test              TINYINT(1)      NOT NULL DEFAULT 0,
                platform_fee         INT UNSIGNED    NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY   order_number (order_number),
                KEY          branch_id (branch_id),
                KEY          customer_id (customer_id),
                KEY          status (status),
                KEY          created_at (created_at),
                KEY          is_test (is_test),
                KEY          branch_status (branch_id, status)
            ) $charset_collate;
        " );

        // ── 3. dishdash_order_items ──────────────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_order_items (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id     BIGINT UNSIGNED NOT NULL,
                menu_item_id BIGINT UNSIGNED NOT NULL,
                item_name    VARCHAR(255)    NOT NULL DEFAULT '',
                quantity     INT UNSIGNED    NOT NULL DEFAULT 1,
                unit_price   DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                addons       LONGTEXT                 DEFAULT NULL,
                variation    VARCHAR(100)             DEFAULT NULL,
                special_note TEXT                     DEFAULT NULL,
                line_total   DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                PRIMARY KEY  (id),
                KEY          order_id (order_id),
                KEY          menu_item_id (menu_item_id)
            ) $charset_collate;
        " );

        // ── 4. dishdash_delivery_zones ───────────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_delivery_zones (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                branch_id      BIGINT UNSIGNED NOT NULL,
                name           VARCHAR(255)    NOT NULL DEFAULT '',
                zone_type      ENUM('radius','polygon','zipcode') NOT NULL DEFAULT 'radius',
                zone_data      LONGTEXT        NOT NULL,
                delivery_fee   DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                min_order      DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                estimated_time INT UNSIGNED    NOT NULL DEFAULT 30,
                is_active      TINYINT(1)      NOT NULL DEFAULT 1,
                PRIMARY KEY  (id),
                KEY          branch_id (branch_id),
                KEY          is_active (is_active)
            ) $charset_collate;
        " );

        // ── 5. dishdash_tables ───────────────────────────────────────────────
        // Schema matches live DB exactly (verified Q1 2026-06-02).
        // id is INT UNSIGNED (not BIGINT). status is ENUM. All 10 live columns present.
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_tables (
                id         INT UNSIGNED        NOT NULL AUTO_INCREMENT,
                branch_id  BIGINT UNSIGNED     NOT NULL,
                name       VARCHAR(100)        NOT NULL,
                capacity   TINYINT(3) UNSIGNED NOT NULL DEFAULT 2,
                qr_code    VARCHAR(255)                 DEFAULT NULL,
                status     ENUM('available','occupied','reserved') NOT NULL DEFAULT 'available',
                is_active  TINYINT(1)          NOT NULL DEFAULT 1,
                section    VARCHAR(20)         NOT NULL DEFAULT 'indoor',
                sort_order SMALLINT(6)         NOT NULL DEFAULT 0,
                created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY          branch_id (branch_id),
                KEY          status (status)
            ) $charset_collate;
        " );

        // ── 6. dishdash_reservations ─────────────────────────────────────────
        // 29-column union schema — both old (branch_id, customer_name/phone/email,
        // party_size, reservation_date/time, notes) and new (booking_ref, whatsapp,
        // session, guests, deposit_*) columns preserved per no-drop policy.
        // duration_minutes present on live DB but absent from both old installer files.
        // Schema verified Q2 2026-06-02.
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_reservations (
                id                BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
                table_id          INT UNSIGNED                 DEFAULT NULL,
                branch_id         BIGINT UNSIGNED     NOT NULL,
                customer_name     VARCHAR(255)        NOT NULL DEFAULT '',
                customer_phone    VARCHAR(50)         NOT NULL DEFAULT '',
                customer_email    VARCHAR(255)        NOT NULL DEFAULT '',
                party_size        INT UNSIGNED        NOT NULL DEFAULT 2,
                reservation_date  DATE                NOT NULL,
                reservation_time  TIME                NOT NULL,
                status            VARCHAR(20)         NOT NULL DEFAULT 'pending',
                notes             TEXT                         DEFAULT NULL,
                created_at        DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                duration_minutes  INT UNSIGNED        NOT NULL DEFAULT 90,
                booking_ref       VARCHAR(20)         NOT NULL DEFAULT '',
                customer_id       BIGINT UNSIGNED              DEFAULT NULL,
                date              DATE                NOT NULL,
                time              VARCHAR(5)          NOT NULL DEFAULT '',
                session           VARCHAR(10)         NOT NULL DEFAULT '',
                guests            TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
                name              VARCHAR(100)        NOT NULL DEFAULT '',
                whatsapp          VARCHAR(30)         NOT NULL DEFAULT '',
                special_requests  TEXT                         DEFAULT NULL,
                source            VARCHAR(30)         NOT NULL DEFAULT '',
                updated_at        DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deposit_required  TINYINT(1)          NOT NULL DEFAULT 0,
                deposit_amount    INT UNSIGNED        NOT NULL DEFAULT 0,
                deposit_status    VARCHAR(20)         NOT NULL DEFAULT 'none',
                deposit_paid_at   DATETIME                     DEFAULT NULL,
                payment_ref       VARCHAR(100)                 DEFAULT NULL,
                is_test           TINYINT(1)          NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY   booking_ref (booking_ref),
                KEY          table_id (table_id),
                KEY          branch_id (branch_id),
                KEY          reservation_date (reservation_date),
                KEY          status (status),
                KEY          customer_id (customer_id),
                KEY          date (date),
                KEY          is_test (is_test)
            ) $charset_collate;
        " );

        // ── 7. dishdash_pos_sessions ─────────────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_pos_sessions (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                branch_id    BIGINT UNSIGNED NOT NULL,
                cashier_id   BIGINT UNSIGNED NOT NULL,
                cash_float   DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                total_cash   DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                total_card   DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                total_orders INT UNSIGNED    NOT NULL DEFAULT 0,
                opened_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                closed_at    DATETIME                 DEFAULT NULL,
                notes        TEXT                     DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY          branch_id (branch_id),
                KEY          cashier_id (cashier_id)
            ) $charset_collate;
        " );

        // ── 8. dishdash_analytics ────────────────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_analytics (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                branch_id       BIGINT UNSIGNED NOT NULL,
                stat_date       DATE            NOT NULL,
                orders_count    INT UNSIGNED    NOT NULL DEFAULT 0,
                revenue         DECIMAL(12,2)   NOT NULL DEFAULT '0.00',
                avg_order_value DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                top_items       LONGTEXT                 DEFAULT NULL,
                order_types     LONGTEXT                 DEFAULT NULL,
                data            LONGTEXT                 DEFAULT NULL,
                created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   branch_date (branch_id, stat_date),
                KEY          stat_date (stat_date)
            ) $charset_collate;
        " );

        // ── 9. dishdash_user_events ──────────────────────────────────────────
        // AI Foundation — records every user interaction.
        // Feeds the behavior engine and user profile builder.
        // event_type values: view_product, view_category, search,
        //                    add_to_cart, remove_from_cart, order, page_view
        // schema_version tracks the meta JSON shape version per event group.
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_user_events (
                id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
                user_id        BIGINT UNSIGNED            DEFAULT NULL,
                session_id     VARCHAR(64)       NOT NULL DEFAULT '',
                event_type     VARCHAR(50)       NOT NULL DEFAULT '',
                product_id     BIGINT UNSIGNED            DEFAULT NULL,
                category_id    BIGINT UNSIGNED            DEFAULT NULL,
                meta           LONGTEXT                   DEFAULT NULL,
                schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                created_at     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY          user_id (user_id),
                KEY          session_id (session_id),
                KEY          event_type (event_type),
                KEY          product_id (product_id),
                KEY          category_id (category_id),
                KEY          created_at (created_at),
                KEY          idx_event_type_schema (event_type, schema_version)
            ) $charset_collate;
        " );

        // ── 10. dishdash_user_profiles ───────────────────────────────────────
        // AI Foundation — computed profile per user/session.
        // Updated automatically as events are tracked.
        // One row per user_id (logged in) or session_id (guest).
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_user_profiles (
                id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id             BIGINT UNSIGNED          DEFAULT NULL,
                session_id          VARCHAR(64)     NOT NULL DEFAULT '',
                favorite_items      LONGTEXT                 DEFAULT NULL,
                favorite_categories LONGTEXT                 DEFAULT NULL,
                avg_order_value     DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                order_count         INT UNSIGNED    NOT NULL DEFAULT 0,
                order_times         LONGTEXT                 DEFAULT NULL,
                last_orders         LONGTEXT                 DEFAULT NULL,
                last_seen           DATETIME                 DEFAULT NULL,
                updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   user_id (user_id),
                KEY          session_id (session_id),
                KEY          updated_at (updated_at)
            ) $charset_collate;
        " );

        // ── 11. dishdash_customers ───────────────────────────────────────────
        // WhatsApp-based customer identity. whatsapp is the primary identifier.
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_customers (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                whatsapp          VARCHAR(20)     NOT NULL DEFAULT '',
                name              VARCHAR(255)    NOT NULL DEFAULT '',
                delivery_address  TEXT                     DEFAULT NULL,
                birthday          DATE                     DEFAULT NULL,
                dd_birthday_asked TINYINT(1)      NOT NULL DEFAULT 0,
                total_orders      INT UNSIGNED    NOT NULL DEFAULT 0,
                total_spent       DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                first_order_at    DATETIME                 DEFAULT NULL,
                last_order_at     DATETIME                 DEFAULT NULL,
                created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   whatsapp (whatsapp)
            ) $charset_collate;
        " );

        // ── 12. dishdash_birthday_tokens ─────────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_birthday_tokens (
                id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                token       VARCHAR(64)     NOT NULL DEFAULT '',
                customer_id BIGINT UNSIGNED NOT NULL,
                used        TINYINT(1)      NOT NULL DEFAULT 0,
                expires_at  DATETIME        NOT NULL,
                created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   token (token),
                KEY          customer_id (customer_id)
            ) $charset_collate;
        " );

        // ── 13. dishdash_reservation_refunds ─────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_reservation_refunds (
                id             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                reservation_id BIGINT UNSIGNED NOT NULL,
                amount         INT UNSIGNED    NOT NULL DEFAULT 0,
                reason         VARCHAR(255)    NOT NULL DEFAULT '',
                refunded_at    DATETIME                 DEFAULT NULL,
                created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY          reservation_id (reservation_id)
            ) $charset_collate;
        " );

        // ── 14. dd_billing_payments ──────────────────────────────────────────
        // Tracks which billing months have been marked as paid by the restaurant.
        // One row per month per restaurant (single-tenant for now).
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dd_billing_payments (
                id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                month      VARCHAR(7)      NOT NULL,
                amount     INT UNSIGNED    NOT NULL DEFAULT 0,
                paid       TINYINT(1)      NOT NULL DEFAULT 0,
                paid_at    DATETIME                 DEFAULT NULL,
                notes      VARCHAR(255)    NOT NULL DEFAULT '',
                created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   month (month)
            ) $charset_collate;
        " );

        // ── Migration: add is_test to reservations if missing ─────────────────
        $cols = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}dishdash_reservations" );
        if ( ! in_array( 'is_test', $cols ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}dishdash_reservations ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0, ADD KEY is_test (is_test)" );
        }
    }

    // ─────────────────────────────────────────
    //  DEFAULT OPTIONS
    // ─────────────────────────────────────────
    private static function set_default_options(): void {
        $defaults = [
            'dish_dash_currency'          => 'USD',
            'dish_dash_currency_symbol'   => '$',
            'dish_dash_currency_position' => 'before', // before | after
            'dish_dash_tax_rate'          => '0',
            'dish_dash_tax_label'         => 'Tax',
            'dish_dash_min_order'         => '0',
            'dish_dash_order_prefix'      => 'DD-',
            'dish_dash_google_maps_key'   => '',
            'dish_dash_claude_api_key'    => '',
            'dish_dash_enable_pickup'     => '1',
            'dish_dash_enable_delivery'   => '1',
            'dish_dash_enable_dinein'     => '1',
            'dish_dash_enable_reservations' => '1',
            'dish_dash_enable_pos'        => '1',
            'dish_dash_email_from_name'   => get_bloginfo( 'name' ),
            'dish_dash_email_from'        => get_option( 'admin_email' ),
        ];

        foreach ( $defaults as $key => $value ) {
            // add_option does nothing if the option already exists.
            add_option( $key, $value );
        }
    }

    // ─────────────────────────────────────────
    //  USER ROLES
    // ─────────────────────────────────────────
    private static function create_roles(): void {
        self::register_roles();
    }

    /**
     * Defines the 2 Dish Dash roles and grants admin caps.
     * Safe to call on every load — only ADDS roles/caps that don't exist.
     * Used by both activation and migrate_roles_v2().
     */
    public static function register_roles(): void {

        // Restaurant Owner — full Dish Dash access, no WP system/admin access.
        add_role( 'dd_restaurant_owner', __( 'Restaurant Owner', 'dish-dash' ), [
            'read'                     => true,
            'dd_manage_orders'         => true,
            'dd_manage_menu'           => true,
            'dd_manage_reservations'   => true,
            'dd_view_analytics'        => true,
            'dd_view_billing'          => true,
            'dd_view_customers'        => true,
            'dd_manage_brand_identity' => true,
            'dd_manage_homepage'       => true,
            'dd_manage_template'       => true,
            'dd_manage_settings'       => true,
            'dd_view_activity_log'     => true,
        ] );

        // Restaurant Manager — day-to-day operations only.
        add_role( 'dd_restaurant_manager', __( 'Restaurant Manager', 'dish-dash' ), [
            'read'                   => true,
            'dd_manage_orders'       => true,
            'dd_manage_menu'         => true,
            'dd_manage_reservations' => true,
            'dd_view_customers'      => true,
        ] );

        // Grant all Dish Dash capabilities to site administrators.
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $caps = [
                'dd_manage_orders', 'dd_manage_menu', 'dd_manage_reservations',
                'dd_view_analytics', 'dd_view_billing', 'dd_view_customers',
                'dd_manage_brand_identity', 'dd_manage_homepage', 'dd_manage_template',
                'dd_manage_settings', 'dd_view_activity_log', 'dd_manage_auth',
            ];
            foreach ( $caps as $cap ) {
                $admin->add_cap( $cap );
            }
        }
    }

    /**
     * Migration: removes the obsolete dd_* roles and re-creates
     * dd_restaurant_manager with the new (reduced) capability set.
     * Runs once per site, tracked via dish_dash_roles_version option.
     */
    public static function migrate_roles_v2(): void {
        if ( get_option( 'dish_dash_roles_version' ) === '2' ) {
            return;
        }

        // Remove obsolete roles entirely.
        foreach ( [ 'dd_branch_manager', 'dd_cashier', 'dd_delivery_driver', 'dd_kitchen_staff' ] as $role ) {
            remove_role( $role );
        }

        // Re-create dd_restaurant_manager with the new, reduced cap set
        // (remove_role first since add_role is a no-op on existing roles).
        remove_role( 'dd_restaurant_manager' );

        self::register_roles();

        update_option( 'dish_dash_roles_version', '2' );
    }

    // ─────────────────────────────────────────
    //  AUTO-CREATE PAGES
    //  Creates placeholder pages with shortcodes
    //  so the admin can just assign a menu to them.
    // ─────────────────────────────────────────
    private static function create_pages(): void {
        $pages = [
            'dish_dash_menu_page_id' => [
                'title'   => 'Our Menu',
                'content' => '[dish_dash_menu]',
                'slug'    => 'restaurant-menu',
            ],
            'dish_dash_cart_page_id' => [
                'title'   => 'Your Cart',
                'content' => '[dish_dash_cart]',
                'slug'    => 'cart-dd',
            ],
            'dish_dash_checkout_page_id' => [
                'title'   => 'Checkout',
                'content' => '[dish_dash_checkout]',
                'slug'    => 'checkout-dd',
            ],
            'dish_dash_track_page_id' => [
                'title'   => 'Track Your Order',
                'content' => '[dish_dash_track]',
                'slug'    => 'track-order',
            ],
            'dish_dash_account_page_id' => [
                'title'   => 'My Account',
                'content' => '[dish_dash_account]',
                'slug'    => 'my-restaurant-account',
            ],
            'dish_dash_reserve_page_id' => [
                'title'   => 'Reserve a Table',
                'content' => '[dish_dash_reserve]',
                'slug'    => 'reserve-table',
            ],
        ];

        foreach ( $pages as $option_key => $page ) {
            // Skip if we already saved an ID for this page.
            if ( get_option( $option_key ) ) {
                continue;
            }

            // Check if a page with this slug already exists.
            $existing = get_page_by_path( $page['slug'] );
            if ( $existing ) {
                update_option( $option_key, $existing->ID );
                continue;
            }

            $page_id = wp_insert_post( [
                'post_title'   => $page['title'],
                'post_content' => $page['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => $page['slug'],
            ] );

            if ( $page_id && ! is_wp_error( $page_id ) ) {
                update_option( $option_key, $page_id );
            }
        }
    }
}
