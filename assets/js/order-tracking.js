/**
 * File: assets/js/order-tracking.js
 * Purpose: Customer order-status tracker (v3.10.30).
 *
 * Polls dd_get_order every 30s and re-renders the timeline authoritatively
 * from the returned order object. Stops once status is terminal
 * (delivered / cancelled). Mirrors the server-rendered markup in
 * templates/orders/track.php so the view is seamless across renders.
 *
 * Localized config (window.ddTrackConfig): { ajaxUrl, nonce }.
 * No jQuery, no build step.
 */
( function () {
    'use strict';

    var cfg  = window.ddTrackConfig || {};
    var root = document.querySelector( '.dd-track[data-order-id]' );
    if ( ! root || ! cfg.ajaxUrl || ! cfg.nonce ) return;

    var orderId = root.getAttribute( 'data-order-id' );
    var body    = root.querySelector( '.dd-track__body' );
    var timer   = null;

    var STEPS = [
        { key: 'placed',    label: 'Placed',    stamp: 'created_at' },
        { key: 'confirmed', label: 'Confirmed', stamp: 'confirmed_at' },
        { key: 'ready',     label: 'Ready',     stamp: 'ready_at' },
        { key: 'delivered', label: 'Delivered', stamp: 'delivered_at' }
    ];

    function esc( s ) {
        return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
        } );
    }

    function fmt( ts ) {
        if ( ! ts ) return '';
        // DATETIME "YYYY-MM-DD HH:MM:SS" — treat as local time.
        var d = new Date( String( ts ).replace( ' ', 'T' ) );
        if ( isNaN( d.getTime() ) ) return esc( ts );
        return d.toLocaleString( [], {
            month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
        } );
    }

    function isTerminal( status ) {
        return status === 'delivered' || status === 'cancelled';
    }

    function render( order ) {
        if ( ! body ) return;
        var status    = String( order.status || '' );
        var cancelled = status === 'cancelled' || !! order.cancelled_at;

        if ( cancelled ) {
            var html = '<div class="dd-track__cancelled">'
                     + '<span class="dd-track__cancelled-badge">Cancelled</span>';
            if ( order.cancelled_at ) {
                html += '<span class="dd-track__cancelled-time">' + esc( fmt( order.cancelled_at ) ) + '</span>';
            }
            html += '</div>';
            body.innerHTML = html;
            root.setAttribute( 'data-status', status );
            return;
        }

        var out = '<ol class="dd-track__timeline">';
        STEPS.forEach( function ( step ) {
            var stamp   = order[ step.stamp ];
            var done    = !! stamp;
            var classes = 'dd-track__step ' + ( done ? 'is-done' : 'is-upcoming' );
            if ( status === step.key ) classes += ' is-current';
            out += '<li class="' + classes + '">'
                 + '<span class="dd-track__dot" aria-hidden="true"></span>'
                 + '<span class="dd-track__label">' + esc( step.label ) + '</span>'
                 + '<span class="dd-track__time">' + ( done ? esc( fmt( stamp ) ) : 'Pending' ) + '</span>'
                 + '</li>';
        } );
        out += '</ol>';
        body.innerHTML = out;
        root.setAttribute( 'data-status', status );
    }

    function stop() {
        if ( timer ) { clearInterval( timer ); timer = null; }
    }

    function poll() {
        var fd = new FormData();
        fd.append( 'action', 'dd_get_order' );
        fd.append( 'order_id', orderId );
        fd.append( 'nonce', cfg.nonce );

        fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( ! res || ! res.success ) return;
                var payload = res.data && res.data.data ? res.data.data : res.data;
                var order   = payload && payload.order;
                if ( ! order ) return;
                render( order );
                if ( isTerminal( String( order.status || '' ) ) ) stop();
            } )
            .catch( function () { /* transient network error — next tick retries */ } );
    }

    // Fire the view-tracking event once (guarded — matches reservations.js convention).
    if ( window.DDTrack && typeof window.DDTrack.event === 'function' ) {
        window.DDTrack.event( 'track_order_view', null, null, {
            order_id: parseInt( orderId, 10 ),
            status:   root.getAttribute( 'data-status' ) || ''
        } );
    }

    // Don't poll an already-terminal order.
    if ( ! isTerminal( root.getAttribute( 'data-status' ) ) ) {
        poll();
        timer = setInterval( poll, 30000 );
    }
} )();
