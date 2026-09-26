#!/usr/bin/env node
/**
 * Serve web/ locally the way the host will: /signa/ is the landing page and
 * /signa/demo/ the demo.
 *
 *   node tools/build_site.js && node tools/serve_site.js    (PORT, default 4180)
 */
'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');

const REPO = path.join(__dirname, '..');
const WEB = path.join(REPO, 'web');
const PORT = Number(process.env.PORT || 4180);

const MIME = {
	'.html': 'text/html; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.js': 'application/javascript; charset=utf-8',
	'.woff2': 'font/woff2',
	'.svg': 'image/svg+xml',
	'.txt': 'text/plain; charset=utf-8',
};

const MOUNTS = { '/signa/': 'signa' };

function handler(req, res) {
	const url = new URL(req.url, 'http://local');
	let pathname = decodeURIComponent(url.pathname);

	if (pathname === '/' || pathname === '/signa' || pathname === '/signa/demo' || /^\/demo\/?$/.test(pathname)) {
		res.writeHead(302, { Location: /demo$|demo\/$/.test(pathname) ? '/signa/demo/' : '/signa/' });
		res.end();
		return;
	}

	const mount = Object.keys(MOUNTS).find((prefix) => pathname.startsWith(prefix));

	if (!mount) {
		res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
		res.end('Not found');
		return;
	}

	const root = path.join(WEB, MOUNTS[mount]);
	let file = path.join(root, pathname.slice(mount.length) || 'index.html');

	if (!file.startsWith(root)) {
		res.writeHead(403);
		res.end();
		return;
	}

	if (fs.existsSync(file) && fs.statSync(file).isDirectory()) {
		file = path.join(file, 'index.html');
	}

	fs.readFile(file, (error, data) => {
		if (error) {
			res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
			res.end('Not found');
			return;
		}

		const type = MIME[path.extname(file)] || 'application/octet-stream';

		res.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-store' });
		res.end(data);
	});
}

module.exports = { handler, create: () => http.createServer(handler) };

if (require.main === module) {
	http.createServer(handler).listen(PORT, '0.0.0.0', () => {
		console.log('Signa sites on http://0.0.0.0:' + PORT + '  →  /signa/ (landing)  /signa/demo/ (demo)');
	});
}
