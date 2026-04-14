<?php
/**
 * File:    admin/pages/dashboard.php
 * Purpose: Renders the Dish Dash admin dashboard — KPI cards (orders,
 *          revenue, pending, menu items), quick action links, and the
 *          3-step setup checklist.
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core guard)
 *   - $wpdb global (order/revenue counts)
 *   - WordPress get_option() (dish_dash_menu_page_id, dish_dash_google_maps_key)
 *   - WordPress wp_count_posts('dd_menu_item')
 *
 * Dependents (files that need this):
 *   - admin/class-dd-admin.php (loaded via render_dashboard())
 *
 * WP options read:
 *   dish_dash_menu_page_id, dish_dash_google_maps_key
 *
 * CSS classes used:
 *   .dd-admin-wrap, .dd-admin-header, .dd-kpi-grid, .dd-kpi-card,
 *   .dd-quick-links, .dd-setup-checklist
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap dd-admin-wrap">

    <div class="dd-admin-header">
        <div class="dd-admin-header__logo">
            <span class="dd-logo-icon">🍽</span>
            <div>
                <h1>Dish Dash</h1>
                <span class="dd-version">v<?php echo esc_html( DD_VERSION ); ?></span>
            </div>
        </div>
        <div class="dd-admin-header__actions">
            <a href="<?php echo esc_url( dd_menu_url() ); ?>" target="_blank" class="button">
                <?php esc_html_e( 'View Menu', 'dish-dash' ); ?> ↗
            </a>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="dd-kpi-grid">
        <div class="dd-kpi-card">
            <div class="dd-kpi-card__icon" style="background:#fff3e0">📦</div>
            <div class="dd-kpi-card__data">
                <span class="dd-kpi-card__number" id="dd-kpi-orders">—</span>
                <span class="dd-kpi-card__label"><?php esc_html_e( "Today's Orders", 'dish-dash' ); ?></span>
            </div>
        </div>
        <div class="dd-kpi-card">
            <div class="dd-kpi-card__icon" style="background:#e8f5e9">💰</div>
            <div class="dd-kpi-card__data">
                <span class="dd-kpi-card__number" id="dd-kpi-revenue">—</span>
                <span class="dd-kpi-card__label"><?php esc_html_e( "Today's Revenue", 'dish-dash' ); ?></span>
            </div>
        </div>
        <div class="dd-kpi-card">
            <div class="dd-kpi-card__icon" style="background:#fce4ec">⏳</div>
            <div class="dd-kpi-card__data">
                <span class="dd-kpi-card__number" id="dd-kpi-pending">—</span>
                <span class="dd-kpi-card__label"><?php esc_html_e( 'Pending Orders', 'dish-dash' ); ?></span>
            </div>
        </div>
        <div class="dd-kpi-card">
            <div class="dd-kpi-card__icon" style="background:#e3f2fd">🍴</div>
            <div class="dd-kpi-card__data">
                <?php
                $menu_count = wp_count_posts( 'dd_menu_item' );
                $total = isset( $menu_count->publish ) ? $menu_count->publish : 0;
                ?>
                <span class="dd-kpi-card__number"><?php echo esc_html( $total ); ?></span>
                <span class="dd-kpi-card__label"><?php esc_html_e( 'Menu Items', 'dish-dash' ); ?></span>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="dd-quick-links">
        <h2><?php esc_html_e( 'Quick Actions', 'dish-dash' ); ?></h2>
        <div class="dd-quick-links__grid">
            <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=dd_menu_item' ) ); ?>" class="dd-quick-link">
                <span>➕</span> <?php esc_html_e( 'Add Menu Item', 'dish-dash' ); ?>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-orders' ) ); ?>" class="dd-quick-link">
                <span>📋</span> <?php esc_html_e( 'View Orders', 'dish-dash' ); ?>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-settings' ) ); ?>" class="dd-quick-link">
                <span>⚙️</span> <?php esc_html_e( 'Settings', 'dish-dash' ); ?>
            </a>
            <a href="<?php echo esc_url( dd_menu_url() ); ?>" target="_blank" class="dd-quick-link">
                <span>👁</span> <?php esc_html_e( 'Preview Menu', 'dish-dash' ); ?>
            </a>
        </div>
    </div>

    <!-- Setup Checklist -->
    <div class="dd-setup-checklist">
        <h2><?php esc_html_e( 'Setup Checklist', 'dish-dash' ); ?></h2>
        <?php
        $checks = [
            [
                'done'  => (bool) get_option( 'dish_dash_menu_page_id' ),
                'label' => __( 'Menu page created', 'dish-dash' ),
                'link'  => admin_url( 'admin.php?page=dish-dash-settings' ),
            ],
            [
                'done'  => (bool) get_option( 'dish_dash_google_maps_key' ),
                'label' => __( 'Google Maps API key configured', 'dish-dash' ),
                'link'  => admin_url( 'admin.php?page=dish-dash-settings' ),
            ],
            [
                'done'  => wp_count_posts( 'dd_menu_item' )->publish > 0,
                'label' => __( 'At least one menu item added', 'dish-dash' ),
                'link'  => admin_url( 'post-new.php?post_type=dd_menu_item' ),
            ],
        ];
        ?>
        <ul class="dd-checklist">
            <?php foreach ( $checks as $check ) : ?>
            <li class="dd-checklist__item <?php echo $check['done'] ? 'dd-checklist__item--done' : ''; ?>">
                <span class="dd-checklist__icon"><?php echo $check['done'] ? '✅' : '⬜'; ?></span>
                <?php if ( ! $check['done'] ) : ?>
                    <a href="<?php echo esc_url( $check['link'] ); ?>"><?php echo esc_html( $check['label'] ); ?></a>
                <?php else : ?>
                    <?php echo esc_html( $check['label'] ); ?>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

</div>
