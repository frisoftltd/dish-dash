<?php
/**
 * Dish Dash – Template Module
 *
 * Handles all frontend template settings:
 * branding, hero, contact, social media.
 * Also injects cart sidebar globally on frontend.
 * Completely independent module.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Template_Module extends DD_Module {

    protected string $id = 'template';

    public function init(): void {
        add_action( 'admin_menu',             [ $this, 'register_admin_page' ] );
        add_action( 'admin_init',             [ $this, 'save_settings' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_admin_assets' ] );
        add_filter( 'theme_page_templates',   [ $this, 'register_page_template' ] );
        add_filter( 'template_include',       [ $this, 'load_page_template' ] );
        add_action( 'after_setup_theme',      [ $this, 'register_nav_menus' ] );
        add_action( 'wp_enqueue_scripts',     [ $this, 'enqueue_frontend_assets' ] );

        // ── Remove ALL theme/plugin conflicts on our page ──
        add_action( 'wp_enqueue_scripts', [ $this, 'remove_theme_conflicts' ], 999 );

        // ── Inject cart sidebar on ALL frontend pages ──
        add_action( 'wp_footer', [ $this, 'inject_cart_sidebar' ] );

        // ── Inject global header on specific pages ──
        add_action( 'wp_body_open', [ $this, 'inject_global_header' ] );
        add_action( 'wp_head',      [ $this, 'inject_global_header_fallback' ] );
    }

    // ─────────────────────────────────────────
    //  INJECT CART SIDEBAR IN FOOTER
    //  Runs on every page so cart is always
    //  available regardless of theme or template
    // ─────────────────────────────────────────
    public function inject_cart_sidebar(): void {
        if ( is_admin() ) return;

        // Don't inject twice if full page template already has it
        if ( is_page() ) {
            $meta = get_post_meta( get_the_ID(), '_wp_page_template', true );
            if ( 'page-dishdash.php' === $meta ) return;
        }
        ?>
        <!-- Dish Dash Cart Sidebar -->
        <div class="dd-cart-overlay"></div>
        <aside class="dd-cart-sidebar" aria-label="<?php esc_attr_e( 'Shopping cart', 'dish-dash' ); ?>">
            <div class="dd-cart-sidebar__header">
                <h3>🛒 <?php esc_html_e( 'Your Cart', 'dish-dash' ); ?></h3>
                <button class="dd-cart-close" aria-label="<?php esc_attr_e( 'Close cart', 'dish-dash' ); ?>">✕</button>
            </div>
            <div class="dd-cart-items">
                <p class="dd-cart-empty"><?php esc_html_e( 'Your cart is empty.', 'dish-dash' ); ?></p>
            </div>
            <div class="dd-cart-summary" style="display:none">
                <div class="dd-cart-summary__row">
                    <span><?php esc_html_e( 'Subtotal', 'dish-dash' ); ?></span>
                    <span class="dd-cart-subtotal">—</span>
                </div>
                <?php if ( (float) DD_Settings::get( 'tax_rate', 0 ) > 0 ) : ?>
                <div class="dd-cart-summary__row">
                    <span><?php echo esc_html( DD_Settings::get( 'tax_label', 'Tax' ) ); ?></span>
                    <span class="dd-cart-tax">—</span>
                </div>
                <?php endif; ?>
                <div class="dd-cart-summary__row dd-cart-summary__row--total">
                    <span><?php esc_html_e( 'Total', 'dish-dash' ); ?></span>
                    <span class="dd-cart-total">—</span>
                </div>
                <button class="dd-cart-checkout-btn">
                    <?php esc_html_e( 'Proceed to Checkout', 'dish-dash' ); ?> →
                </button>
            </div>
        </aside>

        <!-- Floating cart trigger button -->
        <button class="dd-cart-trigger" aria-label="<?php esc_attr_e( 'Open cart', 'dish-dash' ); ?>">
            🛒 <?php esc_html_e( 'Cart', 'dish-dash' ); ?>
            <span class="dd-cart-count" style="display:none">0</span>
        </button>
        <?php
    }

    // ─────────────────────────────────────────
    //  NAV MENUS
    // ─────────────────────────────────────────
    public function register_nav_menus(): void {
        register_nav_menus( [
            'dd-primary' => __( 'Dish Dash Primary Menu', 'dish-dash' ),
            'dd-footer'  => __( 'Dish Dash Footer Menu',  'dish-dash' ),
        ] );
    }

    // ─────────────────────────────────────────
    //  PAGE TEMPLATE
    // ─────────────────────────────────────────
    public function register_page_template( array $templates ): array {
        $templates['page-dishdash.php'] = __( 'Dish Dash Full Page', 'dish-dash' );
        return $templates;
    }

    public function load_page_template( string $template ): string {
        if ( is_page() ) {
            $meta = get_post_meta( get_the_ID(), '_wp_page_template', true );
            if ( 'page-dishdash.php' === $meta ) {
                $plugin_template = DD_TEMPLATES_DIR . 'page-dishdash.php';
                if ( file_exists( $plugin_template ) ) {
                    return $plugin_template;
                }
            }
        }
        return $template;
    }

    // ─────────────────────────────────────────
    //  FRONTEND ASSETS
    // ─────────────────────────────────────────
    public function enqueue_frontend_assets(): void {
        if ( is_admin() ) return;
        $plugin_url = plugins_url( 'dish-dash' );
        wp_enqueue_style(  'dish-dash-theme',    $plugin_url . '/assets/css/theme.css',    [], DD_VERSION );
        wp_enqueue_style(  'dish-dash-menu',     $plugin_url . '/assets/css/menu.css',     [], DD_VERSION );
        wp_enqueue_style(  'dish-dash-cart',     $plugin_url . '/assets/css/cart.css',     [], DD_VERSION );
        wp_enqueue_script( 'dish-dash-menu',     $plugin_url . '/assets/js/menu.js',     [], DD_VERSION, true );
        wp_enqueue_script( 'dish-dash-cart',     $plugin_url . '/assets/js/cart.js',     [], DD_VERSION, true );
        wp_enqueue_script( 'dish-dash-frontend', $plugin_url . '/assets/js/frontend.js', [], DD_VERSION, true );
        wp_localize_script( 'dish-dash-cart', 'dishDash', DD_Settings::get_public_settings() );
    }

    // ─────────────────────────────────────────
    //  ADMIN PAGE
    // ─────────────────────────────────────────
    public function register_admin_page(): void {
        add_submenu_page(
            'dish-dash',
            __( 'Template Settings', 'dish-dash' ),
            __( '🎨 Template', 'dish-dash' ),
            'manage_options',
            'dish-dash-template',
            [ $this, 'render_admin_page' ]
        );
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'dish-dash-template' ) === false ) return;
        wp_enqueue_media();
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_add_inline_script( 'wp-color-picker', '
            jQuery(document).ready(function($){
                $(".dd-color-picker").wpColorPicker({
                    change: function(e, ui){
                        $(this).val(ui.color.toString()).trigger("input");
                    }
                });
                $(".dd-upload-btn").on("click", function(e){
                    e.preventDefault();
                    var target = $(this).data("target");
                    var preview = $(this).data("preview");
                    var frame = wp.media({ title: "Select Image", multiple: false });
                    frame.on("select", function(){
                        var attachment = frame.state().get("selection").first().toJSON();
                        $("#" + target).val(attachment.url);
                        $("#" + preview).attr("src", attachment.url).show();
                    });
                    frame.open();
                });
            });
        ' );
    }

    // ─────────────────────────────────────────
    //  SAVE SETTINGS
    // ─────────────────────────────────────────
    public function save_settings(): void {
        if (
            ! isset( $_POST['dd_template_save'] ) ||
            ! check_admin_referer( 'dd_template_settings', 'dd_template_nonce' ) ||
            ! current_user_can( 'manage_options' )
        ) {
            return;
        }

        $fields = [
            'dish_dash_restaurant_name' => 'sanitize_text_field',
            'dish_dash_logo_url'        => 'esc_url_raw',
            'dish_dash_primary_color'   => 'sanitize_hex_color',
            'dish_dash_dark_color'      => 'sanitize_hex_color',
            'dish_dash_hero_title'      => 'sanitize_text_field',
            'dish_dash_hero_subtitle'   => 'sanitize_text_field',
            'dish_dash_hero_image'      => 'esc_url_raw',
            'dish_dash_address'         => 'sanitize_text_field',
            'dish_dash_phone'           => 'sanitize_text_field',
            'dish_dash_contact_email'   => 'sanitize_email',
            'dish_dash_opening_hours'   => 'sanitize_text_field',
            'dish_dash_facebook'        => 'esc_url_raw',
            'dish_dash_instagram'       => 'esc_url_raw',
            'dish_dash_whatsapp'        => 'sanitize_text_field',
            'dish_dash_tiktok'          => 'esc_url_raw',
        ];

        foreach ( $fields as $key => $sanitizer ) {
            if ( isset( $_POST[ $key ] ) ) {
                update_option( $key, $sanitizer( $_POST[ $key ] ) );
            }
        }

        wp_redirect( add_query_arg( [
            'page'  => 'dish-dash-template',
            'saved' => '1',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // ─────────────────────────────────────────
    //  RENDER ADMIN PAGE
    // ─────────────────────────────────────────
    public function render_admin_page(): void {
        $saved = isset( $_GET['saved'] ) && '1' === $_GET['saved'];
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
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" class="button">
                    <?php esc_html_e( 'Preview Site', 'dish-dash' ); ?> ↗
                </a>
            </div>

            <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible" style="margin-top:1rem">
                <p>✅ <strong><?php esc_html_e( 'Settings saved successfully!', 'dish-dash' ); ?></strong>
                <?php esc_html_e( 'Your changes are now live on the site.', 'dish-dash' ); ?></p>
            </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field( 'dd_template_settings', 'dd_template_nonce' ); ?>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-top:1.5rem;">

                    <!-- BRANDING -->
                    <div class="dd-settings-card">
                        <h2>🏪 <?php esc_html_e( 'Branding', 'dish-dash' ); ?></h2>
                        <div class="dd-form-group">
                            <label><?php esc_html_e( 'Restaurant Name', 'dish-dash' ); ?></label>
                            <input type="text" name="dish_dash_restaurant_name"
                                value="<?php echo esc_attr( get_option( 'dish_dash_restaurant_name', get_bloginfo( 'name' ) ) ); ?>" />
                        </div>
                        <div class="dd-form-group">
                            <label><?php esc_html_e( 'Logo', 'dish-dash' ); ?></label>
                            <div style="display:flex;gap:.5rem;">
                                <input type="text" id="dd_logo_url" name="dish_dash_logo_url"
                                    value="<?php echo esc_attr( get_option( 'dish_dash_logo_url', '' ) ); ?>" style="flex:1" />
                                <button type="button" class="button dd-upload-btn"
                                    data-target="dd_logo_url" data-preview="dd_logo_preview">
                                    <?php esc_html_e( 'Upload', 'dish-dash' ); ?>
                                </button>
                            </div>
                            <?php $logo = get_option( 'dish_dash_logo_url', '' ); ?>
                            <img id="dd_logo_preview" src="<?php echo esc_url( $logo ); ?>"
                                style="max-height:60px;margin-top:.5rem;<?php echo $logo ? '' : 'display:none'; ?>" />
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                            <div class="dd-form-group">
                                <label><?php esc_html_e( 'Primary Color', 'dish-dash' ); ?></label>
                                <input type="text" name="dish_dash_primary_color" class="dd-color-picker"
                                    value="<?php echo esc_attr( get_option( 'dish_dash_primary_color', '#E8832A' ) ); ?>" />
                            </div>
                            <div class="dd-form-group">
                                <label><?php esc_html_e( 'Dark Color', 'dish-dash' ); ?></label>
                                <input type="text" name="dish_dash_dark_color" class="dd-color-picker"
                                    value="<?php echo esc_attr( get_option( 'dish_dash_dark_color', '#1E3A5F' ) ); ?>" />
                            </div>
                        </div>
                    </div>

                    <!-- HERO -->
                    <div class="dd-settings-card">
                        <h2>🦸 <?php esc_html_e( 'Hero Section', 'dish-dash' ); ?></h2>
                        <div class="dd-form-group">
                            <label><?php esc_html_e( 'Hero Title', 'dish-dash' ); ?></label>
                            <input type="text" name="dish_dash_hero_title"
                                value="<?php echo esc_attr( get_option( 'dish_dash_hero_title', 'Hello Dear,' ) ); ?>" />
                        </div>
                        <div class="dd-form-group">
                            <label><?php esc_html_e( 'Hero Subtitle', 'dish-dash' ); ?></label>
                            <input type="text" name="dish_dash_hero_subtitle"
                                value="<?php echo esc_attr( get_option( 'dish_dash_hero_subtitle', "Hungry? You're in the right place..." ) ); ?>" />
                        </div>
                        <div class="dd-form-group">
                            <label><?php esc_html_e( 'Hero Banner Image', 'dish-dash' ); ?></label>
                            <div style="display:flex;gap:.5rem;">
                                <input type="text" id="dd_hero_image" name="dish_dash_hero_image"
                                    value="<?php echo esc_attr( get_option( 'dish_dash_hero_image', '' ) ); ?>" style="flex:1" />
                                <button type="button" class="button dd-upload-btn"
                                    data-target="dd_hero_image" data-preview="dd_hero_preview">
                                    <?php esc_html_e( 'Upload', 'dish-dash' ); ?>
                                </button>
                            </div>
                            <?php $hero_img = get_option( 'dish_dash_hero_image', '' ); ?>
                            <img id="dd_hero_preview" src="<?php echo esc_url( $hero_img ); ?>"
                                style="max-width:100%;margin-top:.5rem;border-radius:8px;<?php echo $hero_img ? '' : 'display:none'; ?>" />
                        </div>
                    </div>

                    <!-- CONTACT -->
                    <div class="dd-settings-card">
                        <h2>📍 <?php esc_html_e( 'Contact Information', 'dish-dash' ); ?></h2>
                        <div class="dd-form-group">
                            <label><?php esc_html_e( 'Address', 'dish-dash' ); ?></label>
                            <input type="text" name="dish_dash_address"
                                value="<?php echo esc_attr( get_option( 'dish_dash_address', '' ) ); ?>" />
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                            <div class="dd-form-group">
                                <label><?php esc_html_e( 'Phone', 'dish-dash' ); ?></label>
                                <input type="text" name="dish_dash_phone"
                                    value="<?php echo esc_attr( get_option( 'dish_dash_phone', '' ) ); ?>" />
                            </div>
                            <div class="dd-form-group">
                                <label><?php esc_html_e( 'Email', 'dish-dash' ); ?></label>
                                <input type="email" name="dish_dash_contact_email"
                                    value="<?php echo esc_attr( get_option( 'dish_dash_contact_email', '' ) ); ?>" />
                            </div>
                        </div>
                        <div class="dd-form-group">
                            <label><?php esc_html_e( 'Opening Hours', 'dish-dash' ); ?></label>
                            <input type="text" name="dish_dash_opening_hours"
                                value="<?php echo esc_attr( get_option( 'dish_dash_opening_hours', '' ) ); ?>"
                                placeholder="Monday – Friday 10 AM – 7 PM" />
                        </div>
                    </div>

                    <!-- SOCIAL -->
                    <div class="dd-settings-card">
                        <h2>📱 <?php esc_html_e( 'Social Media', 'dish-dash' ); ?></h2>
                        <?php
                        $socials = [
                            'dish_dash_facebook'  => [ '📘', 'Facebook URL' ],
                            'dish_dash_instagram' => [ '📷', 'Instagram URL' ],
                            'dish_dash_whatsapp'  => [ '💬', 'WhatsApp Number' ],
                            'dish_dash_tiktok'    => [ '🎵', 'TikTok URL' ],
                        ];
                        foreach ( $socials as $key => [$icon, $label] ) : ?>
                        <div class="dd-form-group">
                            <label><?php echo esc_html( $icon . ' ' . $label ); ?></label>
                            <input type="text" name="<?php echo esc_attr( $key ); ?>"
                                value="<?php echo esc_attr( get_option( $key, '' ) ); ?>" />
                        </div>
                        <?php endforeach; ?>
                    </div>

                </div>

                <!-- Save -->
                <div style="margin-top:1.5rem;padding:1.25rem;background:#fff;border-radius:10px;border:1px solid #e0e0e0;display:flex;align-items:center;justify-content:space-between;">
                    <span style="color:#666;font-size:.9rem;">
                        <?php esc_html_e( 'Changes apply immediately on your Dish Dash pages.', 'dish-dash' ); ?>
                    </span>
                    <?php submit_button( __( '💾 Save All Settings', 'dish-dash' ), 'primary large', 'dd_template_save', false ); ?>
                </div>

            </form>
        </div>

        <style>
        .dd-settings-card{background:#fff;border:1px solid #e0e0e0;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.04);}
        .dd-settings-card h2{font-size:1rem;font-weight:700;margin:0 0 1.25rem;padding-bottom:.75rem;border-bottom:2px solid #f0f0f0;color:#1E3A5F;}
        .dd-form-group{margin-bottom:1rem;}
        .dd-form-group label{display:block;font-size:.82rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#888;margin-bottom:.35rem;}
        .dd-form-group input[type="text"],.dd-form-group input[type="email"]{width:100%;padding:.6rem .85rem;border:1.5px solid #e0e0e0;border-radius:8px;font-size:.9rem;transition:border-color .2s;}
        .dd-form-group input:focus{border-color:#E8832A;outline:none;box-shadow:0 0 0 3px rgba(232,131,42,.1);}
        </style>
        <?php
    }

    // ─────────────────────────────────────────
    //  GLOBAL HEADER
    //  Shown on specific pages
    // ─────────────────────────────────────────

    /**
     * List of page slugs where our header should appear.
     */
    private function get_global_header_slugs(): array {
        return [
            'reserve-table',
            'cart',
            'restaurant-menu',
            'my-restaurant-account',
            'my-account',
            'track-order',
            'checkout',
        ];
    }

    /**
     * Check if current page should show our global header.
     */
    private function is_global_header_page(): bool {
        if ( is_admin() ) return false;

        // Never on DishDash full page template — it has its own header
        if ( is_page() ) {
            $meta = get_post_meta( get_the_ID(), '_wp_page_template', true );
            if ( 'page-dishdash.php' === $meta ) return false;
        }

        // Check page slugs
        if ( is_page( $this->get_global_header_slugs() ) ) return true;

        // Also show on WooCommerce pages
        if ( function_exists( 'is_cart' ) && is_cart() ) return true;
        if ( function_exists( 'is_checkout' ) && is_checkout() ) return true;
        if ( function_exists( 'is_account_page' ) && is_account_page() ) return true;
        if ( function_exists( 'is_shop' ) && is_shop() ) return true;

        return false;
    }

    /**
     * Render the global header HTML.
     */
    private function render_global_header(): void {
        $dd_name   = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );
        $dd_logo   = get_option( 'dish_dash_logo_url', '' );
        $dd_initials = strtoupper( substr( $dd_name, 0, 2 ) );
        $dd_cart_count = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
        $home_url  = home_url( '/' );
        ?>
        <header class="dd-header dd-global-header">
            <div class="dd-container dd-header__inner">

                <a href="<?php echo esc_url( $home_url ); ?>" class="dd-brand">
                    <?php if ( $dd_logo ) : ?>
                        <img src="<?php echo esc_url( $dd_logo ); ?>"
                             alt="<?php echo esc_attr( $dd_name ); ?>"
                             class="dd-brand__logo">
                    <?php else : ?>
                        <span class="dd-brand__badge"><?php echo esc_html( $dd_initials ); ?></span>
                        <div>
                            <div class="dd-brand__name"><?php echo esc_html( $dd_name ); ?></div>
                            <div class="dd-brand__sub">Restaurant</div>
                        </div>
                    <?php endif; ?>
                </a>

                <button class="dd-mobile-toggle" id="ddMobileToggle" aria-label="Open menu">&#9776;</button>

                <nav class="dd-nav" id="ddMainNav">
                    <?php
                    $nav_html = wp_nav_menu( array(
                        'theme_location' => 'dd-primary',
                        'container'      => false,
                        'items_wrap'     => '%3$s',
                        'fallback_cb'    => false,
                        'echo'           => false,
                    ) );
                    if ( $nav_html ) {
                        echo $nav_html;
                    } else {
                        echo '<a href="' . esc_url( $home_url ) . '">Home</a>';
                        echo '<a href="' . esc_url( $home_url ) . '#menu">Menu</a>';
                        echo '<a href="' . esc_url( $home_url ) . '#reserve">Reserve</a>';
                    }
                    ?>
                </nav>

                <div class="dd-header__actions">
                    <a href="<?php echo esc_url( $home_url ); ?>"
                       class="dd-btn dd-btn--light dd-btn--sm">&#8592; Back to Home</a>
                    <button class="dd-cart-top" id="ddCartTopBtn" aria-label="Open cart">
                        <span class="dd-cart-top__label">Cart</span>
                        <span class="dd-cart-badge" id="ddCartCount"><?php echo esc_html( $dd_cart_count ); ?></span>
                    </button>
                </div>

            </div>
        </header>
        <?php
    }

    /**
     * Inject header via wp_body_open (modern themes).
     */
    public function inject_global_header(): void {
        if ( ! $this->is_global_header_page() ) return;
        $this->render_global_header();
    }

    /**
     * Fallback: inject header via JS for themes that don't support wp_body_open.
     */
    public function inject_global_header_fallback(): void {
        if ( ! $this->is_global_header_page() ) return;
        // Only run if wp_body_open is not supported
        if ( function_exists( 'wp_is_block_theme' ) && current_theme_supports( 'body-open' ) ) return;
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Check if our header was already injected via wp_body_open
            if ( document.querySelector('.dd-global-header') ) return;

            var headerHTML = <?php
                ob_start();
                $this->render_global_header();
                $html = ob_get_clean();
                echo json_encode( $html );
            ?>;

            var body = document.body;
            var firstChild = body.firstChild;
            var div = document.createElement('div');
            div.innerHTML = headerHTML;
            body.insertBefore( div.firstChild, firstChild );
        });
        </script>
        <?php
    }

    // ─────────────────────────────────────────
    //  REMOVE THEME & PLUGIN CONFLICTS
    //  Runs only on our DishDash page template
    // ─────────────────────────────────────────
    public function remove_theme_conflicts(): void {
        if ( ! is_page() ) return;

        $meta = get_post_meta( get_the_ID(), '_wp_page_template', true );
        if ( 'page-dishdash.php' !== $meta ) return;

        // ── Remove Astra theme styles ──
        wp_dequeue_style( 'astra-theme-css' );
        wp_dequeue_style( 'astra-theme-dynamic-css' );
        wp_dequeue_style( 'astra-fonts' );
        wp_dequeue_style( 'astra-google-fonts' );
        wp_deregister_style( 'astra-theme-css' );
        wp_deregister_style( 'astra-theme-dynamic-css' );

        // ── Remove Astra scripts ──
        wp_dequeue_script( 'astra-theme-js' );
        wp_deregister_script( 'astra-theme-js' );

        // ── Remove WooCommerce styles that conflict ──
        wp_dequeue_style( 'woocommerce-general' );
        wp_dequeue_style( 'woocommerce-layout' );
        wp_dequeue_style( 'woocommerce-smallscreen' );
        wp_dequeue_style( 'wc-blocks-style' );
        wp_dequeue_style( 'wc-blocks-vendors-style' );

        // ── Remove WordPress block / global styles ──
        wp_dequeue_style( 'wp-block-library' );
        wp_dequeue_style( 'global-styles' );
        wp_dequeue_style( 'classic-theme-styles' );

        // ── Remove Elementor if active ──
        wp_dequeue_style( 'elementor-frontend' );
        wp_dequeue_style( 'elementor-post' );
        wp_dequeue_script( 'elementor-frontend' );
    }
}
