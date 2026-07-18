<?php
namespace AdorationScheduler\Tests\Unit\Regression;

use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Tests\Support\AdorationTestCase;

/**
 * Regression coverage for the bug fixed 2026-07-17: claiming a standing
 * weekly commitment sent 8-9 near-identical "signup confirmed" emails in one
 * burst. Root cause: PerpetualSlotGenerator::apply_standing_commitments()
 * calls SignupsRepository::create() once per future occurrence it auto-fills
 * (often 8-9 calls in one sync_window() run), and create() unconditionally
 * fired a confirmation email on every successful insert — regardless of
 * *why* the row was created. The commitment itself is already confirmed by
 * one dedicated email sent by the caller (StandingSignupHandler /
 * EditSchedulePage's add-commitment action), so per-date emails from the
 * bulk auto-fill are pure duplication.
 *
 * Fix: create() now skips its own confirmation email specifically when
 * created_via === 'standing_commitment'.
 *
 * How this test verifies it without a full email pipeline:
 * create()'s confirmation-email step is `new EmailService(); ...
 * send_signup_confirmation($insert_id)`. That call's *first* action
 * (EmailService::build_args_from_signup_id()) is a real $wpdb->get_row()
 * looking up the just-inserted signup row by id. FakeWpdb logs every query
 * it's asked to run even when no canned response is queued for it (it just
 * returns null, which is fine — EmailService fails gracefully and returns
 * false without reaching wp_mail). So "was that lookup query issued at all"
 * is a reliable, low-assumption signal for "did create() attempt to send a
 * confirmation email" — independent of whether the rest of the email
 * pipeline (templates, wp_mail, logging) would have succeeded.
 */
final class StandingCommitmentEmailBugTest extends AdorationTestCase
{
    private function base_row(string $created_via): array
    {
        return [
            'person_id'   => 7,
            'schedule_id' => 3,
            'slot_id'     => 42,
            'date'        => '2026-07-22',
            'status'      => 'confirmed',
            'type'        => 'standing',
            'created_via' => $created_via,
        ];
    }

    /**
     * EmailService::build_args_from_signup_id()'s first action is exactly
     * `SELECT * FROM {signups} WHERE id = %d LIMIT 1`. Matching that specific
     * "WHERE id = N LIMIT 1" tail (rather than just "adoration_signups") is
     * deliberate: create()'s own dedup check (exists_for_slot_person_date)
     * also queries the signups table, filtered by slot_id/person_id/date —
     * a looser substring match risks a false positive there (e.g.
     * "slot_id = 42" contains "id = 42").
     */
    private function email_lookup_was_attempted(\AdorationScheduler\Tests\Support\FakeWpdb $wpdb, int $signup_id): bool
    {
        foreach ($wpdb->queries as $q) {
            if (strpos($q['sql'], 'adoration_signups') !== false
                && strpos($q['sql'], "WHERE id = {$signup_id} LIMIT 1") !== false) {
                return true;
            }
        }
        return false;
    }

    public function test_standing_commitment_auto_fill_does_not_send_a_confirmation_email(): void
    {
        $wpdb = $this->fake_wpdb();
        $wpdb->will_return_var(null); // exists_for_slot_person_date() dedup check: "not a duplicate"

        $repo = new SignupsRepository();
        $insert_id = $repo->create($this->base_row('standing_commitment'));

        $this->assertGreaterThan(0, $insert_id, 'Sanity check: the insert itself must still succeed.');
        $this->assertFalse(
            $this->email_lookup_was_attempted($wpdb, $insert_id),
            'created_via=standing_commitment must NOT trigger create()\'s own confirmation email — that would reproduce the duplicate-email burst.'
        );
    }

    public function test_public_form_signup_still_sends_a_confirmation_email(): void
    {
        $wpdb = $this->fake_wpdb();
        $wpdb->will_return_var(null); // exists_for_slot_person_date() dedup check: "not a duplicate"

        $repo = new SignupsRepository();
        $insert_id = $repo->create($this->base_row('public_form'));

        $this->assertGreaterThan(0, $insert_id);
        $this->assertTrue(
            $this->email_lookup_was_attempted($wpdb, $insert_id),
            'Any created_via other than standing_commitment must still attempt its confirmation email — the fix must not over-suppress.'
        );
    }

    public function test_admin_created_via_also_still_sends_a_confirmation_email(): void
    {
        // The guard is an exact string match on 'standing_commitment' — this
        // pins that down, since a loose match (e.g. str_contains 'standing')
        // would wrongly also suppress plain admin-created signups.
        $wpdb = $this->fake_wpdb();
        $wpdb->will_return_var(null);

        $repo = new SignupsRepository();
        $insert_id = $repo->create($this->base_row('admin'));

        $this->assertTrue($this->email_lookup_was_attempted($wpdb, $insert_id));
    }
}
