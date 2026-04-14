<?php
/**
 * File:    dishdash-core/class-dd-github-updater.php
 * Module:  DD_GitHub_Updater
 * Purpose: Hooks into WordPress's plugin update system and checks GitHub
 *          Releases for newer versions. Enables one-click updates from
 *          Plugins → Update Now without a WordPress.org listing.
 *
 * Dependencies (this file needs):
 *   - DD_GITHUB_REPO constant (set in dish-dash.php)
 *   - DD_GITHUB_TOKEN constant (optional, for private repos)
 *   - WordPress plugin update transients (core)
 *
 * Dependents (files that need this):
 *   - dish-dash.php (requires this file directly + calls $dd_updater->init())
 *
 * Hooks registered:
 *   - pre_set_site_transient_update_plugins  (check for new version)
 *   - plugins_api                            (return package info)
 *   - upgrader_process_complete              (opcache reset after update)
 *   - admin_init                             (manual "Check for Updates" trigger)
 *
 * Last modified: v3.1.13
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DD_GitHub_Updater {

    /** @var string GitHub repository in format 'username/repo' */
    private string $repo;

    /** @var string GitHub Personal Access Token (for private repos) */
    private string $token;

    /** @var string Plugin slug (folder/file.php) */
    private string $plugin_slug;

    /** @var string Plugin basename */
    private string $basename;

    /** @var object|null Cached release data from GitHub */
    private ?object $github_data = null;

    /**
     * @param string $repo    GitHub repo e.g. 'frisoftltd/dish-dash'
     * @param string $token   GitHub PAT for private repos (empty for public)
     */
    public function __construct( string $repo, string $token = '' ) {
        $this->repo        = $repo;
        $this->token       = $token;
        $this->basename    = DD_PLUGIN_BASENAME;
        $this->plugin_slug = dirname( DD_PLUGIN_BASENAME );
    }

    /**
     * Boot — register all WordPress update hooks.
     */
    public function init(): void {
        // Hook into WordPress update checker
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );

        // Provide plugin info for the "View Details" popup
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );

        // Fix the folder name after GitHub zip extraction
        // GitHub zips extract to 'repo-tag/' but WP expects 'dish-dash/'
        add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );

        // Show current GitHub version on the plugins page
        add_filter( 'plugin_row_meta', [ $this, 'plugin_row_meta' ], 10, 2 );

        // Add "Check for Updates" link on plugins page
        add_filter( 'plugin_action_links_' . $this->basename, [ $this, 'add_check_update_link' ] );
    }

    // ─────────────────────────────────────────
    //  1. CHECK FOR UPDATE
    //     Called every time WordPress checks
    //     for plugin updates.
    // ─────────────────────────────────────────
    public function check_for_update( object $transient ): object {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if ( ! $release ) {
            return $transient;
        }

        $latest_version  = ltrim( $release->tag_name, 'v' ); // strip leading 'v'
        $current_version = $transient->checked[ $this->basename ] ?? DD_VERSION;

        if ( version_compare( $latest_version, $current_version, '>' ) ) {
            $transient->response[ $this->basename ] = (object) [
                'id'          => $this->basename,
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->basename,
                'new_version' => $latest_version,
                'url'         => "https://github.com/{$this->repo}",
                'package'     => $this->get_download_url( $release ),
                'icons'       => [],
                'banners'     => [],
                'tested'      => '6.5',
                'requires_php' => '8.0',
                'compatibility' => new stdClass(),
            ];
        }

        return $transient;
    }

    // ─────────────────────────────────────────
    //  2. PLUGIN INFO POPUP
    //     Fills the "View Details" modal in WP
    // ─────────────────────────────────────────
    public function plugin_info( mixed $result, string $action, object $args ): mixed {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
            return $result;
        }

        $release = $this->get_latest_release();

        if ( ! $release ) {
            return $result;
        }

        $latest_version = ltrim( $release->tag_name, 'v' );

        return (object) [
            'name'          => 'Dish Dash',
            'slug'          => $this->plugin_slug,
            'version'       => $latest_version,
            'author'        => '<a href="https://frisoftltd.com">Fri Soft Ltd</a>',
            'homepage'      => "https://github.com/{$this->repo}",
            'requires'      => '6.0',
            'tested'        => '6.5',
            'requires_php'  => '8.0',
            'last_updated'  => $release->published_at ?? '',
            'sections'      => [
                'description' => '<p>A complete restaurant ordering &amp; management system built on WordPress and WooCommerce.</p>',
                'changelog'   => $this->format_changelog( $release->body ?? '' ),
            ],
            'download_link' => $this->get_download_url( $release ),
            'banners'       => [],
            'icons'         => [],
        ];
    }

    // ─────────────────────────────────────────
    //  3. FIX EXTRACTED FOLDER NAME
    //     GitHub zips extract as 'dish-dash-1.0.1/'
    //     WP needs it as 'dish-dash/'
    // ─────────────────────────────────────────
    public function fix_source_dir( string $source, string $remote_source, object $upgrader, array $hook_extra ): string {
        // Only apply to our plugin
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
            return $source;
        }

        $corrected = trailingslashit( $remote_source ) . $this->plugin_slug . '/';

        // If the extracted folder doesn't match expected slug, rename it
        if ( $source !== $corrected ) {
            global $wp_filesystem;
            if ( $wp_filesystem->move( $source, $corrected ) ) {
                return $corrected;
            }
        }

        return $source;
    }

    // ─────────────────────────────────────────
    //  4. PLUGIN ROW META
    //     Shows GitHub version in plugins list
    // ─────────────────────────────────────────
    public function plugin_row_meta( array $links, string $file ): array {
        if ( $file !== $this->basename ) {
            return $links;
        }

        $links[] = sprintf(
            '<a href="https://github.com/%s" target="_blank">GitHub ↗</a>',
            esc_attr( $this->repo )
        );

        return $links;
    }

    // ─────────────────────────────────────────
    //  5. CHECK FOR UPDATES LINK
    //     Adds "Check for Updates" action link
    // ─────────────────────────────────────────
    public function add_check_update_link( array $links ): array {
        $check_url = wp_nonce_url(
            add_query_arg( [
                'dd_check_update' => '1',
                'plugin'          => $this->basename,
            ], admin_url( 'plugins.php' ) ),
            'dd_check_update'
        );

        $links[] = '<a href="' . esc_url( $check_url ) . '">'
            . esc_html__( 'Check for Updates', 'dish-dash' )
            . '</a>';

        return $links;
    }

    // ─────────────────────────────────────────
    //  GITHUB API HELPERS
    // ─────────────────────────────────────────

    /**
     * Fetch the latest release from GitHub API.
     * Results are cached for 6 hours to avoid rate limiting.
     */
    private function get_latest_release(): ?object {
        if ( $this->github_data ) {
            return $this->github_data;
        }

        $cache_key = 'dd_github_release_' . md5( $this->repo );
        $cached    = get_transient( $cache_key );

        if ( $cached ) {
            $this->github_data = $cached;
            return $cached;
        }

        $url      = "https://api.github.com/repos/{$this->repo}/releases/latest";
        $args     = [
            'timeout' => 15,
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'Dish-Dash-WordPress-Plugin/' . DD_VERSION,
            ],
        ];

        // Add auth header for private repos
        if ( $this->token ) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->token;
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            dd_log( 'GitHub updater error: ' . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== (int) $code ) {
            dd_log( "GitHub updater: API returned HTTP {$code} for {$this->repo}" );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body );

        if ( ! $data || ! isset( $data->tag_name ) ) {
            return null;
        }

        // Cache for 6 hours
        set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );

        $this->github_data = $data;
        return $data;
    }

    /**
     * Get the download URL for the release zip.
     * Uses the first asset if available, falls back to source zip.
     */
    private function get_download_url( object $release ): string {
        // If there's a compiled release asset (e.g. dish-dash.zip), use it
        if ( ! empty( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( str_ends_with( $asset->name, '.zip' ) ) {
                    $url = $asset->browser_download_url;
                    // For private repos, add token
                    if ( $this->token ) {
                        $url = add_query_arg( 'access_token', $this->token, $url );
                    }
                    return $url;
                }
            }
        }

        // Fall back to GitHub's auto-generated source zip
        return "https://github.com/{$this->repo}/archive/refs/tags/{$release->tag_name}.zip";
    }

    /**
     * Format release notes (markdown) as basic HTML for the changelog tab.
     */
    private function format_changelog( string $markdown ): string {
        if ( empty( $markdown ) ) {
            return '<p>' . esc_html__( 'No changelog provided.', 'dish-dash' ) . '</p>';
        }

        // Very basic markdown → HTML conversion
        $html = esc_html( $markdown );
        $html = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $html );
        $html = preg_replace( '/^## (.+)$/m',  '<h3>$1</h3>', $html );
        $html = preg_replace( '/^# (.+)$/m',   '<h2>$1</h2>', $html );
        $html = preg_replace( '/^\* (.+)$/m',  '<li>$1</li>', $html );
        $html = preg_replace( '/^- (.+)$/m',   '<li>$1</li>', $html );
        $html = '<p>' . nl2br( $html ) . '</p>';

        return $html;
    }

    /**
     * Force-clear the cached release data.
     * Called when admin clicks "Check for Updates".
     */
    public function clear_cache(): void {
        delete_transient( 'dd_github_release_' . md5( $this->repo ) );
        $this->github_data = null;
    }
}
