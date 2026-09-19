#!/usr/bin/env node
/**
 * Static checks for the PHP sources that a tokenizer alone cannot catch.
 *
 * `php -l` reports two very different things: syntax errors, and *compile*
 * errors such as declaring the same method twice. A tokenizer-only lint (which
 * is all this environment can run without a php binary) happily accepts the
 * second kind — that is how a real `Cannot redeclare CaptchaResult::passed()`
 * fatal reached CI. This script closes that gap:
 *
 *   - duplicate method / property / constant declarations in one class
 *     (PHP keeps ONE method table, so a static factory and an instance getter
 *     cannot share a name)
 *   - abstract/interface methods that carry a body, and non-abstract ones that
 *     do not
 *   - `use` imports that collide on the same short name
 *
 * Usage:  node tools/php-static-check.js [dir]     (default: tisa-otp)
 */
'use strict';

const fs = require('fs');
const path = require('path');

let Engine;
try {
	Engine = require('php-parser');
} catch (error) {
	console.error('php-parser is not installed. Run: npm install --no-save php-parser');
	process.exit(2);
}

const root = process.argv[2] || 'tisa-otp';
const parser = new Engine({ parser: { extractDoc: false }, ast: { withPositions: true } });

const files = [];
(function walk(dir) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			if (!['node_modules', '.git', 'vendor'].includes(entry.name)) walk(full);
		} else if (entry.name.endsWith('.php')) {
			files.push(full);
		}
	}
})(root);

function walkAst(node, visit) {
	if (!node || typeof node !== 'object') return;
	if (Array.isArray(node)) {
		node.forEach((child) => walkAst(child, visit));
		return;
	}
	visit(node);
	for (const key of Object.keys(node)) {
		if (key === 'loc') continue;
		walkAst(node[key], visit);
	}
}

const nameOf = (value) => (value && (value.name || value)) || '';
const lineOf = (node) => (node && node.loc ? node.loc.start.line : 0);

let problems = 0;
const report = (file, line, message) => {
	problems++;
	console.log(`${file}:${line}  ${message}`);
};

for (const file of files) {
	let ast;
	try {
		ast = parser.parseCode(fs.readFileSync(file, 'utf8'), file);
	} catch (error) {
		report(file, error.lineNumber || 0, 'PARSE ERROR: ' + error.message);
		continue;
	}

	// Colliding `use` short names.
	const imports = new Map();
	walkAst(ast, (node) => {
		if ('usegroup' !== node.kind) return;
		for (const item of node.items || []) {
			const full = nameOf(item.name);
			const short = item.alias ? nameOf(item.alias) : String(full).split('\\').pop();
			const key = short.toLowerCase();
			if (imports.has(key) && imports.get(key).full !== full) {
				report(file, lineOf(item), `import collision: "${short}" already refers to ${imports.get(key).full}`);
			} else {
				imports.set(key, { full });
			}
		}
	});

	walkAst(ast, (node) => {
		if (!['class', 'interface', 'trait'].includes(node.kind)) return;

		const owner = nameOf(node.name) || '(anonymous)';
		const methods = new Map();
		const props = new Map();
		const consts = new Map();

		for (const item of node.body || []) {
			if ('method' === item.kind) {
				const name = nameOf(item.name);
				// PHP's method table is case-insensitive and shared by static
				// and instance methods alike.
				const key = String(name).toLowerCase();

				if (methods.has(key)) {
					report(file, lineOf(item), `Cannot redeclare ${owner}::${name}() — first declared on line ${methods.get(key)}`);
				} else {
					methods.set(key, lineOf(item));
				}

				const isAbstract = !!item.isAbstract || 'interface' === node.kind;
				if (isAbstract && item.body) {
					report(file, lineOf(item), `${owner}::${name}() is abstract but has a body`);
				}
				if (!isAbstract && !item.body) {
					report(file, lineOf(item), `${owner}::${name}() has no body but is not abstract`);
				}
			}

			if ('propertystatement' === item.kind) {
				for (const prop of item.properties || []) {
					const name = nameOf(prop.name);
					if (props.has(name)) {
						report(file, lineOf(prop), `Cannot redeclare ${owner}::$${name} — first declared on line ${props.get(name)}`);
					} else {
						props.set(name, lineOf(prop));
					}
				}
			}

			if ('classconstant' === item.kind) {
				for (const entry of item.constants || []) {
					const name = nameOf(entry.name);
					if (consts.has(name)) {
						report(file, lineOf(entry), `Cannot redeclare constant ${owner}::${name} — first declared on line ${consts.get(name)}`);
					} else {
						consts.set(name, lineOf(entry));
					}
				}
			}
		}
	});
}

console.log(`\n${files.length} files scanned, ${problems} problem(s)`);
process.exit(problems ? 1 : 0);
