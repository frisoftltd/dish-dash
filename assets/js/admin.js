/* ============================================================
   Dish Dash — Admin JS
   File: assets/js/admin.js
   ============================================================ */
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
