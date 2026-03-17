<?php
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
    echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'dish-dash' ) . '</p></div>';
}
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
        </table>

        <?php submit_button( __( 'Save Settings', 'dish-dash' ), 'primary', 'dd_save_settings' ); ?>
    </form>
</div>
