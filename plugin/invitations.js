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

	const
		template = document.getElementById(templateId),
		view = e.detail,
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
				DECLINED:  '✔ Declined'
			}[r.partstat] || '✔ Saved');
		}, 'InvitationRespond', { Ics: invite.ics, PartStat: partstat });
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
		<div class="meeting-invitation-actions" data-bind="visible: !meetingResult()">
			<button class="button-vertical" data-bind="click: () => meetingRespond('ACCEPTED'), disable: meetingBusy">Accept</button>
			<button class="button-vertical" data-bind="click: () => meetingRespond('TENTATIVE'), disable: meetingBusy">Tentative</button>
			<button class="button-vertical" data-bind="click: () => meetingRespond('DECLINED'), disable: meetingBusy">Decline</button>
		</div>
		<div class="meeting-invitation-result" data-bind="text: meetingResult, visible: meetingResult"></div>
	</div>`));

	// Re-evaluate whenever a different message is shown.
	view.message.subscribe(msg => {
		view.meetingInvite(null);
		view.meetingResult('');
		if (!msg) return;
		const part = msg.attachments && msg.attachments.find(a => 'text/calendar' == a.mimeType);
		if (!part || !part.download) return;

		rl.fetch(part.linkDownload())
			.then(response => response.status < 400 ? response.text() : Promise.reject(response.status))
			.then(raw => {
				const text = unfold(raw);
				// Only METHOD:REQUEST asks the recipient a question. REPLY and
				// CANCEL are informational here and get no buttons.
				if (!/^METHOD:REQUEST\s*$/mi.test(text)) return;
				view.meetingInvite({
					ics:       raw,
					summary:   field(text, 'SUMMARY') || 'Meeting invitation',
					organizer: field(text, 'ORGANIZER').replace(/^mailto:/i, ''),
					location:  field(text, 'LOCATION'),
					start:     icalDate(field(text, 'DTSTART')),
					end:       icalDate(field(text, 'DTEND'))
				});
			})
			.catch(err => console.error('[invitations]', err));
	});
});

})(window.rl);
