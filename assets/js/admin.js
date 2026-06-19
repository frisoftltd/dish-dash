/* Dish Dash Admin JS */
( function ( $, config ) {
    'use strict';

    function ddFormatPaymentMethod( method ) {
        var map = {
            'cod':                  'Cash on Delivery',
            'pay_on_delivery':      'Cash on Delivery',
            'mtn_momo':             'MTN Mobile Money',
            'momo':                 'MTN Mobile Money',
            'irembopay':            'IremboPay',
            'pay_now':              'Card Payment',
            'bacs':                 'Bank Transfer',
            'cheque':               'Cheque',
            'alg_custom_gateway_1': 'Cash on Delivery',
        };
        return map[ method ] || ( method ? method.replace( /_/g, ' ' ).replace( /\b\w/g, function(c){ return c.toUpperCase(); } ) : 'Unknown' );
    }

    function ddTimeAgo( timestamp ) {
        var diff = Math.floor( ( Date.now() - timestamp ) / 1000 );
        if ( diff < 0 )     diff = 0;
        if ( diff < 60 )    return 'Just now';
        if ( diff < 3600 )  return Math.floor( diff / 60 ) + ' min ago';
        if ( diff < 86400 ) return Math.floor( diff / 3600 ) + ' hr ago';
        return Math.floor( diff / 86400 ) + ' days ago';
    }

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

    function saveNotifications() {
        try {
            // Keep only the 20 most recent items to avoid localStorage bloat
            var toSave = notifications.slice( 0, 20 );
            localStorage.setItem( 'dd_notifications', JSON.stringify( toSave ) );
        } catch ( e ) {}
    }

    // Start polling immediately
    initPolling();

    // Restore notifications panel from localStorage on page load
    ( function() {
        try {
            var saved = localStorage.getItem( 'dd_notifications' );
            if ( saved ) {
                var items = JSON.parse( saved );
                if ( Array.isArray( items ) && items.length > 0 ) {
                    // Rebuild panel in reverse so newest ends up on top
                    items.slice().reverse().forEach( function( item ) {
                        notifications.push( item );
                        addBellItem( item );
                    } );
                    // Restore unread count
                    var savedCount = parseInt( localStorage.getItem( 'dd_unread_count' ), 10 ) || 0;
                    if ( savedCount > 0 ) {
                        unreadCount = savedCount;
                        updateBadge();
                    }
                }
            }
        } catch ( e ) {}
    } )();

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
        localStorage.setItem( 'dd_unread_count', unreadCount );
    }

    setInterval( function() {
        if ( ! bellItems ) return;
        bellItems.querySelectorAll( '[data-item-type]' ).forEach( function( el ) {
            var id       = parseInt( el.dataset.id, 10 );
            var itemType = el.dataset.itemType;
            var found    = notifications.find( function(n) {
                return parseInt( n.id, 10 ) === id && n.type === itemType;
            } );
            if ( found && found.timestamp ) {
                var timeEl = el.querySelector( '.dd-bell-item-time' );
                if ( timeEl ) timeEl.textContent = ddTimeAgo( found.timestamp );
            }
        } );
    }, 60000 );

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
            notifications = [];
            localStorage.removeItem( 'dd_notifications' );
            localStorage.removeItem( 'dd_unread_count' );
            updateBadge();
            saveNotifications();
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

                // Remove bell items for orders no longer pending
                var currentPendingIds = new Set( newOrders.map( function(o) { return parseInt( o.id, 10 ); } ) );
                if ( bellItems ) {
                    bellItems.querySelectorAll( '[data-item-type="order"]' ).forEach( function( el ) {
                        if ( ! currentPendingIds.has( parseInt( el.dataset.id, 10 ) ) ) {
                            el.remove();
                        }
                    } );
                }

                if ( newOrders.length === 0 && newRes.length === 0 ) {
                    var orderCountR = bellItems ? bellItems.querySelectorAll( '[data-item-type="order"]' ).length : 0;
                    var resCountR   = bellItems ? bellItems.querySelectorAll( '[data-item-type="reservation"].dd-unread' ).length : 0;
                    unreadCount = orderCountR + resCountR;
                    updateBadge();
                    return;
                }

                // Build a set of order IDs already in the panel
                var existingOrderIds = new Set();
                if ( bellItems ) {
                    bellItems.querySelectorAll( '[data-item-type="order"]' ).forEach( function( el ) {
                        existingOrderIds.add( parseInt( el.dataset.id, 10 ) );
                    } );
                }

                var orderNotifCount = bellItems ? bellItems.querySelectorAll( '[data-item-type="order"]' ).length : 0;
                var shouldBeep = false;

                // Add to panel — skip orders already displayed
                newOrders.forEach( function ( order ) {
                    if ( existingOrderIds.has( parseInt( order.id, 10 ) ) ) return;

                    var orderNum = order.order_number || ( 'DD-' + String( order.id ).padStart( 5, '0' ) );
                    var total    = Number( order.total ).toLocaleString( 'en-US', { maximumFractionDigits: 0 } );
                    var item     = {
                        type:      'order',
                        id:        order.id,
                        title:     orderNum + ' · ' + order.customer_name,
                        meta:      total + ' RWF · ' + ddFormatPaymentMethod( order.payment_method ),
                        time:      'Just now',
                        timestamp: order.seconds_ago != null ? ( Date.now() - ( order.seconds_ago * 1000 ) ) : Date.now(),
                    };
                    notifications.unshift( item );
                    addBellItem( item );
                    orderNotifCount++;
                    shouldBeep = true;
                } );

                newRes.forEach( function ( r ) {
                    var guests = r.guests + ' guest' + ( parseInt( r.guests ) !== 1 ? 's' : '' );
                    var item   = {
                        type:      'reservation',
                        id:        r.id,
                        title:     r.name + ' · ' + r.date + ' at ' + r.time.substring( 0, 5 ),
                        meta:      guests,
                        time:      'Just now',
                        timestamp: r.seconds_ago != null ? ( Date.now() - ( r.seconds_ago * 1000 ) ) : Date.now(),
                    };
                    notifications.unshift( item );
                    addBellItem( item );
                    shouldBeep = true;
                } );

                // Update badge from DOM state
                var resItems = bellItems ? bellItems.querySelectorAll( '[data-item-type="reservation"].dd-unread' ).length : 0;
                unreadCount = orderNotifCount + resItems;
                updateBadge();

                if ( shouldBeep ) playBeep();

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
        var url = '';
        if ( item.type === 'order' && item.id ) {
            url = '/wp-admin/admin.php?page=dish-dash-orders&open_order=' + item.id;
        }
        if ( item.type === 'reservation' ) {
            url = '/wp-admin/admin.php?page=dd-reservations&open_reservation=' + item.id;
        }

        var el = document.createElement( 'a' );
        el.className        = 'dd-bell-item dd-unread';
        el.href             = url;
        el.dataset.orderId  = item.id;
        el.dataset.id       = item.id;
        el.dataset.itemType = item.type;
        el.innerHTML =
            '<div class="dd-bell-item-icon ' + iconClass + '">' + iconEmoji + '</div>'
            + '<div class="dd-bell-item-body">'
            +   '<span class="dd-bell-item-title">' + item.title + '</span>'
            +   '<span class="dd-bell-item-meta">' + item.meta + '</span>'
            + '</div>'
            + '<span class="dd-bell-item-time">' + ( item.timestamp ? ddTimeAgo( item.timestamp ) : item.time ) + '</span>';

        // Mark as read on click (client + server)
        el.addEventListener( 'click', function () {
            var self = this;
            const notifId = parseInt( self.dataset.id, 10 );

            if ( self.classList.contains( 'dd-unread' ) ) {
                self.classList.remove( 'dd-unread' );
                unreadCount = Math.max( 0, unreadCount - 1 );
                updateBadge();
            }

            if ( notifId && self.dataset.itemType === 'order' ) {
                // Remove from array and persist
                notifications = notifications.filter( function( n ) {
                    return !( n.type === 'order' && parseInt( n.id, 10 ) === notifId );
                } );
                saveNotifications();
                // Server-side mark read
                var fd = new FormData();
                fd.append( 'action',      'dd_mark_notifications_read' );
                fd.append( 'nonce',       config.pollNonce );
                fd.append( 'order_ids[]', notifId );
                fetch( config.ajaxUrl, { method: 'POST', body: fd } );
                // Remove from DOM
                self.remove();
            }

            if ( notifId && self.dataset.itemType === 'reservation' ) {
                notifications = notifications.filter( function( n ) {
                    return !( n.type === 'reservation' && parseInt( n.id, 10 ) === notifId );
                } );
                saveNotifications();
                // Remove from DOM
                self.remove();
            }
        } );

        bellItems.insertBefore( el, bellItems.firstChild );
        saveNotifications();
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
         window.location.href.indexOf( 'dd-reservations' ) > -1 ) {
        unreadCount = 0;
        updateBadge();
    }

} )( jQuery, window.dishDashAdmin || {} );
