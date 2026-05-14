/**
 * reservations.js — Phase 4A v2: 4-Screen Reservation Modal
 * Dish Dash Plugin — Fri Soft Ltd
 * Pure UI — no AJAX. Phase 4B wires the submit.
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

  // ── Init ──────────────────────────────────────────────────
  function init() {
    if (!$('#dd-res-overlay')) return;

    buildDatePills();
    buildSlots('lunch');
    bindOpenClose();
    bindStepper();
    bindSessionToggle();
    bindNavigation();
    bindInputSync();
  }

  // ── Open / Close ──────────────────────────────────────────
  function bindOpenClose() {
    const openBtns = $all('#dd-open-reservation, .dd-hero__reserve, [href="#reserve"]');
    openBtns.forEach(btn => {
      if (btn) btn.addEventListener('click', e => { e.preventDefault(); openModal(); });
    });

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
      pill.dataset.date = d.toISOString().slice(0,10);
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

    // Arrow scroll buttons
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

    // Auto-select first
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
      if (state.screen < 4) goToScreen(state.screen + 1);
      else submitReservation();
    });

    $('#dd-res-back')?.addEventListener('click', () => {
      if (state.screen > 1) goToScreen(state.screen - 1);
    });
  }

  function goToScreen(n) {
    // Hide all screens
    $all('.dd-res-screen').forEach(s => s.classList.add('dd-res-screen--hidden'));
    $(`#dd-res-screen-${n}`)?.classList.remove('dd-res-screen--hidden');

    state.screen = n;

    // Progress bar
    $('#dd-res-progress-fill').style.width = `${(n/4)*100}%`;

    // Step labels
    $all('.dd-res-step').forEach((el, i) => {
      el.classList.remove('dd-res-step--active','dd-res-step--done');
      if (i+1 === n) el.classList.add('dd-res-step--active');
      else if (i+1 < n) el.classList.add('dd-res-step--done');
    });

    // Back button visibility
    const back = $('#dd-res-back');
    if (back) back.style.visibility = n > 1 ? 'visible' : 'hidden';

    // Next button label
    const next = $('#dd-res-next');
    if (next) {
      if (n === 4) { next.textContent = '✅ Confirm reservation'; }
      else if (n === 3) { next.textContent = 'Review booking →'; }
      else { next.textContent = 'Continue →'; }
    }

    // Populate confirm screen
    if (n === 4) populateConfirm();

    // Scroll modal body to top
    const body = $('.dd-res-modal__body');
    if (body) body.scrollTop = 0;
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

  // ── Submit (Phase 4A placeholder) ─────────────────────────
  function submitReservation() {
    alert(`✅ Booking received!\n\nWe'll confirm via WhatsApp shortly.\n\n(Backend coming in Phase 4B)`);
    closeModal();
  }

  // ── Run ───────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
