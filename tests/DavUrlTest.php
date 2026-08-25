<?php
/**
 * Invitations — the CalDAV URL, and the record of declined meetings.
 *
 * Answering an invitation means writing to the organiser's calendar over
 * CalDAV, so the URL template decides which mailbox is touched. Getting the
 * {user} substitution wrong sends the reply to the wrong calendar — or to a
 * URL that does not exist, which looks to the user like a broken server.
 *
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/../plugin/index.php';

final class DavUrlTest extends TestCase
{
    private function plugin(array $config = []): InvitationsPlugin
    {
        $actions             = new \RainLoop\Actions();
        $actions->account    = new \RainLoop\Account('paul@convergent.tn');
        $plugin              = new InvitationsPlugin();
        $plugin->actionsStub = $actions;
        $plugin->config      = $config;
        return $plugin;
    }

    private function call(object $plugin, string $method, ...$args)
    {
        return (new ReflectionMethod($plugin, $method))->invoke($plugin, ...$args);
    }

    private const TEMPLATE = 'https://dav.convergent.tn/dav/calendars/{user}/Default/';

    // ------------------------------------------------------ substitution

    /** Each placeholder is replaced by the part of the address it names. */
    public function testEveryPlaceholderIsSubstituted(): void
    {
        $plugin = $this->plugin(['caldav_url_template' => '{user}|{email}|{login}|{domain}']);

        self::assertSame(
            'paul@example.org|paul@example.org|paul|example.org',
            $this->call($plugin, 'davUrl', 'paul@example.org')
        );
    }

    /**
     * On the local domain the server expects a bare login, not the full
     * address: the default domain is stripped, and only then.
     */
    public function testTheDefaultDomainIsStrippedFromTheUserPlaceholder(): void
    {
        $plugin = $this->plugin([
            'caldav_url_template' => self::TEMPLATE,
            'dav_default_domain'  => 'convergent.tn',
        ]);

        self::assertSame('https://dav.convergent.tn/dav/calendars/paul/Default/',
            $this->call($plugin, 'davUrl', 'paul@convergent.tn'));

        self::assertSame('https://dav.convergent.tn/dav/calendars/paul@elsewhere.org/Default/',
            $this->call($plugin, 'davUrl', 'paul@elsewhere.org'),
            'an outside address keeps its domain');
    }

    /** The domain comparison ignores case, as DNS does. */
    public function testTheDomainComparisonIsCaseInsensitive(): void
    {
        $plugin = $this->plugin([
            'caldav_url_template' => '{user}',
            'dav_default_domain'  => 'Convergent.TN',
        ]);

        foreach (['paul@convergent.tn', 'paul@CONVERGENT.TN', 'paul@Convergent.Tn'] as $email) {
            self::assertSame('paul', $this->call($plugin, 'davUrl', $email), $email);
        }
    }

    /** Without a default domain, the full address is always used. */
    public function testWithoutADefaultDomainTheFullAddressIsUsed(): void
    {
        $plugin = $this->plugin(['caldav_url_template' => '{user}']);

        self::assertSame('paul@convergent.tn', $this->call($plugin, 'davUrl', 'paul@convergent.tn'));
    }

    /** No template configured means no URL — not a half-built one. */
    public function testAnUnconfiguredTemplateYieldsNothing(): void
    {
        self::assertSame('', $this->call($this->plugin(), 'davUrl', 'paul@convergent.tn'));
        self::assertSame('', $this->call($this->plugin(['caldav_url_template' => '   ']), 'davUrl', 'paul@convergent.tn'));
    }

    /** An address with no domain part must not produce a stray separator. */
    public function testAnAddressWithoutADomainIsHandled(): void
    {
        $plugin = $this->plugin(['caldav_url_template' => '{login}/{domain}']);

        self::assertSame('paul/', $this->call($plugin, 'davUrl', 'paul'));
    }

    /** An address containing a second @ keeps it in the domain part. */
    public function testTheFirstAtSeparatesLoginFromDomain(): void
    {
        $plugin = $this->plugin(['caldav_url_template' => '{login}|{domain}']);

        // explode with a limit of 2: everything after the first @ is the domain.
        self::assertSame('a|b@c.tn', $this->call($plugin, 'davUrl', 'a@b@c.tn'));
    }

    // --------------------------------------------- declined-meeting record

    /** What was declined is remembered, keyed by the event's UID. */
    public function testACancellationIsRecorded(): void
    {
        $plugin  = $this->plugin();
        $account = new \RainLoop\Model\Account('paul@convergent.tn');

        $this->call($plugin, 'rememberCancelled', $account, 'uid-1', 3, time() + 3600);
        $list = $this->call($plugin, 'loadCancelled', $account);

        self::assertSame(3, $list['uid-1']['sequence'] ?? null);
    }

    /**
     * A meeting that has already finished is dropped on the next write, so the
     * record cannot grow for ever in a mailbox that answers many invitations.
     */
    public function testFinishedMeetingsAreForgotten(): void
    {
        $plugin  = $this->plugin();
        $account = new \RainLoop\Model\Account('paul@convergent.tn');

        $this->call($plugin, 'rememberCancelled', $account, 'old', 1, time() - 3600);
        $this->call($plugin, 'rememberCancelled', $account, 'new', 1, time() + 3600);

        $list = $this->call($plugin, 'loadCancelled', $account);
        self::assertArrayHasKey('new', $list);
        self::assertFalse(array_key_exists('old', $list), 'a finished meeting must be dropped');
    }

    /** And the list is capped, oldest first, whatever the expiry dates say. */
    public function testTheRecordIsCappedAtItsMaximum(): void
    {
        $plugin  = $this->plugin();
        $account = new \RainLoop\Model\Account('paul@convergent.tn');
        $max     = (new ReflectionClass(InvitationsPlugin::class))->getConstant('CANCELLED_MAX');

        for ($i = 0; $i <= $max + 5; $i++) {
            $this->call($plugin, 'rememberCancelled', $account, 'uid-' . $i, 1, time() + 86400);
        }

        $list = $this->call($plugin, 'loadCancelled', $account);
        self::assertTrue($max >= count($list), 'the record must not exceed its cap');
        self::assertArrayHasKey('uid-' . ($max + 5), $list, 'the newest entry is kept');
    }

    /** A corrupted store is treated as empty rather than fatal. */
    public function testAnUnreadableRecordDoesNotStopTheUserAnswering(): void
    {
        $plugin  = $this->plugin();
        $account = new \RainLoop\Model\Account('paul@convergent.tn');
        $key     = (new ReflectionClass(InvitationsPlugin::class))->getConstant('CANCELLED_KEY');

        $plugin->actionsStub->storage[$key] = 'not json at all';

        self::assertSame([], $this->call($plugin, 'loadCancelled', $account));
    }

    // ------------------------------------------------------- event end

    /** DTEND is used when the event has one. */
    public function testTheEventEndIsTakenFromDtend(): void
    {
        $end   = 1786000000;
        $event = (object) ['DTEND' => $this->icalTime($end), 'DTSTART' => $this->icalTime($end - 3600)];

        self::assertSame($end, $this->call($this->plugin(), 'eventEndsAt', $event));
    }

    /** Without DTEND, a day after the start is close enough to expire a record. */
    public function testWithoutDtendADayAfterTheStartIsUsed(): void
    {
        $start = 1786000000;
        $event = (object) ['DTSTART' => $this->icalTime($start)];

        self::assertSame($start + 86400, $this->call($this->plugin(), 'eventEndsAt', $event));
    }

    /** An event with neither, or with an unreadable date, still expires. */
    public function testAnUndatedEventStillGetsAnExpiry(): void
    {
        $now = time();
        foreach ([(object) [], (object) ['DTSTART' => (object) []]] as $event) {
            $end = $this->call($this->plugin(), 'eventEndsAt', $event);
            self::assertTrue($end > $now, 'must be in the future');
            self::assertTrue($end <= $now + (7 * 86400) + 5, 'and no more than a week out');
        }
    }

    /** A property shaped like Sabre's, with getDateTime(). */
    private function icalTime(int $timestamp): object
    {
        return new class ($timestamp) {
            public function __construct(private int $ts) {}
            public function getDateTime(): \DateTimeInterface
            { return (new \DateTimeImmutable())->setTimestamp($this->ts); }
        };
    }
}
