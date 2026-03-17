<?php
/**
 * DD_Install
 *
 * Handles plugin activation, deactivation, and database schema creation.
 * Uses dbDelta() so the schema is always kept up to date on upgrades.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Install {

    // ── Activation ────────────────────────────────────────────────────────────

    public static function activate(): void {
        self::create_tables();
        self::create_roles();
        self::set_defaults();

        // Store the version we just activated on.
        update_option( 'dish_dash_version', DD_VERSION );
        update_option( 'dish_dash_activated_at', current_time( 'mysql' ) );

        // Flush rewrite rules so CPT URLs work immediately.
        flush_rewrite_rules();

        do_action( 'dish_dash_activated' );
    }

    // ── Deactivation ─────────────────────────────────────────────────────────

    public static function deactivate(): void {
        flush_rewrite_rules();
        do_action( 'dish_dash_deactivated' );
    }

    // ── Database tables ───────────────────────────────────────────────────────

    /**
     * Create or upgrade all Dish Dash custom database tables.
     * Safe to call multiple times — dbDelta only adds missing columns/tables.
     */
    public static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix;

        // ── Orders ────────────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$prefix}dishdash_orders (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_number    VARCHAR(50)  NOT NULL,
            wc_order_id     BIGINT UNSIGNED          DEFAULT NULL,
            branch_id       BIGINT UNSIGNED NOT NULL DEFAULT 1,
            customer_id     BIGINT UNSIGNED          DEFAULT NULL,
            customer_name   VARCHAR(255) NOT NULL DEFAULT '',
            customer_phone  VARCHAR(50)  NOT NULL DEFAULT '',
            customer_email  VARCHAR(255) NOT NULL DEFAULT '',
            order_type      ENUM('delivery','pickup','dine-in','pos') NOT NULL DEFAULT 'delivery',
            status          VARCHAR(50)  NOT NULL DEFAULT 'pending',
            subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            delivery_fee    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            tip             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            tax             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            payment_method  VARCHAR(100) NOT NULL DEFAULT '',
            payment_status  VARCHAR(50)  NOT NULL DEFAULT 'unpaid',
            scheduled_at    DATETIME              DEFAULT NULL,
            delivery_address LONGTEXT             DEFAULT NULL,
            special_instructions TEXT             DEFAULT NULL,
            pos_session_id  BIGINT                DEFAULT NULL,
            table_id        BIGINT                DEFAULT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_number (order_number),
            KEY branch_id (branch_id),
            KEY customer_id (customer_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;" );

        // ── Order Items ───────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$prefix}dishdash_order_items (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id        BIGINT UNSIGNED NOT NULL,
            menu_item_id    BIGINT UNSIGNED NOT NULL,
            item_name       VARCHAR(255) NOT NULL DEFAULT '',
            quantity        INT UNSIGNED NOT NULL DEFAULT 1,
            unit_price      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            addons          LONGTEXT DEFAULT NULL,
            variation       VARCHAR(100) DEFAULT NULL,
            special_note    TEXT DEFAULT NULL,
            line_total      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY menu_item_id (menu_item_id)
        ) $charset;" );

        // ── Branches ─────────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$prefix}dishdash_branches (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name            VARCHAR(255) NOT NULL DEFAULT '',
            slug            VARCHAR(100) NOT NULL DEFAULT '',
            address         TEXT NOT NULL DEFAULT '',
            latitude        DECIMAL(10,8)          DEFAULT NULL,
            longitude       DECIMAL(11,8)          DEFAULT NULL,
            phone           VARCHAR(50)  NOT NULL DEFAULT '',
            email           VARCHAR(255) NOT NULL DEFAULT '',
            opening_hours   LONGTEXT               DEFAULT NULL,
            min_order       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            is_active       TINYINT(1)   NOT NULL DEFAULT 1,
            settings        LONGTEXT               DEFAULT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY is_active (is_active)
        ) $charset;" );

        // ── Delivery Zones ────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$prefix}dishdash_delivery_zones (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_id       BIGINT UNSIGNED NOT NULL,
            name            VARCHAR(255) NOT NULL DEFAULT '',
            zone_type       ENUM('radius','polygon','zipcode') NOT NULL DEFAULT 'radius',
            zone_data       LONGTEXT NOT NULL DEFAULT '',
            delivery_fee    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            min_order       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            estimated_time  INT UNSIGNED NOT NULL DEFAULT 30,
            is_active       TINYINT(1)   NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY branch_id (branch_id),
            KEY is_active (is_active)
        ) $charset;" );

        // ── Tables (dining) ───────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$prefix}dishdash_tables (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_id       BIGINT UNSIGNED NOT NULL,
            name            VARCHAR(100) NOT NULL DEFAULT '',
            capacity        INT UNSIGNED NOT NULL DEFAULT 4,
            qr_code         VARCHAR(255)           DEFAULT NULL,
            status          ENUM('available','occupied','reserved') NOT NULL DEFAULT 'available',
            is_active       TINYINT(1)   NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY branch_id (branch_id),
            KEY status (status)
        ) $charset;" );

        // ── Reservations ──────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$prefix}dishdash_reservations (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            table_id        BIGINT UNSIGNED NOT NULL,
            branch_id       BIGINT UNSIGNED NOT NULL,
            customer_name   VARCHAR(255) NOT NULL DEFAULT '',
            customer_phone  VARCHAR(50)  NOT NULL DEFAULT '',
            customer_email  VARCHAR(255) NOT NULL DEFAULT '',
            party_size      INT UNSIGNED NOT NULL DEFAULT 2,
            reservation_date DATE         NOT NULL,
            reservation_time TIME         NOT NULL,
            duration_minutes INT UNSIGNED NOT NULL DEFAULT 90,
            status          ENUM('pending','confirmed','seated','completed','cancelled','no-show') NOT NULL DEFAULT 'pending',
            notes           TEXT DEFAULT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY table_id (table_id),
            KEY branch_id (branch_id),
            KEY reservation_date (reservation_date),
            KEY status (status)
        ) $charset;" );

        // ── Analytics ─────────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$prefix}dishdash_analytics (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_id       BIGINT UNSIGNED NOT NULL,
            stat_date       DATE NOT NULL,
            orders_count    INT UNSIGNED NOT NULL DEFAULT 0,
            revenue         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            avg_order_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            top_items       LONGTEXT DEFAULT NULL,
            data            LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY branch_date (branch_id, stat_date),
            KEY stat_date (stat_date)
        ) $charset;" );
    }

    // ── Roles & capabilities ──────────────────────────────────────────────────

    private static function create_roles(): void {
        // Restaurant Manager — full plugin access except settings
        add_role( 'dd_restaurant_manager', __( 'Restaurant Manager', 'dish-dash' ), [
            'read'              => true,
            'dd_manage_orders'  => true,
            'dd_manage_menu'    => true,
            'dd_manage_reservations' => true,
            'dd_view_analytics' => true,
        ] );

        // Branch Manager — scoped to their branch
        add_role( 'dd_branch_manager', __( 'Branch Manager', 'dish-dash' ), [
            'read'              => true,
            'dd_manage_orders'  => true,
            'dd_manage_menu'    => true,
        ] );

        // Cashier — POS only
        add_role( 'dd_cashier', __( 'Cashier (POS)', 'dish-dash' ), [
            'read'          => true,
            'dd_use_pos'    => true,
        ] );

        // Delivery Driver
        add_role( 'dd_driver', __( 'Delivery Driver', 'dish-dash' ), [
            'read'              => true,
            'dd_view_deliveries' => true,
        ] );

        // Give admins all DD capabilities
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $caps = [
                'dd_manage_orders', 'dd_manage_menu',
                'dd_manage_reservations', 'dd_view_analytics',
                'dd_manage_branches', 'dd_manage_settings',
                'dd_use_pos', 'dd_view_deliveries',
            ];
            foreach ( $caps as $cap ) {
                $admin->add_cap( $cap );
            }
        }
    }

    // ── Default options ───────────────────────────────────────────────────────

    private static function set_defaults(): void {
        $defaults = [
            'dd_currency'           => get_woocommerce_currency(),
            'dd_currency_symbol'    => get_woocommerce_currency_symbol(),
            'dd_min_order_amount'   => '0',
            'dd_order_prefix'       => 'DD-',
            'dd_default_prep_time'  => '30',
            'dd_enable_delivery'    => '1',
            'dd_enable_pickup'      => '1',
            'dd_enable_reservations'=> '1',
            'dd_enable_pos'         => '1',
            'dd_google_maps_key'    => '',
            'dd_claude_api_key'     => '',
        ];

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }

        // Insert a default branch if none exist yet.
        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_branches';
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        if ( $count === 0 ) {
            $wpdb->insert( $table, [
                'name'      => get_bloginfo( 'name' ),
                'slug'      => 'main-branch',
                'address'   => '',
                'phone'     => '',
                'email'     => get_bloginfo( 'admin_email' ),
                'is_active' => 1,
            ] );
        }
    }
}
