#!/usr/bin/env node
/**
 * Token contrast gate (UI plan A1).
 *
 * Reads the real token values out of assets/css/front.css *and* admin.css and
 * checks the pairs that WCAG actually constrains: text on its background
 * >= 4.5:1, and the border of an interactive control >= 3:1 (1.4.11).
 * Run: node tests/contrast.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const CSS = path.join(__dirname, '..', 'signa', 'assets', 'css', 'front.css');
const css = fs.readFileSync(CSS, 'utf8');

/** Pull a `--token: value;` out of a given selector block. */
function tokens(selector, source) {
	const css = source || require('fs').readFileSync(CSS, 'utf8');
	const start = css.indexOf(selector + ' {');
	if (start < 0) throw new Error('selector not found: ' + selector);
	const block = css.slice(start, css.indexOf('\n}', start));
	const found = {};
	block.replace(/--([\w-]+):\s*([^;]+);/g, (m, name, value) => {
		found['--' + name] = value.trim();
		return m;
	});
	return found;
}

/** Follow `var(--x)` indirections before parsing a colour. */
function resolve(value, scope) {
	let current = String(value).trim();
	for (let hops = 0; hops < 5; hops++) {
		const ref = current.match(/^var\(\s*(--[\w-]+)\s*\)$/);
		if (!ref) return current;
		if (!(ref[1] in scope)) throw new Error('unknown token: ' + ref[1]);
		current = String(scope[ref[1]]).trim();
	}
	throw new Error('var() chain too deep: ' + value);
}

/**
 * Parse a colour into channels *and* alpha.
 *
 * Alpha matters here: several tokens are washes (`rgba(15,118,110,.1)`), and
 * comparing a wash against its own colour without flattening it first reports a
 * false failure — or hides a real one the other way round.
 */
function parseColour(value) {
	const hex = value.trim().replace(/^#/, '');
	if (/^[0-9a-f]{6}$/i.test(hex)) {
		return { rgb: [0, 2, 4].map((i) => parseInt(hex.substr(i, 2), 16)), alpha: 1 };
	}
	if (/^[0-9a-f]{3}$/i.test(hex)) {
		return { rgb: [0, 1, 2].map((i) => parseInt(hex[i] + hex[i], 16)), alpha: 1 };
	}
	const rgba = value.match(/rgba?\(([^)]+)\)/);
	if (rgba) {
		const parts = rgba[1].split(',').map((n) => parseFloat(n));
		return { rgb: [parts[0], parts[1], parts[2]], alpha: parts.length > 3 ? parts[3] : 1 };
	}
	throw new Error('cannot parse colour: ' + value);
}

function toRgb(value) {
	return parseColour(value).rgb;
}

/** Put a translucent colour on top of an opaque one, the way a browser does. */
function flatten(value, backdrop) {
	const colour = parseColour(value);
	if (colour.alpha >= 1) {
		return colour.rgb;
	}
	const base = parseColour(backdrop).rgb;
	return colour.rgb.map((channel, i) => channel * colour.alpha + base[i] * (1 - colour.alpha));
}

function luminance(rgb) {
	const [r, g, b] = rgb.map((c) => {
		const s = c / 255;
		return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
	});
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function ratio(a, b, scope, backdrop) {
	// Everything is measured as it is painted: on the card, over white.
	const base = backdrop || '#ffffff';
	const la = luminance(flatten(resolve(a, scope), base));
	const lb = luminance(flatten(resolve(b, scope), base));
	return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

const ADMIN_CSS = path.join(__dirname, '..', 'signa', 'assets', 'css', 'admin.css');
const adminCss = fs.readFileSync(ADMIN_CSS, 'utf8');

const light = tokens('.signa');
const slate = tokens('.signa-skin-slate');
const dark = Object.assign({}, light, slate);

// The dashboard has its own token block, and its own way to fail a contrast
// check: an accent that reads fine on a card but not on the soft tint behind a
// table cell.
// The block is shared with the modal, which WordPress appends to <body>.
const adminRaw = tokens('.signa-wrap,\n.signa-modal', adminCss);
const admin = Object.assign(
	{
		'--signa-card': '#ffffff',
		'--signa-white': '#ffffff',
		'--signa-accent-wash': 'rgba(15, 118, 110, 0.1)',
	},
	adminRaw
);
admin['--signa-bg'] = adminRaw['--signa-bg'] || '#f7f8fa';
admin['--signa-white'] = '#ffffff';

// [label, foreground, background, minimum, scope]
const checks = [
	['light: body text on surface', light['--signa-text'], light['--signa-surface'], 4.5],
	['light: muted text on surface', light['--signa-muted'], light['--signa-surface'], 4.5],
	['light: accent on surface', light['--signa-accent'], light['--signa-surface'], 4.5],
	['light: placeholder on surface', light['--signa-placeholder'], light['--signa-surface'], 4.5],
	['light: danger on surface', light['--signa-danger'], light['--signa-surface'], 4.5],
	['light: success on surface', light['--signa-success'], light['--signa-surface'], 4.5],
	['light: info on surface', light['--signa-info'], light['--signa-surface'], 4.5],
	['light: button label on accent', light['--signa-accent-contrast'], light['--signa-accent'], 4.5],
	['light: button label on accent hover', light['--signa-accent-contrast'], light['--signa-accent-strong'], 4.5],
	['light: input border on surface', light['--signa-input-line'], light['--signa-surface'], 3],
	['light: focus ring on surface', light['--signa-focus'], light['--signa-surface'], 3, light],

	['slate: body text on surface', dark['--signa-text'], dark['--signa-surface'], 4.5],
	['slate: muted text on surface', dark['--signa-muted'], dark['--signa-surface'], 4.5],
	['slate: placeholder on surface', dark['--signa-placeholder'], dark['--signa-surface'], 4.5],
	['slate: danger on surface', dark['--signa-danger'], dark['--signa-surface'], 4.5],
	['slate: success on success-soft', dark['--signa-success'], dark['--signa-success-soft'], 4.5],
	['slate: input border on surface', dark['--signa-input-line'], dark['--signa-surface'], 3],
	['slate: focus ring on surface', dark['--signa-focus'], dark['--signa-surface'], 3, dark],

	// The admin screens: same tokens, white cards on a grey page.
	['admin: body text on card', admin['--signa-ink'], admin['--signa-white'], 4.5, admin],
	['admin: muted text on card', admin['--signa-muted'], admin['--signa-white'], 4.5, admin],
	['admin: muted text on page', admin['--signa-muted'], admin['--signa-bg'], 4.5, admin],
	['admin: accent on card', admin['--signa-accent'], admin['--signa-white'], 4.5, admin],
	['admin: accent on its own soft tint (table codes)', admin['--signa-accent'], admin['--signa-accent-soft'], 4.5, admin],
	['admin: danger on card (failed bar legend, numbers)', admin['--signa-danger'], admin['--signa-white'], 4.5, admin],
	['admin: success on card (sent legend, numbers)', admin['--signa-success'], admin['--signa-white'], 4.5, admin],
	['admin: card border on page', admin['--signa-line'], admin['--signa-bg'], 1],
	// The screen switcher: the current pill is filled with the accent.
	['admin: current screen pill (white on accent)', admin['--signa-white'], admin['--signa-accent'], 4.5, admin],
	['admin: screen pill label on card', admin['--signa-ink'], admin['--signa-white'], 4.5, admin],
	['admin: warning text on card (test rows)', admin['--signa-warning'], admin['--signa-white'], 4.5, admin],
	['admin: modal result state on its tint', admin['--signa-danger'], admin['--signa-white'], 4.5, admin],
	// 2.0 design: hints, notices and the side navigation.
	['admin: hint text on card', admin['--signa-hint'], admin['--signa-white'], 4.5, admin],
	['admin: current section label on its tint', admin['--signa-accent'], admin['--signa-accent-soft'], 4.5, admin],
	['admin: primary button label (white on accent hover)', admin['--signa-white'], admin['--signa-accent-strong'], 4.5, admin],
	['admin: info notice on its tint', admin['--signa-info'], admin['--signa-info-soft'], 4.5, admin],
	['admin: warning notice on its tint', admin['--signa-warning'], admin['--signa-warning-soft'], 4.5, admin],
	['admin: success notice on its tint', admin['--signa-success'], admin['--signa-success-soft'], 4.5, admin],
	['admin: danger chip on its tint', admin['--signa-danger'], admin['--signa-danger-soft'], 4.5, admin],
	['admin: input border on card (1.4.11)', admin['--signa-field'], admin['--signa-white'], 3, admin],
];

let failed = 0;
for (const [label, fg, bg, min, scope] of checks) {
	const fallback = label.startsWith('slate') ? dark : (label.startsWith('admin') ? admin : light);
	const value = ratio(fg, bg, scope || fallback);
	const pass = value >= min;
	if (!pass) failed++;
	console.log(`${pass ? 'PASS' : 'FAIL'}  ${value.toFixed(2)}:1 (min ${min})  ${label}`);
}

console.log(failed ? `\n${failed} contrast check(s) failed` : `\nAll ${checks.length} contrast checks passed`);
process.exit(failed ? 1 : 0);
