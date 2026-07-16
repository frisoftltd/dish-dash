/**
 * reservations.js — Phase 4C: 5-Screen Reservation Modal + Deposit Flow
 * Dish Dash Plugin — Fri Soft Ltd
 * Screen 4 = deposit interstitial (skipped when deposit off)
 * Screen 5 = confirm/summary (free booking path)
 */

(function () {
  'use strict';

  const SESSIONS = {
    lunch:  { label: 'Lunch',  start: '11:00', end: '15:00', step: 30 },
    dinner: { label: 'Dinner', start: '17:00', end: '22:00', step: 30 },
  };

  const DAYS   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

  const state = {
    screen:   1,
    date:     null,
    session:  'lunch',
    time:     null,
    guests:   2,
    table:    '',
    name:     '',
    whatsapp: '',
    requests: '',
  };

  const $ = sel => document.querySelector(sel);
  const $all = sel => [...document.querySelectorAll(sel)];

  const ddRes = window.ddReservations || {};
  // Fixed deposits are live. Reads the real setting; when on, the booking form shows
  // the informational "deposit required" notice on screen 1, and the confirmation
  // step renders the MoMo scan-&-pay QR + "I have paid" claim button.
  const depositActive = !!ddRes.depositEnabled;

  // Deposit panel state. When the deposit QR panel is shown it becomes "locked":
  // outside-tap + Escape must NOT close it, so a customer can leave to their MoMo
  // app to pay and return to find the QR still on screen. Only the pinned header X
  // (#dd-res-close) and the panel's own "Payment recorded" flow dismiss it.
  let depositPanelLocked = false;
  let depositBookingRef  = '';
  let depositWhatsappUrl = '';

  // ── Init ──────────────────────────────────────────────────
  function init() {
    if (!$('#dd-res-overlay')) return;

    if (window.location.hash === '#reserve') {
      setTimeout(() => openModal(), 100);
    }

    buildDatePills();
    buildSlots('lunch');
    bindOpenClose();
    bindStepper();
    bindSessionToggle();
    bindNavigation();
    bindInputSync();
    initDepositBadge();
    // NB: initPhonePicker() is NOT called here. The WhatsApp field lives on
    // screen 3 (Details), which is display:none at page load — initialising
    // intl-tel-input on a hidden element mis-measures the separate dial code.
    // Instead we init when screen 3 becomes visible (goToScreen), mirroring the
    // checkout picker, which inits only after its field is shown.
  }

  // ── Phone picker (intl-tel-input, v3.10.33) ───────────────
  // E.164 read via getNumber() at submit, raw trimmed value as fallback
  // if the picker never initialized or utils.js hasn't loaded yet.
  let itiWhatsapp = null;
  function initPhonePicker() {
    if (itiWhatsapp) return;
    if (typeof window.intlTelInput !== 'function') return;
    const input = document.getElementById('dd-res-whatsapp');
    if (!input) return;
    const vendor = (window.ddIntlTel && window.ddIntlTel.utilsUrl) || '';
    itiWhatsapp = window.intlTelInput(input, {
      initialCountry:   'rw',
      countryOrder:     ['rw', 'ke', 'ug', 'tz', 'bi'],
      nationalMode:     false,
      separateDialCode: true,
      // Attach the fullscreen country popup to <body> for consistency with the
      // cart picker (harmless here — no transformed ancestor to escape).
      dropdownContainer: document.body,
      loadUtils:        vendor ? () => import(vendor) : undefined,
    });
    input.addEventListener('input', updateResPhoneHint);
    input.addEventListener('blur',  updateResPhoneHint);
  }

  function showResPhoneWarn(text) {
    const el = document.getElementById('dd-res-phone-warn');
    if (!el) return;
    el.textContent = text;
    el.style.display = text ? 'block' : 'none';
  }

  // Soft hint: warn only when the picker judged the number invalid
  // (isValidNumber() === false). null (utils loading) shows nothing.
  function updateResPhoneHint() {
    const input = document.getElementById('dd-res-whatsapp');
    const val = input ? input.value.trim() : '';
    if (val && itiWhatsapp && itiWhatsapp.isValidNumber() === false) {
      showResPhoneWarn('Please enter a valid phone number.');
    } else {
      showResPhoneWarn('');
    }
  }

  function readPhone(inputEl, iti) {
    const raw = inputEl ? inputEl.value.trim() : '';
    if (iti && typeof iti.getNumber === 'function') {
      const e164 = iti.getNumber();
      if (e164) return e164;
    }
    return raw;
  }

  // ── Deposit badge on Screen 1 ─────────────────────────────
  function initDepositBadge() {
    const badge = document.getElementById('dd-res-deposit-notice');
    if (!badge) return;
    if (depositActive) {
      const amountEl = document.getElementById('dd-res-deposit-badge-amount');
      if (amountEl && ddRes.depositAmount) {
        amountEl.textContent = ddRes.depositAmount.toLocaleString() + ' RWF';
      }
      badge.style.display = 'flex';
    } else {
      badge.style.display = 'none';
    }
  }

  // ── Open / Close ──────────────────────────────────────────
  function bindOpenClose() {
    document.addEventListener('click', e => {
      const trigger = e.target.closest('#dd-open-reservation, .js-open-reservation, .dd-hero__reserve, a[href="#reserve"]');
      if (!trigger) return;
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
      openModal();
    }, true);

    // The header X always closes (explicit dismissal — works even when locked).
    $('#dd-res-close')?.addEventListener('click', closeModal);

    // Outside-tap: suppressed while the deposit panel is locked (sticky).
    $('#dd-res-overlay')?.addEventListener('click', e => {
      if (depositPanelLocked) return;
      if (e.target === $('#dd-res-overlay')) closeModal();
    });

    // Escape: suppressed while the deposit panel is locked (sticky).
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && !depositPanelLocked) closeModal();
    });
  }

  function openModal() {
    const overlay = $('#dd-res-overlay');
    if (!overlay) return;
    if (overlay.classList.contains('dd-res-overlay--open')) return;

    if (window.location.hash === '#reserve') {
      history.replaceState(null, '', window.location.pathname + window.location.search);
    }

    const nextBtn = $('#dd-res-next');
    if (nextBtn) nextBtn.disabled = false;
    const confirmArea = document.querySelector('.dd-res-confirm-area');
    if (confirmArea) {
      confirmArea.innerHTML =
        '<p class="dd-res-submit-error" hidden style="color:#dc2626;font-size:14px;margin:0 0 10px"></p>';
    }

    overlay.classList.add('dd-res-overlay--open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    goToScreen(1);
  }

  function closeModal() {
    const overlay = $('#dd-res-overlay');
    if (!overlay) return;
    depositPanelLocked = false; // unlock so a fresh booking isn't stuck sticky
    overlay.classList.remove('dd-res-overlay--open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  // ── Date pills ────────────────────────────────────────────
  function buildDatePills() {
    const container = $('#dd-res-dates');
    if (!container) return;

    const today = new Date();
    today.setHours(0,0,0,0);
    const frag = document.createDocumentFragment();

    for (let i = 0; i < 30; i++) {
      const d = new Date(today);
      d.setDate(today.getDate() + i);

      const pill = document.createElement('button');
      pill.className = 'dd-res-date-pill' + (i === 0 ? ' dd-res-date-pill--selected' : '');
      pill.setAttribute('role', 'option');
      pill.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
      pill.dataset.date = d.getFullYear() + '-' +
          String( d.getMonth() + 1 ).padStart( 2, '0' ) + '-' +
          String( d.getDate() ).padStart( 2, '0' );
      pill.innerHTML =
        `<span class="dd-res-date-pill__day">${DAYS[d.getDay()]}</span>` +
        `<span class="dd-res-date-pill__num">${d.getDate()}</span>` +
        `<span class="dd-res-date-pill__mon">${MONTHS[d.getMonth()]}</span>`;

      if (i === 0) state.date = d;
      pill.addEventListener('click', () => {
        $all('.dd-res-date-pill').forEach(p => {
          p.classList.remove('dd-res-date-pill--selected');
          p.setAttribute('aria-selected','false');
        });
        pill.classList.add('dd-res-date-pill--selected');
        pill.setAttribute('aria-selected','true');
        state.date = d;
      });
      frag.appendChild(pill);
    }
    container.appendChild(frag);

    const scrollBy = 200;
    $('#dd-res-date-prev')?.addEventListener('click', () => {
      container.scrollBy({ left: -scrollBy, behavior: 'smooth' });
    });
    $('#dd-res-date-next')?.addEventListener('click', () => {
      container.scrollBy({ left: scrollBy, behavior: 'smooth' });
    });
  }

  // ── Time slots ────────────────────────────────────────────
  function buildSlots(sessionKey) {
    const container = $('#dd-res-slots');
    if (!container) return;
    container.innerHTML = '';

    const cfg = SESSIONS[sessionKey];
    const [sh,sm] = cfg.start.split(':').map(Number);
    const [eh,em] = cfg.end.split(':').map(Number);
    const startMin = sh*60+sm, endMin = eh*60+em;

    for (let t = startMin; t < endMin; t += cfg.step) {
      const hh = String(Math.floor(t/60)).padStart(2,'0');
      const mm = String(t%60).padStart(2,'0');
      const btn = document.createElement('button');
      btn.className = 'dd-res-slot';
      btn.textContent = fmt12(`${hh}:${mm}`);
      btn.dataset.time = `${hh}:${mm}`;
      btn.setAttribute('role','option');
      btn.addEventListener('click', () => {
        $all('.dd-res-slot').forEach(s => {
          s.classList.remove('dd-res-slot--selected');
          s.setAttribute('aria-selected','false');
        });
        btn.classList.add('dd-res-slot--selected');
        btn.setAttribute('aria-selected','true');
        state.time = `${hh}:${mm}`;
      });
      container.appendChild(btn);
    }

    const first = container.querySelector('.dd-res-slot');
    if (first) { first.classList.add('dd-res-slot--selected'); state.time = first.dataset.time; }
  }

  function fmt12(t) {
    const [h,m] = t.split(':').map(Number);
    return `${((h+11)%12+1)}:${String(m).padStart(2,'0')} ${h>=12?'PM':'AM'}`;
  }

  // ── Session toggle ────────────────────────────────────────
  function bindSessionToggle() {
    $all('.dd-res-toggle__btn').forEach(btn => {
      btn.addEventListener('click', () => {
        $all('.dd-res-toggle__btn').forEach(b => b.classList.remove('dd-res-toggle__btn--active'));
        btn.classList.add('dd-res-toggle__btn--active');
        state.session = btn.dataset.session;
        state.time = null;
        buildSlots(state.session);
      });
    });
  }

  // ── Stepper ───────────────────────────────────────────────
  function bindStepper() {
    $('#dd-guests-minus')?.addEventListener('click', () => {
      if (state.guests > 1) { state.guests--; $('#dd-guests-val').textContent = state.guests; }
    });
    $('#dd-guests-plus')?.addEventListener('click', () => {
      if (state.guests < 20) { state.guests++; $('#dd-guests-val').textContent = state.guests; }
    });
  }

  // ── Input sync ────────────────────────────────────────────
  function bindInputSync() {
    $('#dd-res-name')?.addEventListener('input', e => { state.name = e.target.value; });
    $('#dd-res-whatsapp')?.addEventListener('input', e => { state.whatsapp = e.target.value; });
    $('#dd-res-requests')?.addEventListener('input', e => { state.requests = e.target.value; });
    $('#dd-res-table')?.addEventListener('change', e => { state.table = e.target.value; });
  }

  // ── Navigation ────────────────────────────────────────────
  function bindNavigation() {
    $('#dd-res-next')?.addEventListener('click', () => {
      if (!validateScreen(state.screen)) return;
      if (state.screen < 4) {
        goToScreen(state.screen + 1);
      } else {
        submitReservation();
      }
    });

    $('#dd-res-back')?.addEventListener('click', () => {
      const nextBtn = $('#dd-res-next');
      if (nextBtn) nextBtn.disabled = false;
      goToScreen(state.screen - 1);
    });
  }

  function goToScreen(n) {
    $all('.dd-res-screen').forEach(s => s.classList.add('dd-res-screen--hidden'));
    $(`#dd-res-screen-${n}`)?.classList.remove('dd-res-screen--hidden');

    state.screen = n;

    $('#dd-res-progress-fill').style.width = `${(n/4)*100}%`;

    $all('.dd-res-step').forEach((el, i) => {
      el.classList.remove('dd-res-step--active','dd-res-step--done');
      if (i+1 === n) el.classList.add('dd-res-step--active');
      else if (i+1 < n) el.classList.add('dd-res-step--done');
    });

    const back = $('#dd-res-back');
    if (back) back.style.visibility = n > 1 ? 'visible' : 'hidden';

    const next = $('#dd-res-next');
    if (next) {
      if (n === 4)      { next.textContent = '✅ Confirm reservation'; }
      else if (n === 3) { next.textContent = 'Review booking →'; }
      else              { next.textContent = 'Continue →'; }
    }

    if (n === 4) populateConfirm();

    // Init the country-code picker the moment its field (screen 3) is visible,
    // matching checkout's init-when-shown timing. Guarded once-init in
    // initPhonePicker() makes re-entry to screen 3 a no-op.
    if (n === 3) initPhonePicker();

    const body = $('.dd-res-modal__body');
    if (body) body.scrollTop = 0;
  }

  // ── Populate deposit screen ───────────────────────────────
  function populateDepositScreen() {
    const amountEl = document.getElementById('dd-res-deposit-amount-display');
    if (amountEl && ddRes.depositAmount) {
      amountEl.textContent = ddRes.depositAmount.toLocaleString() + ' RWF';
    }
    const policyEl = document.getElementById('dd-res-refund-policy');
    if (policyEl && ddRes.refundPolicy) {
      policyEl.textContent = ddRes.refundPolicy;
    }
  }

  // ── Validation ────────────────────────────────────────────
  function validateScreen(n) {
    if (n === 1) {
      if (!state.date) { alert('Please select a date.'); return false; }
      if (!state.time) { alert('Please select a time slot.'); return false; }
    }
    if (n === 3) {
      if (!$('#dd-res-name')?.value.trim()) { alert('Please enter your name.'); return false; }
      if (!$('#dd-res-whatsapp')?.value.trim()) { alert('Please enter your WhatsApp number.'); return false; }
      // Hard-block ONLY when the picker loaded and judged it invalid.
      // isValidNumber() === null (utils not loaded) or itiWhatsapp undefined
      // (picker never inited) both skip this → fail-open.
      if (itiWhatsapp && itiWhatsapp.isValidNumber() === false) {
        showResPhoneWarn('Please enter a valid phone number.');
        return false;
      }
      showResPhoneWarn('');
      state.name     = $('#dd-res-name').value.trim();
      state.whatsapp = readPhone($('#dd-res-whatsapp'), itiWhatsapp);
      state.requests = $('#dd-res-requests')?.value.trim() || '';
    }
    return true;
  }

  // ── Populate confirm screen ───────────────────────────────
  function populateConfirm() {
    const d = state.date;
    const dateStr = d ? `${DAYS[d.getDay()]}, ${d.getDate()} ${MONTHS[d.getMonth()]}` : '—';
    const tableEl = $('#dd-res-table');
    const tableText = tableEl?.options[tableEl.selectedIndex]?.text || 'No preference';
    const today = new Date();
    const ref = `RES-${today.getFullYear()}${String(today.getMonth()+1).padStart(2,'0')}${String(today.getDate()).padStart(2,'0')}-XXXX`;

    setText('#dd-res-ref',     ref);
    setText('#dd-sum-date',    dateStr);
    setText('#dd-sum-time',    state.time ? fmt12(state.time) : '—');
    setText('#dd-sum-session', SESSIONS[state.session]?.label || '—');
    setText('#dd-sum-guests',  `${state.guests} guest${state.guests !== 1 ? 's' : ''}`);
    setText('#dd-sum-table',   tableText);
    setText('#dd-sum-name',    state.name     || '—');
    setText('#dd-sum-wa',      state.whatsapp || '—');
  }

  function setText(sel, val) {
    const el = $(sel);
    if (el) el.textContent = val;
  }

  // ── Submit ────────────────────────────────────────────────
  function submitReservation() {
    const btn = document.querySelector('.dd-res-confirm-btn');
    if ( btn ) {
      btn.disabled = true;
      btn.textContent = 'Confirming…';
    }

    let dateStr;
    if ( state.date instanceof Date ) {
      const y = state.date.getFullYear();
      const m = String( state.date.getMonth() + 1 ).padStart( 2, '0' );
      const d = String( state.date.getDate() ).padStart( 2, '0' );
      dateStr = `${y}-${m}-${d}`;
    } else {
      dateStr = state.date;
    }

    const formData = new FormData();
    formData.append( 'action',    'dd_submit_reservation' );
    formData.append( 'nonce',     ddRes.nonce || '' );
    formData.append( 'name',      state.name );
    formData.append( 'whatsapp',  state.whatsapp );
    formData.append( 'date',      dateStr );
    formData.append( 'time',      state.time );
    formData.append( 'session',   state.session );
    formData.append( 'guests',    state.guests );
    formData.append( 'table',     state.table || '' );
    formData.append( 'requests',  state.requests || '' );
    formData.append( 'source',    'homepage' );

    const ajaxUrl = ddRes.ajax_url || '/wp-admin/admin-ajax.php';

    fetch( ajaxUrl, { method: 'POST', body: formData } )
      .then( r => r.text().then( text => ({ status: r.status, text: text }) ) )
      .then( resp => {
        console.log( 'DD RESERVATION — HTTP', resp.status );
        console.log( 'DD RESERVATION — RAW RESPONSE:', resp.text );
        let res;
        try {
          res = JSON.parse( resp.text );
        } catch ( e ) {
          showSubmitError( btn, 'Server error: ' + resp.text.slice( 0, 200 ) );
          return;
        }

        if ( ! res.success ) {
          showSubmitError( btn, res.data && res.data.message ? res.data.message : 'Something went wrong. Please try again.' );
          return;
        }

        try {
          const data = res.data;

          // Free booking — show inline confirmation
          const refEl = document.querySelector( '.dd-res-booking-ref' );
          if ( refEl ) refEl.textContent = data.booking_ref;

          try {
            if ( window.DDTrack && typeof window.DDTrack.event === 'function' ) {
              window.DDTrack.event( 'reservation_made', null, null, {
                date:    dateStr,
                time:    state.time,
                session: state.session,
                guests:  state.guests,
                source:  'homepage',
              } );
            }
          } catch ( e ) { console.log( 'DD tracking skipped:', e ); }

          if ( btn ) btn.style.display = 'none';
          // Deposit booking → show the MoMo scan-&-pay QR panel; otherwise the
          // normal confirmation (with the opt-in WhatsApp handoff button).
          if ( depositActive ) {
            renderDepositPanel( data );
          } else {
            showWhatsAppButtons( data.admin_url, data.customer_url );
          }
        } catch ( e ) {
          console.log( 'DD RESERVATION — success handler error:', e );
          if ( btn ) btn.style.display = 'none';
          showWhatsAppButtons();
        }
      } )
      .catch( err => {
        console.log( 'DD RESERVATION — FETCH FAILED:', err );
        showSubmitError( btn, 'Network error. Please try again.' );
      } );
  }

  function showSubmitError( btn, message ) {
    if ( btn ) {
      btn.disabled = false;
      if (state.screen === 5)      btn.textContent = '✅ Confirm reservation';
      else if (state.screen === 4) btn.textContent = ddRes.depositEnabled ? 'Pay deposit →' : 'Confirm reservation →';
      else if (state.screen === 3) btn.textContent = 'Review booking →';
      else                         btn.textContent = 'Continue →';
    }
    // Show error in whichever screen's error element is visible
    const errEl = document.querySelector( `#dd-res-screen-${state.screen} .dd-res-submit-error` );
    if ( errEl ) {
      errEl.textContent = message;
      errEl.hidden = false;
    }
  }

  function showWhatsAppButtons( adminUrl, customerUrl ) {
    var confirmArea = document.querySelector( '.dd-res-confirm-area' );
    if ( ! confirmArea ) return;

    // Reuse the ORDER confirmation's button styling verbatim — the same
    // classes the checkout drawer uses (.dd-confirm-panel__whatsapp green
    // pill + .dd-confirm-panel__close accent pill, both in cart.css, which
    // loads on this surface). A centered flex column mirrors the order
    // panel's stacked layout so the two look identical. No new CSS.
    var html = '<div style="display:flex;flex-direction:column;align-items:center;text-align:center;padding:8px 0;">'
             + '<p style="color:#25D366;font-weight:700;font-size:16px;margin:0 0 16px;">✅ Booking received!</p>';

    // Opt-in restaurant WhatsApp handoff — tap only, NEVER auto-open.
    // Uses the RESTAURANT ticket (admin_url). Hidden when the setting is
    // off or the URL is empty/missing (no dead control).
    if ( ddRes.whatsappHandoff && adminUrl ) {
        html += '<a href="' + adminUrl + '" target="_blank" rel="noopener noreferrer" '
              + 'class="dd-confirm-panel__whatsapp">'
              + 'Send my booking to the restaurant on WhatsApp</a>';
    }

    html += '<button id="dd-res-close-confirm" type="button" class="dd-confirm-panel__close">Close</button>'
          + '</div>';
    confirmArea.innerHTML = html;

    var closeBtn = document.getElementById( 'dd-res-close-confirm' );
    if ( closeBtn ) {
        closeBtn.addEventListener( 'click', function () {
            closeModal();
        } );
    }
  }

  // ── Deposit scan-&-pay QR (mirrors the orders R7 rendering) ─────────────────
  // The USSD payload string is built server-side (dd_momo_ussd_payload → ddRes
  // .depositPayload) so its format never drifts from the orders QR. These helpers
  // are only the PRESENTATION (QR image + tap-to-copy), duplicated from cart.js on
  // purpose — cart.js's live orders QR must not be touched.
  function makeQrDataUrl( text ) {
    if ( typeof qrcode === 'undefined' ) return '';
    try {
      var qr = qrcode( 0, 'M' );        // 0 = auto-size, M = error correction
      qr.addData( text );
      qr.make();
      return qr.createDataURL( 6, 8 );  // cellSize 6px, quiet-zone margin 8
    } catch ( e ) {
      return '';
    }
  }

  function legacyCopy( text ) {
    try {
      var ta = document.createElement( 'textarea' );
      ta.value = text;
      ta.setAttribute( 'readonly', '' );
      ta.style.position = 'absolute';
      ta.style.left     = '-9999px';
      document.body.appendChild( ta );
      ta.select();
      document.execCommand( 'copy' );
      document.body.removeChild( ta );
    } catch ( e ) {}
  }

  function copyText( text, rowEl ) {
    if ( ! text ) return;
    function feedback() {
      if ( ! rowEl ) return;
      rowEl.classList.add( 'is-copied' );
      setTimeout( function () { rowEl.classList.remove( 'is-copied' ); }, 1200 );
    }
    if ( navigator.clipboard && navigator.clipboard.writeText ) {
      navigator.clipboard.writeText( text ).then( feedback, function () { legacyCopy( text ); feedback(); } );
    } else {
      legacyCopy( text );
      feedback();
    }
  }

  function renderDepositPanel( data ) {
    var area = document.querySelector( '.dd-res-confirm-area' );
    if ( ! area ) return;

    // Lock the panel (sticky) + stash this booking's identifiers for the claim.
    depositPanelLocked = true;
    depositBookingRef  = data.booking_ref || '';
    depositWhatsappUrl = data.admin_url  || ''; // restaurant ticket (v3.10.58)

    var merchant = ddRes.momoMerchantCode ? String( ddRes.momoMerchantCode ) : '';
    var amount   = Math.round( Number( ddRes.depositAmount ) || 0 ); // integer RWF
    var ref      = data.booking_ref || '';
    var payload  = ddRes.depositPayload || ''; // server-built; '' when no merchant code
    var hasQr    = !!( merchant && payload );
    var qrImg    = hasQr ? makeQrDataUrl( payload ) : '';

    var html = '<div class="dd-momoqr">';
    html += '<p class="dd-momoqr__ordernum">✅ Booking received!</p>';

    if ( hasQr ) {
      html += '<p class="dd-momoqr__instruction">Pay your ' + amount.toLocaleString()
            + ' RWF deposit: scan the code, or dial *182*8*1*' + merchant + '*' + amount
            + '# on your phone.</p>';
      if ( qrImg ) {
        html += '<div class="dd-momoqr__code"><img src="' + qrImg
              + '" alt="Scan to pay deposit with MoMo" class="dd-momoqr__img"></div>';
      }
      html += '<a class="dd-momoqr__dial" href="' + payload + '">Dial to pay now</a>';
    } else {
      // Empty merchant code → no broken QR: copy rows + a plain MoMo note.
      html += '<p class="dd-momoqr__instruction">Pay your ' + amount.toLocaleString()
            + ' RWF deposit via MTN MoMo, then share your booking reference with the restaurant.</p>';
    }

    html += '<div class="dd-momoqr__details">';
    if ( merchant ) {
      html += '<button type="button" class="dd-momoqr__row" data-copy="' + merchant + '">'
            + '<span class="dd-momoqr__label">Merchant code</span>'
            + '<span class="dd-momoqr__value">' + merchant + '</span></button>';
    }
    html += '<button type="button" class="dd-momoqr__row" data-copy="' + amount + '">'
          + '<span class="dd-momoqr__label">Deposit (RWF)</span>'
          + '<span class="dd-momoqr__value">' + amount.toLocaleString() + '</span></button>';
    html += '<button type="button" class="dd-momoqr__row" data-copy="' + ref + '">'
          + '<span class="dd-momoqr__label">Booking reference</span>'
          + '<span class="dd-momoqr__value">' + ref + '</span></button>';
    html += '</div>';

    html += '<p class="dd-momoqr__note">Tap any detail above to copy.</p>';
    html += '<button id="dd-res-momo-claim" type="button" class="dd-momoqr__claim">I have paid — notify restaurant</button>';
    html += '<p class="dd-momoqr__recorded" id="dd-res-momo-recorded" hidden>Payment recorded — you can close this.</p>';
    html += '</div>';

    area.innerHTML = html;

    // Tap-to-copy on each detail row (iOS fallback + reconciliation).
    var details = area.querySelector( '.dd-momoqr__details' );
    if ( details ) {
      details.addEventListener( 'click', function ( e ) {
        var row = e.target.closest ? e.target.closest( '.dd-momoqr__row' ) : null;
        if ( ! row ) return;
        copyText( row.getAttribute( 'data-copy' ) || '', row );
      } );
    }

    // "I have paid — notify restaurant": ALWAYS records the claim (pending → claimed);
    // opens the booking's WhatsApp ticket too IF handoff is on. Panel stays open.
    var claimBtn = document.getElementById( 'dd-res-momo-claim' );
    if ( claimBtn ) {
      claimBtn.addEventListener( 'click', function () {
        if ( claimBtn.disabled ) return;      // guard against double-tap
        claimBtn.disabled    = true;
        claimBtn.textContent = 'Recording…';

        // WhatsApp handoff (opt-in) — open in-gesture so it isn't popup-blocked.
        // App-switch is fine: the panel is sticky and still here on return.
        if ( ddRes.whatsappHandoff && depositWhatsappUrl ) {
          var a = document.createElement( 'a' );
          a.href   = depositWhatsappUrl;
          a.target = '_blank';
          a.rel    = 'noopener noreferrer';
          document.body.appendChild( a );
          a.click();
          document.body.removeChild( a );
        }

        // Claim (always) — flip pending → claimed. Server is idempotent; NEVER 'paid'.
        claimDeposit( depositBookingRef, function () {
          if ( claimBtn ) { claimBtn.disabled = true; claimBtn.hidden = true; }
          var recorded = document.getElementById( 'dd-res-momo-recorded' );
          if ( recorded ) recorded.hidden = false;
        }, function ( message ) {
          // Allow a retry (claim is idempotent; any WhatsApp already opened).
          claimBtn.disabled    = false;
          claimBtn.textContent = 'I have paid — notify restaurant';
          var hint = area.querySelector( '.dd-momoqr__note' );
          if ( hint ) hint.textContent = message || 'Could not record. Please try again.';
        } );
      } );
    }
  }

  function claimDeposit( bookingRef, onSuccess, onError ) {
    if ( ! bookingRef ) { if ( onError ) onError(); return; }
    var fd = new FormData();
    fd.append( 'action',      'dd_reservation_claim_deposit' );
    fd.append( 'nonce',       ddRes.nonce || '' );
    fd.append( 'booking_ref', bookingRef );

    fetch( ddRes.ajax_url || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd } )
      .then( function ( r ) { return r.json(); } )
      .then( function ( res ) {
        if ( res && res.success ) { if ( onSuccess ) onSuccess(); }
        else { if ( onError ) onError( res && res.data && res.data.message ); }
      } )
      .catch( function () { if ( onError ) onError(); } );
  }

  // ── Run ───────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
