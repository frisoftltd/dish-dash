<?php
/**
 * File:    admin/pages/brand-identity.php
 * Purpose: Renders and saves the Brand Identity admin page — restaurant name,
 *          logo (WP media uploader), color pickers, font selector,
 *          contact info, and social media links.
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core guard)
 *   - WordPress update_option(), check_admin_referer(), sanitize_text_field()
 *   - wp.media JS object (wp_enqueue_media() called in enqueue_admin_assets())
 *
 * Dependents (files that need this):
 *   - admin/class-dd-admin.php (loaded via render_brand_identity())
 *
 * WP options written:
 *   dish_dash_restaurant_name, dish_dash_logo_url,
 *   dish_dash_primary_color, dish_dash_dark_color, dish_dash_background_color,
 *   dish_dash_font, dish_dash_address, dish_dash_phone, dish_dash_contact_email,
 *   dish_dash_facebook, dish_dash_instagram, dish_dash_whatsapp, dish_dash_tiktok
 *
 * Nonce action: dd_brand_identity_save
 *
 * Last modified: v3.4.28
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Save handler ──────────────────────────────────────────────────────────────
if ( isset( $_POST['dd_save_brand_identity'] ) && check_admin_referer( 'dd_brand_identity_save' ) ) {
    $fields = [
        'dish_dash_restaurant_name',
        'dish_dash_restaurant_tagline',
        'dish_dash_logo_url',
        'dish_dash_primary_color',
        'dish_dash_dark_color',
        'dish_dash_background_color',
        'dish_dash_font',
        'dish_dash_address',
        'dish_dash_phone',
        'dish_dash_contact_email',
        'dish_dash_facebook',
        'dish_dash_instagram',
        'dish_dash_whatsapp',
        'dish_dash_tiktok',
    ];
    foreach ( $fields as $field ) {
        if ( isset( $_POST[ $field ] ) ) {
            update_option( $field, sanitize_text_field( $_POST[ $field ] ) );
        }
    }

    // Footer attribution — whitelist, never pass raw input through.
    if ( isset( $_POST['dish_dash_footer_attribution'] ) ) {
        $attr = sanitize_text_field( wp_unslash( $_POST['dish_dash_footer_attribution'] ) );
        if ( ! in_array( $attr, array( 'frisoft', 'dishdash', 'none' ), true ) ) {
            $attr = 'frisoft';
        }
        update_option( 'dish_dash_footer_attribution', $attr );
    }

    echo '<div class="notice notice-success is-dismissible"><p>'
        . esc_html__( 'Brand identity saved!', 'dish-dash' )
        . '</p></div>';
}

// ── Current values ────────────────────────────────────────────────────────────
$restaurant_name    = get_option( 'dish_dash_restaurant_name', get_bloginfo( 'name' ) );
$restaurant_tagline = get_option( 'dish_dash_restaurant_tagline', '' );
$footer_attribution = get_option( 'dish_dash_footer_attribution', 'frisoft' );
$logo_url        = get_option( 'dish_dash_logo_url', '' );
$primary_color   = get_option( 'dish_dash_primary_color', '#65040d' );
$dark_color      = get_option( 'dish_dash_dark_color', '#000000' );
$bg_color        = get_option( 'dish_dash_background_color', '#F5EFE6' );
$font            = get_option( 'dish_dash_font', 'Inter' );
$address         = get_option( 'dish_dash_address', '' );
$phone           = get_option( 'dish_dash_phone', '' );
$contact_email   = get_option( 'dish_dash_contact_email', get_option( 'admin_email' ) );
$facebook        = get_option( 'dish_dash_facebook', '' );
$instagram       = get_option( 'dish_dash_instagram', '' );
$whatsapp        = get_option( 'dish_dash_whatsapp', '' );
$tiktok          = get_option( 'dish_dash_tiktok', '' );

$font_options = [ 'Inter', 'Poppins', 'Roboto', 'Lato', 'Montserrat' ];
?>
<div class="wrap dd-admin-wrap">

    <div class="dd-admin-header">
        <div class="dd-admin-header__logo">
            <span class="dd-logo-icon">🎨</span>
            <div>
                <h1><?php esc_html_e( 'Brand Identity', 'dish-dash' ); ?></h1>
                <span class="dd-version"><?php esc_html_e( 'Logo, colors, fonts & contact info', 'dish-dash' ); ?></span>
            </div>
        </div>
        <div class="dd-admin-header__actions">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" class="button">
                <?php esc_html_e( 'Preview Site', 'dish-dash' ); ?> ↗
            </a>
        </div>
    </div>

    <form method="post">
        <?php wp_nonce_field( 'dd_brand_identity_save' ); ?>

        <div class="dd-hp-sections">

            <!-- ── IDENTITY ───────────────────────────────────────── -->
            <div class="dd-hp-section">
                <div class="dd-hp-section__header">
                    <div class="dd-hp-section__icon">🏪</div>
                    <h2><?php esc_html_e( 'Restaurant Identity', 'dish-dash' ); ?></h2>
                </div>
                <div class="dd-hp-section__body">

                    <div class="dd-hp-field" style="margin-bottom:20px;">
                        <label><?php esc_html_e( 'Restaurant Name', 'dish-dash' ); ?></label>
                        <input type="text" class="dd-hp-input" name="dish_dash_restaurant_name"
                               value="<?php echo esc_attr( $restaurant_name ); ?>"
                               placeholder="e.g. Khana Khazana" />
                    </div>

                    <div class="dd-hp-field" style="margin-bottom:20px;">
                        <label><?php esc_html_e( 'Tagline', 'dish-dash' ); ?></label>
                        <input type="text" class="dd-hp-input" name="dish_dash_restaurant_tagline"
                               value="<?php echo esc_attr( $restaurant_tagline ); ?>"
                               placeholder="e.g. The Authentic Indian Restaurant" />
                        <p class="dd-hp-hint"><?php esc_html_e( 'Optional. Shown after the restaurant name in the footer copyright.', 'dish-dash' ); ?></p>
                    </div>

                    <div class="dd-hp-field" style="margin-bottom:20px;">
                        <label><?php esc_html_e( 'Footer Attribution', 'dish-dash' ); ?></label>
                        <select class="dd-hp-input" name="dish_dash_footer_attribution">
                            <option value="frisoft" <?php selected( $footer_attribution, 'frisoft' ); ?>><?php esc_html_e( 'Built by Fri Soft Ltd', 'dish-dash' ); ?></option>
                            <option value="dishdash" <?php selected( $footer_attribution, 'dishdash' ); ?>><?php esc_html_e( 'Powered by Dish Dash', 'dish-dash' ); ?></option>
                            <option value="none" <?php selected( $footer_attribution, 'none' ); ?>><?php esc_html_e( 'None', 'dish-dash' ); ?></option>
                        </select>
                        <p class="dd-hp-hint"><?php esc_html_e( 'Shown at the end of the footer copyright line.', 'dish-dash' ); ?></p>
                    </div>

                    <div class="dd-hp-field">
                        <label><?php esc_html_e( 'Logo', 'dish-dash' ); ?></label>
                        <div class="dd-bi-logo-row">
                            <?php if ( $logo_url ) : ?>
                            <div class="dd-bi-logo-preview">
                                <img id="dd-logo-preview-img" src="<?php echo esc_url( $logo_url ); ?>"
                                     alt="<?php esc_attr_e( 'Restaurant logo', 'dish-dash' ); ?>" />
                            </div>
                            <?php else : ?>
                            <div class="dd-bi-logo-preview dd-bi-logo-preview--empty" id="dd-logo-preview-empty">
                                <span>🖼</span>
                                <p><?php esc_html_e( 'No logo uploaded', 'dish-dash' ); ?></p>
                            </div>
                            <?php endif; ?>
                            <div class="dd-bi-logo-controls">
                                <input type="hidden" name="dish_dash_logo_url" id="dd_logo_url"
                                       value="<?php echo esc_attr( $logo_url ); ?>" />
                                <button type="button" class="dd-bi-btn-upload" id="dd-logo-upload-btn">
                                    📤 <?php esc_html_e( 'Upload Logo', 'dish-dash' ); ?>
                                </button>
                                <?php if ( $logo_url ) : ?>
                                <button type="button" class="dd-bi-btn-remove" id="dd-logo-remove-btn">
                                    <?php esc_html_e( 'Remove', 'dish-dash' ); ?>
                                </button>
                                <?php endif; ?>
                                <p class="dd-hp-hint"><?php esc_html_e( 'Recommended: PNG with transparent background, min 200×200px.', 'dish-dash' ); ?></p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ── COLORS ─────────────────────────────────────────── -->
            <div class="dd-hp-section">
                <div class="dd-hp-section__header">
                    <div class="dd-hp-section__icon">🎨</div>
                    <h2><?php esc_html_e( 'Brand Colors', 'dish-dash' ); ?></h2>
                </div>
                <div class="dd-hp-section__body">
                    <div class="dd-hp-grid-3">

                        <div class="dd-hp-field">
                            <label><?php esc_html_e( 'Primary Color', 'dish-dash' ); ?></label>
                            <div class="dd-bi-color-wrap">
                                <input type="color" class="dd-bi-color-swatch"
                                       id="dd_primary_color_picker"
                                       value="<?php echo esc_attr( $primary_color ); ?>"
                                       oninput="document.getElementById('dd_primary_color_text').value=this.value" />
                                <input type="text" class="dd-hp-input dd-bi-color-text"
                                       id="dd_primary_color_text"
                                       name="dish_dash_primary_color"
                                       value="<?php echo esc_attr( $primary_color ); ?>"
                                       placeholder="#65040d"
                                       oninput="document.getElementById('dd_primary_color_picker').value=this.value" />
                            </div>
                            <p class="dd-hp-hint"><?php esc_html_e( 'Header, buttons, active states.', 'dish-dash' ); ?></p>
                        </div>

                        <div class="dd-hp-field">
                            <label><?php esc_html_e( 'Dark Color', 'dish-dash' ); ?></label>
                            <div class="dd-bi-color-wrap">
                                <input type="color" class="dd-bi-color-swatch"
                                       id="dd_dark_color_picker"
                                       value="<?php echo esc_attr( $dark_color ); ?>"
                                       oninput="document.getElementById('dd_dark_color_text').value=this.value" />
                                <input type="text" class="dd-hp-input dd-bi-color-text"
                                       id="dd_dark_color_text"
                                       name="dish_dash_dark_color"
                                       value="<?php echo esc_attr( $dark_color ); ?>"
                                       placeholder="#000000"
                                       oninput="document.getElementById('dd_dark_color_picker').value=this.value" />
                            </div>
                            <p class="dd-hp-hint"><?php esc_html_e( 'Secondary elements, text accents.', 'dish-dash' ); ?></p>
                        </div>

                        <div class="dd-hp-field">
                            <label><?php esc_html_e( 'Background Color', 'dish-dash' ); ?></label>
                            <div class="dd-bi-color-wrap">
                                <input type="color" class="dd-bi-color-swatch"
                                       id="dd_bg_color_picker"
                                       value="<?php echo esc_attr( $bg_color ); ?>"
                                       oninput="document.getElementById('dd_bg_color_text').value=this.value" />
                                <input type="text" class="dd-hp-input dd-bi-color-text"
                                       id="dd_bg_color_text"
                                       name="dish_dash_background_color"
                                       value="<?php echo esc_attr( $bg_color ); ?>"
                                       placeholder="#F5EFE6"
                                       oninput="document.getElementById('dd_bg_color_picker').value=this.value" />
                            </div>
                            <p class="dd-hp-hint"><?php esc_html_e( 'Page background (warm cream default).', 'dish-dash' ); ?></p>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ── TYPOGRAPHY ─────────────────────────────────────── -->
            <div class="dd-hp-section">
                <div class="dd-hp-section__header">
                    <div class="dd-hp-section__icon">🔤</div>
                    <h2><?php esc_html_e( 'Typography', 'dish-dash' ); ?></h2>
                </div>
                <div class="dd-hp-section__body">
                    <div class="dd-hp-field" style="max-width:320px;">
                        <label><?php esc_html_e( 'Font Family', 'dish-dash' ); ?></label>
                        <select class="dd-hp-select" name="dish_dash_font" id="dd_font_select">
                            <?php foreach ( $font_options as $f ) : ?>
                            <option value="<?php echo esc_attr( $f ); ?>"
                                <?php selected( $font, $f ); ?>
                                style="font-family:'<?php echo esc_attr( $f ); ?>'">
                                <?php echo esc_html( $f ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="dd-hp-hint" style="margin-top:6px;">
                            <?php esc_html_e( 'Applied across the entire storefront. Inter is the default.', 'dish-dash' ); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- ── CONTACT INFO ───────────────────────────────────── -->
            <div class="dd-hp-section">
                <div class="dd-hp-section__header">
                    <div class="dd-hp-section__icon">📍</div>
                    <h2><?php esc_html_e( 'Contact Information', 'dish-dash' ); ?></h2>
                </div>
                <div class="dd-hp-section__body">

                    <div class="dd-hp-field" style="margin-bottom:16px;">
                        <label><?php esc_html_e( 'Address', 'dish-dash' ); ?></label>
                        <input type="text" class="dd-hp-input" name="dish_dash_address"
                               value="<?php echo esc_attr( $address ); ?>"
                               placeholder="<?php esc_attr_e( '123 Main Street, City', 'dish-dash' ); ?>" />
                    </div>

                    <div class="dd-hp-grid-2">
                        <div class="dd-hp-field">
                            <label><?php esc_html_e( 'Phone', 'dish-dash' ); ?></label>
                            <input type="text" class="dd-hp-input" name="dish_dash_phone"
                                   value="<?php echo esc_attr( $phone ); ?>"
                                   placeholder="+250 788 123 456" />
                        </div>
                        <div class="dd-hp-field">
                            <label><?php esc_html_e( 'Email', 'dish-dash' ); ?></label>
                            <input type="text" class="dd-hp-input" name="dish_dash_contact_email"
                                   value="<?php echo esc_attr( $contact_email ); ?>"
                                   placeholder="hello@yourrestaurant.com" />
                        </div>
                    </div>

                </div>
            </div>

            <!-- ── SOCIAL MEDIA ───────────────────────────────────── -->
            <div class="dd-hp-section">
                <div class="dd-hp-section__header">
                    <div class="dd-hp-section__icon">📱</div>
                    <h2><?php esc_html_e( 'Social Media', 'dish-dash' ); ?></h2>
                </div>
                <div class="dd-hp-section__body">
                    <div class="dd-hp-grid-2">

                        <div class="dd-hp-field">
                            <label>📘 <?php esc_html_e( 'Facebook', 'dish-dash' ); ?></label>
                            <input type="text" class="dd-hp-input" name="dish_dash_facebook"
                                   value="<?php echo esc_attr( $facebook ); ?>"
                                   placeholder="https://facebook.com/yourpage" />
                        </div>

                        <div class="dd-hp-field">
                            <label>📷 <?php esc_html_e( 'Instagram', 'dish-dash' ); ?></label>
                            <input type="text" class="dd-hp-input" name="dish_dash_instagram"
                                   value="<?php echo esc_attr( $instagram ); ?>"
                                   placeholder="https://instagram.com/yourpage" />
                        </div>

                        <div class="dd-hp-field">
                            <label>💬 <?php esc_html_e( 'WhatsApp', 'dish-dash' ); ?></label>
                            <input type="text" class="dd-hp-input" name="dish_dash_whatsapp"
                                   value="<?php echo esc_attr( $whatsapp ); ?>"
                                   placeholder="<?php esc_attr_e( '250788123456 (digits only)', 'dish-dash' ); ?>" />
                        </div>

                        <div class="dd-hp-field">
                            <label>🎵 <?php esc_html_e( 'TikTok', 'dish-dash' ); ?></label>
                            <input type="text" class="dd-hp-input" name="dish_dash_tiktok"
                                   value="<?php echo esc_attr( $tiktok ); ?>"
                                   placeholder="https://tiktok.com/@yourpage" />
                        </div>

                    </div>
                </div>
            </div>

        </div><!-- .dd-hp-sections -->

        <!-- ── SAVE BAR ───────────────────────────────────────────── -->
        <div class="dd-hp-save-bar">
            <span><?php esc_html_e( 'Changes apply immediately after saving.', 'dish-dash' ); ?></span>
            <button type="submit" name="dd_save_brand_identity" class="button button-primary">
                <?php esc_html_e( 'Save Brand Identity', 'dish-dash' ); ?>
            </button>
        </div>

    </form>

</div><!-- .dd-admin-wrap -->

<style>
.dd-bi-logo-row {
    display: flex;
    gap: 20px;
    align-items: flex-start;
}
.dd-bi-logo-preview {
    width: 120px;
    height: 120px;
    border-radius: 12px;
    border: 1.5px solid #e8e8e8;
    background: #fafafa;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
}
.dd-bi-logo-preview img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.dd-bi-logo-preview--empty {
    flex-direction: column;
    gap: 4px;
    color: #bbb;
    font-size: 11px;
    text-align: center;
}
.dd-bi-logo-preview--empty span { font-size: 28px; }
.dd-bi-logo-preview--empty p { margin: 0; }
.dd-bi-logo-controls {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding-top: 4px;
}
.dd-bi-btn-upload {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--dd-brand, #65040d) !important;
    color: #fff !important;
    border: none !important;
    border-radius: 8px !important;
    padding: 9px 16px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer;
    transition: opacity 0.2s !important;
    height: auto !important;
    line-height: 1.4 !important;
}
.dd-bi-btn-upload:hover { opacity: 0.88; }
.dd-bi-btn-remove {
    background: none !important;
    border: 1.5px solid #e0e0e0 !important;
    border-radius: 8px !important;
    color: #999 !important;
    font-size: 12px !important;
    padding: 6px 12px !important;
    cursor: pointer;
    height: auto !important;
    line-height: 1.4 !important;
    transition: border-color 0.2s, color 0.2s !important;
}
.dd-bi-btn-remove:hover {
    border-color: #c00 !important;
    color: #c00 !important;
}
.dd-bi-color-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
}
.dd-bi-color-swatch {
    width: 42px !important;
    height: 42px !important;
    padding: 3px !important;
    border: 1.5px solid #e8e8e8 !important;
    border-radius: 8px !important;
    cursor: pointer;
    flex-shrink: 0;
    background: none !important;
}
.dd-bi-color-text {
    flex: 1;
}
</style>

<script>
(function() {
    // Media uploader
    var uploadBtn  = document.getElementById('dd-logo-upload-btn');
    var removeBtn  = document.getElementById('dd-logo-remove-btn');
    var logoInput  = document.getElementById('dd_logo_url');

    if ( uploadBtn ) {
        uploadBtn.addEventListener('click', function() {
            var frame = wp.media({
                title: '<?php echo esc_js( __( 'Select Logo', 'dish-dash' ) ); ?>',
                button: { text: '<?php echo esc_js( __( 'Use this image', 'dish-dash' ) ); ?>'},
                multiple: false,
                library: { type: 'image' }
            });
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                logoInput.value = attachment.url;
                ddUpdateLogoPreview(attachment.url);
            });
            frame.open();
        });
    }

    if ( removeBtn ) {
        removeBtn.addEventListener('click', function() {
            logoInput.value = '';
            ddUpdateLogoPreview('');
        });
    }

    function ddUpdateLogoPreview(url) {
        var previewWrap = document.querySelector('.dd-bi-logo-preview');
        if ( ! previewWrap ) return;
        if ( url ) {
            previewWrap.classList.remove('dd-bi-logo-preview--empty');
            previewWrap.innerHTML = '<img id="dd-logo-preview-img" src="' + url + '" alt="" />';
            if ( removeBtn ) removeBtn.style.display = '';
        } else {
            previewWrap.classList.add('dd-bi-logo-preview--empty');
            previewWrap.innerHTML = '<span>🖼</span><p><?php echo esc_js( __( 'No logo uploaded', 'dish-dash' ) ); ?></p>';
            if ( removeBtn ) removeBtn.style.display = 'none';
        }
    }
}());
</script>
