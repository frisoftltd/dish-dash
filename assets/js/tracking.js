/**
 * Dish Dash — Behavior Tracking
 *
 * Sends user events to the server silently.
 * Works on homepage AND menu page.
 * Never blocks UI — all tracking is fire-and-forget.
 *
 * Events tracked:
 *   view_product   — product card enters viewport
 *   view_category  — user clicks a category filter
 *   search         — user types in search box
 *   add_to_cart    — user clicks Add to Cart
 *
 * @package DishDash
 * @since   2.5.33
 */

(function () {
    'use strict';

    var cfg = window.DDTrackConfig || {};
    if ( ! cfg.ajaxUrl ) return; // safety guard

    /* ── FIRE EVENT ─────────────────────────────────────────────────────── */
    function fire( eventType, productId, categoryId, meta ) {
        var body = new URLSearchParams({
            action:      'dd_track_event',
            nonce:       cfg.nonce   || '',
            session_id:  cfg.sessionId || '',
            event_type:  eventType   || '',
            product_id:  productId   || '',
            category_id: categoryId  || '',
            meta:        meta ? JSON.stringify( meta ) : '',
        });

        // fire-and-forget — no callback, no error handling
        // tracking should never affect UX
        fetch( cfg.ajaxUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    body.toString(),
            keepalive: true, // survives page navigation
        }).catch(function(){});
    }

    /* ── PRODUCT VIEW (Intersection Observer) ───────────────────────────── */
    // Fires when a product card becomes 50% visible in viewport.
    // Works on both homepage featured row and menu page list.
    function setupProductViews() {
        if ( ! window.IntersectionObserver ) return;

        var observer = new IntersectionObserver( function( entries ) {
            entries.forEach( function( entry ) {
                if ( ! entry.isIntersecting ) return;

                var card        = entry.target;
                var productId   = card.dataset.id   || card.dataset.productId  || '';
                var categoryId  = card.dataset.catId || '';

                if ( productId ) {
                    fire( 'view_product', productId, categoryId || null, null );
                }

                // Stop observing after first view — don't double-count
                observer.unobserve( card );
            });
        }, { threshold: 0.5 });

        // Observe all dish cards on the page
        document.querySelectorAll('.dd-dish-card, .dd-menu-item').forEach( function( card ) {
            observer.observe( card );
        });

        // Re-observe when new cards appear (e.g. after Load More)
        if ( window.MutationObserver ) {
            var mutObs = new MutationObserver( function( mutations ) {
                mutations.forEach( function( mutation ) {
                    mutation.addedNodes.forEach( function( node ) {
                        if ( node.nodeType !== 1 ) return;
                        // Observe the node itself if it's a card
                        if ( node.matches && node.matches('.dd-dish-card, .dd-menu-item') ) {
                            observer.observe( node );
                        }
                        // Observe any cards inside the added node
                        node.querySelectorAll && node.querySelectorAll('.dd-dish-card, .dd-menu-item').forEach( function(c) {
                            observer.observe(c);
                        });
                    });
                });
            });

            mutObs.observe( document.body, { childList: true, subtree: true });
        }
    }

    /* ── CATEGORY CLICK ─────────────────────────────────────────────────── */
    // Fires when user clicks a category pill/tab.
    // Works on homepage category circles and menu page filter pills.
    function setupCategoryTracking() {
        document.addEventListener( 'click', function( e ) {
            // Homepage category cards
            var catCard = e.target.closest('.dd-cat-card');
            if ( catCard ) {
                var slug = catCard.dataset.slug || '';
                // Get term ID from data attribute if available
                var catId = catCard.dataset.termId || null;
                fire( 'view_category', null, catId, { slug: slug, name: catCard.dataset.name || '' });
                return;
            }

            // Menu page category pills
            var pill = e.target.closest('.dd-menu-filter-btn');
            if ( pill ) {
                var catSlug = pill.dataset.slug || '';
                var termId  = pill.dataset.termId || null;
                fire( 'view_category', null, termId, { slug: catSlug });
                return;
            }

            // Selected category tabs
            var tab = e.target.closest('.dd-selcat__tab');
            if ( tab ) {
                fire( 'view_category', null, null, { slug: tab.dataset.slug || '' });
                return;
            }
        });
    }

    /* ── SEARCH ─────────────────────────────────────────────────────────── */
    // Search is tracked ONLY on Enter key or suggestion click — NOT on keystroke.
    // This prevents partial queries ("bi", "ch") from polluting recent searches.
    // Tracking is fired from frontend.js via window.DDTrack.search(query).
    function setupSearchTracking() {
        document.addEventListener( 'keydown', function( e ) {
            if ( e.key !== 'Enter' ) return;
            var el = e.target;
            var isSearchInput = (
                el.id === 'ddSearch'     ||
                el.id === 'ddMobileSearch' ||
                el.id === 'ddMenuSearch' ||
                el.classList.contains('dd-search-input')
            );
            if ( ! isSearchInput ) return;
            var q = el.value.trim();
            if ( q && q.length >= 2 ) {
                fire( 'search', null, null, { query: q } );
            }
        } );
    }

    /* ── ADD TO CART ────────────────────────────────────────────────────── */
    // Fires when user clicks any Add to Cart button.
    // Works everywhere — homepage, menu page, category rows.
    function setupAddToCartTracking() {
        document.addEventListener( 'click', function( e ) {
            var btn = e.target.closest('.dd-add-btn, .dd-menu-add-btn');
            if ( ! btn ) return;

            var productId  = btn.dataset.id || btn.dataset.productId || '';
            var card       = btn.closest('.dd-dish-card, .dd-menu-item');
            var categoryId = card ? ( card.dataset.catId || null ) : null;

            if ( productId ) {
                fire( 'add_to_cart', productId, categoryId, { qty: 1 });
            }
        });
    }

    /* ── INIT ───────────────────────────────────────────────────────────── */
    function init() {
        setupProductViews();
        setupCategoryTracking();
        setupSearchTracking();
        setupAddToCartTracking();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

    // Expose for manual tracking from other scripts
    window.DDTrack = {
        event: fire,
        viewProduct:  function(id, catId)     { fire('view_product',  id,   catId, null); },
        viewCategory: function(catId, slug)    { fire('view_category', null, catId, { slug: slug }); },
        addToCart:    function(id, catId)      { fire('add_to_cart',   id,   catId, { qty: 1 }); },
        search:       function(query)          { fire('search', null, null, { query: query }); },
        order:        function(orderId, total) { fire('order',  null, null, { order_id: orderId, total: total }); },
    };

})();
