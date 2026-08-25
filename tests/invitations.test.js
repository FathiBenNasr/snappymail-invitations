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
		pluginRemoteRequest: (cb, action, params) => { sandbox.lastRequest = { action, params, cb }; },
	};

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
