<?php
/**
 * Meeting Invitations for SnappyMail
 *
 * Turns an iMIP invitation (a text/calendar part with METHOD:REQUEST) into
 * something the user can act on: Accept, Tentative or Decline, with the answer
 * written straight into their CalDAV calendar.
 *
 * The reply to the organiser is deliberately NOT built here. Under RFC 6638
 * the CalDAV server owns scheduling: writing the event into the user's own
 * calendar with their PARTSTAT set makes the server generate and deliver the
 * METHOD:REPLY itself, and mark the ORGANIZER with SCHEDULE-STATUS. Verified
 * against Cyrus IMAP, which answers such a PUT with SCHEDULE-STATUS=1.1.
 * That keeps this plugin free of iTIP construction and of any SMTP path.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Convergent Cloud Computing
 */
class InvitationsPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME        = 'Meeting Invitations',
		AUTHOR      = 'Convergent Cloud Computing',
		URL         = 'https://www.convergent.tn',
		VERSION     = '1.4.0',
		RELEASE     = '2026-08-29',
		REQUIRED    = '2.36.0',
		CATEGORY    = 'Calendar',
		LICENSE     = 'AGPL-3.0-or-later',
		DESCRIPTION = 'Accept, tentatively accept or decline meeting invitations, with the day they land on shown beside them, and store them in your CalDAV calendar.';

	/** Where processed cancellations are recorded, and how many to keep. */
	private const
		CANCELLED_KEY = 'invitations_cancelled',
		CANCELLED_MAX = 200;

	/**
	 * The day drawn beside an invitation. The cap is on what is *returned*,
	 * not on what the server may hold: a calendar with two hundred blocks on
	 * one day is a machine account, and a strip of two hundred blocks tells
	 * the reader nothing anyway.
	 */
	private const DAY_MAX_BLOCKS = 60;

	public function Init() : void
	{
		$this->addJs('invitations.js');
		$this->addCss('invitations.css');
		$this->addJsonHook('InvitationRespond', 'DoInvitationRespond');
		$this->addJsonHook('InvitationDay', 'DoInvitationDay');
	}

	protected function configMapping() : array
	{
		return array(
			\RainLoop\Plugins\Property::NewInstance('caldav_url_template')
				->SetLabel('CalDAV URL template')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::STRING)
				->SetDescription('Calendar collection that answers get written to, e.g.'
					. ' https://dav.example.com/dav/calendars/user/{user}/Default/'
					. ' - {user} = mailbox name as the DAV server knows it, {email} = full'
					. ' address, {login} = local part, {domain} = domain part.')
				->SetDefaultValue(''),
			\RainLoop\Plugins\Property::NewInstance('dav_default_domain')
				->SetLabel('DAV default domain')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::STRING)
				->SetDescription('Addresses in this domain are addressed by local part only.'
					. ' Leave empty to always use the full address.')
				->SetDefaultValue(''),
			\RainLoop\Plugins\Property::NewInstance('day_agenda')
				->SetLabel('Show the day beside the invitation')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDescription('Reads the day the meeting falls on from the same CalDAV'
					. ' collection and draws it next to the buttons, so a clash is visible'
					. ' before the answer is given. One extra request per invitation opened.')
				->SetDefaultValue(true),
			\RainLoop\Plugins\Property::NewInstance('verify_peer')
				->SetLabel('Verify the DAV server certificate')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(true)
		);
	}

	/**
	 * Expand the configured template for one account.
	 */
	private function davUrl(string $sEmail) : string
	{
		$sTemplate = \trim($this->Config()->Get('plugin', 'caldav_url_template', ''));
		$sDefaultDomain = \strtolower(\trim($this->Config()->Get('plugin', 'dav_default_domain', '')));

		// Fall back to the caldav plugin's own settings when this one has not
		// been filled in. Both plugins write to the same calendars on the same
		// server, so two settings pages that must be kept agreeing is a support
		// call waiting to happen - a URL corrected in one place and still wrong
		// in the other looks exactly like a broken server. Whatever is
		// configured here still wins, so an existing install is unaffected.
		if (!\strlen($sTemplate)) {
			$aBorrowed = $this->caldavPluginSettings();
			$sTemplate = $aBorrowed['caldav_url_template'];
			if (!\strlen($sDefaultDomain)) {
				$sDefaultDomain = \strtolower($aBorrowed['dav_default_domain']);
			}
		}
		if (!\strlen($sTemplate)) {
			return '';
		}
		$aParts  = \explode('@', $sEmail, 2);
		$sLogin  = $aParts[0];
		$sDomain = $aParts[1] ?? '';
		$sUser   = ($sDefaultDomain && \strtolower($sDomain) === $sDefaultDomain) ? $sLogin : $sEmail;
		return \strtr($sTemplate, array(
			'{user}'   => $sUser,
			'{email}'  => $sEmail,
			'{login}'  => $sLogin,
			'{domain}' => $sDomain
		));
	}

	/**
	 * The caldav plugin's stored settings, read straight from its config file.
	 *
	 * There is no supported way for one plugin to ask another for a setting -
	 * Config() is scoped to the plugin that calls it - and the alternative,
	 * making the calendar plugin a hard dependency, would stop invitations
	 * working on a deployment that does not have it. Reading the file is
	 * tolerant instead: absent, unreadable or unset all give the same empty
	 * answer, and the settings on this plugin's own page still take precedence.
	 *
	 * @return array{caldav_url_template:string, dav_default_domain:string}
	 */
	private function caldavPluginSettings() : array
	{
		$aEmpty = array('caldav_url_template' => '', 'dav_default_domain' => '');
		try {
			if (!\defined('APP_PRIVATE_DATA')) {
				return $aEmpty;
			}
			$sFile = \APP_PRIVATE_DATA . 'configs/plugin-caldav.json';
			if (!\is_readable($sFile)) {
				return $aEmpty;
			}
			$aData = \json_decode((string) \file_get_contents($sFile), true);
			$aPlugin = (\is_array($aData) && isset($aData['plugin']) && \is_array($aData['plugin']))
				? $aData['plugin'] : array();
			return array(
				'caldav_url_template' => \trim((string) ($aPlugin['caldav_url_template'] ?? '')),
				'dav_default_domain'  => \trim((string) ($aPlugin['dav_default_domain'] ?? ''))
			);
		} catch (\Throwable $oException) {
			return $aEmpty;
		}
	}

	/**
	 * Every address that counts as "this user", so the right ATTENDEE is found
	 * even when the invitation was sent to an alias or an identity.
	 */
	private function ownAddresses(\RainLoop\Model\Account $oAccount) : array
	{
		$aResult = array(\strtolower($oAccount->Email()));
		try {
			foreach ($this->Manager()->Actions()->GetIdentities($oAccount) as $oIdentity) {
				$sEmail = \strtolower(\trim($oIdentity->Email()));
				if (\strlen($sEmail)) {
					$aResult[] = $sEmail;
				}
			}
		} catch (\Throwable $oException) {
			// Identities are a bonus; the account address alone still works.
		}
		return \array_unique($aResult);
	}

	public function DoInvitationRespond() : array
	{
		$oActions = $this->Manager()->Actions();
		$oAccount = $oActions->getAccountFromToken();
		if (!$oAccount) {
			return $this->jsonResponse(__FUNCTION__, ['success' => false, 'error' => 'Not logged in']);
		}

		$sIcs      = (string) $this->jsonParam('Ics', '');
		$sMode     = \strtolower((string) $this->jsonParam('Mode', 'respond'));
		$sPartStat = \strtoupper((string) $this->jsonParam('PartStat', ''));
		$bCancel   = ('cancel' === $sMode);
		if (!$bCancel && !\in_array($sPartStat, ['ACCEPTED', 'TENTATIVE', 'DECLINED'], true)) {
			return $this->jsonResponse(__FUNCTION__, ['success' => false, 'error' => 'Invalid PARTSTAT']);
		}
		if (!\strlen($sIcs)) {
			return $this->jsonResponse(__FUNCTION__, ['success' => false, 'error' => 'No calendar data']);
		}

		$sUrl = $this->davUrl($oAccount->Email());
		if (!\strlen($sUrl)) {
			return $this->jsonResponse(__FUNCTION__, ['success' => false,
				'error' => 'No CalDAV URL configured for this plugin']);
		}

		try {
			$oVCal = \Sabre\VObject\Reader::read($sIcs, \Sabre\VObject\Reader::OPTION_FORGIVING);
			if (!($oVCal instanceof \Sabre\VObject\Component\VCalendar) || !isset($oVCal->VEVENT)) {
				return $this->jsonResponse(__FUNCTION__, ['success' => false, 'error' => 'Not a calendar event']);
			}

			$sUid = (string) $oVCal->VEVENT->UID;
			if (!\strlen($sUid)) {
				return $this->jsonResponse(__FUNCTION__, ['success' => false, 'error' => 'Event has no UID']);
			}
			$sEventUrl = \rtrim($sUrl, '/') . '/' . \rawurlencode($sUid) . '.ics';
			$iSequence = (int) ((string) ($oVCal->VEVENT->SEQUENCE ?? '0'));

			// A CANCEL withdraws the meeting: drop our copy rather than storing
			// a scheduling message. Absent from the calendar is not an error -
			// the user may simply never have accepted it.
			if ($bCancel) {
				$iStatus = $this->request($oAccount, 'DELETE', $sEventUrl);
				$bDone = (404 === $iStatus || 300 > $iStatus);
				if ($bDone) {
					// Record it. The SEQUENCE check below compares against the
					// copy in the calendar, which this DELETE has just removed,
					// so without a record the original invitation mail can still
					// be accepted afterwards and the meeting comes back.
					$this->rememberCancelled($oAccount, $sUid, $iSequence,
						$this->eventEndsAt($oVCal->VEVENT));
				}
				return $this->jsonResponse(__FUNCTION__, [
					'success'  => $bDone,
					'partstat' => 'CANCELLED',
					'status'   => $iStatus
				]);
			}

			// Refuse an invitation the organiser has already withdrawn. A later
			// revision is allowed through: that is the organiser reinstating the
			// meeting, not the stale mail being answered again.
			$aCancelled = $this->loadCancelled($oAccount);
			if (isset($aCancelled[$sUid])
			 && $iSequence <= (int) ($aCancelled[$sUid]['sequence'] ?? 0)) {
				return $this->jsonResponse(__FUNCTION__, ['success' => false,
					'error' => 'This meeting was cancelled by the organizer']);
			}

			// SEQUENCE orders revisions of the same UID (RFC 5545 3.8.7.4). If
			// the calendar already holds a newer revision, this invitation has
			// been superseded - answering it would resurrect stale details.
			$sExisting = $this->fetch($oAccount, $sEventUrl);
			if (null !== $sExisting
			 && \preg_match('/^SEQUENCE:(\d+)/mi', \str_replace("\r\n ", '', $sExisting), $aSeq)
			 && (int) $aSeq[1] > $iSequence) {
				return $this->jsonResponse(__FUNCTION__, ['success' => false,
					'error' => 'A newer version of this invitation is already in your calendar'
						. " (sequence {$aSeq[1]} vs {$iSequence})"]);
			}

			$aOwn   = $this->ownAddresses($oAccount);
			$bFound = false;
			foreach ($oVCal->VEVENT as $oEvent) {
				if (isset($oEvent->ATTENDEE)) {
					foreach ($oEvent->ATTENDEE as $oAttendee) {
						$sAddr = \strtolower(\preg_replace('#^mailto:#i', '', \trim((string) $oAttendee)));
						if (\in_array($sAddr, $aOwn, true)) {
							$oAttendee['PARTSTAT'] = $sPartStat;
							$oAttendee['RSVP'] = 'FALSE';
							$bFound = true;
						}
					}
				}
			}
			if (!$bFound) {
				return $this->jsonResponse(__FUNCTION__, ['success' => false,
					'error' => 'This invitation is not addressed to any of your identities']);
			}

			// The stored copy is a plain event, not a scheduling message.
			unset($oVCal->METHOD);

			$oResponse = $this->put($oAccount, $sEventUrl, $oVCal->serialize());

			return $this->jsonResponse(__FUNCTION__, [
				'success'   => true,
				'partstat'  => $sPartStat,
				'sequence'  => $iSequence,
				// Present when the server took care of replying to the organiser.
				'scheduled' => $oResponse
			]);
		} catch (\Throwable $oException) {
			\SnappyMail\Log::error('Invitations', $oException->getMessage());
			return $this->jsonResponse(__FUNCTION__, ['success' => false, 'error' => $oException->getMessage()]);
		}
	}

	/**
	 * The day an invitation lands on, as the user's own calendar already holds it.
	 *
	 * Answering a meeting request is a scheduling decision, and today it is
	 * taken blind: the mail says Tuesday 16:00 and the calendar is one screen
	 * away. This reads the same CalDAV collection the answer is written to,
	 * for that one day, and hands back a thin list of blocks - start, end,
	 * summary - which is all a strip beside the buttons needs.
	 *
	 * Nothing here is authoritative and nothing here blocks. A collection that
	 * cannot be read answers `success` with an empty day and a reason: the
	 * invitation must stay answerable when the calendar is down, which is
	 * exactly when it is least convenient to be asked to dismiss an error.
	 */
	public function DoInvitationDay() : array
	{
		$oActions = $this->Manager()->Actions();
		$oAccount = $oActions->getAccountFromToken();
		if (!$oAccount) {
			return $this->jsonResponse(__FUNCTION__, ['success' => false, 'error' => 'Not logged in']);
		}
		if (!$this->Config()->Get('plugin', 'day_agenda', true)) {
			return $this->jsonResponse(__FUNCTION__, ['success' => false, 'error' => 'Disabled']);
		}

		$sIcs = (string) $this->jsonParam('Ics', '');
		if (!\strlen($sIcs)) {
			return $this->jsonResponse(__FUNCTION__, ['success' => false, 'error' => 'No calendar data']);
		}

		try {
			$oVCal = \Sabre\VObject\Reader::read($sIcs, \Sabre\VObject\Reader::OPTION_FORGIVING);
			if (!($oVCal instanceof \Sabre\VObject\Component\VCalendar) || !isset($oVCal->VEVENT)) {
				return $this->jsonResponse(__FUNCTION__, ['success' => false, 'error' => 'Not a calendar event']);
			}
			$oEvent = $oVCal->VEVENT;
			if (!isset($oEvent->DTSTART)) {
				return $this->jsonResponse(__FUNCTION__, ['success' => false, 'error' => 'Event has no start']);
			}

			$oTz = $this->readTimeZone((string) $this->jsonParam('Tz', ''), $oEvent);
			// The span first, then the day around it: a whole-day invitation
			// has a floating DATE for a start, and reading it before the zone
			// is known puts it on midnight of whatever zone PHP was started
			// with - which is the day before, half the time.
			list($oSlotStart, $oSlotEnd) = self::eventSpan($oEvent, $oTz);
			list($oFrom, $oTo) = self::dayWindow($oSlotStart, $oTz);

			$aAnswer = array(
				'success'  => true,
				'timezone' => $oTz->getName(),
				'from'     => $oFrom->format(\DateTimeInterface::ATOM),
				'to'       => $oTo->format(\DateTimeInterface::ATOM),
				'allDay'   => !$oEvent->DTSTART->hasTime(),
				'slot'     => array(
					'start' => $oSlotStart->format(\DateTimeInterface::ATOM),
					'end'   => $oSlotEnd->format(\DateTimeInterface::ATOM)
				),
				'busy'     => array(),
				'read'     => false
			);

			$sUrl = $this->davUrl($oAccount->Email());
			if (!\strlen($sUrl)) {
				$aAnswer['error'] = 'No CalDAV URL configured for this plugin';
				return $this->jsonResponse(__FUNCTION__, $aAnswer);
			}

			$sXml = $this->report($oAccount, $sUrl, self::calendarQuery($oFrom, $oTo));
			if (null === $sXml) {
				$aAnswer['error'] = 'The calendar could not be read';
				return $this->jsonResponse(__FUNCTION__, $aAnswer);
			}

			$aAnswer['read'] = true;
			$aAnswer['busy'] = self::busyBlocks(self::calendarData($sXml), $oFrom, $oTo,
				(string) $oEvent->UID, $oTz, $oSlotStart, $oSlotEnd);

			return $this->jsonResponse(__FUNCTION__, $aAnswer);
		} catch (\Throwable $oException) {
			\SnappyMail\Log::notice('Invitations', 'day view: ' . $oException->getMessage());
			return $this->jsonResponse(__FUNCTION__, ['success' => false, 'error' => $oException->getMessage()]);
		}
	}

	/**
	 * Which zone the day is cut in.
	 *
	 * The reader's own zone wins: they are the one deciding, and 16:00 in
	 * Paris is not the hour they are looking at in Tunis. It arrives from the
	 * browser, so it is checked against the zone database rather than trusted
	 * - an identifier goes on to build a day boundary, and a name PHP does not
	 * know throws. Unknown or absent falls back to the zone the organiser
	 * wrote into the invitation, then to UTC.
	 */
	private function readTimeZone(string $sWanted, $oEvent) : \DateTimeZone
	{
		$sWanted = \trim($sWanted);
		if (\strlen($sWanted) && \in_array($sWanted, \DateTimeZone::listIdentifiers(), true)) {
			return new \DateTimeZone($sWanted);
		}
		try {
			if (isset($oEvent->DTSTART)) {
				return $oEvent->DTSTART->getDateTime()->getTimezone();
			}
		} catch (\Throwable $oException) {
			// An invitation with an unreadable zone still has a day in UTC.
		}
		return new \DateTimeZone('UTC');
	}

	/**
	 * The local day containing an instant, as a half-open window.
	 *
	 * `+1 day` rather than `+24 hours` on purpose: on the night a zone changes
	 * offset the day is 23 or 25 hours long, and a fixed 24 either loses an
	 * hour of the evening or borrows one from tomorrow.
	 *
	 * @return array{0:\DateTimeImmutable, 1:\DateTimeImmutable}
	 */
	private static function dayWindow(\DateTimeInterface $oInstant, \DateTimeZone $oTz) : array
	{
		$oLocal = \DateTimeImmutable::createFromInterface($oInstant)->setTimezone($oTz);
		$oFrom  = $oLocal->setTime(0, 0, 0);
		return array($oFrom, $oFrom->modify('+1 day'));
	}

	/**
	 * When a VEVENT starts and ends, whichever way it says so.
	 *
	 * DTEND, then DURATION, then the RFC 5545 defaults: a whole-day event with
	 * neither lasts one day, a timed one lasts nothing.
	 *
	 * The zone is a *reference*, not an override: a DTSTART carrying a TZID
	 * keeps it, and the argument decides only where a floating time or a bare
	 * DATE lands. Passing it matters for whole-day entries, which otherwise
	 * fall on midnight of whatever zone the PHP process was started with.
	 *
	 * @return array{0:\DateTimeImmutable, 1:\DateTimeImmutable}
	 */
	private static function eventSpan($oEvent, ?\DateTimeZone $oTz = null) : array
	{
		$oStart = \DateTimeImmutable::createFromInterface($oEvent->DTSTART->getDateTime($oTz));
		if (isset($oEvent->DTEND)) {
			return array($oStart, \DateTimeImmutable::createFromInterface($oEvent->DTEND->getDateTime($oTz)));
		}
		if (isset($oEvent->DURATION)) {
			return array($oStart, $oStart->add(
				\Sabre\VObject\DateTimeParser::parseDuration((string) $oEvent->DURATION)));
		}
		return array($oStart, $oEvent->DTSTART->hasTime() ? $oStart : $oStart->modify('+1 day'));
	}

	/**
	 * The REPORT body asking one collection for one day.
	 *
	 * The two instants are formatted, never interpolated: `format()` on a
	 * DateTime can only ever produce `\d{8}T\d{6}Z`, so no caller-supplied
	 * character reaches the XML. That is the reason this takes DateTimes and
	 * not strings.
	 *
	 * Recurrences are expanded here rather than asked for with `<C:expand>`:
	 * expand is optional in RFC 4791 and a server that ignores it answers with
	 * the master events, silently, and the day would then be missing every
	 * weekly meeting on it.
	 */
	private static function calendarQuery(\DateTimeInterface $oFrom, \DateTimeInterface $oTo) : string
	{
		$sFrom = $oFrom->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
		$sTo   = $oTo->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
		return '<?xml version="1.0" encoding="utf-8" ?>'
			. '<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
			. '<D:prop><D:getetag /><C:calendar-data /></D:prop>'
			. '<C:filter><C:comp-filter name="VCALENDAR">'
			. '<C:comp-filter name="VEVENT">'
			. '<C:time-range start="' . $sFrom . '" end="' . $sTo . '"/>'
			. '</C:comp-filter></C:comp-filter></C:filter>'
			. '</C:calendar-query>';
	}

	/**
	 * The calendar objects out of a multistatus, by namespace and not by tag.
	 *
	 * Servers differ on the prefix - `C:`, `cal:`, `caldav:` - so matching the
	 * literal name finds nothing on half of them. LIBXML_NONET is set because
	 * this document comes off the network: nothing in it may cause a fetch.
	 *
	 * @return string[]
	 */
	private static function calendarData(string $sXml) : array
	{
		$aResult = array();
		if (!\strlen(\trim($sXml))) {
			return $aResult;
		}
		$bPrevious = \libxml_use_internal_errors(true);
		try {
			$oDoc = new \DOMDocument();
			if (!$oDoc->loadXML($sXml, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING)) {
				return $aResult;
			}
			foreach ($oDoc->getElementsByTagNameNS('urn:ietf:params:xml:ns:caldav', 'calendar-data') as $oNode) {
				$sText = \trim((string) $oNode->textContent);
				if (\strlen($sText)) {
					$aResult[] = $sText;
				}
			}
		} catch (\Throwable $oException) {
			// A malformed answer is an empty day, not an exception in the mail view.
		} finally {
			\libxml_clear_errors();
			\libxml_use_internal_errors($bPrevious);
		}
		return $aResult;
	}

	/**
	 * The blocks to draw: what actually occupies the reader on that day.
	 *
	 * Three things are left out on purpose. The invitation's own UID, because
	 * a meeting already accepted would otherwise be shown clashing with
	 * itself. TRANSP:TRANSPARENT, because its author said it does not take
	 * their time. STATUS:CANCELLED, because a withdrawn meeting is not a
	 * reason to refuse another.
	 *
	 * Whole-day entries are kept but never counted as a clash: "on leave" and
	 * "public holiday" are worth seeing above the strip, and a meeting is not
	 * impossible because a day is labelled.
	 *
	 * @param string[] $aObjects
	 */
	private static function busyBlocks(array $aObjects, \DateTimeInterface $oFrom, \DateTimeInterface $oTo,
		string $sSkipUid, \DateTimeZone $oTz, \DateTimeInterface $oSlotStart, \DateTimeInterface $oSlotEnd) : array
	{
		$aBlocks = array();
		$sSkipUid = \trim($sSkipUid);

		foreach ($aObjects as $sObject) {
			try {
				$oVCal = \Sabre\VObject\Reader::read($sObject, \Sabre\VObject\Reader::OPTION_FORGIVING);
				if (!($oVCal instanceof \Sabre\VObject\Component\VCalendar)) {
					continue;
				}
				// Recurrence is resolved here: the day needs the instances that
				// fall on it, not the rule that generates them.
				try {
					$oVCal = $oVCal->expand($oFrom, $oTo, $oTz);
				} catch (\Throwable $oException) {
					// A rule this library will not walk still has a master
					// event, and drawing that is better than drawing nothing.
				}
				if (!isset($oVCal->VEVENT)) {
					continue;
				}
				foreach ($oVCal->VEVENT as $oEvent) {
					if (!isset($oEvent->DTSTART)) {
						continue;
					}
					$sUid = \trim((string) ($oEvent->UID ?? ''));
					if (\strlen($sSkipUid) && $sUid === $sSkipUid) {
						continue;
					}
					if ('CANCELLED' === \strtoupper(\trim((string) ($oEvent->STATUS ?? '')))
					 || 'TRANSPARENT' === \strtoupper(\trim((string) ($oEvent->TRANSP ?? '')))) {
						continue;
					}
					list($oStart, $oEnd) = self::eventSpan($oEvent, $oTz);
					// Half-open on both sides: a meeting ending at 14:00 does
					// not occupy the one starting at 14:00.
					if ($oStart >= $oTo || $oEnd <= $oFrom) {
						continue;
					}
					$bAllDay = !$oEvent->DTSTART->hasTime();
					$aBlocks[] = array(
						'uid'     => $sUid,
						'summary' => \trim((string) ($oEvent->SUMMARY ?? '')),
						'start'   => $oStart->format(\DateTimeInterface::ATOM),
						'end'     => $oEnd->format(\DateTimeInterface::ATOM),
						'allDay'  => $bAllDay,
						'clash'   => !$bAllDay && $oStart < $oSlotEnd && $oEnd > $oSlotStart,
						'sort'    => $oStart->getTimestamp()
					);
				}
			} catch (\Throwable $oException) {
				// One unreadable object must not empty the whole day.
				continue;
			}
		}

		\usort($aBlocks, function (array $a, array $b) {
			return $a['sort'] <=> $b['sort'];
		});
		$aBlocks = \array_slice($aBlocks, 0, self::DAY_MAX_BLOCKS);
		foreach ($aBlocks as &$aBlock) {
			unset($aBlock['sort']);
		}
		unset($aBlock);
		return $aBlocks;
	}

	/**
	 * Issue a REPORT and hand back the multistatus body, or null.
	 *
	 * Depth is sent because RFC 4791 defines a calendar-query against a
	 * collection at depth 1; a server that defaults to 0 answers about the
	 * collection itself and returns no events at all, which reads exactly like
	 * an empty day.
	 */
	private function report(\RainLoop\Model\Account $oAccount, string $sUrl, string $sBody) : ?string
	{
		$oResponse = $this->http($oAccount)->doRequest('REPORT', $sUrl, $sBody, array(
			'Content-Type' => 'application/xml; charset=utf-8',
			'Depth' => '1'
		));
		$iStatus = $oResponse ? $oResponse->status : 0;
		if (207 !== $iStatus) {
			\SnappyMail\Log::notice('Invitations', "REPORT {$sUrl} ({$iStatus})");
			return null;
		}
		return $oResponse->body;
	}

	/**
	 * Cancellations this account has processed, keyed by UID.
	 *
	 * Needed because a cancellation deletes the calendar copy that the
	 * SEQUENCE check would otherwise compare against, leaving nothing to stop
	 * the original invitation mail being accepted again afterwards.
	 */
	private function loadCancelled(\RainLoop\Model\Account $oAccount) : array
	{
		try {
			$mData = $this->Manager()->Actions()->StorageProvider()->Get($oAccount,
				\RainLoop\Providers\Storage\Enumerations\StorageType::CONFIG,
				self::CANCELLED_KEY);
			if ($mData && \is_string($mData)) {
				$aData = \json_decode($mData, true);
				if (\is_array($aData)) {
					return $aData;
				}
			}
		} catch (\Throwable $oException) {
			// An unreadable store must not stop the user answering an invitation.
		}
		return array();
	}

	private function rememberCancelled(\RainLoop\Model\Account $oAccount, string $sUid,
		int $iSequence, int $iEndsAt) : void
	{
		$aList = $this->loadCancelled($oAccount);
		$aList[$sUid] = array('sequence' => $iSequence, 'until' => $iEndsAt);

		// Keep it from growing without bound: drop entries for meetings that
		// have already finished, then cap what is left.
		$iNow = \time();
		$aList = \array_filter($aList, function ($aEntry) use ($iNow) {
			return empty($aEntry['until']) || $aEntry['until'] > $iNow;
		});
		if (self::CANCELLED_MAX < \count($aList)) {
			$aList = \array_slice($aList, -self::CANCELLED_MAX, null, true);
		}

		try {
			$this->Manager()->Actions()->StorageProvider()->Put($oAccount,
				\RainLoop\Providers\Storage\Enumerations\StorageType::CONFIG,
				self::CANCELLED_KEY, \json_encode($aList));
		} catch (\Throwable $oException) {
			\SnappyMail\Log::notice('Invitations', 'could not record the cancellation: '
				. $oException->getMessage());
		}
	}

	/**
	 * When the meeting finishes, as a unix timestamp. Used only to expire the
	 * record above, so a rough answer is fine; falls back to a day after the
	 * start, then to a week from now.
	 */
	private function eventEndsAt($oEvent) : int
	{
		try {
			if (isset($oEvent->DTEND)) {
				return $oEvent->DTEND->getDateTime()->getTimestamp();
			}
			if (isset($oEvent->DTSTART)) {
				return $oEvent->DTSTART->getDateTime()->getTimestamp() + 86400;
			}
		} catch (\Throwable $oException) {
			// fall through
		}
		return \time() + (7 * 86400);
	}

	/**
	 * GET the current copy of an event, or null when it is not there.
	 */
	private function fetch(\RainLoop\Model\Account $oAccount, string $sUrl) : ?string
	{
		$oResponse = $this->http($oAccount)->doRequest('GET', $sUrl);
		return ($oResponse && 200 === $oResponse->status) ? $oResponse->body : null;
	}

	/**
	 * Issue a request that has no body and report the status.
	 */
	private function request(\RainLoop\Model\Account $oAccount, string $sMethod, string $sUrl) : int
	{
		$oResponse = $this->http($oAccount)->doRequest($sMethod, $sUrl);
		$iStatus = $oResponse ? $oResponse->status : 0;
		\SnappyMail\Log::info('Invitations', "{$sMethod} {$sUrl} ({$iStatus})");
		return $iStatus;
	}

	private function http(\RainLoop\Model\Account $oAccount) : \SnappyMail\HTTP\Request
	{
		$oHTTP = \SnappyMail\HTTP\Request::factory();
		$oHTTP->verify_peer = !!$this->Config()->Get('plugin', 'verify_peer', true);
		$oHTTP->timeout = 20;
		$oHTTP->setAuth(3, $oAccount->Email(), $oAccount->ImapPass());
		return $oHTTP;
	}

	/**
	 * PUT the event into the user's calendar using their own credentials.
	 */
	private function put(\RainLoop\Model\Account $oAccount, string $sUrl, string $sBody) : bool
	{
		$oResponse = $this->http($oAccount)->doRequest('PUT', $sUrl, $sBody,
			array('Content-Type' => 'text/calendar; charset=utf-8'));
		if (!$oResponse || 300 <= $oResponse->status) {
			throw new \RuntimeException('CalDAV PUT failed with status '
				. ($oResponse ? $oResponse->status : 'none'));
		}
		\SnappyMail\Log::info('Invitations', "stored {$sUrl} ({$oResponse->status})");
		return true;
	}
}
