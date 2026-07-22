<?php
namespace AdorationScheduler\Tests\Integration\Domain\Repositories;

use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Tests\Support\AdorationIntegrationTestCase;

/**
 * Covers the no-account-adorer change to PersonsRepository::upsert_by_email()
 * (2026-07-21): `email` is a real NOT NULL UNIQUE column, so the trickiest
 * part of "let an admin add someone with no email" is proving two separate
 * blank-email people don't collide on that unique index — exactly the bug
 * this replaced (a blank email used to insert as a literal '' the first
 * time, then throw a duplicate-key error the second time). The unit suite
 * can't verify real UNIQUE constraint behavior; this can.
 */
class PersonsRepositoryIntegrationTest extends AdorationIntegrationTestCase
{
    public function test_upsert_by_email_with_blank_email_creates_distinct_people(): void
    {
        $repo = new PersonsRepository();

        $first_id = $repo->upsert_by_email([
            'first_name' => 'Mary',
            'last_name'  => 'Smith',
            'email'      => '',
        ]);
        $second_id = $repo->upsert_by_email([
            'first_name' => 'John',
            'last_name'  => 'Doe',
            'email'      => '',
        ]);

        $this->assertGreaterThan(0, $first_id, 'Expected the first blank-email person to insert successfully.');
        $this->assertGreaterThan(0, $second_id, 'Expected the second blank-email person to insert successfully, not collide on the unique email index.');
        $this->assertNotSame($first_id, $second_id);

        $first = $repo->find($first_id);
        $second = $repo->find($second_id);

        $this->assertSame('Mary', $first['first_name']);
        $this->assertSame('John', $second['first_name']);

        // Placeholder emails must still be unique and non-empty (satisfies
        // the NOT NULL UNIQUE column) but never collide with each other.
        $this->assertNotSame('', $first['email']);
        $this->assertNotSame('', $second['email']);
        $this->assertNotSame($first['email'], $second['email']);
    }

    public function test_upsert_by_email_with_real_email_still_dedupes(): void
    {
        $repo = new PersonsRepository();

        $first_id = $repo->upsert_by_email([
            'first_name' => 'Frances',
            'last_name'  => 'Xavier',
            'email'      => 'frances-xavier@example.test',
        ]);
        $second_id = $repo->upsert_by_email([
            'first_name' => 'Frances',
            'last_name'  => 'Xavier',
            'email'      => 'frances-xavier@example.test',
            'phone'      => '(555) 123-4567',
        ]);

        $this->assertGreaterThan(0, $first_id);
        $this->assertSame($first_id, $second_id, 'A matching real email should still resolve to the same person, not create a duplicate.');
    }
}
