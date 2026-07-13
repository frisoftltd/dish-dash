<?php
/**
 * File:    modules/template/class-dd-template-module.php
 * Module:  DD_Template_Module (extends DD_Module)
 * Purpose: Owns the full-page template (page-dishdash.php), the global
 *          header/footer injection on all Dish Dash pages, frontend asset
 *          enqueuing, nav menu registration, and theme conflict removal.
 *          Also owns the Plugin Settings admin page.
 *
 * Dependencies (this file needs):
 *   - DD_Module base class
 *   - templates/page-dishdash.php  (page template file)
 *   - templates/cart/cart.php      (injected into wp_footer)
 *   - assets/css/theme.css, assets/css/cart.css, assets/css/menu-page.css
 *   - assets/js/frontend.js, assets/js/cart.js, assets/js/search.js,
 *     assets/js/tracking.js
 *   - admin/pages/settings.php, admin/pages/template-settings.php
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php (instantiates this module)
 *
 * Hooks registered:
 *   - admin_menu, admin_init, admin_enqueue_scripts
 *   - theme_page_templates (filter), template_include (filter)
 *   - after_setup_theme → register_nav_menus()
 *   - wp_enqueue_scripts → enqueue_frontend_assets() + remove_theme_conflicts()
 *   - wp_footer → inject_cart_sidebar() + inject_global_footer() + inject_product_modal()
 *   - wp_body_open → inject_global_header()
 *   - wp_head → inject_global_header_styles()
 *   - init → remove_theme_header_hooks()
 *
 * Nav menu locations: dd-primary (main nav), dd-footer (footer nav)
 *
 * Global header injected on pages:
 *   /reserve-table/, /cart-dd/, /checkout-dd/, /restaurant-menu/,
 *   /my-account/, /my-restaurant-account/, /track-order/
 *
 * Localized JS data (window.dishDash):
 *   ajaxUrl, nonce, cartUrl, checkoutUrl, trackUrl, currency settings,
 *   page IDs, primaryColor
 *
 * Admin page: dish-dash-settings
 *
 * Depends on (modules): NONE — architecture rule
 *
 * Last modified: v3.1.13
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
        add_action( 'wp_head',      [ $this, 'inject_global_header_styles' ] );

        // ── Inject global footer on all DD pages ──
        add_action( 'wp_footer', [ $this, 'inject_global_footer' ] );

        // ── Inject product modal on all DD pages ──
        add_action( 'wp_footer', [ $this, 'inject_product_modal' ] );

        // ── Remove theme header on global header pages (runs early) ──
        add_action( 'init', [ $this, 'remove_theme_header_hooks' ] );

        // ── Reservation modal (injected on all DD pages via footer) ──
        add_action( 'wp_footer', [ $this, 'inject_reservation_modal' ] );

        // ── Birthday flow ──
        add_action( 'wp_footer',       [ $this, 'inject_birthday_whatsapp' ] );
        add_filter( 'template_include', [ $this, 'maybe_load_birthday_template' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_birthday_css' ] );
    }

    // ─────────────────────────────────────────
    //  INJECT CART SIDEBAR IN FOOTER
    //  Runs on every page so cart is always
    //  available regardless of theme or template
    // ─────────────────────────────────────────
    public function inject_cart_sidebar(): void {
        if ( is_admin() ) return;

        $checkout_url  = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
        $dd_cart_count = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;

        // Cart drawer + floating button — single source of truth
        require_once DD_PLUGIN_DIR . 'templates/cart/cart.php';
        ?>

        <!-- ══ MOBILE BOTTOM NAV ════════════════════════════════ -->
        <nav class="dd-bottom-nav" id="ddBottomNav">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="dd-bottom-link">
                <span class="dd-bottom-link__icon">&#127968;</span><span>Home</span>
            </a>
            <a href="<?php echo esc_url( home_url( '/restaurant-menu/' ) ); ?>" class="dd-bottom-link">
                <span class="dd-bottom-link__icon">&#127859;</span><span>Menu</span>
            </a>
            <a href="<?php echo esc_url( home_url( '/#reserve' ) ); ?>" class="dd-bottom-link js-open-reservation">
                <span class="dd-bottom-link__icon">&#127860;</span><span>Reserve</span>
            </a>
            <button class="dd-bottom-link" id="ddBottomCartBtn" type="button">
                <span class="dd-bottom-link__icon">&#128722;</span>
                <span>Cart</span>
                <span class="dd-bottom-badge" id="ddBottomBadge"
                      style="<?php echo $dd_cart_count > 0 ? '' : 'display:none'; ?>">
                    <?php echo esc_html( $dd_cart_count ); ?>
                </span>
            </button>
        </nav>
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
        $templates['page-dishdash.php'] = __( 'Dish Dash Full Page',   'dish-dash' );
        $templates['page-simple.php']   = __( 'Dish Dash Simple Page', 'dish-dash' );
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
            if ( 'page-simple.php' === $meta ) {
                $plugin_template = DD_TEMPLATES_DIR . 'page-simple.php';
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

    private function asset_url( string $type, string $filename ): string {
        return DD_ASSETS_URL . $type . '/' . $filename;
    }

    private function is_dishdash_page(): bool {
        if ( is_front_page() )                    return true;
        if ( is_page( 'restaurant-menu' ) )       return true;
        if ( is_page( 'cart' ) )                  return true;
        if ( is_page( 'checkout' ) )              return true;
        if ( is_page( 'birthday' ) )              return true;
        if ( is_page( 'my-account' ) )            return true;
        if ( is_page( 'track-order' ) )           return true;
        if ( is_page() ) {
            $meta = get_post_meta( get_the_ID(), '_wp_page_template', true );
            if ( 'page-dishdash.php' === $meta ) return true;
            if ( 'page-simple.php'   === $meta ) return true;
        }
        return false;
    }

    public function enqueue_frontend_assets(): void {
        if ( is_admin() ) return;
        if ( ! $this->is_dishdash_page() ) return;

        $primary = get_option( 'dish_dash_primary_color', '#6B1D1D' );
        $dark    = get_option( 'dish_dash_dark_color',    '#160F0D' );

        wp_enqueue_style(  'dish-dash-theme',    $this->asset_url( 'css', 'theme.css' ),    [], DD_VERSION );
        wp_enqueue_style(  'dish-dash-frontend', $this->asset_url( 'css', 'frontend.css' ), [ 'dish-dash-theme' ], DD_VERSION );
        wp_enqueue_style(  'dish-dash-menu',     $this->asset_url( 'css', 'menu.css' ),     [], DD_VERSION );
        wp_enqueue_style(  'dish-dash-cart',     $this->asset_url( 'css', 'cart.css' ),     [], DD_VERSION );
        wp_enqueue_style(
            'dish-dash-reservations',
            $this->asset_url( 'css', 'reservations.css' ),
            [ 'dish-dash-frontend' ],
            DD_VERSION
        );

        if ( is_page() ) {
            $meta = get_post_meta( get_the_ID(), '_wp_page_template', true );
            if ( 'page-simple.php' === $meta ) {
                wp_enqueue_style(
                    'dd-simple-page',
                    $this->asset_url( 'css', 'simple-page.css' ),
                    [],
                    DD_VERSION
                );
            }
        }

        // ── intl-tel-input (self-hosted country-code picker, v3.10.33) ──
        // Vendored under assets/vendor/. Loaded on every Dish Dash frontend page so
        // the cart drawer, reservations modal, and My Profile phone inputs can attach
        // the picker. utils.js is loaded lazily by the JS via loadUtils() (see below).
        $itl_base = DD_ASSETS_URL . 'vendor/intl-tel-input';
        wp_enqueue_style(
            'dd-intl-tel-input',
            $itl_base . '/css/intlTelInput.min.css',
            [],
            DD_VERSION
        );
        wp_enqueue_script(
            'dd-intl-tel-input',
            $itl_base . '/js/intlTelInput.min.js',
            [],
            DD_VERSION,
            true
        );
        wp_localize_script( 'dd-intl-tel-input', 'ddIntlTel', [
            'vendorUrl' => $itl_base,
            'utilsUrl'  => $itl_base . '/js/utils.js',
        ] );

        wp_enqueue_script( 'dish-dash-menu',     $this->asset_url( 'js', 'menu.js' ),     [], DD_VERSION, true );
        wp_enqueue_script( 'dish-dash-cart',     $this->asset_url( 'js', 'cart.js' ),     [ 'dd-intl-tel-input' ], DD_VERSION, true );
        wp_enqueue_script( 'dish-dash-search',   $this->asset_url( 'js', 'search.js' ),   [], DD_VERSION, true );
        wp_enqueue_script( 'dish-dash-frontend', $this->asset_url( 'js', 'frontend.js' ), [ 'dish-dash-search' ], DD_VERSION, true );
        wp_enqueue_script(
            'dish-dash-reservations',
            $this->asset_url( 'js', 'reservations.js' ),
            [ 'dd-intl-tel-input' ],
            DD_VERSION,
            true
        );
        wp_localize_script( 'dish-dash-cart', 'dishDash', DD_Settings::get_public_settings() );
        wp_localize_script( 'dish-dash-cart', 'ddCartData', [
            'threshold'             => (int) get_option( 'dd_free_delivery_threshold', 10000 ),
            'delivery_fee'          => (int) get_option( 'dd_delivery_fee',            1500  ),
            'ajax_url'              => admin_url( 'admin-ajax.php' ),
            'nonce'                 => wp_create_nonce( 'dish_dash_frontend' ),
            'checkout_url'          => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' ),
            'currency'              => DD_Settings::get( 'currency_symbol', 'RWF' ),
            'freeDeliveryThreshold' => (int) get_option( 'dd_free_delivery_threshold', 10000 ),
            'deliveryFee'           => (int) get_option( 'dd_delivery_fee',            1500  ),
            'deliveryEta'           => get_option( 'dd_delivery_eta', '30–45 minutes' ),
            'whatsappAdmin'         => get_option( 'dd_whatsapp_admin', '' ),
            'pluginUrl'             => plugins_url( '/', DD_PLUGIN_FILE ),
            'paymentGateways'       => (function() {
                if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) return [];
                $gateways = WC()->payment_gateways->get_available_payment_gateways();
                $icon_urls = [
                    'mtn_momo'  => plugins_url( 'assets/images/mtn-momo-logo.jpg', DD_PLUGIN_FILE ),
                    'irembopay' => plugins_url( 'assets/images/irembopay-logo.jpg', DD_PLUGIN_FILE ),
                    'pesapal'   => plugins_url( 'assets/images/pesapal-logo.png',   DD_PLUGIN_FILE ),
                    'cod'       => '',
                    'bacs'      => '',
                    'cheque'    => '',
                ];
                $icon_emojis = [
                    'cod'    => '🛵',
                    'bacs'   => '🏦',
                    'cheque' => '📝',
                ];
                $out = [];
                foreach ( $gateways as $id => $gw ) {
                    if ( $id === 'irembopay' ) {
                        wp_enqueue_script( 'irembopay-inline', 'https://dashboard.irembopay.com/assets/payment/inline.js', [], null, true );
                    }
                    $out[] = [
                        'id'      => $id,
                        'title'   => html_entity_decode( $gw->get_title(), ENT_QUOTES, 'UTF-8' ),
                        'icon'    => $icon_emojis[ $id ] ?? '',
                        'iconUrl' => $icon_urls[ $id ] ?? '',
                    ];
                }
                return $out;
            })(),
        ] );
        wp_localize_script( 'dish-dash-reservations', 'ddReservations', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'dish_dash_frontend' ),
            'depositEnabled' => (bool) get_option( 'dd_reservation_deposit_enabled', 0 ),
            'depositAmount'  => (int)  get_option( 'dd_reservation_deposit_amount', 2000 ),
            'refundPolicy'   => get_option( 'dd_reservation_refund_policy_text', '' ),
        ] );

        // Inject CSS variables + footer background via WordPress inline style system
        // This is guaranteed to output after theme.css and override correctly
        wp_add_inline_style( 'dish-dash-theme', '
            :root {
                --brand:      ' . $primary . ';
                --brand-dark: ' . $dark . ';
            }
            .dd-footer, .dd-global-footer {
                background: ' . $dark . ' !important;
                color: #F1E7DB !important;
            }
            .dd-footer__heading { color: #C9A24A !important; }
            .dd-footer__list a, .dd-footer__list li { color: rgba(241,231,219,0.7) !important; }
            .dd-footer__list a:hover { color: #F1E7DB !important; }
            .dd-footer__bottom { background: rgba(0,0,0,0.25) !important; color: rgba(241,231,219,0.5) !important; }
            .dd-footer__copy, .dd-footer__brand-name { color: rgba(241,231,219,0.7) !important; }
            .dd-footer__social-link { color: rgba(241,231,219,0.7) !important; }
            .dd-footer__social-link:hover { color: #F1E7DB !important; }
        ' );
    }

    // ─────────────────────────────────────────
    //  ADMIN PAGE
    // ─────────────────────────────────────────
    public function register_admin_page(): void {
        add_submenu_page(
            'dish-dash',
            __( 'Template Settings', 'dish-dash' ),
            __( '🎨 Template', 'dish-dash' ),
            'dd_manage_template',
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
            ! current_user_can( 'dd_manage_template' )
        ) {
            return;
        }

        do_action( 'dd_log_activity', [
            'action'      => 'settings_updated',
            'object_type' => 'template',
            'object_id'   => 'template',
        ] );

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
        $saved   = isset( $_GET['saved'] ) && '1' === $_GET['saved'];
        $primary = get_option( 'dish_dash_primary_color', '#65040d' );
        $brand_url = admin_url( 'admin.php?page=dish-dash-brand-identity' );
        ?>
        <div class="wrap dd-admin-wrap">

            <div class="dd-admin-header">
                <div class="dd-admin-header__logo">
                    <span class="dd-logo-icon">🎨</span>
                    <div>
                        <h1><?php esc_html_e( 'Template', 'dish-dash' ); ?></h1>
                        <span class="dd-version"><?php esc_html_e( 'Choose your restaurant template', 'dish-dash' ); ?></span>
                    </div>
                </div>
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" class="button">
                    <?php esc_html_e( 'Preview Site', 'dish-dash' ); ?> ↗
                </a>
            </div>

            <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible" style="margin-top:1rem">
                <p>✅ <strong><?php esc_html_e( 'Settings saved.', 'dish-dash' ); ?></strong></p>
            </div>
            <?php endif; ?>

            <!-- TEMPLATE CARDS -->
            <div style="margin-top:1.5rem;">
                <h2 style="font-size:1rem;font-weight:700;color:#333;margin-bottom:1rem;">
                    <?php esc_html_e( 'Active Template', 'dish-dash' ); ?>
                </h2>
                <div style="display:flex;gap:1.5rem;flex-wrap:wrap;">

                    <!-- Card 1: Active — Khana Khazana -->
                    <div style="background:#fff;border:2.5px solid <?php echo esc_attr( $primary ); ?>;border-radius:14px;padding:1.25rem;width:220px;box-shadow:0 4px 16px rgba(0,0,0,.08);position:relative;">
                        <div style="border-radius:8px;overflow:hidden;margin-bottom:1rem;height:110px;background:#F5EFE6;position:relative;">
                            <div style="height:28px;background:<?php echo esc_attr( $primary ); ?>;"></div>
                            <div style="padding:.5rem;display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.3rem;">
                                <div style="height:10px;width:60px;background:#e0d5c8;border-radius:4px;"></div>
                                <div style="height:10px;width:40px;background:#e0d5c8;border-radius:4px;"></div>
                            </div>
                            <div style="position:absolute;bottom:.5rem;right:.5rem;width:32px;height:32px;background:#E8832A;border-radius:6px;opacity:.7;"></div>
                        </div>
                        <div style="font-weight:700;font-size:.95rem;color:#333;margin-bottom:.4rem;">Khana Khazana</div>
                        <span style="display:inline-block;background:<?php echo esc_attr( $primary ); ?>;color:#fff;font-size:.72rem;font-weight:700;padding:.2rem .6rem;border-radius:20px;margin-bottom:.75rem;">✓ Active</span>
                        <br>
                        <a href="<?php echo esc_url( $brand_url ); ?>" class="button button-primary" style="background:<?php echo esc_attr( $primary ); ?>;border-color:<?php echo esc_attr( $primary ); ?>;width:100%;text-align:center;box-sizing:border-box;">
                            Customize →
                        </a>
                    </div>

                    <!-- Card 2: Coming Soon — Modern Dark -->
                    <div style="background:#fff;border:2px solid #e0e0e0;border-radius:14px;padding:1.25rem;width:220px;opacity:.45;cursor:not-allowed;">
                        <div style="border-radius:8px;overflow:hidden;margin-bottom:1rem;height:110px;background:#2a2a2a;position:relative;">
                            <div style="height:28px;background:#1a1a1a;"></div>
                            <div style="padding:.5rem;display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.3rem;">
                                <div style="height:10px;width:60px;background:#444;border-radius:4px;"></div>
                                <div style="height:10px;width:40px;background:#444;border-radius:4px;"></div>
                            </div>
                            <div style="position:absolute;bottom:.5rem;right:.5rem;width:32px;height:32px;background:#555;border-radius:6px;"></div>
                        </div>
                        <div style="font-weight:700;font-size:.95rem;color:#333;margin-bottom:.4rem;">Modern Dark</div>
                        <span style="display:inline-block;background:#ccc;color:#fff;font-size:.72rem;font-weight:700;padding:.2rem .6rem;border-radius:20px;">Coming Soon</span>
                    </div>

                    <!-- Card 3: Coming Soon — Minimal Light -->
                    <div style="background:#fff;border:2px solid #e0e0e0;border-radius:14px;padding:1.25rem;width:220px;opacity:.45;cursor:not-allowed;">
                        <div style="border-radius:8px;overflow:hidden;margin-bottom:1rem;height:110px;background:#fafafa;position:relative;">
                            <div style="height:28px;background:#f0f0f0;"></div>
                            <div style="padding:.5rem;display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.3rem;">
                                <div style="height:10px;width:60px;background:#ddd;border-radius:4px;"></div>
                                <div style="height:10px;width:40px;background:#ddd;border-radius:4px;"></div>
                            </div>
                            <div style="position:absolute;bottom:.5rem;right:.5rem;width:32px;height:32px;background:#e0e0e0;border-radius:6px;"></div>
                        </div>
                        <div style="font-weight:700;font-size:.95rem;color:#333;margin-bottom:.4rem;">Minimal Light</div>
                        <span style="display:inline-block;background:#ccc;color:#fff;font-size:.72rem;font-weight:700;padding:.2rem .6rem;border-radius:20px;">Coming Soon</span>
                    </div>

                </div>
            </div>

        </div>
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
            'cart-dd',
            'checkout-dd',
            'restaurant-menu',
            'my-restaurant-account',
            'my-account',
            'track-order',
        ];
    }

    /**
     * Check if current page should show our global header.
     */
    private function is_global_header_page(): bool {
        if ( is_admin() ) return false;

        // Show on ALL frontend pages — including the DishDash homepage template
        return true;
    }

    /**
     * Remove theme header hooks on our pages.
     * Uses Astra's own filter + CSS nuclear option as fallback.
     */
    public function remove_theme_header_hooks(): void {
        // Blank theme — no third-party hooks to remove
        // Header/footer visibility handled via CSS in inject_global_header_styles()
    }

    /**
     * Inject styles to hide theme header + our CSS vars.
     */
    public function inject_global_header_styles(): void {
        if ( ! $this->is_global_header_page() ) return;
        $primary = get_option( 'dish_dash_primary_color', '#6B1D1D' );
        $dark    = get_option( 'dish_dash_dark_color', '#160F0D' );
        ?>
        <style>
        /* ── CSS variables for all pages ───────────────────── */
        :root {
            --brand:        <?php echo esc_attr( $primary ); ?>;
            --brand-dark:   <?php echo esc_attr( $dark ); ?>;
            --dd-bg:        #F5EFE6;
            --dd-surface:   #FBF7F1;
            --dd-surface-2: #FFF7EA;
            --dd-white:     #ffffff;
            --dd-text:      #221B19;
            --dd-muted:     #6E5B4C;
            --dd-muted-2:   #8A6E53;
            --dd-gold:      #C9A24A;
            --dd-gold-soft: #E6C77A;
            --dd-line:      #EADfCE;
            --dd-shadow-sm: 0 10px 30px rgba(107,29,29,0.06);
            --dd-shadow-md: 0 20px 40px rgba(0,0,0,0.14);
            --dd-container: 1240px;
        }

        /* ── Hide default theme header/footer — blank theme ── */
        .site-header:not(.dd-header):not(.dd-global-header),
        header:not(.dd-header):not(.dd-global-header),
        .site-footer:not(.dd-footer):not(.dd-global-footer),
        footer:not(.dd-footer):not(.dd-global-footer),
        #colophon, #masthead,
        .woocommerce-breadcrumb,
        .breadcrumbs { display: none !important; }

        /* ── Page content spacing ───────────────────────────── */
        #content, #primary, .entry-content, main {
            margin-top: 0 !important;
            padding-top: 20px !important;
        }

        /* ── Global header ──────────────────────────────────── */
        .dd-global-header {
            position: sticky !important;
            top: 0 !important;
            z-index: 9999 !important;
            width: 100% !important;
        }

        /* ── Footer background — hardcoded, no variable needed ── */
        .dd-footer, .dd-global-footer {
            background: <?php echo esc_attr( $dark ); ?> !important;
            color: #F1E7DB !important;
        }
        </style>
        <?php
    }

    /**
     * Render the global header HTML.
     */
    private function render_global_header(): void {
        $dd_name       = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );
        $dd_logo       = get_option( 'dish_dash_logo_url', '' );
        $dd_initials   = strtoupper( substr( $dd_name, 0, 2 ) );
        $dd_cart_count = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
        $home_url      = home_url( '/' );
        $orders_url    = function_exists( 'wc_get_account_url' )
            ? wc_get_account_url( 'orders' )
            : home_url( '/my-account/orders/' );
        $account_url   = function_exists( 'wc_get_account_endpoint_url' )
            ? wc_get_account_endpoint_url( 'my-profile' )
            : home_url( '/my-account/my-profile/' );

        $nav_html = wp_nav_menu( array(
            'theme_location' => 'dd-primary',
            'container'      => false,
            'items_wrap'     => '%3$s',
            'fallback_cb'    => false,
            'echo'           => false,
        ) );
        if ( ! $nav_html ) {
            $nav_html  = '<a href="' . esc_url( $home_url ) . '">Home</a>';
            $nav_html .= '<a href="' . esc_url( home_url( '/restaurant-menu/' ) ) . '">Our Menu</a>';
            $nav_html .= '<a href="' . esc_url( home_url( '/reserve-table/' ) ) . '">Reserve a Table</a>';
        }
        ?>

        <!-- Drawer overlay -->
        <div class="dd-drawer-overlay" id="ddDrawerOverlay"></div>

        <!-- Slide-out nav drawer -->
        <aside class="dd-nav-drawer" id="ddNavDrawer" aria-label="Navigation">
            <div class="dd-nav-drawer__header">
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
                <button class="dd-nav-drawer__close" id="ddDrawerClose" aria-label="Close">&#10005;</button>
            </div>
            <nav class="dd-nav-drawer__nav"><?php echo $nav_html; ?></nav>
            <div class="dd-nav-drawer__footer">
                <?php if ( is_user_logged_in() ) : ?>
                <a href="<?php echo esc_url( $account_url ); ?>"
                   class="dd-btn dd-btn--light dd-btn--block">&#128100; My Profile</a>
                <button id="ddLogoutBtn" class="dd-nav-drawer__logout">Log out</button>
                <?php else : ?>
                <button id="ddOpenRegister" class="dd-btn dd-btn--brand dd-btn--block" style="margin-bottom:10px;">&#128100; Create Account</button>
                <button id="ddOpenLogin" class="dd-btn dd-btn--light dd-btn--block">Log in</button>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Sticky header -->
        <header class="dd-header dd-global-header" id="ddHeader">
            <div class="dd-container dd-header__inner">

                <!-- Left: hamburger + logo -->
                <div class="dd-header__left">
                    <button class="dd-menu-toggle" id="ddMenuToggle" aria-label="Open menu" aria-expanded="false">
                        <span class="dd-menu-toggle__bar"></span>
                        <span class="dd-menu-toggle__bar"></span>
                        <span class="dd-menu-toggle__bar"></span>
                    </button>
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
                </div>

                <!-- Center: search (desktop only) -->
                <div class="dd-smart-search dd-header__search dd-desktop-search" id="ddSmartSearch">
                    <div class="dd-ss__bar">
                        <span class="dd-ss__icon">&#128269;</span>
                        <input type="search"
                               id="ddSearch"
                               name="dd_search"
                               class="dd-ss__input"
                               placeholder="Search dishes&hellip;"
                               autocomplete="off"
                               autocorrect="off"
                               autocapitalize="off"
                               spellcheck="false"
                               aria-label="Search dishes"
                               aria-expanded="false"
                               aria-autocomplete="list"
                               aria-controls="ddSearchDropdown">
                        <button class="dd-ss__clear" id="ddSearchClear" aria-label="Clear">&#10005;</button>
                    </div>
                    <div class="dd-ss__dropdown" id="ddSearchDropdown" role="listbox"></div>
                </div>

                <!-- Right: search icon (mobile) + actions -->
                <div class="dd-header__actions">
                    <!-- Mobile search trigger -->
                    <button class="dd-mobile-search-trigger" id="ddMobileSearchTrigger" aria-label="Search">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </button>
                    <?php if ( is_user_logged_in() ) : ?>
                    <a href="<?php echo esc_url( $account_url ); ?>"
                       class="dd-btn dd-btn--light dd-btn--sm dd-desktop-only">My Profile</a>
                    <button id="ddLogoutBtn" class="dd-auth-logout-btn dd-btn dd-btn--light dd-btn--sm dd-desktop-only">Log out</button>
                    <?php else : ?>
                    <button id="ddOpenLogin" class="dd-btn dd-btn--light dd-btn--sm dd-desktop-only">Log in</button>
                    <button id="ddOpenRegister" class="dd-btn dd-btn--brand dd-btn--sm dd-desktop-only">Sign up</button>
                    <?php endif; ?>
                    <button class="dd-cart-top" id="ddCartTopBtn" aria-label="Open cart">
                        <span class="dd-cart-top__label">Cart</span>
                        <span class="dd-cart-badge" id="ddCartCount"><?php echo esc_html( $dd_cart_count ); ?></span>
                    </button>
                </div>

            </div>

            <!-- Mobile search expand panel -->
            <div class="dd-mobile-search-panel" id="ddMobileSearchPanel" aria-hidden="true">
                <div class="dd-mobile-search-panel__inner">
                    <div class="dd-ss__bar dd-ss__bar--mobile-expand">
                        <span class="dd-ss__icon">&#128269;</span>
                        <input type="search"
                               id="ddMobileSearch"
                               name="dd_search_mobile"
                               class="dd-ss__input"
                               placeholder="Search dishes, try 'biryani'…"
                               autocomplete="off"
                               autocorrect="off"
                               autocapitalize="off"
                               spellcheck="false"
                               aria-label="Search dishes">
                        <button class="dd-mobile-search-close" id="ddMobileSearchClose" aria-label="Close search">Cancel</button>
                    </div>
                    <div class="dd-ss__dropdown dd-ss__dropdown--mobile" id="ddMobileSearchDropdown" role="listbox"></div>
                </div>
            </div>

        </header>

        <!-- JS bridge for global header pages -->
        <?php
        $dd_hours_state  = class_exists( 'DD_Hours' ) ? DD_Hours::get_state() : 'open';
        $dd_next_open_ts = 0;
        $dd_close_ts     = 0;
        if ( class_exists( 'DD_Hours' ) ) {
            if ( $dd_hours_state !== 'open' ) {
                $dd_next_open_ts = DD_Hours::get_next_open_info_ts();
            }
            if ( in_array( $dd_hours_state, [ 'open', 'closing_soon' ], true ) ) {
                $dd_close_ts = DD_Hours::get_current_close_ts();
            }
        }
        ?>
        <script>
        window.DD = window.DD || {
            ajaxUrl:      '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
            nonce:        '<?php echo esc_js( wp_create_nonce( 'dish_dash_frontend' ) ); ?>',
            checkoutUrl:  '<?php echo esc_url( function_exists("wc_get_checkout_url") ? wc_get_checkout_url() : home_url("/checkout/") ); ?>',
            deliveryFee:  <?php echo (int) get_option( 'dish_dash_delivery_fee', 2000 ); ?>,
            cartCount:    <?php echo (int) $dd_cart_count; ?>,
            hours_state:  '<?php echo esc_js( $dd_hours_state ); ?>',
            next_open_ts: <?php echo (int) $dd_next_open_ts; ?>,
            close_ts:     <?php echo (int) $dd_close_ts; ?>,
            whatsapp_admin: '<?php echo esc_js( get_option( 'dd_whatsapp_admin', '' ) ); ?>',
            menu_url:     '/restaurant-menu/',
        };
        </script>
        <?php
    }

    /**
     * Inject header via wp_body_open (modern themes).
     */
    public function inject_global_header(): void {
        if ( ! $this->is_global_header_page() ) return;
        $this->render_global_header();
    }

    // ─────────────────────────────────────────
    //  INJECT PRODUCT MODAL
    //  On all pages so clicking any dish opens modal
    // ─────────────────────────────────────────
    public function inject_product_modal(): void {
        if ( is_admin() ) return;
        ?>
        <div class="dd-product-modal" id="ddProductModal" role="dialog" aria-modal="true" aria-label="Product details">
            <div class="dd-product-modal__overlay" id="ddProductModalOverlay"></div>
            <div class="dd-product-modal__wrap">
                <button class="dd-product-modal__close" id="ddProductModalClose" aria-label="Close" onclick="if(window.ddCloseModal)window.ddCloseModal();return false;">&#10005;</button>
                <div class="dd-product-modal__content" id="ddProductModalContent"></div>
            </div>
        </div>
        <?php
    }


    public function inject_reservation_modal(): void {
        if ( is_admin() ) return;
        if ( ! $this->is_dishdash_page() ) return;
        require_once DD_PLUGIN_DIR . 'templates/reservations/modal.php';
    }

    // ─────────────────────────────────────────
    //  INJECT GLOBAL FOOTER
    //  Shown on all DD pages except full template
    // ─────────────────────────────────────────
    public function inject_global_footer(): void {
        if ( ! $this->is_global_header_page() ) return;

        $dd_name     = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );
        $dd_logo     = get_option( 'dish_dash_logo_url', '' );
        $dd_initials = strtoupper( substr( $dd_name, 0, 2 ) );
        $dd_addr     = get_option( 'dish_dash_address', '' );
        $dd_phone    = get_option( 'dish_dash_phone', '' );
        $dd_email    = get_option( 'dish_dash_contact_email', '' );
        $dd_hours    = get_option( 'dish_dash_opening_hours', '' );
        $dd_fb       = get_option( 'dish_dash_facebook', '' );
        $dd_ig       = get_option( 'dish_dash_instagram', '' );
        $dd_wa       = get_option( 'dish_dash_whatsapp', '' );
        $dd_tiktok   = get_option( 'dish_dash_tiktok', '' );
        $dd_footer_desc = get_option( 'dd_footer_description', 'Premium Indian dining and a refined digital ordering experience.' );
        $dark        = get_option( 'dish_dash_dark_color', '#160F0D' );
        $home_url    = home_url( '/' );
        $orders_url  = function_exists( 'wc_get_account_url' ) ? wc_get_account_url( 'orders' ) : home_url( '/my-account/orders/' );
        $hours_lines = array_filter( array_map( 'trim', explode( "\n", $dd_hours ) ) );
        ?>
        <footer class="dd-footer dd-global-footer" id="ddGlobalFooter">
            <div class="dd-container dd-footer__grid">

                <div class="dd-footer__col-brand">
                    <div class="dd-footer__brand">
                        <?php if ( $dd_logo ) : ?>
                            <img src="<?php echo esc_url( $dd_logo ); ?>" alt="<?php echo esc_attr( $dd_name ); ?>" class="dd-footer__logo">
                        <?php else : ?>
                            <div class="dd-footer__brand-badge"><?php echo esc_html( $dd_initials ); ?></div>
                            <span class="dd-footer__brand-name"><?php echo esc_html( $dd_name ); ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="dd-footer__copy"><?php echo esc_html( $dd_footer_desc ); ?></p>
                    <div class="dd-footer__social">
                        <?php if ( $dd_fb ) : ?><a href="<?php echo esc_url( $dd_fb ); ?>" target="_blank" rel="noopener" class="dd-footer__social-link" aria-label="Facebook"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></a><?php endif; ?>
                        <?php if ( $dd_ig ) : ?><a href="<?php echo esc_url( $dd_ig ); ?>" target="_blank" rel="noopener" class="dd-footer__social-link" aria-label="Instagram"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></a><?php endif; ?>
                        <?php if ( $dd_wa ) : ?><a href="https://wa.me/<?php echo esc_attr( preg_replace('/\D/', '', $dd_wa) ); ?>" target="_blank" rel="noopener" class="dd-footer__social-link" aria-label="WhatsApp"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.116 1.522 5.849L0 24l6.335-1.498A11.95 11.95 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.587-.504-5.078-1.38l-.36-.214-3.762.889.928-3.667-.235-.374A9.96 9.96 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg></a><?php endif; ?>
                        <?php if ( $dd_tiktok ) : ?><a href="<?php echo esc_url( $dd_tiktok ); ?>" target="_blank" rel="noopener" class="dd-footer__social-link" aria-label="TikTok"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.18 8.18 0 0 0 4.78 1.52V6.75a4.85 4.85 0 0 1-1.01-.06z"/></svg></a><?php endif; ?>
                    </div>
                </div>

                <div>
                    <div class="dd-footer__heading">Explore</div>
                    <ul class="dd-footer__list">
                        <li><a href="<?php echo esc_url( $home_url ); ?>">Home</a></li>
                        <li><a href="<?php echo esc_url( home_url('/restaurant-menu/') ); ?>">Our Menu</a></li>
                        <li><a href="<?php echo esc_url( home_url('/#reserve') ); ?>" class="js-open-reservation">Reserve Table</a></li>
                        <li><a href="<?php echo esc_url( $orders_url ); ?>">Track Order</a></li>
                        <li><a href="<?php echo esc_url( home_url('/privacy-policy/') ); ?>">Privacy Policy</a></li>
                        <li><a href="<?php echo esc_url( home_url('/refund_returns/') ); ?>">Refund &amp; Returns</a></li>
                    </ul>
                </div>

                <div>
                    <div class="dd-footer__heading">Contact</div>
                    <ul class="dd-footer__list">
                        <?php if ( $dd_addr )  echo '<li>📍 ' . esc_html( $dd_addr ) . '</li>'; ?>
                        <?php if ( $dd_phone ) echo '<li><a href="tel:' . esc_attr( preg_replace('/\s/', '', $dd_phone) ) . '">📞 ' . esc_html( $dd_phone ) . '</a></li>'; ?>
                        <?php if ( $dd_email ) echo '<li><a href="mailto:' . esc_attr( $dd_email ) . '">✉️ ' . esc_html( $dd_email ) . '</a></li>'; ?>
                    </ul>
                </div>

                <div>
                    <div class="dd-footer__heading">Opening Hours</div>
                    <ul class="dd-footer__list">
                        <?php if ( ! empty( $hours_lines ) ) : ?>
                            <?php foreach ( $hours_lines as $line ) echo '<li>⏰ ' . esc_html( $line ) . '</li>'; ?>
                        <?php else : ?>
                            <li>Mon – Fri: 10AM – 10PM</li>
                            <li>Sat – Sun: 9AM – 11PM</li>
                        <?php endif; ?>
                    </ul>
                </div>

            </div>
            <div class="dd-footer__bottom" style="background:rgba(0,0,0,0.25);color:rgba(241,231,219,0.6);">
                <div class="dd-container">
                    <p>&copy; <?php echo date( 'Y' ); ?> <?php echo esc_html( $dd_name ); ?> &mdash; Built by <strong>Fri Soft Ltd</strong></p>
                </div>
            </div>
        </footer>
        <?php
    }

        // ─────────────────────────────────────────
    //  REMOVE THEME & PLUGIN CONFLICTS
    //  Runs only on our DishDash page template
    // ─────────────────────────────────────────
    public function remove_theme_conflicts(): void {
        if ( is_admin() ) return;

        // ── Remove WordPress block / global styles on ALL DD pages ──
        // These can inject background colors that override our footer/header styles
        wp_dequeue_style( 'wp-block-library' );
        wp_dequeue_style( 'global-styles' );
        wp_dequeue_style( 'classic-theme-styles' );
        wp_dequeue_style( 'wp-block-library-theme' );

        // ── Remove WooCommerce styles that conflict ──
        wp_dequeue_style( 'woocommerce-layout' );
        wp_dequeue_style( 'woocommerce-smallscreen' );
        wp_dequeue_style( 'wc-blocks-style' );
        wp_dequeue_style( 'wc-blocks-vendors-style' );

        // ── Only on full page template — remove more aggressively ──
        if ( is_page() ) {
            $meta = get_post_meta( get_the_ID(), '_wp_page_template', true );
            if ( 'page-dishdash.php' === $meta ) {
                wp_dequeue_style( 'woocommerce-general' );
            }
        }
    }

    // ─────────────────────────────────────────
    //  BIRTHDAY FLOW
    // ─────────────────────────────────────────

    /**
     * Inject birthday WhatsApp redirect JS if transient is ready.
     * Fires 2 minutes after first order via WP-Cron.
     */
    public function inject_birthday_whatsapp(): void {
        $customer_id = (int) ( $_COOKIE['dd_customer_id'] ?? 0 );
        if ( ! $customer_id ) return;

        $wa_url = get_transient( 'dd_birthday_wa_' . $customer_id );
        if ( ! $wa_url ) return;

        // Delete immediately — one-time only
        delete_transient( 'dd_birthday_wa_' . $customer_id );
        ?>
        <script>
        setTimeout( function() {
            window.location.href = <?php echo wp_json_encode( $wa_url ); ?>;
        }, 1500 );
        </script>
        <?php
    }

    /**
     * Load birthday page template for /birthday/?c=TOKEN
     */
    public function maybe_load_birthday_template( string $template ): string {
        if ( ! isset( $_GET['c'] ) ) return $template;

        global $post;
        if ( ! $post || get_post_field( 'post_name', $post->ID ) !== 'birthday' ) {
            return $template;
        }

        $custom = DD_PLUGIN_DIR . 'templates/birthday.php';
        return file_exists( $custom ) ? $custom : $template;
    }

    /**
     * Enqueue birthday page CSS.
     */
    public function maybe_enqueue_birthday_css(): void {
        if ( ! isset( $_GET['c'] ) ) return;

        global $post;
        if ( ! $post || get_post_field( 'post_name', $post->ID ) !== 'birthday' ) return;

        wp_enqueue_style(
            'dd-birthday',
            $this->asset_url( 'css', 'birthday.css' ),
            [], DD_VERSION
        );
    }
}
