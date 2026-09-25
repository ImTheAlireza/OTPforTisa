/**
 * The public sites (web/signa, web/demo) work on their own: every link lands,
 * the live form signs in against the in-browser mock, and the admin demo's
 * buttons answer without a server.
 *
 *   node tools/build_site.js && node tests/site.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');
const site = require('../tools/serve_site.js');

const REPO = path.join(__dirname, '..');
const WEB = path.join(REPO, 'web');

let failed = 0;
let passed = 0;

function check(name, ok, detail) {
	if (ok) {
		passed += 1;
		console.log('  PASS  ' + name);
	} else {
		failed += 1;
		console.log('  FAIL  ' + name + (detail ? '  — ' + detail : ''));
	}
}

function scenario(name) {
	console.log('\n' + name);
}

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function until(test, ms = 6000) {
	const end = Date.now() + ms;
	while (Date.now() < end) {
		if (test()) {
			return true;
		}
		await wait(40);
	}
	return !!test();
}

async function open(base, pathname) {
	const errors = [];
	const vc = new VirtualConsole();

	vc.on('jsdomError', (error) => {
		// jsdom cannot navigate or lay out; neither is what is tested here.
		if (!/Not implemented: (navigation|window\.scrollTo|HTMLCanvasElement)|Could not parse CSS stylesheet/.test(error.message)) {
			errors.push(error.message);
		}
	});

	const dom = await JSDOM.fromURL(base + pathname, {
		runScripts: 'dangerously',
		resources: 'usable',
		pretendToBeVisual: true,
		virtualConsole: vc,
		beforeParse(win) {
			// jsdom has no fetch; the page's own requests go to the local server.
			win.fetch = (input, init) => {
				const url = new URL(typeof input === 'string' ? input : input.url, win.location.href);
				const options = Object.assign({}, init || {});
				delete options.signal;
				return fetch(url, options);
			};
			win.Response = Response;
			win.Headers = Headers;
			win.HTMLFormElement.prototype.submit = function () {};
			win.scrollTo = () => {};
			win.HTMLElement.prototype.scrollIntoView = function () {};
		},
	});

	await new Promise((resolve) => {
		if (dom.window.document.readyState === 'complete') {
			resolve();
		} else {
			dom.window.addEventListener('load', resolve);
		}
	});

	return { dom, win: dom.window, doc: dom.window.document, errors };
}

function text(node) {
	return node ? node.textContent.replace(/\s+/g, ' ').trim() : '';
}

/* Every address a page links to exists in the same site. */
function linkCheck() {
	scenario('Every internal link and asset of the built sites exists');

	for (const root of ['signa', 'demo']) {
		const dir = path.join(WEB, root);
		const pages = fs.readdirSync(dir).filter((f) => f.endsWith('.html'));
		const missing = [];

		for (const page of pages) {
			const html = fs.readFileSync(path.join(dir, page), 'utf8');
			for (const [, ref] of html.matchAll(/(?:href|src)="([^"]+)"/g)) {
				if (/^(https?:|#|data:|mailto:|javascript:)/.test(ref)) {
					continue;
				}
				const file = ref.replace(/[?#].*$/, '');
				if (file && !fs.existsSync(path.join(dir, file))) {
					missing.push(page + ' → ' + ref);
				}
			}
		}

		check(root + ': ' + pages.length + ' pages, no broken internal link', missing.length === 0, missing.slice(0, 5).join(', '));
	}

	const total = (dir) => fs.readdirSync(dir, { withFileTypes: true }).reduce((sum, e) => sum + (e.isDirectory() ? total(path.join(dir, e.name)) : fs.statSync(path.join(dir, e.name)).size), 0);
	const size = total(WEB);
	check('both sites together stay small for the host (under 2 MB)', size < 2 * 1024 * 1024, Math.round(size / 1024) + ' KB');
}

async function signIn(ctx, phone) {
	const host = ctx.doc.querySelector('[data-signa-form]');
	await until(() => host && host.signaForm);
	const form = host.signaForm;
	const scope = () => host.shadowRoot || host;

	scope().querySelector('[data-signa-phone]').value = phone;
	form.act('start');

	await until(() => form.stepCode && form.stepCode.classList.contains('is-current'));
	// The boxes are rebuilt for the code length the server announces, so type
	// into the ones the form holds now.
	(form.boxes || []).forEach((box, i) => {
		box.value = '12345'.charAt(i);
		box.dispatchEvent(new ctx.win.Event('input', { bubbles: true }));
	});

	if (form.bulk && !(form.boxes || []).length) {
		form.bulk.value = '12345';
	}

	await until(() => form.root.classList.contains('is-signed-in'), 4000);

	if (!form.root.classList.contains('is-signed-in')) {
		form.act('verify');
		await until(() => form.root.classList.contains('is-signed-in'), 4000);
	}

	return { host, form, scope };
}

async function landing(base) {
	scenario('Landing page: the hero form is live and signs in');

	const ctx = await open(base, '/signa/');
	const host = ctx.doc.querySelector('[data-signa-form]');

	check('the page title names the product', /سیگنا/.test(ctx.doc.title));
	check('the form is on the page', !!host);
	check('front.js mounted it', await until(() => host && host.signaForm));
	check('inside its own shadow root, like on a real site', await until(() => !!host.shadowRoot));

	const { form, scope } = await signIn(ctx, '09121234567');
	check('an existing number reaches the code step and signs in with 12345', form.root.classList.contains('is-signed-in'), text(scope().querySelector('[data-signa-status-text]')));

	check('no buy link points nowhere', Array.from(ctx.doc.querySelectorAll('a[aria-disabled="true"]')).every((a) => !a.hasAttribute('href')));
	check('the demo is linked', !!ctx.doc.querySelector('a[href*="demo"]'));
	check('no script error on the page', ctx.errors.length === 0, ctx.errors.slice(0, 3).join(' | '));
	ctx.win.close();
}

async function demoForm(base) {
	scenario('Demo: the login form, new-member path and the strip');

	const ctx = await open(base, '/demo/');
	const strip = ctx.doc.querySelector('.sg-strip');

	check('the demo strip is on top', !!strip && ctx.doc.body.firstElementChild === strip);
	check('it marks the current page', text(strip && strip.querySelector('[aria-current="page"]')) === 'فرم ورود');
	check('the developer bar and its download link are gone', !ctx.doc.querySelector('.demo-bar') && !/signa\.zip/.test(ctx.doc.documentElement.outerHTML));

	const host = ctx.doc.querySelector('[data-signa-form]');
	await until(() => host && host.signaForm);
	const form = host.signaForm;
	const scope = () => host.shadowRoot || host;

	scope().querySelector('[data-signa-phone]').value = '09351112233';
	form.act('start');
	check('a new number opens the signup fields', await until(() => form.stepFields && form.stepFields.classList.contains('is-current')));

	ctx.win.close();

	const again = await open(base, '/demo/');
	const done = await signIn(again, '09121234567');
	check('signing in works on the demo page too', done.form.root.classList.contains('is-signed-in'));
	check('and it is set to continue to the member page', done.form.redirect === 'account.html' || done.host.getAttribute('data-redirect') === 'account.html');
	check('no script error on the page', again.errors.length === 0, again.errors.slice(0, 3).join(' | '));
	again.win.close();
}

async function demoAdmin(base) {
	scenario('Demo: the admin panel answers without a server');

	const ctx = await open(base, '/demo/admin-settings.html');
	const check1 = ctx.doc.querySelector('[data-signa-check="gateways"]');

	check('the settings screen loads with the strip', !!ctx.doc.querySelector('.sg-strip') && !!ctx.doc.querySelector('.signa-wrap'));
	check('the quick test button is there', !!check1);

	if (check1) {
		check1.click();
		const ok = await until(() => /آزمایش سامانه‌های پیامکی/.test(ctx.doc.body.textContent) && /SMS\.ir — اصلی/.test(ctx.doc.body.textContent));
		check('the quick test shows the gateway report from the mock', ok);
	}

	const appForm = ctx.doc.querySelector('form.signa-appmode');
	if (appForm) {
		appForm.dispatchEvent(new ctx.win.Event('submit', { bubbles: true, cancelable: true }));
		check('app mode toggles in place instead of posting to a server', ctx.doc.body.classList.contains('signa-app'));
		appForm.dispatchEvent(new ctx.win.Event('submit', { bubbles: true, cancelable: true }));
		check('and toggles back', !ctx.doc.body.classList.contains('signa-app'));
	} else {
		check('the app mode form is on the page', false);
	}

	const frame = ctx.doc.querySelector('iframe.signa-pv__iframe');
	check('the live form preview points at a page that exists', !!frame && fs.existsSync(path.join(WEB, 'demo', frame.getAttribute('src'))), frame ? frame.getAttribute('src') : 'no iframe');
	check('no script error on the page', ctx.errors.length === 0, ctx.errors.slice(0, 3).join(' | '));
	ctx.win.close();

	const tools = await open(base, '/demo/admin-tools.html');
	const link = tools.doc.querySelector('a[href*="example.test"]');
	if (link) {
		const event = new tools.win.MouseEvent('click', { bubbles: true, cancelable: true });
		link.dispatchEvent(event);
		check('a server-only link is stopped and explained', event.defaultPrevented && /در دمو فقط نمایشی/.test(text(tools.doc.getElementById('sg-toast'))));
	}
	check('the tools screen loads without script errors', tools.errors.length === 0, tools.errors.slice(0, 3).join(' | '));
	tools.win.close();

	for (const page of ['admin.html', 'admin-reports.html', 'admin-logs.html', 'admin-access.html', 'account.html', 'woodmart.html']) {
		const one = await open(base, '/demo/' + page);
		check(page + ' loads without script errors, under the strip', one.errors.length === 0 && !!one.doc.querySelector('.sg-strip'), one.errors.slice(0, 2).join(' | '));
		one.win.close();
	}
}

async function main() {
	if (!fs.existsSync(path.join(WEB, 'demo', 'index.html'))) {
		console.log('web/ is not built; run node tools/build_site.js');
		process.exit(1);
	}

	linkCheck();

	const server = site.create();
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
	const base = 'http://127.0.0.1:' + server.address().port;

	try {
		await landing(base);
		await demoForm(base);
		await demoAdmin(base);
	} finally {
		server.close();
	}

	console.log('\n' + (failed ? failed + ' FAILED, ' : '') + passed + ' checks passed');
	process.exit(failed ? 1 : 0);
}

main().catch((error) => {
	console.error(error);
	process.exit(1);
});
