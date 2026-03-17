/* =============================================================
   Dish Dash – Frontend JS  |  assets/js/frontend.js
   ============================================================= */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.dd-menu-wrap').forEach(initMenu);
    });

    function initMenu(wrap) {
        var btns  = wrap.querySelectorAll('.dd-filter-btn');
        var cards = wrap.querySelectorAll('.dd-menu-card');

        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var filter = btn.dataset.filter;

                btns.forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');

                cards.forEach(function (card) {
                    if (filter === 'all') {
                        card.classList.remove('dd-hidden');
                    } else {
                        var cats = (card.dataset.category || '').split(' ');
                        card.classList.toggle('dd-hidden', !cats.includes(filter));
                    }
                });
            });
        });
    }
})();
