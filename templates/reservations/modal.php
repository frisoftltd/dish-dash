<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<!-- ── RESERVATION MODAL ───────────────────────────────────────────────── -->
<div class="dd-res-overlay" id="dd-res-overlay" aria-hidden="true">
  <div class="dd-res-modal" role="dialog" aria-modal="true" aria-label="Reserve a table">

    <!-- Modal header -->
    <div class="dd-res-modal__header">
      <div class="dd-res-modal__title">Reserve a Table</div>
      <button class="dd-res-modal__close" id="dd-res-close" aria-label="Close">✕</button>
    </div>

    <!-- Progress bar -->
    <div class="dd-res-progress" role="progressbar" aria-valuemin="1" aria-valuemax="4">
      <div class="dd-res-progress__track">
        <div class="dd-res-progress__fill" id="dd-res-progress-fill"></div>
      </div>
      <div class="dd-res-progress__steps">
        <span class="dd-res-step dd-res-step--active" data-step="1">Date</span>
        <span class="dd-res-step" data-step="2">Guests</span>
        <span class="dd-res-step" data-step="3">Details</span>
        <span class="dd-res-step" data-step="4">Confirm</span>
      </div>
    </div>

    <!-- Modal body (screens) -->
    <div class="dd-res-modal__body">

      <!-- SCREEN 1 — Date & Session & Time -->
      <div class="dd-res-screen" id="dd-res-screen-1">
        <div class="dd-res-screen__title">When would you like to visit?</div>

        <div class="dd-res-field-block">
          <label class="dd-res-label">📅 Date</label>
          <div class="dd-res-date-wrap">
            <button class="dd-res-date-arrow dd-res-date-arrow--left" id="dd-res-date-prev" aria-label="Previous dates" type="button">‹</button>
            <div class="dd-res-date-scroll" id="dd-res-dates" role="listbox" aria-label="Select date"></div>
            <button class="dd-res-date-arrow dd-res-date-arrow--right" id="dd-res-date-next" aria-label="Next dates" type="button">›</button>
          </div>
        </div>

        <div class="dd-res-field-block">
          <label class="dd-res-label">🍽 Session</label>
          <div class="dd-res-toggle" role="group">
            <button class="dd-res-toggle__btn dd-res-toggle__btn--active" data-session="lunch">Lunch</button>
            <button class="dd-res-toggle__btn" data-session="dinner">Dinner</button>
          </div>
        </div>

        <div class="dd-res-field-block">
          <label class="dd-res-label">🕐 Time</label>
          <div class="dd-res-slots" id="dd-res-slots" role="listbox" aria-label="Select time"></div>
        </div>

        <div class="dd-res-deposit-notice" id="dd-res-deposit-notice">
          💳 <span id="dd-res-deposit-badge-amount"></span> deposit required
        </div>
      </div>

      <!-- SCREEN 2 — Guests & Table -->
      <div class="dd-res-screen dd-res-screen--hidden" id="dd-res-screen-2">
        <div class="dd-res-screen__title">Who&#39;s joining you?</div>

        <div class="dd-res-field-block">
          <label class="dd-res-label">👥 Number of guests</label>
          <div class="dd-res-stepper">
            <button class="dd-res-stepper__btn" id="dd-guests-minus" aria-label="Fewer guests">−</button>
            <span class="dd-res-stepper__val" id="dd-guests-val">2</span>
            <button class="dd-res-stepper__btn" id="dd-guests-plus" aria-label="More guests">+</button>
          </div>
        </div>

        <div class="dd-res-field-block">
          <label class="dd-res-label" for="dd-res-table">🪱 Table preference</label>
          <select class="dd-res-select" id="dd-res-table" name="dd_table">
            <option value="">No preference</option>
            <?php if ( class_exists( 'DD_Reservations_Module' ) ) :
                  foreach ( DD_Reservations_Module::get_active_section_names() as $section_name ) : ?>
              <option value="<?php echo esc_attr( $section_name ); ?>">
                <?php echo esc_html( $section_name ); ?>
              </option>
            <?php endforeach; endif; ?>
          </select>
        </div>
      </div>

      <!-- SCREEN 3 — Contact details -->
      <div class="dd-res-screen dd-res-screen--hidden" id="dd-res-screen-3">
        <div class="dd-res-screen__title">Your contact details</div>

        <div class="dd-res-field-block">
          <label class="dd-res-label" for="dd-res-name">👤 Full name</label>
          <input class="dd-res-input" type="text" id="dd-res-name" name="dd_name"
                 placeholder="Your name" autocomplete="name" required>
        </div>

        <div class="dd-res-field-block">
          <label class="dd-res-label" for="dd-res-whatsapp">📱 WhatsApp number</label>
          <input class="dd-res-input" type="tel" id="dd-res-whatsapp" name="dd_whatsapp"
                 placeholder="+250 78 000 0000" autocomplete="tel" required>
        </div>

        <div class="dd-res-field-block">
          <label class="dd-res-label" for="dd-res-requests">
            💬 Special requests <span class="dd-res-optional">(optional)</span>
          </label>
          <textarea class="dd-res-input dd-res-textarea" id="dd-res-requests"
                    rows="3" placeholder="Dietary requirements, occasion, seating preference…"></textarea>
        </div>
      </div>

      <!-- SCREEN 4 — Confirm your booking -->
      <div class="dd-res-screen dd-res-screen--hidden" id="dd-res-screen-4">
        <div class="dd-res-screen__title">Confirm your booking</div>

        <div class="dd-res-confirm-card">
          <div class="dd-res-confirm-ref dd-res-booking-ref" id="dd-res-ref">RES-––––––-XXXX</div>
          <ul class="dd-res-confirm-list" id="dd-res-confirm-list">
            <li><span class="dd-res-confirm-icon">📅</span><span id="dd-sum-date">—</span></li>
            <li><span class="dd-res-confirm-icon">🕐</span><span id="dd-sum-time">—</span></li>
            <li><span class="dd-res-confirm-icon">🍽</span><span id="dd-sum-session">—</span></li>
            <li><span class="dd-res-confirm-icon">👥</span><span id="dd-sum-guests">—</span></li>
            <li><span class="dd-res-confirm-icon">🪱</span><span id="dd-sum-table">—</span></li>
            <li class="dd-res-confirm-divider"></li>
            <li><span class="dd-res-confirm-icon">👤</span><span id="dd-sum-name">—</span></li>
            <li><span class="dd-res-confirm-icon">📱</span><span id="dd-sum-wa">—</span></li>
          </ul>
        </div>

        <div class="dd-res-confirm-area">
          <p class="dd-res-submit-error" hidden style="color:#dc2626;font-size:14px;margin:0 0 10px"></p>
        </div>
      </div>

    </div><!-- /modal body -->

    <!-- Modal footer (navigation) -->
    <div class="dd-res-modal__footer">
      <button class="dd-res-back-btn" id="dd-res-back" style="visibility:hidden">← Back</button>
      <button class="dd-res-next-btn dd-res-confirm-btn" id="dd-res-next">Continue →</button>
    </div>

  </div><!-- /modal -->
</div>
<!-- ── /RESERVATION MODAL ─────────────────────────────────────────────────── -->
