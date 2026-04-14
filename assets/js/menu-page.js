/**
 * File:    assets/js/menu-page.js
 * Purpose: AJAX-driven category navigation and paginated product grid
 *          loading for the desktop menu page (/restaurant-menu/).
 *          Also syncs initial state from the ?cat= URL deep-link param.
 *          Arrow scroll (prev/next) initialises without requiring DDMenu.
 *
 * DOM elements required:
 *   - #ddMenuGrid        (product grid container, data-current-cat attribute)
 *   - #ddMenuLoadMore    (load-more button, data-page attribute)
 *   - #ddMenuCatsTrack   (category carousel scroll container)
 *   - #ddMenuCatsPrev, #ddMenuCatsNext  (arrow buttons)
 *   - #ddMenuGridTitle   (grid section heading, updated on category click)
 *   - .dd-menu-cat       (category buttons, data-cat-slug attribute)
 *
 * Localized data needed (wp_localize_script):
 *   - window.DDMenu  (ajaxUrl, nonce, action='dd_menu_load_products', perPage)
 *     Localized by DD_Menu_Module::enqueue_menu_assets()
 *
 * AJAX endpoints called:
 *   - admin-ajax.php?action=dd_menu_load_products  (cat_slug, page, per_page)
 *
 * Custom events fired:   None
 * Custom events listened: None
 *
 * Dependencies:
 *   - window.DDTrackConfig (optional — from tracking.js, used for category view events)
 *
 * Dependents:
 *   - modules/menu/class-dd-menu-module.php (enqueues this on menu page)
 *   - templates/menu/grid.php (DOM elements rendered here)
 *
 * Last modified: v3.1.13
 */
(function () {
    'use strict';

    var grid         = document.getElementById('ddMenuGrid');
    var loadMore     = document.getElementById('ddMenuLoadMore');
    var loadMoreWrap = loadMore ? loadMore.parentNode : null;
    var catTrack     = document.getElementById('ddMenuCatsTrack');
    var catPrev      = document.getElementById('ddMenuCatsPrev');
    var catNext      = document.getElementById('ddMenuCatsNext');
    var gridTitle    = document.getElementById('ddMenuGridTitle');

    // ── Arrow scroll — no DDMenu dependency, init immediately ──────────
    function scrollCats(dir) {
        if (!catTrack) return;
        var item = catTrack.querySelector('.dd-menu-cat');
        if (!item) return;
        var step = (item.offsetWidth + 24) * 3;
        catTrack.scrollBy({ left: dir * step, behavior: 'smooth' });
    }
    if (catPrev) catPrev.addEventListener('click', function () { scrollCats(-1); });
    if (catNext) catNext.addEventListener('click', function () { scrollCats(1); });

    // ── AJAX features require DDMenu localization ──────────────────────
    if (!grid || !loadMore || !catTrack) return;
    if (typeof DDMenu === 'undefined') return;

    // ── Sync JS state with ?cat= URL param (server pre-filtered) ──────
    (function () {
        var params   = new URLSearchParams(window.location.search);
        var catParam = params.get('cat') || '';
        if (!catParam) return;

        // Server already set data-current-cat in HTML; confirm it matches.
        grid.setAttribute('data-current-cat', catParam);

        // Fire one category_view tracking event for deep-link traffic.
        var trackCfg = window.DDTrackConfig || {};
        if (trackCfg.ajaxUrl) {
            var body = new URLSearchParams({
                action:      'dd_track_event',
                nonce:       trackCfg.nonce      || '',
                session_id:  trackCfg.sessionId  || '',
                event_type:  'view_category',
                product_id:  '',
                category_id: '',
                meta:        JSON.stringify({ slug: catParam, source: 'deep_link' }),
            });
            fetch(trackCfg.ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    body.toString(),
                keepalive: true,
            }).catch(function () {});
        }
    })();

    catTrack.addEventListener('click', function (e) {
        var btn = e.target.closest('.dd-menu-cat');
        if (!btn) return;

        catTrack.querySelectorAll('.dd-menu-cat').forEach(function (b) {
            b.classList.remove('is-active');
        });
        btn.classList.add('is-active');

        var slug = btn.getAttribute('data-cat-slug') || '';

        // Update grid section heading to reflect active category
        if (gridTitle) {
            if (slug === '') {
                gridTitle.textContent = 'All Dishes';
            } else {
                var nameEl = btn.querySelector('.dd-menu-cat__name');
                gridTitle.textContent = nameEl ? nameEl.textContent.trim() : 'All Dishes';
            }
        }

        grid.setAttribute('data-current-cat', slug);
        loadMore.setAttribute('data-page', '1');

        loadProducts(slug, 1, true);
    });

    loadMore.addEventListener('click', function () {
        if (loadMore.classList.contains('is-loading')) return;
        var slug = grid.getAttribute('data-current-cat') || '';
        var page = parseInt(loadMore.getAttribute('data-page'), 10) + 1;
        loadProducts(slug, page, false);
    });


    function loadProducts(catSlug, page, replace) {
        loadMore.classList.add('is-loading');

        var formData = new FormData();
        formData.append('action', 'dd_menu_load_products');
        formData.append('nonce', DDMenu.nonce);
        formData.append('cat_slug', catSlug);
        formData.append('page', String(page));

        fetch(DDMenu.ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                loadMore.classList.remove('is-loading');
                if (!data || !data.success) {
                    console.error('DD Menu load failed', data);
                    return;
                }
                if (replace) {
                    grid.innerHTML = data.data.html;
                } else {
                    grid.insertAdjacentHTML('beforeend', data.data.html);
                }
                loadMore.setAttribute('data-page', String(data.data.page));
                if (loadMoreWrap) {
                    loadMoreWrap.style.display = data.data.has_more ? '' : 'none';
                }
            })
            .catch(function (err) {
                console.error('DD Menu fetch error', err);
                loadMore.classList.remove('is-loading');
            });
    }
})();
