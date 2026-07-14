<?php
/**
 * File:    admin/pages/settings.php
 * Purpose: Renders and saves the Dish Dash general settings page
 *          (General, Delivery, Opening Hours, Reservations, Pricing & Fees, Security).
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core guard)
 *   - WordPress update_option(), check_admin_referer()
 *   - DD_Settings class (optional, settings also saved directly)
 *
 * Dependents (files that need this):
 *   - modules/template/class-dd-template-module.php  (loaded via render_settings())
 *
 * WP options written:
 *   dish_dash_currency_symbol, dish_dash_currency_position,
 *   dish_dash_tax_rate, dish_dash_tax_label, dish_dash_min_order,
 *   dish_dash_order_prefix, dish_dash_google_maps_key,
 *   dish_dash_claude_api_key, dish_dash_enable_pickup,
 *   dish_dash_enable_delivery, dish_dash_enable_dinein,
 *   dish_dash_enable_reservations, dish_dash_enable_pos,
 *   dd_per_order_fee, dd_minimum_order_amount,
 *   dd_payment_card_enabled, dd_payment_momo_enabled, dd_payment_cod_enabled
 *
 * Nonce action: dd_settings_save
 *
 * Last modified: v3.4.91
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Save settings
if ( isset( $_POST['dd_save_settings'] ) && check_admin_referer( 'dd_settings_save' ) ) {
    $fields = [
        'currency', 'currency_symbol', 'currency_position',
        'tax_rate', 'tax_label', 'min_order', 'order_prefix',
        'google_maps_key', 'claude_api_key',
        'enable_pickup', 'enable_delivery', 'enable_dinein',
        'enable_reservations', 'enable_pos',
    ];
    foreach ( $fields as $f ) {
        $val = isset( $_POST[ 'dish_dash_' . $f ] ) ? sanitize_text_field( $_POST[ 'dish_dash_' . $f ] ) : '0';
        update_option( 'dish_dash_' . $f, $val );
    }

    // Delivery settings
    update_option( 'dd_free_delivery_threshold', absint( $_POST['dd_free_delivery_threshold'] ?? 10000 ) );
    update_option( 'dd_delivery_fee',            absint( $_POST['dd_delivery_fee']            ?? 1500  ) );
    update_option( 'dd_delivery_eta',            sanitize_text_field( $_POST['dd_delivery_eta'] ?? '30–45 minutes' ) );
    update_option( 'dd_kitchen_prep_time',       absint( $_POST['dd_kitchen_prep_time'] ?? 30 ) );
    update_option( 'dd_whatsapp_admin',          sanitize_text_field( $_POST['dd_whatsapp_admin']   ?? '' ) );
    update_option( 'dd_whatsapp_kitchen',        sanitize_text_field( $_POST['dd_whatsapp_kitchen'] ?? '' ) );
    update_option( 'dd_admin_email',             sanitize_email( $_POST['dd_admin_email'] ?? '' ) );

    // Riders — rebuild array from parallel name/whatsapp POST arrays
    $rider_names  = $_POST['dd_rider_name']     ?? [];
    $rider_phones = $_POST['dd_rider_whatsapp'] ?? [];
    $riders_clean = [];
    foreach ( $rider_names as $i => $name ) {
        $name  = sanitize_text_field( $name );
        $phone = sanitize_text_field( $rider_phones[ $i ] ?? '' );
        if ( $name && $phone ) {
            $riders_clean[] = [ 'name' => $name, 'whatsapp' => $phone ];
        }
    }
    update_option( 'dd_riders', wp_json_encode( $riders_clean ) );

    // Opening hours — save JSON
    if ( isset( $_POST['dd_opening_hours'] ) ) {
        $raw     = wp_unslash( $_POST['dd_opening_hours'] );
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) {
            update_option( 'dd_opening_hours', $raw );
        }
    }

    // Closing soon threshold
    if ( isset( $_POST['dd_closing_soon_minutes'] ) ) {
        update_option( 'dd_closing_soon_minutes', absint( $_POST['dd_closing_soon_minutes'] ) );
    }

    // Timezone
    update_option( 'dd_timezone', sanitize_text_field( wp_unslash( $_POST['dd_timezone'] ?? 'Africa/Kigali' ) ) );

    // Reservation Settings
    update_option( 'dd_reservation_deposit_enabled',    isset( $_POST['dd_reservation_deposit_enabled'] ) ? 1 : 0 );
    update_option( 'dd_reservation_deposit_type',       sanitize_text_field( $_POST['dd_reservation_deposit_type'] ?? 'fixed' ) );
    update_option( 'dd_reservation_deposit_amount',     absint( $_POST['dd_reservation_deposit_amount'] ?? 2000 ) );
    update_option( 'dd_reservation_autocancel_hours',   absint( $_POST['dd_reservation_autocancel_hours'] ?? 2 ) );
    update_option( 'dd_reservation_refund_enabled',     isset( $_POST['dd_reservation_refund_enabled'] ) ? 1 : 0 );
    update_option( 'dd_reservation_refund_hours',       absint( $_POST['dd_reservation_refund_hours'] ?? 24 ) );
    update_option( 'dd_reservation_refund_policy_text', sanitize_textarea_field( $_POST['dd_reservation_refund_policy_text'] ?? '' ) );

    // Pricing & Fees
    update_option( 'dd_per_order_fee',        absint( $_POST['dd_per_order_fee']        ?? 750   ) );
    update_option( 'dd_minimum_order_amount', absint( $_POST['dd_minimum_order_amount'] ?? 10000 ) );
    update_option( 'dd_fees_enabled',         isset( $_POST['dd_fees_enabled']          ) ? '1' : '0' );

    // Order Handling
    update_option( 'dish_dash_order_notify_dashboard', isset( $_POST['dish_dash_order_notify_dashboard'] ) ? '1' : '0' );
    update_option( 'dish_dash_order_handoff_whatsapp',  isset( $_POST['dish_dash_order_handoff_whatsapp']  ) ? '1' : '0' );
    update_option( 'dish_dash_momo_merchant_code',      preg_replace( '/\D/', '', (string) ( $_POST['dish_dash_momo_merchant_code'] ?? '' ) ) );

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'dish-dash' ) . '</p></div>';

    do_action( 'dd_log_activity', [
        'action'      => 'settings_updated',
        'object_type' => 'setting',
        'object_id'   => 'general',
    ] );
}

// Opening hours read
$dd_opening_hours_raw = get_option( 'dd_opening_hours', '' );
$dd_opening_hours     = ! empty( $dd_opening_hours_raw )
    ? json_decode( $dd_opening_hours_raw, true )
    : [];
$dd_closing_soon      = get_option( 'dd_closing_soon_minutes', 30 );
$dd_timezone          = get_option( 'dd_timezone', 'Africa/Kigali' );

$days             = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
$default_sessions = [ 'sessions' => [ [ '11:00', '22:00' ] ] ];
?>
<div class="wrap dd-admin-wrap dd-settings-page">

<style>
.dd-settings-page .dd-settings-card {
    background: #fff;
    border: 1px solid #f0f0f0;
    border-radius: 10px;
    box-shadow: 0 1px 4px rgba(0,0,0,.07);
    padding: 24px 28px;
    margin-bottom: 28px;
}
.dd-settings-page .dd-section-heading {
    font-size: 15px;
    font-weight: 700;
    color: var(--dd-brand);
    border-bottom: 2px solid var(--dd-brand);
    padding-bottom: 10px;
    margin: 0 0 20px;
}
.dd-settings-page .dd-field-grid {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 16px;
    align-items: start;
    margin-bottom: 16px;
}
.dd-settings-page .dd-field-grid:last-child {
    margin-bottom: 0;
}
.dd-settings-page .dd-field-label {
    font-weight: 600;
    font-size: 13px;
    color: #111;
    padding-top: 6px;
    line-height: 1.4;
}
.dd-settings-page .dd-field-label .dd-label-hint {
    display: block;
    font-weight: 400;
    font-size: 11px;
    color: #999;
    margin-top: 2px;
}
.dd-settings-page .dd-field-control .description,
.dd-settings-page .dd-field-control p.description {
    font-size: 12px;
    color: #777;
    margin-top: 4px;
    margin-bottom: 0;
}
.dd-settings-page .dd-input {
    width: 100%;
    max-width: 400px;
}
.dd-settings-page .dd-input--short {
    max-width: 120px;
}
.dd-settings-page .dd-input--medium {
    max-width: 220px;
}
.dd-settings-page select.dd-input {
    max-width: 400px;
}
.dd-settings-page textarea.dd-input {
    max-width: 400px;
    width: 100%;
}
.dd-settings-page .dd-check-label {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 13px;
    color: #333;
    margin-bottom: 8px;
    cursor: pointer;
}
.dd-settings-page .dd-check-label input[type="checkbox"] {
    margin-top: 2px;
    flex-shrink: 0;
}
.dd-settings-page .dd-check-label .dd-check-desc {
    display: block;
    font-size: 11px;
    color: #999;
    margin-top: 2px;
}
.dd-settings-page .dd-rider-row {
    display: flex;
    gap: 10px;
    margin-bottom: 8px;
    align-items: center;
}
.dd-settings-page .dd-rider-row .dd-input {
    max-width: 180px;
}
.dd-settings-footer {
    position: sticky;
    bottom: 0;
    background: #fff;
    border-top: 1px solid #eee;
    padding: 16px 28px;
    z-index: 10;
    margin: 0 -8px;
    display: flex;
    align-items: center;
    gap: 16px;
}
.dd-settings-footer .button-primary {
    background: var(--dd-brand);
    border-color: var(--dd-brand-dark, var(--dd-brand));
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    padding: 8px 24px;
    height: auto;
    line-height: 1.5;
}
.dd-settings-footer .button-primary:hover {
    background: var(--dd-brand-dark, var(--dd-brand));
    border-color: var(--dd-brand-dark, var(--dd-brand));
}
.dd-hours-row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.dd-hours-row input[type="time"] {
    width: 120px;
    font-size: 13px;
}
#dd-fee-advisory {
    border: 1px solid var(--dd-brand);
    background: var(--dd-bg, #fff8f3);
    border-radius: 8px;
    padding: 16px 20px;
    max-width: 480px;
    margin-top: 16px;
    font-size: 14px;
    line-height: 1.5;
}
#dd-fee-advisory .dd-advisory__title {
    font-weight: 600;
    font-size: 13px;
    color: #444;
    margin-bottom: 6px;
}
</style>

    <h1><?php esc_html_e( 'Settings', 'dish-dash' ); ?></h1>

    <form method="post" action="">
        <?php wp_nonce_field( 'dd_settings_save' ); ?>

        <!-- ⚙️ General -->
        <div class="dd-settings-card">
            <h2 class="dd-section-heading">⚙️ General</h2>

            <div class="dd-field-grid">
                <div class="dd-field-label">Currency Symbol</div>
                <div class="dd-field-control">
                    <input type="text" name="dish_dash_currency_symbol" id="dish_dash_currency_symbol"
                           value="<?php echo esc_attr( get_option( 'dish_dash_currency_symbol', '$' ) ); ?>"
                           class="dd-input dd-input--short" />
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Symbol Position</div>
                <div class="dd-field-control">
                    <select name="dish_dash_currency_position" class="dd-input dd-input--medium">
                        <option value="before" <?php selected( get_option( 'dish_dash_currency_position' ), 'before' ); ?>>Before amount ($10)</option>
                        <option value="after"  <?php selected( get_option( 'dish_dash_currency_position' ), 'after' ); ?>>After amount (10$)</option>
                    </select>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Tax Rate (%)</div>
                <div class="dd-field-control">
                    <input type="number" step="0.01" min="0" max="100"
                           name="dish_dash_tax_rate"
                           value="<?php echo esc_attr( get_option( 'dish_dash_tax_rate', '0' ) ); ?>"
                           class="dd-input dd-input--short" />
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Minimum Order
                    <span class="dd-label-hint">WooCommerce checkout threshold</span>
                </div>
                <div class="dd-field-control">
                    <input type="number" step="0.01" min="0"
                           name="dish_dash_min_order"
                           value="<?php echo esc_attr( get_option( 'dish_dash_min_order', '0' ) ); ?>"
                           class="dd-input dd-input--short" />
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Order Number Prefix</div>
                <div class="dd-field-control">
                    <input type="text" name="dish_dash_order_prefix"
                           value="<?php echo esc_attr( get_option( 'dish_dash_order_prefix', 'DD-' ) ); ?>"
                           class="dd-input dd-input--short" />
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Google Maps API Key</div>
                <div class="dd-field-control">
                    <input type="text" name="dish_dash_google_maps_key"
                           value="<?php echo esc_attr( get_option( 'dish_dash_google_maps_key', '' ) ); ?>"
                           class="dd-input" />
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Claude AI API Key</div>
                <div class="dd-field-control">
                    <input type="password" name="dish_dash_claude_api_key"
                           value="<?php echo esc_attr( get_option( 'dish_dash_claude_api_key', '' ) ); ?>"
                           class="dd-input" />
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Enable Features</div>
                <div class="dd-field-control">
                    <?php
                    $features = [
                        'enable_pickup'       => 'Pickup ordering',
                        'enable_delivery'     => 'Delivery ordering',
                        'enable_dinein'       => 'Dine-in ordering',
                        'enable_reservations' => 'Table reservations',
                        'enable_pos'          => 'POS terminal',
                    ];
                    foreach ( $features as $key => $label ) : ?>
                    <label class="dd-check-label">
                        <input type="checkbox" name="dish_dash_<?php echo esc_attr( $key ); ?>" value="1"
                               <?php checked( '1', get_option( 'dish_dash_' . $key, '1' ) ); ?> />
                        <?php echo esc_html( $label ); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- 🚚 Delivery -->
        <div class="dd-settings-card">
            <h2 class="dd-section-heading">🚚 Delivery</h2>

            <div class="dd-field-grid">
                <div class="dd-field-label">Free Delivery Threshold (RWF)</div>
                <div class="dd-field-control">
                    <input type="number" name="dd_free_delivery_threshold" min="0" step="500"
                           value="<?php echo esc_attr( get_option( 'dd_free_delivery_threshold', 10000 ) ); ?>"
                           class="dd-input dd-input--short" />
                    <p class="description">Orders above this amount get free delivery. Default: 10,000 RWF</p>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Delivery Fee (RWF)</div>
                <div class="dd-field-control">
                    <input type="number" name="dd_delivery_fee" min="0" step="100"
                           value="<?php echo esc_attr( get_option( 'dd_delivery_fee', 1500 ) ); ?>"
                           class="dd-input dd-input--short" />
                    <p class="description">Flat fee charged when below threshold. Default: 1,500 RWF</p>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Delivery ETA</div>
                <div class="dd-field-control">
                    <input type="text" name="dd_delivery_eta"
                           value="<?php echo esc_attr( get_option( 'dd_delivery_eta', '30–45 minutes' ) ); ?>"
                           placeholder="30–45 minutes"
                           class="dd-input dd-input--medium" />
                    <p class="description">Shown to customer after placing order.</p>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Kitchen Prep Time (minutes)</div>
                <div class="dd-field-control">
                    <input type="number" id="dd_kitchen_prep_time" name="dd_kitchen_prep_time"
                           value="<?php echo esc_attr( get_option( 'dd_kitchen_prep_time', 30 ) ); ?>"
                           min="1" max="180" step="1"
                           class="dd-input dd-input--short" />
                    <p class="description">Expected time from order confirmed → ready. Used for kitchen queue alerts.</p>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Admin WhatsApp Number</div>
                <div class="dd-field-control">
                    <input type="text" name="dd_whatsapp_admin"
                           value="<?php echo esc_attr( get_option( 'dd_whatsapp_admin', '' ) ); ?>"
                           placeholder="+250 78 000 0000"
                           class="dd-input dd-input--medium" />
                    <p class="description">Restaurant WhatsApp number that receives order notifications.</p>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Kitchen WhatsApp Number
                    <span class="dd-label-hint">Receives order details when Accepted.</span>
                </div>
                <div class="dd-field-control">
                    <input type="text" id="dd_whatsapp_kitchen" name="dd_whatsapp_kitchen"
                           value="<?php echo esc_attr( get_option( 'dd_whatsapp_kitchen', '' ) ); ?>"
                           placeholder="+250 78 000 0000"
                           class="dd-input dd-input--medium" />
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Delivery Riders
                    <span class="dd-label-hint">Each rider gets a WhatsApp when an order is ready.</span>
                </div>
                <div class="dd-field-control">
                    <div id="dd-riders-list">
                        <?php
                        $saved_riders = json_decode( get_option( 'dd_riders', '[]' ), true );
                        if ( ! is_array( $saved_riders ) ) $saved_riders = [];
                        foreach ( $saved_riders as $rider ) : ?>
                        <div class="dd-rider-row">
                            <input type="text" name="dd_rider_name[]"
                                   value="<?php echo esc_attr( $rider['name'] ); ?>"
                                   placeholder="Rider name" class="dd-input">
                            <input type="text" name="dd_rider_whatsapp[]"
                                   value="<?php echo esc_attr( $rider['whatsapp'] ); ?>"
                                   placeholder="+250 78 000 0000" class="dd-input">
                            <button type="button" class="button dd-remove-rider"
                                    onclick="this.closest('.dd-rider-row').remove()"
                                    style="color:#991b1b;border-color:#fca5a5">Remove</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button" id="dd-add-rider" style="margin-top:8px">+ Add Rider</button>
                    <script>
                    document.getElementById('dd-add-rider').addEventListener('click', function() {
                        var row = document.createElement('div');
                        row.className = 'dd-rider-row';
                        row.innerHTML = '<input type="text" name="dd_rider_name[]" placeholder="Rider name" class="dd-input" style="max-width:180px">'
                            + '<input type="text" name="dd_rider_whatsapp[]" placeholder="+250 78 000 0000" class="dd-input" style="max-width:180px">'
                            + '<button type="button" class="button dd-remove-rider" onclick="this.closest(\'.dd-rider-row\').remove()" style="color:#991b1b;border-color:#fca5a5">Remove</button>';
                        document.getElementById('dd-riders-list').appendChild(row);
                    });
                    </script>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Admin Notification Email</div>
                <div class="dd-field-control">
                    <input type="email" name="dd_admin_email"
                           value="<?php echo esc_attr( get_option( 'dd_admin_email', get_option( 'admin_email' ) ) ); ?>"
                           class="dd-input dd-input--medium" />
                    <p class="description">Order notification emails are sent here. Defaults to WordPress admin email.</p>
                </div>
            </div>
        </div>

        <!-- 🕐 Opening Hours -->
        <div class="dd-settings-card">
            <h2 class="dd-section-heading">🕐 Opening Hours</h2>
            <p class="description" style="margin-bottom:20px;font-size:13px;color:#666;">
                Configure when the restaurant accepts orders. Customers will see a closed banner outside these hours.
            </p>

            <?php foreach ( $days as $day ) :
                $day_data = $dd_opening_hours[ $day ] ?? $default_sessions;
                $is_open  = ! empty( $day_data['open'] );
                $sessions = $day_data['sessions'] ?? [ [ '11:00', '22:00' ] ];
                while ( count( $sessions ) < 2 ) { $sessions[] = [ '', '' ]; }
            ?>
            <div class="dd-field-grid">
                <div class="dd-field-label" style="text-transform:capitalize;"><?php echo esc_html( $day ); ?></div>
                <div class="dd-field-control">
                    <div class="dd-hours-row">
                        <label class="dd-check-label" style="margin-bottom:0;min-width:70px;">
                            <input type="checkbox"
                                   name="dd_hours_open[<?php echo esc_attr( $day ); ?>]"
                                   value="1"
                                   <?php checked( $is_open ); ?>>
                            Open
                        </label>
                        <span>
                            <input type="time"
                                   name="dd_hours_s1_open[<?php echo esc_attr( $day ); ?>]"
                                   value="<?php echo esc_attr( $sessions[0][0] ?? '11:00' ); ?>">
                            &ndash;
                            <input type="time"
                                   name="dd_hours_s1_close[<?php echo esc_attr( $day ); ?>]"
                                   value="<?php echo esc_attr( $sessions[0][1] ?? '22:00' ); ?>">
                        </span>
                        <span style="color:#999;font-size:12px;">+ Break</span>
                        <span>
                            <input type="time"
                                   name="dd_hours_s2_open[<?php echo esc_attr( $day ); ?>]"
                                   value="<?php echo esc_attr( $sessions[1][0] ?? '' ); ?>">
                            &ndash;
                            <input type="time"
                                   name="dd_hours_s2_close[<?php echo esc_attr( $day ); ?>]"
                                   value="<?php echo esc_attr( $sessions[1][1] ?? '' ); ?>">
                            <span style="color:#999;font-size:11px;">(optional)</span>
                        </span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="dd-field-grid">
                <div class="dd-field-label">Closing Soon Warning</div>
                <div class="dd-field-control">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" name="dd_closing_soon_minutes"
                               value="<?php echo esc_attr( $dd_closing_soon ); ?>"
                               min="5" max="120" class="dd-input dd-input--short" />
                        <span style="font-size:13px;color:#555;">minutes before closing — show "Order now" warning banner</span>
                    </div>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Restaurant Timezone</div>
                <div class="dd-field-control">
                    <select name="dd_timezone" id="dd_timezone" class="dd-input dd-input--medium">
                        <?php
                        $saved_tz = get_option( 'dd_timezone', 'Africa/Kigali' );
                        $zones = [
                            'Africa/Kigali'       => 'Africa/Kigali (Rwanda, Uganda, Tanzania)',
                            'Africa/Nairobi'      => 'Africa/Nairobi (Kenya)',
                            'Africa/Lagos'        => 'Africa/Lagos (Nigeria, West Africa)',
                            'Africa/Accra'        => 'Africa/Accra (Ghana)',
                            'Africa/Johannesburg' => 'Africa/Johannesburg (South Africa)',
                            'Africa/Cairo'        => 'Africa/Cairo (Egypt)',
                            'Europe/London'       => 'Europe/London (UK)',
                            'Europe/Paris'        => 'Europe/Paris (France, Belgium)',
                            'America/New_York'    => 'America/New_York (US East)',
                            'America/Chicago'     => 'America/Chicago (US Central)',
                            'America/Los_Angeles' => 'America/Los_Angeles (US West)',
                            'Asia/Dubai'          => 'Asia/Dubai (UAE)',
                            'Asia/Riyadh'         => 'Asia/Riyadh (Saudi Arabia)',
                            'Asia/Kolkata'        => 'Asia/Kolkata (India)',
                            'Asia/Singapore'      => 'Asia/Singapore',
                            'Australia/Sydney'    => 'Australia/Sydney',
                        ];
                        foreach ( $zones as $tz => $label ) : ?>
                            <option value="<?php echo esc_attr( $tz ); ?>" <?php selected( $saved_tz, $tz ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">All opening hours, date validation, and order timing use this timezone.</p>
                </div>
            </div>
        </div>

        <!-- 📅 Reservations -->
        <div class="dd-settings-card">
            <h2 class="dd-section-heading">📅 Reservations</h2>

            <div class="dd-field-grid">
                <div class="dd-field-label">Require Deposit</div>
                <div class="dd-field-control">
                    <label class="dd-check-label">
                        <input type="checkbox" name="dd_reservation_deposit_enabled" value="1"
                               <?php checked( get_option( 'dd_reservation_deposit_enabled', 0 ), 1 ); ?>>
                        Customers must pay a deposit to confirm their booking
                    </label>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Deposit Type</div>
                <div class="dd-field-control">
                    <select name="dd_reservation_deposit_type" class="dd-input dd-input--medium">
                        <option value="fixed"   <?php selected( get_option( 'dd_reservation_deposit_type', 'fixed' ), 'fixed' ); ?>>Fixed amount (RWF)</option>
                        <option value="percent" <?php selected( get_option( 'dd_reservation_deposit_type', 'fixed' ), 'percent' ); ?>>Percentage of estimated order</option>
                    </select>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Deposit Amount</div>
                <div class="dd-field-control">
                    <input type="number" name="dd_reservation_deposit_amount"
                           value="<?php echo esc_attr( get_option( 'dd_reservation_deposit_amount', 2000 ) ); ?>"
                           min="0" step="100" class="dd-input dd-input--short" />
                    <p class="description">Enter RWF amount (for fixed) or percentage (for percent type).</p>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Auto-Cancel After</div>
                <div class="dd-field-control">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" name="dd_reservation_autocancel_hours"
                               value="<?php echo esc_attr( get_option( 'dd_reservation_autocancel_hours', 2 ) ); ?>"
                               min="1" max="72" class="dd-input dd-input--short" />
                        <span style="font-size:13px;color:#555;">hours</span>
                    </div>
                    <p class="description">Unpaid deposit bookings are automatically cancelled after this many hours.</p>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Allow Refunds</div>
                <div class="dd-field-control">
                    <label class="dd-check-label">
                        <input type="checkbox" name="dd_reservation_refund_enabled" value="1"
                               <?php checked( get_option( 'dd_reservation_refund_enabled', 0 ), 1 ); ?>>
                        Allow deposit refunds when customer cancels in time
                    </label>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Refund Window</div>
                <div class="dd-field-control">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" name="dd_reservation_refund_hours"
                               value="<?php echo esc_attr( get_option( 'dd_reservation_refund_hours', 24 ) ); ?>"
                               min="1" class="dd-input dd-input--short" />
                        <span style="font-size:13px;color:#555;">hours before reservation</span>
                    </div>
                    <p class="description">Customer must cancel at least this many hours before the booking to receive a refund.</p>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Refund Policy</div>
                <div class="dd-field-control">
                    <textarea name="dd_reservation_refund_policy_text" rows="3" class="dd-input textarea"
                              style="max-width:400px;width:100%;"><?php
                        echo esc_textarea( get_option( 'dd_reservation_refund_policy_text', '' ) );
                    ?></textarea>
                    <p class="description">Shown to customers on the booking review screen.</p>
                </div>
            </div>
        </div>

        <!-- 💰 Pricing & Fees -->
        <div class="dd-settings-card">
            <h2 class="dd-section-heading">💰 Pricing & Fees</h2>

            <div class="dd-field-grid">
                <div class="dd-field-label">Per-Order Flat Fee (RWF)</div>
                <div class="dd-field-control">
                    <input type="number" id="dd_per_order_fee" name="dd_per_order_fee"
                           value="<?php echo esc_attr( get_option( 'dd_per_order_fee', 750 ) ); ?>"
                           min="0" step="50" class="dd-input dd-input--short" />
                    <p class="description">Flat fee charged per confirmed order. Never a percentage. Applied to all payment methods.</p>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Minimum Order Amount (RWF)</div>
                <div class="dd-field-control">
                    <input type="number" id="dd_minimum_order_amount" name="dd_minimum_order_amount"
                           value="<?php echo esc_attr( get_option( 'dd_minimum_order_amount', 10000 ) ); ?>"
                           min="0" step="500" class="dd-input dd-input--short" />
                    <p class="description">Advisory minimum. Customers see this at checkout. Helps protect your margin.</p>

                    <div id="dd-fee-advisory">
                        <div class="dd-advisory__title">💡 Effective Fee Advisory</div>
                        <div id="dd-advisory-body">—</div>
                    </div>
                </div>
            </div>

            <script>
            (function(){
                var feeInput = document.getElementById('dd_per_order_fee');
                var minInput = document.getElementById('dd_minimum_order_amount');
                var panel    = document.getElementById('dd-fee-advisory');
                var body     = document.getElementById('dd-advisory-body');

                function update() {
                    var fee = parseFloat(feeInput.value) || 0;
                    var min = parseFloat(minInput.value) || 0;

                    if (min === 0) {
                        body.textContent = 'Set a minimum order amount to see your effective rate.';
                        panel.style.borderColor = '#ccc';
                        return;
                    }

                    var pct    = (fee / min * 100).toFixed(1);
                    var pctNum = parseFloat(pct);
                    var msg, color;

                    if (pctNum > 10) {
                        msg   = '⚠️ ' + pct + '% effective rate — Above 10%, consider raising your minimum.';
                        color = '#fbbf24';
                    } else if (pctNum >= 5) {
                        msg   = '✅ ' + pct + '% effective rate — Great rate, competitive vs Vuba Vuba\'s 15–30%.';
                        color = 'var(--dd-brand)';
                    } else {
                        msg   = '🏆 ' + pct + '% effective rate — Excellent, below 5%.';
                        color = '#86efac';
                    }

                    body.textContent = 'At RWF ' + min.toLocaleString() + ', your flat fee of RWF ' + fee.toLocaleString() + ' represents: ' + msg;
                    panel.style.borderColor = color;
                }

                feeInput.addEventListener('input', update);
                minInput.addEventListener('input', update);
                update();
            })();
            </script>

            <div style="margin:24px 0;border-top:1px solid #f0f0f0"></div>

            <div class="dd-field-grid">
                <div class="dd-field-label">Fee Tracking
                    <span class="dd-label-hint">Controls platform fee recording</span>
                </div>
                <div class="dd-field-control">
                    <label class="dd-check-label">
                        <input type="checkbox" name="dd_fees_enabled" value="1"
                            <?php checked( '1', get_option( 'dd_fees_enabled', '1' ) ); ?>>
                        <span>
                            Enable platform fee tracking
                            <span class="dd-check-desc">
                                When enabled, RWF <?php echo number_format( (int) get_option( 'dd_per_order_fee', 750 ) ); ?>
                                is recorded on each delivered order. Disable to pause without losing history.
                            </span>
                        </span>
                    </label>
                </div>
            </div>
        </div>

        <!-- 📦 Order Handling -->
        <div class="dd-settings-card">
            <h2 class="dd-section-heading">📦 Order Handling</h2>

            <div class="dd-field-grid">
                <div class="dd-field-label">Dashboard Notifications
                    <span class="dd-label-hint">In-dashboard order alerts</span>
                </div>
                <div class="dd-field-control">
                    <label class="dd-check-label">
                        <input type="checkbox" name="dish_dash_order_notify_dashboard" value="1"
                            <?php checked( '1', get_option( 'dish_dash_order_notify_dashboard', '1' ) ); ?>>
                        <span>
                            Show new orders in the dashboard
                            <span class="dd-check-desc">
                                Show new orders in the dashboard with sound + browser alerts.
                                Best for restaurants that keep the dashboard open.
                            </span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">WhatsApp Handoff
                    <span class="dd-label-hint">Customer sends order to you</span>
                </div>
                <div class="dd-field-control">
                    <label class="dd-check-label">
                        <input type="checkbox" name="dish_dash_order_handoff_whatsapp" value="1"
                            <?php checked( '1', get_option( 'dish_dash_order_handoff_whatsapp', '0' ) ); ?>>
                        <span>
                            Send the order to your WhatsApp after checkout
                            <span class="dd-check-desc">
                                After ordering, the customer sends their order to your WhatsApp.
                                Best for busy restaurants that don't watch a dashboard.
                            </span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label">MoMo Merchant Code</div>
                <div class="dd-field-control">
                    <input type="text" name="dish_dash_momo_merchant_code"
                           inputmode="numeric" pattern="[0-9]*"
                           value="<?php echo esc_attr( get_option( 'dish_dash_momo_merchant_code', '' ) ); ?>"
                           class="dd-input dd-input--medium" />
                    <p class="description">Your MTN MoMo merchant code, used for the payment QR. Digits only.</p>
                </div>
            </div>
        </div>

        <!-- Hidden field: assembled JSON sent on submit -->
        <input type="hidden" name="dd_opening_hours" id="dd_opening_hours_json" value="">

        <!-- Sticky footer -->
        <div class="dd-settings-footer">
            <input type="submit" name="dd_save_settings"
                   class="button button-primary"
                   value="<?php esc_attr_e( 'Save Settings', 'dish-dash' ); ?>">
        </div>

    </form>

<script>
document.querySelector('form').addEventListener('submit', function() {
    var days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    var result = {};
    days.forEach(function(day) {
        var openCb = document.querySelector('input[name="dd_hours_open[' + day + ']"]');
        var s1o    = document.querySelector('input[name="dd_hours_s1_open[' + day + ']"]');
        var s1c    = document.querySelector('input[name="dd_hours_s1_close[' + day + ']"]');
        var s2o    = document.querySelector('input[name="dd_hours_s2_open[' + day + ']"]');
        var s2c    = document.querySelector('input[name="dd_hours_s2_close[' + day + ']"]');

        var sessions = [];
        if ( s1o && s1c && s1o.value && s1c.value ) {
            sessions.push([ s1o.value, s1c.value ]);
        }
        if ( s2o && s2c && s2o.value && s2c.value ) {
            sessions.push([ s2o.value, s2c.value ]);
        }

        result[day] = {
            open:     openCb ? openCb.checked : false,
            sessions: sessions
        };
    });

    document.getElementById('dd_opening_hours_json').value = JSON.stringify(result);
});
</script>
</div>
