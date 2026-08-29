// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (c) 2026 Convergent Cloud Computing

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

	// The strip never shows less than a working day. A single 10:00 meeting on
	// an otherwise empty page only reads as "nothing else today" if there is a
	// page around it; drawn tight to its own hour it reads as a full day.
	DAY_MIN_HOUR = 8,
	DAY_MAX_HOUR = 19,

	// The reader's zone, so the day is cut where they see midnight. The server
	// checks it against the zone database and falls back on its own; a browser
	// without Intl simply gets the organiser's zone.
	localZone = () => {
		try {
			return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		} catch (e) {
			return '';
		}
	},

	instant = value => {
		const d = new Date(value || '');
		return isNaN(d) ? null : d;
	},

	// Formatted in the zone the day was *cut* in, not in the browser's.
	//
	// The two are the same whenever the browser told the server its zone, which
	// is the ordinary case. They are not when it could not, and the server fell
	// back on the organiser's: the strip is then laid out from that zone's
	// midnight while the labels would read in another, and a 09:00 meeting is
	// drawn at nine and labelled eight. A zone the browser rejects falls back
	// to its own rather than throwing.
	inZone = (zone, options) => {
		try {
			return new Intl.DateTimeFormat([], Object.assign({ timeZone: zone }, options));
		} catch (e) {
			return new Intl.DateTimeFormat([], options);
		}
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

/**
 * Turn the server's answer into something a strip can be drawn from.
 *
 * Everything here is arithmetic on two instants and a list of blocks: the
 * server decides what is busy, this decides where it goes. Percentages rather
 * than pixels, so the column is whatever height the reading pane can spare.
 *
 * Returns null when the answer cannot be read at all — the caller then draws
 * nothing, which is the same as the plugin before this existed.
 */
const layoutDay = data => {

	const
		from = instant(data && data.from),
		to = instant(data && data.to);

	if (!from || !to || to <= from) return null;

	const
		heure = inZone(data.timezone, { hour: '2-digit', minute: '2-digit' }),
		jour = inZone(data.timezone, { weekday: 'long', day: 'numeric', month: 'long' }),
		hhmm = d => heure.format(d),
		minute = d => (d - from) / 60000,
		dayEnd = minute(to),
		clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v)),
		slotStart = instant(data.slot && data.slot.start),
		slotEnd = instant(data.slot && data.slot.end);

	const timed = [], allDay = [];
	(data.busy || []).forEach(b => {
		const s = instant(b.start), e = instant(b.end);
		if (!s || !e) return;
		(b.allDay ? allDay : timed).push({
			summary: b.summary || '(no title)',
			clash: !!b.clash,
			s: s,
			e: e
		});
	});

	// The window: the working day, widened by whatever falls outside it, and
	// snapped to whole hours so the labels line up with the ticks.
	let first = DAY_MIN_HOUR * 60, last = DAY_MAX_HOUR * 60;
	const stretch = (s, e) => {
		first = Math.min(first, minute(s));
		last = Math.max(last, minute(e));
	};
	timed.forEach(b => stretch(b.s, b.e));
	if (slotStart && slotEnd && !data.allDay) stretch(slotStart, slotEnd);

	first = clamp(Math.floor(first / 60) * 60, 0, dayEnd);
	last = clamp(Math.ceil(last / 60) * 60, first + 60, dayEnd);

	const span = last - first,
		top = m => clamp((m - first) / span * 100, 0, 100),
		pct = v => v.toFixed(3) + '%';

	// Lanes: two meetings at the same hour sit side by side rather than one
	// hiding the other. Greedy and stable — the first lane whose last block
	// has already finished.
	timed.sort((a, b) => a.s - b.s || a.e - b.e);
	const laneEnds = [];
	timed.forEach(b => {
		let lane = laneEnds.findIndex(end => end <= b.s);
		if (0 > lane) {
			lane = laneEnds.length;
		}
		laneEnds[lane] = b.e;
		b.lane = lane;
	});
	const lanes = Math.max(1, laneEnds.length);

	const hours = [];
	for (let h = first / 60; h <= last / 60; ++h) {
		hours.push({
			label: (10 > h ? '0' : '') + h + ':00',
			top: pct(top(h * 60))
		});
	}

	const clashes = timed.filter(b => b.clash).length;

	return {
		hours: hours,
		lanes: lanes,
		allDay: allDay.map(b => ({ summary: b.summary })),
		blocks: timed.map(b => {
			const a = top(minute(b.s)), z = top(minute(b.e));
			return {
				summary: b.summary,
				clash: b.clash,
				time: hhmm(b.s) + ' – ' + hhmm(b.e),
				title: hhmm(b.s) + ' – ' + hhmm(b.e) + ' · ' + b.summary,
				top: pct(a),
				// A zero-length event is still worth a line to look at.
				height: pct(Math.max(z - a, 1.2)),
				lead: pct(b.lane / lanes * 100),
				width: pct(100 / lanes)
			};
		}),
		slot: (slotStart && slotEnd && !data.allDay)
			? {
				top: pct(top(minute(slotStart))),
				height: pct(Math.max(top(minute(slotEnd)) - top(minute(slotStart)), 1.2))
			}
			: null,
		// The day *and* the slot on one line. In the band the slot's times
		// collide with whatever is booked over them — which is precisely the
		// case where they are worth reading.
		label: jour.format(from) + (slotStart && slotEnd && !data.allDay
			? ' · ' + hhmm(slotStart) + ' – ' + hhmm(slotEnd)
			: ''),
		clashes: clashes,
		// The three things worth saying, and never more than one of them.
		note: !data.read
			? 'Your calendar could not be read, so this day may be incomplete.'
			: clashes
				? clashes + (1 === clashes ? ' event overlaps this slot.' : ' events overlap this slot.')
				: 'Nothing else booked at that time.'
	};
};

const build = detail => {

	const
		template = document.getElementById(templateId),
		view = detail,
		attachmentsPlace = template.content.querySelector('.attachmentsPlace');

	if (!attachmentsPlace) return;

	view.meetingInvite = ko.observable(null);
	view.meetingBusy = ko.observable(false);
	view.meetingResult = ko.observable('');
	view.meetingDay = ko.observable(null);

	// The day the meeting falls on, read from the same calendar the answer
	// will be written to. Fired once per invitation shown, never on a message
	// that carries none, and never blocking: the buttons are usable while it
	// is in flight and stay usable if it never arrives.
	const loadDay = (raw, token) => {
		rl.pluginRemoteRequest((iError, oData) => {
			// A second message may have been opened in the meantime. Its own
			// request will answer; this one must not paint over it.
			if (token !== view.meetingToken) return;
			const r = oData && oData.Result;
			if (iError || !r || !r.success) {
				view.meetingDay(null);
				return;
			}
			try {
				view.meetingDay(layoutDay(r));
			} catch (err) {
				console.error('[invitations] day view:', err);
				view.meetingDay(null);
			}
		}, 'InvitationDay', { Ics: raw, Tz: localZone() });
	};

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
		<div class="meeting-invitation-body">
			<div class="meeting-invitation-main">
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
			</div>
			<div class="meeting-invitation-day" data-bind="if: meetingDay, visible: meetingDay">
				<div class="mid-label" data-bind="text: meetingDay().label"></div>
				<div class="mid-allday" data-bind="visible: meetingDay().allDay.length, foreach: meetingDay().allDay">
					<span class="mid-chip" data-bind="text: $data.summary"></span>
				</div>
				<div class="mid-grid">
					<div class="mid-axis" data-bind="foreach: meetingDay().hours">
						<span class="mid-tick" data-bind="text: $data.label, style: { insetBlockStart: $data.top }"></span>
					</div>
					<div class="mid-track">
						<div class="mid-slot" data-bind="visible: meetingDay().slot, style: { insetBlockStart: meetingDay().slot ? meetingDay().slot.top : '0%', blockSize: meetingDay().slot ? meetingDay().slot.height : '0%' }"></div>
						<!-- ko foreach: meetingDay().blocks -->
						<div class="mid-block" data-bind="css: { clash: $data.clash }, attr: { title: $data.title }, style: { insetBlockStart: $data.top, blockSize: $data.height, insetInlineStart: $data.lead, inlineSize: $data.width }">
							<span class="mid-block-name" data-bind="text: $data.summary"></span><span class="mid-block-time" data-bind="text: $data.time"></span>
						</div>
						<!-- /ko -->
					</div>
				</div>
				<div class="mid-note" data-bind="text: meetingDay().note, css: { clash: meetingDay().clashes }"></div>
			</div>
		</div>
	</div>`));

	// Re-evaluate whenever a different message is shown.
	// Which message the pending day request belongs to. A plain counter: the
	// answers come back in whatever order the network gives them, and the one
	// that belongs to the message on screen is the only one worth painting.
	view.meetingToken = 0;

	view.message.subscribe(msg => {
		view.meetingInvite(null);
		view.meetingResult('');
		view.meetingDay(null);
		const token = ++view.meetingToken;
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
					// A cancellation has no slot to defend: the meeting is
					// already gone, and drawing the day around it says nothing.
					if ('CANCEL' !== method) {
						loadDay(raw, token);
					}
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
