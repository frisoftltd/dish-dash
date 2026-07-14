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
            'pesapal':              'PesaPal',
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

    // Panel is rebuilt authoritatively from the server on each poll — no localStorage restore.

    // Start polling immediately — gated by the dashboard-notify setting (default on).
    // Only an explicit false (setting saved off) suppresses it; a missing flag polls.
    if ( config.notifyEnabled !== false ) {
        initPolling();
    }

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
            badge.textContent   = unreadCount > 99 ? '99+' : unreadCount;
            badge.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
        }
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

    // "Mark all read" removed — pending items clear only when confirmed/cancelled.

    function poll( silent ) {
        var data = new FormData();
        data.append( 'action', 'dd_poll_notifications' );
        data.append( 'nonce',  config.pollNonce );

        fetch( config.ajaxUrl, { method: 'POST', body: data } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( ! res.success ) return;
                var payload = res.data.data || res.data;
                var items   = payload.pending_items || [];

                // Detect genuinely new items (by id+type) vs what we last saw, for the beep.
                var newKeys  = items.map( function ( it ) { return it.type + ':' + it.id; } );
                var prevKeys = notifications.map( function ( n ) { return n.type + ':' + n.id; } );
                var hasNew   = newKeys.some( function ( k ) { return prevKeys.indexOf( k ) === -1; } );

                // Rebuild the authoritative list.
                notifications = items.map( function ( it ) {
                    return {
                        type:      it.type,
                        id:        it.id,
                        title:     it.title,
                        meta:      it.meta,
                        timestamp: it.seconds_ago != null ? ( Date.now() - ( it.seconds_ago * 1000 ) ) : Date.now(),
                    };
                } );

                // Re-render the panel from scratch.
                if ( bellItems ) {
                    bellItems.innerHTML = '';
                    if ( notifications.length === 0 ) {
                        bellItems.innerHTML = '<p class="dd-bell-empty">No pending items</p>';
                    } else {
                        notifications.forEach( function ( item ) { addBellItem( item ); } );
                    }
                }

                // Badge = authoritative count.
                unreadCount = notifications.length;
                updateBadge();
                saveNotifications();

                // Beep + desktop notification only for genuinely new arrivals, not on first silent poll.
                if ( hasNew && ! silent ) {
                    playBeep();
                    var canNotify = typeof Notification !== 'undefined' && Notification.permission === 'granted';
                    if ( canNotify ) {
                        try {
                            new Notification( 'Dish Dash', { body: 'You have new pending items to review.' } );
                        } catch ( e ) {}
                    }
                }

                if ( silent ) {
                    localStorage.setItem( LS_NOTIF_INIT, '1' );
                }
            } )
            .catch( function () {} );
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
