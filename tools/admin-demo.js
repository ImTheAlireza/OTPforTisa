#!/usr/bin/env node
/**
 * Writes (or checks) the admin preview pages.
 *
 * `tools/admin-demo.php` renders the plugin's real admin screens and prints
 * them as one JSON object; this script puts each page in `preview/public/`.
 *
 *   node tools/admin-demo.js           # write the pages
 *   node tools/admin-demo.js --check   # fail if a committed page is stale
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.join(__dirname, '..');
const OUT = path.join(ROOT, 'preview', 'public');
const check = process.argv.includes('--check');

/*
 * php-test.js ends with process.exit(), which can cut a large write to a pipe
 * short; a file descriptor is written synchronously, so the JSON goes through
 * a temporary file.
 */
const tmp = path.join(os.tmpdir(), 'signa-admin-demo-' + process.pid + '.json');
const fd = fs.openSync(tmp, 'w');

try {
	// SIGNA_PHP=php uses a real interpreter (CI); otherwise php-wasm through php-test.js.
	const [cmd, args] = process.env.SIGNA_PHP
		? [process.env.SIGNA_PHP, ['tools/admin-demo.php']]
		: [process.execPath, [path.join(__dirname, 'php-test.js'), '--quiet', 'tools/admin-demo.php']];

	execFileSync(cmd, args, {
		cwd: ROOT,
		stdio: ['ignore', fd, 'inherit'],
	});
} finally {
	fs.closeSync(fd);
}

const raw = fs.readFileSync(tmp, 'utf8');
fs.unlinkSync(tmp);

const start = raw.indexOf('{"admin"');

if (start < 0 || raw.slice(0, start).trim() !== '') {
	console.error(raw.slice(0, Math.max(start, 2000)));
	console.error('admin-demo.php printed something before its JSON (a PHP notice?)');
	process.exit(1);
}
const pages = JSON.parse(raw.slice(start));
let stale = 0;

for (const [name, html] of Object.entries(pages)) {
	const file = path.join(OUT, name + '.html');

	if (check) {
		const committed = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : '';

		if (committed !== html) {
			console.error('stale: preview/public/' + name + '.html');
			stale++;
		}

		continue;
	}

	fs.writeFileSync(file, html);
	console.log('wrote preview/public/' + name + '.html (' + html.length + ' bytes)');
}

if (check) {
	if (stale) {
		console.error(stale + ' page(s) stale — run: node tools/admin-demo.js');
		process.exit(1);
	}

	console.log(Object.keys(pages).length + ' admin preview pages match the plugin');
}
