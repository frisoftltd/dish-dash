<?php
/**
 * DD_Profile_Module — customer-facing "My Profile" tab on WooCommerce my-account.
 *
 * Adds a profile endpoint that surfaces DD_Customer_Profile::get():
 * tier, stats, favorites, birthday, WhatsApp contact, phone-link.
 *
 * White-label: all styling via var(--brand)/var(--dd-*) tokens injected
 * dynamically from each restaurant's settings. No hardcoded colors.
 *
 * @package DishDash
 * @since   3.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once DD_PLUGIN_DIR . 'modules/orders/class-dd-customer-profile.php';
require_once DD_PLUGIN_DIR . 'modules/orders/class-dd-customer-manager.php';

class DD_Profile_Module extends DD_Module {

    protected string $id = 'profile';

    const ENDPOINT = 'my-profile';

    public function init(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $this, 'render_endpoint' ] );
        add_filter( 'query_vars', [ $this, 'add_query_var' ], 0 );

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_dd_profile_save_birthday', [ $this, 'ajax_save_birthday' ] );
        add_action( 'wp_ajax_dd_profile_link_phone',    [ $this, 'ajax_link_phone' ] );

        add_action( 'init', [ $this, 'maybe_flush_rewrite' ], 99 );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
    }

    public function add_query_var( $vars ) {
        $vars[] = self::ENDPOINT;
        return $vars;
    }

    /**
     * Flush rewrite rules once after this version deploys (endpoint 404s otherwise).
     */
    public function maybe_flush_rewrite(): void {
        if ( get_option( 'dd_profile_endpoint_flushed' ) !== DD_VERSION ) {
            $this->add_endpoint();
            flush_rewrite_rules( false );
            update_option( 'dd_profile_endpoint_flushed', DD_VERSION );
        }
    }

    /**
     * Build a clean account menu for a restaurant:
     * My Profile (first) · Order History · Addresses · Account details · Log out.
     * Removes Dashboard and Downloads (not useful for a restaurant).
     */
    public function add_menu_item( $items ) {
        $clean = [];
        $clean[ self::ENDPOINT ] = __( 'My Profile', 'dish-dash' );

        if ( isset( $items['orders'] ) ) {
            $clean['orders'] = __( 'Order History', 'dish-dash' );
        }
        if ( isset( $items['edit-address'] ) ) {
            $clean['edit-address'] = __( 'Addresses', 'dish-dash' );
        }
        if ( isset( $items['edit-account'] ) ) {
            $clean['edit-account'] = __( 'Account details', 'dish-dash' );
        }
        if ( isset( $items['customer-logout'] ) ) {
            $clean['customer-logout'] = $items['customer-logout'];
        }
        return $clean;
    }

    public function enqueue_assets(): void {
        if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return;
        wp_enqueue_style( 'dish-dash-profile', DD_ASSETS_URL . 'css/profile.css', [ 'dish-dash-theme' ], DD_VERSION );
    }

    /**
     * Render the My Profile tab content.
     */
    public function render_endpoint(): void {
        $user_id = get_current_user_id();
        $profile = DD_Customer_Profile::get( $user_id );
        $tpl     = DD_PLUGIN_DIR . 'templates/profile/my-profile.php';
        if ( file_exists( $tpl ) ) {
            include $tpl;
        }
    }

    /**
     * AJAX — customer saves their birthday (month + day).
     */
    public function ajax_save_birthday(): void {
        if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in.' );
        check_ajax_referer( 'dd_profile', 'nonce' );

        $user_id = get_current_user_id();
        $month   = (int) ( $_POST['month'] ?? 0 );
        $day     = (int) ( $_POST['day'] ?? 0 );

        if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
            wp_send_json_error( 'Please choose a valid month and day.' );
        }

        global $wpdb;
        $customer = DD_Customer_Manager::get_customer_for_user( $user_id );
        if ( ! $customer ) {
            wp_send_json_error( 'Add your phone number first to set up your profile.' );
        }

        $birthday = sprintf( '2000-%02d-%02d', $month, $day );
        $wpdb->update(
            $wpdb->prefix . 'dishdash_customers',
            [ 'birthday' => $birthday ],
            [ 'id' => (int) $customer->id ],
            [ '%s' ], [ '%d' ]
        );
        update_user_meta( $user_id, 'dd_birthday', $birthday );

        wp_send_json_success( [ 'display' => date_i18n( 'F j', strtotime( $birthday ) ) ] );
    }

    /**
     * AJAX — customer links their phone (surfaces the Brief 1 foundation).
     */
    public function ajax_link_phone(): void {
        if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in.' );
        check_ajax_referer( 'dd_profile', 'nonce' );

        $user_id = get_current_user_id();
        $phone   = sanitize_text_field( $_POST['phone'] ?? '' );
        $user    = wp_get_current_user();
        $name    = $user ? $user->display_name : '';

        $result = DD_Customer_Manager::link_user_to_phone( $user_id, $phone, $name );
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        }
        wp_send_json_error( $result['message'] );
    }
}
