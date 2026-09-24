#!/usr/bin/env node
/**
 * The stylesheets, parsed rather than eyeballed.
 *
 * front.css is loaded twice over: once as a `<link>` and once as text that is
 * injected into a shadow root. A single unclosed brace in the second path does
 * not degrade gracefully — it silently swallows every rule after it, and the
 * form that ships is a form nobody designed. The same goes for the
 * `@font-face` blocks: a `url()` that points at a file that is not in the
 * package is a fallback font in every visitor's browser, on the site only.
 *
 * So this is a gate, not a report: the file must parse with zero errors, every
 * font URL must resolve to a real file, and no rule may reach outside the
 * plugin's own namespace (a bare `button { ... }` would restyle the whole
 * site's buttons — which is the mirror image of the bug this plugin fights).
 *
 * Run:  node tools/css-check.js        (needs css-tree; see tests/README.md)
 */

'use strict';

const fs = require('fs');
const path = require('path');

let csstree;

try {
	csstree = require('css-tree');
} catch (error) {
	console.error('css-tree is not installed. Run: npm install --no-save css-tree');
	process.exit(2);
}

const REPO = path.join(__dirname, '..');
const FILES = ['signa/assets/css/front.css', 'signa/assets/css/admin.css'];

/**
 * Split a selector list on its own commas.
 *
 * A naive `split(',')` also splits inside `:where(a, b)` and `:is(a, b)`, which
 * turns one scoped selector into fragments — and a fragment like `svg` reads as
 * a leaked bare-element selector. Depth-aware splitting keeps a functional
 * pseudo together, and bare lists (`img, svg { … }`) still get caught.
 */
function selectorParts(selector) {
	const parts = [];
	let depth = 0;
	let quote = '';
	let current = '';

	for (const character of String(selector)) {
		if ('' !== quote) {
			current += character;

			if (character === quote) {
				quote = '';
			}

			continue;
		}

		if ('"' === character || "'" === character) {
			quote = character;
			current += character;
			continue;
		}

		if ('(' === character) {
			depth++;
		} else if (')' === character) {
			depth = Math.max(0, depth - 1);
		}

		if (',' === character && 0 === depth) {
			parts.push(current);
			current = '';
			continue;
		}

		current += character;
	}

	parts.push(current);

	return parts;
}

/** Selectors that would leak the plugin's styles onto the rest of the site. */
const FOREIGN = /^(?:html|body|a|p|div|span|ul|ol|li|table|tr|td|th|h[1-6]|input|button|select|textarea|form|img|svg|label|small|strong|em|section|article|header|footer|nav|aside|main|figure|blockquote|pre|code)(?:[.:\s,>+~]|$)/;

let problems = 0;

for (const file of FILES) {
	const full = path.join(REPO, file);
	const css = fs.readFileSync(full, 'utf8');
	const errors = [];

	let ast;

	try {
		ast = csstree.parse(css, { positions: true, onParseError: (error) => errors.push(error.formattedMessage || error.message) });
	} catch (error) {
		console.log(`  FAIL  ${file} does not parse — ${error.message}`);
		problems++;
		continue;
	}

	let rules = 0;
	let declarations = 0;
	let faces = 0;
	const leaked = [];
	const missing = [];

	csstree.walk(ast, {
		visit: 'Rule',
		enter(node) {
			rules++;
			const selector = csstree.generate(node.prelude);

			// `:host`, `:root` and the plugin's own classes are all fine; a
			// bare element selector is not.
			selectorParts(selector).forEach((part) => {
				const trimmed = part.trim();

				if ('' === trimmed || trimmed.indexOf('.signa') >= 0 || trimmed.indexOf(':host') >= 0) {
					return;
				}

				if (FOREIGN.test(trimmed)) {
					leaked.push(trimmed);
				}
			});
		},
	});

	csstree.walk(ast, {
		visit: 'Declaration',
		enter(node) {
			declarations++;

			if ('src' !== node.property.toLowerCase()) {
				return;
			}

			const value = csstree.generate(node.value);
			const urls = value.match(/url\((['"]?)([^'")]+)\1\)/g) || [];

			urls.forEach((url) => {
				const relative = url.replace(/^url\((['"]?)/, '').replace(/['"]?\)$/, '').split(/[?#]/)[0];

				if (/^(https?:)?\/\//.test(relative)) {
					return;
				}

				const resolved = path.resolve(path.dirname(full), relative);

				if (!fs.existsSync(resolved)) {
					missing.push(relative);
				}
			});
		},
	});

	csstree.walk(ast, {
		visit: 'Atrule',
		enter(node) {
			if ('font-face' === node.name) {
				faces++;
			}
		},
	});

	console.log(`${file}: ${rules} rules, ${declarations} declarations, ${faces} @font-face, ${errors.length} parse error(s)`);

	if (errors.length) {
		problems += errors.length;
		errors.slice(0, 5).forEach((error) => console.log('    ' + error.replace(/\n/g, ' ').slice(0, 160)));
	}

	if (leaked.length) {
		problems += leaked.length;
		console.log(`  FAIL  ${file} styles elements it does not own: ${leaked.slice(0, 6).join(' | ')}`);
	}

	if (missing.length) {
		problems += missing.length;
		console.log(`  FAIL  ${file} points at files that are not in the package: ${missing.slice(0, 6).join(' | ')}`);
	}
}

/* The fonts themselves: a truncated download is a 404 in every browser. */
const FONT_DIR = path.join(REPO, 'signa/assets/fonts');
const fonts = fs.existsSync(FONT_DIR) ? fs.readdirSync(FONT_DIR).filter((name) => name.endsWith('.woff2')) : [];

if (0 === fonts.length) {
	console.log('  FAIL  no font files in signa/assets/fonts');
	problems++;
}

fonts.forEach((name) => {
	const magic = fs.readFileSync(path.join(FONT_DIR, name)).subarray(0, 4).toString('binary');

	if ('wOF2' !== magic) {
		console.log(`  FAIL  ${name} is not a woff2 file`);
		problems++;
	}
});

console.log(`fonts: ${fonts.length} file(s), all readable woff2`);

if (problems > 0) {
	console.log(`\n${problems} problem(s) in the stylesheets`);
	process.exit(1);
}

console.log('\nstylesheets are sound: they parse, they stay in their namespace, and their fonts are here');
