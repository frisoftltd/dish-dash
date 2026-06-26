<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap dd-audit-wrap">
    <h1 class="dd-audit-title">🔍 Dish Dash Audit</h1>
    <p class="dd-audit-subtitle">Automated scan of all 7 audit pillars. Run before every release.</p>

    <div class="dd-audit-actions">
        <button id="dd-run-audit" class="button button-primary button-large">Run Audit Now</button>
        <button id="dd-copy-report" class="button" style="display:none;">Copy Report for Claude</button>
        <button id="dd-export-text" class="button" style="display:none;">Export to Text</button>
        <span id="dd-audit-spinner" class="spinner" style="float:none;margin-top:4px;display:none;"></span>
    </div>

    <div id="dd-audit-summary" style="display:none;" class="dd-audit-summary"></div>

    <div id="dd-audit-results" class="dd-audit-results"></div>

    <div class="dd-audit-manual">
        <h2>P7 — Regression (Manual Checklist)</h2>
        <p>These tests require a real browser and cannot be automated server-side.</p>
        <ul class="dd-manual-checks">
            <li><label><input type="checkbox" data-key="mobile_layout"> Mobile layout renders correctly (375px viewport)</label></li>
            <li><label><input type="checkbox" data-key="ios_zoom"> iOS: inputs do not trigger zoom (font-size ≥ 16px)</label></li>
            <li><label><input type="checkbox" data-key="momo_flow"> MTN MoMo payment flow completes end-to-end</label></li>
            <li><label><input type="checkbox" data-key="pesapal_flow"> PesaPal drawer opens and payment completes</label></li>
            <li><label><input type="checkbox" data-key="cart_persist"> Cart persists across page reload</label></li>
            <li><label><input type="checkbox" data-key="whatsapp_notif"> WhatsApp notification fires after order placed</label></li>
            <li><label><input type="checkbox" data-key="order_history"> Order history visible in customer profile</label></li>
            <li><label><input type="checkbox" data-key="reorder"> One-click Reorder works for recent order</label></li>
        </ul>
        <p class="dd-manual-note">Checkbox state is saved in your browser automatically.</p>
    </div>
</div>
