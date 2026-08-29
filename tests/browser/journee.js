// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (c) 2026 Convergent Cloud Computing
//
// Le banc de la journée dessinée à côté de l'invitation.
//
// `node --test` prouve l'arithmétique : quel pourcentage pour quel bloc. Il ne
// prouve rien de ce qui décide si la colonne est lisible, parce que rien de
// tout cela ne lève d'erreur :
//
//   1. une liaison `data-bind` qui échoue **emporte tout le sous-arbre** — et
//      Knockout ne dit rien : la carte disparaît entière, boutons compris ;
//   2. une propriété CSS inconnue est ignorée en silence : `insetBlockStart`
//      mal écrit empile tous les blocs sur la première heure ;
//   3. deux réunions à la même heure peuvent se recouvrir exactement, et la
//      seconde n'existe alors que dans le DOM ;
//   4. en écriture droite-à-gauche, une propriété physique oubliée envoie l'axe
//      des heures d'un côté et les blocs de l'autre ;
//   5. une couleur codée en dur ne bouge pas quand le thème bascule, et c'est
//      précisément ce que le lot 2 est censé avoir supprimé.
//
// Le greffon est chargé **tel qu'il sera déployé** : `plugin/invitations.js` et
// `plugin/invitations.css`, avec le Knockout de SnappyMail. Rien n'est recopié.
//
// Lancement — voir README.md.

const puppeteer = require('puppeteer');
const path = require('node:path');

const PAGE = 'file://' + path.join(__dirname, 'apercu.html');

// Les captures ne sont pas prises par défaut : elles ne prouvent rien toutes
// seules, et c'est un œil qui les lit. `CAPTURES=/chemin` les garde.
const CAPTURES = process.env.CAPTURES || '';

const ICS = [
	'BEGIN:VCALENDAR',
	'METHOD:REQUEST',
	'BEGIN:VEVENT',
	'UID:the-meeting',
	'SUMMARY:Comité de pilotage',
	'ORGANIZER;CN=Anne:mailto:anne@convergent.tn',
	'DTSTART;TZID=Africa/Tunis:20260901T090000',
	'DTEND;TZID=Africa/Tunis:20260901T103000',
	'END:VEVENT',
	'END:VCALENDAR',
].join('\r\n');

const bloc = (debut, fin, sur) => Object.assign({
	uid: 'b-' + debut,
	summary: 'Revue de sprint',
	start: '2026-09-01T' + debut + ':00+01:00',
	end: '2026-09-01T' + fin + ':00+01:00',
	allDay: false,
	clash: false,
}, sur);

const journee = (sur) => Object.assign({
	success: true,
	timezone: 'Africa/Tunis',
	from: '2026-09-01T00:00:00+01:00',
	to: '2026-09-02T00:00:00+01:00',
	allDay: false,
	slot: { start: '2026-09-01T09:00:00+01:00', end: '2026-09-01T10:30:00+01:00' },
	read: true,
	busy: [],
}, sur);

/** Ce qui est mesuré dans la page, une fois la journée dessinée. */
function mesurer() {
	const q = (s) => document.querySelector(s);
	const qa = (s) => Array.from(document.querySelectorAll(s));
	const R = (e) => {
		const r = e.getBoundingClientRect();
		return { x: r.x, y: r.y, w: r.width, h: r.height, right: r.right, bottom: r.bottom };
	};
	const couleur = (e, p) => getComputedStyle(e)[p];

	const carte = q('.meeting-invitation');
	const piste = q('.mid-track');

	return {
		// 1. La carte a survécu à ses liaisons.
		carte: !!carte,
		// Les boutons **visibles**. La rangée « Retirer de mon agenda » vit dans
		// le même arbre, cachée par `visible:` — la compter donnerait quatre
		// boutons de réponse là où l'écran en montre trois, et le banc
		// mesurerait le DOM au lieu de l'écran.
		boutons: qa('.meeting-invitation-actions button').filter((e) => null !== e.offsetParent).length,
		titre: (q('.meeting-invitation-title') || {}).textContent || '',

		colonne: !!q('.meeting-invitation-day'),
		etiquette: (q('.mid-label') || {}).textContent || '',
		note: (q('.mid-note') || {}).textContent || '',
		heures: qa('.mid-tick').map((e) => e.textContent),
		pastilles: qa('.mid-chip').map((e) => e.textContent),

		rMain: q('.meeting-invitation-main') ? R(q('.meeting-invitation-main')) : null,
		rJour: q('.meeting-invitation-day') ? R(q('.meeting-invitation-day')) : null,
		rAxe: q('.mid-axis') ? R(q('.mid-axis')) : null,
		rPiste: piste ? R(piste) : null,
		rCreneau: q('.mid-slot') ? R(q('.mid-slot')) : null,

		blocs: qa('.mid-block').map((e) => ({
			r: R(e),
			clash: e.classList.contains('clash'),
			fond: couleur(e, 'backgroundColor'),
			texte: e.textContent,
		})),

		// 5. Ce que le thème doit pouvoir déplacer.
		fondPiste: piste ? couleur(piste, 'backgroundColor') : '',
		texteCarte: carte ? couleur(carte, 'color') : '',

		// Le sens d'écriture des textes de contenu. Une valeur autre que
		// `plaintext` ne lève rien : elle tronque un nom latin par la tête dans
		// une page en arabe, et réordonne « 1 event… » en « …event 1 ».
		bidi: qa('.mid-block, .mid-note, .mid-label').map((e) => couleur(e, 'unicodeBidi')),

		debordement: document.documentElement.scrollWidth > document.documentElement.clientWidth,
		erreurs: window.__erreurs || [],
		demandes: (window.__demandes || []).map((d) => d.action),
	};
}

/** Ouvre la page avec un scénario, et rend la mesure. */
async function ouvrir(navigateur, { largeur = 900, hauteur = 900, jour, rtl = false, theme = 'clair' }) {
	const page = await navigateur.newPage();
	await page.setViewport({ width: largeur, height: hauteur });
	await page.evaluateOnNewDocument((s, sensRtl, th) => {
		window.__scenario = s;
		addEventListener('DOMContentLoaded', () => {
			document.documentElement.dir = sensRtl ? 'rtl' : 'ltr';
			document.documentElement.dataset.theme = th;
		});
	}, { ics: ICS, jour: jour || journee() }, rtl, theme);

	await page.goto(PAGE, { waitUntil: 'load' });
	await page.waitForFunction('window.__pret && window.__pret()', { timeout: 5000 })
		.catch(() => {});
	const mesure = await page.evaluate(mesurer);
	return { page, mesure };
}

let capture = 0;
async function garder(page, nom) {
	if (!CAPTURES) return;
	await page.screenshot({
		path: path.join(CAPTURES, `journee-${String(++capture).padStart(2, '0')}-${nom}.png`),
	});
}

const controles = [];
const verifier = (nom, condition, detail) => {
	controles.push({ nom, ok: !!condition, detail: condition ? '' : (detail || '') });
};

(async () => {
	const navigateur = await puppeteer.launch({
		// Le Chrome du conteneur, pas celui que puppeteer téléchargerait : la
		// version livrée avec la bibliothèque n'est pas installée ici.
		executablePath: process.env.CHROME || '/usr/bin/google-chrome',
		args: ['--no-sandbox', '--disable-dev-shm-usage', '--allow-file-access-from-files'],
	});

	try {
		// ── 1. La carte tient debout, et la journée est dessinée ─────────────
		{
			const { page, mesure: m } = await ouvrir(navigateur, {
				jour: journee({ busy: [bloc('14:00', '15:00'), bloc('16:30', '17:00', { summary: 'Point client' })] }),
			});

			verifier('la carte de l’invitation est rendue', m.carte);
			verifier('les trois boutons de réponse sont là', 3 === m.boutons, `${m.boutons} boutons`);
			verifier('aucune erreur pendant les liaisons', 0 === m.erreurs.length, m.erreurs.join(' · '));
			verifier('la journée a été demandée au serveur', m.demandes.includes('InvitationDay'));
			verifier('la colonne du jour est rendue', m.colonne);
			verifier('l’axe porte les heures de la journée ouvrée',
				m.heures.includes('08:00') && m.heures.includes('19:00'), m.heures.join(','));
			verifier('les deux réunions sont dessinées', 2 === m.blocs.length, `${m.blocs.length} blocs`);
			verifier('le créneau proposé est dessiné', !!m.rCreneau);

			// La géométrie : chaque bloc dans la piste, et dans l'ordre des heures.
			const dedans = m.blocs.every((b) =>
				b.r.y >= m.rPiste.y - 1 && b.r.bottom <= m.rPiste.bottom + 1);
			verifier('chaque bloc tient dans la piste', dedans);
			verifier('14 h est dessiné au-dessus de 16 h 30',
				m.blocs[0].r.y < m.blocs[1].r.y,
				`${m.blocs[0].r.y} vs ${m.blocs[1].r.y}`);
			verifier('un bloc a une hauteur non nulle', m.blocs.every((b) => 2 < b.r.h));
			verifier('le créneau est à la hauteur de 9 h, au-dessus de 14 h',
				m.rCreneau.y < m.blocs[0].r.y);
			verifier('rien ne déborde horizontalement', !m.debordement);
			await garder(page, 'journee-complete');
			await page.close();
		}

		// ── 2. Deux réunions à la même heure ────────────────────────────────
		{
			const { page, mesure: m } = await ouvrir(navigateur, {
				jour: journee({ busy: [
					bloc('14:00', '15:00', { summary: 'A' }),
					bloc('14:30', '15:30', { summary: 'B' }),
				] }),
			});

			verifier('les deux réunions simultanées sont dessinées', 2 === m.blocs.length);
			const [a, b] = m.blocs;
			const recouvre = a.r.x < b.r.right - 1 && b.r.x < a.r.right - 1;
			verifier('elles ne se recouvrent pas', !recouvre,
				`A ${a.r.x}–${a.r.right}, B ${b.r.x}–${b.r.right}`);
			verifier('chacune garde une largeur utile', a.r.w > 20 && b.r.w > 20,
				`${a.r.w} et ${b.r.w}`);
			await page.close();
		}

		// ── 3. Le chevauchement se voit ─────────────────────────────────────
		{
			const { page, mesure: m } = await ouvrir(navigateur, {
				jour: journee({ busy: [
					bloc('09:30', '10:00', { clash: true, summary: 'Entretien' }),
					bloc('16:00', '17:00', { summary: 'Revue' }),
				] }),
			});

			const enConflit = m.blocs.filter((x) => x.clash);
			verifier('le bloc en conflit porte sa classe', 1 === enConflit.length);
			verifier('il ne se peint pas comme les autres',
				enConflit.length && enConflit[0].fond !== m.blocs.find((x) => !x.clash).fond,
				enConflit.length ? enConflit[0].fond : '');
			verifier('la note compte le conflit', /1 event overlaps/.test(m.note), m.note);
			await garder(page, 'conflit');
			await page.close();
		}

		// ── 4. La journée entière, et la journée illisible ──────────────────
		{
			const { page, mesure: m } = await ouvrir(navigateur, {
				jour: journee({ busy: [
					bloc('00:00', '00:00', { allDay: true, summary: 'Congé' }),
					bloc('14:00', '15:00'),
				] }),
			});

			verifier('l’entrée « toute la journée » est une pastille',
				1 === m.pastilles.length && /Congé/.test(m.pastilles[0]), m.pastilles.join(','));
			verifier('elle ne prend pas la piste', 1 === m.blocs.length);
			await page.close();
		}
		{
			const { page, mesure: m } = await ouvrir(navigateur, {
				jour: journee({ read: false }),
			});

			verifier('un agenda illisible le dit au lieu de paraître vide',
				/could not be read/.test(m.note), m.note);
			verifier('et les boutons restent utilisables', 3 === m.boutons);
			await page.close();
		}

		// ── 5. Le volet étroit ──────────────────────────────────────────────
		{
			const { page, mesure: large } = await ouvrir(navigateur, {
				largeur: 1100, jour: journee({ busy: [bloc('14:00', '15:00')] }),
			});
			verifier('au large, la journée est à côté de l’invitation',
				large.rJour.y < large.rMain.bottom - 5,
				`main ${large.rMain.y}–${large.rMain.bottom}, jour ${large.rJour.y}`);
			await page.close();

			const { page: p2, mesure: etroit } = await ouvrir(navigateur, {
				largeur: 420, jour: journee({ busy: [bloc('14:00', '15:00')] }),
			});
			verifier('à l’étroit, elle passe dessous',
				etroit.rJour.y >= etroit.rMain.bottom - 1,
				`main ${etroit.rMain.bottom}, jour ${etroit.rJour.y}`);
			verifier('et rien ne déborde', !etroit.debordement);
			verifier('la piste garde une largeur utile', etroit.rPiste.w > 100, `${etroit.rPiste.w}`);
			await garder(p2, 'volet-etroit');
			await p2.close();
		}

		// ── 6. L'écriture droite-à-gauche ───────────────────────────────────
		{
			const { page, mesure: ltr } = await ouvrir(navigateur, {
				jour: journee({ busy: [bloc('14:00', '15:00', { summary: 'A' }), bloc('14:30', '15:30', { summary: 'B' })] }),
			});
			await page.close();

			const { page: p2, mesure: rtl } = await ouvrir(navigateur, {
				rtl: true,
				jour: journee({ busy: [bloc('14:00', '15:00', { summary: 'A' }), bloc('14:30', '15:30', { summary: 'B' })] }),
			});

			verifier('en LTR l’axe des heures est à gauche de la piste',
				ltr.rAxe.x < ltr.rPiste.x, `axe ${ltr.rAxe.x}, piste ${ltr.rPiste.x}`);
			verifier('en RTL il passe à droite',
				rtl.rAxe.x > rtl.rPiste.x, `axe ${rtl.rAxe.x}, piste ${rtl.rPiste.x}`);
			verifier('en RTL la première voie est celle de droite',
				rtl.blocs[0].r.x > rtl.blocs[1].r.x,
				`A ${rtl.blocs[0].r.x}, B ${rtl.blocs[1].r.x}`);
			verifier('en RTL les deux réunions ne se recouvrent toujours pas',
				!(rtl.blocs[0].r.x < rtl.blocs[1].r.right - 1 && rtl.blocs[1].r.x < rtl.blocs[0].r.right - 1));
			verifier('en RTL rien ne déborde', !rtl.debordement);
			verifier('les textes de contenu décident seuls de leur sens',
				rtl.bidi.length && rtl.bidi.every((v) => 'plaintext' === v), rtl.bidi.join(','));
			await garder(p2, 'rtl');
			await p2.close();
		}

		// ── 7. Le thème déplace bien les couleurs ───────────────────────────
		{
			const { page, mesure: clair } = await ouvrir(navigateur, {
				jour: journee({ busy: [bloc('14:00', '15:00')] }),
			});
			await page.close();

			const { page: p2, mesure: sombre } = await ouvrir(navigateur, {
				theme: 'sombre', jour: journee({ busy: [bloc('14:00', '15:00')] }),
			});

			verifier('le fond de la piste suit le thème',
				clair.fondPiste !== sombre.fondPiste, `${clair.fondPiste} des deux côtés`);
			verifier('le fond d’un bloc suit le thème',
				clair.blocs[0].fond !== sombre.blocs[0].fond, `${clair.blocs[0].fond} des deux côtés`);
			verifier('la couleur du texte suit le thème',
				clair.texteCarte !== sombre.texteCarte, `${clair.texteCarte} des deux côtés`);
			await garder(p2, 'sombre');
			await p2.close();
		}

	} finally {
		await navigateur.close();
	}

	const echecs = controles.filter((c) => !c.ok);
	controles.forEach((c) => console.log(`  ${c.ok ? 'ok  ' : 'ÉCHEC'} ${c.nom}${c.detail ? ' — ' + c.detail : ''}`));
	console.log(`\nContrôles : ${controles.length}  Réussis : ${controles.length - echecs.length}  Échecs : ${echecs.length}`);
	process.exit(echecs.length ? 1 : 0);
})().catch((e) => {
	console.error(e);
	process.exit(1);
});
