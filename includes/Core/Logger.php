<?php

namespace AdorationScheduler\Core;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Internal diagnostic logging with an explicit opt-in production gate.
 */
final class Logger
{
    public static function error(string $message): void
    {
        $enabled = (defined('WP_DEBUG') && WP_DEBUG)
            || (defined('ADORATION_SCHEDULER_LOG_ERRORS') && ADORATION_SCHEDULER_LOG_ERRORS);

        if (!$enabled) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- centralized, explicitly gated diagnostic logging.
        error_log($message);
    }
}
