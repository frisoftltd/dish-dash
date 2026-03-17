<?php
/**
 * Dish Dash – Installer
 *
 * Runs on plugin activation. Creates all custom DB tables,
 * default options, and user roles.
 *
 * Safe to run multiple times — uses dbDelta() which only
 * applies changes, never drops existing data.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
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
    public static function create_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        // We load dbDelta helper.
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── dishdash_branches ────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_branches (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name         VARCHAR(255)    NOT NULL,
                slug         VARCHAR(100)    NOT NULL,
                address      TEXT            NOT NULL DEFAULT '',
                latitude     DECIMAL(10,8)   NOT NULL DEFAULT '0.00000000',
                longitude    DECIMAL(11,8)   NOT NULL DEFAULT '0.00000000',
                phone        VARCHAR(50)     NOT NULL DEFAULT '',
                email        VARCHAR(255)    NOT NULL DEFAULT '',
                opening_hours JSON,
                min_order    DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                is_active    TINYINT(1)      NOT NULL DEFAULT 1,
                settings     JSON,
                created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   slug (slug),
                KEY          is_active (is_active)
            ) $charset;
        " );

        // ── dishdash_orders ──────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_orders (
                id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_number        VARCHAR(50)     NOT NULL,
                wc_order_id         BIGINT UNSIGNED          DEFAULT NULL,
                branch_id           BIGINT UNSIGNED NOT NULL DEFAULT 1,
                customer_id         BIGINT UNSIGNED          DEFAULT NULL,
                customer_name       VARCHAR(255)    NOT NULL DEFAULT '',
                customer_phone      VARCHAR(50)     NOT NULL DEFAULT '',
                customer_email      VARCHAR(255)    NOT NULL DEFAULT '',
                order_type          VARCHAR(20)     NOT NULL DEFAULT 'delivery',
                status              VARCHAR(50)     NOT NULL DEFAULT 'pending',
                subtotal            DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                delivery_fee        DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                discount            DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                tip                 DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                tax                 DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                total               DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                payment_method      VARCHAR(100)    NOT NULL DEFAULT '',
                payment_status      VARCHAR(50)     NOT NULL DEFAULT 'unpaid',
                scheduled_at        DATETIME                 DEFAULT NULL,
                delivery_address    TEXT                     DEFAULT NULL,
                special_instructions TEXT                    DEFAULT NULL,
                pos_session_id      BIGINT                   DEFAULT NULL,
                table_id            BIGINT                   DEFAULT NULL,
                created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   order_number (order_number),
                KEY          branch_id (branch_id),
                KEY          customer_id (customer_id),
                KEY          status (status),
                KEY          created_at (created_at),
                KEY          branch_status (branch_id, status)
            ) $charset;
        " );

        // ── dishdash_order_items ─────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_order_items (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id        BIGINT UNSIGNED NOT NULL,
                menu_item_id    BIGINT UNSIGNED NOT NULL,
                item_name       VARCHAR(255)    NOT NULL DEFAULT '',
                quantity        INT UNSIGNED    NOT NULL DEFAULT 1,
                unit_price      DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                addons          JSON                     DEFAULT NULL,
                variation       VARCHAR(100)             DEFAULT NULL,
                special_note    TEXT                     DEFAULT NULL,
                line_total      DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                PRIMARY KEY  (id),
                KEY          order_id (order_id),
                KEY          menu_item_id (menu_item_id)
            ) $charset;
        " );

        // ── dishdash_delivery_zones ──────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_delivery_zones (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                branch_id       BIGINT UNSIGNED NOT NULL,
                name            VARCHAR(255)    NOT NULL DEFAULT '',
                zone_type       VARCHAR(20)     NOT NULL DEFAULT 'radius',
                zone_data       JSON            NOT NULL,
                delivery_fee    DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                min_order       DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                estimated_time  INT UNSIGNED    NOT NULL DEFAULT 30,
                is_active       TINYINT(1)      NOT NULL DEFAULT 1,
                PRIMARY KEY  (id),
                KEY          branch_id (branch_id),
                KEY          is_active (is_active)
            ) $charset;
        " );

        // ── dishdash_tables ──────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_tables (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                branch_id   BIGINT UNSIGNED NOT NULL,
                name        VARCHAR(100)    NOT NULL DEFAULT '',
                capacity    INT UNSIGNED    NOT NULL DEFAULT 2,
                qr_code     VARCHAR(255)             DEFAULT NULL,
                status      VARCHAR(20)     NOT NULL DEFAULT 'available',
                PRIMARY KEY (id),
                KEY         branch_id (branch_id)
            ) $charset;
        " );

        // ── dishdash_reservations ────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_reservations (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                table_id        BIGINT UNSIGNED          DEFAULT NULL,
                branch_id       BIGINT UNSIGNED NOT NULL,
                customer_name   VARCHAR(255)    NOT NULL DEFAULT '',
                customer_phone  VARCHAR(50)     NOT NULL DEFAULT '',
                customer_email  VARCHAR(255)    NOT NULL DEFAULT '',
                party_size      INT UNSIGNED    NOT NULL DEFAULT 1,
                reservation_date DATE           NOT NULL,
                reservation_time TIME           NOT NULL,
                status          VARCHAR(20)     NOT NULL DEFAULT 'pending',
                notes           TEXT                     DEFAULT NULL,
                created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY          branch_id (branch_id),
                KEY          reservation_date (reservation_date),
                KEY          status (status)
            ) $charset;
        " );

        // ── dishdash_pos_sessions ────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_pos_sessions (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                branch_id       BIGINT UNSIGNED NOT NULL,
                cashier_id      BIGINT UNSIGNED NOT NULL,
                cash_float      DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                total_cash      DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                total_card      DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                total_orders    INT UNSIGNED    NOT NULL DEFAULT 0,
                opened_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                closed_at       DATETIME                 DEFAULT NULL,
                notes           TEXT                     DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY          branch_id (branch_id),
                KEY          cashier_id (cashier_id)
            ) $charset;
        " );

        // ── dishdash_analytics ───────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}dishdash_analytics (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                branch_id       BIGINT UNSIGNED NOT NULL,
                stat_date       DATE            NOT NULL,
                orders_count    INT UNSIGNED    NOT NULL DEFAULT 0,
                revenue         DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                avg_order_value DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
                top_items       JSON                     DEFAULT NULL,
                order_types     JSON                     DEFAULT NULL,
                created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   branch_date (branch_id, stat_date),
                KEY          stat_date (stat_date)
            ) $charset;
        " );
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
        // Restaurant Manager — manages everything except plugin settings.
        add_role( 'dd_restaurant_manager', __( 'Restaurant Manager', 'dish-dash' ), [
            'read'                     => true,
            'dd_manage_orders'         => true,
            'dd_manage_menu'           => true,
            'dd_manage_reservations'   => true,
            'dd_manage_delivery'       => true,
            'dd_view_analytics'        => true,
            'dd_manage_branches'       => true,
        ] );

        // Branch Manager — scoped to their own branch.
        add_role( 'dd_branch_manager', __( 'Branch Manager', 'dish-dash' ), [
            'read'                   => true,
            'dd_manage_orders'       => true,
            'dd_manage_menu'         => true,
            'dd_manage_reservations' => true,
            'dd_view_analytics'      => true,
        ] );

        // Cashier — POS terminal access only.
        add_role( 'dd_cashier', __( 'Cashier', 'dish-dash' ), [
            'read'            => true,
            'dd_access_pos'   => true,
            'dd_manage_orders' => true,
        ] );

        // Delivery Driver — view & update assigned deliveries.
        add_role( 'dd_delivery_driver', __( 'Delivery Driver', 'dish-dash' ), [
            'read'               => true,
            'dd_view_deliveries' => true,
            'dd_update_delivery' => true,
        ] );

        // Kitchen Staff — view order queue only.
        add_role( 'dd_kitchen_staff', __( 'Kitchen Staff', 'dish-dash' ), [
            'read'            => true,
            'dd_view_orders'  => true,
            'dd_update_order_status' => true,
        ] );

        // Grant all Dish Dash capabilities to site administrators.
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $caps = [
                'dd_manage_orders', 'dd_manage_menu', 'dd_manage_reservations',
                'dd_manage_delivery', 'dd_view_analytics', 'dd_manage_branches',
                'dd_access_pos', 'dd_view_deliveries', 'dd_update_delivery',
                'dd_view_orders', 'dd_update_order_status', 'dd_manage_settings',
            ];
            foreach ( $caps as $cap ) {
                $admin->add_cap( $cap );
            }
        }
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
