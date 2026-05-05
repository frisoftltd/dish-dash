/**
 * reservations.js — Phase 4A: Reservation UI Shell
 * Dish Dash Plugin — Fri Soft Ltd
 * Pure UI — no AJAX, no backend calls. Phase 4B wires the submit.
 */

(function () {
  'use strict';

  // ── Config ────────────────────────────────────────────────
  const SESSIONS = {
    lunch:  { label: 'Lunch',  start: '11:00', end: '15:00', step: 30 },
    dinner: { label: 'Dinner', start: '17:00', end: '22:00', step: 30 },
  };

  const DAYS_SHORT = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  const MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const DATE_COUNT = 30;
  const GUESTS_MIN = 1;
  const GUESTS_MAX = 20;

  // ── State ─────────────────────────────────────────────────
  const state = {
    guests:  2,
    date:    null,   // Date object
    session: 'lunch',
    time:    null,   // '12:30'
    table:   '',
    name:    '',
    whatsapp: '',
    requests: '',
    screen:  1,       // Mobile active screen (1/2/3)
  };

  // ── DOM refs ──────────────────────────────────────────────
  const $ = (sel, ctx) => (ctx || document).querySelector(sel);
  const $all = (sel, ctx) => [...(ctx || document).querySelectorAll(sel)];

  // ── Init ──────────────────────────────────────────────────
  function init() {
    if (!$('#reserve')) return;   // Not on homepage — bail

    buildDatePills();
    buildTimeSlots();
    bindStepper();
    bindSessionToggle();
    bindNavButtons();
    bindConfirmButton();
    bindInputSync();
  }

  // ── Date pills ────────────────────────────────────────────
  function buildDatePills() {
    const container = $('#dd-res-dates');
    if (!container) return;

    const today = new Date();
    today.setHours(0,0,0,0);
    const fragment = document.createDocumentFragment();

    for (let i = 0; i < DATE_COUNT; i++) {
      const d = new Date(today);
      d.setDate(today.getDate() + i);

      const pill = document.createElement('button');
      pill.className = 'dd-res-date-pill' + (i === 0 ? ' dd-res-date-pill--selected' : '');
      pill.setAttribute('role', 'option');
      pill.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
      pill.dataset.date = d.toISOString().slice(0, 10);

      pill.innerHTML =
        `<span class="dd-res-date-pill__day">${DAYS_SHORT[d.getDay()]}</span>` +
        `<span class="dd-res-date-pill__num">${d.getDate()}</span>` +
        `<span class="dd-res-date-pill__mon">${MONTHS_SHORT[d.getMonth()]}</span>`;

      if (i === 0) state.date = d;

      pill.addEventListener('click', () => selectDate(pill, d));
      fragment.appendChild(pill);
    }

    container.appendChild(fragment);
    updateSummary();
  }

  function selectDate(pill, d) {
    $all('.dd-res-date-pill').forEach(p => {
      p.classList.remove('dd-res-date-pill--selected');
      p.setAttribute('aria-selected', 'false');
    });
    pill.classList.add('dd-res-date-pill--selected');
    pill.setAttribute('aria-selected', 'true');
    state.date = d;
    updateSummary();
  }

  // ── Time slots ────────────────────────────────────────────
  function buildTimeSlots() {
    renderSlots(state.session);
  }

  function renderSlots(sessionKey) {
    const container = $('#dd-res-slots');
    if (!container) return;

    const cfg = SESSIONS[sessionKey];
    container.innerHTML = '';

    const [sh, sm] = cfg.start.split(':').map(Number);
    const [eh, em] = cfg.end.split(':').map(Number);
    const startMin = sh * 60 + sm;
    const endMin   = eh * 60 + em;

    for (let t = startMin; t < endMin; t += cfg.step) {
      const hh = String(Math.floor(t / 60)).padStart(2, '0');
      const mm = String(t % 60).padStart(2, '0');
      const label = formatTime12(`${hh}:${mm}`);

      const btn = document.createElement('button');
      btn.className = 'dd-res-slot';
      btn.textContent = label;
      btn.dataset.time = `${hh}:${mm}`;
      btn.setAttribute('role', 'option');
      btn.setAttribute('aria-selected', 'false');

      btn.addEventListener('click', () => selectSlot(btn, `${hh}:${mm}`));
      container.appendChild(btn);
    }

    // Auto-select first slot
    const first = container.querySelector('.dd-res-slot');
    if (first) selectSlot(first, first.dataset.time, true);
  }

  function selectSlot(btn, time, silent) {
    $all('.dd-res-slot').forEach(s => {
      s.classList.remove('dd-res-slot--selected');
      s.setAttribute('aria-selected', 'false');
    });
    btn.classList.add('dd-res-slot--selected');
    btn.setAttribute('aria-selected', 'true');
    state.time = time;
    if (!silent) updateSummary();
  }

  function formatTime12(t24) {
    const [h, m] = t24.split(':').map(Number);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12  = ((h + 11) % 12 + 1);
    return `${h12}:${String(m).padStart(2,'0')} ${ampm}`;
  }

  // ── Guests stepper ────────────────────────────────────────
  function bindStepper() {
    const minus = $('#dd-guests-minus');
    const plus  = $('#dd-guests-plus');
    const val   = $('#dd-guests-val');
    if (!minus || !plus || !val) return;

    minus.addEventListener('click', () => {
      if (state.guests > GUESTS_MIN) {
        state.guests--;
        val.textContent = state.guests;
        updateSummary();
      }
    });

    plus.addEventListener('click', () => {
      if (state.guests < GUESTS_MAX) {
        state.guests++;
        val.textContent = state.guests;
        updateSummary();
      }
    });
  }

  // ── Session toggle ────────────────────────────────────────
  function bindSessionToggle() {
    $all('.dd-res-toggle__btn').forEach(btn => {
      btn.addEventListener('click', () => {
        $all('.dd-res-toggle__btn').forEach(b => b.classList.remove('dd-res-toggle__btn--active'));
        btn.classList.add('dd-res-toggle__btn--active');
        state.session = btn.dataset.session;
        state.time = null;
        renderSlots(state.session);
        updateSummary();
      });
    });
  }

  // ── Mobile screen navigation ──────────────────────────────
  function bindNavButtons() {
    const next1 = $('#dd-res-next-1');
    const next2 = $('#dd-res-next-2');
    const back2 = $('#dd-res-back-2');
    const back3 = $('#dd-res-back-3');

    if (next1) next1.addEventListener('click', () => goToScreen(2));
    if (next2) next2.addEventListener('click', () => {
      syncContactFromInputs();
      goToScreen(3);
    });
    if (back2) back2.addEventListener('click', () => goToScreen(1));
    if (back3) back3.addEventListener('click', () => goToScreen(2));
  }

  function goToScreen(n) {
    // Hide all screens
    $all('.dd-res-screen').forEach(s => s.classList.add('dd-res-screen--hidden'));

    // On mobile, left col shows screens 1 and 2; right col shows screen 3
    if (n === 1 || n === 2) {
      const target = $(`#dd-res-screen-${n}`);
      if (target) target.classList.remove('dd-res-screen--hidden');
    } else {
      const s3 = $('#dd-res-screen-3');
      if (s3) s3.classList.remove('dd-res-screen--hidden');
    }

    // Update dots
    $all('.dd-res-dot').forEach((dot, i) => {
      dot.classList.toggle('dd-res-dot--active', i + 1 === n);
    });

    state.screen = n;
    updateSummary();

    // Update summary strip on screen 2
    if (n === 2) updateStrip();
  }

  // ── Input sync ────────────────────────────────────────────
  function bindInputSync() {
    const nameInput = $('#dd-res-name');
    const waInput   = $('#dd-res-whatsapp');

    if (nameInput) nameInput.addEventListener('input', () => {
      state.name = nameInput.value;
      updateSummary();
    });

    if (waInput) waInput.addEventListener('input', () => {
      state.whatsapp = waInput.value;
      updateSummary();
    });
  }

  function syncContactFromInputs() {
    state.name     = ($('#dd-res-name')      || {}).value || '';
    state.whatsapp = ($('#dd-res-whatsapp')  || {}).value || '';
    state.requests = ($('#dd-res-requests')  || {}).value || '';
    state.table    = ($('#dd-res-table')     || {}).value || '';
    updateSummary();
  }

  // ── Summary update (right-col confirm card) ───────────────
  function updateSummary() {
    setText('#dd-sum-guests',  `${state.guests} guest${state.guests !== 1 ? 's' : ''}`);
    setText('#dd-sum-date',    state.date ? formatDate(state.date) : '—');
    setText('#dd-sum-time',    state.time ? formatTime12(state.time) : '—');
    setText('#dd-sum-session', SESSIONS[state.session]?.label || '—');
    setText('#dd-sum-table',   state.table
      ? ($('#dd-res-table')?.options[$('#dd-res-table')?.selectedIndex]?.text || state.table)
      : 'No preference');
    setText('#dd-sum-name',    state.name     || '—');
    setText('#dd-sum-wa',      state.whatsapp || '—');

    // Generate placeholder booking ref from today
    const d = new Date();
    const dateStr = `${d.getFullYear()}${String(d.getMonth()+1).padStart(2,'0')}${String(d.getDate()).padStart(2,'0')}`;
    setText('#dd-res-ref', `RES-${dateStr}-XXXX`);

    // Table select sync
    const tableEl = $('#dd-res-table');
    if (tableEl) tableEl.addEventListener('change', () => {
      state.table = tableEl.value;
      updateSummary();
    });
  }

  function updateStrip() {
    const strip = $('#dd-res-strip-2');
    if (!strip) return;
    const items = [
      `${state.guests} guest${state.guests !== 1 ? 's' : ''}`,
      state.date ? formatDate(state.date) : null,
      state.time ? formatTime12(state.time) : null,
      SESSIONS[state.session]?.label,
    ].filter(Boolean);

    strip.innerHTML = items.map(t => `<span class="dd-res-strip-item">${t}</span>`).join('');
  }

  function setText(sel, val) {
    const el = $(sel);
    if (el) el.textContent = val;
  }

  function formatDate(d) {
    return `${DAYS_SHORT[d.getDay()]}, ${d.getDate()} ${MONTHS_SHORT[d.getMonth()]}`;
  }

  // ── Confirm button (Phase 4A: placeholder) ────────────────
  function bindConfirmButton() {
    const btn = $('#dd-res-submit');
    if (!btn) return;

    btn.addEventListener('click', () => {
      syncContactFromInputs();

      // Validate minimum fields
      if (!state.date) { alert('Please select a date.'); return; }
      if (!state.time) { alert('Please select a time slot.'); return; }
      if (!state.name.trim()) { alert('Please enter your name.'); return; }
      if (!state.whatsapp.trim()) { alert('Please enter your WhatsApp number.'); return; }

      // Phase 4A placeholder
      alert(`✅ Booking received!\n\nRef: RES-${new Date().toISOString().slice(0,10).replace(/-/g,'')}-XXXX\n\nWe'll confirm via WhatsApp shortly.\n\n(Backend wiring coming in Phase 4B)`);
    });
  }

  // ── Run ───────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
