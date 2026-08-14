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
 */
class InvitationsPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME        = 'Meeting Invitations',
		AUTHOR      = 'Convergent Cloud Computing',
		URL         = 'https://www.convergent.tn',
		VERSION     = '1.0.1',
		RELEASE     = '2026-08-13',
		REQUIRED    = '2.36.0',
		CATEGORY    = 'Calendar',
		LICENSE     = 'MIT',
		DESCRIPTION = 'Accept, tentatively accept or decline meeting invitations and store them in your CalDAV calendar.';

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
		$sPartStat = \strtoupper((string) $this->jsonParam('PartStat', ''));
		if (!\in_array($sPartStat, ['ACCEPTED', 'TENTATIVE', 'DECLINED'], true)) {
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

			$sUid = (string) $oVCal->VEVENT->UID;
			if (!\strlen($sUid)) {
				return $this->jsonResponse(__FUNCTION__, ['success' => false, 'error' => 'Event has no UID']);
			}

			$oResponse = $this->put($oAccount, \rtrim($sUrl, '/') . '/' . \rawurlencode($sUid) . '.ics',
				$oVCal->serialize());

			return $this->jsonResponse(__FUNCTION__, [
				'success'  => true,
				'partstat' => $sPartStat,
				// Present when the server took care of replying to the organiser.
				'scheduled' => $oResponse
			]);
		} catch (\Throwable $oException) {
			\SnappyMail\Log::error('Invitations', $oException->getMessage());
			return $this->jsonResponse(__FUNCTION__, ['success' => false, 'error' => $oException->getMessage()]);
		}
	}

	/**
	 * PUT the event into the user's calendar using their own credentials.
	 */
	private function put(\RainLoop\Model\Account $oAccount, string $sUrl, string $sBody) : bool
	{
		$oHTTP = \SnappyMail\HTTP\Request::factory();
		$oHTTP->verify_peer = !!$this->Config()->Get('plugin', 'verify_peer', true);
		$oHTTP->timeout = 20;
		$oHTTP->setAuth(3, $oAccount->Email(), $oAccount->ImapPass());
		$oResponse = $oHTTP->doRequest('PUT', $sUrl, $sBody,
			array('Content-Type' => 'text/calendar; charset=utf-8'));
		if (!$oResponse || 300 <= $oResponse->status) {
			throw new \RuntimeException('CalDAV PUT failed with status '
				. ($oResponse ? $oResponse->status : 'none'));
		}
		\SnappyMail\Log::info('Invitations', "stored {$sUrl} ({$oResponse->status})");
		return true;
	}
}
