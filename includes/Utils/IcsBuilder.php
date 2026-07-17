<?php
namespace AdorationScheduler\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Minimal RFC 5545 (iCalendar) writer — no external library, matching this
 * plugin's "no external services required" design. Handles just what the
 * personal and public calendar feeds need: a flat list of VEVENTs with a
 * fixed start/end time, no recurrence rules (each occurrence is written as
 * its own VEVENT, since slots/signups are already materialized per-date
 * rows rather than abstract recurrence patterns — see SlotsRepository).
 *
 * All datetimes are converted to UTC on the way in (see event() below) so
 * this never needs to emit a VTIMEZONE block — the simplest interoperable
 * approach for a read-only subscribe feed.
 */
class IcsBuilder {

    /** @var string[] */
    private array $events = [];

    private string $calendar_name;
    private string $calendar_description = '';
    private string $product_id;

    public function __construct(string $calendar_name, string $product_id = '-//Adoration Scheduler//EN') {
        $this->calendar_name = $calendar_name;
        $this->product_id    = $product_id;
    }

    /**
     * Optional X-WR-CALDESC — mainly useful for the "something's wrong"
     * empty-feed case, so the reason is visible if someone opens the feed
     * URL directly in a browser instead of a calendar app.
     */
    public function set_description(string $description): void {
        $this->calendar_description = $description;
    }

    /**
     * Add one VEVENT.
     *
     * @param string        $uid       Stable, globally-unique identifier (e.g. "signup-123@example.com").
     * @param \DateTimeImmutable $start Event start, in any timezone — converted to UTC internally.
     * @param \DateTimeImmutable $end   Event end, in any timezone — converted to UTC internally.
     * @param string        $summary   VEVENT SUMMARY (title).
     * @param string        $location  VEVENT LOCATION (optional).
     * @param string        $description VEVENT DESCRIPTION (optional).
     */
    public function add_event(
        string $uid,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $summary,
        string $location = '',
        string $description = ''
    ): void {
        $utc = new \DateTimeZone('UTC');
        $dtstart = $start->setTimezone($utc)->format('Ymd\THis\Z');
        $dtend   = $end->setTimezone($utc)->format('Ymd\THis\Z');
        $dtstamp = gmdate('Ymd\THis\Z');

        $lines = [];
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . self::escape_text($uid);
        $lines[] = 'DTSTAMP:' . $dtstamp;
        $lines[] = 'DTSTART:' . $dtstart;
        $lines[] = 'DTEND:' . $dtend;
        $lines[] = 'SUMMARY:' . self::escape_text($summary);
        if ($location !== '') $lines[] = 'LOCATION:' . self::escape_text($location);
        if ($description !== '') $lines[] = 'DESCRIPTION:' . self::escape_text($description);
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'END:VEVENT';

        foreach ($lines as $line) {
            $this->events[] = self::fold_line($line);
        }
    }

    /**
     * Assemble the full VCALENDAR document.
     */
    public function build(): string {
        $lines = [];
        $lines[] = self::fold_line('BEGIN:VCALENDAR');
        $lines[] = self::fold_line('VERSION:2.0');
        $lines[] = self::fold_line('PRODID:' . $this->product_id);
        $lines[] = self::fold_line('CALSCALE:GREGORIAN');
        $lines[] = self::fold_line('METHOD:PUBLISH');
        $lines[] = self::fold_line('X-WR-CALNAME:' . self::escape_text($this->calendar_name));
        if ($this->calendar_description !== '') {
            $lines[] = self::fold_line('X-WR-CALDESC:' . self::escape_text($this->calendar_description));
        }
        // Hint to calendar apps that poll on a schedule (Google/Apple/Outlook
        // honor this loosely) not to hammer the feed more than hourly.
        $lines[] = self::fold_line('X-PUBLISHED-TTL:PT1H');
        $lines[] = self::fold_line('REFRESH-INTERVAL;VALUE=DURATION:PT1H');

        $lines = array_merge($lines, $this->events);

        $lines[] = self::fold_line('END:VCALENDAR');

        // RFC 5545 requires CRLF line endings.
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Escape TEXT-valued properties per RFC 5545 §3.3.11: backslash,
     * semicolon, comma, and newline.
     */
    private static function escape_text(string $value): string {
        $value = str_replace(["\\", ";", ","], ["\\\\", "\\;", "\\,"], $value);
        $value = str_replace(["\r\n", "\r", "\n"], "\\n", $value);
        return $value;
    }

    /**
     * Fold a content line at 75 octets per RFC 5545 §3.1, continuation
     * lines prefixed with a single space. Operates on bytes (not
     * multi-byte-safe mid-character), which is the standard/expected
     * behavior for this fold algorithm.
     */
    private static function fold_line(string $line): string {
        if (strlen($line) <= 75) return $line;

        $out = '';
        $first = true;
        while (strlen($line) > 0) {
            $chunk_len = $first ? 75 : 74; // continuation lines lose 1 byte to the leading space
            $chunk = substr($line, 0, $chunk_len);
            $line = substr($line, $chunk_len);

            $out .= $first ? $chunk : ("\r\n " . $chunk);
            $first = false;
        }

        return $out;
    }
}
