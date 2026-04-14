<?php
/**
 * File:    admin/pages/coming-soon.php
 * Purpose: Generic "Coming Soon" placeholder admin page template.
 *          Included by DD_Admin::render_*() for unbuilt feature pages
 *          (Reservations, Delivery, Branches, POS, Analytics).
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core guard)
 *
 * Dependents (files that need this):
 *   - admin/class-dd-admin.php (loaded via require for stub pages)
 *
 * Variables expected in scope:
 *   None — fully self-contained placeholder markup.
 *
 * CSS classes used:
 *   .dd-admin-wrap, .dd-coming-soon
 *
 * Last modified: v3.1.13
 */

 if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap dd-admin-wrap">
    <div class="dd-coming-soon">
        <span>🚧</span>
        <h2><?php esc_html_e( 'Coming Soon', 'dish-dash' ); ?></h2>
        <p><?php esc_html_e( 'This module is on the roadmap and will be built in an upcoming phase. Check back soon!', 'dish-dash' ); ?></p>
    </div>
</div>
