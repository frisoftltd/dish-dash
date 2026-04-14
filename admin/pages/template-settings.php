<?php
/**
 * File:    admin/pages/template-settings.php
 * Purpose: Renders and saves the Template / Branding settings page —
 *          restaurant name, logo, brand colors, hero content, address,
 *          phone, opening hours, and social media links.
 *
 * Dependencies (this file needs):
 *   - ABSPATH (WordPress core guard)
 *   - WordPress update_option(), check_admin_referer(), sanitize_text_field()
 *
 * Dependents (files that need this):
 *   - modules/template/class-dd-template-module.php (loaded via a render method)
 *
 * WP options written:
 *   dish_dash_restaurant_name, dish_dash_logo_url,
 *   dish_dash_primary_color, dish_dash_dark_color,
 *   dish_dash_hero_title, dish_dash_hero_subtitle, dish_dash_hero_image,
 *   dish_dash_address, dish_dash_phone, dish_dash_contact_email,
 *   dish_dash_opening_hours, dish_dash_facebook, dish_dash_instagram,
 *   dish_dash_whatsapp, dish_dash_twitter, dish_dash_tiktok
 *
 * Nonce action: dd_template_settings_save
 *
 * Last modified: v3.1.13
 */
?>

<?php
/**
 * Admin Page: Template Settings
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Save settings
if ( isset( $_POST['dd_save_template_settings'] ) && check_admin_referer( 'dd_template_settings_save' ) ) {
    $fields = [
        'dish_dash_restaurant_name',
        'dish_dash_logo_url',
        'dish_dash_primary_color',
        'dish_dash_dark_color',
        'dish_dash_hero_title',
        'dish_dash_hero_subtitle',
        'dish_dash_hero_image',
        'dish_dash_address',
        'dish_dash_phone',
        'dish_dash_contact_email',
        'dish_dash_opening_hours',
        'dish_dash_facebook',
        'dish_dash_instagram',
        'dish_dash_whatsapp',
        'dish_dash_twitter',
        'dish_dash_tiktok',
    ];
    foreach ( $fields as $field ) {
        if ( isset( $_POST[ $field ] ) ) {
            update_option( $field, sanitize_text_field( $_POST[ $field ] ) );
        }
    }
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Template settings saved!', 'dish-dash' ) . '</p></div>';
}

// Handle logo upload
if ( isset( $_POST['dd_upload_logo'] ) && check_admin_referer( 'dd_template_settings_save' ) ) {
    if ( ! empty( $_FILES['dd_logo_file']['name'] ) ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $attachment_id = media_handle_upload( 'dd_logo_file', 0 );
        if ( ! is_wp_error( $attachment_id ) ) {
            update_option( 'dish_dash_logo_url', wp_get_attachment_url( $attachment_id ) );
        }
    }
}

// Current values
$restaurant_name = get_option( 'dish_dash_restaurant_name', get_bloginfo( 'name' ) );
$logo_url        = get_option( 'dish_dash_logo_url', '' );
$primary_color   = get_option( 'dish_dash_primary_color', '#E8832A' );
$dark_color      = get_option( 'dish_dash_dark_color', '#1E3A5F' );
$hero_title      = get_option( 'dish_dash_hero_title', 'Hello Dear,' );
$hero_subtitle   = get_option( 'dish_dash_hero_subtitle', "Hungry? You're in the right place..." );
$hero_image      = get_option( 'dish_dash_hero_image', '' );
$address         = get_option( 'dish_dash_address', '' );
$phone           = get_option( 'dish_dash_phone', '' );
$contact_email   = get_option( 'dish_dash_contact_email', get_option( 'admin_email' ) );
$opening_hours   = get_option( 'dish_dash_opening_hours', 'Monday – Friday 10 AM – 7 PM' );
$facebook        = get_option( 'dish_dash_facebook', '' );
$instagram       = get_option( 'dish_dash_instagram', '' );
$whatsapp        = get_option( 'dish_dash_whatsapp', '' );
$twitter         = get_option( 'dish_dash_twitter', '' );
$tiktok          = get_option( 'dish_dash_tiktok', '' );
?>
<div class="wrap dd-admin-wrap">

    <div class="dd-admin-header">
        <div class="dd-admin-header__logo">
            <span class="dd-logo-icon">🎨</span>
            <div>
                <h1><?php esc_html_e( 'Template Settings', 'dish-dash' ); ?></h1>
                <span class="dd-version"><?php esc_html_e( 'Customize your restaurant branding', 'dish-dash' ); ?></span>
            </div>
        </div>
        <div class="dd-admin-header__actions">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" class="button">
                <?php esc_html_e( 'Preview Site', 'dish-dash' ); ?> ↗
            </a>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field( 'dd_template_settings_save' ); ?>

        <div class="dd-settings-grid">

            <!-- ── BRANDING ── -->
            <div class="dd-settings-card">
                <h2 class="dd-settings-card__title">🏪 <?php esc_html_e( 'Branding', 'dish-dash' ); ?></h2>

                <div class="dd-field">
                    <label><?php esc_html_e( 'Restaurant Name', 'dish-dash' ); ?></label>
                    <input type="text" name="dish_dash_restaurant_name"
                           value="<?php echo esc_attr( $restaurant_name ); ?>"
                           placeholder="e.g. Khana Khazana" />
                </div>

                <div class="dd-field">
                    <label><?php esc_html_e( 'Logo URL', 'dish-dash' ); ?></label>
                    <div class="dd-field-row">
                        <input type="url" name="dish_dash_logo_url" id="dd_logo_url"
                               value="<?php echo esc_attr( $logo_url ); ?>"
                               placeholder="https://..." />
                        <button type="button" class="button" onclick="ddMediaUpload()">
                            <?php esc_html_e( 'Upload', 'dish-dash' ); ?>
                        </button>
                    </div>
                    <?php if ( $logo_url ) : ?>
                    <img src="<?php echo esc_url( $logo_url ); ?>" style="max-height:60px;margin-top:8px;border-radius:6px;" />
                    <?php endif; ?>
                </div>

                <div class="dd-field-row">
                    <div class="dd-field">
                        <label><?php esc_html_e( 'Primary Color', 'dish-dash' ); ?></label>
                        <div class="dd-color-field">
                            <input type="color" name="dish_dash_primary_color" value="<?php echo esc_attr( $primary_color ); ?>" />
                            <input type="text" value="<?php echo esc_attr( $primary_color ); ?>"
                                   oninput="this.previousElementSibling.value=this.value"
                                   placeholder="#E8832A" />
                        </div>
                    </div>
                    <div class="dd-field">
                        <label><?php esc_html_e( 'Dark Color', 'dish-dash' ); ?></label>
                        <div class="dd-color-field">
                            <input type="color" name="dish_dash_dark_color" value="<?php echo esc_attr( $dark_color ); ?>" />
                            <input type="text" value="<?php echo esc_attr( $dark_color ); ?>"
                                   oninput="this.previousElementSibling.value=this.value"
                                   placeholder="#1E3A5F" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── HERO SECTION ── -->
            <div class="dd-settings-card">
                <h2 class="dd-settings-card__title">🦸 <?php esc_html_e( 'Hero Section', 'dish-dash' ); ?></h2>

                <div class="dd-field">
                    <label><?php esc_html_e( 'Hero Title', 'dish-dash' ); ?></label>
                    <input type="text" name="dish_dash_hero_title"
                           value="<?php echo esc_attr( $hero_title ); ?>"
                           placeholder="Hello Dear," />
                </div>

                <div class="dd-field">
                    <label><?php esc_html_e( 'Hero Subtitle', 'dish-dash' ); ?></label>
                    <input type="text" name="dish_dash_hero_subtitle"
                           value="<?php echo esc_attr( $hero_subtitle ); ?>"
                           placeholder="Hungry? You're in the right place..." />
                </div>

                <div class="dd-field">
                    <label><?php esc_html_e( 'Hero Banner Image URL', 'dish-dash' ); ?></label>
                    <div class="dd-field-row">
                        <input type="url" name="dish_dash_hero_image" id="dd_hero_image"
                               value="<?php echo esc_attr( $hero_image ); ?>"
                               placeholder="https://..." />
                        <button type="button" class="button" onclick="ddMediaUploadHero()">
                            <?php esc_html_e( 'Upload', 'dish-dash' ); ?>
                        </button>
                    </div>
                    <?php if ( $hero_image ) : ?>
                    <img src="<?php echo esc_url( $hero_image ); ?>"
                         style="max-height:80px;margin-top:8px;border-radius:8px;object-fit:cover;" />
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── CONTACT INFO ── -->
            <div class="dd-settings-card">
                <h2 class="dd-settings-card__title">📍 <?php esc_html_e( 'Contact Information', 'dish-dash' ); ?></h2>

                <div class="dd-field">
                    <label><?php esc_html_e( 'Address', 'dish-dash' ); ?></label>
                    <input type="text" name="dish_dash_address"
                           value="<?php echo esc_attr( $address ); ?>"
                           placeholder="123 Main Street, City" />
                </div>

                <div class="dd-field-row">
                    <div class="dd-field">
                        <label><?php esc_html_e( 'Phone', 'dish-dash' ); ?></label>
                        <input type="text" name="dish_dash_phone"
                               value="<?php echo esc_attr( $phone ); ?>"
                               placeholder="+1 234 567 890" />
                    </div>
                    <div class="dd-field">
                        <label><?php esc_html_e( 'Email', 'dish-dash' ); ?></label>
                        <input type="email" name="dish_dash_contact_email"
                               value="<?php echo esc_attr( $contact_email ); ?>" />
                    </div>
                </div>

                <div class="dd-field">
                    <label><?php esc_html_e( 'Opening Hours', 'dish-dash' ); ?></label>
                    <input type="text" name="dish_dash_opening_hours"
                           value="<?php echo esc_attr( $opening_hours ); ?>"
                           placeholder="Monday – Friday 10 AM – 7 PM" />
                </div>
            </div>

            <!-- ── SOCIAL MEDIA ── -->
            <div class="dd-settings-card">
                <h2 class="dd-settings-card__title">📱 <?php esc_html_e( 'Social Media', 'dish-dash' ); ?></h2>

                <div class="dd-field">
                    <label>📘 <?php esc_html_e( 'Facebook URL', 'dish-dash' ); ?></label>
                    <input type="url" name="dish_dash_facebook"
                           value="<?php echo esc_attr( $facebook ); ?>"
                           placeholder="https://facebook.com/yourpage" />
                </div>
                <div class="dd-field">
                    <label>📷 <?php esc_html_e( 'Instagram URL', 'dish-dash' ); ?></label>
                    <input type="url" name="dish_dash_instagram"
                           value="<?php echo esc_attr( $instagram ); ?>"
                           placeholder="https://instagram.com/yourpage" />
                </div>
                <div class="dd-field">
                    <label>💬 <?php esc_html_e( 'WhatsApp Number', 'dish-dash' ); ?></label>
                    <input type="text" name="dish_dash_whatsapp"
                           value="<?php echo esc_attr( $whatsapp ); ?>"
                           placeholder="250788123456 (no + or spaces)" />
                </div>
                <div class="dd-field">
                    <label>🐦 <?php esc_html_e( 'Twitter / X URL', 'dish-dash' ); ?></label>
                    <input type="url" name="dish_dash_twitter"
                           value="<?php echo esc_attr( $twitter ); ?>"
                           placeholder="https://x.com/yourpage" />
                </div>
                <div class="dd-field">
                    <label>🎵 <?php esc_html_e( 'TikTok URL', 'dish-dash' ); ?></label>
                    <input type="url" name="dish_dash_tiktok"
                           value="<?php echo esc_attr( $tiktok ); ?>"
                           placeholder="https://tiktok.com/@yourpage" />
                </div>
            </div>

        </div>

        <div style="margin-top:1.5rem">
            <?php submit_button( __( 'Save Template Settings', 'dish-dash' ), 'primary large', 'dd_save_template_settings' ); ?>
        </div>

    </form>
</div>

<style>
.dd-settings-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(440px,1fr)); gap:1.5rem; margin-top:1rem; }
.dd-settings-card { background:#fff; border:1px solid #e0e0e0; border-radius:12px; padding:1.5rem; box-shadow:0 2px 8px rgba(0,0,0,.05); }
.dd-settings-card__title { font-size:1rem; font-weight:700; color:#1E3A5F; margin:0 0 1.25rem; padding-bottom:.75rem; border-bottom:2px solid #f0ece6; }
.dd-field { margin-bottom:1rem; }
.dd-field label { display:block; font-size:.82rem; font-weight:700; color:#555; margin-bottom:.4rem; text-transform:uppercase; letter-spacing:.04em; }
.dd-field input[type="text"],
.dd-field input[type="url"],
.dd-field input[type="email"] { width:100%; padding:.6rem .85rem; border:2px solid #e8e0d5; border-radius:8px; font-size:.9rem; font-family:inherit; transition:border-color .2s; outline:none; }
.dd-field input:focus { border-color:#E8832A; }
.dd-field-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.dd-field-row .dd-field { margin-bottom:0; }
.dd-field-row.dd-field-row--btn { display:flex; gap:.5rem; align-items:flex-end; }
.dd-field-row.dd-field-row--btn input { flex:1; }
.dd-color-field { display:flex; gap:.5rem; align-items:center; }
.dd-color-field input[type="color"] { width:44px; height:38px; padding:2px; border:2px solid #e8e0d5; border-radius:8px; cursor:pointer; }
.dd-color-field input[type="text"] { width:100px !important; }
</style>

<script>
// WordPress Media Uploader for Logo
function ddMediaUpload() {
    var frame = wp.media({
        title: 'Select Logo',
        button: { text: 'Use this image' },
        multiple: false,
        library: { type: 'image' }
    });
    frame.on('select', function() {
        var attachment = frame.state().get('selection').first().toJSON();
        document.getElementById('dd_logo_url').value = attachment.url;
    });
    frame.open();
}

// WordPress Media Uploader for Hero Image
function ddMediaUploadHero() {
    var frame = wp.media({
        title: 'Select Hero Image',
        button: { text: 'Use this image' },
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
