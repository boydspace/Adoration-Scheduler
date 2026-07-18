<?php
namespace AdorationScheduler\Tests\Unit\Utils;

use AdorationScheduler\Tests\Support\AdorationTestCase;
use AdorationScheduler\Utils\NameFormatter;

/**
 * Regression coverage for the privacy_mode bug fixed 2026-07-17: the admin
 * dropdown offered 4 privacy modes but the front end only ever rendered
 * first_last_initial — "first_name_only" and "names" were silently dead
 * options. These tests pin down each formatter individually, then the
 * format() dispatcher's mode -> formatter mapping, so that bug can't
 * silently come back.
 */
final class NameFormatterTest extends AdorationTestCase
{
    // --- first_last_initial() ------------------------------------------

    public function test_first_last_initial_with_both_names(): void
    {
        $this->assertSame('Andy B.', NameFormatter::first_last_initial('Andy', 'Boyd'));
    }

    public function test_first_last_initial_missing_last_name(): void
    {
        $this->assertSame('Andy', NameFormatter::first_last_initial('Andy', ''));
    }

    public function test_first_last_initial_missing_first_name(): void
    {
        $this->assertSame('B.', NameFormatter::first_last_initial('', 'Boyd'));
    }

    public function test_first_last_initial_both_missing(): void
    {
        $this->assertSame('—', NameFormatter::first_last_initial('', ''));
        $this->assertSame('—', NameFormatter::first_last_initial(null, null));
    }

    public function test_first_last_initial_trims_whitespace(): void
    {
        $this->assertSame('Andy B.', NameFormatter::first_last_initial('  Andy  ', '  Boyd  '));
    }

    // --- first_name_only() ----------------------------------------------

    public function test_first_name_only_with_both_names(): void
    {
        $this->assertSame('Andy', NameFormatter::first_name_only('Andy', 'Boyd'));
    }

    public function test_first_name_only_missing_first_falls_back_to_last_initial(): void
    {
        $this->assertSame('B.', NameFormatter::first_name_only('', 'Boyd'));
    }

    public function test_first_name_only_both_missing(): void
    {
        $this->assertSame('—', NameFormatter::first_name_only('', ''));
    }

    // --- full_name() ------------------------------------------------------

    public function test_full_name_with_both_names(): void
    {
        $this->assertSame('Andy Boyd', NameFormatter::full_name('Andy', 'Boyd'));
    }

    public function test_full_name_missing_last_name(): void
    {
        $this->assertSame('Andy', NameFormatter::full_name('Andy', ''));
    }

    public function test_full_name_missing_first_name(): void
    {
        $this->assertSame('Boyd', NameFormatter::full_name('', 'Boyd'));
    }

    public function test_full_name_both_missing(): void
    {
        $this->assertSame('—', NameFormatter::full_name('', ''));
    }

    // --- format() dispatcher ---------------------------------------------

    public function test_format_dispatches_first_name_only(): void
    {
        $this->assertSame('Andy', NameFormatter::format('first_name_only', 'Andy', 'Boyd'));
    }

    public function test_format_dispatches_names_to_full_name(): void
    {
        $this->assertSame('Andy Boyd', NameFormatter::format('names', 'Andy', 'Boyd'));
    }

    public function test_format_dispatches_first_last_initial(): void
    {
        $this->assertSame('Andy B.', NameFormatter::format('first_last_initial', 'Andy', 'Boyd'));
    }

    /**
     * counts_only isn't a real formatting mode (callers should skip building
     * names entirely for it — see ScheduleShortcode's `!== 'counts_only'`
     * guards), but format() still needs to degrade sensibly rather than
     * fatal or silently return something wrong if it's ever called anyway.
     */
    public function test_format_falls_back_to_first_last_initial_for_unknown_mode(): void
    {
        $this->assertSame('Andy B.', NameFormatter::format('counts_only', 'Andy', 'Boyd'));
        $this->assertSame('Andy B.', NameFormatter::format('some_future_mode', 'Andy', 'Boyd'));
    }
}
