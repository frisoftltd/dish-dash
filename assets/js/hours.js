/**
 * assets/js/hours.js
 *
 * Computes restaurant open/closed state client-side from the static schedule
 * carried on window.DD (hours_schedule / hours_tz / closing_soon_min), instead
 * of trusting a server-computed state baked into cached page HTML.
 *
 * Ports DD_Hours::get_state() / get_current_close_ts() / get_next_open_info_ts()
 * (dishdash-core/class-dd-helpers.php) — keep this file's logic in sync with
 * that class if the schedule format or state rules ever change there.
 *
 * Writes window.DD.hours_state / next_open_ts / close_ts, the same three keys
 * every existing consumer (frontend.js, menu-page.js) already reads. Must run
 * before those scripts — enforced via the 'dd-hours' wp_enqueue_script dependency.
 *
 * Server-side enforcement in dd_cart_add is the real gate; this is UI only.
 * Any failure here (missing Intl, bad schedule) fails open — a false "closed"
 * blocks real orders, a false "open" is caught server-side.
 */
(function () {
    'use strict';

    var DAY_ORDER   = [ 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ];
    var RECHECK_MS  = 60000;

    function isEmptyObj( o ) {
        return ! o || typeof o !== 'object' || Object.keys( o ).length === 0;
    }

    // Offset (ms) to add to a UTC timestamp to get tz's local wall-clock time,
    // evaluated at `date` (so DST is resolved for the moment being converted).
    function tzOffsetMs( tz, date ) {
        var dtf = new Intl.DateTimeFormat( 'en-US', {
            timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
        } );
        var p = {};
        dtf.formatToParts( date ).forEach( function ( x ) { p[ x.type ] = x.value; } );
        var asUTC = Date.UTC( +p.year, p.month - 1, +p.day, +p.hour, +p.minute, +p.second );
        return asUTC - ( Math.floor( date.getTime() / 1000 ) * 1000 );
    }

    // tz-local Y/M/D + weekday name for "now"
    function tzTodayParts( tz, date ) {
        var dtf = new Intl.DateTimeFormat( 'en-US', {
            timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'long'
        } );
        var p = {};
        dtf.formatToParts( date ).forEach( function ( x ) { p[ x.type ] = x.value; } );
        return { y: +p.year, m: +p.month, d: +p.day, weekday: p.weekday.toLowerCase() };
    }

    // Epoch seconds of wall-clock "HH:MM" on tz-local Y-M-D. Uses midday UTC of
    // that same calendar date to resolve the DST offset, so dates a few days out
    // (next_open_ts can look up to 7 days ahead) still get the right offset even
    // if a DST transition falls between now and then.
    function wallTimeToEpochSec( tz, y, m, d, timeStr ) {
        var hm = String( timeStr ).split( ':' );
        var hh = parseInt( hm[ 0 ], 10 ) || 0;
        var mm = parseInt( hm[ 1 ], 10 ) || 0;
        var ref = new Date( Date.UTC( y, m - 1, d, 12, 0, 0 ) );
        var ms  = Date.UTC( y, m - 1, d, hh, mm, 0 ) - tzOffsetMs( tz, ref );
        return Math.floor( ms / 1000 );
    }

    // Calendar Y/M/D of "today + offsetDays" (pure date math, tz-agnostic).
    function addDays( y, m, d, offsetDays ) {
        var dt = new Date( Date.UTC( y, m - 1, d + offsetDays ) );
        return { y: dt.getUTCFullYear(), m: dt.getUTCMonth() + 1, d: dt.getUTCDate() };
    }

    function dayNameAt( todayName, offsetDays ) {
        var idx = DAY_ORDER.indexOf( todayName );
        if ( idx === -1 ) return todayName;
        return DAY_ORDER[ ( idx + offsetDays ) % 7 ];
    }

    // Mirrors DD_Hours::get_state()
    function getState( schedule, tz, closingSoonMin, today, y, m, d, nowSec ) {
        if ( isEmptyObj( schedule ) ) return 'open';
        if ( isEmptyObj( today ) ) return 'open';
        if ( ! today.open || ! today.sessions || ! today.sessions.length ) return 'closed';

        var sessions = today.sessions;
        var i;
        for ( i = 0; i < sessions.length; i++ ) {
            var openSec  = wallTimeToEpochSec( tz, y, m, d, sessions[ i ][ 0 ] );
            var closeSec = wallTimeToEpochSec( tz, y, m, d, sessions[ i ][ 1 ] );
            if ( nowSec >= openSec && nowSec < closeSec ) {
                var diffMin = ( closeSec - nowSec ) / 60;
                return diffMin <= closingSoonMin ? 'closing_soon' : 'open';
            }
        }

        // Between sessions (mid-day break) — only defined for a two-session day.
        if ( sessions.length === 2 ) {
            var endS1   = wallTimeToEpochSec( tz, y, m, d, sessions[ 0 ][ 1 ] );
            var startS2 = wallTimeToEpochSec( tz, y, m, d, sessions[ 1 ][ 0 ] );
            if ( nowSec >= endS1 && nowSec < startS2 ) return 'break';
        }

        return 'closed';
    }

    // Mirrors DD_Hours::get_current_close_ts()
    function getCurrentCloseTs( today, tz, y, m, d, nowSec ) {
        if ( isEmptyObj( today ) || ! today.sessions ) return 0;
        for ( var i = 0; i < today.sessions.length; i++ ) {
            var openSec  = wallTimeToEpochSec( tz, y, m, d, today.sessions[ i ][ 0 ] );
            var closeSec = wallTimeToEpochSec( tz, y, m, d, today.sessions[ i ][ 1 ] );
            if ( nowSec >= openSec && nowSec < closeSec ) return closeSec;
        }
        return 0;
    }

    // Mirrors DD_Hours::get_next_open_info_ts() — looks ahead up to 7 days.
    function getNextOpenTs( schedule, tz, todayY, todayM, todayD, todayName, nowSec ) {
        for ( var i = 0; i <= 7; i++ ) {
            var cal     = addDays( todayY, todayM, todayD, i );
            var dName   = dayNameAt( todayName, i );
            var dayData = schedule[ dName ];

            if ( ! dayData || ! dayData.open || ! dayData.sessions || ! dayData.sessions.length ) continue;

            for ( var s = 0; s < dayData.sessions.length; s++ ) {
                var openSec = wallTimeToEpochSec( tz, cal.y, cal.m, cal.d, dayData.sessions[ s ][ 0 ] );
                if ( openSec > nowSec ) return openSec;
            }
        }
        return 0;
    }

    function computeAll() {
        var fallback = { hours_state: 'open', next_open_ts: 0, close_ts: 0 };

        try {
            if ( typeof Intl === 'undefined' || ! Intl.DateTimeFormat ) return fallback;

            var DD = window.DD || {};
            var schedule = DD.hours_schedule;
            var tz       = DD.hours_tz || 'Africa/Kigali';
            var closingSoonMin = parseInt( DD.closing_soon_min, 10 );
            if ( isNaN( closingSoonMin ) ) closingSoonMin = 30;

            if ( ! schedule || typeof schedule !== 'object' ) return fallback;

            var now    = new Date();
            var nowSec = Math.floor( now.getTime() / 1000 );
            var parts  = tzTodayParts( tz, now );
            var today  = schedule[ parts.weekday ];

            var state = getState( schedule, tz, closingSoonMin, today, parts.y, parts.m, parts.d, nowSec );

            var nextOpenTs = 0;
            if ( state !== 'open' ) {
                nextOpenTs = getNextOpenTs( schedule, tz, parts.y, parts.m, parts.d, parts.weekday, nowSec );
            }

            var closeTs = 0;
            if ( state === 'open' || state === 'closing_soon' ) {
                closeTs = getCurrentCloseTs( today, tz, parts.y, parts.m, parts.d, nowSec );
            }

            return { hours_state: state, next_open_ts: nextOpenTs, close_ts: closeTs };
        } catch ( e ) {
            return fallback;
        }
    }

    function apply() {
        var DD = window.DD = window.DD || {};
        var result  = computeAll();
        var changed = DD.hours_state !== result.hours_state;

        DD.hours_state  = result.hours_state;
        DD.next_open_ts = result.next_open_ts;
        DD.close_ts     = result.close_ts;

        if ( changed ) {
            document.dispatchEvent( new CustomEvent( 'dd:hours-change', { detail: result } ) );
        }
    }

    apply();
    setInterval( apply, RECHECK_MS );
})();
