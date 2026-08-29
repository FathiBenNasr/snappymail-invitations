<?php
/**
 * Invitations — the day drawn beside the invitation.
 *
 * The point of the column is a decision taken with the clash in view, so what
 * matters is that the day shown is the right day, cut where the reader sees
 * midnight, and that what is drawn on it is what actually occupies them. Each
 * of the three ways this can quietly lie has a test here: a window that is a
 * fixed 24 hours (wrong twice a year), a recurrence left unexpanded (every
 * weekly meeting missing), and a busy block that is the invitation itself
 * (a meeting shown clashing with its own copy).
 *
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/../plugin/index.php';

final class DayAgendaTest extends TestCase
{
    private const TEMPLATE = 'https://dav.convergent.tn/dav/calendars/{user}/Default/';

    private function plugin(array $config = [], array $params = []): InvitationsPlugin
    {
        $actions             = new \RainLoop\Actions();
        $actions->account    = new \RainLoop\Account('paul@convergent.tn');
        $plugin              = new InvitationsPlugin();
        $plugin->actionsStub = $actions;
        $plugin->config      = $config;
        $plugin->jsonParams  = $params;
        return $plugin;
    }

    private function call(string $method, ...$args)
    {
        return (new ReflectionMethod(InvitationsPlugin::class, $method))->invoke(null, ...$args);
    }

    private function callOn(object $plugin, string $method, ...$args)
    {
        return (new ReflectionMethod($plugin, $method))->invoke($plugin, ...$args);
    }

    private function tz(string $name): \DateTimeZone
    {
        return new \DateTimeZone($name);
    }

    /** An invitation for one meeting, in the organiser's own zone. */
    private static function invitation(string $start = '20260901T090000', string $end = '20260901T103000'): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\n"
            . "UID:the-meeting\r\nSUMMARY:Comité de pilotage\r\n"
            . "DTSTART;TZID=Africa/Tunis:{$start}\r\nDTEND;TZID=Africa/Tunis:{$end}\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /** One calendar object as a CalDAV collection would hand it over. */
    private static function object(string $uid, string $body, string $summary = 'Autre chose'): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:{$uid}\r\n"
            . "SUMMARY:{$summary}\r\n{$body}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private static function multistatus(array $objects, string $prefix = 'C'): string
    {
        $sXml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<D:multistatus xmlns:D="DAV:" xmlns:' . $prefix . '="urn:ietf:params:xml:ns:caldav">';
        foreach ($objects as $sObject) {
            $sXml .= '<D:response><D:href>/x.ics</D:href><D:propstat><D:prop>'
                . '<' . $prefix . ':calendar-data>' . \htmlspecialchars($sObject, \ENT_XML1)
                . '</' . $prefix . ':calendar-data>'
                . '</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
        }
        return $sXml . '</D:multistatus>';
    }

    /** The event of an ICS string, parsed the way the plugin parses it. */
    private static function vevent(string $sIcs)
    {
        return \Sabre\VObject\Reader::read($sIcs, \Sabre\VObject\Reader::OPTION_FORGIVING)->VEVENT;
    }

    // ------------------------------------------------------- the library

    /**
     * The tests exercise the plugin's real Sabre calls, so an absent library
     * has to fail loudly. Skipping quietly would leave twenty green ticks
     * asserting nothing at all.
     */
    public function testTheCalendarLibraryIsAvailable(): void
    {
        self::assertTrue(INVITATIONS_TESTS_HAVE_VOBJECT,
            'Sabre VObject is required to run these tests');
    }

    // ------------------------------------------------------- the window

    /** A day runs from local midnight to local midnight, not from the instant. */
    public function testTheWindowIsTheLocalDayAroundTheMeeting(): void
    {
        list($oFrom, $oTo) = $this->call('dayWindow',
            new \DateTimeImmutable('2026-09-01T09:00:00', $this->tz('Africa/Tunis')),
            $this->tz('Africa/Tunis'));

        self::assertSame('2026-09-01 00:00:00', $oFrom->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-02 00:00:00', $oTo->format('Y-m-d H:i:s'));
    }

    /**
     * The night the clocks go forward the day is 23 hours long. A window built
     * as start + 24h would end at 01:00 the next morning and show tomorrow's
     * first meeting as today's last.
     */
    public function testASpringForwardDayIsTwentyThreeHours(): void
    {
        list($oFrom, $oTo) = $this->call('dayWindow',
            new \DateTimeImmutable('2026-03-29T12:00:00', $this->tz('Europe/Paris')),
            $this->tz('Europe/Paris'));

        self::assertSame(23 * 3600, $oTo->getTimestamp() - $oFrom->getTimestamp());
        self::assertSame('2026-03-30 00:00:00', $oTo->format('Y-m-d H:i:s'));
    }

    /** And 25 the night they go back — the symmetric half of the same bug. */
    public function testAnAutumnDayIsTwentyFiveHours(): void
    {
        list($oFrom, $oTo) = $this->call('dayWindow',
            new \DateTimeImmutable('2026-10-25T12:00:00', $this->tz('Europe/Paris')),
            $this->tz('Europe/Paris'));

        self::assertSame(25 * 3600, $oTo->getTimestamp() - $oFrom->getTimestamp());
    }

    /**
     * The day belongs to the reader, not to the organiser. Half past midnight
     * on 1 September in Tunis is half past seven the *previous evening* in New
     * York, and it is the New Yorker's 31 August that has to be drawn.
     *
     * London would not have made the point: through September it shares an
     * offset with Tunis, and the test would have passed against a day window
     * that ignored the argument entirely.
     */
    public function testTheWindowFollowsTheReadersZoneNotTheOrganisers(): void
    {
        $oInstant = new \DateTimeImmutable('2026-09-01T00:30:00', $this->tz('Africa/Tunis'));

        list($oFrom) = $this->call('dayWindow', $oInstant, $this->tz('America/New_York'));

        self::assertSame('2026-08-31 00:00:00', $oFrom->format('Y-m-d H:i:s'));
        self::assertSame('America/New_York', $oFrom->getTimezone()->getName());
    }

    // --------------------------------------------------------- the zone

    /** A zone the database knows is the one used. */
    public function testAKnownZoneIsAccepted(): void
    {
        $plugin = $this->plugin();
        $oTz = $this->callOn($plugin, 'readTimeZone', 'Europe/London',
            self::vevent(self::invitation()));

        self::assertSame('Europe/London', $oTz->getName());
    }

    /**
     * The identifier comes from the browser and goes on to build a day
     * boundary, so it is checked against the zone database rather than
     * trusted. Anything else falls back on the organiser's zone.
     */
    public function testAnUnknownZoneFallsBackToTheInvitationsOwn(): void
    {
        $plugin = $this->plugin();

        foreach (["Africa/Tunis'; DROP", 'Mars/Olympus', '../../etc/localtime', '<script>'] as $sHostile) {
            $oTz = $this->callOn($plugin, 'readTimeZone', $sHostile,
                self::vevent(self::invitation()));
            self::assertSame('Africa/Tunis', $oTz->getName(), "refused: {$sHostile}");
        }
    }

    /** An absent zone is not an error: the invitation carries one. */
    public function testAnEmptyZoneFallsBackToTheInvitationsOwn(): void
    {
        $plugin = $this->plugin();
        $oTz = $this->callOn($plugin, 'readTimeZone', '  ', self::vevent(self::invitation()));

        self::assertSame('Africa/Tunis', $oTz->getName());
    }

    // --------------------------------------------------------- the span

    /** DTEND says when it ends, and it wins. */
    public function testDtendGivesTheEnd(): void
    {
        list($oStart, $oEnd) = $this->call('eventSpan',
            self::vevent(self::invitation()), $this->tz('Africa/Tunis'));

        self::assertSame('2026-09-01 09:00:00', $oStart->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-01 10:30:00', $oEnd->format('Y-m-d H:i:s'));
    }

    /** Half the world's clients send DURATION instead. */
    public function testDurationIsUsedWhenThereIsNoDtend(): void
    {
        $sIcs = self::object('x', "DTSTART;TZID=Africa/Tunis:20260901T090000\r\nDURATION:PT45M");

        list($oStart, $oEnd) = $this->call('eventSpan', self::vevent($sIcs), $this->tz('Africa/Tunis'));

        self::assertSame(45 * 60, $oEnd->getTimestamp() - $oStart->getTimestamp());
    }

    /** RFC 5545: a whole-day event with neither lasts one day. */
    public function testAWholeDayEventWithoutAnEndLastsADay(): void
    {
        $sIcs = self::object('x', 'DTSTART;VALUE=DATE:20260901');

        list($oStart, $oEnd) = $this->call('eventSpan', self::vevent($sIcs), $this->tz('Africa/Tunis'));

        self::assertSame('2026-09-01 00:00:00', $oStart->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-02 00:00:00', $oEnd->format('Y-m-d H:i:s'));
    }

    /**
     * A bare DATE has no zone of its own. Read without one it lands on
     * midnight of whatever zone the process was started with, which is the day
     * before for anyone east of it.
     */
    public function testAWholeDayEventIsPlacedInTheGivenZone(): void
    {
        $sIcs = self::object('x', 'DTSTART;VALUE=DATE:20260901');

        list($oStart) = $this->call('eventSpan', self::vevent($sIcs), $this->tz('Pacific/Auckland'));

        self::assertSame('Pacific/Auckland', $oStart->getTimezone()->getName());
        self::assertSame('2026-09-01 00:00:00', $oStart->format('Y-m-d H:i:s'));
    }

    // -------------------------------------------------------- the query

    /** The window reaches the server in UTC, whatever zone it was cut in. */
    public function testTheQueryCarriesTheWindowInUtc(): void
    {
        $oFrom = new \DateTimeImmutable('2026-09-01T00:00:00', $this->tz('Africa/Tunis'));
        $sXml  = $this->call('calendarQuery', $oFrom, $oFrom->modify('+1 day'));

        self::assertStringContainsString('start="20260831T230000Z"', $sXml);
        self::assertStringContainsString('end="20260901T230000Z"', $sXml);
    }

    /**
     * No caller-supplied character can reach the XML: the two instants are
     * formatted by DateTime, which can only ever produce digits, T and Z.
     */
    public function testTheQueryIsWellFormedAndCarriesOnlyFormattedInstants(): void
    {
        $oFrom = new \DateTimeImmutable('2026-09-01T00:00:00', $this->tz('UTC'));
        $sXml  = $this->call('calendarQuery', $oFrom, $oFrom->modify('+1 day'));

        $oDoc = new \DOMDocument();
        self::assertTrue($oDoc->loadXML($sXml), 'the REPORT body must be well-formed XML');
        self::assertMatchesRegularExpression('/start="\d{8}T\d{6}Z" end="\d{8}T\d{6}Z"/', $sXml);
    }

    /**
     * Expansion is done here, not asked for. `<C:expand>` is optional in RFC
     * 4791 and a server that ignores it answers with the master events
     * *silently* — the day would then be missing every recurring meeting on
     * it, and nothing would say so.
     */
    public function testTheQueryDoesNotAskTheServerToExpand(): void
    {
        $oFrom = new \DateTimeImmutable('2026-09-01T00:00:00', $this->tz('UTC'));
        $sXml  = $this->call('calendarQuery', $oFrom, $oFrom->modify('+1 day'));

        self::assertStringNotContainsString('expand', $sXml);
        self::assertStringContainsString('name="VEVENT"', $sXml);
    }

    // ------------------------------------------------- reading the answer

    /** Servers pick their own prefix; the namespace is what identifies it. */
    public function testCalendarDataIsFoundWhateverThePrefix(): void
    {
        foreach (['C', 'cal', 'caldav'] as $sPrefix) {
            $aFound = $this->call('calendarData',
                self::multistatus([self::object('a', 'DTSTART:20260901T090000Z')], $sPrefix));

            self::assertCount(1, $aFound, "prefix {$sPrefix}");
            self::assertStringContainsString('UID:a', $aFound[0]);
        }
    }

    /** Two objects come back as two, in order. */
    public function testEveryCalendarObjectIsReturned(): void
    {
        $aFound = $this->call('calendarData', self::multistatus([
            self::object('a', 'DTSTART:20260901T090000Z'),
            self::object('b', 'DTSTART:20260901T140000Z'),
        ]));

        self::assertCount(2, $aFound);
    }

    /** A body that is not XML is an empty day, never an exception. */
    public function testRubbishGivesAnEmptyDay(): void
    {
        foreach (['', '   ', 'not xml at all', '<D:multistatus'] as $sBody) {
            self::assertSame([], $this->call('calendarData', $sBody));
        }
    }

    /**
     * The multistatus arrives over the network. An entity in it must never
     * cause a read of the local disk, nor a fetch.
     */
    public function testAnExternalEntityIsNotResolved(): void
    {
        $sFile = \sys_get_temp_dir() . '/invitations-xxe-' . \getmypid() . '.txt';
        \file_put_contents($sFile, 'CANARY-SHOULD-NEVER-APPEAR');
        try {
            $sXml = '<?xml version="1.0"?>'
                . '<!DOCTYPE r [<!ENTITY x SYSTEM "file://' . $sFile . '">]>'
                . '<D:multistatus xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
                . '<D:response><D:propstat><D:prop><C:calendar-data>&x;</C:calendar-data>'
                . '</D:prop></D:propstat></D:response></D:multistatus>';

            $aFound = $this->call('calendarData', $sXml);
            self::assertStringNotContainsString('CANARY-SHOULD-NEVER-APPEAR',
                \implode('', $aFound), 'an external entity was resolved');
        } finally {
            @\unlink($sFile);
        }
    }

    // --------------------------------------------------------- the blocks

    /** @return array the blocks for one day of Tunis time, 09:00-10:30 proposed */
    private function blocks(array $aObjects, string $sSkipUid = 'the-meeting'): array
    {
        $oTz   = $this->tz('Africa/Tunis');
        $oFrom = new \DateTimeImmutable('2026-09-01T00:00:00', $oTz);

        return $this->call('busyBlocks', $aObjects, $oFrom, $oFrom->modify('+1 day'), $sSkipUid, $oTz,
            new \DateTimeImmutable('2026-09-01T09:00:00', $oTz),
            new \DateTimeImmutable('2026-09-01T10:30:00', $oTz));
    }

    /** The ordinary case: something else on the same day comes back. */
    public function testAnEventOnTheDayIsReturned(): void
    {
        $aBlocks = $this->blocks([self::object('other',
            "DTSTART;TZID=Africa/Tunis:20260901T140000\r\nDTEND;TZID=Africa/Tunis:20260901T150000",
            'Revue de sprint')]);

        self::assertCount(1, $aBlocks);
        self::assertSame('Revue de sprint', $aBlocks[0]['summary']);
        self::assertFalse($aBlocks[0]['allDay']);
        self::assertFalse($aBlocks[0]['clash']);
    }

    /**
     * Once accepted, the meeting is in the calendar under the same UID.
     * Without this it is drawn opposite itself, flagged as a clash with
     * itself, and the reader is told not to accept what they already have.
     */
    public function testTheInvitationsOwnCopyIsNotDrawnAgainstIt(): void
    {
        $aBlocks = $this->blocks([self::object('the-meeting',
            "DTSTART;TZID=Africa/Tunis:20260901T090000\r\nDTEND;TZID=Africa/Tunis:20260901T103000")]);

        self::assertSame([], $aBlocks);
    }

    /** An event its author marked as not taking their time does not take it. */
    public function testTransparentEventsAreNotBusy(): void
    {
        $aBlocks = $this->blocks([self::object('other',
            "DTSTART;TZID=Africa/Tunis:20260901T090000\r\nDTEND;TZID=Africa/Tunis:20260901T100000\r\n"
            . 'TRANSP:TRANSPARENT')]);

        self::assertSame([], $aBlocks);
    }

    /** A withdrawn meeting is not a reason to refuse another. */
    public function testCancelledEventsAreNotBusy(): void
    {
        $aBlocks = $this->blocks([self::object('other',
            "DTSTART;TZID=Africa/Tunis:20260901T090000\r\nDTEND;TZID=Africa/Tunis:20260901T100000\r\n"
            . 'STATUS:CANCELLED')]);

        self::assertSame([], $aBlocks);
    }

    /**
     * "Congé" and "jour férié" are worth seeing above the strip, but a meeting
     * is not impossible because a day carries a label.
     */
    public function testAWholeDayEntryIsShownAndNeverAClash(): void
    {
        $aBlocks = $this->blocks([self::object('leave',
            'DTSTART;VALUE=DATE:20260901', 'Congé')]);

        self::assertCount(1, $aBlocks);
        self::assertTrue($aBlocks[0]['allDay']);
        self::assertFalse($aBlocks[0]['clash']);
    }

    /** What overlaps the proposed slot is flagged; that is the whole point. */
    public function testAnOverlapIsFlaggedAsAClash(): void
    {
        $aBlocks = $this->blocks([self::object('other',
            "DTSTART;TZID=Africa/Tunis:20260901T100000\r\nDTEND;TZID=Africa/Tunis:20260901T110000")]);

        self::assertCount(1, $aBlocks);
        self::assertTrue($aBlocks[0]['clash']);
    }

    /** Touching is not overlapping: 08:00-09:00 leaves 09:00 free. */
    public function testAMeetingEndingWhenThisOneStartsIsNotAClash(): void
    {
        $aBlocks = $this->blocks([self::object('before',
            "DTSTART;TZID=Africa/Tunis:20260901T080000\r\nDTEND;TZID=Africa/Tunis:20260901T090000")]);

        self::assertCount(1, $aBlocks);
        self::assertFalse($aBlocks[0]['clash']);
    }

    /**
     * The weekly meeting is the commonest thing on a working calendar, and it
     * is stored once with a rule. Left unexpanded, a day looks free when it is
     * not — and it looks free *quietly*.
     */
    public function testARecurringMeetingIsExpandedOntoTheDay(): void
    {
        // Starts four Tuesdays before the day in question.
        $aBlocks = $this->blocks([self::object('weekly',
            "DTSTART;TZID=Africa/Tunis:20260804T093000\r\nDTEND;TZID=Africa/Tunis:20260804T100000\r\n"
            . 'RRULE:FREQ=WEEKLY;BYDAY=TU', 'Point hebdomadaire')]);

        self::assertCount(1, $aBlocks);
        self::assertTrue($aBlocks[0]['clash'], 'the 09:30 instance overlaps a 09:00-10:30 slot');
        self::assertStringContainsString('2026-09-01', $aBlocks[0]['start']);
    }

    /** A rule that has stopped before the day puts nothing on it. */
    public function testARecurrenceThatHasEndedIsNotDrawn(): void
    {
        $aBlocks = $this->blocks([self::object('weekly',
            "DTSTART;TZID=Africa/Tunis:20260804T093000\r\nDTEND;TZID=Africa/Tunis:20260804T100000\r\n"
            . 'RRULE:FREQ=WEEKLY;BYDAY=TU;COUNT=2')]);

        self::assertSame([], $aBlocks);
    }

    /** Another day's meeting is another day's problem. */
    public function testAnEventOutsideTheWindowIsDropped(): void
    {
        $aBlocks = $this->blocks([self::object('tomorrow',
            "DTSTART;TZID=Africa/Tunis:20260902T090000\r\nDTEND;TZID=Africa/Tunis:20260902T100000")]);

        self::assertSame([], $aBlocks);
    }

    /** The strip is read top to bottom, so the blocks arrive in that order. */
    public function testBlocksComeBackInTimeOrder(): void
    {
        $aBlocks = $this->blocks([
            self::object('c', "DTSTART;TZID=Africa/Tunis:20260901T160000\r\nDTEND;TZID=Africa/Tunis:20260901T170000", 'C'),
            self::object('a', "DTSTART;TZID=Africa/Tunis:20260901T080000\r\nDTEND;TZID=Africa/Tunis:20260901T083000", 'A'),
            self::object('b', "DTSTART;TZID=Africa/Tunis:20260901T140000\r\nDTEND;TZID=Africa/Tunis:20260901T150000", 'B'),
        ]);

        self::assertSame(['A', 'B', 'C'], \array_column($aBlocks, 'summary'));
    }

    /** One unreadable object must not empty the whole day. */
    public function testAnUnreadableObjectDoesNotEmptyTheDay(): void
    {
        $aBlocks = $this->blocks([
            'BEGIN:VCALENDAR this is not a calendar',
            self::object('good', "DTSTART;TZID=Africa/Tunis:20260901T140000\r\nDTEND;TZID=Africa/Tunis:20260901T150000", 'Bon'),
        ]);

        self::assertCount(1, $aBlocks);
        self::assertSame('Bon', $aBlocks[0]['summary']);
    }

    /** A machine account with a day full of blocks is capped, not drawn. */
    public function testTheNumberOfBlocksIsCapped(): void
    {
        $aObjects = [];
        for ($i = 0; 200 > $i; ++$i) {
            $sMinute = \str_pad((string) ($i % 60), 2, '0', \STR_PAD_LEFT);
            $aObjects[] = self::object("bulk-{$i}",
                "DTSTART;TZID=Africa/Tunis:20260901T12{$sMinute}00\r\nDURATION:PT5M");
        }

        self::assertCount(60, $this->blocks($aObjects));
    }

    // ------------------------------------------------- the hook's guards

    /** Turned off in the settings, it asks the calendar nothing at all. */
    public function testTheDayCanBeTurnedOff(): void
    {
        $plugin = $this->plugin(['day_agenda' => false], ['Ics' => self::invitation()]);
        $aAnswer = $plugin->DoInvitationDay();

        self::assertFalse($aAnswer['success']);
        self::assertSame('Disabled', $aAnswer['error']);
    }

    /** Nothing to read the day from. */
    public function testAnEmptyPayloadIsRefused(): void
    {
        $plugin = $this->plugin(['day_agenda' => true], ['Ics' => '']);

        self::assertFalse($plugin->DoInvitationDay()['success']);
    }

    /** A payload that is not a calendar is not a day. */
    public function testANonCalendarPayloadIsRefused(): void
    {
        $plugin = $this->plugin(['day_agenda' => true], ['Ics' => "BEGIN:VCARD\r\nFN:Paul\r\nEND:VCARD\r\n"]);
        $aAnswer = $plugin->DoInvitationDay();

        self::assertFalse($aAnswer['success']);
    }

    /**
     * The contract that matters: an unreachable calendar still answers, and
     * the invitation stays answerable. Failing closed here would mean the one
     * moment the calendar is down is the moment the buttons stop working.
     */
    public function testAnUnconfiguredCalendarStillGivesAnAnswerableDay(): void
    {
        $plugin  = $this->plugin(['day_agenda' => true], ['Ics' => self::invitation(), 'Tz' => 'Africa/Tunis']);
        $aAnswer = $plugin->DoInvitationDay();

        self::assertTrue($aAnswer['success']);
        self::assertFalse($aAnswer['read'], 'the calendar was not read');
        self::assertSame([], $aAnswer['busy']);
        self::assertArrayHasKey('error', $aAnswer);
        self::assertSame('2026-09-01T09:00:00+01:00', $aAnswer['slot']['start']);
        self::assertSame('Africa/Tunis', $aAnswer['timezone']);
    }

    /** The window handed to the front end is the reader's day, not the organiser's. */
    public function testTheAnswerCarriesTheReadersWindow(): void
    {
        $plugin  = $this->plugin(['day_agenda' => true],
            ['Ics' => self::invitation('20260901T003000', '20260901T013000'), 'Tz' => 'America/New_York']);
        $aAnswer = $plugin->DoInvitationDay();

        self::assertSame('America/New_York', $aAnswer['timezone']);
        // 00:30 on 1 September in Tunis is still 31 August in New York.
        self::assertStringContainsString('2026-08-31T00:00:00', $aAnswer['from']);
    }
}
