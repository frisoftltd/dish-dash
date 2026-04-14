/**
 * File:    assets/js/admin.js
 * Purpose: Admin panel interactivity — confirm-delete prompts on destructive
 *          buttons, and auto-dismissal of success notices after 4 seconds.
 *
 * DOM elements required:
 *   - .dd-confirm-delete  (any admin button needing confirmation)
 *   - .dd-admin-notice-auto  (auto-dismissing notice elements)
 *
 * Localized data needed (wp_localize_script):
 *   - window.dishDashAdmin  (ajaxUrl, nonce, restUrl, version, i18n)
 *     Localized by DD_Admin::enqueue_admin_assets()
 *
 * Dependencies:
 *   - jQuery (WordPress core, loaded in admin)
 *
 * Dependents:
 *   - admin/class-dd-admin.php (enqueues this via wp_enqueue_script)
 *
 * AJAX endpoints called: None
 * Custom events fired:   None
 * Custom events listened: None
 *
 * Last modified: v3.1.13
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        // Generic confirmation for delete actions.
        $(document).on('click', '.dd-confirm-delete', function (e) {
            if (!confirm(window.dishDashAdmin.i18n.confirmDelete)) {
                e.preventDefault();
            }
        });

        // Auto-dismiss admin notices after 4 seconds.
        setTimeout(function () {
            $('.dd-admin-notice-auto').fadeOut(400);
        }, 4000);
    });

}(jQuery));
