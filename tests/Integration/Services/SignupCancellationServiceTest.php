<?php
namespace AdorationScheduler\Tests\Integration\Services;

use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Services\SignupCancellationService;
use AdorationScheduler\Tests\Support\AdorationIntegrationTestCase;

/**
 * Exercises SignupCancellationService::cancel_signup() directly against
 * real fixture rows — the public entry point, handle(), always terminates
 * via exit() (see finish_redirect()), which isn't practical to run under
 * PHPUnit, hence testing the (now public) cancel_signup() core logic
 * instead. See that method's docblock.
 */
class SignupCancellationServiceTest extends AdorationIntegrationTestCase
{
    private function make_confirmed_signup(): array
    {
        $chapel_id   = $this->make_chapel();
        $schedule_id = $this->make_schedule($chapel_id);
        $slot_id     = $this->make_slot($schedule_id, $chapel_id);
        $person_id   = $this->make_person();

        $repo = new SignupsRepository();
        $signup_id = $repo->create([
            'person_id'   => $person_id,
            'schedule_id' => $schedule_id,
            'slot_id'     => $slot_id,
            'date'        => gmdate('Y-m-d', strtotime('+1 day')),
            'status'      => 'confirmed',
            'created_via' => 'admin',
        ]);

        $this->assertGreaterThan(0, $signup_id, 'Fixture signup should insert successfully.');

        return [$signup_id, $person_id];
    }

    public function test_cancel_signup_marks_confirmed_signup_as_cancelled(): void
    {
        [$signup_id, $person_id] = $this->make_confirmed_signup();

        $ok = SignupCancellationService::cancel_signup($signup_id, $person_id);
        $this->assertTrue($ok);

        $row = (new SignupsRepository())->find($signup_id);
        $this->assertSame('cancelled', $row['status']);
        if (array_key_exists('is_active', $row)) {
            $this->assertSame(0, (int)$row['is_active']);
        }
    }

    public function test_cancel_signup_is_idempotent_for_already_cancelled_signup(): void
    {
        [$signup_id, $person_id] = $this->make_confirmed_signup();

        $this->assertTrue(SignupCancellationService::cancel_signup($signup_id, $person_id));
        // Cancelling an already-cancelled signup should still report success,
        // not be treated as a failure/not-found.
        $this->assertTrue(SignupCancellationService::cancel_signup($signup_id, $person_id));

        $row = (new SignupsRepository())->find($signup_id);
        $this->assertSame('cancelled', $row['status']);
    }

    public function test_cancel_signup_rejects_a_different_persons_signup(): void
    {
        [$signup_id, ] = $this->make_confirmed_signup();
        $someone_else_id = $this->make_person(['email' => 'someone-else@example.test']);

        $ok = SignupCancellationService::cancel_signup($signup_id, $someone_else_id);
        $this->assertFalse($ok, 'Cancelling with a person_id that does not own the signup must fail.');

        $row = (new SignupsRepository())->find($signup_id);
        $this->assertSame('confirmed', $row['status'], 'The signup must be untouched when ownership does not match.');
    }
}
