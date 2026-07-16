<?php
/**
 * File:    admin/pages/template-settings.php
 * Purpose: Template picker (display only) + Homepage Content form.
 *          All brand fields (name, logo, colors, contact, social) have moved
 *          to admin/pages/brand-identity.php.
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core guard)
 *   - WordPress update_option(), check_admin_referer(), sanitize_text_field()
 *   - wp.media JS (wp_enqueue_media() called at bottom)
 *
 * Dependents (files that need this):
 *   - modules/template/class-dd-template-module.php (loaded via a render method)
 *
 * WP options written (3 keys only):
 *   dish_dash_hero_title, dish_dash_hero_subtitle, dish_dash_hero_image
 *
 * Nonce action: dd_template_settings_save
 *
 * Last modified: v3.4.28
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Save handler — 3 keys only ────────────────────────────────────────────
// Opening hours removed in v3.10.67: it is owned exclusively by the Homepage
// module now (dish_dash_opening_hours). This page must not write that key —
// its hardcoded default used to clobber the Homepage value on every save.
if ( isset( $_POST['dd_save_template_settings'] ) && check_admin_referer( 'dd_template_settings_save' ) ) {
    $fields = [
        'dish_dash_hero_title',
        'dish_dash_hero_subtitle',
        'dish_dash_hero_image',
    ];
    foreach ( $fields as $field ) {
        if ( isset( $_POST[ $field ] ) ) {
            update_option( $field, sanitize_text_field( $_POST[ $field ] ) );
        }
    }
    echo '<div class="notice notice-success is-dismissible"><p>'
        . esc_html__( 'Homepage content saved!', 'dish-dash' )
        . '</p></div>';
}

// ── Current values ─────────────────────────────────────────────────────────
$primary       = esc_attr( get_option( 'dish_dash_primary_color', '#65040d' ) );
$hero_title    = get_option( 'dish_dash_hero_title',    'Hello Dear,' );
$hero_subtitle = get_option( 'dish_dash_hero_subtitle', "Hungry? You're in the right place..." );
$hero_image    = get_option( 'dish_dash_hero_image',    '' );
?>
<div class="wrap dd-admin-wrap">

    <div class="dd-admin-header">
        <div class="dd-admin-header__logo">
            <span class="dd-logo-icon">🖼</span>
            <div>
                <h1><?php esc_html_e( 'Template', 'dish-dash' ); ?></h1>
                <span class="dd-version"><?php esc_html_e( 'Choose your restaurant template', 'dish-dash' ); ?></span>
            </div>
        </div>
        <div class="dd-admin-header__actions">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" class="button">
                <?php esc_html_e( 'Preview Site', 'dish-dash' ); ?> ↗
            </a>
        </div>
    </div>

    <!-- ══════════════════════════════════════════ -->
    <!-- SECTION 1 — Template Cards (display only)  -->
    <!-- ══════════════════════════════════════════ -->
    <div class="dd-hp-section" style="margin-bottom:24px;">
        <div class="dd-hp-section__header">
            <div class="dd-hp-section__icon">🎨</div>
            <h2><?php esc_html_e( 'Choose a Template', 'dish-dash' ); ?></h2>
        </div>
        <div class="dd-hp-section__body">

            <div style="display:flex;gap:20px;flex-wrap:wrap;">

                <!-- Card 1 — Active: Khana Khazana -->
                <div style="flex:1;min-width:200px;border:2px solid <?php echo $primary; ?>;border-radius:12px;overflow:hidden;background:#fff;">
                    <!-- Thumbnail -->
                    <div style="height:120px;background:#F5EFE6;position:relative;overflow:hidden;">
                        <div style="height:26px;background:<?php echo $primary; ?>;"></div>
                        <div style="position:absolute;bottom:16px;left:16px;width:56px;height:10px;background:#E8832A;border-radius:4px;"></div>
                        <div style="position:absolute;bottom:16px;left:80px;width:36px;height:10px;background:rgba(0,0,0,0.10);border-radius:4px;"></div>
                    </div>
                    <!-- Footer -->
                    <div style="padding:14px 16px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                            <strong style="font-size:14px;color:#111;">Khana Khazana</strong>
                            <span style="background:<?php echo $primary; ?>;color:#fff;font-size:11px;font-weight:600;padding:3px 10px;border-radius:999px;white-space:nowrap;">
                                ✓ <?php esc_html_e( 'Active', 'dish-dash' ); ?>
                            </span>
                        </div>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=dish-dash-brand-identity' ) ); ?>"
                           style="display:block;text-align:center;background:<?php echo $primary; ?>;color:#fff;padding:9px 16px;border-radius:8px;font-weight:600;font-size:13px;text-decoration:none;transition:opacity 0.2s;"
                           onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                            <?php esc_html_e( 'Customize', 'dish-dash' ); ?> →
                        </a>
                    </div>
                </div>

                <!-- Card 2 — Coming Soon: Modern Dark -->
                <div style="flex:1;min-width:200px;border:2px solid #e0e0e0;border-radius:12px;overflow:hidden;background:#fff;opacity:0.5;cursor:not-allowed;">
                    <div style="height:120px;background:#f0f0f0;position:relative;overflow:hidden;">
                        <div style="height:26px;background:#333;"></div>
                        <div style="position:absolute;bottom:16px;left:16px;width:56px;height:10px;background:#666;border-radius:4px;"></div>
                    </div>
                    <div style="padding:14px 16px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <strong style="font-size:14px;color:#111;"><?php esc_html_e( 'Modern Dark', 'dish-dash' ); ?></strong>
                            <span style="background:#f0f0f0;color:#888;font-size:11px;font-weight:600;padding:3px 10px;border-radius:999px;white-space:nowrap;">
                                <?php esc_html_e( 'Coming Soon', 'dish-dash' ); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Card 3 — Coming Soon: Minimal Light -->
                <div style="flex:1;min-width:200px;border:2px solid #e0e0e0;border-radius:12px;overflow:hidden;background:#fff;opacity:0.5;cursor:not-allowed;">
                    <div style="height:120px;background:#f8f8f8;position:relative;overflow:hidden;">
                        <div style="height:26px;background:#bbb;"></div>
                        <div style="position:absolute;bottom:16px;left:16px;width:56px;height:10px;background:#ccc;border-radius:4px;"></div>
                    </div>
                    <div style="padding:14px 16px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <strong style="font-size:14px;color:#111;"><?php esc_html_e( 'Minimal Light', 'dish-dash' ); ?></strong>
                            <span style="background:#f0f0f0;color:#888;font-size:11px;font-weight:600;padding:3px 10px;border-radius:999px;white-space:nowrap;">
                                <?php esc_html_e( 'Coming Soon', 'dish-dash' ); ?>
                            </span>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════ -->
    <!-- SECTION 2 — Homepage Content (editable)    -->
    <!-- ══════════════════════════════════════════ -->
    <div class="dd-hp-section">
        <div class="dd-hp-section__header">
            <div class="dd-hp-section__icon">🏠</div>
            <h2><?php esc_html_e( 'Homepage Content', 'dish-dash' ); ?></h2>
        </div>
        <div class="dd-hp-section__body">

            <form method="post">
                <?php wp_nonce_field( 'dd_template_settings_save' ); ?>

                <div class="dd-hp-grid-2" style="margin-bottom:16px;">

                    <div class="dd-hp-field">
                        <label><?php esc_html_e( 'Hero Title', 'dish-dash' ); ?></label>
                        <input type="text" class="dd-hp-input" name="dish_dash_hero_title"
                               value="<?php echo esc_attr( $hero_title ); ?>"
                               placeholder="Hello Dear," />
                    </div>

                    <div class="dd-hp-field">
                        <label><?php esc_html_e( 'Hero Subtitle', 'dish-dash' ); ?></label>
                        <input type="text" class="dd-hp-input" name="dish_dash_hero_subtitle"
                               value="<?php echo esc_attr( $hero_subtitle ); ?>"
                               placeholder="<?php esc_attr_e( "Hungry? You're in the right place...", 'dish-dash' ); ?>" />
                    </div>

                </div>

                <div class="dd-hp-field" style="margin-bottom:16px;">
                    <label><?php esc_html_e( 'Hero Banner Image', 'dish-dash' ); ?></label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text" class="dd-hp-input" name="dish_dash_hero_image" id="dd_hero_image"
                               value="<?php echo esc_attr( $hero_image ); ?>"
                               placeholder="https://..." style="flex:1;" />
                        <button type="button" class="button" onclick="ddMediaUploadHero()">
                            📤 <?php esc_html_e( 'Upload', 'dish-dash' ); ?>
                        </button>
                    </div>
                    <?php if ( $hero_image ) : ?>
                    <img src="<?php echo esc_url( $hero_image ); ?>"
                         style="max-height:80px;margin-top:8px;border-radius:8px;object-fit:cover;" />
                    <?php endif; ?>
                </div>

                <div class="dd-hp-save-bar">
                    <span><?php esc_html_e( 'Changes apply immediately after saving.', 'dish-dash' ); ?></span>
                    <button type="submit" name="dd_save_template_settings" class="button button-primary">
                        <?php esc_html_e( 'Save Homepage Content', 'dish-dash' ); ?>
                    </button>
                </div>

            </form>

        </div>
    </div>

</div>

<script>
function ddMediaUploadHero() {
    var frame = wp.media({
        title: '<?php echo esc_js( __( 'Select Hero Image', 'dish-dash' ) ); ?>',
        button: { text: '<?php echo esc_js( __( 'Use this image', 'dish-dash' ) ); ?>' },
        multiple: false,
        library: { type: 'image' }
    });
    frame.on('select', function() {
        var attachment = frame.state().get('selection').first().toJSON();
        document.getElementById('dd_hero_image').value = attachment.url;
    });
    frame.open();
}
</script>

<?php wp_enqueue_media(); ?>
