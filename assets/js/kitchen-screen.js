/*
 * File:    assets/js/kitchen-screen.js
 * Purpose: Kitchen screen (v3.18.54) — polls dd_kitchen_feed every 5s,
 *          renders confirmed-order cards, plays an alert sound on new
 *          orders until tapped, Mark Ready calls the existing
 *          dd_update_status action directly (no duplicated status logic),
 *          requests Screen Wake Lock where supported.
 *
 * Expects window.ddKitchenData = { ajaxUrl, nonce, soundUrl }.
 *
 * Last modified: v3.18.54
 */
(function () {
    'use strict';

    var POLL_INTERVAL_MS   = 5000;
    var SOUND_SESSION_KEY  = 'dd_ks_sound_enabled';

    var grid        = document.getElementById( 'dd-ks-grid' );
    var emptyState  = document.getElementById( 'dd-ks-empty' );
    var statusPill  = document.getElementById( 'dd-ks-status' );
    var statusText  = document.getElementById( 'dd-ks-status-text' );
    var overlay     = document.getElementById( 'dd-ks-overlay' );
    var enableBtn   = document.getElementById( 'dd-ks-enable-sound' );
    var toastEl     = document.getElementById( 'dd-ks-toast' );

    var since           = '';
    var needsFullResync = false;
    var cardsById        = {};   // id -> { data, el, timerEl, soundStop }
    var soundEnabled      = false;
    var audioEl          = null;
    var wakeLock          = null;

    init();

    function init() {
        audioEl = new Audio( window.ddKitchenData.soundUrl );
        audioEl.preload = 'auto';

        if ( sessionStorage.getItem( SOUND_SESSION_KEY ) === '1' ) {
            soundEnabled = true;
            overlay.style.display = 'none';
        } else {
            overlay.style.display = 'flex';
        }

        enableBtn.addEventListener( 'click', function () {
            soundEnabled = true;
            sessionStorage.setItem( SOUND_SESSION_KEY, '1' );
            overlay.style.display = 'none';
            // Plays once as audible confirmation and satisfies the browser's
            // user-gesture requirement for later unprompted playback calls.
            audioEl.play().catch( function () {} );
            requestWakeLock();
        } );

        // Wake Lock doesn't require a user gesture by spec — try immediately
        // too, failing quietly if unsupported (brief requirement).
        requestWakeLock();
        document.addEventListener( 'visibilitychange', function () {
            if ( wakeLock !== null && document.visibilityState === 'visible' ) {
                requestWakeLock();
            }
        } );

        setInterval( tickTimers, 1000 );

        poll();
    }

    function requestWakeLock() {
        if ( ! ( 'wakeLock' in navigator ) ) return;
        navigator.wakeLock.request( 'screen' )
            .then( function ( lock ) { wakeLock = lock; } )
            .catch( function () { /* fail quietly — not supported/allowed here */ } );
    }

    // ── Polling ──────────────────────────────────────────────────────────────
    function poll() {
        var data = new FormData();
        data.append( 'action', 'dd_kitchen_feed' );
        data.append( 'nonce', window.ddKitchenData.nonce );
        if ( since && ! needsFullResync ) {
            data.append( 'since', since );
        }

        fetch( window.ddKitchenData.ajaxUrl, { method: 'POST', body: data } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( ! res.success ) throw new Error( 'bad response' );
                var wasOffline = ( statusPill.dataset.offline === '1' );
                setOnline();
                if ( wasOffline ) {
                    // "On reconnect, refresh the full list" — the cursor we
                    // were holding may be stale after an outage, so the next
                    // call (not this one — this one already used since='')
                    // doesn't need special handling; needsFullResync covers
                    // the case where THIS call itself was the first retry.
                }
                var payload = res.data && res.data.data ? res.data.data : res.data;
                since           = payload.cursor;
                needsFullResync = false;
                applyOrders( payload.orders || [], payload.prep_time || 30 );
            } )
            .catch( function () {
                setOffline();
                needsFullResync = true; // reconnect = full resync, not a stale delta
            } )
            .finally( function () {
                setTimeout( poll, POLL_INTERVAL_MS );
            } );
    }

    function setOnline() {
        statusPill.dataset.offline = '0';
        statusPill.classList.remove( 'dd-ks-status--offline' );
        statusText.textContent = 'Live';
    }

    function setOffline() {
        statusPill.dataset.offline = '1';
        statusPill.classList.add( 'dd-ks-status--offline' );
        statusText.textContent = 'Offline, retrying…';
    }

    // ── Apply feed results ───────────────────────────────────────────────────
    function applyOrders( orders, prepTime ) {
        orders.forEach( function ( order ) {
            if ( 'confirmed' === order.status ) {
                var isNew = ! cardsById[ order.id ];
                upsertCard( order, prepTime, isNew );
            } else if ( cardsById[ order.id ] ) {
                var removedData = cardsById[ order.id ].data;
                removeCard( order.id );
                if ( 'cancelled' === order.status ) {
                    showToast( ( removedData.order_number || ( 'Order #' + order.id ) ) + ' was cancelled' );
                }
            }
        } );
        emptyState.style.display = Object.keys( cardsById ).length ? 'none' : 'block';
    }

    function upsertCard( order, prepTime, isNew ) {
        var existing = cardsById[ order.id ];

        if ( existing ) {
            existing.data = order;
            renderCardBody( existing.el, order, prepTime );
            return;
        }

        var el = document.createElement( 'div' );
        el.className = 'dd-ks-card' + ( isNew ? ' dd-ks-card--new' : '' );
        el.dataset.orderId = order.id;
        renderCardBody( el, order, prepTime );
        grid.appendChild( el );

        cardsById[ order.id ] = { data: order, el: el };

        if ( isNew ) {
            if ( soundEnabled ) {
                playAlertLoop( order.id );
            }
            var dismiss = function () {
                el.classList.remove( 'dd-ks-card--new' );
                stopAlertLoop( order.id );
                el.removeEventListener( 'click', dismiss );
            };
            el.addEventListener( 'click', dismiss );
        }
    }

    function removeCard( id ) {
        var entry = cardsById[ id ];
        if ( ! entry ) return;
        stopAlertLoop( id );
        if ( entry.el.parentNode ) entry.el.parentNode.removeChild( entry.el );
        delete cardsById[ id ];
    }

    function renderCardBody( el, order, prepTime ) {
        var itemsHtml = ( order.items || [] ).map( function ( item ) {
            var line = '<div class="dd-ks-item__line">' + esc( item.quantity ) + '× ' + esc( item.item_name ) + '</div>';
            ( item.variation_lines || [] ).forEach( function ( v ) {
                line += '<div class="dd-ks-item__sub">' + esc( v ) + '</div>';
            } );
            ( item.addon_lines || [] ).forEach( function ( a ) {
                line += '<div class="dd-ks-item__sub">+ ' + esc( a ) + '</div>';
            } );
            if ( item.special_note ) {
                // special_note is already HTML-escaped server-side (DD_Notifications::clean_note()).
                line += '<div class="dd-ks-item__note">Note: ' + item.special_note + '</div>';
            }
            return line;
        } ).join( '' );

        var instructionsHtml = order.special_instructions
            ? '<div class="dd-ks-card__instructions">' + esc( order.special_instructions ) + '</div>'
            : '';

        el.innerHTML =
            '<div class="dd-ks-card__header">' +
                '<span class="dd-ks-card__number">' + esc( order.order_number ) + '</span>' +
                '<span class="dd-ks-card__type">' + esc( ucfirst( order.order_type || 'delivery' ) ) + '</span>' +
            '</div>' +
            '<div class="dd-ks-card__customer">' + esc( order.customer_name || '' ) + '</div>' +
            '<div class="dd-ks-card__items">' + itemsHtml + '</div>' +
            instructionsHtml +
            '<div class="dd-ks-card__timer" data-confirmed="' + esc( order.confirmed_at ) + '" data-prep="' + ( prepTime * 60 ) + '">--:--</div>' +
            '<button type="button" class="dd-ks-card__ready" data-order-id="' + order.id + '">✓ Mark Ready</button>';

        el.querySelector( '.dd-ks-card__ready' ).addEventListener( 'click', function () {
            markReady( order.id, this );
        } );

        tickTimerFor( el );
    }

    // ── Elapsed-time / late state ────────────────────────────────────────────
    function tickTimers() {
        grid.querySelectorAll( '.dd-ks-card' ).forEach( tickTimerFor );
    }

    function tickTimerFor( cardEl ) {
        var timerEl = cardEl.querySelector( '.dd-ks-card__timer' );
        if ( ! timerEl || ! timerEl.dataset.confirmed ) return;

        var confirmedAt = new Date( timerEl.dataset.confirmed.replace( ' ', 'T' ) ).getTime();
        if ( isNaN( confirmedAt ) ) return;

        var elapsedSec = Math.max( 0, Math.floor( ( Date.now() - confirmedAt ) / 1000 ) );
        var prepSec     = parseInt( timerEl.dataset.prep, 10 ) || 1800;
        var mins = Math.floor( elapsedSec / 60 );
        var secs = elapsedSec % 60;

        timerEl.textContent = mins + ':' + ( secs < 10 ? '0' : '' ) + secs + ' since confirmed';

        var isLate = elapsedSec > prepSec;
        cardEl.classList.toggle( 'dd-ks-card--late', isLate );
    }

    // ── Mark Ready ───────────────────────────────────────────────────────────
    function markReady( orderId, btnEl ) {
        btnEl.disabled = true;
        btnEl.textContent = 'Marking ready…';

        var data = new FormData();
        data.append( 'action', 'dd_update_status' );
        data.append( 'order_id', orderId );
        data.append( 'status', 'ready' );
        data.append( 'nonce', window.ddKitchenData.nonce );

        fetch( window.ddKitchenData.ajaxUrl, { method: 'POST', body: data } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( res.success ) {
                    removeCard( orderId );
                    emptyState.style.display = Object.keys( cardsById ).length ? 'none' : 'block';
                } else {
                    btnEl.disabled = false;
                    btnEl.textContent = '✓ Mark Ready';
                    showToast( 'Could not mark ready — try again' );
                }
            } )
            .catch( function () {
                btnEl.disabled = false;
                btnEl.textContent = '✓ Mark Ready';
                showToast( 'Could not mark ready — try again' );
            } );
    }

    // ── Sound loop (plays until the card is tapped) ──────────────────────────
    var soundTimers = {};
    function playAlertLoop( id ) {
        var play = function () {
            var a = new Audio( window.ddKitchenData.soundUrl );
            a.play().catch( function () {} );
        };
        play();
        soundTimers[ id ] = setInterval( play, 2500 );
    }
    function stopAlertLoop( id ) {
        if ( soundTimers[ id ] ) {
            clearInterval( soundTimers[ id ] );
            delete soundTimers[ id ];
        }
    }

    // ── Toast ────────────────────────────────────────────────────────────────
    var toastTimer = null;
    function showToast( message ) {
        toastEl.textContent = message;
        toastEl.classList.add( 'dd-ks-toast--visible' );
        if ( toastTimer ) clearTimeout( toastTimer );
        toastTimer = setTimeout( function () {
            toastEl.classList.remove( 'dd-ks-toast--visible' );
        }, 4000 );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    function esc( s ) {
        return String( s == null ? '' : s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }
    function ucfirst( s ) {
        s = String( s || '' );
        return s.charAt( 0 ).toUpperCase() + s.slice( 1 );
    }
})();
