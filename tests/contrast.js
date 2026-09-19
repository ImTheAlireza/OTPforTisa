#!/usr/bin/env node
/**
 * Token contrast gate (UI plan A1).
 *
 * Reads the real token values out of assets/css/front.css and checks the pairs
 * that WCAG actually constrains: text on its background >= 4.5:1, and the
 * border of an interactive control >= 3:1 (1.4.11). Run: node tests/contrast.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const CSS = path.join(__dirname, '..', 'tisa-otp', 'assets', 'css', 'front.css');
const css = fs.readFileSync(CSS, 'utf8');

/** Pull a `--token: value;` out of a given selector block. */
function tokens(selector) {
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

function toRgb(value) {
	const hex = value.trim().replace(/^#/, '');
	if (/^[0-9a-f]{6}$/i.test(hex)) {
		return [0, 2, 4].map((i) => parseInt(hex.substr(i, 2), 16));
	}
	if (/^[0-9a-f]{3}$/i.test(hex)) {
		return [0, 1, 2].map((i) => parseInt(hex[i] + hex[i], 16));
	}
	const rgba = value.match(/rgba?\(([^)]+)\)/);
	if (rgba) {
		const parts = rgba[1].split(',').map((n) => parseFloat(n));
		return [parts[0], parts[1], parts[2]];
	}
	throw new Error('cannot parse colour: ' + value);
}

function luminance(rgb) {
	const [r, g, b] = rgb.map((c) => {
		const s = c / 255;
		return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
	});
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function ratio(a, b, scope) {
	const la = luminance(toRgb(resolve(a, scope)));
	const lb = luminance(toRgb(resolve(b, scope)));
	return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

const light = tokens('.tisa-otp');
const slate = tokens('.tisa-skin-slate');
const dark = Object.assign({}, light, slate);

// [label, foreground, background, minimum, scope]
const checks = [
	['light: body text on surface', light['--tisa-text'], light['--tisa-surface'], 4.5],
	['light: muted text on surface', light['--tisa-muted'], light['--tisa-surface'], 4.5],
	['light: accent on surface', light['--tisa-accent'], light['--tisa-surface'], 4.5],
	['light: placeholder on surface', light['--tisa-placeholder'], light['--tisa-surface'], 4.5],
	['light: danger on surface', light['--tisa-danger'], light['--tisa-surface'], 4.5],
	['light: success on surface', light['--tisa-success'], light['--tisa-surface'], 4.5],
	['light: info on surface', light['--tisa-info'], light['--tisa-surface'], 4.5],
	['light: button label on accent', light['--tisa-accent-contrast'], light['--tisa-accent'], 4.5],
	['light: button label on accent hover', light['--tisa-accent-contrast'], light['--tisa-accent-strong'], 4.5],
	['light: input border on surface', light['--tisa-input-line'], light['--tisa-surface'], 3],
	['light: focus ring on surface', light['--tisa-focus'], light['--tisa-surface'], 3, light],

	['slate: body text on surface', dark['--tisa-text'], dark['--tisa-surface'], 4.5],
	['slate: muted text on surface', dark['--tisa-muted'], dark['--tisa-surface'], 4.5],
	['slate: placeholder on surface', dark['--tisa-placeholder'], dark['--tisa-surface'], 4.5],
	['slate: danger on surface', dark['--tisa-danger'], dark['--tisa-surface'], 4.5],
	['slate: success on success-soft', dark['--tisa-success'], dark['--tisa-success-soft'], 4.5],
	['slate: input border on surface', dark['--tisa-input-line'], dark['--tisa-surface'], 3],
	['slate: focus ring on surface', dark['--tisa-focus'], dark['--tisa-surface'], 3, dark],
];

let failed = 0;
for (const [label, fg, bg, min, scope] of checks) {
	const value = ratio(fg, bg, scope || (label.startsWith('slate') ? dark : light));
	const pass = value >= min;
	if (!pass) failed++;
	console.log(`${pass ? 'PASS' : 'FAIL'}  ${value.toFixed(2)}:1 (min ${min})  ${label}`);
}

console.log(failed ? `\n${failed} contrast check(s) failed` : `\nAll ${checks.length} contrast checks passed`);
process.exit(failed ? 1 : 0);
