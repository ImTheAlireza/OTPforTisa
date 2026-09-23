#!/usr/bin/env node
/**
 * Run the plugin's PHP logic tests without a PHP binary.
 *
 * This sandbox has no `php`, but it does have `@php-wasm/node` — a real PHP
 * 7.4 build compiled to WebAssembly. This script copies the plugin tree and the
 * test files into that interpreter's filesystem and runs the exact files CI
 * runs, so the assertions are exercised for real instead of being eyeballed.
 *
 *   node tools/php-test.js breaker-test.php blocklist-test.php
 *   node tools/php-test.js --lint          # require every plugin file
 *
 * Setup (once):  npm install --no-save @php-wasm/node
 * Overrides:     TISA_PHP_WASM=/path/to/node_modules/@php-wasm/node
 *                TISA_PHP_VERSION=8.3
 */
'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Where the wasm build lives: an explicit path, a local install, or the
 * scratch directory this sandbox keeps it in.
 */
function wasmDir() {
	if (process.env.TISA_PHP_WASM) {
		return process.env.TISA_PHP_WASM;
	}

	for (const base of [__dirname + '/..', '/tmp/phpwasm']) {
		try {
			return require('path').dirname(require.resolve('@php-wasm/node/package.json', { paths: [base] }));
		} catch (error) {
			// try the next base
		}
	}

	return '/tmp/phpwasm/node_modules/@php-wasm/node';
}

const WASM_DIR = wasmDir();
const ROOT = path.join(__dirname, '..');
const VERSION = process.env.TISA_PHP_VERSION || '7.4';

function loadModule(relative) {
	const base = WASM_DIR.replace(/\/@php-wasm\/node$/, '');

	return import('file://' + path.join(base, relative, 'index.js'));
}

function collect(hostDir, vfsDir, filter) {
	const out = [];

	for (const entry of fs.readdirSync(hostDir, { withFileTypes: true })) {
		if (entry.name.startsWith('.')) {
			continue;
		}

		const host = path.join(hostDir, entry.name);
		const vfs = vfsDir + '/' + entry.name;

		if (entry.isDirectory()) {
			out.push(...collect(host, vfs, filter));
		} else if (!filter || filter(host)) {
			out.push([host, vfs]);
		}
	}

	return out;
}

async function put(php, files) {
	const dirs = new Set();

	for (const [, vfs] of files) {
		let dir = path.posix.dirname(vfs);

		while ('/' !== dir && !dirs.has(dir)) {
			dirs.add(dir);
			dir = path.posix.dirname(dir);
		}
	}

	for (const dir of [...dirs].sort()) {
		// mkdirTree is happy when the directory is already there; mkdir is not.
		if (!php.isDir(dir)) {
			php.mkdirTree(dir);
		}
	}

	for (const [host, vfs] of files) {
		php.writeFile(vfs, new Uint8Array(fs.readFileSync(host)));
	}
}

async function main() {
	const args = process.argv.slice(2);
	const lint = args.includes('--lint');
	/*
	 * `--quiet` drops the banner, so a tool that prints something — the preview
	 * page generator — can be redirected straight into a file.
	 */
	const quiet = args.includes('--quiet');
	const scripts = args.filter((arg) => !arg.startsWith('--'));

	const { loadNodeRuntime } = await loadModule('@php-wasm/node');
	const { PHP } = await loadModule('@php-wasm/universal');

	// processId matters for file locking; a single-process test needs one value.
	const runtimeId = await loadNodeRuntime(VERSION, { emscriptenOptions: { processId: 1 } });
	const php = new PHP(runtimeId);

	const files = [
		...collect(path.join(ROOT, 'tests', 'php'), '/tests/php'),
		/*
		 * Everything in the plugin, not only the PHP: one test hashes the whole
		 * installed tree against build.json, which is how it proves this checkout
		 * is one release. Mounting only .php files made that test report every
		 * stylesheet as missing.
		 */
		...collect(path.join(ROOT, 'tisa-otp'), '/tisa-otp'),
		// The tools, so a generator can be run here too.
		...collect(path.join(ROOT, 'tools'), '/tools', (file) => file.endsWith('.php')),
	];

	await put(php, files);

	if (lint) {
		const plugin = files.filter(([, vfs]) => vfs.startsWith('/tisa-otp/') && vfs.endsWith('.php'));
		const failures = [];

		/*
		 * `token_get_all( $source, TOKEN_PARSE )` compiles without executing, so
		 * this is `php -l` for a file: a syntax error comes back as a ParseError
		 * and templates that need WordPress never get the chance to run.
		 */
		for (const [, vfs] of plugin) {
			const code =
				"<?php $source = file_get_contents( " +
				JSON.stringify(vfs) +
				" ); try { token_get_all( $source, TOKEN_PARSE ); echo 'LINT-OK'; }" +
				" catch ( \\ParseError $e ) { echo 'PARSE: ' . $e->getMessage(); }";

			try {
				const result = await php.run({ code });

				if (result.text.indexOf('LINT-OK') < 0) {
					failures.push([vfs, result.text || result.errors]);
				}
			} catch (error) {
				failures.push([vfs, (error && error.message) || String(error)]);
			}
		}

		for (const [file, message] of failures) {
			console.log('FAIL  ' + file + '\n      ' + String(message).split('\n')[0]);
		}

		console.log(plugin.length + ' plugin files, ' + failures.length + ' failed');
		process.exit(failures.length ? 1 : 0);
	}

	if (!scripts.length) {
		console.error('usage: node tools/php-test.js [--lint] <test-file.php> [...]');
		process.exit(2);
	}

	let failed = 0;

	if (!quiet) {
		console.log('PHP ' + VERSION + ' via WebAssembly\n');
	}

	for (const script of scripts) {
		// `tests/php/foo-test.php`, `tools/thing.php`, or a bare test name.
		const vfsPath = script.includes('/') ? '/' + script : '/tests/php/' + script;
		const result = await php.run({ scriptPath: vfsPath });

		process.stdout.write(result.text);

		if (result.errors) {
			process.stderr.write(String(result.errors) + '\n');
		}

		if (result.exitCode) {
			failed++;
		}
	}

	process.exit(failed ? 1 : 0);
}

main().catch((error) => {
	console.error(error);
	process.exit(1);
});
