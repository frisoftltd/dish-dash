/*
 * File:    assets/js/kitchen-screen.js
 * Purpose: Kitchen screen — polls dd_kitchen_feed every 5s, renders
 *          confirmed/preparing-order cards, requests Screen Wake Lock
 *          where supported. Start Preparing / Mark Ready both call the
 *          existing dd_update_status action directly (no duplicated
 *          status logic).
 *
 *          v3.18.55 — sound model rewritten: ONE shared repeating alert
 *          (never per-card overlapping instances) that fires while any
 *          visible card is unacknowledged. Tapping a card acknowledges it
 *          — stored in localStorage per order id, per device, so other
 *          devices keep alerting. A header Mute button silences the
 *          alert for 5 minutes with a countdown; tapping it again ends
 *          the mute early; a new card during a mute still highlights,
 *          just stays silent. Also adds the 'preparing' stage: a New
 *          (confirmed) card shows Start Preparing; a Preparing card shows
 *          Mark Ready plus a "time since preparing" counter. The late
 *          flag is still computed from confirmed_at + prep time at every
 *          stage, unchanged.
 *
 * Expects window.ddKitchenData = { ajaxUrl, nonce, soundUrl }.
 *
 * Last modified: v3.18.55
 */
(function () {
    'use strict';

    var POLL_INTERVAL_MS  = 5000;
    var ALERT_INTERVAL_MS = 2500;
    var MUTE_DURATION_MS  = 5 * 60 * 1000;
    var SOUND_SESSION_KEY = 'dd_ks_sound_enabled';
    var ACK_PREFIX         = 'dd_ks_ack_';

    var grid        = document.getElementById( 'dd-ks-grid' );
    var emptyState  = document.getElementById( 'dd-ks-empty' );
    var statusPill  = document.getElementById( 'dd-ks-status' );
    var statusText  = document.getElementById( 'dd-ks-status-text' );
    var overlay     = document.getElementById( 'dd-ks-overlay' );
    var enableBtn   = document.getElementById( 'dd-ks-enable-sound' );
    var toastEl     = document.getElementById( 'dd-ks-toast' );
    var muteBtn     = document.getElementById( 'dd-ks-mute-btn' );

    var since           = '';
    var needsFullResync = false;
    var cardsById       = {};   // id -> { data, el }
    var soundEnabled    = false;
    var audioEl         = null;
    var wakeLock        = null;
    var lastPrepTime    = 30;  // minutes, updated from each poll response

    var mutedUntil         = null; // ms epoch, client-side UI timer only
    var muteCountdownTimer = null;

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

        if ( muteBtn ) {
            muteBtn.addEventListener( 'click', toggleMute );
            updateMuteButton();
        }

        // Wake Lock doesn't require a user gesture by spec — try immediately
        // too, failing quietly if unsupported (brief requirement).
        requestWakeLock();
        document.addEventListener( 'visibilitychange', function () {
            if ( wakeLock !== null && document.visibilityState === 'visible' ) {
                requestWakeLock();
            }
        } );

        setInterval( tickTimers, 1000 );
        setInterval( alertTick, ALERT_INTERVAL_MS );

        poll();
    }

    function requestWakeLock() {
        if ( ! ( 'wakeLock' in navigator ) ) return;
        navigator.wakeLock.request( 'screen' )
            .then( function ( lock ) { wakeLock = lock; } )
            .catch( function () { /* fail quietly — not supported/allowed here */ } );
    }

    // ── Acknowledgment (per device, survives reloads) ───────────────────────
    function isAcked( id ) {
        return localStorage.getItem( ACK_PREFIX + id ) === '1';
    }
    function setAcked( id ) {
        localStorage.setItem( ACK_PREFIX + id, '1' );
    }
    function clearAcked( id ) {
        localStorage.removeItem( ACK_PREFIX + id );
    }

    // ── Shared alert loop — one Audio element, one timer, never overlapping ──
    function alertTick() {
        if ( ! soundEnabled || isMuted() ) return;

        var hasUnacked = Object.keys( cardsById ).some( function ( id ) {
            return ! isAcked( id );
        } );
        if ( ! hasUnacked ) return;

        try { audioEl.currentTime = 0; } catch ( e ) {}
        audioEl.play().catch( function () {} );
    }

    // ── Mute ─────────────────────────────────────────────────────────────────
    function isMuted() {
        return mutedUntil !== null && Date.now() < mutedUntil;
    }

    function toggleMute() {
        if ( isMuted() ) {
            endMute();
        } else {
            startMute();
        }
    }

    function startMute() {
        mutedUntil = Date.now() + MUTE_DURATION_MS;
        updateMuteButton();
        if ( muteCountdownTimer ) clearInterval( muteCountdownTimer );
        muteCountdownTimer = setInterval( function () {
            if ( ! isMuted() ) {
                endMute();
            } else {
                updateMuteButton();
            }
        }, 1000 );
    }

    function endMute() {
        mutedUntil = null;
        if ( muteCountdownTimer ) {
            clearInterval( muteCountdownTimer );
            muteCountdownTimer = null;
        }
        updateMuteButton();
        // "when mute ends, unacknowledged cards alert again" — the next
        // alertTick() tick resumes on its own since isMuted() now reads
        // false; nothing else to trigger here.
    }

    function updateMuteButton() {
        if ( ! muteBtn ) return;
        if ( isMuted() ) {
            var remaining = Math.max( 0, Math.ceil( ( mutedUntil - Date.now() ) / 1000 ) );
            muteBtn.textContent = '🔇 Muted (' + fmtMinSec( remaining ) + ')';
            muteBtn.classList.add( 'dd-ks-mute-btn--active' );
        } else {
            muteBtn.textContent = '🔔 Mute';
            muteBtn.classList.remove( 'dd-ks-mute-btn--active' );
        }
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
                setOnline();
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
        lastPrepTime = prepTime;
        orders.forEach( function ( order ) {
            if ( 'confirmed' === order.status || 'preparing' === order.status ) {
                upsertCard( order, prepTime );
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

    function upsertCard( order, prepTime ) {
        var existing = cardsById[ order.id ];
        var acked    = isAcked( order.id );

        if ( existing ) {
            existing.data = order;
            renderCardBody( existing.el, order, prepTime );
            existing.el.classList.toggle( 'dd-ks-card--new', ! acked );
            return;
        }

        var el = document.createElement( 'div' );
        el.className = 'dd-ks-card' + ( acked ? '' : ' dd-ks-card--new' );
        el.dataset.orderId = order.id;
        renderCardBody( el, order, prepTime );
        grid.appendChild( el );

        cardsById[ order.id ] = { data: order, el: el };

        // Tapping anywhere on the card acknowledges it — stored per device,
        // so a device that never taps keeps alerting even if the order
        // moves to Preparing via another device's tap.
        el.addEventListener( 'click', function () {
            if ( isAcked( order.id ) ) return;
            setAcked( order.id );
            el.classList.remove( 'dd-ks-card--new' );
        } );
    }

    function removeCard( id ) {
        var entry = cardsById[ id ];
        if ( ! entry ) return;
        if ( entry.el.parentNode ) entry.el.parentNode.removeChild( entry.el );
        delete cardsById[ id ];
        clearAcked( id );
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

        var isPreparing = 'preparing' === order.status;

        var stageHtml = isPreparing
            ? '<span class="dd-ks-card__stage dd-ks-card__stage--preparing">Preparing</span>'
            : '<span class="dd-ks-card__stage dd-ks-card__stage--new">New</span>';

        var actionHtml = isPreparing
            ? '<button type="button" class="dd-ks-card__action dd-ks-card__ready" data-order-id="' + order.id + '" data-next-status="ready">✓ Mark Ready</button>'
            : '<button type="button" class="dd-ks-card__action dd-ks-card__start" data-order-id="' + order.id + '" data-next-status="preparing">▶ Start Preparing</button>';

        el.innerHTML =
            '<div class="dd-ks-card__header">' +
                '<span class="dd-ks-card__number">' + esc( order.order_number ) + '</span>' +
                stageHtml +
                '<span class="dd-ks-card__type">' + esc( ucfirst( order.order_type || 'delivery' ) ) + '</span>' +
            '</div>' +
            '<div class="dd-ks-card__customer">' + esc( order.customer_name || '' ) + '</div>' +
            '<div class="dd-ks-card__items">' + itemsHtml + '</div>' +
            instructionsHtml +
            '<div class="dd-ks-card__timer"' +
                ' data-confirmed="' + esc( order.confirmed_at || '' ) + '"' +
                ' data-preparing="' + esc( order.preparing_at || '' ) + '"' +
                ' data-prep="' + ( prepTime * 60 ) + '"' +
                ' data-stage="' + ( isPreparing ? 'preparing' : 'confirmed' ) + '">--:--</div>' +
            actionHtml;

        el.querySelector( '.dd-ks-card__action' ).addEventListener( 'click', function ( e ) {
            e.stopPropagation(); // don't double-fire the card's own tap-to-acknowledge click
            changeStatus( order.id, this.dataset.nextStatus, this );
        } );

        tickTimerFor( el );
    }

    // ── Elapsed-time / late state ────────────────────────────────────────────
    // Late is always computed from confirmed_at + prep time, regardless of
    // stage — a Preparing card can still be "late" if it took too long to
    // even start, per the brief. The visible counter text is stage-specific.
    function tickTimers() {
        grid.querySelectorAll( '.dd-ks-card' ).forEach( tickTimerFor );
    }

    function tickTimerFor( cardEl ) {
        var timerEl = cardEl.querySelector( '.dd-ks-card__timer' );
        if ( ! timerEl ) return;

        var confirmedAt = parseServerTime( timerEl.dataset.confirmed );
        var prepSec     = parseInt( timerEl.dataset.prep, 10 ) || 1800;

        var isLate = false;
        var sinceConfirmedSec = null;
        if ( confirmedAt !== null ) {
            sinceConfirmedSec = Math.max( 0, Math.floor( ( Date.now() - confirmedAt ) / 1000 ) );
            isLate = sinceConfirmedSec > prepSec;
        }
        cardEl.classList.toggle( 'dd-ks-card--late', isLate );

        if ( 'preparing' === timerEl.dataset.stage ) {
            var preparingAt = parseServerTime( timerEl.dataset.preparing );
            if ( preparingAt === null ) {
                // Optimistic local transition — the real preparing_at arrives
                // on the next poll (within 5s), nothing fabricated here.
                timerEl.textContent = 'Just started preparing…';
                return;
            }
            var sincePrep = Math.max( 0, Math.floor( ( Date.now() - preparingAt ) / 1000 ) );
            timerEl.innerHTML = fmtMinSec( sincePrep ) + ' preparing' +
                ( sinceConfirmedSec !== null
                    ? '<span class="dd-ks-card__timer-sub">' + fmtMinSec( sinceConfirmedSec ) + ' total since confirmed</span>'
                    : '' );
        } else {
            timerEl.textContent = sinceConfirmedSec !== null
                ? fmtMinSec( sinceConfirmedSec ) + ' since confirmed'
                : '--:--';
        }
    }

    function parseServerTime( s ) {
        if ( ! s ) return null;
        var t = new Date( s.replace( ' ', 'T' ) ).getTime();
        return isNaN( t ) ? null : t;
    }

    function fmtMinSec( totalSec ) {
        var m = Math.floor( totalSec / 60 );
        var s = totalSec % 60;
        return m + ':' + ( s < 10 ? '0' : '' ) + s;
    }

    // ── Start Preparing / Mark Ready — both call dd_update_status directly ──
    function changeStatus( orderId, nextStatus, btnEl ) {
        btnEl.disabled = true;
        var originalText  = btnEl.textContent;
        btnEl.textContent = 'ready' === nextStatus ? 'Marking ready…' : 'Starting…';

        var data = new FormData();
        data.append( 'action', 'dd_update_status' );
        data.append( 'order_id', orderId );
        data.append( 'status', nextStatus );
        data.append( 'nonce', window.ddKitchenData.nonce );

        fetch( window.ddKitchenData.ajaxUrl, { method: 'POST', body: data } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( ! res.success ) {
                    btnEl.disabled = false;
                    btnEl.textContent = originalText;
                    showToast( 'Could not update — try again' );
                    return;
                }

                if ( 'ready' === nextStatus ) {
                    removeCard( orderId );
                    emptyState.style.display = Object.keys( cardsById ).length ? 'none' : 'block';
                    return;
                }

                // 'preparing' — optimistic re-render now; the authoritative
                // preparing_at lands on the next poll (≤5s), same pattern the
                // Orders page modal already uses for its own status changes.
                var entry = cardsById[ orderId ];
                if ( entry ) {
                    entry.data.status       = 'preparing';
                    entry.data.preparing_at = entry.data.preparing_at || null;
                    renderCardBody( entry.el, entry.data, lastPrepTime );
                }
            } )
            .catch( function () {
                btnEl.disabled = false;
                btnEl.textContent = originalText;
                showToast( 'Could not update — try again' );
            } );
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
