/**
 * Invitations — the front-end that reads the .ics attachment.
 *
 * The real plugin file is loaded into a sandbox and driven through its own
 * entry point: the `rl-view-model.create` event, then a message carrying
 * attachments. Nothing here re-implements the parsing, so the tests keep
 * telling the truth when the file changes.
 *
 * Run with:  node --test tests/*.test.js
 *
 * @license AGPL-3.0-or-later
 */

'use strict';

// The strip's labels are formatted in the runner's zone, so it is pinned:
// otherwise "09:00 – 10:30" reads differently on a machine in Auckland and the
// tests would assert the box rather than the plugin. The geometry below does
// not depend on it — percentages are computed from absolute instants.
process.env.TZ = 'Africa/Tunis';

const test = require('node:test');
const assert = require('node:assert');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

const SOURCE = fs.readFileSync(path.join(__dirname, '..', 'plugin', 'invitations.js'), 'utf8');

/** Knockout's observable, reduced to what the plugin uses. */
function observable(initial) {
	let value = initial;
	const subscribers = [];
	const fn = (...args) => {
		if (args.length) {
			value = args[0];
			subscribers.forEach((s) => s(value));
			return undefined;
		}
		return value;
	};
	fn.subscribe = (s) => subscribers.push(s);
	return fn;
}

/**
 * Loads the plugin and returns handles on the view model it builds, plus the
 * fetch stub the test drives.
 */
function load(icsByUrl) {
	const listeners = {};
	const attachmentsPlace = { after: () => {} };
	const inserted = [];

	const sandbox = {
		console: { error: () => {}, log: () => {} },
		ko: { observable, observableArray: observable },
		Element: { fromHTML: (html) => { inserted.push(html); return {}; } },
		document: {
			getElementById: (id) => (id === 'MailMessageView'
				? { content: { querySelector: (sel) => (sel === '.attachmentsPlace' ? attachmentsPlace : null) } }
				: null),
		},
		addEventListener: (name, fn) => { (listeners[name] ||= []).push(fn); },
		Promise,
		RegExp,
		Date,
		parseInt,
		isNaN,
	};
	sandbox.window = sandbox;
	sandbox.rl = {
		fetch: (url) => {
			const body = icsByUrl[url];
			return body === undefined
				? Promise.resolve({ status: 404, text: () => Promise.resolve('') })
				: Promise.resolve({ status: 200, text: () => Promise.resolve(body) });
		},
		pluginRemoteRequest: (cb, action, params) => {
			const request = { action, params, cb };
			sandbox.lastRequest = request;
			sandbox.requests.push(request);
		},
	};

	sandbox.requests = [];

	vm.runInContext(SOURCE, vm.createContext(sandbox));

	const view = { message: observable(null) };
	listeners['rl-view-model.create'].forEach((fn) =>
		fn({ detail: Object.assign(view, { viewModelTemplateID: 'MailMessageView' }) }));

	return { view, sandbox, inserted };
}

/** An attachment as the message view exposes it. */
const attachment = (url, mimeType, fileName) => ({
	download: true,
	mimeType,
	fileName,
	linkDownload: () => url,
});

const REQUEST_ICS = [
	'BEGIN:VCALENDAR',
	'METHOD:REQUEST',
	'BEGIN:VTIMEZONE',
	'TZID:Africa/Tunis',
	'BEGIN:STANDARD',
	'DTSTART:19050701T000000',
	'END:STANDARD',
	'END:VTIMEZONE',
	'BEGIN:VEVENT',
	'UID:abc-123',
	'SEQUENCE:2',
	'SUMMARY:Comité de pilotage',
	'ORGANIZER;CN=Anne:mailto:anne@convergent.tn',
	'LOCATION:Salle Carthage',
	'DTSTART;TZID=Africa/Tunis:20260901T090000',
	'DTEND;TZID=Africa/Tunis:20260901T103000',
	'END:VEVENT',
	'END:VCALENDAR',
].join('\r\n');

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('a REQUEST invitation is read out of the VEVENT', async () => {
	const { view } = load({ 'ics://1': REQUEST_ICS });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'invite.ics')] });
	await settle();

	const invite = view.meetingInvite();
	assert.ok(invite, 'the invitation should have been picked up');
	assert.strictEqual(invite.summary, 'Comité de pilotage');
	assert.strictEqual(invite.organizer, 'anne@convergent.tn', 'the mailto: prefix is stripped');
	assert.strictEqual(invite.location, 'Salle Carthage');
	assert.strictEqual(invite.sequence, 2);
	assert.strictEqual(invite.cancelled, false);
});

test('the VTIMEZONE start is not mistaken for the meeting start', async () => {
	// A transition rule's DTSTART is often 19050701T000000. Reading the first
	// DTSTART in the document would show 1905 instead of the meeting.
	const { view } = load({ 'ics://1': REQUEST_ICS });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'invite.ics')] });
	await settle();

	assert.ok(!/1905/.test(view.meetingInvite().start), 'must not show the timezone rule date');
	assert.ok(/2026/.test(view.meetingInvite().start), 'must show the meeting date');
});

test('folded continuation lines are joined before anything is read', async () => {
	const folded = REQUEST_ICS.replace('SUMMARY:Comité de pilotage',
		'SUMMARY:Comité de\r\n  pilotage annuel');
	const { view } = load({ 'ics://1': folded });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'invite.ics')] });
	await settle();

	assert.strictEqual(view.meetingInvite().summary, 'Comité de pilotage annuel');
});

test('a CANCEL is shown as a cancellation, not as an invitation', async () => {
	const { view } = load({ 'ics://1': REQUEST_ICS.replace('METHOD:REQUEST', 'METHOD:CANCEL') });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'invite.ics')] });
	await settle();

	assert.strictEqual(view.meetingInvite().cancelled, true);
	assert.strictEqual(view.meetingInvite().method, 'CANCEL');
});

test('a REPLY from another attendee is ignored', async () => {
	const { view } = load({ 'ics://1': REQUEST_ICS.replace('METHOD:REQUEST', 'METHOD:REPLY') });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'invite.ics')] });
	await settle();

	assert.strictEqual(view.meetingInvite(), null, 'no buttons for somebody else’s answer');
});

test('an attachment is recognised by name when the type is generic', async () => {
	const { view } = load({ 'ics://1': REQUEST_ICS });
	view.message({ attachments: [attachment('ics://1', 'application/octet-stream', 'meeting.ICS')] });
	await settle();

	assert.ok(view.meetingInvite(), 'a .ics file name is enough');
});

test('a message with no calendar part shows nothing', async () => {
	const { view } = load({});
	view.message({ attachments: [attachment('x://1', 'application/pdf', 'contrat.pdf')] });
	await settle();

	assert.strictEqual(view.meetingInvite(), null);
});

test('a failed download falls through to the next candidate', async () => {
	const { view } = load({ 'ics://2': REQUEST_ICS });
	view.message({ attachments: [
		attachment('ics://missing', 'text/calendar', 'a.ics'),
		attachment('ics://2', 'text/calendar', 'b.ics'),
	] });
	await settle();
	await settle();

	assert.ok(view.meetingInvite(), 'the second attachment should have been tried');
});

test('attachments held in an observable array are unwrapped', async () => {
	const { view } = load({ 'ics://1': REQUEST_ICS });
	view.message({ attachments: observable([attachment('ics://1', 'text/calendar', 'a.ics')]) });
	await settle();

	assert.ok(view.meetingInvite());
});

test('switching message clears the previous invitation', async () => {
	const { view } = load({ 'ics://1': REQUEST_ICS });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'a.ics')] });
	await settle();
	assert.ok(view.meetingInvite());

	view.message({ attachments: [] });
	assert.strictEqual(view.meetingInvite(), null, 'a new message must not keep the old buttons');
});

test('answering sends the answer and the untouched ICS', async () => {
	const { view, sandbox } = load({ 'ics://1': REQUEST_ICS });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'a.ics')] });
	await settle();

	view.meetingRespond('DECLINED');

	assert.strictEqual(sandbox.lastRequest.action, 'InvitationRespond');
	assert.strictEqual(sandbox.lastRequest.params.PartStat, 'DECLINED');
	assert.strictEqual(sandbox.lastRequest.params.Mode, 'respond');
	assert.strictEqual(sandbox.lastRequest.params.Ics, REQUEST_ICS,
		'the server re-parses the original bytes, so they must go back unchanged');
});

test('a second click is ignored while the first is still in flight', async () => {
	const { view, sandbox } = load({ 'ics://1': REQUEST_ICS });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'a.ics')] });
	await settle();

	view.meetingRespond('ACCEPTED');
	sandbox.lastRequest = null;
	view.meetingRespond('DECLINED');

	assert.strictEqual(sandbox.lastRequest, null, 'no double answer');
});

test('the server’s refusal is shown to the user', async () => {
	const { view, sandbox } = load({ 'ics://1': REQUEST_ICS });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'a.ics')] });
	await settle();

	view.meetingRespond('ACCEPTED');
	sandbox.lastRequest.cb(0, { Result: { success: false, error: 'Calendar is read-only' } });

	assert.match(view.meetingResult(), /read-only/);
});

test('a view other than the message view is left alone', () => {
	// The plugin shares the page with others; it must not build itself twice
	// or attach to a view it does not know.
	const { sandbox } = load({});
	assert.doesNotThrow(() => sandbox.rl.fetch('nothing'));
});

// ─── The day drawn beside the invitation ────────────────────────────────────
//
// The column exists so a clash is seen before the answer is given. Everything
// below is geometry on two instants and a list of blocks: the server decides
// what is busy, this decides where it goes on the strip. Drawn wrong, it is
// wrong *quietly* — a block in the wrong place still looks like a calendar.

/** The day request the plugin fired, if it fired one. */
const dayRequest = (sandbox) => sandbox.requests.find((r) => 'InvitationDay' === r.action);

/** A day as the server hands it over. */
const dayAnswer = (over) => Object.assign({
	success: true,
	timezone: 'Africa/Tunis',
	from: '2026-09-01T00:00:00+01:00',
	to: '2026-09-02T00:00:00+01:00',
	allDay: false,
	slot: { start: '2026-09-01T09:00:00+01:00', end: '2026-09-01T10:30:00+01:00' },
	busy: [],
	read: true,
}, over);

const busy = (start, end, over) => Object.assign({
	uid: 'x',
	summary: 'Revue de sprint',
	start: '2026-09-01T' + start + ':00+01:00',
	end: '2026-09-01T' + end + ':00+01:00',
	allDay: false,
	clash: false,
}, over);

/** Open an invitation and hand the plugin a day for it. */
const withDay = async (over) => {
	const { view, sandbox } = load({ 'ics://1': REQUEST_ICS });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'invite.ics')] });
	await settle();
	const request = dayRequest(sandbox);
	assert.ok(request, 'the plugin should have asked for the day');
	request.cb(0, { Result: dayAnswer(over) });
	return { view, sandbox, day: view.meetingDay() };
};

const percent = (s) => parseFloat(String(s));

test('opening an invitation asks for the day it falls on', async () => {
	const { view, sandbox } = load({ 'ics://1': REQUEST_ICS });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'invite.ics')] });
	await settle();

	const request = dayRequest(sandbox);
	assert.ok(request, 'a day request should have been made');
	assert.strictEqual(request.params.Ics, REQUEST_ICS,
		'the server re-parses the same bytes with a real library');
	assert.ok('Tz' in request.params, 'the reader’s zone decides where the day is cut');
});

test('a cancellation asks for no day', async () => {
	// The meeting is already gone; there is no slot left to defend, and a strip
	// drawn around it would say nothing.
	const { sandbox, view } = load({ 'ics://1': REQUEST_ICS.replace('METHOD:REQUEST', 'METHOD:CANCEL') });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'invite.ics')] });
	await settle();

	assert.strictEqual(dayRequest(sandbox), undefined);
});

test('a message with no invitation asks for nothing', async () => {
	const { sandbox, view } = load({});
	view.message({ attachments: [] });
	await settle();

	assert.strictEqual(sandbox.requests.length, 0);
});

test('the day becomes an hour axis, a slot and its blocks', async () => {
	const { day } = await withDay({ busy: [busy('14:00', '15:00')] });

	assert.ok(day, 'a day should have been laid out');
	assert.strictEqual(day.hours[0].label, '08:00');
	assert.strictEqual(day.hours[day.hours.length - 1].label, '19:00');
	assert.strictEqual(day.blocks.length, 1);
	assert.strictEqual(day.blocks[0].summary, 'Revue de sprint');
	assert.ok(day.slot, 'the proposed slot is drawn');
});

test('the strip never shows less than the working day', async () => {
	// One 10:00 meeting on an empty page only reads as "nothing else today" if
	// there is a page around it.
	const { day } = await withDay({ busy: [busy('10:00', '10:30')] });

	assert.strictEqual(day.hours[0].label, '08:00');
	assert.strictEqual(day.hours[day.hours.length - 1].label, '19:00');
});

test('the strip stretches to whatever falls outside the working day', async () => {
	const { day } = await withDay({ busy: [busy('06:30', '07:00'), busy('21:00', '22:15')] });

	assert.strictEqual(day.hours[0].label, '06:00', 'snapped down to the whole hour');
	assert.strictEqual(day.hours[day.hours.length - 1].label, '23:00', 'and up');
});

test('every block sits inside the strip', async () => {
	const { day } = await withDay({ busy: [busy('06:30', '07:00'), busy('12:00', '13:00')] });

	day.blocks.forEach((b) => {
		const top = percent(b.top), height = percent(b.height);
		assert.ok(0 <= top && 100 >= top, `top out of the strip: ${b.top}`);
		assert.ok(0 < height && 100 >= top + height + 0.001, `height out of the strip: ${b.height}`);
	});
});

test('two meetings at the same hour sit side by side, not one on the other', async () => {
	const { day } = await withDay({ busy: [
		busy('14:00', '15:00', { summary: 'A' }),
		busy('14:30', '15:30', { summary: 'B' }),
	] });

	assert.strictEqual(day.lanes, 2);
	const [a, b] = day.blocks;
	assert.notStrictEqual(a.lead, b.lead, 'they must not start at the same offset');
	assert.strictEqual(percent(a.width), 50);
});

test('meetings that do not overlap share the one lane', async () => {
	const { day } = await withDay({ busy: [busy('09:00', '10:00'), busy('11:00', '12:00')] });

	assert.strictEqual(day.lanes, 1);
	assert.strictEqual(percent(day.blocks[0].width), 100);
});

test('a meeting ending exactly when the next begins does not open a second lane', async () => {
	const { day } = await withDay({ busy: [busy('09:00', '10:00'), busy('10:00', '11:00')] });

	assert.strictEqual(day.lanes, 1);
});

test('the clashes the server found are counted in the note', async () => {
	const { day } = await withDay({ busy: [
		busy('09:30', '10:00', { clash: true }),
		busy('10:00', '10:15', { clash: true }),
		busy('16:00', '17:00'),
	] });

	assert.strictEqual(day.clashes, 2);
	assert.match(day.note, /2 events overlap/);
	assert.strictEqual(day.blocks.filter((b) => b.clash).length, 2);
});

test('one clash is counted in the singular', async () => {
	const { day } = await withDay({ busy: [busy('09:30', '10:00', { clash: true })] });

	assert.match(day.note, /^1 event overlaps/);
});

test('an empty slot says so rather than saying nothing', async () => {
	const { day } = await withDay({ busy: [busy('16:00', '17:00')] });

	assert.strictEqual(day.clashes, 0);
	assert.match(day.note, /Nothing else booked/);
});

test('a day that could not be read says so instead of looking empty', async () => {
	// An unreadable calendar drawn as a blank strip is the worst of the three
	// outcomes: it looks exactly like a free afternoon.
	const { day } = await withDay({ read: false, busy: [] });

	assert.match(day.note, /could not be read/);
});

test('whole-day entries go above the strip, never on it', async () => {
	const { day } = await withDay({ busy: [
		busy('00:00', '00:00', { allDay: true, summary: 'Congé' }),
		busy('14:00', '15:00'),
	] });

	// deepStrictEqual would compare prototypes, and this array was made in the
	// sandbox's realm, not this one.
	assert.strictEqual(day.allDay.map((b) => b.summary).join('|'), 'Congé');
	assert.strictEqual(day.blocks.length, 1, 'a whole day would swamp the strip');
});

test('a zero-length event still gets a line to look at', async () => {
	const { day } = await withDay({ busy: [busy('14:00', '14:00')] });

	assert.ok(0 < percent(day.blocks[0].height));
});

test('a refused or failed day leaves the buttons alone', async () => {
	const { view, sandbox } = load({ 'ics://1': REQUEST_ICS });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'invite.ics')] });
	await settle();

	dayRequest(sandbox).cb(0, { Result: { success: false, error: 'Disabled' } });

	assert.strictEqual(view.meetingDay(), null, 'no strip');
	assert.ok(view.meetingInvite(), 'and the invitation is still answerable');
});

test('a malformed day is dropped rather than thrown', async () => {
	const { view, sandbox } = load({ 'ics://1': REQUEST_ICS });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'invite.ics')] });
	await settle();

	assert.doesNotThrow(() =>
		dayRequest(sandbox).cb(0, { Result: { success: true, from: 'not a date', to: 'nor this' } }));
	assert.strictEqual(view.meetingDay(), null);
});

test('a day arriving after the reader has moved on is not painted', async () => {
	// The answers come back in whatever order the network gives them. Without
	// the token, yesterday's day is drawn beside today's invitation.
	const { view, sandbox } = load({ 'ics://1': REQUEST_ICS });
	view.message({ attachments: [attachment('ics://1', 'text/calendar', 'invite.ics')] });
	await settle();
	const stale = dayRequest(sandbox);

	view.message({ attachments: [] });
	await settle();

	stale.cb(0, { Result: dayAnswer({ busy: [busy('14:00', '15:00')] }) });

	assert.strictEqual(view.meetingDay(), null);
});

test('opening another message clears the previous day', async () => {
	const { view, sandbox } = await withDay({ busy: [busy('14:00', '15:00')] })
		.then((r) => r);
	assert.ok(view.meetingDay());

	view.message({ attachments: [] });
	await settle();

	assert.strictEqual(view.meetingDay(), null);
	assert.ok(sandbox);
});

test('a block carries a readable time and a title for the hover', async () => {
	const { day } = await withDay({ busy: [busy('14:00', '15:00', { summary: 'Revue' })] });

	// Twelve- or twenty-four-hour is the reader's locale to decide, so the
	// assertion is on both ends of the range being there, not on their shape.
	assert.match(day.blocks[0].time, /2:00.+3:00/);
	assert.match(day.blocks[0].title, /Revue/);
});

test('an untitled event is not drawn as a blank box', async () => {
	const { day } = await withDay({ busy: [busy('14:00', '15:00', { summary: '' })] });

	assert.strictEqual(day.blocks[0].summary, '(no title)');
});

test('the day is labelled with its own date and the proposed slot', async () => {
	const { day } = await withDay({ busy: [] });

	assert.match(day.label, /September/i);
	assert.match(day.label, /9:00.+10:30/, 'the slot belongs on the label, not in the band');
});

test('times are read in the zone the day was cut in, not the reader’s', async () => {
	// The two agree whenever the browser told the server its zone — the
	// ordinary case. They do not when it could not and the server fell back on
	// the organiser's, and the strip is then laid out from one midnight while
	// the labels read from another: a 09:00 meeting drawn at nine, labelled
	// eight, on yesterday's date. The runner sits in Africa/Tunis (see the top
	// of this file); the day below is cut in Auckland, eleven hours away.
	const { day } = await withDay({
		timezone: 'Pacific/Auckland',
		from: '2026-09-01T00:00:00+12:00',
		to: '2026-09-02T00:00:00+12:00',
		slot: { start: '2026-09-01T09:00:00+12:00', end: '2026-09-01T10:30:00+12:00' },
		busy: [Object.assign(busy('00:00', '00:00'), {
			start: '2026-09-01T14:00:00+12:00',
			end: '2026-09-01T15:00:00+12:00',
		})],
	});

	assert.match(day.label, /September/i, 'still 1 September, not 31 August');
	assert.match(day.label, /9:00/, 'the slot starts at nine in Auckland');
	assert.match(day.blocks[0].time, /2:00.+3:00/, 'and the block at two');
});

test('a timezone the browser will not accept does not lose the day', async () => {
	const { day } = await withDay({ timezone: 'Mars/Olympus', busy: [busy('14:00', '15:00')] });

	assert.ok(day, 'the day is still laid out');
	assert.ok(day.blocks[0].time.length, 'and the block still has a time');
});
