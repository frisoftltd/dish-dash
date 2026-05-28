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
    var LS_NOTIF_INIT = 'dd_notif_initialised';
    var badge         = document.getElementById( 'dd-bell-badge' );
    var bellPanel     = document.getElementById( 'dd-bell-panel' );
    var bellItems     = document.getElementById( 'dd-bell-items' );
    var notifications = [];
    var unreadCount   = 0;

    // Start polling immediately
    initPolling();

    function initPolling() {
        if ( ! localStorage.getItem( LS_NOTIF_INIT ) ) {
            poll( true );
        } else {
            poll( false );
        }
        setInterval( function () { poll( false ); }, config.pollInterval || 30000 );
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

    // Bell icon click — toggle panel
    var bellWrap = document.querySelector( '#wp-admin-bar-dd-notifications > a' );
    if ( bellWrap ) {
        bellWrap.addEventListener( 'click', function ( e ) {
            e.preventDefault();
            e.stopPropagation();

            // Request notification permission on first click
            if ( typeof Notification !== 'undefined' && Notification.permission === 'default' ) {
                Notification.requestPermission();
            }

            if ( ! bellPanel ) return;
            var isOpen = bellPanel.style.display !== 'none';
            bellPanel.style.display = isOpen ? 'none' : 'block';
        } );
    }

    // Close panel on outside click
    document.addEventListener( 'click', function ( e ) {
        if ( bellPanel && ! bellPanel.contains( e.target ) && ! ( bellWrap && bellWrap.contains( e.target ) ) ) {
            bellPanel.style.display = 'none';
        }
    } );

    // Mark all read
    var markReadBtn = document.getElementById( 'dd-bell-mark-read' );
    if ( markReadBtn ) {
        markReadBtn.addEventListener( 'click', function () {
            unreadCount = 0;
            updateBadge();
            notifications = [];
            if ( bellItems ) bellItems.innerHTML = '<p class="dd-bell-empty">No new notifications</p>';
        } );
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

                // Add to panel
                newOrders.forEach( function ( order ) {
                    var orderNum = order.order_number || ( 'DD-' + String( order.id ).padStart( 5, '0' ) );
                    var total    = Number( order.total ).toLocaleString( 'en-US', { maximumFractionDigits: 0 } );
                    var item     = {
                        type:  'order',
                        id:    order.id,
                        title: '🛍 New Order · ' + orderNum,
                        meta:  order.customer_name + ' · ' + total + ' RWF',
                        time:  'Just now',
                        url:   config.ajaxUrl.replace( 'admin-ajax.php', 'admin.php' ) + '?page=dish-dash-orders',
                    };
                    notifications.unshift( item );
                    addBellItem( item );
                } );

                newRes.forEach( function ( r ) {
                    var guests = r.guests + ' guest' + ( parseInt( r.guests ) !== 1 ? 's' : '' );
                    var item   = {
                        type:  'reservation',
                        id:    r.id,
                        title: '📅 New Reservation · ' + r.date + ' ' + r.time.substring( 0, 5 ),
                        meta:  guests + ' · ' + r.name,
                        time:  'Just now',
                        url:   config.ajaxUrl.replace( 'admin-ajax.php', 'admin.php' ) + '?page=dish-dash-reservations',
                    };
                    notifications.unshift( item );
                    addBellItem( item );
                } );

                // Play sound
                playBeep();

                // Browser notifications
                var canNotify = typeof Notification !== 'undefined'
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

                newRes.forEach( function ( r ) {
                    var guests = r.guests + ' guest' + ( parseInt( r.guests ) !== 1 ? 's' : '' );
                    var time   = r.date + ' ' + r.time.substring( 0, 5 );
                    if ( canNotify ) {
                        new Notification( '📅 New Reservation — ' + config.restaurantName, {
                            body: time + ' · ' + guests + ' · ' + r.name,
                            tag:  'dd-res-' + r.id,
                        } );
                    }
                } );
            } )
            .catch( function () {} ); // Silent fail — don't break admin on network error
    }

    function addBellItem( item ) {
        if ( ! bellItems ) return;
        var empty = bellItems.querySelector( '.dd-bell-empty' );
        if ( empty ) empty.remove();

        var iconClass = item.type === 'order' ? 'dd-icon-order' : 'dd-icon-reservation';
        var iconEmoji = item.type === 'order' ? '🛍' : '📅';

        // Build URL — orders get open_order param to trigger modal
        var url = item.url;
        if ( item.type === 'order' ) {
            url = config.ajaxUrl.replace( 'admin-ajax.php', 'admin.php' )
                + '?page=dish-dash-orders&open_order=' + item.id;
        }

        var el = document.createElement( 'a' );
        el.className        = 'dd-bell-item dd-unread';
        el.href             = url;
        el.dataset.orderId  = item.id;
        el.dataset.itemType = item.type;
        el.innerHTML =
            '<div class="dd-bell-item-icon ' + iconClass + '">' + iconEmoji + '</div>'
            + '<div class="dd-bell-item-body">'
            +   '<span class="dd-bell-item-title">' + item.title + '</span>'
            +   '<span class="dd-bell-item-meta">' + item.meta + '</span>'
            + '</div>'
            + '<span class="dd-bell-item-time">' + item.time + '</span>';

        // Mark as read on click
        el.addEventListener( 'click', function () {
            if ( this.classList.contains( 'dd-unread' ) ) {
                this.classList.remove( 'dd-unread' );
                unreadCount = Math.max( 0, unreadCount - 1 );
                updateBadge();
            }
        } );

        bellItems.insertBefore( el, bellItems.firstChild );
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
