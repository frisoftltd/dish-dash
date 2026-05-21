<?php
/**
 * File:    admin/pages/settings.php
 * Purpose: Renders and saves the Dish Dash general settings page
 *          (currency, tax, minimum order, order prefix, API keys,
 *          and feature toggles for pickup/delivery/dine-in/pos).
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
 *   dish_dash_enable_reservations, dish_dash_enable_pos
 *
 * Nonce action: dd_settings_save
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Recovery email handler (separate form, runs before main save)
if ( isset( $_POST['dd_send_recovery_email'] ) && check_admin_referer( 'dd_settings_save' ) ) {
    $path = get_option( 'dd_admin_custom_path', '' );
    if ( $path ) {
        wp_mail(
            get_option( 'admin_email' ),
            'Your Dish Dash admin URL',
            'Your custom admin URL is: ' . home_url( '/' . $path )
        );
        echo '<div class="notice notice-success"><p>Recovery email sent to ' . esc_html( get_option( 'admin_email' ) ) . '.</p></div>';
    } else {
        echo '<div class="notice notice-warning"><p>No custom admin path is set yet.</p></div>';
    }
}

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
    update_option( 'dd_whatsapp_admin',          sanitize_text_field( $_POST['dd_whatsapp_admin'] ?? '' ) );
    update_option( 'dd_admin_email',             sanitize_email( $_POST['dd_admin_email'] ?? '' ) );

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

    // Security — custom admin path (superadmin only)
    if ( current_user_can( 'manage_options' ) && isset( $_POST['dd_admin_custom_path'] ) ) {
        update_option( 'dd_admin_custom_path', sanitize_title( wp_unslash( $_POST['dd_admin_custom_path'] ) ) );
    }

    echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'dish-dash' ) . '</p></div>';
}
// Opening hours read
$dd_opening_hours_raw = get_option( 'dd_opening_hours', '' );
$dd_opening_hours     = ! empty( $dd_opening_hours_raw )
    ? json_decode( $dd_opening_hours_raw, true )
    : [];
$dd_closing_soon      = get_option( 'dd_closing_soon_minutes', 30 );
$dd_timezone          = get_option( 'dd_timezone', 'Africa/Kigali' );

$days = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];

// Defaults if nothing saved yet
$default_sessions = [ 'sessions' => [ [ '11:00', '22:00' ] ] ];
?>
<div class="wrap dd-admin-wrap">
    <h1><?php esc_html_e( 'Dish Dash Settings', 'dish-dash' ); ?></h1>

    <form method="post" action="">
        <?php wp_nonce_field( 'dd_settings_save' ); ?>

        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Currency Symbol', 'dish-dash' ); ?></th>
                <td><input type="text" name="dish_dash_currency_symbol" value="<?php echo esc_attr( get_option( 'dish_dash_currency_symbol', '$' ) ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Symbol Position', 'dish-dash' ); ?></th>
                <td>
                    <select name="dish_dash_currency_position">
                        <option value="before" <?php selected( get_option( 'dish_dash_currency_position' ), 'before' ); ?>><?php esc_html_e( 'Before amount ($10)', 'dish-dash' ); ?></option>
                        <option value="after"  <?php selected( get_option( 'dish_dash_currency_position' ), 'after' ); ?>><?php esc_html_e( 'After amount (10$)', 'dish-dash' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Tax Rate (%)', 'dish-dash' ); ?></th>
                <td><input type="number" step="0.01" min="0" max="100" name="dish_dash_tax_rate" value="<?php echo esc_attr( get_option( 'dish_dash_tax_rate', '0' ) ); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Minimum Order', 'dish-dash' ); ?></th>
                <td><input type="number" step="0.01" min="0" name="dish_dash_min_order" value="<?php echo esc_attr( get_option( 'dish_dash_min_order', '0' ) ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Order Number Prefix', 'dish-dash' ); ?></th>
                <td><input type="text" name="dish_dash_order_prefix" value="<?php echo esc_attr( get_option( 'dish_dash_order_prefix', 'DD-' ) ); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Google Maps API Key', 'dish-dash' ); ?></th>
                <td><input type="text" name="dish_dash_google_maps_key" value="<?php echo esc_attr( get_option( 'dish_dash_google_maps_key', '' ) ); ?>" class="large-text" /></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Claude AI API Key', 'dish-dash' ); ?></th>
                <td><input type="password" name="dish_dash_claude_api_key" value="<?php echo esc_attr( get_option( 'dish_dash_claude_api_key', '' ) ); ?>" class="large-text" /></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Enable Features', 'dish-dash' ); ?></th>
                <td>
                    <?php
                    $features = [
                        'enable_pickup'       => __( 'Pickup ordering', 'dish-dash' ),
                        'enable_delivery'     => __( 'Delivery ordering', 'dish-dash' ),
                        'enable_dinein'       => __( 'Dine-in ordering', 'dish-dash' ),
                        'enable_reservations' => __( 'Table reservations', 'dish-dash' ),
                        'enable_pos'          => __( 'POS terminal', 'dish-dash' ),
                    ];
                    foreach ( $features as $key => $label ) :
                    ?>
                    <label style="display:block;margin-bottom:6px">
                        <input type="checkbox" name="dish_dash_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( '1', get_option( 'dish_dash_' . $key, '1' ) ); ?> />
                        <?php echo esc_html( $label ); ?>
                    </label>
                    <?php endforeach; ?>
                </td>
            </tr>

        <!-- Delivery Settings -->
        <tr>
            <th scope="row"><?php esc_html_e( 'Free Delivery Threshold (RWF)', 'dish-dash' ); ?></th>
            <td>
                <input type="number" name="dd_free_delivery_threshold" min="0" step="500"
                       value="<?php echo esc_attr( get_option( 'dd_free_delivery_threshold', 10000 ) ); ?>"
                       class="regular-text">
                <p class="description"><?php esc_html_e( 'Orders above this amount get free delivery. Default: 10,000 RWF', 'dish-dash' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'Delivery Fee (RWF)', 'dish-dash' ); ?></th>
            <td>
                <input type="number" name="dd_delivery_fee" min="0" step="100"
                       value="<?php echo esc_attr( get_option( 'dd_delivery_fee', 1500 ) ); ?>"
                       class="regular-text">
                <p class="description"><?php esc_html_e( 'Flat delivery fee charged when below threshold. Default: 1,500 RWF', 'dish-dash' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'Delivery ETA', 'dish-dash' ); ?></th>
            <td>
                <input type="text" name="dd_delivery_eta"
                       value="<?php echo esc_attr( get_option( 'dd_delivery_eta', '30–45 minutes' ) ); ?>"
                       class="regular-text" placeholder="30–45 minutes">
                <p class="description"><?php esc_html_e( 'Shown to customer after placing order.', 'dish-dash' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'Admin WhatsApp Number', 'dish-dash' ); ?></th>
            <td>
                <input type="text" name="dd_whatsapp_admin"
                       value="<?php echo esc_attr( get_option( 'dd_whatsapp_admin', '' ) ); ?>"
                       class="regular-text" placeholder="+250 78 000 0000">
                <p class="description"><?php esc_html_e( 'Restaurant WhatsApp number that receives order notifications.', 'dish-dash' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'Admin Notification Email', 'dish-dash' ); ?></th>
            <td>
                <input type="email" name="dd_admin_email"
                       value="<?php echo esc_attr( get_option( 'dd_admin_email', get_option( 'admin_email' ) ) ); ?>"
                       class="regular-text">
                <p class="description"><?php esc_html_e( 'Order notification emails are sent here. Defaults to WordPress admin email.', 'dish-dash' ); ?></p>
            </td>
        </tr>
        </table>

        <hr style="margin: 30px 0;">
        <h2>Opening Hours</h2>
        <p class="description" style="margin-bottom:20px;">
            Configure when the restaurant accepts orders. Customers will see a closed banner outside these hours.
        </p>

        <table class="form-table">

          <!-- Per-day rows -->
          <?php foreach ( $days as $day ) :
              $day_data = $dd_opening_hours[ $day ] ?? $default_sessions;
              $is_open  = ! empty( $day_data['open'] );
              $sessions = $day_data['sessions'] ?? [ [ '11:00', '22:00' ] ];
              // Always give at least 2 session slots in the UI
              while ( count( $sessions ) < 2 ) { $sessions[] = [ '', '' ]; }
          ?>
          <tr valign="top">
              <th scope="row" style="text-transform:capitalize;"><?php echo esc_html( $day ); ?></th>
              <td>
                  <label style="margin-right:16px;">
                      <input type="checkbox"
                             name="dd_hours_open[<?php echo esc_attr( $day ); ?>]"
                             value="1"
                             <?php checked( $is_open ); ?>>
                      Open
                  </label>

                  <span class="dd-sessions" style="display:inline-flex;gap:12px;flex-wrap:wrap;align-items:center;">

                      <!-- Session 1 (always shown) -->
                      <span>
                          <input type="time"
                                 name="dd_hours_s1_open[<?php echo esc_attr( $day ); ?>]"
                                 value="<?php echo esc_attr( $sessions[0][0] ?? '11:00' ); ?>"
                                 style="width:120px;">
                          &ndash;
                          <input type="time"
                                 name="dd_hours_s1_close[<?php echo esc_attr( $day ); ?>]"
                                 value="<?php echo esc_attr( $sessions[0][1] ?? '22:00' ); ?>"
                                 style="width:120px;">
                      </span>

                      <span style="color:#999;font-size:12px;">+ Break</span>

                      <!-- Session 2 (split day) -->
                      <span>
                          <input type="time"
                                 name="dd_hours_s2_open[<?php echo esc_attr( $day ); ?>]"
                                 value="<?php echo esc_attr( $sessions[1][0] ?? '' ); ?>"
                                 style="width:120px;">
                          &ndash;
                          <input type="time"
                                 name="dd_hours_s2_close[<?php echo esc_attr( $day ); ?>]"
                                 value="<?php echo esc_attr( $sessions[1][1] ?? '' ); ?>"
                                 style="width:120px;">
                          <span style="color:#999;font-size:11px;">(optional)</span>
                      </span>

                  </span>
              </td>
          </tr>
          <?php endforeach; ?>

          <!-- Closing Soon -->
          <tr valign="top">
              <th scope="row">Closing Soon Warning</th>
              <td>
                  <input type="number"
                         name="dd_closing_soon_minutes"
                         value="<?php echo esc_attr( $dd_closing_soon ); ?>"
                         min="5" max="120" style="width:80px;">
                  <span class="description"> minutes before closing — show "Order now" warning banner</span>
              </td>
          </tr>

          <!-- Timezone -->
          <tr valign="top">
              <th scope="row">
                  <label for="dd_timezone">Restaurant Timezone</label>
              </th>
              <td>
                  <select name="dd_timezone" id="dd_timezone">
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
                      foreach ( $zones as $tz => $label ) :
                      ?>
                          <option value="<?php echo esc_attr( $tz ); ?>" <?php selected( $saved_tz, $tz ); ?>>
                              <?php echo esc_html( $label ); ?>
                          </option>
                      <?php endforeach; ?>
                  </select>
                  <p class="description">All opening hours, date validation, and order timing use this timezone.</p>
              </td>
          </tr>

        </table>

        <hr>
        <h2>Reservations</h2>

        <table class="form-table">
            <tr>
                <th scope="row">Require Deposit</th>
                <td>
                    <label>
                        <input type="checkbox" name="dd_reservation_deposit_enabled" value="1"
                            <?php checked( get_option( 'dd_reservation_deposit_enabled', 0 ), 1 ); ?>>
                        Customers must pay a deposit to confirm their booking
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">Deposit Type</th>
                <td>
                    <select name="dd_reservation_deposit_type">
                        <option value="fixed" <?php selected( get_option( 'dd_reservation_deposit_type', 'fixed' ), 'fixed' ); ?>>Fixed amount (RWF)</option>
                        <option value="percent" <?php selected( get_option( 'dd_reservation_deposit_type', 'fixed' ), 'percent' ); ?>>Percentage of estimated order</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">Deposit Amount</th>
                <td>
                    <input type="number" name="dd_reservation_deposit_amount"
                        value="<?php echo esc_attr( get_option( 'dd_reservation_deposit_amount', 2000 ) ); ?>"
                        min="0" step="100" class="regular-text">
                    <p class="description">Enter RWF amount (for fixed) or percentage (for percent type).</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Auto-Cancel After</th>
                <td>
                    <input type="number" name="dd_reservation_autocancel_hours"
                        value="<?php echo esc_attr( get_option( 'dd_reservation_autocancel_hours', 2 ) ); ?>"
                        min="1" max="72" class="small-text"> hours
                    <p class="description">Unpaid deposit bookings are automatically cancelled after this many hours.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Allow Refunds</th>
                <td>
                    <label>
                        <input type="checkbox" name="dd_reservation_refund_enabled" value="1"
                            <?php checked( get_option( 'dd_reservation_refund_enabled', 0 ), 1 ); ?>>
                        Allow deposit refunds when customer cancels in time
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">Refund Window</th>
                <td>
                    <input type="number" name="dd_reservation_refund_hours"
                        value="<?php echo esc_attr( get_option( 'dd_reservation_refund_hours', 24 ) ); ?>"
                        min="1" class="small-text"> hours before reservation
                    <p class="description">Customer must cancel at least this many hours before the booking to receive a refund.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Refund Policy</th>
                <td>
                    <textarea name="dd_reservation_refund_policy_text" rows="3" class="large-text"><?php
                        echo esc_textarea( get_option( 'dd_reservation_refund_policy_text', '' ) );
                    ?></textarea>
                    <p class="description">Shown to customers on the booking review screen.</p>
                </td>
            </tr>
        </table>

        <?php if ( current_user_can( 'manage_options' ) ) : ?>
        <hr>
        <h2>🔐 Security</h2>

        <table class="form-table">
            <tr>
                <th scope="row">Custom Admin Path</th>
                <td>
                    <input type="text" name="dd_admin_custom_path"
                           value="<?php echo esc_attr( get_option( 'dd_admin_custom_path', '' ) ); ?>"
                           class="regular-text"
                           placeholder="e.g. my-restaurant-admin">
                    <p class="description" style="color:#888;">
                        Once set, /wp-admin will return 404 for non-admin users.
                        Use only letters, numbers, and hyphens. Leave blank to disable.
                    </p>
                </td>
            </tr>
        </table>
        <?php endif; ?>

        <!-- Hidden field: assembled JSON sent on submit -->
        <input type="hidden" name="dd_opening_hours" id="dd_opening_hours_json" value="">

        <?php submit_button( __( 'Save Settings', 'dish-dash' ), 'primary', 'dd_save_settings' ); ?>
    </form>

    <?php if ( current_user_can( 'manage_options' ) ) : ?>
    <form method="post" action="" style="margin-top:12px;">
        <?php wp_nonce_field( 'dd_settings_save' ); ?>
        <button type="submit" name="dd_send_recovery_email" class="button button-secondary">
            📧 Send recovery email
        </button>
        <span class="description" style="margin-left:8px;">
            Sends your custom admin URL to the admin email address.
        </span>
    </form>
    <?php endif; ?>

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
