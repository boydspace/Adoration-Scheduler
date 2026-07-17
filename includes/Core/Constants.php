<?php
namespace AdorationScheduler\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

// Bump this anytime you change DB schema (tables/columns/indexes)
if ( ! defined( 'ADORATION_SCHEDULER_DB_VERSION' ) ) {
    define( 'ADORATION_SCHEDULER_DB_VERSION', '0.5.1' );
}

if ( ! defined( 'ADORATION_SCHEDULER_DB_VERSION_OPTION' ) ) {
    define( 'ADORATION_SCHEDULER_DB_VERSION_OPTION', 'adoration_scheduler_db_version' );
}
