<?php
namespace AdorationScheduler\Tests\Integration\Core;

/** Verifies the real WordPress bootstrap still initializes the bundled API. */
class ActionSchedulerBootstrapIntegrationTest extends \WP_UnitTestCase
{
    public function test_standard_action_scheduler_lifecycle_initializes_the_api(): void
    {
        $this->assertSame('1.0.11', ADORATION_SCHEDULER_VERSION);
        $this->assertTrue(class_exists('ActionScheduler'));
        $this->assertTrue(function_exists('as_schedule_single_action'));
        $this->assertGreaterThan(0, did_action('action_scheduler_init'));
    }
}
