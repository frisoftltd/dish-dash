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

        // iOS fix: bind touchend directly on close button
        // (click delegation unreliable on position:fixed inside iOS Safari)
        var closeBtn = document.getElementById( 'ddCartClose' );
        if ( closeBtn ) {
            closeBtn.addEventListener( 'touchend', function ( e ) {
                e.preventDefault();
                e.stopPropagation();
                closeCart();
            }, { passive: false } );
        }

        // iOS fix: bind touchend on overlay
        var overlay = document.getElementById( 'ddCartOverlay' );
        if ( overlay ) {
            overlay.addEventListener( 'touchend', function ( e ) {
                e.preventDefault();
                closeCart();
            }, { passive: false } );
        }
    } );

    /* ── EVENT BINDING ──────────────────────────────────────── */
    function bindEvents() {

        var cartOpenTime = null;

        document.addEventListener( 'click', function ( e ) {

            // Open triggers — event delegation works for late-rendered elements
            if ( e.target.closest( '#ddCartBtn, #ddBottomCartBtn, #ddCartTopBtn' ) ) {
                cartOpenTime = Date.now();
                openCart();
                return;
            }

            // Close triggers
            if ( e.target.closest( '#ddCartClose, .dd-cart-drawer-overlay' ) ) {
                closeCart();
                return;
            }

            // Disabled checkout: button[disabled] handles this natively
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
                if ( data.items ) {
                    data.items.forEach( function ( item ) {
                        var itemEl = document.querySelector( '.dd-cart-drawer__item[data-key="' + item.key + '"]' );
                        if ( itemEl ) {
                            var priceEl = itemEl.querySelector( '.dd-cart-drawer__item-price' );
                            if ( priceEl ) priceEl.textContent = formatPrice( item.price * item.qty );
                        }
                    } );
                }
                if ( window.DDTrack ) window.DDTrack.cartQuantityChange( key, newQty - 1, newQty );
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
                if ( data.items ) {
                    data.items.forEach( function ( item ) {
                        var itemEl = document.querySelector( '.dd-cart-drawer__item[data-key="' + item.key + '"]' );
                        if ( itemEl ) {
                            var priceEl = itemEl.querySelector( '.dd-cart-drawer__item-price' );
                            if ( priceEl ) priceEl.textContent = formatPrice( item.price * item.qty );
                        }
                    } );
                }
                if ( window.DDTrack ) window.DDTrack.cartQuantityChange( key, newQty + 1, newQty );
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
                // Track removal — find product id from item element before it fades
                var productId = item ? ( item.dataset.id || null ) : null;
                if ( window.DDTrack ) window.DDTrack.removeFromCart( productId, 1 );
            } );
        } );

        // Fire cart_abandon on page leave if cart is open and has items
        window.addEventListener( 'beforeunload', function () {
            var drawer = document.getElementById( 'ddCartDrawer' );
            if ( ! drawer || ! drawer.classList.contains( 'dd-cart-drawer--open' ) ) return;
            var badge = document.getElementById( 'ddBottomBadge' );
            var count = badge ? ( parseInt( badge.textContent, 10 ) || 0 ) : 0;
            if ( count === 0 ) return;
            var subtotalEl = document.getElementById( 'ddCartSubtotal' );
            var total = subtotalEl ? subtotalEl.textContent : '0';
            var timeOpen = cartOpenTime ? Math.round( ( Date.now() - cartOpenTime ) / 1000 ) : 0;
            if ( window.DDTrack ) window.DDTrack.cartAbandon( count, total, timeOpen );
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

        // Track with item count from badges
        var badge = document.getElementById( 'ddBottomBadge' );
        var count = badge ? ( parseInt( badge.textContent, 10 ) || 0 ) : 0;
        if ( window.DDTrack ) window.DDTrack.cartOpen( count );
    }

    /* ── CLOSE ──────────────────────────────────────────────── */
    function closeCart() {
        var overlay = document.getElementById( 'ddCartOverlay' );
        var drawer  = document.getElementById( 'ddCartDrawer' );
        if ( drawer ) {
            drawer.classList.remove( 'dd-cart-drawer--open' );
            drawer.classList.remove( 'open' );
        }
        if ( overlay ) {
            overlay.classList.remove( 'dd-cart-drawer-overlay--visible' );
            overlay.classList.remove( 'open' );
        }
        document.body.classList.remove( 'dd-cart-open' );
        // Always reset to cart panel — next open starts fresh
        showPanel( panelCart );
    }

    /* ── FETCH CART FROM SERVER ─────────────────────────────── */
    function fetchCart( renderPanel ) {
        ajax( 'dd_cart_get', {}, function ( data ) {
            updateBadges( data.count );
            // Store for checkout panel to read
            window.ddCartSummary = data;
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
            checkoutEl.disabled = ! hasItems;
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
        if ( window.DDTrack && typeof window.DDTrack.event === 'function' ) {
            window.DDTrack.event( type, null, null, meta || {} );
            return;
        }
        // Fallback direct AJAX
        var body = new FormData();
        body.append( 'action',     'dd_track_event' );
        body.append( 'nonce',      NONCE );
        body.append( 'event_type', type );
        body.append( 'meta',       JSON.stringify( meta || {} ) );
        fetch( AJAX_URL, { method: 'POST', body: body } ).catch( function () {} );
    }

    /* ── AJAX HELPER ────────────────────────────────────────── */
    function ajax( action, data, onSuccess, onError ) {
        var body = new FormData();
        body.append( 'action', action );
        body.append( 'nonce',  NONCE );
        Object.keys( data ).forEach( function ( k ) { body.append( k, data[ k ] ); } );

        fetch( AJAX_URL, { method: 'POST', body: body } )
            .then( function ( r )   { return r.json(); } )
            .then( function ( res ) {
                if ( res.success ) {
                    onSuccess( res.data );
                } else if ( typeof onError === 'function' ) {
                    onError( ( res.data && res.data.message ) ? res.data.message : 'Something went wrong.' );
                }
            } )
            .catch( function () {
                if ( typeof onError === 'function' ) {
                    onError( 'Network error. Please try again.' );
                }
            } );
    }

    /* ── FORMAT HELPERS ─────────────────────────────────────── */
    function formatPrice( value ) {
        return Math.round( value ).toLocaleString( 'en-US' ) + ' RWF';
    }

    function formatMoney( amount ) {
        return parseFloat( amount || 0 ).toLocaleString( 'en-US' ) + ' RWF';
    }

    function formatNumber( value ) {
        return parseFloat( value ).toLocaleString( 'en-RW', { maximumFractionDigits: 0 } );
    }

    function escHtml( str ) {
        var div = document.createElement( 'div' );
        div.textContent = String( str || '' );
        return div.innerHTML;
    }

    /* ── PANEL NAVIGATION ───────────────────────────────────── */
    var panelCart         = document.getElementById( 'ddPanelCart' );
    var panelCheckout     = document.getElementById( 'ddPanelCheckout' );
    var panelConfirmation = document.getElementById( 'ddPanelConfirmation' );

    function showPanel( panelEl ) {
        [ panelCart, panelCheckout, panelConfirmation ].forEach( function ( p ) {
            if ( p ) p.classList.add( 'dd-cart-panel--hidden' );
        } );
        if ( panelEl ) panelEl.classList.remove( 'dd-cart-panel--hidden' );
    }

    // Proceed to checkout
    var checkoutBtn = document.getElementById( 'ddCartCheckout' );
    if ( checkoutBtn ) {
        checkoutBtn.addEventListener( 'click', function () {
            var summary   = window.ddCartSummary || {};
            var count     = summary.count    || 0;
            var sub       = summary.subtotal || 0;

            // Tracking
            if ( window.DDTrack && typeof window.DDTrack.checkoutStart === 'function' ) {
                window.DDTrack.checkoutStart( count, sub );
            }

            // Populate summary strip
            var strip = document.getElementById( 'ddCheckoutSummaryStrip' );
            if ( strip && summary.items ) {
                strip.innerHTML = summary.items
                    .map( function ( i ) { return '<div>' + escHtml( i.qty + '× ' + i.name ) + '</div>'; } )
                    .join( '' );
            }

            // Render payment gateway options from WooCommerce
            var gatewayContainer = document.getElementById( 'ddPaymentOptions' );
            if ( gatewayContainer && window.ddCartData.paymentGateways && window.ddCartData.paymentGateways.length ) {
                gatewayContainer.innerHTML = window.ddCartData.paymentGateways.map( function ( gw, i ) {
                    return '<label class="dd-payment-option">' +
                        '<input type="radio" name="payment_method" value="' + escHtml( gw.id ) + '"' + ( i === 0 ? ' checked' : '' ) + '>' +
                        '<span class="dd-payment-option__card">' +
                        '<span class="dd-payment-option__icon">' + gw.icon + '</span>' +
                        '<span class="dd-payment-option__text">' + escHtml( gw.title ) + '</span>' +
                        '</span></label>';
                } ).join( '' );
            }

            // Delivery fee display — explicit parseFloat prevents string concatenation
            var threshold  = parseFloat( ( window.ddCartData && window.ddCartData.freeDeliveryThreshold ) || 10000 );
            var fee        = parseFloat( ( window.ddCartData && window.ddCartData.deliveryFee ) || 1500 );
            var subF       = parseFloat( summary.subtotal || 0 );
            var isFree     = subF >= threshold;
            var grandTotal = subF + ( isFree ? 0 : fee );

            var totalEl = document.getElementById( 'ddCheckoutTotal' );
            var feeEl   = document.getElementById( 'ddCheckoutDeliveryFee' );
            if ( totalEl ) totalEl.textContent = formatMoney( grandTotal );
            if ( feeEl )   feeEl.textContent   = isFree ? 'FREE' : formatMoney( fee );

            // ETA
            var eta   = ( window.ddCartData && window.ddCartData.deliveryEta ) || '30\u201345 minutes';
            var etaEl = document.getElementById( 'ddCheckoutEta' );
            if ( etaEl ) etaEl.textContent = '\uD83D\uDEF5 Estimated delivery: ' + eta;

            showPanel( panelCheckout );

            // Scroll hint — reset to top so fade is visible
            var body = document.querySelector( '.dd-checkout-panel__body' );
            if ( body ) body.scrollTop = 0;
        } );
    }

    // Back button
    var backBtn = document.getElementById( 'ddCheckoutBack' );
    if ( backBtn ) {
        backBtn.addEventListener( 'click', function () {
            showPanel( panelCart );
        } );
    }

    // Place order
    var placeOrderBtn = document.getElementById( 'ddPlaceOrder' );
    if ( placeOrderBtn ) {
        placeOrderBtn.addEventListener( 'click', function () {
            var nameEl = document.getElementById( 'ddFieldName' );
            var waEl   = document.getElementById( 'ddFieldWhatsapp' );
            var addrEl = document.getElementById( 'ddFieldAddress' );
            var pmEl   = document.querySelector( 'input[name="payment_method"]:checked' );

            // Clear previous errors
            [ 'ddErrorName', 'ddErrorWhatsapp', 'ddErrorAddress' ].forEach( function ( id ) {
                var el = document.getElementById( id );
                if ( el ) el.textContent = '';
            } );
            var existingGenErr = document.querySelector( '.dd-cform-error--general' );
            if ( existingGenErr ) existingGenErr.remove();

            var name    = nameEl ? nameEl.value.trim() : '';
            var wa      = waEl   ? waEl.value.trim()   : '';
            var addr    = addrEl ? addrEl.value.trim()  : '';
            var payment = pmEl   ? pmEl.value           : 'pay_on_delivery';

            var valid = true;
            if ( ! name ) {
                var e1 = document.getElementById( 'ddErrorName' );
                if ( e1 ) e1.textContent = 'Please enter your full name.';
                valid = false;
            }
            if ( ! wa ) {
                var e2 = document.getElementById( 'ddErrorWhatsapp' );
                if ( e2 ) e2.textContent = 'Please enter your WhatsApp number.';
                valid = false;
            }
            if ( ! addr ) {
                var e3 = document.getElementById( 'ddErrorAddress' );
                if ( e3 ) e3.textContent = 'Please enter your delivery address.';
                valid = false;
            }
            if ( ! valid ) return;

            // Loading state
            placeOrderBtn.disabled    = true;
            placeOrderBtn.textContent = 'Placing order\u2026';

            ajax( 'dd_place_order', {
                customer_name:    name,
                whatsapp:         wa,
                delivery_address: addr,
                payment_method:   payment,
            }, function ( data ) {
                // Online gateway — redirect to payment page
                if ( data.redirect && data.payment_url ) {
                    window.location.href = data.payment_url;
                    return; // stop — no confirmation panel for online payments
                }

                // Populate confirmation panel
                var numEl2 = document.getElementById( 'ddConfirmOrderNum' );
                var etaEl2 = document.getElementById( 'ddConfirmEta' );
                if ( numEl2 ) numEl2.textContent = 'Order #' + data.order_number;
                if ( etaEl2 ) etaEl2.textContent = '\uD83D\uDEF5 Estimated delivery: ' + ( data.eta || '30\u201345 minutes' );

                showPanel( panelConfirmation );
                updateBadges( 0 );

                // Set customer ID cookie — used by birthday WhatsApp trigger (2 min later)
                if ( data.customer_id ) {
                    document.cookie = 'dd_customer_id=' + data.customer_id
                        + '; path=/; max-age=7200; SameSite=Lax';
                }

                // Open WhatsApp in new tab via anchor click (avoids mobile popup block)
                if ( data.whatsapp_url ) {
                    setTimeout( function () {
                        const a = document.createElement('a');
                        a.href = data.whatsapp_url;
                        a.target = '_blank';
                        a.rel = 'noopener noreferrer';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                    }, 800 );
                }

                window.ddCartSummary = null;

                var waBtn = document.getElementById( 'ddConfirmWhatsappBtn' );
                if ( waBtn ) waBtn.style.display = 'none';

                // Track order event
                if ( window.DDTrack && typeof window.DDTrack.event === 'function' ) {
                    window.DDTrack.event( 'order', null, null, {
                        order_id:       data.order_id,
                        total:          data.total,
                        payment_method: payment,
                    } );
                }

                // Reset button for next use
                placeOrderBtn.disabled    = false;
                placeOrderBtn.textContent = 'Place Order \u2192';
            }, function ( message ) {
                // Error — re-enable button and show message above it
                placeOrderBtn.disabled    = false;
                placeOrderBtn.textContent = 'Place Order \u2192';
                var errP = document.createElement( 'p' );
                errP.className   = 'dd-cform-error dd-cform-error--general';
                errP.textContent = message;
                var footer = placeOrderBtn.closest( '.dd-checkout-panel__footer' );
                if ( footer ) footer.insertBefore( errP, placeOrderBtn );
            } );
        } );
    }

    // Confirm done → close drawer and return to cart panel
    var confirmCloseBtn = document.getElementById( 'ddConfirmClose' );
    if ( confirmCloseBtn ) {
        confirmCloseBtn.addEventListener( 'click', function () {
            closeCart();
            showPanel( panelCart );
        } );
    }

    /* ── PUBLIC API ─────────────────────────────────────────── */
    window.DDCart = {
        open:    openCart,
        close:   closeCart,
        refresh: function () { fetchCart( true ); },
        sync:    function () { fetchCart( false ); },
    };

})();
