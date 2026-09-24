#!/usr/bin/env node
/**
 * Behavioural tests for the real `assets/js/front.js` (UI plan A10).
 *
 * There is no PHP here, so the suite mounts the plugin's actual script against
 * the preview harness markup — the same HTML the PHP templates print — inside
 * jsdom, and drives it the way a visitor would. The backend is stubbed at the
 * `fetch` boundary with the exact envelope `src/Http/Api.php` returns, including
 * the fact that rejections arrive over HTTP 200 as `{success: false, ...}`.
 *
 * Run:  node tests/front.js        (needs jsdom; see tests/README.md)
 */
'use strict';

const fs = require('fs');
const path = require('path');

let JSDOM;
try {
	({ JSDOM } = require('jsdom'));
} catch (error) {
	console.error('jsdom is not installed. Run: npm install --no-save jsdom');
	process.exit(2);
}

const REPO = path.join(__dirname, '..');
const PAGE = path.join(REPO, 'preview', 'public', 'index.html');
const SCRIPT = path.join(REPO, 'signa', 'assets', 'js', 'front.js');

const scriptSource = fs.readFileSync(SCRIPT, 'utf8');
const pageSource = fs.readFileSync(PAGE, 'utf8');

// The admin preview is generated from the plugin's real screens, one file per
// screen (tools/admin-demo.js). A promise about "the admin demo" holds for the
// set, whichever screen it happens to be drawn on.
const ADMIN_DEMO = fs.readdirSync(path.join(REPO, 'preview', 'public'))
	.filter((name) => /^admin(-[a-z]+)?\.html$/.test(name) && !/^admin-preview-/.test(name))
	.sort()
	.map((name) => fs.readFileSync(path.join(REPO, 'preview', 'public', name), 'utf8'))
	.join('\n');

let passed = 0;
let failed = 0;

function check(label, condition, detail) {
	if (condition) {
		passed++;
		console.log('  PASS  ' + label);
	} else {
		failed++;
		console.log('  FAIL  ' + label + (detail ? '  — ' + detail : ''));
	}
}

function scenario(name) {
	console.log('\n' + name);
}

const tick = () => new Promise((resolve) => setImmediate(resolve));
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Boot the harness page with the real script.
 *
 * `html` lets a test change markup (data-* attributes outrank the global config
 * by design, so those scenarios must edit the HTML, not `window.signaOtp`).
 */
function boot(options) {
	const opts = options || {};
	const html = (opts.html || ((s) => s))(pageSource)
		// The harness ships its own <script src>; the suite injects the file itself.
		.replace(/<script src="\/plugin-assets\/js\/front\.js"><\/script>/, '');

	const dom = new JSDOM(html, { runScripts: 'outside-only', pretendToBeVisual: true });
	const win = dom.window;

	/*
	 * Inline <script> tags do not run in this mode, so the harness' stand-in for
	 * wp_localize_script has to be lifted out and evaluated by hand. Using the
	 * page's own block keeps the test honest: it exercises the same i18n strings
	 * and labels the plugin ships.
	 */
	const config = html.match(/window\.signaOtp\s*=\s*\{[\s\S]*?\n\t\};/);
	if (!config) throw new Error('could not find the signaOtp config block in the harness page');
	win.eval(config[0]);

	/*
	 * Merge the test's overrides over it, exactly as a site's settings would.
	 *
	 * Style isolation moves the form into a shadow root, and with it every node
	 * the scenarios below reach for; those scenarios are about the form's
	 * behaviour, not about where it is rendered, so they run in the light DOM.
	 * `testStyleIsolation()` — the scenario that *is* about isolation — turns it
	 * on and waits for the swap, and the PHP suite pins the shipped default.
	 */
	const overrides = Object.assign({ isolate: false }, opts.config || {});
	win.eval('window.__signaOverride = ' + JSON.stringify(overrides) + ';');
	win.eval('window.signaOtp = Object.assign({}, window.signaOtp, window.__signaOverride);');

	const calls = [];
	win.fetch = function (url, init) {
		const request = { url: String(url), init: init || {}, body: null };
		try {
			request.body = init && init.body ? JSON.parse(init.body) : null;
		} catch (error) {
			request.body = null;
		}
		calls.push(request);
		return (opts.fetch || (() => Promise.reject(new Error('no stub'))))(request, win);
	};

	// jsdom has no AbortController wired into fetch, and no navigator.onLine toggle.
	if (!win.AbortController) {
		win.AbortController = class {
			constructor() {
				this.signal = { aborted: false, addEventListener() {}, removeEventListener() {} };
			}
			abort() {
				this.signal.aborted = true;
				if ('function' === typeof this.signal.onabort) this.signal.onabort();
			}
		};
	}

	if (opts.offline) {
		Object.defineProperty(win.navigator, 'onLine', { value: false, configurable: true });
	}

	/*
	 * front.js decides whether to show the paste button while it binds, so a
	 * clipboard stub has to be in place before the script runs — a plain jsdom
	 * window has none, exactly like a page served over http.
	 */
	if (opts.clipboard) {
		Object.defineProperty(win.navigator, 'clipboard', { value: opts.clipboard, configurable: true });
	}

	win.eval(scriptSource);

	// jsdom is still parsing when the script runs, so front.js waits for the
	// event a browser would fire here.
	if ('loading' === win.document.readyState) {
		win.document.dispatchEvent(new win.Event('DOMContentLoaded', { bubbles: true }));
	}

	const root = win.document.querySelector('[data-signa-form]');

	if (!root || (!root.signaForm && !opts.deferred)) {
		throw new Error('front.js did not mount the form');
	}

	return { win, doc: win.document, root, form: root.signaForm, calls };
}

/**
 * Boot with style isolation on and wait for the swap.
 *
 * Isolation is asynchronous by nature — the stylesheet text has to be in hand
 * before the form can move into its own tree — so this waits for the mount
 * instead of asserting it happened in the same tick.
 */
async function bootIsolated(options) {
	const opts = Object.assign({ deferred: true }, options || {});
	const ctx = boot(opts);

	for (let i = 0; i < 60 && !ctx.root.signaForm; i++) {
		await wait(5);
	}

	ctx.form = ctx.root.signaForm;

	if (!ctx.form) {
		throw new Error('the isolated form never mounted');
	}

	return ctx;
}

/** The `{success: true, data}` envelope Api.php wraps every success in. */
function ok(data) {
	return Promise.resolve({
		ok: true,
		status: 200,
		json: () => Promise.resolve({ success: true, data }),
	});
}

/** A rejection: HTTP 200, `success: false`, with the code the UI maps on. */
function reject(code, message, data) {
	return Promise.resolve({
		ok: true,
		status: 200,
		json: () => Promise.resolve({ success: false, code, message, data: data || {} }),
	});
}

const verifyStep = (extra) =>
	Object.assign(
		{
			step: 'verify',
			scope: 'login',
			message: 'کد ۵ رقمی پیامک شد. تا ۲ دقیقه معتبر است.',
			masked: '0912***567',
			cooldown: 60,
			expires_in: 120,
			code_length: 5,
		},
		extra || {}
	);

const text = (el) => (el ? String(el.textContent || '').trim() : '');

/* ------------------------------------------------------------------ tests */

async function testStepBar() {
	scenario('Step bar tracks the flow and announces "step N of M"');

	const ctx = boot({
		fetch: (req) =>
			req.url.indexOf('form-config') >= 0
				? ok({ nonce: 'fresh' })
				: ok({ step: 'register_form', message: 'اطلاعات را کامل کنید.', masked: '0912***567' }),
	});

	const markers = Array.from(ctx.doc.querySelectorAll('[data-signa-step-marker]'));
	const announce = ctx.doc.querySelector('[data-signa-steps-text]');

	check('three markers rendered', 3 === markers.length, markers.length + ' found');
	check('first marker starts current', markers[0].classList.contains('is-current'));
	check('announcement starts at step 1', text(announce).indexOf('۱') >= 0, text(announce));

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	check('phone marker becomes done', markers[0].classList.contains('is-done'));
	check('fields marker becomes current', markers[1].classList.contains('is-current'));
	check(
		'announcement moves to step 2 with the step name',
		text(announce).indexOf('۲') >= 0 && text(announce).indexOf('اطلاعات') >= 0,
		text(announce)
	);
	check('list itself stays hidden from screen readers', 'true' === ctx.doc.querySelector('[data-signa-steps]').getAttribute('aria-hidden'));
}

async function testActionableErrors() {
	scenario('Errors offer an action instead of a dead end');

	const ctx = boot({
		fetch: (req) => {
			if (req.url.indexOf('form-config') >= 0) return ok({ nonce: 'fresh' });
			if (req.url.indexOf('/start') >= 0) return ok(verifyStep());
			return reject('invalid_code', 'کد درست نیست.', { attempts_left: 3 });
		},
	});

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	ctx.form.fillCode('99999');
	ctx.form.act('verify');
	await tick();
	await tick();

	const status = ctx.doc.querySelector('[data-signa-status]');
	const actions = Array.from(ctx.doc.querySelectorAll('.signa__status-action'));

	check('status is an assertive alert', 'alert' === status.getAttribute('role') && 'assertive' === status.getAttribute('aria-live'));
	check('invalid_code offers exactly one action', 1 === actions.length, actions.length + ' buttons');
	check('that action is "new code"', text(actions[0]).indexOf('کد تازه') >= 0, text(actions[0]));
	check('remaining attempts are shown', text(ctx.doc.querySelector('[data-signa-attempts]')).indexOf('۳') >= 0, text(ctx.doc.querySelector('[data-signa-attempts]')));
	check('code boxes are marked invalid', 'true' === ctx.doc.querySelector('[data-signa-box]').getAttribute('aria-invalid'));

	// A network failure should offer a retry that repeats the last action.
	const ctx2 = boot({
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : Promise.reject(new Error('down'))),
	});
	ctx2.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx2.form.act('start');
	await tick();
	await tick();

	const retry = ctx2.doc.querySelector('.signa__status-action');
	check('network error offers a retry button', !!retry && text(retry).indexOf('تلاش دوباره') >= 0, text(retry));

	const before = ctx2.calls.length;
	retry.click();
	await tick();
	await tick();
	check('retry repeats the failed request', ctx2.calls.length > before, before + ' -> ' + ctx2.calls.length);
}

async function testThrottleHasNoFalseHope() {
	scenario('A throttle offers no button that cannot work');

	const ctx = boot({
		fetch: (req) =>
			req.url.indexOf('form-config') >= 0
				? ok({ nonce: 'n' })
				: reject('throttled', 'برای امنیت شما، ارسال کد موقتاً متوقف شده است.', { retry_after: 600 }),
	});

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	check('no action buttons offered', 0 === ctx.doc.querySelectorAll('.signa__status-action').length);
	check('message still reaches the user', text(ctx.doc.querySelector('[data-signa-status-text]')).length > 0);
}

async function testCodeLengthRebuild() {
	scenario('Boxes rebuild when the server reports a different code length');

	const ctx = boot({
		fetch: (req) =>
			req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok(verifyStep({ code_length: 6 })),
	});

	check('starts with five boxes', 5 === ctx.doc.querySelectorAll('[data-signa-box]').length);

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	const boxes = Array.from(ctx.doc.querySelectorAll('[data-signa-box]'));
	check('rebuilt to six boxes', 6 === boxes.length, boxes.length + ' boxes');
	check('bulk input maxlength follows', 6 === ctx.doc.querySelector('[data-signa-code-bulk]').maxLength);
	check('only the first box advertises autofill', 'one-time-code' === boxes[0].getAttribute('autocomplete') && 'off' === boxes[1].getAttribute('autocomplete'));
	check('every rebuilt box is labelled', boxes.every((box) => (box.getAttribute('aria-label') || '').length > 0));

	// The rebuilt boxes must still be wired: typing has to advance the caret.
	boxes[0].value = '1';
	boxes[0].dispatchEvent(new ctx.win.Event('input', { bubbles: true }));
	check('rebuilt boxes are re-bound (focus advances)', ctx.doc.activeElement === boxes[1]);
}

async function testRescuePanel() {
	scenario('"No SMS?" appears only after the wait becomes unreasonable');

	const ctx = boot({
		// Seconds are whole numbers in the real setting, so the test waits one.
		config: { rescueAfter: 1 },
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok(verifyStep())),
	});

	const rescue = ctx.doc.querySelector('[data-signa-rescue]');
	check('hidden before the code step', rescue.hidden);

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	check('still hidden right after the code is sent', rescue.hidden);
	await wait(1100);
	check('revealed once the delay passes', !rescue.hidden);

	// Going back to the phone step must take the panel with it.
	ctx.form.act('edit-phone');
	check('hidden again after editing the phone', rescue.hidden);
}

async function testCooldownAndPersianDigits() {
	scenario('Cooldown counts down in Persian and drives the progress bar');

	const ctx = boot({
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok(verifyStep({ cooldown: 45 }))),
	});

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	const label = text(ctx.doc.querySelector('[data-signa-resend-label]'));
	const bar = ctx.doc.querySelector('[data-signa-cooldown]');

	check('countdown uses Persian digits', /[۰-۹]/.test(label) && !/[0-9]/.test(label), label);
	check('resend is disabled while it runs', ctx.doc.querySelector('[data-signa-action="resend"]').disabled);
	check('progress bar is shown', !bar.hidden);
	check('bar duration matches the cooldown', '45s' === bar.style.getPropertyValue('--signa-cooldown'), bar.style.getPropertyValue('--signa-cooldown'));
	check('bar is decorative for screen readers', 'true' === bar.getAttribute('aria-hidden'));

	// The phone value itself must stay Latin, or the server cannot parse it.
	check('phone field keeps Latin digits', /^[0-9]+$/.test(ctx.doc.querySelector('[data-signa-phone]').value));
}

async function testFocusMovesToTheProblem() {
	scenario('Focus lands on the thing that needs fixing');

	const ctx = boot({
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok({ step: 'register_form', fields: [] })),
	});

	// Client-side validation: empty required fields.
	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	ctx.form.act('submit-fields');
	await tick();

	const active = ctx.doc.activeElement;
	check('focus is on an invalid field', 'true' === (active.getAttribute && active.getAttribute('aria-invalid')), active.tagName + '/' + (active.getAttribute ? active.getAttribute('data-signa-input') : ''));
	check('the invalid field names its error', !!(active.getAttribute('aria-describedby') || '').length);
}

async function testSkipLink() {
	scenario('Skip link focuses the current step, not a hidden field');

	const ctx = boot({
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok(verifyStep())),
	});

	const skip = ctx.doc.querySelector('[data-signa-skip]');
	check('skip link is the first focusable element in the form', !!skip);

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	skip.dispatchEvent(new ctx.win.MouseEvent('click', { bubbles: true, cancelable: true }));

	const active = ctx.doc.activeElement;
	const codeStep = ctx.doc.querySelector('[data-signa-step="code"]');
	check('focus stays inside the visible step', codeStep.contains(active), active.tagName);
}

async function testExpiry() {
	scenario('An expired code says so and offers a fresh one');

	const ctx = boot({
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok(verifyStep({ expires_in: 1 }))),
	});

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	await wait(1200);

	check('form is marked expired', ctx.root.classList.contains('is-expired'));
	check('a message explains why', text(ctx.doc.querySelector('[data-signa-status-text]')).indexOf('منقضی') >= 0, text(ctx.doc.querySelector('[data-signa-status-text]')));
	const labels = Array.from(ctx.doc.querySelectorAll('.signa__status-action')).map(text);
	check('a way to get a new code is offered', labels.some((l) => l.indexOf('کد تازه') >= 0), labels.join(' | '));
}

async function testStaleNonceStillRecovers() {
	scenario('The 1.0.1 cache/nonce recovery still works');

	let served = 0;
	const ctx = boot({
		fetch: (req) => {
			if (req.url.indexOf('form-config') >= 0) {
				served++;
				return ok({ nonce: 'fresh-nonce-' + served });
			}
			if (1 === served) {
				return Promise.resolve({
					ok: false,
					status: 403,
					json: () => Promise.resolve({ code: 'rest_cookie_invalid_nonce', message: 'nonce', data: { status: 403 } }),
				});
			}
			return ok(verifyStep());
		},
	});

	await tick();
	check('a fresh nonce is fetched on mount', served >= 1);

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	for (let i = 0; i < 8; i++) await tick();

	check('the rejected call is retried after refreshing', served >= 2, 'config fetched ' + served + 'x');
	check('the visitor ends up on the code step', ctx.doc.querySelector('[data-signa-step="code"]').classList.contains('is-current'));
	check('no error is left on screen', ctx.doc.querySelector('[data-signa-status]').className.indexOf('is-error') < 0);
}

async function testCaptchaFailureIsVisible() {
	scenario('A captcha script that never loads says so, and is not a dead end');

	const bundle = {
		enabled: true,
		provider: 'hcaptcha',
		siteKey: '10000000-ffff-ffff-ffff-000000000001',
		kind: 'widget',
		scripts: ['/blocked/captcha.js'],
		failOpen: true,
		loadTimeout: 300,
		config: {},
	};

	const ctx = boot({
		config: { captcha: bundle },
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'fresh' }) : ok(verifyStep())),
	});

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');

	await wait(500);

	const box = ctx.doc.querySelector('.signa-captcha__error');
	check('an error card fills the gap the widget left', !!box);
	check('it says the challenge did not load', !!box && text(box).indexOf('بارگذاری نشد') >= 0, box ? text(box) : 'no card');
	check('it offers a retry', !!ctx.doc.querySelector('[data-signa-captcha-retry]'));
	check('it says the visitor may continue', !!ctx.doc.querySelector('.signa-captcha__error-hint'));

	for (let i = 0; i < 8; i++) await tick();

	check(
		'fail-open still sends the code',
		ctx.doc.querySelector('[data-signa-step="code"]').classList.contains('is-current'),
		'status: ' + text(ctx.doc.querySelector('[data-signa-status-text]'))
	);
	check(
		'the request went out without a captcha token',
		ctx.calls.some((call) => call.url.indexOf('/start') >= 0 && !call.body.captcha_token),
		ctx.calls.map((call) => call.url).join(' | ')
	);

	// With fail-open off, the visitor is stopped — and told why, in words.
	const strict = boot({
		config: { captcha: Object.assign({}, bundle, { failOpen: false }) },
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'fresh' }) : ok(verifyStep())),
	});

	strict.doc.querySelector('[data-signa-phone]').value = '09121234567';
	strict.form.act('start');
	await wait(500);
	for (let i = 0; i < 8; i++) await tick();

	check('with fail-open off nothing is sent', !strict.calls.some((call) => call.url.indexOf('/start') >= 0));
	check(
		'and the visitor gets an explanation',
		text(strict.doc.querySelector('[data-signa-status-text]')).indexOf('بارگذاری نشد') >= 0,
		text(strict.doc.querySelector('[data-signa-status-text]'))
	);
}

async function testStaleFormTokenRecovers() {
	scenario('A form token frozen by a day-long page cache recovers in one retry');

	let configs = 0;
	const tokens = [];

	const ctx = boot({
		html: (source) => source.replace('data-form-token="demo-form-token"', 'data-form-token="stale-form-token"'),
		fetch: (req) => {
			if (req.url.indexOf('form-config') >= 0) {
				configs++;

				return ok({ nonce: 'fresh-nonce-' + configs, formToken: 'fresh-token-' + configs, renderedAt: 1800000000 });
			}

			if (req.url.indexOf('/start') >= 0) {
				tokens.push(req.body.signa_ft);

				if ('stale-form-token' === req.body.signa_ft) {
					return reject('stale_form', 'این فرم مدت‌ها پیش ساخته شده است.', { recoverable: true });
				}

				return ok(verifyStep());
			}

			return ok({});
		},
	});

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');

	for (let i = 0; i < 10; i++) await tick();

	check('the first attempt carries the cached token', 'stale-form-token' === tokens[0], String(tokens[0]));
	check('the client pulls a fresh configuration', configs >= 1, configs + ' fetch(es)');
	check('the retry carries the fresh token', !!tokens[1] && 'stale-form-token' !== tokens[1], String(tokens[1]));
	check('the hidden timestamp moves with it', '1800000000' === ctx.doc.querySelector('input[name="signa_ts"]').value, ctx.doc.querySelector('input[name="signa_ts"]').value);
	check('the visitor ends up on the code step', ctx.doc.querySelector('[data-signa-step="code"]').classList.contains('is-current'));
	check('no error is left on screen', ctx.doc.querySelector('[data-signa-status]').className.indexOf('is-error') < 0);

	const chip = ctx.doc.querySelector('[data-signa-phone-chip]');
	check('the code step names the number the code went to', !!chip && !chip.hidden, 'hidden=' + (chip ? chip.hidden : 'missing'));
	check('with the masked number in it', !!chip && text(ctx.doc.querySelector('[data-signa-phone-chip-value]')).indexOf('***') >= 0, chip ? text(chip) : '');
}

async function testPasteFromSms() {
	scenario('Pasting the code from the SMS fills the boxes');

	const ctx = boot({
		clipboard: { readText: () => Promise.resolve('کد شما: ۱۲۳۴۵') },
		fetch: (req) => {
			if (req.url.indexOf('form-config') >= 0) return ok({ nonce: 'fresh' });
			if (req.url.indexOf('/verify') >= 0) return reject('invalid_code', 'کد درست نیست.', { attempts_left: 4 });

			return ok(verifyStep());
		},
	});

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	const paste = ctx.doc.querySelector('[data-signa-paste]');
	check('the code step offers a paste button', !!paste && !paste.hidden);

	paste.click();
	await tick();
	await tick();

	const boxes = Array.from(ctx.doc.querySelectorAll('[data-signa-box]')).map((box) => box.value);
	check('Persian digits from the SMS are written as Latin digits', '12345' === boxes.join(''), boxes.join(''));

	await wait(260);
	check('and the code is verified without pressing anything', ctx.calls.some((call) => call.url.indexOf('/verify') >= 0), ctx.calls.map((call) => call.url).join(' | '));
}

async function testPasteWithoutAClipboard() {
	scenario('With no readable clipboard the button is not offered');

	const ctx = boot({
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok(verifyStep())),
	});

	const paste = ctx.doc.querySelector('[data-signa-paste]');
	check('the clipboard button is hidden when readText() does not exist', !!paste && paste.hidden, paste ? 'hidden=' + paste.hidden : 'missing');

	// The box still accepts a normal paste, which is the path the help text sends people to.
	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	for (let i = 0; i < 4; i++) await tick();

	const event = new ctx.win.Event('paste', { bubbles: true, cancelable: true });
	event.clipboardData = { getData: () => '۱۲۳۴۵' };
	ctx.doc.querySelector('[data-signa-box]').dispatchEvent(event);
	await tick();

	const boxes = Array.from(ctx.doc.querySelectorAll('[data-signa-box]')).map((box) => box.value);
	check('pasting into the first box still fills them all', '12345' === boxes.join(''), boxes.join(''));
}

async function testPhoneFieldHasOneTruth() {
	scenario('The phone field asks for exactly what it accepts');

	const ctx = boot({
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok(verifyStep())),
	});

	const phone = ctx.doc.querySelector('[data-signa-phone]');
	const dial = ctx.doc.querySelector('.signa-phone__dial');
	const css = fs.readFileSync(path.join(REPO, 'signa', 'assets', 'css', 'front.css'), 'utf8');

	/*
	 * A fixed "09" chip while the error says "start with 09" is a field arguing
	 * with itself. The chip is gone; the placeholder shows the whole number.
	 */
	check('no fixed prefix is printed inside the field', !dial, 'the chip is back');
	check('and no rule paints one either', css.indexOf('.signa-phone__dial') < 0);
	check('the placeholder shows the whole number', '09121234567' === phone.placeholder, phone.placeholder);
	// What the placeholder demonstrates and what the error asks for must be the
	// same rule: eleven digits, beginning with 09.
	const copy = fs.readFileSync(path.join(REPO, 'signa', 'src', 'Front', 'Assets.php'), 'utf8');
	check('the error still states the 09 / 11-digit rule', /\u06f0\u06f9/.test(copy) && copy.indexOf('\u06f1\u06f1 \u0631\u0642\u0645') >= 0);
	check('the example in the field obeys that same rule', /^09\d{9}$/.test(phone.placeholder));
	check('a plain eleven-digit number needs no fixing', phone.value === '' || /^09\d{9}$/.test(phone.value));

	// Typing the whole number, chip or no chip, must send the whole number.
	phone.value = '09123456789';
	ctx.form.act('start');
	for (let i = 0; i < 6; i++) await tick();

	const first = ctx.calls.filter((call) => call.url.indexOf('/start') >= 0).pop();
	check('eleven digits go out unchanged', !!first && '09123456789' === first.body.phone, first ? String(first.body.phone) : 'no call');

	// The shorthand people still type out of habit keeps working.
	phone.value = '9123456789';
	ctx.form.act('start');
	for (let i = 0; i < 6; i++) await tick();

	const start = ctx.calls.filter((call) => call.url.indexOf('/start') >= 0).pop();
	check('ten digits are still completed to a full number', !!start && '09123456789' === start.body.phone, start ? String(start.body.phone) : 'no call');

	// The other spellings users paste have to fold too.
	const cases = [
		['+98 912 123 4567', '09121234567'],
		['00989121234567', '09121234567'],
		['۰۹۱۲۱۲۳۴۵۶۷', '09121234567'],
		['09121234567', '09121234567'],
	];

	for (const [typed, expected] of cases) {
		const one = boot({
			fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok(verifyStep())),
		});

		one.doc.querySelector('[data-signa-phone]').value = typed;
		one.form.act('start');
		for (let i = 0; i < 6; i++) await tick();

		const call = one.calls.filter((c) => c.url.indexOf('/start') >= 0).pop();
		check('«' + typed + '» is sent as ' + expected, !!call && expected === call.body.phone, call ? String(call.body.phone) : 'no call');
	}
}

async function testTheLookOfTheTwoReportedBugs() {
	scenario('The progress bar and the skip link stay fixed');

	const css = fs.readFileSync(path.join(REPO, 'signa', 'assets', 'css', 'front.css'), 'utf8');
	const skip = css.slice(css.indexOf('.signa__skip {'), css.indexOf('.signa__skip:focus'));

	check('the skip link is invisible until it is focused', /opacity:\s*0/.test(skip) && /pointer-events:\s*none/.test(skip), skip.split('\n')[1] || '');
	check('and it is still the first tab stop', /^\.signa__skip:focus/m.test(css) || /\.signa__skip:focus,/.test(css));

	check('no connector line is drawn across the progress bar', !/signa__steps li \+ li::after/.test(css));
	check('progress is three filled segments instead', /\.signa__steps li::before \{/.test(css));
	check('the phone field has one border, from the shared input rule', /\.signa-field__input,\n\.signa-field__select,\n\.signa-phone__input/.test(css));
	const page = new JSDOM(pageSource, { runScripts: 'outside-only' });
	const shown = page.window.document.querySelector('.signa').textContent;

	check('nothing on screen prints a fake «09xxxxxxxxx» hint', !/09x{3,}/i.test(shown), (shown.match(/09x{3,}/i) || [''])[0]);

	const trust = page.window.document.querySelector('[data-signa-step="phone"] .signa__trust');
	check('the reassurance row sits under the send button', !!trust, 'not inside step 1');
}

function testPreviewMirrorsTheTemplate() {
	scenario('The demo page still mirrors the template it claims to show');

	const template = fs.readFileSync(path.join(REPO, 'signa', 'templates', 'partials', 'step-phone.php'), 'utf8');
	const renderer = fs.readFileSync(path.join(REPO, 'signa', 'src', 'Front', 'FormRenderer.php'), 'utf8');
	const step = new JSDOM(pageSource, { runScripts: 'outside-only' }).window.document.querySelector('[data-signa-step="phone"]');

	for (const name of ['signa-phone', 'signa-phone__input', 'signa__trust']) {
		check('the template renders .' + name, template.indexOf(name) >= 0);
		check('the demo shows .' + name, !!step.querySelector('.' + name));
	}

	check('the template prints no prefix chip at all', template.indexOf('signa-phone__dial') < 0);
	check('the demo prints none either', !step.querySelector('.signa-phone__dial'));
	check('the placeholder comes from the renderer', /\$phonePlaceholder/.test(template) && /'09121234567'/.test(renderer));
	check('the demo placeholder matches the renderer', step.querySelector('[data-signa-phone]').placeholder === '09121234567');

	const trustTemplate = template.slice(template.indexOf('signa__trust'));
	check('the demo trust items are the ones the PHP filter ships', trustTemplate.indexOf("__(") < 0 && step.querySelectorAll('.signa__trust-item').length >= 3);
}

function testTheAccentIsTheOnlyColour() {
	scenario('The button belongs to the accent, and so does its hover');

	const css = fs.readFileSync(path.join(REPO, 'signa', 'assets', 'css', 'front.css'), 'utf8');
	const script = fs.readFileSync(path.join(REPO, 'signa', 'assets', 'js', 'front.js'), 'utf8');
	const php = fs.readFileSync(path.join(REPO, 'signa', 'src', 'Front', 'Assets.php'), 'utf8');

	const primary = css.slice(css.indexOf('.signa-btn--primary {'), css.indexOf('.signa-btn--ghost {'));
	const resting = primary.slice(0, primary.indexOf('.signa-btn--primary:hover'));

	check('the button is painted with the accent', /background-color:\s*var\(--signa-accent\)/.test(resting));
	check('its depth is a translucent sheen, not a second colour', /linear-gradient\(180deg, rgba\(255, 255, 255/.test(resting));
	check('no fixed teal is left in the resting rule', resting.indexOf('11, 92, 86') < 0, resting.split('\n').find((line) => line.indexOf('11, 92, 86') >= 0) || '');
	check('hover darkens the very same accent', /\.signa-btn--primary:hover[\s\S]{0,220}rgba\(0, 0, 0/.test(primary));
	check('the button shadow follows the accent', /--signa-elev-button:\s*0 12px 26px -16px var\(--signa-accent-strong\)/.test(css));
	check('PHP derives the darker shade from the accent', /mix\( \$accent, '#000000', 0\.22 \)/.test(php));
	check(
		'the accent wash is translucent, so the dark skin keeps it',
		/'signa-accent-soft'\s*=>\s*\$this->rgba\( \$accent, 0\.14 \)/.test(php) && /sprintf\( 'rgba\(%d, %d, %d, %s\)'/.test(php)
	);
	check('the demo derives the same shade when a swatch is clicked', /shade\(accent, 0\.22\)/.test(pageSource));

	// Two complaints from the same screenshot batch.
	check('the code boxes are centred', /\.signa-code__boxes \{[\s\S]{0,240}justify-content: center/.test(css));
	check('the phone control is one left-to-right island', /\.signa-phone \{[\s\S]{0,600}direction: ltr/.test(css));
	check('so the placeholder starts where the chip ends', /\.signa-phone__input,\n\.signa-phone__input:focus \{[\s\S]{0,320}text-align: left/.test(css));

	const actions = script.slice(script.indexOf('Form.prototype.actionsFor'), script.indexOf('Form.prototype.clearStatus'));
	check('errors no longer offer a second way to edit the number', actions.indexOf("'edit-phone'") < 0, (actions.match(/'edit-phone'/) || [''])[0]);

	const template = fs.readFileSync(path.join(REPO, 'signa', 'templates', 'partials', 'step-code.php'), 'utf8');
	const rescue = new JSDOM(pageSource, { runScripts: 'outside-only' }).window.document.querySelector('[data-signa-rescue]');

	/*
	 * Callout rules this panel is held to (see docs/UI-PLAN.fa.md §4.10):
	 * one message, no duplicate actions, nothing centred, no second primary
	 * button competing with the one that submits the code.
	 */
	check('the rescue panel is a callout with an icon and a title', !!rescue.querySelector('.signa-code__rescue-icon svg') && !!rescue.querySelector('.signa-code__rescue-title'));
	check('it carries no button at all', !rescue.querySelector('button'));
	check('and therefore no second primary button', !rescue.querySelector('.signa-btn'));
	check('it explains in at most two short lines', rescue.querySelectorAll('.signa-code__rescue-list li').length === 2);
	check('each line points at a control that already exists', /ارسال دوبارهٔ کد/.test(rescue.textContent) && /ویرایش شماره/.test(rescue.textContent));
	check('the text is never centred', /\.signa-code__rescue \{[\s\S]{0,600}text-align: start/.test(css));
	check('and no rule centres the panel contents', !/\.signa-code__rescue[\s-][^{]*\{[^}]*text-align: center/.test(css));
	check('the template prints the same panel', /signa-code__rescue-icon/.test(template) && /signa-code__rescue-list/.test(template) && template.indexOf('signa-code__rescue-note') < 0);
}

function testTheThreeDemoPages() {
	scenario('The demo pages keep up with the plugin');

	const account = fs.readFileSync(path.join(REPO, 'preview', 'public', 'account.html'), 'utf8');
	const admin = ADMIN_DEMO;
	const adminCss = fs.readFileSync(path.join(REPO, 'signa', 'assets', 'css', 'admin.css'), 'utf8');
	const server = fs.readFileSync(path.join(REPO, 'preview', 'server.js'), 'utf8');
	const demo = new JSDOM(pageSource, { runScripts: 'outside-only' }).window.document;

	// The account page mounts the real form with the real stylesheet and script.
	check('the account demo is routed', /'\/account'/.test(server));
	check('it loads the plugin stylesheet', /\/plugin-assets\/css\/front\.css/.test(account));
	check('and the plugin script, so the form is live there too', /\/plugin-assets\/js\/front\.js/.test(account));
	check('with a form for changing the number', /data-signa-form/.test(account) && /data-signa-action="start"/.test(account));
	check('the saved address and postcode are shown as profile data', /کد پستی/.test(account) && /آدرس/.test(account));

	// The signup step mirrors the identity preset the PHP now ships.
	const fields = demo.querySelector('[data-signa-step="fields"]');

	for (const id of ['postcode', 'address']) {
		const field = fields.querySelector('[data-signa-field="' + id + '"]');
		check('the demo signup asks for ' + id, !!field, 'missing');
	}

	const postcode = fields.querySelector('[data-signa-input="postcode"]');
	check('the postal code box is numeric and LTR like the template', postcode && 'numeric' === postcode.getAttribute('inputmode') && 'ltr' === postcode.getAttribute('dir') && '10' === postcode.getAttribute('maxlength'));
	check('and it has the same placeholder as the preset', '1234567890' === postcode.placeholder, postcode.placeholder);
	check('the address is a textarea with its hint', 'textarea' === fields.querySelector('[data-signa-input="address"]').tagName.toLowerCase() && /\.$/.test(text(fields.querySelector('.signa-field__hint'))));

	const template = fs.readFileSync(path.join(REPO, 'signa', 'templates', 'partials', 'step-fields.php'), 'utf8');
	check('the template handles the postcode type the way the demo shows', /'postcode' === \$field\['type'\]/.test(template) && /postal-code/.test(template));

	// The admin demo shows the report screen the plugin renders.
	for (const name of ['signa-kpi', 'signa-chart__col', 'signa-table', 'signa-range__item']) {
		check('the admin demo has .' + name, admin.indexOf(name) >= 0);
		check('and the real admin stylesheet defines .' + name, adminCss.indexOf('.' + name) >= 0);
	}

	check('the report screen is a real admin page', /class ReportScreen/.test(fs.readFileSync(path.join(REPO, 'signa', 'src', 'Admin', 'ReportScreen.php'), 'utf8')));
	check('the captcha test is wired in the demo, with the keys the plugin localizes', /data-signa-captcha-test/.test(admin) && /"captcha":\{/.test(admin) && /'global'/.test(fs.readFileSync(path.join(REPO, 'signa', 'src', 'Front', 'Assets.php'), 'utf8')));
	check('and admin.js implements it', /testCaptcha/.test(fs.readFileSync(path.join(REPO, 'signa', 'assets', 'js', 'admin.js'), 'utf8')));
}

/*
 * The admin plugin registers five screens, but only the settings screen has its
 * own tab row — so reports, events, tools and access used to be reachable only
 * from the WordPress sidebar. These checks hold the switcher (and the version
 * stamp people use to tell which build is installed) in place.
 */
function testEveryScreenIsReachable() {
	scenario('Every screen is reachable from every screen');

	const read = (...parts) => fs.readFileSync(path.join(REPO, ...parts), 'utf8');
	const nav = read('signa', 'src', 'Admin', 'ScreenNav.php');
	const settings = read('signa', 'src', 'Admin', 'SettingsScreen.php');
	const menu = read('signa', 'src', 'Admin', 'Menu.php');
	const admin = ADMIN_DEMO;
	const adminCss = read('signa', 'assets', 'css', 'admin.css');

	check('the switcher knows all five screens', ['Menu::ROOT', 'ReportScreen::SLUG', 'LogsScreen::SLUG', 'ToolsScreen::SLUG', 'AccessScreen::SLUG'].every((slug) => nav.indexOf(slug) >= 0));

	// Since 2.0 every screen opens with the same frame, and the frame draws the nav.
	const layout = read('signa', 'src', 'Admin', 'Layout.php');
	check('the frame prints the side navigation', /ScreenNav::render\( \$current \)/.test(layout));

	for (const [file, marker] of [
		['SettingsScreen', 'Layout::open( $tab,'],
		['ReportScreen', "Layout::open( 'reports',"],
		['LogsScreen', 'Layout::open( self::SLUG,'],
		['ToolsScreen', 'Layout::open( self::SLUG,'],
		['AccessScreen', 'Layout::open( self::SLUG,'],
	]) {
		check(file + ' opens the shared frame', read('signa', 'src', 'Admin', file + '.php').indexOf(marker) >= 0);
	}

	check('the menu and the switcher share one slug per screen', /LogsScreen::SLUG/.test(menu) && /ToolsScreen::SLUG/.test(menu) && menu.indexOf("self::ROOT . '-logs'") < 0 && menu.indexOf("self::ROOT . '-tools'") < 0);
	check('the tools screen owns its slug like the others', /const SLUG = 'signa-tools'/.test(read('signa', 'src', 'Admin', 'ToolsScreen.php')));

	// The dashboard counts the last day (as the design does) and names the page that explains it.
	const dashboard = read('signa', 'src', 'Admin', 'Dashboard.php');
	check('the dashboard shows a 24-hour overview', /signa-kpis/.test(dashboard) && /const DAYS = 1;/.test(dashboard) && /۲۴ ساعت/.test(dashboard));
	check('and a way into the reports section', /tabUrl\( 'reports' \)/.test(dashboard) || /ScreenNav::url\( 'reports' \)/.test(dashboard));
	check('it says so instead of showing zeros when logging is off', /logs_enabled/.test(dashboard));

	check('the css defines the navigation', /\.signa-screens__list \{/.test(adminCss) && /\.signa-screen\.is-current/.test(adminCss));
	check('and the numbers strip', /\.signa-kpis \{/.test(adminCss));
	check('the demo mirrors both', admin.indexOf('signa-screens') >= 0 && admin.indexOf('signa-kpis') >= 0);

	// "Which build is on my site?" must be answerable from the dashboard header.
	const plugin = read('signa', 'signa.php');
	const readme = read('signa', 'readme.txt');
	const version = (plugin.match(/Version:\s*([0-9.]+)/) || [])[1];

	check('the header version and the constant agree', !!version && plugin.indexOf("SIGNA_VERSION', '" + version + "'") >= 0, version);
	check('the readme advertises the same version', !!version && readme.indexOf('Stable tag: ' + version) >= 0);
	check('the demo shows the same version', !!version && admin.indexOf('نسخه ' + version) >= 0);
	check('and the changelog has an entry for it', !!version && readme.indexOf('= ' + version + ' =') >= 0);
}

/*
 * "Where is the reports section?" had a real answer missing: the panel itself.
 * Reports now live in the settings tab row, and every section can test itself
 * from where the administrator already is — in a modal, without a second URL.
 */
function testThePanelKeepsItsOwnPromises() {
	scenario('Reports and stats live in the panel, and every section can test itself');

	const read = (...parts) => fs.readFileSync(path.join(REPO, ...parts), 'utf8');
	const settings = read('signa', 'src', 'Admin', 'SettingsScreen.php');
	const report = read('signa', 'src', 'Admin', 'ReportScreen.php');
	const selfTest = read('signa', 'src', 'Diagnostics', 'SelfTest.php');
	const adminJs = read('signa', 'assets', 'js', 'admin.js');
	const adminCss = read('signa', 'assets', 'css', 'admin.css');
	const assets = read('signa', 'src', 'Front', 'Assets.php');
	const api = read('signa', 'src', 'Http', 'Api.php');
	const controller = read('signa', 'src', 'Http', 'AdminController.php');
	const nav = read('signa', 'src', 'Admin', 'ScreenNav.php');
	const admin = ADMIN_DEMO;
	const server = read('preview', 'server.js');
	const appMode = read('signa', 'src', 'Admin', 'AppMode.php');
	const pluginSource = read('signa', 'src', 'Plugin.php');
	const bootstrap = read('signa', 'signa.php');
	const readme = read('signa', 'readme.txt');

	// --- reports as a tab of the settings panel ------------------------------
	check('the settings panel has a reports section', /'reports'\s+=> array\( __\(/.test(nav));
	check('and it draws the reports screen body rather than a copy of it', /\$this->reports->body\( \$this->reports->range\(\) \)/.test(settings));
	check('the reports screen exposes that body', /public function body\( int \$days \): void/.test(report));
	check('both callers use it: the screen and the section', (report.match(/\$this->body\(/g) || []).length === 1 && (settings.match(/->body\(/g) || []).length === 1);

	// The section is not a form: no save button, no settings fields.
	const tabBranch = (settings.match(/if \( 'reports' === \$tab \) \{[\s\S]*?\n\t\t\}/) || [''])[0];
	check('the section skips the settings form entirely', /return;/.test(tabBranch) && tabBranch.indexOf('settings_fields') < 0);

	// One address per section inside the plugin.
	check('section urls are built in one place', /public static function tabUrl\(/.test(settings) && /SettingsScreen::tabUrl\( \$id \)/.test(nav));
	check('the navigation points at the section, not at a second page', /SettingsScreen::tabUrl\( 'reports' \)/.test(nav));
	check('reports sits after the integrations section', nav.indexOf("'integ'") < nav.indexOf("'reports'"));

	// --- "did my update actually land?" --------------------------------------
	// Twice now the answer to "where is it?" was "the installed package is older
	// than the one you were told about". The number is printed in the header of
	// every tab, so it is on screen before a single setting is read — and it is
	// the only place a release is described: a card of release notes in front of
	// the settings was prose the owner did not ask for.
	const layoutFile = read('signa', 'src', 'Admin', 'Layout.php');
	check('the running version is printed in the header of every screen', /signa-header__meta/.test(layoutFile) && /نسخه %s/.test(layoutFile) && /SIGNA_VERSION/.test(layoutFile));
	check('and no card of release notes stands in front of the settings', settings.indexOf('whatsNew') < 0 && settings.indexOf('تازه در نسخهٔ') < 0 && settings.indexOf('signa-bullets') < 0);

	// --- the text diet -------------------------------------------------------
	// The request was literal: "توضیحات اضافه رو از پلاگین حذف کن". A sentence an
	// administrator has to read before touching a control is prose unless it
	// says what a field accepts or what happens if it is set that way.
	const sentences = (source) => (source.match(/__\(\s*'[^']{120,}'/g) || []);
	const longOnes = sentences(settings).concat(sentences(selfTest));
	check('no sentence in the admin screens runs past 120 characters', longOnes.length === 0, longOnes.slice(0, 2).join(' | '));
	check('the release-notes card is gone from the panel', settings.indexOf('private function whatsNew') < 0);
	check('and every settings section starts with settings', /private function loginSection\(\): void \{\s*\n\s*\$c = \$this->controls;\s*\n\s*\$this->card\(/.test(settings));

	const version = (bootstrap.match(/define\( 'SIGNA_VERSION', '([0-9.]+)' \)/) || [])[1];
	// Persian digits, for the places a release is named to a person.
	const faVersion = !!version && version.replace(/[0-9]/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[digit]);
	check('the package declares one version, and the file header agrees', !!version && bootstrap.indexOf('Version:           ' + version) >= 0);
	check('the readme ships that same version as its stable tag', !!version && readme.indexOf('Stable tag: ' + version) >= 0);
	check('and the readme explains what changed in it', !!version && readme.indexOf('= ' + version + ' =') >= 0);
	check('the preview says which version it is showing', admin.indexOf(version) >= 0);
	check('and the preview shows no release-notes card either', admin.indexOf('تازه در نسخهٔ ' + faVersion) < 0 && admin.indexOf('signa-bullets') < 0);
	check('but it still leads into the reports section', admin.indexOf('href="/admin/reports"') >= 0);

	// --- a broken install must not take the site down -----------------------
	// 1.3.2 shipped a constructor that had grown a tenth argument while the
	// binding still passed nine. Every gate was green and the site died on every
	// request. Three things now stand in the way of that happening again.
	const guard = read('signa', 'src', 'Install', 'Guard.php');
	const packageFile = read('signa', 'src', 'Install', 'Package.php');
	const transport = read('signa', 'src', 'Support', 'Transport.php');
	const failover = read('signa', 'src', 'Gateway', 'FailoverChain.php');
	const httpGateway = read('signa', 'src', 'Gateway', 'HttpGateway.php');
	const dispatcherFile = read('signa', 'src', 'Channel', 'Dispatcher.php');
	const manifest = JSON.parse(read('signa', 'build.json'));
	const builder = read('tools', 'build_package.py');
	const wiring = read('tests', 'php', 'wiring-test.php');

	check('every service is built through the guard, so one failure is not a fatal', /Install\\\\Guard::record\( \$id, \$error \)/.test(pluginSource) || /Guard::record\( \$id/.test(pluginSource));
	check('the wiring itself is a test, not a hope', /AdminController is given every argument it requires/.test(wiring) || /getNumberOfRequiredParameters/.test(wiring));
	check('and the test reads the bindings out of the plugin, not a copy', wiring.indexOf('src/Plugin.php') >= 0 && wiring.indexOf('::class') >= 0);
	check('the package describes itself', manifest.version === version && Object.keys(manifest.files).length > 100);
	check('build.json carries a hash for every file', Object.values(manifest.files).every((sha) => /^[0-9a-f]{64}$/.test(sha)));
	check('one tool builds it and can verify it', /def check\(/.test(builder) && /--check/.test(builder) && /def manifest\(/.test(builder));
	check('the plugin verifies that manifest at runtime', /hash_equals\( \(string\) \$sha, \$actual \)/.test(packageFile) && /hash_file\( 'sha256'/.test(packageFile));
	check('a mismatch names the files rather than the version number', /public static function offenders\(/.test(packageFile) && /files_differ/.test(packageFile));
	check('the guard reports failures and mismatches to the administrator', /printFailures\(\)/.test(guard) && /printMismatch\(/.test(guard) && /نسخهٔ بارگذاری‌شده/.test(guard));
	check('and stays quiet when nothing is wrong', /if \( ! empty\( self::\$failures \) \)/.test(guard) && /if \( empty\( \$state\['ok'\] \) \)/.test(guard));
	check('the general self-test shows package health too', /یکپارچگی بستهٔ نصب‌شده/.test(selfTest));
	check('the demo shows that row', /یکپارچگی بستهٔ نصب‌شده/.test(server));

	// --- an error has to name a cause and a person --------------------------
	// The owner sent their events screen: every failure read `transport`. That
	// word is true and useless — it hides a DNS failure, a blocked port and a
	// stale CA bundle behind the same three syllables.
	const transportTest = read('tests', 'php', 'transport-test.php');

	check('the transport classifier exists and names its cases', /const DNS\s+= 'dns'/.test(transport) && /const CONNECT/.test(transport) && /const TLS/.test(transport) && /const BLOCKED/.test(transport));
	check('a dropped port is a connect failure, not a slow panel', transport.indexOf("self::CONNECT") < transport.indexOf("return self::TIMEOUT;"));
	check('the server sentence is kept, not summarised away', /strtoupper\( \$kind \) \. ': ' \. \$detail/.test(transport));
	check('and a credential in it is masked', /api_key\|apikey\|access_token/.test(transport));
	check('each cause has a Persian sentence that says what to do', /WP_ACCESSIBLE_HOSTS/.test(transport) && /هاست/.test(transport) && /DNS/.test(transport));

	check('the gateway keeps the reason instead of the word transport', /Transport::fromError\( \$response \)/.test(httpGateway));
	check('the failing log rows carry it', /'reason'\s+=> isset\( \$meta\['reason'\] \)/.test(failover) && /'reason'\s+=> isset\( \$meta\['reason'\] \)/.test(dispatcherFile));
	check('and carry a message, because that is the column the admin reads', /'message'\s+=> \$result->message\(\)/.test(failover) && /'message'\s+=> \$result->message\(\)/.test(dispatcherFile));
	check('the health card gets the reason too', /\$detail = trim\(/.test(failover) && /health->failure\( \$result->gateway\(\), \$result->errorCode\(\), \$detail/.test(failover));

	check('the gateways self-test asks the server to reach the gateway', /private function reachability\( array \$chain \)/.test(selfTest) && /wp_remote_get\(/.test(selfTest));
	check('it never sends a message and never carries a key', /'limit_response_size' => 1024/.test(selfTest) && selfTest.indexOf('Authorization', selfTest.indexOf('private function reachability')) < 0);
	check('it reports the millisecond and the status', /میلی‌ثانیه · پاسخ HTTP/.test(selfTest));
	check('and it respects the site that blocked outbound HTTP', /Transport::blockFailure\( \$host \)/.test(selfTest) && /WP_HTTP_BLOCK_EXTERNAL/.test(transport));
	check('the self-test says which channel the site sends with', /کانال ارسال کد/.test(selfTest));
	check('a gateway whose last send failed is not called ready', /\$status = 'fail';/.test(selfTest) && /\$health && empty\( \$health\['ok'\] \)/.test(selfTest));
	check('the captcha rejects are counted, and split by who sent them', /private function captchaRejects\( int \$days \)/.test(selfTest) && /function looksLikeBrowser/.test(selfTest) && /'captcha_missing' === \$code/.test(selfTest));
	check('so the row can no longer blame the visitors’ browsers', /private function captchaRejectRow/.test(selfTest) && selfTest.indexOf('ویجت در مرورگر کاربران بارگذاری نشده') < 0);

	check('the browser test has a suite of its own, in CI', /php tests\/php\/transport-test\.php/.test(read('.github', 'workflows', 'ci.yml')));
	check('and that suite covers the sentences hosts really produce', transportTest.indexOf('Could not resolve host') >= 0 && transportTest.indexOf('Connection refused') >= 0 && transportTest.indexOf('SSL certificate problem') >= 0);

	check('the test modal shows the reason next to the advice', /step\.reason/.test(adminJs) && /data\.reason/.test(adminJs));
	check('the demo has a gateway failure that explains itself', /reason: 'CONNECT: cURL error 7/.test(server) && /دسترسی این سرور به سامانه/.test(server) && /ردشدن به‌خاطر کپچا/.test(server));

	// --- app mode: the panel can take the whole window ----------------------
	// The panel sits inside somebody else's page. This is the opt-in that hides
	// the frame, and the rules are: only the person who asked for it, always one
	// click from leaving it, and never hide feedback while hiding furniture.
	check('app mode is a user preference, not a site setting', /const META\s+= 'signa_app_mode'/.test(appMode) && /get_user_meta\( \$user_id, self::META/.test(appMode));
	check('the body is marked before it is painted', /add_filter\( 'admin_body_class'/.test(appMode) && /' signa-app'/.test(appMode));
	check('the switch is a form post with a nonce and a capability check', /admin_post_/.test(appMode) && /check_admin_referer\( self::ACTION \)/.test(appMode) && /current_user_can\( Menu::CAPABILITY \)/.test(appMode));
	check('and it lands back on the page it was pressed from', /wp_safe_redirect\( \$back/.test(appMode));
	check('the switch rides in the shared header, so all five screens have it', /public static function appToggle\(\)/.test(nav) && /ScreenNav::appToggle\(\);/.test(read('signa', 'src', 'Admin', 'Layout.php')));
	check('the button names where it goes, not what it is', /'نمای پیشخوان'/.test(nav) && /'حالت اپ'/.test(nav));
	check('the stylesheet takes the chrome away', /body\.signa-app #adminmenumain/.test(adminCss) && /body\.signa-app #wpadminbar/.test(adminCss) && /body\.signa-app #wpfooter/.test(adminCss));
	check('and keeps the notices, because a save message is feedback', adminCss.indexOf('body.signa-app .notice') < 0);
	check('the toolbar room is given back on html as well', /html:has\(body\.signa-app\)/.test(adminCss) && /initAppMode\(\)/.test(adminJs));
	check('the demo shows the switch and what it does', /name="action" value="signa_app_mode"/.test(admin) && /body\.signa-app \.demo-bar\{display:none\}/.test(admin));

	// --- one self-test per section ------------------------------------------
	const kinds = (selfTest.match(/return array\( '([a-z]+)'(?:, '[a-z]+')* \);/) || [])[1];
	check('the self-test knows which sections exist', !!kinds && ['general', 'code', 'gateways', 'security', 'registration', 'design', 'store', 'data'].every((kind) => selfTest.indexOf("'" + kind + "'") >= 0));

	for (const kind of ['general', 'code', 'gateways', 'security', 'registration', 'design', 'store', 'data']) {
		check('the ' + kind + ' section has a test button', new RegExp("'" + kind + "'\\s+=> __\\(").test(settings) && admin.indexOf('data-signa-check="' + kind + '"') >= 0);
		check('and the test really exists', new RegExp('private function ' + kind + '\\(\\)').test(selfTest));
	}

	check('the section head is what prints the buttons', /private function sectionHead\(/.test(settings) && /data-signa-check="%1\$s"/.test(settings));
	check('the gateways section offers a real send too', /data-signa-sms-test/.test(settings));
	check('the captcha button kept its old hook', /data-signa-captcha-test/.test(settings) && settings.indexOf('data-signa-captcha-result') < 0);

	// --- what the tests are allowed to do ------------------------------------
	check('the tests never send a message of their own', selfTest.indexOf('deliver(') < 0 && selfTest.indexOf('dispatcher') < 0);
	check('the code test cleans up after itself', /revoke\( self::SAMPLE_PHONE \)/.test(selfTest));
	check('the data test writes and reads a real event', /admin\.self_test/.test(selfTest) && /\$this->logs->count\(/.test(selfTest));

	// --- the route they run on ------------------------------------------------
	check('the check route is registered', /'\/admin\/check'\s+=> 'check'/.test(api));
	check('and the controller hands the kind straight to the service', /public function check\( Request \$request \): array \{[\s\S]{0,120}\$this->selfTest->run\( \$request->key\( 'kind' \) \)/.test(controller));

	// --- the modal ------------------------------------------------------------
	check('the modal is a real dialog', /document\.createElement\('dialog'\)/.test(adminJs) && /\.showModal\(\)/.test(adminJs));
	check('and it gives focus back when it closes', /opener\.focus\(\)/.test(adminJs));
	check('admin.js asks for the check route', /api\('admin\/check', \{ kind: kind \}\)/.test(adminJs));
	check('a result row carries the status as text, not only as a colour', /screen-reader-text/.test(adminJs) && /statusWord\(/.test(adminJs));
	check('the captcha test reports into the modal', /function testCaptcha\(report\)/.test(adminJs) && /testCaptcha\(function \(row\)/.test(adminJs));
	check('the send test opens its own modal with the administrator number', /data-signa-sms-test/.test(adminJs) && /cfg\.myPhone/.test(adminJs));
	check('and the plugin localises that number', /'myPhone' => \$this->ownPhone\(\)/.test(assets) && /private function ownPhone\(\): string/.test(assets));

	check('the css defines the modal', /\.signa-modal \{/.test(adminCss) && /\.signa-modal::backdrop \{/.test(adminCss));
	check('an engine without <dialog> still gets an overlay, not a thrown error', /typeof dialog\.showModal/.test(adminJs) && /\.signa-modal--fallback/.test(adminCss));
	check('and closing the overlay by hand still returns the focus', /function finish\(\)/.test(adminJs) && /opener\.focus\(\)/.test(adminJs));
	check('and the result rows', /\.signa-test-row \{/.test(adminCss) && /\.signa-test-row\.is-fail \.signa-test-row__dot \{/.test(adminCss));

	// --- the demo can be clicked through -------------------------------------
	check('the demo can run a section test', /data-signa-check="(gateways|data|general)"/.test(admin));
	check('the demo can show the captcha test', /data-signa-check="security" data-signa-captcha-test/.test(admin));
	check('the demo can send a test message', admin.indexOf('data-signa-sms-test') >= 0);
	const sectionLabels = [...nav.matchAll(/'([a-z]+)'\s+=> array\( __\( '([^']+)'/g)].map((m) => m[2]);
	check('and the demo names all eight sections in one place', sectionLabels.length === 8 && sectionLabels.every((label) => admin.indexOf('>' + label + '<') >= 0), sectionLabels.join(' | '));
	check('the demo shows reports in the same navigation', /<a href="\/admin\/reports" class="signa-screen[^"]*"[^>]*>/.test(admin));
	check('the demo api answers the check route', /case 'admin\/check':/.test(server) && /function checkPayload\(/.test(server));
	check('with a failing row in it, so the modal is seen doing its job', /status: 'warn'/.test(server) || /status: 'fail'/.test(server));
}

/*
 * "یکم سرچ بزن، ببین مستندات sms.ir چیه" — the panel documents two send
 * endpoints, a `status` field in every answer and a table of refusal numbers,
 * and an owner who is told "the code was not sent" needs the number.
 *
 * These checks hold the driver to that document: the request it builds, the
 * refusal it recognises, and the account it can ask about without sending
 * anything. They also hold the chain to the rule that an empty account is worth
 * retrying somewhere else while a wrong key is not.
 */
function testSmsIrAgainstItsDocumentation() {
	scenario('sms.ir is read the way sms.ir documents itself');

	const read = (...parts) => fs.readFileSync(path.join(REPO, ...parts), 'utf8');
	const smsIr = read('signa', 'src', 'Gateway', 'Drivers', 'SmsIr.php');
	const probeInterface = read('signa', 'src', 'Gateway', 'AccountProbe.php');
	const result = read('signa', 'src', 'Gateway', 'GatewayResult.php');
	const selfTest = read('signa', 'src', 'Diagnostics', 'SelfTest.php');
	const suite = read('tests', 'php', 'smsir-test.php');
	const ci = read('.github', 'workflows', 'ci.yml');
	const admin = ADMIN_DEMO;
	const server = read('preview', 'server.js');

	// The two documented endpoints, and the two documented ways to authenticate.
	check('the verify endpoint is the documented one', smsIr.indexOf('https://api.sms.ir/v1/send/verify') >= 0);
	check('and so is the free-text one', smsIr.indexOf('https://api.sms.ir/v1/send/bulk') >= 0);
	check('the key travels in the documented header', /'X-API-KEY'\s*=>\s*\$apiKey/.test(smsIr));
	check('the template body is mobile / templateId / parameters', /'mobile'\s*=> \$request->phone\(\)/.test(smsIr) && /'templateId' => \(int\) \$this->template\(\)/.test(smsIr) && /'name'\s*=> \$name/.test(smsIr));

	// A line number is a number on the wire, and a 14-digit one must not be cast.
	check('the line number leaves as a JSON number', smsIr.indexOf("'{\"lineNumber\":' . $sender") >= 0);
	check('and never through an int cast, which saturates on 32-bit PHP', smsIr.indexOf('(int) $sender') < 0 && /32-bit PHP/.test(smsIr));

	// The refusal table, in the driver, with our own codes attached.
	for (const [code, ours, word] of [
		['10', 'unauthorized', 'کلید API نامعتبر'],
		['12', 'unauthorized', 'IP'],
		['20', 'rate_limited', 'سقف'],
		['101', 'rejected', 'شماره خط نامعتبر'],
		['102', 'no_credit', 'اعتبار'],
		['113', 'rejected', 'الگو'],
		['114', 'rejected', '۲۵'],
		['115', 'rejected', 'لیست سیاه'],
		['117', 'rejected', 'تأیید نشده'],
		['119', 'rejected', 'پلن'],
		['123', 'rejected', 'فعال نشده'],
	]) {
		check('refusal ' + code + ' is ' + ours + ' with a sentence about it', new RegExp('\\b' + code + '\\s*=>\\s*array\\(\\s*\'' + ours + '\'').test(smsIr) && smsIr.indexOf(word) >= 0);
	}

	check('the panel sentence is kept as the reason, with the number in front', /'SMS\.ir ' \. \$api \. ': ' \. \( '' !== \$sentence/.test(smsIr));
	check('a refusal hidden behind HTTP 200 is still read from the body', /null !== \$api && isset\( self::\$statuses\[ \$api \] \)/.test(smsIr));

	// Asking the account, not a message.
	check('a driver may be asked about its account, without sending anything', /interface AccountProbe/.test(probeInterface) && /probe\(\): array/.test(probeInterface));
	check('sms.ir answers with credit and lines, and nothing else', /CREDIT_ENDPOINT/.test(smsIr) && /LINE_ENDPOINT/.test(smsIr) && /function probe\(\): array/.test(smsIr) && smsIr.indexOf('wp_remote_post') < 0);
	check('the self-test shows that as its own row', /کلید API و اعتبار/.test(selfTest) && /شماره خط/.test(selfTest));
	check('and the driver interface stays optional for other drivers', !/interface SmsGateway[\s\S]{0,400}probe\(/.test(read('signa', 'src', 'Gateway', 'SmsGateway.php')));

	// Who is allowed to fail over.
	check('an empty account is a transient failure, not a configuration one', /if \( '' !== \$this->errorCode && \$this->isTransient\(\) \) \{\s*\n\s*return false;/.test(result));
	check('a wrong key still stops the chain', /'not_configured', 'unauthorized', 'forbidden', 'bad_credentials'/.test(result));

	// And it is a test in CI, not a claim in a comment.
	check('the suite feeds the driver real panel answers', suite.indexOf('every documented refusal arrives as its own cause') >= 0 && suite.indexOf('the line number leaves as a JSON number') >= 0);
	check('and CI runs it', /smsir-test\.php/.test(ci));

	// The demo can be clicked through to the same rows.
	check('the demo answers the gateways test with the two new rows', server.indexOf('کلید API و اعتبار') >= 0 && server.indexOf('شماره خط') >= 0);
	check('and with a reachability row that carries a cause', server.indexOf('دسترسی این سرور به سامانه') >= 0 && server.indexOf('CONNECT') >= 0);
	const pluginVersion = (read('signa', 'signa.php').match(/Version:\s*([0-9.]+)/) || [])[1];
	const faPluginVersion = !!pluginVersion && pluginVersion.replace(/[0-9]/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[digit]);
	check('the demo version follows the plugin', !!pluginVersion && !!faPluginVersion && admin.indexOf('نسخه ' + pluginVersion) >= 0, pluginVersion);
}

/*
 * The screenshot that arrived with 1.3.6 in it:
 *
 *   «ارسال شد  0911***375
 *    از طریق email — کد آزمایشی ارسال شد.»
 *   smsir   transport   UNKNOWN: کاربر درخواست HTTP را بوکله نمود.
 *
 * Two things were wrong. The test button says "send a test SMS" and the code
 * left by email, with a green tick over it. And the cause WordPress named —
 * the site blocks outbound HTTP, so the request never reached cURL — came back
 * as UNKNOWN, which sends an owner to check DNS for a one-line wp-config fix.
 */
function testBlockedOutboundHttpIsNamedAndNotDressedUpAsSuccess() {
	scenario('a blocked outbound request is named, and a fallback is not a success');

	const read = (...parts) => fs.readFileSync(path.join(REPO, ...parts), 'utf8');
	const transport = read('signa', 'src', 'Support', 'Transport.php');
	const gateway = read('signa', 'src', 'Gateway', 'HttpGateway.php');
	const selfTest = read('signa', 'src', 'Diagnostics', 'SelfTest.php');
	const controller = read('signa', 'src', 'Http', 'AdminController.php');
	const adminJs = read('signa', 'assets', 'js', 'admin.js');
	const assets = read('signa', 'src', 'Front', 'Assets.php');
	const server = read('preview', 'server.js');
	const transportTest = read('tests', 'php', 'transport-test.php');

	// The cause WordPress itself reports when wp-config blocks outbound HTTP.
	check('the WordPress-level block is a cause of its own', /http_request_not_executed/.test(transport) && /blocked requests/.test(transport));
	check('and the Persian wording of it too', transport.indexOf('بوکله') >= 0 && transport.indexOf('بلوکه') >= 0);
	check('the sentence names both constants', /WP_HTTP_BLOCK_EXTERNAL/.test(transport) && /WP_ACCESSIBLE_HOSTS/.test(transport));
	check('and the whitelist is matched the way core does, plus the bare domain', /function allowed\(/.test(transport) && /function blocked\(/.test(transport) && /function egressBlocked\(/.test(transport));

	// Detected before the request, not only after the failure.
	check('a blocked host is refused before the request is made', /function blockFailure\(/.test(transport) && /Transport::egressBlocked\( \$host \)/.test(gateway) && gateway.indexOf('http_request_not_executed') >= 0);
	check('and the self-test says it without knocking', /Transport::blockFailure\( \$host \)/.test(selfTest));
	check('the row names the host that is not allowed', /Transport::BLOCKED|\$block\['message'\]/.test(selfTest));

	// The modal: which channel actually carried the code.
	check('the send test reports the channel that carried the code', /'carrier' => \$carrier/.test(controller) && /'direct'\s*=> \$direct/.test(controller));
	// The label of that row already says «پیامک ارسال نشد»; the sentence says where the code went.
	check('and stops calling an email a sent SMS', /'smsNotSent'\s*=>/.test(assets) && controller.indexOf('کد آزمایشی از راه %s رفت') >= 0);
	check('the script shows a warning instead of a tick', /false === data\.direct \? 'warn' : 'ok'/.test(adminJs));
	check('with the word for it localised', /'smsNotSent'\s*=>/.test(assets) && /i18n\.smsNotSent/.test(adminJs));
	check('and the demo shows that same case', /carrier: 'email'/.test(server) && /direct: false/.test(server));

	// And the whole thing is a test in the PHP suite, not a claim.
	check('the PHP suite feeds it the real sentences', transportTest.indexOf("'User has blocked requests through HTTP.'") >= 0 && transportTest.indexOf('WP_ACCESSIBLE_HOSTS') >= 0);
}

/*
 * Round 10: «راه حلش چیه» — the owner asked it about the screenshot, and the
 * panel has to be able to answer it too. Three answers, each where the owner
 * is already looking: the failure names the wp-config line, the plan card
 * carries the block instead of blaming an unknown gateway, and the plugin
 * offers its own switch for a site whose wp-config.php cannot be edited.
 */
function testTheBlockHasAnAnswer() {
	scenario('a blocked site is given the answers it can act on');

	const read = (...parts) => fs.readFileSync(path.join(REPO, ...parts), 'utf8');
	const transport = read('signa', 'src', 'Support', 'Transport.php');
	const gateway = read('signa', 'src', 'Gateway', 'HttpGateway.php');
	const registry = read('signa', 'src', 'Gateway', 'Registry.php');
	const controller = read('signa', 'src', 'Http', 'AdminController.php');
	const adminJs = read('signa', 'assets', 'js', 'admin.js');
	const assets = read('signa', 'src', 'Front', 'Assets.php');
	const css = read('signa', 'assets', 'css', 'admin.css');
	const settingsScreen = read('signa', 'src', 'Admin', 'SettingsScreen.php');
	const settings = read('signa', 'src', 'Config', 'Settings.php');
	const sanitizer = read('signa', 'src', 'Config', 'Sanitizer.php');
	const server = read('preview', 'server.js');
	const adminHtml = ADMIN_DEMO;
	const ci = read('.github', 'workflows', 'ci.yml');

	// 1. The failure itself: two numbered answers and the host to put in them.
	check('the two wp-config answers are numbered, not run together', /۱\)/.test(transport) && /۲\)/.test(transport));
	check('and the sentence carries the host that was refused', /function host\(/.test(transport) && /\$host/.test(transport));
	check('the host is only claimed when the message names one', /if \( preg_match/.test(transport) && /دامنهٔ سامانهٔ پیامکی/.test(transport));

	// 2. The plugin's own answer for a wp-config nobody can edit.
	check('the plugin can send the request itself', /function direct\( string \$url/.test(gateway) && gateway.indexOf('curl_init') >= 0);
	check('it is off by default', /'direct_send'\s*=> '0'/.test(settings) && /'direct_send'\s*=> array\( 'type' => 'bool' \)/.test(sanitizer));
	check('and the switch says what it bypasses', /ارسال مستقیم/.test(settingsScreen) && /WP_HTTP_BLOCK_EXTERNAL/.test(settingsScreen));
	check('the direct path keeps TLS checked', /CURLOPT_SSL_VERIFYPEER => true/.test(gateway) && /CURLOPT_SSL_VERIFYHOST => 2/.test(gateway));
	check('and it fails in the shape the drivers already read', /'response' => array\( 'code' => \$status/.test(gateway));
	check('the self-test stops predicting the block once the switch is on', /\$this->settings->bool\( 'direct_send', false \) \? null : Transport::blockFailure/.test(read('signa', 'src', 'Diagnostics', 'SelfTest.php')));

	// 3. The plan card: about the SMS gateway, and about this installation.
	check('the plan card reports the block as a configuration problem', /Transport::egressBlocked\( \$host \)/.test(registry));
	check('and never calls the gateway unknown when it is not', registry.indexOf('سامانه پیامکی انتخاب‌شده شناخته نشده است') < 0);
	check('an unknown gateway is named by its id', /سامانهٔ «%s» در فهرست/.test(registry));

	// 4. The modal: what the owner reads.
	check('the channel is named in Persian, not by its id', /'carrier_label' =>/.test(controller) && /data\.carrier_label/.test(adminJs));
	check('the plan belongs to the SMS gateway, not to the channel that rescued it', /function smsPlan\(/.test(controller) && /'plan'\s*=> \$this->smsPlan\(\)/.test(controller));
	check('a fix is offered when the plugin has one', /private function fixFor/.test(controller) && /'fix'\s*=> \$this->fixFor/.test(controller));
	check('and it is the plugin’s own switch, not a homework assignment', /ارسال مستقیم/.test(controller) && controller.indexOf('لازم نیست wp-config.php را عوض کنید') >= 0);
	check('the modal prints the server sentence on its own line', /signa-test-row__why/.test(adminJs) && /\.signa-test-row__why \{/.test(css));
	check('and that line is a left-to-right one', /why\.dir = 'ltr'/.test(adminJs) && /direction: ltr/.test(css));
	check('the fix row has a word for itself', /'fix'\s*=> __\(/.test(assets) && /i18n\.fix/.test(adminJs));
	check('the hint sentence that explained the modal is gone', adminJs.indexOf('smsHint') < 0 && assets.indexOf('smsHint') < 0);

	// 5. The demo promises the same thing, and CI runs the suite that proves it.
	check('the demo has the switch and the fix', /ارسال مستقیم/.test(adminHtml) && /"fix":"راه‌حل"/.test(adminHtml));
	check('and its mock answers with the Persian channel label', /carrier_label: 'ایمیل'/.test(server) && /fix: '/.test(server));
	// 6. Who is told what: the administrator's sentence stays in the panel.
	const auth = read('signa', 'src', 'Http', 'AuthController.php');
	const result = read('signa', 'src', 'Gateway', 'GatewayResult.php');
	const health = read('signa', 'src', 'Gateway', 'Health.php');
	const chain = read('signa', 'src', 'Gateway', 'FailoverChain.php');

	check('the visitor is never handed the administrator’s diagnosis', /\$result->visitorMessage\(\)/.test(auth) && auth.indexOf("'delivery_failed',\n\t\t\t\t$result->message()") < 0);
	check('and the sentence exists for both visitors', /public function visitorMessage\(\): string/.test(result) && /rate_limited/.test(result));
	check('it names no constant and no gateway', result.indexOf('visitorMessage') > 0 && /__\( 'امکان ارسال کد در این لحظه نیست/.test(result));

	// 7. The site's own block is not the gateway's failure.
	check('a blocked request does not count against the gateway', /public function blocked\( string \$gateway/.test(health) && /'count'\s*=> 0/.test(health));
	check('so the breaker never benches it', /function blockedUntil/.test(health) && health.indexOf("if ( ! empty( $record['blocked'] ) )") > 0);
	check('and the row says whose problem it is', /خودِ سایت درخواست خروجی را می\u200cبندد/.test(health));
	check('the chain chooses which record to keep', /Transport::isBlocked\(/.test(chain) && /gateway\.blocked/.test(chain) && chain.indexOf('gateway.failed') > 0);
	check('the pre-flight error carries the technical line, not a finished reason', /Transport::blockReason\( \$host \)/.test(gateway) && /public static function blockReason/.test(transport));
	check('and the reason can be read back from the text alone', /public static function isBlocked/.test(transport));

	// 8. The banner that answers the question before it is asked.
	check('every settings screen leads with the block, when it is true', /private function egressNotice/.test(settingsScreen) && /\$this->egressNotice\(\);/.test(settingsScreen));
	check('it names the host and both answers', /اجازهٔ درخواست خروجی به %s/.test(settingsScreen) && /ارسال مستقیم/.test(settingsScreen) && /WP_HTTP_BLOCK_EXTERNAL/.test(settingsScreen));
	check('and it disappears once the switch is on', /bool\( 'direct_send', false \)\s*\)\s*\{\s*return;/.test(settingsScreen));
	check('the blocked-site suite runs in CI', /php tests\/php\/direct-send-test\.php/.test(ci));
	check('and is documented in the test README', /direct-send-test\.php/.test(read('tests', 'README.md')));
}

/*
 * Round 12: the owner's security modal said «۲ درخواست بدون توکن کپچا … ویجت
 * در مرورگر کاربران بارگذاری نشده» three lines under a green «کپچا درست
 * بارگذاری شد». The count was real, the sentence was a guess, and the client
 * was feeding it: an empty token was posted as if it were a token, so the
 * server had no way to tell an outage from a robot.
 */
function testTheCaptchaKnowsWhoItIsTalkingTo() {
	scenario('a captcha that cannot load is not the visitor’s fault');

	const read = (...parts) => fs.readFileSync(path.join(REPO, ...parts), 'utf8');
	const front = read('signa', 'assets', 'js', 'front.js');
	const guard = read('signa', 'src', 'Guard', 'CaptchaGuard.php');
	const pipeline = read('signa', 'src', 'Guard', 'Pipeline.php');
	const selfTest = read('signa', 'src', 'Diagnostics', 'SelfTest.php');
	const store = read('signa', 'src', 'Log', 'LogStore.php');
	const ci = read('.github', 'workflows', 'ci.yml');
	const readme = read('tests', 'README.md');

	// 1. The client: a token, or an honest failure — never '' dressed as one.
	check('a v3 token that never arrives is retried once', /self\.pause\(600\)\.then\(once\)/.test(front));
	check('and then reported as an outage instead of sent as empty', /Captcha\.prototype\.missing = function/.test(front) && /this\.state = 'unavailable'/.test(front));
	check('the state travels with the request', /body\.captcha_state = self\.captcha\.state/.test(front));
	check('and is cleared on the retry, so a stale claim cannot ride along', /delete body\.captcha_state;/.test(front));
	check('with fail-open off, nothing is sent at all', /if \(!this\.conf\.failOpen\) \{\s*return Promise\.reject\(this\.error\(\)\);/.test(front));

	// 2. The server: the claim is worth exactly what the admin's setting grants.
	check('the guard reads the browser’s state', /const STATE_UNAVAILABLE = 'unavailable'/.test(guard) && /function browserOutage/.test(guard));
	check('it refuses the claim while fail-open is off', /if \( ! \$this\->captcha\->failOpen\(\) \) \{\s*return false;/.test(guard));
	check('an accepted outage is logged as a fail-open with its own reason', /'reason'\s*=> 'browser_unavailable'/.test(guard));
	check('and the guard still asks the provider for a real token', /return \$provider->verify\( \$token, \$ip \)/.test(read('signa', 'src', 'Captcha', 'Manager.php')));

	// 3. The evidence the diagnosis needs.
	check('rejections record the user agent', /'ua'\s*=> substr\( trim\( \$request->userAgent\(\) \), 0, 200 \)/.test(pipeline));
	check('and the reader goes through the meta column, not a property that never exists', /public static function metaOf/.test(store) && /LogStore::metaOf\( \$row, 'ua' \)/.test(selfTest));
	check('the row is split by who sent the request', /function looksLikeBrowser/.test(selfTest) && /function captchaRejectRow/.test(selfTest));
	check('and the old sentence that blamed the visitors is gone', selfTest.indexOf('ویجت در مرورگر کاربران بارگذاری نشده') < 0);
	check('bots are reported as the captcha working', /از ربات یا اسکریپت بود/.test(selfTest));
	check('the passes granted during an outage are counted too', /عبور بدون کپچا/.test(selfTest));

	// 4. It is a test in CI, not a claim.
	check('the captcha suite runs in CI', /php tests\/php\/captcha-test\.php/.test(ci));
	check('and is documented', /captcha-test\.php/.test(readme));
}

/*
 * Round 13: «ظاهر فرمم با پیش‌نمایش فرق داره … وردپرس چیزیو سرخود عوض می‌کنه؟»
 * — no. Two other things did: the preview borrowed Vazirmatn from a CDN while
 * the form said `--signa-font: inherit`, and a theme's own selectors outrank a
 * stylesheet that names one class each. The captcha question has its own
 * answer, and it belongs in the panel rather than in a reply.
 */
function testTheFormLooksLikeItself() {
	scenario('the form brings its own font, and the panel answers the captcha question');

	const read = (...parts) => fs.readFileSync(path.join(REPO, ...parts), 'utf8');
	const css = read('signa', 'assets', 'css', 'front.css');
	const renderer = read('signa', 'src', 'Front', 'FormRenderer.php');
	const sanitizer = read('signa', 'src', 'Config', 'Sanitizer.php');
	const selfTest = read('signa', 'src', 'Diagnostics', 'SelfTest.php');
	const settings = read('signa', 'src', 'Config', 'Settings.php');
	const screen = read('signa', 'src', 'Admin', 'SettingsScreen.php');
	const index = read('preview', 'public', 'index.html');
	const server = read('preview', 'server.js');
	const ci = read('.github', 'workflows', 'ci.yml');

	// 1. The font travels with the plugin, and both subsets survive.
	const faces = css.match(/@font-face/g) || [];
	check('six faces: two subsets for three weights', faces.length === 6);
	check('each one claims its own codepoints', (css.match(/unicode-range:/g) || []).length === 6);
	check('the Persian digits come from the Arabic file', /arabic-400-normal\.woff2\)[^}]*U\+06F0-06F9/s.test(css));
	check('and ASCII from the Latin one', /latin-400-normal\.woff2\)[^}]*U\+0020-007E/s.test(css));
	check('the two files are really in the package', ['arabic', 'latin'].every((s) => [400, 600, 700].every((w) => fs.existsSync(path.join(REPO, 'signa', 'assets', 'fonts', `vazirmatn-${s}-${w}-normal.woff2`)))));
	check('with the licence that allows it', fs.existsSync(path.join(REPO, 'signa', 'assets', 'fonts', 'OFL.txt')));
	check('the default font is the shipped one', /--signa-font: 'Vazirmatn'/.test(css));

	// 2. The preview stops borrowing the font it now ships.
	check('the preview no longer calls a font CDN', !/fonts\.googleapis\.com|fonts\.gstatic\.com/.test(index));
	check('and serves woff2 as a font', /'\.woff2': 'font\/woff2'/.test(server));

	// 3. A theme cannot take the form over.
	check('controls are declared again with the form in front', /\.signa \.signa-field__input,/.test(css) && /\.signa \.signa-btn \{/.test(css));
	check('the font of a control is not the theme\'s to choose', /\.signa \.signa-btn \{\s*font-family: inherit;/.test(css));
	check('and the digits-only field keeps its tracking', css.indexOf('.signa-code-single .signa-code__bulk') > css.indexOf('.signa .signa-code__box,'));

	// 4. The setting exists end to end.
	check('the setting has a default', /'form_font'\s*=> 'vazirmatn'/.test(settings));
	check('it is sanitised as a font, not as text', /'form_font_custom'\s*=> array\( 'type' => 'font' \)/.test(read('signa', 'src', 'Config', 'Sanitizer.php')));
	check('and a font value cannot end its own declaration', /function fontFamily/.test(sanitizer) && /preg_replace\( '\/\[\^A-Za-z0-9 ,/.test(sanitizer));
	check('the form prints it on the element', /--signa-font:' \. \$font/.test(renderer));
	check('and the surface colour too, which used to be printed where nothing read it', /--signa-surface:' \. \$surface/.test(renderer));
	check('the settings screen offers it', /'form_font',/.test(screen) && /form_font_custom/.test(screen));

	// 5. The two questions the owner actually asked get answers in the panel.
	check('the panel says when the challenge is invisible', /چالش دیدنی است؟/.test(selfTest) && /کاربر چالش را می‌بیند/.test(selfTest));
	check('and what to choose instead of v3', /ARCaptcha یا hCaptcha/.test(selfTest));
	check('an exempt number is named, masked, as a warning', /function trustedRow/.test(selfTest) && /شمارهٔ خودتان/.test(selfTest) && /function ownPhone/.test(selfTest));
	check('the row does not print the number in full', !/trustedRow[\s\S]{0,900}\$own \.[^)]*\$own/.test(selfTest));
	check('after_limit says the first attempts pass unchallenged', /بدون چالش رد می‌شوند/.test(selfTest));
	check('the appearance test names the font', /فونت فرم/.test(selfTest) && /function fontLabel/.test(selfTest));
	check('and says it is the preview\'s font', /همان فونتی که پیش‌نمایش/.test(selfTest));

	// 6. It is a test in CI, not a paragraph.
	check('the appearance suite runs in CI', /php tests\/php\/appearance-test\.php/.test(ci));
	check('and the demo shows the new rows', /چالش دیدنی است؟/.test(server) && /فونت فرم/.test(server));
	check('every address the preview promises is an address it serves', /pathname === '\/login'[\s\S]{0,160}index\.html/.test(server));
}

/*
 * Round 14: «چیزی که توی دمو هست رو ببین؛ اینو میخوام. هیچ چیزی رو از وردپرس
 * نخونه.» The form is now rendered inside a shadow root with the plugin's own
 * stylesheet injected into it, so the theme's cascade and inheritance stop at
 * the boundary. These checks drive the real swap, the real fallback, and the
 * two things a shadow root could quietly break: events leaving the tree, and a
 * site that recolours the form from the outside.
 */
async function testVendorStylesInsideShadow() {
	scenario("a third-party widget's own stylesheet reaches the form inside its own tree");

	const read = (...parts) => fs.readFileSync(path.join(REPO, ...parts), 'utf8');
	const cssFile = read('signa', 'assets', 'css', 'front.css');

	/*
	 * This is the screenshot that came back from a live site: ARCaptcha's
	 * widget inside an isolated form rendered as bare markup, because its
	 * stylesheet is injected into the page's <head> and a shadow root cannot
	 * see the head. Its loader mark is an SVG with viewBox 0 0 621 363, so it
	 * laid out at that size: a purple cloud across the form.
	 */
	const bundle = {
		enabled: true,
		provider: 'arcaptcha',
		siteKey: 'arc-site-key',
		kind: 'widget',
		scripts: [],
		failOpen: true,
		config: { siteKey: 'arc-site-key', kind: 'widget', lang: 'fa', dir: 'rtl', theme: 'light' },
	};

	// A page that already carries the vendor's sheet — a second form, or a
	// library that loaded before this one mounted.
	const prewarmed = '<style id="arcaptcha-prewarm">.spinner-loader{display:flex;}</style>';

	const ctx = await bootIsolated({
		config: { isolate: true, css: '/plugin-assets/css/front.css', assets: '/plugin-assets/', captcha: bundle },
		html: (source) => source.replace('</head>', prewarmed + '</head>'),
		fetch: (request) => {
			if (String(request.url).indexOf('.css') > 0) {
				return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve(cssFile) });
			}

			return ok(verifyStep());
		},
	});

	ctx.win.arcaptcha = { render: () => 7, getArcToken: () => 'ARC-TOKEN-1' };

	const shadow = ctx.root.shadowRoot;
	const host = shadow && shadow.querySelector('[data-signa-captcha]');

	check('the widget is mounted inside the shadow tree', !!host);
	check(
		'which is exactly why its stylesheet has to follow it in',
		!!host && host.getRootNode() === shadow
	);

	const copies = () => [...shadow.querySelectorAll('style[data-signa-vendor-style], link[data-signa-vendor-style]')]
		.map((node) => node.textContent)
		.join('\n');

	check('a sheet already in the head that names the vendor is copied in', copies().indexOf('.spinner-loader{display:flex;}') >= 0);

	// The vendor, arriving with the widget, injects its styling the way it does
	// on every other page: into the head.
	const late = ctx.doc.createElement('style');
	late.textContent = '.spinner-logo{width:40px;height:24px;}.tw-flex{display:flex;}';
	ctx.doc.head.appendChild(late);

	await tick();
	await tick();

	check('and the sheet it injects later follows it immediately', copies().indexOf('.spinner-logo{width:40px') >= 0);

	// The vendor's rules have to beat this plugin's fallback caps, or a widget
	// would be clipped to the fallback size on a site where it loads fine.
	const shadowStyles = [...shadow.querySelectorAll('style')];
	const vendorLast = shadowStyles[shadowStyles.length - 1];

	check('the copy lands last, after the plugin stylesheet, so the vendor wins a tie', '1' === (vendorLast && vendorLast.getAttribute('data-signa-vendor-style')));

	// A widget may also append its sheet to the body, or to a wrapper of its
	// own; the copy still has to arrive.
	const inBody = ctx.doc.createElement('style');
	inBody.textContent = '.arcaptcha, #checkbox { box-sizing: border-box; }';
	ctx.doc.body.appendChild(inBody);

	await tick();
	await tick();

	check('a sheet the widget puts in the body is picked up as well', copies().indexOf('#checkbox { box-sizing') >= 0);

	/*
	 * The same door, used by a theme: CSS that loads after the form mounted is
	 * copied in, but *below* this plugin's own rules, so the form keeps its own
	 * layout — which is the promise isolation makes.
	 */
	const theme = ctx.doc.createElement('style');
	theme.textContent = '.signa .signa-btn { background: #ff0000 !important; }';
	ctx.doc.head.appendChild(theme);

	await tick();
	await tick();

	const order = [...shadow.querySelectorAll('style')];
	const pluginAt = order.findIndex((node) => '1' === node.getAttribute('data-signa-shadow-style'));
	const pageAt = order.findIndex((node) => '1' === node.getAttribute('data-signa-page-style'));

	check('a theme sheet that loads late is copied in below the plugin rules, not above them', pageAt >= 0 && pluginAt >= 0 && pageAt < pluginAt);

	// And the fallback: a widget that never gets its stylesheet must not be
	// able to blow the form apart in the first place.
	check('the plugin caps runaway media inside the captcha slot', /\.signa-captcha :where\(img, svg, canvas, video\) \{[^}]*max-height: 96px;/.test(cssFile));
	check('and caps an embedded frame by width', /\.signa-captcha :where\(iframe\) \{[^}]*max-width: 100%;/.test(cssFile));
	check('in the light DOM nothing is mirrored, because the head already applies', !!(await (async () => {
		const plain = boot({
			config: { isolate: false, captcha: bundle },
			fetch: () => ok(verifyStep()),
		});

		await tick();

		return plain.root.querySelector('[data-signa-captcha]') && false === plain.root.querySelector('[data-signa-captcha]').signaVendorStyles.shadow;
	})()));
}

async function testStyleIsolation() {
	scenario('the form renders in its own tree, so the theme cannot restyle it');

	const read = (...parts) => fs.readFileSync(path.join(REPO, ...parts), 'utf8');
	const cssFile = read('signa', 'assets', 'css', 'front.css');
	const assets = read('signa', 'src', 'Front', 'Assets.php');
	const index = read('preview', 'public', 'index.html');

	/* --- it is the shipped default, and the panel says so ---------------- */
	check('isolation is on unless a site turns it off', /'isolate'\s*=> \$this->settings->bool\( 'style_isolation', true \)/.test(assets));
	check('the stylesheet URL is the one the <link> already loaded, so the fetch is a cache hit', /add_query_arg\( 'ver', SIGNA_VERSION, SIGNA_URL \. 'assets\/css\/front.css' \)/.test(assets));
	check('the font base travels with it', /'assets'\s*=> esc_url_raw\( SIGNA_URL \. 'assets\/' \)/.test(assets));
	check('and the computed variables travel as values, not as a :root block', /'vars'\s*=> \$this->variables\(\)/.test(assets));

	/* --- the stylesheet is self-sufficient inside a shadow tree ---------- */
	check('the root block declares what a theme would otherwise pass down', /font-weight: 400;[\s\S]{0,400}letter-spacing: normal;[\s\S]{0,200}text-transform: none;/.test(cssFile));
	check('and a shadow host does not collapse to inline', /:host \{\s*display: block;/.test(cssFile));
	check('the font lives in the same file the shadow gets', /@font-face/.test(cssFile) && /\.\.\/fonts\/vazirmatn-arabic-400-normal\.woff2/.test(cssFile));

	/* --- the swap itself -------------------------------------------------- */
	const booted = await bootIsolated({
		config: { isolate: true, css: '/plugin-assets/css/front.css', assets: '/plugin-assets/' },
		fetch: (request) => {
			if (String(request.url).indexOf('.css') > 0) {
				return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve(cssFile) });
			}

			return ok(verifyStep());
		},
	});

	const host = booted.root;
	const shadow = host.shadowRoot;
	const style = shadow && shadow.querySelector('style[data-signa-shadow-style]');
	const inner = shadow && shadow.querySelector('.signa');

	check('the form is moved into a shadow root', !!shadow && 'open' === shadow.mode);
	check('the plugin stylesheet is injected there', !!style && style.textContent.length > 30000);
	check('with the font URLs made absolute, because a <style> resolves against the page', !!style && style.textContent.indexOf('url(/plugin-assets/fonts/vazirmatn-arabic-400-normal.woff2)') >= 0);
	check(
		'the markup moved in, and nothing was left behind to be restyled',
		inner ? inner.querySelectorAll('[data-signa-step]').length === 3 && !!inner.querySelector('[data-signa-phone]') && 0 === host.children.length : false
	);
	check('the inner element carries the same classes, so the skin still applies', inner ? 'signa' === inner.className.split(' ')[0] && inner.classList.contains('signa-skin-line') : false);
	check(
		'the plugin\'s own variables are inline on it, where a theme cannot outrank them',
		inner ? '#0f766e' === inner.style.getPropertyValue('--signa-accent') && 'rgba(15, 118, 110, 0.14)' === inner.style.getPropertyValue('--signa-accent-soft') : false
	);
	check('the form object was built on the inner element', booted.form.root === inner);
	check('and it is interactive: the phone step is the current one', inner ? inner.querySelector('[data-signa-step="phone"]').classList.contains('is-current') : false);
	check('the host records that it was isolated', '1' === host.getAttribute('data-signa-isolated'));
	check('the stylesheet was fetched once, not per form', booted.calls.filter((call) => call.url.indexOf('.css') > 0).length === 1);
	check('and it is the same URL the page already loaded', booted.calls.some((call) => call.url === '/plugin-assets/css/front.css'));

	/* --- a site that recolours the form from outside still works --------- */
	host.style.setProperty('--signa-accent', '#b91c1c');
	host.classList.add('signa-skin-card');
	await wait(10);

	check('changing the accent on the host reaches the form', inner.style.getPropertyValue('--signa-accent') === '#b91c1c');
	check('and so does changing the skin class', inner.classList.contains('signa-skin-card'));

	/* --- events still reach the page ------------------------------------- */
	let heard = 0;
	booted.doc.addEventListener('signa:sent', () => { heard++; });
	inner.dispatchEvent(new booted.win.CustomEvent('signa:sent', { bubbles: true, composed: true, detail: {} }));
	check('an event crosses the shadow boundary, so a site still hears it', heard === 1);

	/* --- the fallback: no stylesheet text, no isolation, form still works - */
	const broken = await bootIsolated({
		config: { isolate: true, css: '/plugin-assets/css/front.css', assets: '/plugin-assets/' },
		fetch: () => Promise.reject(new Error('offline')),
	});

	check('a stylesheet that cannot be read leaves the form in the light DOM', !broken.root.shadowRoot);

	/*
	 * A security plugin or a CDN rule can answer the stylesheet request with an
	 * HTML error page and a 200. Injecting that into a shadow root would leave
	 * a form nothing can style: the theme cannot reach in, and the plugin's own
	 * rules are in the page it just threw away.
	 */
	const wrongBody = await bootIsolated({
		config: { isolate: true, css: '/plugin-assets/css/front.css', assets: '/plugin-assets/' },
		fetch: () => Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('<html><body>403 Forbidden</body></html>') }),
	});

	check('and something that is not the stylesheet is not injected as one', !wrongBody.root.shadowRoot);
	check('with the form still mounted and usable', !!wrongBody.root.signaForm && !!wrongBody.root.signaForm.phoneInput);
	check('and the form is mounted and usable anyway', !!broken.root.signaForm && !!broken.root.signaForm.phoneInput);
	check('nothing claims it was isolated', !broken.root.getAttribute('data-signa-isolated'));

	/* --- no CSS URL at all: do not fetch the page and call it a stylesheet */
	const bare = await bootIsolated({ config: { isolate: true, css: '' } });

	check('without a stylesheet URL there is no swap at all', !bare.root.shadowRoot && !!bare.root.signaForm);

	/* --- the demo shows it, with a theme trying to get in ---------------- */
	check('the demo can switch a hostile theme on around the form', /id="demo-hostile"/.test(index) && /demo-page\.is-hostile/.test(index));
	check('and that theme is written to be aggressive', /font-family: 'Courier New', monospace !important/.test(index) && /background: #f0f0f1 !important/.test(index));
	check('while the demo form is isolated for real', /isolate: true/.test(index) && /css: '\/plugin-assets\/css\/front\.css'/.test(index));
}

	/*
	 * ARCaptcha is the one provider whose client API this plugin once got
	 * wrong: the widget was called through `arcaptcha.widget.*`, an object the
	 * library never exposed, so the Iranian widget never appeared at all and
	 * nothing caught it. These scenarios pin the four things that have to be
	 * true for it to work: the widget renders through `arcaptcha.render`, the
	 * token is read through `arcaptcha.getArcToken`, a token that arrives
	 * wrapped in an object is unwrapped, and the hidden field their docs
	 * promise is read when the getter is not there.
	 */

async function testArcaptchaWidget() {
	scenario('ARCaptcha renders through the documented widget API');

	const bundle = {
		enabled: true,
		provider: 'arcaptcha',
		siteKey: 'arc-site-key',
		kind: 'widget',
		scripts: [],
		failOpen: true,
		config: { siteKey: 'arc-site-key', kind: 'widget', lang: 'fa', dir: 'rtl', theme: 'light' },
	};

	const ctx = boot({
		config: { captcha: bundle },
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'fresh' }) : ok(verifyStep())),
	});

	const rendered = [];

	// The library, as the vendor ships it: render() returns a widget id and
	// getArcToken(id) hands back the solved token.
	ctx.win.arcaptcha = {
		render: (element, params) => {
			rendered.push({ element, params });
			return 7;
		},
		getArcToken: (id) => (7 === id ? 'ARC-TOKEN-123' : ''),
	};

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	for (let i = 0; i < 10; i++) await tick();

	check('the widget is rendered into the captcha container', rendered.length === 1 && !!rendered[0].element);
	check(
		'the site key travels under the name the library reads',
		!!rendered[0] && 'arc-site-key' === rendered[0].params.site_key,
		rendered[0] ? JSON.stringify(rendered[0].params) : 'never rendered'
	);
	check(
		'the Persian widget is asked for in Persian, right to left',
		!!rendered[0] && 'fa' === rendered[0].params.lang && 'rtl' === rendered[0].params.dir && 'light' === rendered[0].params.theme
	);
	check(
		'the solved token reaches the server',
		ctx.calls.some((call) => call.url.indexOf('/start') >= 0 && 'ARC-TOKEN-123' === call.body.captcha_token),
		ctx.calls.map((call) => call.url + ' ' + JSON.stringify(call.body && call.body.captcha_token)).join(' | ')
	);
}

async function testArcaptchaHiddenField() {
	scenario('ARCaptcha: a token in the documented hidden field is found');

	const bundle = {
		enabled: true,
		provider: 'arcaptcha',
		siteKey: 'arc-site-key',
		kind: 'widget',
		scripts: [],
		failOpen: false,
		config: { siteKey: 'arc-site-key', kind: 'widget', lang: 'fa', dir: 'rtl', theme: 'light' },
	};

	const ctx = boot({
		config: { captcha: bundle },
		html: (source) => source.replace(
			'<div class="signa-captcha" data-signa-captcha hidden></div>',
			'<div class="signa-captcha" data-signa-captcha hidden></div>' +
				'<input type="hidden" name="arcaptcha-token" value="FIELD-TOKEN-456">'
		),
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'fresh' }) : ok(verifyStep())),
	});

	// An older bundle: it renders (or re-renders) but exposes no getter.
	ctx.win.arcaptcha = { render: () => 7 };

	check(
		'the hidden field is inside the form, as the scenario assumes',
		!!ctx.root.querySelector('input[name="arcaptcha-token"]')
	);

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	for (let i = 0; i < 10; i++) await tick();

	check(
		'the field token is posted, not an empty string',
		ctx.calls.some((call) => call.url.indexOf('/start') >= 0 && 'FIELD-TOKEN-456' === call.body.captcha_token),
		ctx.calls.map((call) => JSON.stringify(call.body && call.body.captcha_token)).join(' | ')
	);
}

async function testArcaptchaObjectToken() {
	scenario('ARCaptcha: a token that arrives wrapped in an object is unwrapped');

	const bundle = {
		enabled: true,
		provider: 'arcaptcha',
		siteKey: 'arc-site-key',
		kind: 'score',
		scripts: [],
		failOpen: false,
		config: { siteKey: 'arc-site-key', kind: 'score', action: 'signa_send' },
	};

	const ctx = boot({
		config: { captcha: bundle },
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'fresh' }) : ok(verifyStep())),
	});

	// The invisible flow their docs describe: execute() resolves an object.
	ctx.win.arcaptcha = {
		ready: (fn) => fn(),
		execute: () => Promise.resolve({ arcaptcha_token: 'OBJECT-TOKEN-789' }),
	};

	ctx.doc.querySelector('[data-signa-phone]').value = '09121234567';
	ctx.form.act('start');
	for (let i = 0; i < 12; i++) await tick();

	check(
		'the token inside the object is posted, not the object itself',
		ctx.calls.some((call) => call.url.indexOf('/start') >= 0 && 'OBJECT-TOKEN-789' === call.body.captcha_token),
		ctx.calls.map((call) => JSON.stringify(call.body && call.body.captcha_token)).join(' | ')
	);
}

async function main() {
	await testStepBar();
	await testActionableErrors();
	await testThrottleHasNoFalseHope();
	await testCodeLengthRebuild();
	await testRescuePanel();
	await testEveryScreenIsReachable();
	await testThePanelKeepsItsOwnPromises();
	await testSmsIrAgainstItsDocumentation();
	await testBlockedOutboundHttpIsNamedAndNotDressedUpAsSuccess();
	await testTheBlockHasAnAnswer();
	await testTheCaptchaKnowsWhoItIsTalkingTo();
	await testTheFormLooksLikeItself();
	await testStyleIsolation();
	await testVendorStylesInsideShadow();
	await testCooldownAndPersianDigits();
	await testFocusMovesToTheProblem();
	await testSkipLink();
	await testExpiry();
	await testStaleNonceStillRecovers();
	await testCaptchaFailureIsVisible();
	await testArcaptchaWidget();
	await testArcaptchaHiddenField();
	await testArcaptchaObjectToken();
	await testStaleFormTokenRecovers();
	await testPasteFromSms();
	await testPasteWithoutAClipboard();
	await testPhoneFieldHasOneTruth();
	await testTheLookOfTheTwoReportedBugs();
	await testPreviewMirrorsTheTemplate();
	await testTheAccentIsTheOnlyColour();
	await testTheThreeDemoPages();

	console.log('\n' + (failed ? failed + ' FAILED, ' : '') + passed + ' checks passed');
	process.exit(failed ? 1 : 0);
}

main().catch((error) => {
	console.error(error);
	process.exit(1);
});
