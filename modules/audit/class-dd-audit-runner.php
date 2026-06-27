<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Audit_Runner {

    private string $plugin_dir;
    private string $assets_js;
    private string $assets_css;
    private string $modules_dir;
    private string $templates_dir;

    public function __construct() {
        $this->plugin_dir    = DD_PLUGIN_DIR;
        $this->assets_js     = DD_PLUGIN_DIR . 'assets/js/';
        $this->assets_css    = DD_PLUGIN_DIR . 'assets/css/';
        $this->modules_dir   = DD_PLUGIN_DIR . 'modules/';
        $this->templates_dir = DD_PLUGIN_DIR . 'templates/';
    }

    public function run_all(): array {
        return [
            'version'  => DD_VERSION,
            'ran_at'   => current_time( 'mysql' ),
            'pillars'  => [
                'p1' => $this->pillar_1_mission(),
                'p2' => $this->pillar_2_architecture(),
                'p3' => $this->pillar_3_data_integrity(),
                'p4' => $this->pillar_4_performance(),
                'p5' => $this->pillar_5_security(),
                'p6' => $this->pillar_6_code_quality(),
            ],
        ];
    }

    // ─── P1: Mission Alignment ────────────────────────────────────────────────

    private function pillar_1_mission(): array {
        $checks = [];

        // event-schemas.php is at modules/tracking/event-schemas.php
        $schema_file = $this->plugin_dir . 'modules/tracking/event-schemas.php';
        if ( file_exists( $schema_file ) ) {
            $checks[] = $this->check( true, 'event-schemas.php found', 'Schema file exists at modules/tracking/event-schemas.php' );
        } else {
            $checks[] = $this->check( false, 'event-schemas.php missing', 'No schema file at modules/tracking/event-schemas.php' );
        }

        // Scan all JS files for DDTrack.track() — wrong method name
        $js_files    = glob( $this->assets_js . '*.js' ) ?: [];
        $js_files    = array_merge( $js_files, glob( $this->modules_dir . '*/*.js' ) ?: [] );
        $wrong_calls = [];
        foreach ( $js_files as $f ) {
            $code = file_get_contents( $f );
            if ( strpos( $code, 'DDTrack.track(' ) !== false ) {
                $wrong_calls[] = basename( $f );
            }
        }
        if ( empty( $wrong_calls ) ) {
            $checks[] = $this->check( true, 'No DDTrack.track() calls', 'All JS uses DDTrack.event() correctly' );
        } else {
            $checks[] = $this->check( false, 'DDTrack.track() calls found', 'Wrong method in: ' . implode( ', ', $wrong_calls ) );
        }

        // Scan for DDTrack.event( usage
        $event_calls = [];
        foreach ( $js_files as $f ) {
            $code = file_get_contents( $f );
            preg_match_all( "/DDTrack\.event\(\s*['\"]([^'\"]+)['\"]/", $code, $m );
            $event_calls = array_merge( $event_calls, $m[1] ?? [] );
        }
        $event_calls = array_unique( $event_calls );
        $checks[] = $this->check(
            ! empty( $event_calls ),
            count( $event_calls ) . ' DDTrack.event() call(s) found',
            empty( $event_calls ) ? 'No tracking events found in JS' : 'Events tracked: ' . implode( ', ', $event_calls )
        );

        return $this->pillar_result( 'P1', 'Mission Alignment', $checks );
    }

    // ─── P2: Architecture ─────────────────────────────────────────────────────

    private function pillar_2_architecture(): array {
        $checks = [];

        // Minification removed — .min files should NOT exist (LiteSpeed handles compression).
        $min_js  = glob( $this->assets_js . '*.min.js' ) ?: [];
        $min_css = glob( $this->assets_css . '*.min.css' ) ?: [];
        $min_all = array_merge( $min_js, $min_css );

        if ( empty( $min_all ) ) {
            $checks[] = $this->check( true, 'No .min files (minification removed)', 'Assets served unminified; LiteSpeed Cache handles compression' );
        } else {
            $names = array_map( 'basename', $min_all );
            $checks[] = $this->check( false, count( $min_all ) . ' stale .min file(s) found', implode( ', ', $names ) . ' — should be removed' );
        }

        // Check DD_VERSION constant vs plugin header
        $plugin_file    = $this->plugin_dir . 'dish-dash.php';
        $plugin_content = file_exists( $plugin_file ) ? file_get_contents( $plugin_file ) : '';
        $const_version  = '';
        $header_version = '';

        if ( preg_match( "/define\s*\(\s*'DD_VERSION'\s*,\s*'([^']+)'/", $plugin_content, $m ) ) {
            $const_version = $m[1];
        }
        if ( preg_match( "/\*\s+Version:\s+([^\n]+)/", $plugin_content, $m ) ) {
            $header_version = trim( $m[1] );
        }

        $versions_match = ( $const_version !== '' && $const_version === $header_version );
        $checks[] = $this->check(
            $versions_match,
            'Version consistency: header=' . ( $header_version ?: '?' ) . ' constant=' . ( $const_version ?: '?' ),
            $versions_match ? 'Both version declarations match' : 'MISMATCH — update both locations in dish-dash.php'
        );

        // Check all module classes extend DD_Module
        $module_files = glob( $this->modules_dir . '*/class-dd-*-module.php' ) ?: [];
        $bad_modules  = [];
        foreach ( $module_files as $f ) {
            $code      = file_get_contents( $f );
            $classname = '';
            if ( preg_match( '/class\s+(DD_\w+)\s+/', $code, $m ) ) {
                $classname = $m[1];
            }
            if ( $classname && strpos( $code, 'extends DD_Module' ) === false ) {
                $bad_modules[] = basename( $f );
            }
        }
        if ( empty( $bad_modules ) ) {
            $checks[] = $this->check( true, 'All modules extend DD_Module', count( $module_files ) . ' module(s) checked' );
        } else {
            $checks[] = $this->check( false, 'Modules not extending DD_Module', implode( ', ', $bad_modules ) );
        }

        return $this->pillar_result( 'P2', 'Architecture', $checks );
    }

    // ─── P3: Data Integrity ───────────────────────────────────────────────────

    private function pillar_3_data_integrity(): array {
        global $wpdb;
        $checks = [];

        // Check required tables exist
        $required_tables = [
            'wp_dishdash_customers',
            'wp_dishdash_user_profiles',
            'wp_dishdash_tracking_events',
        ];

        foreach ( $required_tables as $table ) {
            $real_table = str_replace( 'wp_', $wpdb->prefix, $table );
            $exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $real_table ) ) === $real_table;
            $checks[]   = $this->check( $exists, 'Table: ' . $real_table, $exists ? 'Exists' : 'MISSING — run install function' );
        }

        // Check for ALTER TABLE without DESCRIBE/SHOW COLUMNS guard in PHP
        $php_files = $this->find_php_files( $this->plugin_dir );
        $unguarded = [];
        foreach ( $php_files as $f ) {
            $code = file_get_contents( $f );
            if ( strpos( $code, 'ALTER TABLE' ) !== false ) {
                $lines = explode( "\n", $code );
                foreach ( $lines as $i => $line ) {
                    if ( strpos( $line, 'ALTER TABLE' ) !== false ) {
                        $context = implode( "\n", array_slice( $lines, max( 0, $i - 10 ), 20 ) );
                        if ( strpos( $context, 'SHOW COLUMNS' ) === false
                          && strpos( $context, 'DESCRIBE' ) === false
                          && strpos( $context, 'SHOW INDEX' ) === false ) {
                            $unguarded[] = basename( $f ) . ':' . ( $i + 1 );
                        }
                    }
                }
            }
        }
        if ( empty( $unguarded ) ) {
            $checks[] = $this->check( true, 'ALTER TABLE statements are guarded', 'All ALTER TABLE has adjacent SHOW COLUMNS / DESCRIBE / SHOW INDEX guard' );
        } else {
            $checks[] = $this->check( false, 'Unguarded ALTER TABLE found', implode( ', ', $unguarded ) );
        }

        // Check for customer/user deletion capability
        $has_delete = false;
        foreach ( $php_files as $f ) {
            $code = file_get_contents( $f );
            if ( strpos( $code, 'delete_customer' ) !== false || strpos( $code, 'gdpr' ) !== false ) {
                $has_delete = true;
                break;
            }
        }
        $checks[] = $this->check( $has_delete, 'Customer deletion / GDPR function', $has_delete ? 'Found' : 'No GDPR deletion capability detected' );

        return $this->pillar_result( 'P3', 'Data Integrity', $checks );
    }

    // ─── P4: Performance ──────────────────────────────────────────────────────

    private function pillar_4_performance(): array {
        $checks = [];

        // Admin-only assets never load on the customer-facing site — exclude them.
        $admin_only = [
            'admin', 'audit', 'analytics', 'analytics-reservations',
            'dashboard', 'reservations-admin',
        ];

        $is_admin_asset = function ( string $path ) use ( $admin_only ): bool {
            $base = preg_replace( '/\.(js|css)$/', '', basename( $path ) );
            return in_array( $base, $admin_only, true );
        };

        $frontend_assets = array_filter(
            array_merge(
                glob( $this->assets_js . '*.js' ) ?: [],
                glob( $this->assets_css . '*.css' ) ?: []
            ),
            fn( $f ) => ! str_ends_with( $f, '.min.js' )
                    && ! str_ends_with( $f, '.min.css' )
                    && ! $is_admin_asset( $f )
        );

        $large_files = [];
        $total_js    = 0;
        $total_css   = 0;

        foreach ( $frontend_assets as $f ) {
            $size = filesize( $f );
            $ext  = pathinfo( $f, PATHINFO_EXTENSION );
            if ( $ext === 'js' )  $total_js  += $size;
            if ( $ext === 'css' ) $total_css += $size;
            // 100KB per-file threshold for uncompressed source (LiteSpeed gzips ~80% in production).
            if ( $size > 102400 ) {
                $large_files[] = basename( $f ) . ' (' . round( $size / 1024, 1 ) . 'KB)';
            }
        }

        if ( empty( $large_files ) ) {
            $checks[] = $this->check( true, 'No frontend asset over 100KB', 'All customer-facing assets within budget (pre-compression)' );
        } else {
            $checks[] = $this->check( false, 'Large frontend assets found', implode( ', ', $large_files ) );
        }

        // 300KB uncompressed frontend budget — LiteSpeed compresses to ~60KB over the wire.
        $total_kb = round( ( $total_js + $total_css ) / 1024, 1 );
        $checks[] = $this->check(
            $total_kb <= 300,
            'Frontend payload: ' . $total_kb . 'KB (pre-compression)',
            $total_kb <= 300 ? 'Within budget — LiteSpeed gzips ~80% in production' : 'Over budget — review frontend assets'
        );

        // Check is_dishdash_page() guard
        $guard_found = false;
        $php_files   = $this->find_php_files( $this->modules_dir );
        foreach ( $php_files as $f ) {
            if ( strpos( file_get_contents( $f ), 'is_dishdash_page' ) !== false ) {
                $guard_found = true;
                break;
            }
        }
        $checks[] = $this->check( $guard_found, 'is_dishdash_page() guard', $guard_found ? 'Found — assets load conditionally' : 'Missing — assets may load on every page' );

        // Minification removed — passing state is no .min files shadowing source.
        $min_js  = glob( $this->assets_js . '*.min.js' ) ?: [];
        $min_css = glob( $this->assets_css . '*.min.css' ) ?: [];
        $no_min  = empty( $min_js ) && empty( $min_css );
        $checks[] = $this->check(
            $no_min,
            'No stale .min files shadowing source',
            $no_min ? 'Clean — assets served unminified' : 'Stale .min files exist — remove them'
        );

        return $this->pillar_result( 'P4', 'Performance', $checks );
    }

    // ─── P5: Security ─────────────────────────────────────────────────────────

    private function pillar_5_security(): array {
        $checks = [];

        $php_files     = $this->find_php_files( $this->plugin_dir );
        $ajax_handlers = [];
        $missing_nonce = [];

        foreach ( $php_files as $f ) {
            $code = file_get_contents( $f );
            preg_match_all( "/wp_ajax_nopriv_([a-z_]+)|wp_ajax_([a-z_]+)/", $code, $m );
            $handlers = array_filter( array_merge( $m[1], $m[2] ) );
            foreach ( $handlers as $h ) {
                $ajax_handlers[] = $h;
                if ( strpos( $code, 'check_ajax_referer' ) === false && strpos( $code, 'verify_nonce' ) === false ) {
                    $missing_nonce[] = $h . ' (' . basename( $f ) . ')';
                }
            }
        }

        $ajax_handlers = array_unique( $ajax_handlers );
        if ( empty( $missing_nonce ) ) {
            $checks[] = $this->check( true, count( $ajax_handlers ) . ' AJAX handler(s) — all have nonce checks', implode( ', ', $ajax_handlers ) );
        } else {
            $checks[] = $this->check( false, 'AJAX handlers without nonce', implode( ', ', array_unique( $missing_nonce ) ) );
        }

        // Check wp-config debug flags
        if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
            $checks[] = $this->check( ! WP_DEBUG, 'WP_DEBUG', WP_DEBUG ? 'TRUE — disable in production' : 'false ✓' );
            $checks[] = $this->check( ! ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ), 'WP_DEBUG_LOG', ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ? 'TRUE — disable in production' : 'false or not set ✓' );
            $edit_off = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT === true;
            $checks[] = $this->check( $edit_off, 'DISALLOW_FILE_EDIT', $edit_off ? 'true ✓' : 'Not set — add to wp-config.php' );
        } else {
            $checks[] = $this->check( false, 'wp-config.php not readable', 'Cannot verify security constants' );
        }

        // Scan for $wpdb queries with variable interpolation (not wrapped in prepare())
        $unescaped = [];
        foreach ( $php_files as $f ) {
            $code = file_get_contents( $f );
            preg_match_all(
                '/\$wpdb->(?:query|get_results|get_var|get_row)\s*\(\s*["\'][^"\']*\$[a-zA-Z_]/',
                $code,
                $m,
                PREG_OFFSET_CAPTURE
            );
            if ( ! empty( $m[0] ) ) {
                $unescaped[] = basename( $f );
            }
        }
        $unescaped = array_unique( $unescaped );
        if ( empty( $unescaped ) ) {
            $checks[] = $this->check( true, 'No unescaped $wpdb queries detected', 'All queries appear to use prepare()' );
        } else {
            $checks[] = $this->check( false, 'Possible unescaped queries', implode( ', ', $unescaped ) . ' — review manually' );
        }

        return $this->pillar_result( 'P5', 'Security', $checks );
    }

    // ─── P6: AI Code Quality ──────────────────────────────────────────────────

    private function pillar_6_code_quality(): array {
        $checks = [];

        // Scan templates for echo $ without escaping
        $template_files = $this->find_php_files( $this->templates_dir );
        $unescaped_echo = [];
        foreach ( $template_files as $f ) {
            $code = file_get_contents( $f );
            preg_match_all( '/echo\s+\$(?!_)[\w\[\'"\]]+\s*;/', $code, $m );
            if ( ! empty( $m[0] ) ) {
                $unescaped_echo[] = basename( $f ) . ' (' . count( $m[0] ) . ' instance(s))';
            }
        }
        if ( empty( $unescaped_echo ) ) {
            $checks[] = $this->check( true, 'Templates: no unescaped echo', 'All template output appears to use esc_*()' );
        } else {
            $checks[] = $this->check( false, 'Unescaped echo in templates', implode( ', ', $unescaped_echo ) );
        }

        // Scan for esc_url() on WhatsApp wa.me links (should be esc_attr)
        $all_php     = $this->find_php_files( $this->plugin_dir );
        $bad_walinks = [];
        foreach ( $all_php as $f ) {
            $code = file_get_contents( $f );
            if ( preg_match( '/esc_url\s*\([^)]*wa\.me/', $code ) ) {
                $bad_walinks[] = basename( $f );
            }
        }
        if ( empty( $bad_walinks ) ) {
            $checks[] = $this->check( true, 'WhatsApp URLs: esc_attr() used correctly', 'No esc_url() on wa.me links' );
        } else {
            $checks[] = $this->check( false, 'esc_url() on wa.me links', implode( ', ', $bad_walinks ) . ' — use esc_attr() instead' );
        }

        // Scan for remove_submenu_page() — capability removal anti-pattern
        $bad_remove = [];
        foreach ( $all_php as $f ) {
            if ( basename( $f ) === 'class-dd-audit-runner.php' ) continue; // skip self
            $code = file_get_contents( $f );
            if ( strpos( $code, 'remove_submenu_page' ) !== false ) {
                $bad_remove[] = basename( $f );
            }
        }
        if ( empty( $bad_remove ) ) {
            $checks[] = $this->check( true, 'No remove_submenu_page() calls', 'No capability-revoking submenu removals' );
        } else {
            $checks[] = $this->check( false, 'remove_submenu_page() found', implode( ', ', $bad_remove ) . ' — use CSS-only hiding instead' );
        }

        // Scan for eval() or exec()
        $dangerous = [];
        foreach ( $all_php as $f ) {
            if ( basename( $f ) === 'class-dd-audit-runner.php' ) continue; // skip self
            $code = file_get_contents( $f );
            if ( preg_match( '/\beval\s*\(|\bexec\s*\(/', $code ) ) {
                $dangerous[] = basename( $f );
            }
        }
        $checks[] = $this->check( empty( $dangerous ), 'No eval() / exec() calls', empty( $dangerous ) ? 'Clean' : 'Found in: ' . implode( ', ', $dangerous ) );

        return $this->pillar_result( 'P6', 'AI Code Quality', $checks );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function check( bool $pass, string $label, string $detail = '' ): array {
        return [
            'pass'   => $pass,
            'label'  => $label,
            'detail' => $detail,
        ];
    }

    private function pillar_result( string $id, string $name, array $checks ): array {
        $total  = count( $checks );
        $passed = count( array_filter( $checks, fn( $c ) => $c['pass'] ) );
        $score  = $total > 0 ? (int) round( ( $passed / $total ) * 100 ) : 0;

        if ( $score === 100 ) $status = 'green';
        elseif ( $score >= 75 ) $status = 'yellow';
        elseif ( $score >= 50 ) $status = 'orange';
        else $status = 'red';

        return [
            'id'     => $id,
            'name'   => $name,
            'score'  => $score,
            'status' => $status,
            'passed' => $passed,
            'total'  => $total,
            'checks' => $checks,
        ];
    }

    private function find_php_files( string $dir ): array {
        $results = [];
        if ( ! is_dir( $dir ) ) return $results;
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
        foreach ( $iterator as $file ) {
            if ( $file->getExtension() === 'php' ) {
                $results[] = $file->getPathname();
            }
        }
        return $results;
    }
}
