<?php
namespace AdorationScheduler\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Autoloader {

    public static function register(): void {
        spl_autoload_register( [ __CLASS__, 'autoload' ] );
    }

    private static function autoload( string $class ): void {
        $prefix = 'AdorationScheduler\\';

        if ( strpos( $class, $prefix ) !== 0 ) {
            return;
        }

        $relative = substr( $class, strlen( $prefix ) );
        $relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );

        $file = ADORATION_SCHEDULER_DIR . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}
