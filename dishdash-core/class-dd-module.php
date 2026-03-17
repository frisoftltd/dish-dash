<?php
/**
 * Dish Dash – Abstract Module Base Class
 *
 * Every Dish Dash module extends this class. It enforces a
 * consistent interface and provides shared utilities.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class DD_Module {

    /**
     * Module identifier — override in each subclass.
     * Used for option keys, hook prefixes etc.
     * Example: 'menu', 'orders', 'delivery'
     */
    protected string $id = '';

    /**
     * Module version — used for asset versioning.
     */
    protected string $version = DD_VERSION;

    /**
     * Boot the module. Called once by DD_Loader.
     * Subclasses register their hooks here.
     */
    abstract public function init(): void;

    // ─────────────────────────────────────────
    //  SHARED UTILITIES
    // ─────────────────────────────────────────

    /**
     * Get a module-scoped option.
     * Key is prefixed automatically: dish_dash_{module_id}_{key}
     */
    protected function get_option( string $key, mixed $default = false ): mixed {
        return get_option( "dish_dash_{$this->id}_{$key}", $default );
    }

    /**
     * Save a module-scoped option.
     */
    protected function update_option( string $key, mixed $value ): bool {
        return update_option( "dish_dash_{$this->id}_{$key}", $value );
    }

    /**
     * Check if the current user has a Dish Dash capability.
     */
    protected function current_user_can( string $cap ): bool {
        return current_user_can( $cap );
    }

    /**
     * Enqueue a module CSS file from assets/css/.
     */
    protected function enqueue_style( string $handle, string $file, array $deps = [] ): void {
        wp_enqueue_style(
            "dish-dash-{$handle}",
            DD_ASSETS_URL . "css/{$file}",
            $deps,
            $this->version
        );
    }

    /**
     * Enqueue a module JS file from assets/js/.
     */
    protected function enqueue_script( string $handle, string $file, array $deps = [], bool $in_footer = true ): void {
        wp_enqueue_script(
            "dish-dash-{$handle}",
            DD_ASSETS_URL . "js/{$file}",
            $deps,
            $this->version,
            $in_footer
        );
    }

    /**
     * Send a JSON success response and exit.
     */
    protected function json_success( mixed $data = null, string $message = '' ): void {
        wp_send_json_success( [ 'data' => $data, 'message' => $message ] );
    }

    /**
     * Send a JSON error response and exit.
     */
    protected function json_error( string $message, int $code = 400 ): void {
        wp_send_json_error( [ 'message' => $message, 'code' => $code ], $code );
    }

    /**
     * Verify a nonce or die with a JSON error.
     */
    protected function verify_nonce( string $nonce_value, string $action ): void {
        if ( ! wp_verify_nonce( $nonce_value, $action ) ) {
            $this->json_error( __( 'Security check failed.', 'dish-dash' ), 403 );
        }
    }

    /**
     * Render a template file from /templates/.
     * Searches the active theme first (WooCommerce-style override).
     *
     * @param string $template  Relative path e.g. 'menu/grid.php'
     * @param array  $args      Variables to extract into the template scope
     */
    protected function render_template( string $template, array $args = [] ): void {
        // Allow themes to override templates.
        $theme_file  = get_stylesheet_directory() . '/dish-dash/' . $template;
        $plugin_file = DD_TEMPLATES_DIR . $template;

        $file = file_exists( $theme_file ) ? $theme_file : $plugin_file;

        if ( ! file_exists( $file ) ) {
            return;
        }

        // Extract variables into local scope.
        if ( $args ) {
            extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
        }

        include $file;
    }

    /**
     * Get rendered template as a string (for shortcode return).
     */
    protected function get_template( string $template, array $args = [] ): string {
        ob_start();
        $this->render_template( $template, $args );
        return ob_get_clean();
    }
}
