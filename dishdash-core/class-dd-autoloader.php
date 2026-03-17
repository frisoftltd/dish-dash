<?php
/**
 * DD_Autoloader
 *
 * PSR-4-style autoloader for Dish Dash classes.
 *
 * Naming convention:
 *   Class name:  DD_Menu_CPT
 *   File name:   class-dd-menu-cpt.php
 *   Location:    anywhere under DD_PATH (searched recursively via the map)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Autoloader {

    /** @var array<string,string>  class => absolute file path */
    private static array $map = [];

    /**
     * Register the autoloader with PHP's SPL stack and build the class map.
     */
    public static function register(): void {
        // Always load the requirements class first (it was required manually).
        // Build the rest of the map now.
        self::build_map();

        spl_autoload_register( [ __CLASS__, 'load' ] );
    }

    /**
     * Called by PHP when an unknown class is used.
     */
    public static function load( string $class ): void {
        if ( isset( self::$map[ $class ] ) ) {
            require_once self::$map[ $class ];
        }
    }

    /**
     * Build a flat class → file map by scanning known directories.
     */
    private static function build_map(): void {
        $dirs = [
            DD_PATH . 'dishdash-core/',
            DD_PATH . 'admin/',
            DD_PATH . 'modules/menu/',
            DD_PATH . 'modules/orders/',
            DD_PATH . 'modules/delivery/',
            DD_PATH . 'modules/reservations/',
            DD_PATH . 'modules/pos/',
            DD_PATH . 'modules/analytics/',
            DD_PATH . 'modules/branches/',
            DD_PATH . 'modules/customers/',
            DD_PATH . 'modules/notifications/',
            DD_PATH . 'api/',
            DD_PATH . 'api/endpoints/',
            DD_PATH . 'frontend/',
        ];

        foreach ( $dirs as $dir ) {
            if ( ! is_dir( $dir ) ) continue;

            foreach ( glob( $dir . 'class-*.php' ) as $file ) {
                $class = self::filename_to_classname( basename( $file ) );
                if ( $class ) {
                    self::$map[ $class ] = $file;
                }
            }
        }
    }

    /**
     * Convert  class-dd-menu-cpt.php  →  DD_Menu_CPT
     */
    private static function filename_to_classname( string $filename ): string {
        // Strip "class-" prefix and ".php" suffix.
        $name = preg_replace( '/^class-/i', '', $filename );
        $name = preg_replace( '/\.php$/i', '', $name );

        if ( ! $name ) return '';

        // "dd-menu-cpt" → ["dd","menu","cpt"] → "DD_Menu_CPT"
        $parts = explode( '-', $name );
        return implode( '_', array_map( 'strtoupper', $parts ) );
    }
}
