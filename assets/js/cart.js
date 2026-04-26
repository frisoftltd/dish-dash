/**
 * File:    assets/js/cart.js
 * Purpose: Cart drawer open/close, server cart fetch on open, badge updates,
 *          and delivery nudge bar. v3.2.13 — UI layer only (read-only cart).
 *          Quantity controls and item removal come in v3.2.14.
 *
 * Config object (wp_localize_script → ddCartData):
 *   threshold    — free delivery threshold in RWF (default 10000)
 *   delivery_fee — flat delivery fee below threshold (default 1500)
 *   ajax_url     — admin-ajax.php URL
 *   nonce        — dish_dash_frontend nonce
 *   checkout_url — URL to checkout page
 *   currency     — currency symbol/code (default 'RWF')
 *
 * DOM IDs targeted:
 *   #ddCartOverlay, #ddCartDrawer, #ddCartClose
 *   #ddCartItems, #ddCartNudge, #ddNudgeFill, #ddNudgeRemaining
 *   #ddCartSubtotal, #ddCartCheckout
 *   #ddCartBtn, #ddCartBtnCount     — floating button (desktop)
 *   #ddBottomCartBtn, #ddBottomBadge — bottom nav (mobile)
 *   #ddCartTopBtn, #ddCartCount      — header cart button
 *
 * Tracking events fired:
 *   cart_open — on every panel open
 *
 * Public API:
 *   window.DDCart.open()    — open the drawer
 *   window.DDCart.close()   — close the drawer
 *   window.DDCart.refresh() — re-fetch cart and re-render
 *
 * Dependents:
 *   modules/template/class-dd-template-module.php (enqueues this)
 *
 * Last modified: v3.2.13
 */
(function () {
    'use strict';

    /* ── CONFIG ─────────────────────────────────────────────── */
    var cfg         = window.ddCartData || {};
    var THRESHOLD   = parseInt( cfg.threshold,    10 ) || 10000;
    var AJAX_URL    = cfg.ajax_url    || '/wp-admin/admin-ajax.php';
    var NONCE       = cfg.nonce       || '';
    var CURRENCY    = cfg.currency    || 'RWF';

    /* ── INIT ───────────────────────────────────────────────── */
    document.addEventListener( 'DOMContentLoaded', function () {
        fetchCart( false ); // silent fetch on load to update badges only
        bindEvents();
    } );

    /* ── EVENT BINDING ──────────────────────────────────────── */
    function bindEvents() {

        document.addEventListener( 'click', function ( e ) {

            // Open triggers — event delegation works for late-rendered elements
            if ( e.target.closest( '#ddCartBtn, #ddBottomCartBtn, #ddCartTopBtn' ) ) {
                openCart();
                return;
            }

            // Close triggers
            if ( e.target.closest( '#ddCartClose, .dd-cart-drawer-overlay' ) ) {
                closeCart();
                return;
            }

            // Disabled checkout: block navigation
            var checkout = e.target.closest( '#ddCartCheckout' );
            if ( checkout && checkout.classList.contains( 'dd-cart-drawer__checkout--disabled' ) ) {
                e.preventDefault();
            }
        } );

        // Keyboard close
        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' ) closeCart();
        } );

        // Qty increase
        document.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest( '.dd-cart-plus' );
            if ( ! btn ) return;
            var key = btn.dataset.key;
            var qtyEl = btn.closest( '.dd-cart-stepper' ).querySelector( '.dd-cart-stepper__qty' );
            var newQty = parseInt( qtyEl.textContent, 10 ) + 1;
            qtyEl.textContent = newQty;
            ajax( 'dd_cart_update', { key: key, qty: newQty }, function ( data ) {
                updateBadges( data.count );
                updateNudge( data.total );
                updateFooter( data );
            } );
        } );

        // Qty decrease
        document.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest( '.dd-cart-minus' );
            if ( ! btn ) return;
            var key = btn.dataset.key;
            var qtyEl = btn.closest( '.dd-cart-stepper' ).querySelector( '.dd-cart-stepper__qty' );
            var newQty = Math.max( 1, parseInt( qtyEl.textContent, 10 ) - 1 );
            qtyEl.textContent = newQty;
            ajax( 'dd_cart_update', { key: key, qty: newQty }, function ( data ) {
                updateBadges( data.count );
                updateNudge( data.total );
                updateFooter( data );
            } );
        } );

        // Remove item
        document.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest( '.dd-cart-remove' );
            if ( ! btn ) return;
            var key = btn.dataset.key;
            var item = btn.closest( '.dd-cart-drawer__item' );
            if ( item ) item.style.opacity = '0.4';
            ajax( 'dd_cart_remove', { key: key }, function ( data ) {
                updateBadges( data.count );
                updateNudge( data.total );
                updateFooter( data );
                fetchCart( true );
            } );
        } );
    }

    /* ── OPEN ───────────────────────────────────────────────── */
    function openCart() {
        var overlay = document.getElementById( 'ddCartOverlay' );
        var drawer  = document.getElementById( 'ddCartDrawer' );
        if ( ! drawer ) return;

        drawer.classList.add( 'dd-cart-drawer--open' );
        if ( overlay ) overlay.classList.add( 'dd-cart-drawer-overlay--visible' );
        document.body.classList.add( 'dd-cart-open' );

        // Re-fetch on every open so panel always reflects server cart
        fetchCart( true );

        // Tracking
        trackEvent( 'cart_open', {} );
    }

    /* ── CLOSE ──────────────────────────────────────────────── */
    function closeCart() {
        var overlay = document.getElementById( 'ddCartOverlay' );
        var drawer  = document.getElementById( 'ddCartDrawer' );
        if ( drawer )  drawer.classList.remove( 'dd-cart-drawer--open' );
        if ( overlay ) overlay.classList.remove( 'dd-cart-drawer-overlay--visible' );
        document.body.classList.remove( 'dd-cart-open' );
    }

    /* ── FETCH CART FROM SERVER ─────────────────────────────── */
    function fetchCart( renderPanel ) {
        ajax( 'dd_cart_get', {}, function ( data ) {
            updateBadges( data.count );
            if ( renderPanel ) {
                renderItems( data.items );
                updateNudge( data.total );
                updateFooter( data );
            }
        } );
    }

    /* ── RENDER ITEMS ───────────────────────────────────────── */
    function renderItems( items ) {
        var container = document.getElementById( 'ddCartItems' );
        if ( ! container ) return;

        if ( ! items || items.length === 0 ) {
            container.innerHTML =
                '<p class="dd-cart-drawer__empty">' +
                    'Your cart is empty &mdash; add something delicious &#x1F37D;' +
                '</p>';
            return;
        }

        container.innerHTML = items.map( function ( item ) {
            var addonTotal = ( item.addons || [] ).reduce( function ( s, a ) { return s + a.price; }, 0 );
            var lineTotal  = formatPrice( ( item.price + addonTotal ) * item.qty );
            var key        = escHtml( item.key || item.id );

            return '<div class="dd-cart-drawer__item" data-key="' + key + '">' +
                ( item.image
                    ? '<img class="dd-cart-drawer__item-img" src="' + escHtml( item.image ) + '" alt="' + escHtml( item.name ) + '" loading="lazy">'
                    : '<div class="dd-cart-drawer__item-img dd-cart-drawer__item-img--placeholder">&#127869;</div>' ) +
                '<div class="dd-cart-drawer__item-info">' +
                    '<span class="dd-cart-drawer__item-name">' + escHtml( item.name ) + '</span>' +
                    '<div class="dd-cart-stepper">' +
                        '<button class="dd-cart-stepper__btn dd-cart-minus" data-key="' + key + '" aria-label="Decrease">&#8722;</button>' +
                        '<span class="dd-cart-stepper__qty">' + parseInt( item.qty, 10 ) + '</span>' +
                        '<button class="dd-cart-stepper__btn dd-cart-plus" data-key="' + key + '" aria-label="Increase">&#43;</button>' +
                    '</div>' +
                '</div>' +
                '<div class="dd-cart-drawer__item-right">' +
                    '<span class="dd-cart-drawer__item-price">' + lineTotal + '</span>' +
                    '<button class="dd-cart-remove" data-key="' + key + '" aria-label="Remove item">&#10005;</button>' +
                '</div>' +
            '</div>';
        } ).join( '' );
    }

    /* ── NUDGE BAR ──────────────────────────────────────────── */
    function updateNudge( total ) {
        var nudge     = document.getElementById( 'ddCartNudge' );
        var fill      = document.getElementById( 'ddNudgeFill' );
        var remaining = document.getElementById( 'ddNudgeRemaining' );
        if ( ! nudge ) return;

        total = parseFloat( total ) || 0;

        if ( total <= 0 ) {
            nudge.style.display = 'none';
            return;
        }

        nudge.style.display = '';

        if ( total >= THRESHOLD ) {
            nudge.innerHTML =
                '<p class="dd-nudge__label dd-nudge__label--success">' +
                    '&#x2705; You unlocked FREE delivery!' +
                '</p>';
            return;
        }

        // Partially filled
        var pct = Math.min( Math.round( ( total / THRESHOLD ) * 100 ), 99 );
        if ( fill )      fill.style.width         = pct + '%';
        if ( fill )      fill.parentNode.setAttribute( 'aria-valuenow', pct );
        if ( remaining ) remaining.textContent     = formatNumber( THRESHOLD - total );
    }

    /* ── FOOTER ─────────────────────────────────────────────── */
    function updateFooter( data ) {
        var subtotalEl = document.getElementById( 'ddCartSubtotal' );
        var checkoutEl = document.getElementById( 'ddCartCheckout' );
        var hasItems   = data.items && data.items.length > 0;

        if ( subtotalEl ) {
            subtotalEl.textContent = formatPrice( data.subtotal || 0 );
        }

        if ( checkoutEl ) {
            checkoutEl.classList.toggle( 'dd-cart-drawer__checkout--disabled', ! hasItems );
            checkoutEl.setAttribute( 'aria-disabled', hasItems ? 'false' : 'true' );
            if ( hasItems ) {
                checkoutEl.removeAttribute( 'tabindex' );
            } else {
                checkoutEl.setAttribute( 'tabindex', '-1' );
            }
        }
    }

    /* ── BADGES ─────────────────────────────────────────────── */
    function updateBadges( count ) {
        count = parseInt( count, 10 ) || 0;

        // All badge element IDs to keep in sync
        var badgeIds = [
            'ddCartBtnCount',  // floating button
            'ddBottomBadge',   // mobile bottom nav
            'ddCartCount',     // header cart button
        ];

        badgeIds.forEach( function ( id ) {
            var el = document.getElementById( id );
            if ( ! el ) return;
            el.textContent    = count;
            el.style.display  = count > 0 ? '' : 'none';
        } );

        // Also update any .dd-cart-badge elements (generic class used in header)
        document.querySelectorAll( '.dd-cart-badge' ).forEach( function ( el ) {
            // Skip elements that already have a specific ID handled above
            if ( el.id && badgeIds.indexOf( el.id ) !== -1 ) return;
            el.textContent   = count;
            el.style.display = count > 0 ? '' : 'none';
        } );
    }

    /* ── TRACKING ───────────────────────────────────────────── */
    function trackEvent( type, meta ) {
        // Use DDTrack global if tracking.js has exposed it
        if ( window.DDTrack && typeof window.DDTrack.fire === 'function' ) {
            window.DDTrack.fire( type, meta );
            return;
        }
        // Fallback: fire AJAX directly (non-blocking, best-effort)
        var body = new FormData();
        body.append( 'action',     'dd_track_event' );
        body.append( 'nonce',      NONCE );
        body.append( 'event_type', type );
        body.append( 'meta',       JSON.stringify( meta || {} ) );
        fetch( AJAX_URL, { method: 'POST', body: body } ).catch( function () {} );
    }

    /* ── AJAX HELPER ────────────────────────────────────────── */
    function ajax( action, data, onSuccess ) {
        var body = new FormData();
        body.append( 'action', action );
        body.append( 'nonce',  NONCE );
        Object.keys( data ).forEach( function ( k ) { body.append( k, data[ k ] ); } );

        fetch( AJAX_URL, { method: 'POST', body: body } )
            .then( function ( r )   { return r.json(); } )
            .then( function ( res ) { if ( res.success ) onSuccess( res.data ); } )
            .catch( function ()     {} ); // silent — cart is enhancement, not critical path
    }

    /* ── FORMAT HELPERS ─────────────────────────────────────── */
    function formatPrice( value ) {
        return CURRENCY + ' ' + formatNumber( value );
    }

    function formatNumber( value ) {
        return parseFloat( value ).toLocaleString( 'en-RW', { maximumFractionDigits: 0 } );
    }

    function escHtml( str ) {
        var div = document.createElement( 'div' );
        div.textContent = String( str || '' );
        return div.innerHTML;
    }

    /* ── PUBLIC API ─────────────────────────────────────────── */
    window.DDCart = {
        open:    openCart,
        close:   closeCart,
        refresh: function () { fetchCart( true ); },
    };

})();
