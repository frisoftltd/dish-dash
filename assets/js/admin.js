/* Dish Dash Admin JS */
( function ( $, config ) {
    'use strict';

    // ── Existing: confirm delete ──────────────────────────────────────────────
    $( document ).on( 'click', '.dd-confirm-delete', function ( e ) {
        if ( ! confirm( config.i18n.confirmDelete ) ) {
            e.preventDefault();
        }
    } );

    // ── Existing: auto-fade notices ───────────────────────────────────────────
    setTimeout( function () {
        $( '.dd-admin-notice-auto' ).fadeOut( 600 );
    }, 4000 );

    // ── Notification system ───────────────────────────────────────────────────

    var LS_ORDER_ID   = 'dd_last_order_id';
    var LS_RES_ID     = 'dd_last_res_id';
    var LS_NOTIF_OPT  = 'dd_notif_opted_in';
    var LS_NOTIF_INIT = 'dd_notif_initialised';
    var badge         = document.getElementById( 'dd-menu-badge' );
    var unreadCount   = 0;

    // Show opt-in banner if not yet opted in and not dismissed
    if ( ! localStorage.getItem( LS_NOTIF_OPT ) ) {
        showOptInBanner();
    } else {
        initPolling();
    }

    function showOptInBanner() {
        var banner = document.createElement( 'div' );
        banner.id        = 'dd-notif-banner';
        banner.innerHTML =
            '<span class="dd-notif-banner-text">🔔 Enable notifications to get alerted when new orders or reservations arrive</span>'
            + '<button type="button" id="dd-notif-enable" class="dd-notif-btn-enable">Enable</button>'
            + '<button type="button" id="dd-notif-dismiss" class="dd-notif-btn-dismiss">Not now</button>';
        document.body.appendChild( banner );

        document.getElementById( 'dd-notif-enable' ).addEventListener( 'click', function () {
            Notification.requestPermission().then( function ( permission ) {
                localStorage.setItem( LS_NOTIF_OPT, permission === 'granted' ? 'granted' : 'denied' );
                banner.remove();
                initPolling();
            } );
        } );

        document.getElementById( 'dd-notif-dismiss' ).addEventListener( 'click', function () {
            localStorage.setItem( LS_NOTIF_OPT, 'dismissed' );
            banner.remove();
            // Still poll — just no browser notifications
            initPolling();
        } );
    }

    function initPolling() {
        // On first run — initialise last known IDs by polling once silently
        if ( ! localStorage.getItem( LS_NOTIF_INIT ) ) {
            poll( true );
        } else {
            poll( false );
        }
        setInterval( function () { poll( false ); }, config.pollInterval || 30000 );
    }

    function poll( silent ) {
        var lastOrderId = parseInt( localStorage.getItem( LS_ORDER_ID ) || '0', 10 );
        var lastResId   = parseInt( localStorage.getItem( LS_RES_ID ) || '0', 10 );

        var data = new FormData();
        data.append( 'action',        'dd_poll_notifications' );
        data.append( 'nonce',         config.pollNonce );
        data.append( 'last_order_id', lastOrderId );
        data.append( 'last_res_id',   lastResId );

        fetch( config.ajaxUrl, { method: 'POST', body: data } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( ! res.success ) return;

                var payload = res.data.data || res.data;

                // Store new max IDs
                if ( payload.max_order_id ) localStorage.setItem( LS_ORDER_ID, payload.max_order_id );
                if ( payload.max_res_id )   localStorage.setItem( LS_RES_ID, payload.max_res_id );

                // Mark as initialised after first silent poll
                if ( silent ) {
                    localStorage.setItem( LS_NOTIF_INIT, '1' );
                    return;
                }

                var newOrders = payload.new_orders || [];
                var newRes    = payload.new_reservations || [];

                if ( newOrders.length === 0 && newRes.length === 0 ) return;

                // Update badge
                unreadCount += newOrders.length + newRes.length;
                updateBadge();

                // Play sound
                playBeep();

                // Browser notifications
                var canNotify = localStorage.getItem( LS_NOTIF_OPT ) === 'granted'
                    && typeof Notification !== 'undefined'
                    && Notification.permission === 'granted';

                newOrders.forEach( function ( order ) {
                    var orderNum = order.order_number || ( 'DD-' + String( order.id ).padStart( 5, '0' ) );
                    var total    = Number( order.total ).toLocaleString( 'en-US', { maximumFractionDigits: 0 } );

                    if ( canNotify ) {
                        new Notification( '🔔 New Order — ' + config.restaurantName, {
                            body: orderNum + ' · ' + order.customer_name + ' · ' + total + ' RWF',
                            icon: '/wp-admin/images/wordpress-logo.svg',
                            tag:  'dd-order-' + order.id,
                        } );
                    }
                } );

                newRes.forEach( function ( res ) {
                    var guests = res.guests + ' guest' + ( res.guests !== '1' ? 's' : '' );
                    var time   = res.date + ' ' + res.time.substring( 0, 5 );

                    if ( canNotify ) {
                        new Notification( '📅 New Reservation — ' + config.restaurantName, {
                            body: time + ' · ' + guests + ' · ' + res.name,
                            tag:  'dd-res-' + res.id,
                        } );
                    }
                } );
            } )
            .catch( function () {} ); // Silent fail — don't break admin on network error
    }

    function updateBadge() {
        if ( ! badge ) return;
        if ( unreadCount > 0 ) {
            badge.textContent   = unreadCount > 9 ? '9+' : unreadCount;
            badge.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
        }
    }

    function playBeep() {
        try {
            var ctx  = new ( window.AudioContext || window.webkitAudioContext )();
            var osc  = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect( gain );
            gain.connect( ctx.destination );
            osc.frequency.value = 880;
            osc.type            = 'sine';
            gain.gain.setValueAtTime( 0.3, ctx.currentTime );
            gain.gain.exponentialRampToValueAtTime( 0.001, ctx.currentTime + 0.4 );
            osc.start( ctx.currentTime );
            osc.stop( ctx.currentTime + 0.4 );
        } catch ( e ) {} // Silent fail if AudioContext unavailable
    }

    // Clear badge when visiting Orders or Reservations page
    if ( window.location.href.indexOf( 'dish-dash-orders' ) > -1 ||
         window.location.href.indexOf( 'dish-dash-reservations' ) > -1 ) {
        unreadCount = 0;
        updateBadge();
    }

} )( jQuery, window.dishDashAdmin || {} );
