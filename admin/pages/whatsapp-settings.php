<?php
/**
 * File:    admin/pages/whatsapp-settings.php
 * Purpose: Renders and saves per-restaurant WhatsApp Cloud API credentials
 *          (Phase 9A Step 1) — Phone Number ID, WhatsApp Business Account
 *          ID, permanent token, webhook verify token, app secret — plus a
 *          test-send panel that sends one approved template to a number
 *          entered on this page.
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core guard)
 *   - WordPress update_option(), check_admin_referer(), sanitize_text_field()
 *
 * Dependents (files that need this):
 *   - modules/whatsapp/class-dd-whatsapp-module.php (loaded via render_page())
 *
 * WP options written:
 *   dd_whatsapp_phone_number_id, dd_whatsapp_waba_id, dd_whatsapp_token,
 *   dd_whatsapp_verify_token, dd_whatsapp_app_secret
 *
 * Nonce action: dd_whatsapp_settings_save
 *
 * Secret fields never echo their stored value back into the page (unlike
 * settings.php's Claude API key field) — they render blank with a
 * "saved — leave blank to keep it" hint instead. A blank submit leaves the
 * stored value untouched. Deliberately stricter than the Claude-key
 * precedent: this token/app-secret pair can send messages and validate the
 * webhook, a higher blast radius than the Claude key.
 *
 * Last modified: v3.18.48
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Save handler ────────────────────────────────────────────────────────────
if ( isset( $_POST['dd_save_whatsapp_settings'] ) && check_admin_referer( 'dd_whatsapp_settings_save' ) ) {
    update_option( 'dd_whatsapp_phone_number_id', sanitize_text_field( wp_unslash( $_POST['dd_whatsapp_phone_number_id'] ?? '' ) ) );
    update_option( 'dd_whatsapp_waba_id',         sanitize_text_field( wp_unslash( $_POST['dd_whatsapp_waba_id']         ?? '' ) ) );

    // Secrets — only overwrite when the admin actually typed a new value;
    // the fields always render blank (see docblock), so a blank submit here
    // means "leave unchanged," not "clear it."
    foreach ( [ 'dd_whatsapp_token', 'dd_whatsapp_verify_token', 'dd_whatsapp_app_secret' ] as $secret_key ) {
        $val = sanitize_text_field( wp_unslash( $_POST[ $secret_key ] ?? '' ) );
        if ( '' !== $val ) {
            update_option( $secret_key, $val );
        }
    }

    echo '<div class="notice notice-success is-dismissible"><p>'
        . esc_html__( 'WhatsApp settings saved!', 'dish-dash' )
        . '</p></div>';
}

$phone_number_id  = get_option( 'dd_whatsapp_phone_number_id', '' );
$waba_id          = get_option( 'dd_whatsapp_waba_id', '' );
$has_token        = '' !== get_option( 'dd_whatsapp_token', '' );
$has_verify_token = '' !== get_option( 'dd_whatsapp_verify_token', '' );
$has_app_secret   = '' !== get_option( 'dd_whatsapp_app_secret', '' );
?>
<div class="wrap dd-admin-wrap dd-settings-page">

<style>
/* Same selector namespace as admin/pages/settings.php — page-local rules,
   not in admin.css, so duplicated here rather than shared (that file's
   .dd-settings-card/.dd-field-grid block is itself page-scoped, not a
   global convention to extend). */
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
</style>

    <h1><?php esc_html_e( 'WhatsApp', 'dish-dash' ); ?></h1>

    <form method="post" action="">
        <?php wp_nonce_field( 'dd_whatsapp_settings_save' ); ?>

        <div class="dd-settings-card">
            <h2 class="dd-section-heading">🔑 <?php esc_html_e( 'Credentials', 'dish-dash' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'From Meta Business Settings → System Users, and the WhatsApp app\'s API Setup page. Each restaurant uses its own Meta app, number and token — nothing here is shared between installs.', 'dish-dash' ); ?>
            </p>

            <div class="dd-field-grid">
                <div class="dd-field-label"><?php esc_html_e( 'Phone Number ID', 'dish-dash' ); ?></div>
                <div class="dd-field-control">
                    <input type="text" name="dd_whatsapp_phone_number_id"
                           value="<?php echo esc_attr( $phone_number_id ); ?>"
                           class="dd-input" autocomplete="off" />
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label"><?php esc_html_e( 'WhatsApp Business Account ID', 'dish-dash' ); ?></div>
                <div class="dd-field-control">
                    <input type="text" name="dd_whatsapp_waba_id"
                           value="<?php echo esc_attr( $waba_id ); ?>"
                           class="dd-input" autocomplete="off" />
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label"><?php esc_html_e( 'Permanent Token', 'dish-dash' ); ?>
                    <span class="dd-label-hint">
                        <?php echo $has_token
                            ? esc_html__( 'A token is saved — leave blank to keep it.', 'dish-dash' )
                            : esc_html__( 'Not set.', 'dish-dash' ); ?>
                    </span>
                </div>
                <div class="dd-field-control">
                    <input type="password" name="dd_whatsapp_token"
                           value="" placeholder="<?php echo $has_token ? esc_attr( '••••••••••••' ) : ''; ?>"
                           class="dd-input" autocomplete="new-password" />
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label"><?php esc_html_e( 'Webhook Verify Token', 'dish-dash' ); ?>
                    <span class="dd-label-hint">
                        <?php echo $has_verify_token
                            ? esc_html__( 'A verify token is saved — leave blank to keep it.', 'dish-dash' )
                            : esc_html__( 'Not set.', 'dish-dash' ); ?>
                    </span>
                </div>
                <div class="dd-field-control">
                    <input type="password" name="dd_whatsapp_verify_token"
                           value="" placeholder="<?php echo $has_verify_token ? esc_attr( '••••••••••••' ) : ''; ?>"
                           class="dd-input" autocomplete="new-password" />
                </div>
            </div>

            <div class="dd-field-grid">
                <div class="dd-field-label"><?php esc_html_e( 'App Secret', 'dish-dash' ); ?>
                    <span class="dd-label-hint">
                        <?php echo $has_app_secret
                            ? esc_html__( 'An app secret is saved — leave blank to keep it.', 'dish-dash' )
                            : esc_html__( 'Not set.', 'dish-dash' ); ?>
                    </span>
                </div>
                <div class="dd-field-control">
                    <input type="password" name="dd_whatsapp_app_secret"
                           value="" placeholder="<?php echo $has_app_secret ? esc_attr( '••••••••••••' ) : ''; ?>"
                           class="dd-input" autocomplete="new-password" />
                </div>
            </div>
        </div>

        <div class="dd-settings-footer">
            <input type="submit" name="dd_save_whatsapp_settings"
                   class="button button-primary"
                   value="<?php esc_attr_e( 'Save Settings', 'dish-dash' ); ?>">
        </div>
    </form>

    <div class="dd-settings-card" style="margin-top:24px;">
        <h2 class="dd-section-heading">🧪 <?php esc_html_e( 'Test Send', 'dish-dash' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Sends one approved template message through the credentials above. Use Meta\'s own hello_world template (language en_US) against the free test number while no custom template is approved yet.', 'dish-dash' ); ?>
        </p>

        <div class="dd-field-grid">
            <div class="dd-field-label"><?php esc_html_e( 'Recipient Number', 'dish-dash' ); ?></div>
            <div class="dd-field-control">
                <input type="text" id="dd-wa-test-to" placeholder="250788123456"
                       class="dd-input dd-input--medium" />
            </div>
        </div>

        <div class="dd-field-grid">
            <div class="dd-field-label"><?php esc_html_e( 'Template Name', 'dish-dash' ); ?></div>
            <div class="dd-field-control">
                <input type="text" id="dd-wa-test-template" value="hello_world"
                       class="dd-input dd-input--medium" />
            </div>
        </div>

        <div class="dd-field-grid">
            <div class="dd-field-label"><?php esc_html_e( 'Language Code', 'dish-dash' ); ?></div>
            <div class="dd-field-control">
                <input type="text" id="dd-wa-test-lang" value="en_US"
                       class="dd-input dd-input--short" />
            </div>
        </div>

        <div class="dd-field-grid">
            <div class="dd-field-label"></div>
            <div class="dd-field-control">
                <button type="button" id="dd-wa-test-send" class="button button-secondary">
                    <?php esc_html_e( 'Send Test Message', 'dish-dash' ); ?>
                </button>
                <span id="dd-wa-test-spinner" style="display:none;margin-left:8px;">⏳</span>
                <p id="dd-wa-test-result" style="margin-top:12px;"></p>
            </div>
        </div>
    </div>
</div>
