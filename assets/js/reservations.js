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
  // Phase 4C deposit deferred — force off until payment gateway is fully integrated
  const depositActive = false; // was: ddRes.depositEnabled

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

    $('#dd-res-close')?.addEventListener('click', closeModal);

    $('#dd-res-overlay')?.addEventListener('click', e => {
      if (e.target === $('#dd-res-overlay')) closeModal();
    });

    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') closeModal();
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
      state.name     = $('#dd-res-name').value.trim();
      state.whatsapp = $('#dd-res-whatsapp').value.trim();
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
            if ( window.DDTrack && typeof window.DDTrack.track === 'function' ) {
              window.DDTrack.track( 'reservation_made', {
                date:    dateStr,
                time:    state.time,
                session: state.session,
                guests:  state.guests,
                source:  'homepage',
              } );
            }
          } catch ( e ) { console.log( 'DD tracking skipped:', e ); }

          showWhatsAppButtons( data.admin_url, data.customer_url );
        } catch ( e ) {
          console.log( 'DD RESERVATION — success handler error:', e );
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
    const backBtn = $('#dd-res-back');
    if (backBtn) backBtn.style.visibility = 'hidden';
    const confirmArea = document.querySelector( '.dd-res-confirm-area' );
    if ( ! confirmArea ) return;

    confirmArea.innerHTML = `
        <p style="margin:0 0 12px;font-weight:600;color:#15803d">✅ Booking received!</p>
        <div style="display:flex;flex-direction:column;gap:10px">
            ${ adminUrl ? `<a href="${ adminUrl }" target="_blank" rel="noopener noreferrer" style="display:block;padding:12px;background:#65040d;color:#fff;border-radius:8px;text-align:center;text-decoration:none;font-weight:600">
                📲 Notify Restaurant
            </a>` : '' }
            ${ customerUrl ? `<a href="${ customerUrl }" target="_blank" rel="noopener noreferrer" style="display:block;padding:12px;background:#15803d;color:#fff;border-radius:8px;text-align:center;text-decoration:none;font-weight:600">
                💬 Send My Confirmation
            </a>` : '' }
            <button id="dd-res-close-confirm" type="button" style="padding:12px;background:transparent;border:1px solid #EADFCE;border-radius:8px;cursor:pointer;font-weight:600">
                Close
            </button>
        </div>
    `;

    const closeBtn = document.getElementById( 'dd-res-close-confirm' );
    if ( closeBtn ) {
        closeBtn.addEventListener( 'click', function () {
            closeModal();
        } );
    }
  }

  // ── Run ───────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
