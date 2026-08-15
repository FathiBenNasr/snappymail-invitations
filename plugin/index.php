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
		VERSION     = '1.2.1',
		RELEASE     = '2026-08-15',
		REQUIRED    = '2.36.0',
		CATEGORY    = 'Calendar',
		LICENSE     = 'AGPL-3.0-or-later',
		DESCRIPTION = 'Accept, tentatively accept or decline meeting invitations and store them in your CalDAV calendar.';

	/** Where processed cancellations are recorded, and how many to keep. */
	private const
		CANCELLED_KEY = 'invitations_cancelled',
		CANCELLED_MAX = 200;

	public function Init() : void
	{
		$this->addJs('invitations.js');
		$this->addCss('invitations.css');
		$this->addJsonHook('InvitationRespond', 'DoInvitationRespond');
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
		if (!\strlen($sTemplate)) {
			return '';
		}
		$sDefaultDomain = \strtolower(\trim($this->Config()->Get('plugin', 'dav_default_domain', '')));
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
