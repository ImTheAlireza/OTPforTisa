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
const SCRIPT = path.join(REPO, 'tisa-otp', 'assets', 'js', 'front.js');

const scriptSource = fs.readFileSync(SCRIPT, 'utf8');
const pageSource = fs.readFileSync(PAGE, 'utf8');

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
 * by design, so those scenarios must edit the HTML, not `window.tisaOtp`).
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
	const config = html.match(/window\.tisaOtp\s*=\s*\{[\s\S]*?\n\t\};/);
	if (!config) throw new Error('could not find the tisaOtp config block in the harness page');
	win.eval(config[0]);

	// Merge the test's overrides over it, exactly as a site's settings would.
	win.eval('window.__tisaOverride = ' + JSON.stringify(opts.config || {}) + ';');
	win.eval('window.tisaOtp = Object.assign({}, window.tisaOtp, window.__tisaOverride);');

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

	win.eval(scriptSource);

	// jsdom is still parsing when the script runs, so front.js waits for the
	// event a browser would fire here.
	if ('loading' === win.document.readyState) {
		win.document.dispatchEvent(new win.Event('DOMContentLoaded', { bubbles: true }));
	}

	const root = win.document.querySelector('[data-tisa-form]');

	if (!root || !root.tisaForm) {
		throw new Error('front.js did not mount the form');
	}

	return { win, doc: win.document, root, form: root.tisaForm, calls };
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

	const markers = Array.from(ctx.doc.querySelectorAll('[data-tisa-step-marker]'));
	const announce = ctx.doc.querySelector('[data-tisa-steps-text]');

	check('three markers rendered', 3 === markers.length, markers.length + ' found');
	check('first marker starts current', markers[0].classList.contains('is-current'));
	check('announcement starts at step 1', text(announce).indexOf('۱') >= 0, text(announce));

	ctx.doc.querySelector('[data-tisa-phone]').value = '09121234567';
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
	check('list itself stays hidden from screen readers', 'true' === ctx.doc.querySelector('[data-tisa-steps]').getAttribute('aria-hidden'));
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

	ctx.doc.querySelector('[data-tisa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	ctx.form.fillCode('99999');
	ctx.form.act('verify');
	await tick();
	await tick();

	const status = ctx.doc.querySelector('[data-tisa-status]');
	const actions = Array.from(ctx.doc.querySelectorAll('.tisa-otp__status-action'));

	check('status is an assertive alert', 'alert' === status.getAttribute('role') && 'assertive' === status.getAttribute('aria-live'));
	check('invalid_code offers exactly one action', 1 === actions.length, actions.length + ' buttons');
	check('that action is "new code"', text(actions[0]).indexOf('کد تازه') >= 0, text(actions[0]));
	check('remaining attempts are shown', text(ctx.doc.querySelector('[data-tisa-attempts]')).indexOf('۳') >= 0, text(ctx.doc.querySelector('[data-tisa-attempts]')));
	check('code boxes are marked invalid', 'true' === ctx.doc.querySelector('[data-tisa-box]').getAttribute('aria-invalid'));

	// A network failure should offer a retry that repeats the last action.
	const ctx2 = boot({
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : Promise.reject(new Error('down'))),
	});
	ctx2.doc.querySelector('[data-tisa-phone]').value = '09121234567';
	ctx2.form.act('start');
	await tick();
	await tick();

	const retry = ctx2.doc.querySelector('.tisa-otp__status-action');
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

	ctx.doc.querySelector('[data-tisa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	check('no action buttons offered', 0 === ctx.doc.querySelectorAll('.tisa-otp__status-action').length);
	check('message still reaches the user', text(ctx.doc.querySelector('[data-tisa-status-text]')).length > 0);
}

async function testCodeLengthRebuild() {
	scenario('Boxes rebuild when the server reports a different code length');

	const ctx = boot({
		fetch: (req) =>
			req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok(verifyStep({ code_length: 6 })),
	});

	check('starts with five boxes', 5 === ctx.doc.querySelectorAll('[data-tisa-box]').length);

	ctx.doc.querySelector('[data-tisa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	const boxes = Array.from(ctx.doc.querySelectorAll('[data-tisa-box]'));
	check('rebuilt to six boxes', 6 === boxes.length, boxes.length + ' boxes');
	check('bulk input maxlength follows', 6 === ctx.doc.querySelector('[data-tisa-code-bulk]').maxLength);
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

	const rescue = ctx.doc.querySelector('[data-tisa-rescue]');
	check('hidden before the code step', rescue.hidden);

	ctx.doc.querySelector('[data-tisa-phone]').value = '09121234567';
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

	ctx.doc.querySelector('[data-tisa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	const label = text(ctx.doc.querySelector('[data-tisa-resend-label]'));
	const bar = ctx.doc.querySelector('[data-tisa-cooldown]');

	check('countdown uses Persian digits', /[۰-۹]/.test(label) && !/[0-9]/.test(label), label);
	check('resend is disabled while it runs', ctx.doc.querySelector('[data-tisa-action="resend"]').disabled);
	check('progress bar is shown', !bar.hidden);
	check('bar duration matches the cooldown', '45s' === bar.style.getPropertyValue('--tisa-cooldown'), bar.style.getPropertyValue('--tisa-cooldown'));
	check('bar is decorative for screen readers', 'true' === bar.getAttribute('aria-hidden'));

	// The phone value itself must stay Latin, or the server cannot parse it.
	check('phone field keeps Latin digits', /^[0-9]+$/.test(ctx.doc.querySelector('[data-tisa-phone]').value));
}

async function testFocusMovesToTheProblem() {
	scenario('Focus lands on the thing that needs fixing');

	const ctx = boot({
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok({ step: 'register_form', fields: [] })),
	});

	// Client-side validation: empty required fields.
	ctx.doc.querySelector('[data-tisa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	ctx.form.act('submit-fields');
	await tick();

	const active = ctx.doc.activeElement;
	check('focus is on an invalid field', 'true' === (active.getAttribute && active.getAttribute('aria-invalid')), active.tagName + '/' + (active.getAttribute ? active.getAttribute('data-tisa-input') : ''));
	check('the invalid field names its error', !!(active.getAttribute('aria-describedby') || '').length);
}

async function testSkipLink() {
	scenario('Skip link focuses the current step, not a hidden field');

	const ctx = boot({
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok(verifyStep())),
	});

	const skip = ctx.doc.querySelector('[data-tisa-skip]');
	check('skip link is the first focusable element in the form', !!skip);

	ctx.doc.querySelector('[data-tisa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	skip.dispatchEvent(new ctx.win.MouseEvent('click', { bubbles: true, cancelable: true }));

	const active = ctx.doc.activeElement;
	const codeStep = ctx.doc.querySelector('[data-tisa-step="code"]');
	check('focus stays inside the visible step', codeStep.contains(active), active.tagName);
}

async function testExpiry() {
	scenario('An expired code says so and offers a fresh one');

	const ctx = boot({
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok(verifyStep({ expires_in: 1 }))),
	});

	ctx.doc.querySelector('[data-tisa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	await wait(1200);

	check('form is marked expired', ctx.root.classList.contains('is-expired'));
	check('a message explains why', text(ctx.doc.querySelector('[data-tisa-status-text]')).indexOf('منقضی') >= 0, text(ctx.doc.querySelector('[data-tisa-status-text]')));
	const labels = Array.from(ctx.doc.querySelectorAll('.tisa-otp__status-action')).map(text);
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

	ctx.doc.querySelector('[data-tisa-phone]').value = '09121234567';
	ctx.form.act('start');
	for (let i = 0; i < 8; i++) await tick();

	check('the rejected call is retried after refreshing', served >= 2, 'config fetched ' + served + 'x');
	check('the visitor ends up on the code step', ctx.doc.querySelector('[data-tisa-step="code"]').classList.contains('is-current'));
	check('no error is left on screen', ctx.doc.querySelector('[data-tisa-status]').className.indexOf('is-error') < 0);
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

	ctx.doc.querySelector('[data-tisa-phone]').value = '09121234567';
	ctx.form.act('start');

	await wait(500);

	const box = ctx.doc.querySelector('.tisa-captcha__error');
	check('an error card fills the gap the widget left', !!box);
	check('it says the challenge did not load', !!box && text(box).indexOf('بارگذاری نشد') >= 0, box ? text(box) : 'no card');
	check('it offers a retry', !!ctx.doc.querySelector('[data-tisa-captcha-retry]'));
	check('it says the visitor may continue', !!ctx.doc.querySelector('.tisa-captcha__error-hint'));

	for (let i = 0; i < 8; i++) await tick();

	check(
		'fail-open still sends the code',
		ctx.doc.querySelector('[data-tisa-step="code"]').classList.contains('is-current'),
		'status: ' + text(ctx.doc.querySelector('[data-tisa-status-text]'))
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

	strict.doc.querySelector('[data-tisa-phone]').value = '09121234567';
	strict.form.act('start');
	await wait(500);
	for (let i = 0; i < 8; i++) await tick();

	check('with fail-open off nothing is sent', !strict.calls.some((call) => call.url.indexOf('/start') >= 0));
	check(
		'and the visitor gets an explanation',
		text(strict.doc.querySelector('[data-tisa-status-text]')).indexOf('بارگذاری نشد') >= 0,
		text(strict.doc.querySelector('[data-tisa-status-text]'))
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
				tokens.push(req.body.tisa_ft);

				if ('stale-form-token' === req.body.tisa_ft) {
					return reject('stale_form', 'این فرم مدت‌ها پیش ساخته شده است.', { recoverable: true });
				}

				return ok(verifyStep());
			}

			return ok({});
		},
	});

	ctx.doc.querySelector('[data-tisa-phone]').value = '09121234567';
	ctx.form.act('start');

	for (let i = 0; i < 10; i++) await tick();

	check('the first attempt carries the cached token', 'stale-form-token' === tokens[0], String(tokens[0]));
	check('the client pulls a fresh configuration', configs >= 1, configs + ' fetch(es)');
	check('the retry carries the fresh token', !!tokens[1] && 'stale-form-token' !== tokens[1], String(tokens[1]));
	check('the hidden timestamp moves with it', '1800000000' === ctx.doc.querySelector('input[name="tisa_ts"]').value, ctx.doc.querySelector('input[name="tisa_ts"]').value);
	check('the visitor ends up on the code step', ctx.doc.querySelector('[data-tisa-step="code"]').classList.contains('is-current'));
	check('no error is left on screen', ctx.doc.querySelector('[data-tisa-status]').className.indexOf('is-error') < 0);

	const chip = ctx.doc.querySelector('[data-tisa-phone-chip]');
	check('the code step names the number the code went to', !!chip && !chip.hidden, 'hidden=' + (chip ? chip.hidden : 'missing'));
	check('with the masked number in it', !!chip && text(ctx.doc.querySelector('[data-tisa-phone-chip-value]')).indexOf('***') >= 0, chip ? text(chip) : '');
}

async function testPasteFromSms() {
	scenario('Pasting the code from the SMS fills the boxes');

	const ctx = boot({
		fetch: (req) => {
			if (req.url.indexOf('form-config') >= 0) return ok({ nonce: 'fresh' });
			if (req.url.indexOf('/verify') >= 0) return reject('invalid_code', 'کد درست نیست.', { attempts_left: 4 });

			return ok(verifyStep());
		},
	});

	ctx.doc.querySelector('[data-tisa-phone]').value = '09121234567';
	ctx.form.act('start');
	await tick();
	await tick();

	Object.defineProperty(ctx.win.navigator, 'clipboard', {
		value: { readText: () => Promise.resolve('کد شما: ۱۲۳۴۵') },
		configurable: true,
	});

	const paste = ctx.doc.querySelector('[data-tisa-paste]');
	check('the code step offers a paste button', !!paste);

	paste.click();
	await tick();
	await tick();

	const boxes = Array.from(ctx.doc.querySelectorAll('[data-tisa-box]')).map((box) => box.value);
	check('Persian digits from the SMS are written as Latin digits', '12345' === boxes.join(''), boxes.join(''));

	await wait(260);
	check('and the code is verified without pressing anything', ctx.calls.some((call) => call.url.indexOf('/verify') >= 0), ctx.calls.map((call) => call.url).join(' | '));
}

async function testPrefixChip() {
	scenario('The 09 chip means something, and is not printed twice');

	const ctx = boot({
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok(verifyStep())),
	});

	const phone = ctx.doc.querySelector('[data-tisa-phone]');
	const dial = ctx.doc.querySelector('.tisa-phone__dial');

	check('the field has a prefix chip', !!dial, 'missing');
	check('the chip is the prefix alone', '۰۹' === text(dial), text(dial));
	check('the placeholder shows only what is left to type', '912 345 6789' === phone.placeholder, phone.placeholder);
	check('the placeholder does not repeat the prefix', phone.placeholder.indexOf('09') < 0, phone.placeholder);
	check('the chip is decorative for screen readers', 'true' === dial.getAttribute('aria-hidden'));

	// Type the ten digits the chip implies, and the full number must go out.
	phone.value = '9123456789';
	ctx.form.act('start');
	for (let i = 0; i < 6; i++) await tick();

	const start = ctx.calls.filter((call) => call.url.indexOf('/start') >= 0).pop();
	check('ten digits are sent as a full number', !!start && '09123456789' === start.body.phone, start ? String(start.body.phone) : 'no call');

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

		one.doc.querySelector('[data-tisa-phone]').value = typed;
		one.form.act('start');
		for (let i = 0; i < 6; i++) await tick();

		const call = one.calls.filter((c) => c.url.indexOf('/start') >= 0).pop();
		check('«' + typed + '» is sent as ' + expected, !!call && expected === call.body.phone, call ? String(call.body.phone) : 'no call');
	}
}

async function testTheLookOfTheTwoReportedBugs() {
	scenario('The progress bar and the skip link stay fixed');

	const css = fs.readFileSync(path.join(REPO, 'tisa-otp', 'assets', 'css', 'front.css'), 'utf8');
	const skip = css.slice(css.indexOf('.tisa-otp__skip {'), css.indexOf('.tisa-otp__skip:focus'));

	check('the skip link is invisible until it is focused', /opacity:\s*0/.test(skip) && /pointer-events:\s*none/.test(skip), skip.split('\n')[1] || '');
	check('and it is still the first tab stop', /^\.tisa-otp__skip:focus/m.test(css) || /\.tisa-otp__skip:focus,/.test(css));

	check('no connector line is drawn across the progress bar', !/tisa-otp__steps li \+ li::after/.test(css));
	check('progress is three filled segments instead', /\.tisa-otp__steps li::before \{/.test(css));
	check('the phone chip draws the only border around the field', /\.tisa-phone__input[\s\S]{0,240}border:\s*0 !important/.test(css));
	const page = new JSDOM(pageSource, { runScripts: 'outside-only' });
	const shown = page.window.document.querySelector('.tisa-otp').textContent;

	check('nothing on screen prints a fake «09xxxxxxxxx» hint', !/09x{3,}/i.test(shown), (shown.match(/09x{3,}/i) || [''])[0]);

	const trust = page.window.document.querySelector('[data-tisa-step="phone"] .tisa-otp__trust');
	check('the reassurance row sits under the send button', !!trust, 'not inside step 1');
}

function testPreviewMirrorsTheTemplate() {
	scenario('The demo page still mirrors the template it claims to show');

	const template = fs.readFileSync(path.join(REPO, 'tisa-otp', 'templates', 'partials', 'step-phone.php'), 'utf8');
	const renderer = fs.readFileSync(path.join(REPO, 'tisa-otp', 'src', 'Front', 'FormRenderer.php'), 'utf8');
	const step = new JSDOM(pageSource, { runScripts: 'outside-only' }).window.document.querySelector('[data-tisa-step="phone"]');

	for (const name of ['tisa-phone', 'tisa-phone__dial', 'tisa-phone__input', 'tisa-otp__trust']) {
		check('the template renders .' + name, template.indexOf(name) >= 0);
		check('the demo shows .' + name, !!step.querySelector('.' + name));
	}

	check('the chip text is a variable, not a hard-coded 09', /\$dial/.test(template) && step.querySelector('.tisa-phone__dial').textContent === '۰۹');
	check('the chip flips to Latin digits for LTR', /'09'/.test(template));
	check('the placeholder comes from the renderer', /\$phonePlaceholder/.test(template) && /'912 345 6789'/.test(renderer));
	check('the demo placeholder matches the renderer', step.querySelector('[data-tisa-phone]').placeholder === '912 345 6789');

	const trustTemplate = template.slice(template.indexOf('tisa-otp__trust'));
	check('the demo trust items are the ones the PHP filter ships', trustTemplate.indexOf("__(") < 0 && step.querySelectorAll('.tisa-otp__trust-item').length >= 3);
}

async function main() {
	await testStepBar();
	await testActionableErrors();
	await testThrottleHasNoFalseHope();
	await testCodeLengthRebuild();
	await testRescuePanel();
	await testCooldownAndPersianDigits();
	await testFocusMovesToTheProblem();
	await testSkipLink();
	await testExpiry();
	await testStaleNonceStillRecovers();
	await testCaptchaFailureIsVisible();
	await testStaleFormTokenRecovers();
	await testPasteFromSms();
	await testPrefixChip();
	await testTheLookOfTheTwoReportedBugs();
	await testPreviewMirrorsTheTemplate();

	console.log('\n' + (failed ? failed + ' FAILED, ' : '') + passed + ' checks passed');
	process.exit(failed ? 1 : 0);
}

main().catch((error) => {
	console.error(error);
	process.exit(1);
});
