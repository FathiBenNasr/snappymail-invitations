(rl => {

const
	templateId = 'MailMessageView',

	// Unfold RFC 5545 continuation lines before anything is read out of the ICS.
	unfold = text => text.replace(/\r\n/g, '\n').replace(/\n[ \t]/g, ''),

	// Deliberately minimal: the server re-parses the ICS with a real library
	// and is the authority. This only needs enough to decide whether to offer
	// the buttons, and what to show above them.
	field = (text, name) => {
		const m = new RegExp('^' + name + '(?:;[^:\\n]*)?:(.*)$', 'mi').exec(text);
		return m ? m[1].trim() : '';
	},

	icalDate = value => {
		const m = /(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})(Z)?)?/.exec(value || '');
		if (!m) return value || '';
		const d = m[7]
			? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0)))
			: new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0));
		return isNaN(d) ? value : d.toLocaleString([], m[4]
			? { dateStyle: 'long', timeStyle: 'short' }
			: { dateStyle: 'long' });
	};

addEventListener('rl-view-model.create', e => {
	if (templateId !== e.detail.viewModelTemplateID) return;
	// This plugin shares the message view with others. Anything thrown here
	// must not escape and take their initialisation down with it.
	try {
		build(e.detail);
	} catch (err) {
		console.error('[invitations] disabled after error:', err);
	}
});

const build = detail => {

	const
		template = document.getElementById(templateId),
		view = detail,
		attachmentsPlace = template.content.querySelector('.attachmentsPlace');

	if (!attachmentsPlace) return;

	view.meetingInvite = ko.observable(null);
	view.meetingBusy = ko.observable(false);
	view.meetingResult = ko.observable('');

	view.meetingRespond = partstat => {
		const invite = view.meetingInvite();
		if (!invite || view.meetingBusy()) return;
		view.meetingBusy(true);
		view.meetingResult('');
		rl.pluginRemoteRequest((iError, oData) => {
			view.meetingBusy(false);
			const r = oData && oData.Result;
			if (iError || !r || !r.success) {
				view.meetingResult('⚠ ' + ((r && r.error) || 'Could not save your answer'));
				return;
			}
			view.meetingResult({
				ACCEPTED:  '✔ Accepted — added to your calendar',
				TENTATIVE: '✔ Tentatively accepted — added to your calendar',
				DECLINED:  '✔ Declined',
				CANCELLED: '✔ Removed from your calendar'
			}[r.partstat] || '✔ Saved');
		}, 'InvitationRespond', {
			Ics: invite.ics,
			PartStat: partstat,
			Mode: invite.cancelled ? 'cancel' : 'respond'
		});
	};

	attachmentsPlace.after(Element.fromHTML(`
	<div class="meeting-invitation" data-bind="if: meetingInvite, visible: meetingInvite">
		<div class="meeting-invitation-head">
			<span data-icon="📅"></span>
			<span class="meeting-invitation-title" data-bind="text: meetingInvite().summary"></span>
		</div>
		<table class="meeting-invitation-detail"><tbody>
			<tr data-bind="visible: meetingInvite().organizer">
				<td>Organizer</td><td data-bind="text: meetingInvite().organizer"></td></tr>
			<tr><td>Start</td><td data-bind="text: meetingInvite().start"></td></tr>
			<tr data-bind="visible: meetingInvite().end">
				<td>End</td><td data-bind="text: meetingInvite().end"></td></tr>
			<tr data-bind="visible: meetingInvite().location">
				<td>Where</td><td data-bind="text: meetingInvite().location"></td></tr>
		</tbody></table>
		<div class="meeting-invitation-cancelled" data-bind="visible: meetingInvite().cancelled">
			This meeting was cancelled by the organizer.
		</div>
		<div class="meeting-invitation-actions" data-bind="visible: !meetingResult() &amp;&amp; !meetingInvite().cancelled">
			<button class="button-vertical" data-bind="click: () => meetingRespond('ACCEPTED'), disable: meetingBusy">Accept</button>
			<button class="button-vertical" data-bind="click: () => meetingRespond('TENTATIVE'), disable: meetingBusy">Tentative</button>
			<button class="button-vertical" data-bind="click: () => meetingRespond('DECLINED'), disable: meetingBusy">Decline</button>
		</div>
		<div class="meeting-invitation-actions" data-bind="visible: !meetingResult() &amp;&amp; meetingInvite().cancelled">
			<button class="button-vertical" data-bind="click: () => meetingRespond('CANCELLED'), disable: meetingBusy">Remove from my calendar</button>
		</div>
		<div class="meeting-invitation-result" data-bind="text: meetingResult, visible: meetingResult"></div>
	</div>`));

	// Re-evaluate whenever a different message is shown.
	view.message.subscribe(msg => {
		view.meetingInvite(null);
		view.meetingResult('');
		if (!msg) return;
		// attachments is a ko.observableArray, so it must be unwrapped before
		// array methods are used on it.
		const list = (typeof msg.attachments === 'function' ? msg.attachments() : msg.attachments) || [];

		// An invitation reaches us in more than one shape. Evolution and
		// Exchange send the scheduling part inline inside multipart/alternative
		// as text/calendar and repeat it as an application/ics attachment, so
		// match on type or on the file name and try each candidate in turn.
		const candidates = list.filter(a => a && a.download && (
			'text/calendar' === a.mimeType
			|| 'application/ics' === a.mimeType
			|| 'text/x-vcalendar' === a.mimeType
			|| /\.ics$/i.test(a.fileName || '')));

		if (!candidates.length) return;

		const tryNext = i => {
			if (i >= candidates.length) return;
			rl.fetch(candidates[i].linkDownload())
				.then(response => response.status < 400 ? response.text() : Promise.reject(response.status))
				.then(raw => {
					const text = unfold(raw);
					const method = (/^METHOD:([A-Z]+)\s*$/mi.exec(text) || [, ''])[1].toUpperCase();
					// REQUEST asks a question, CANCEL withdraws the meeting.
					// REPLY is another attendee answering and needs no action here.
					if ('REQUEST' !== method && 'CANCEL' !== method) {
						tryNext(i + 1);
						return;
					}
					// Read the event fields from the VEVENT only. VTIMEZONE
					// carries its own DTSTART for each transition rule - often
					// something like 19050701T000000 - and reading the first
					// DTSTART in the document shows that instead of the meeting.
					const vevent = (/BEGIN:VEVENT[\s\S]*?END:VEVENT/i.exec(text) || [''])[0];
					view.meetingInvite({
						ics:       raw,
						method:    method,
						cancelled: 'CANCEL' === method,
						sequence:  parseInt(field(vevent, 'SEQUENCE') || '0', 10),
						summary:   field(vevent, 'SUMMARY') || 'Meeting invitation',
						organizer: field(vevent, 'ORGANIZER').replace(/^mailto:/i, ''),
						location:  field(vevent, 'LOCATION'),
						start:     icalDate(field(vevent, 'DTSTART')),
						end:       icalDate(field(vevent, 'DTEND'))
					});
				})
				.catch(err => {
					console.error('[invitations]', err);
					tryNext(i + 1);
				});
		};
		tryNext(0);
	});
};

})(window.rl);
