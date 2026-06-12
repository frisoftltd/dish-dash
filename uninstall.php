<?php
/**
 * File:    uninstall.php
 * Purpose: Runs when the plugin is DELETED from Plugins → Delete.
 *          Drops all custom DB tables, deletes all dish_dash_ options,
 *          removes CPT posts, and removes custom user roles — but only
 *          if the admin has opted in via dish_dash_remove_data_on_uninstall.
 *
 * Dependencies (this file needs):
 *   - WP_UNINSTALL_PLUGIN constant (WordPress core, guards execution)
 *   - $wpdb global (WordPress core)
 *
 * Dependents (files that need this):
 *   - WordPress core (called automatically on plugin deletion)
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Only remove data if the admin checked "Delete all data on uninstall"
// in Settings. This protects accidental data loss on simple reinstalls.
$remove_data = get_option( 'dish_dash_remove_data_on_uninstall', '0' );

if ( '1' !== $remove_data ) {
    return;
}

global $wpdb;

// ── Drop custom tables ───────────────────────
$tables = [
    'dishdash_analytics',
    'dishdash_pos_sessions',
    'dishdash_reservations',
    'dishdash_tables',
    'dishdash_delivery_zones',
    'dishdash_order_items',
    'dishdash_orders',
    'dishdash_branches',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
}

// ── Delete all plugin options ────────────────
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dish_dash_%'"  // phpcs:ignore
);

// ── Delete all CPT posts (menu items etc.) ───
$post_types = [ 'dd_menu_item', 'dd_addon_group', 'dd_coupon' ];
foreach ( $post_types as $pt ) {
    $posts = get_posts( [ 'post_type' => $pt, 'numberposts' => -1, 'post_status' => 'any' ] );
    foreach ( $posts as $post ) {
        wp_delete_post( $post->ID, true );
    }
}

// ── Remove custom roles ──────────────────────
$roles = [
    'dd_restaurant_owner',
    'dd_restaurant_manager',
    // Legacy roles from earlier versions — removed defensively.
    'dd_branch_manager',
    'dd_cashier',
    'dd_delivery_driver',
    'dd_kitchen_staff',
];
foreach ( $roles as $role ) {
    remove_role( $role );
}

// ── Remove capabilities from administrators ──
$admin = get_role( 'administrator' );
if ( $admin ) {
    $caps = [
        'dd_manage_orders', 'dd_manage_menu', 'dd_manage_reservations',
        'dd_view_analytics', 'dd_view_billing', 'dd_view_customers',
        'dd_manage_brand_identity', 'dd_manage_homepage', 'dd_manage_template',
        'dd_manage_settings', 'dd_view_activity_log', 'dd_manage_auth',
    ];
    foreach ( $caps as $cap ) {
        $admin->remove_cap( $cap );
    }
}
