<?php
namespace AdorationScheduler\Tests\Integration\Domain\Repositories;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Tests\Support\AdorationIntegrationTestCase;

class SchedulesRepositoryIntegrationTest extends AdorationIntegrationTestCase
{
    public function test_admin_list_returns_schedules_when_date_filters_are_blank(): void
    {
        global $wpdb;

        $chapel_id = $this->make_chapel();
        $schedule_id = $this->make_schedule($chapel_id, [
            'name'       => 'November 2026 Adoration',
            'type'       => 'monthly',
            'start_date' => null,
            'end_date'   => null,
        ]);

        $wpdb->last_error = '';
        $result = (new SchedulesRepository())->admin_list();

        $this->assertSame('', $wpdb->last_error, 'The unfiltered schedules query should not produce a database error.');
        $this->assertGreaterThanOrEqual(1, $result['total']);
        $this->assertContains($schedule_id, array_map('intval', array_column($result['items'], 'id')));
    }
}
