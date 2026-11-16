<?php
namespace AdorationScheduler\Tests\Unit\Regression;

use PHPUnit\Framework\TestCase;

/** Prevents the unsafe 1.0.10 Action Scheduler bootstrap from returning. */
final class ActionSchedulerBootstrapTest extends TestCase
{
    public function test_plugin_does_not_manually_initialize_action_scheduler(): void
    {
        $bootstrap = file_get_contents(dirname(__DIR__, 3) . '/adoration-scheduler.php');

        $this->assertIsString($bootstrap);
        $this->assertStringNotContainsString('action_scheduler_initialize()', $bootstrap);
        $this->assertStringNotContainsString('ActionScheduler::init(', $bootstrap);
        $this->assertStringContainsString("class_exists('ActionScheduler_Versions', false)", $bootstrap);
        $this->assertStringContainsString("libraries/action-scheduler/", $bootstrap);
    }
}
