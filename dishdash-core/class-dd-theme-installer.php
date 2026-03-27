
<?php
/**
 * Dish Dash — Theme Installer
 *
 * Automatically installs and activates the bundled
 * Dish Dash blank theme when the plugin is activated.
 *
 * @package DishDash
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Theme_Installer {

    /**
     * Theme slug — matches the theme folder name.
     */
    const THEME_SLUG = 'dish-dash-theme';

    /**
     * Path to bundled theme inside the plugin.
     */
    private static function bundled_theme_path(): string {
        return DD_PLUGIN_DIR . 'theme/' . self::THEME_SLUG . '/';
    }

    /**
     * Path to WordPress themes directory.
     */
    private static function wp_themes_path(): string {
        return get_theme_root() . '/' . self::THEME_SLUG . '/';
    }

    /**
     * Run on plugin activation.
     * Installs and activates the theme automatically.
     */
    public static function on_plugin_activate(): void {
        self::install_theme();
        self::activate_theme();
        self::show_admin_notice();
    }

    /**
     * Check if the Dish Dash theme is the active theme.
     */
    public static function is_active(): bool {
        return get_stylesheet() === self::THEME_SLUG
            || get_template() === self::THEME_SLUG;
    }

    /**
     * Check if the theme is installed (exists in themes directory).
     */
    public static function is_installed(): bool {
        return is_dir( self::wp_themes_path() );
    }

    /**
     * Copy the bundled theme to the WordPress themes directory.
     */
    public static function install_theme(): bool {
        if ( self::is_installed() ) {
            // Update it — copy fresh files
            self::copy_theme_files(
                self::bundled_theme_path(),
                self::wp_themes_path()
            );
            return true;
        }

        // Fresh install
        if ( ! is_dir( self::bundled_theme_path() ) ) {
            return false;
        }

        wp_mkdir_p( self::wp_themes_path() );
        self::copy_theme_files(
            self::bundled_theme_path(),
            self::wp_themes_path()
        );

        return true;
    }

    /**
     * Activate the Dish Dash theme.
     */
    public static function activate_theme(): bool {
        if ( ! self::is_installed() ) return false;
        if ( self::is_active() ) return true;

        // Switch theme
        switch_theme( self::THEME_SLUG );

        return self::is_active();
    }

    /**
     * Recursively copy theme files from plugin to themes directory.
     */
    private static function copy_theme_files( string $source, string $dest ): void {
        if ( ! is_dir( $source ) ) return;

        wp_mkdir_p( $dest );

        $files = scandir( $source );
        foreach ( $files as $file ) {
            if ( $file === '.' || $file === '..' ) continue;

            $src_path  = $source . $file;
            $dest_path = $dest . $file;

            if ( is_dir( $src_path ) ) {
                self::copy_theme_files( $src_path . '/', $dest_path . '/' );
            } else {
                copy( $src_path, $dest_path );
            }
        }
    }

    /**
     * Show admin notice after theme activation.
     */
    private static function show_admin_notice(): void {
        set_transient( 'dd_theme_activated', true, 30 );
    }

    /**
     * Display admin notice that theme was installed.
     */
    public static function admin_notice(): void {
        if ( ! get_transient( 'dd_theme_activated' ) ) return;
        delete_transient( 'dd_theme_activated' );
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                ✅ <strong>Dish Dash:</strong>
                The <strong>Dish Dash Theme</strong> has been automatically installed and activated.
                Your site is now using the official Dish Dash theme — zero conflicts guaranteed.
            </p>
        </div>
        <?php
    }

    /**
     * Show warning if a different theme is active.
     */
    public static function wrong_theme_notice(): void {
        if ( self::is_active() ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $activate_url = wp_nonce_url(
            admin_url( 'admin.php?page=dish-dash&dd_action=activate_theme' ),
            'dd_activate_theme'
        );
        ?>
        <div class="notice notice-warning">
            <p>
                ⚠️ <strong>Dish Dash:</strong>
                You are using <strong><?php echo esc_html( wp_get_theme()->get('Name') ); ?></strong> as your active theme.
                For the best experience with zero conflicts, we recommend using the
                <strong>Dish Dash Theme</strong>.
                <a href="<?php echo esc_url( $activate_url ); ?>" class="button button-primary" style="margin-left:10px;">
                    Activate Dish Dash Theme
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Handle theme activation from admin URL.
     */
    public static function handle_activate_action(): void {
        if ( ! isset( $_GET['dd_action'] ) || $_GET['dd_action'] !== 'activate_theme' ) return;
        if ( ! check_admin_referer( 'dd_activate_theme' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        self::install_theme();
        self::activate_theme();

        wp_redirect( admin_url( 'admin.php?page=dish-dash&dd_theme_activated=1' ) );
        exit;
    }

    /**
     * Keep theme files in sync with plugin updates.
     * Runs whenever the plugin updates.
     */
    public static function sync_theme_on_plugin_update(): void {
        if ( ! self::is_active() ) return;
        self::install_theme(); // Re-copy files to update theme
    }
}
