(function () {
    'use strict';

    var grid         = document.getElementById('ddMenuGrid');
    var loadMore     = document.getElementById('ddMenuLoadMore');
    var loadMoreWrap = loadMore ? loadMore.parentNode : null;
    var catTrack     = document.getElementById('ddMenuCatsTrack');
    var catPrev      = document.getElementById('ddMenuCatsPrev');
    var catNext      = document.getElementById('ddMenuCatsNext');

    if (!grid || !loadMore || !catTrack) return;
    if (typeof DDMenu === 'undefined') return;

    catTrack.addEventListener('click', function (e) {
        var btn = e.target.closest('.dd-menu-cat');
        if (!btn) return;

        catTrack.querySelectorAll('.dd-menu-cat').forEach(function (b) {
            b.classList.remove('is-active');
        });
        btn.classList.add('is-active');

        var slug = btn.getAttribute('data-cat-slug') || '';
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

    function scrollCats(dir) {
        var item = catTrack.querySelector('.dd-menu-cat');
        if (!item) return;
        var step = (item.offsetWidth + 24) * 3;
        catTrack.scrollBy({ left: dir * step, behavior: 'smooth' });
    }
    if (catPrev) catPrev.addEventListener('click', function () { scrollCats(-1); });
    if (catNext) catNext.addEventListener('click', function () { scrollCats(1); });

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
