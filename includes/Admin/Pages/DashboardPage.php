<?php
namespace AdorationScheduler\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DashboardPage {
    public function render(): void {
        echo '<div class="wrap"><h1>Adoration Scheduler</h1></div>';
    }
}
