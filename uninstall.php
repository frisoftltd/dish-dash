<?php
/**
 * Dish Dash – Uninstaller
 *
 * Runs when the plugin is DELETED (not just deactivated) from
 * Plugins → Delete. Removes all plugin data if the admin opted in.
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
    'dd_restaurant_manager',
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
        'dd_manage_delivery', 'dd_view_analytics', 'dd_manage_branches',
        'dd_access_pos', 'dd_view_deliveries', 'dd_update_delivery',
        'dd_view_orders', 'dd_update_order_status', 'dd_manage_settings',
    ];
    foreach ( $caps as $cap ) {
        $admin->remove_cap( $cap );
    }
}
