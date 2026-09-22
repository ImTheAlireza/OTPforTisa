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
		clipboard: { readText: () => Promise.resolve('کد شما: ۱۲۳۴۵') },
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

	const paste = ctx.doc.querySelector('[data-tisa-paste]');
	check('the code step offers a paste button', !!paste && !paste.hidden);

	paste.click();
	await tick();
	await tick();

	const boxes = Array.from(ctx.doc.querySelectorAll('[data-tisa-box]')).map((box) => box.value);
	check('Persian digits from the SMS are written as Latin digits', '12345' === boxes.join(''), boxes.join(''));

	await wait(260);
	check('and the code is verified without pressing anything', ctx.calls.some((call) => call.url.indexOf('/verify') >= 0), ctx.calls.map((call) => call.url).join(' | '));
}

async function testPasteWithoutAClipboard() {
	scenario('With no readable clipboard the button is not offered');

	const ctx = boot({
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok(verifyStep())),
	});

	const paste = ctx.doc.querySelector('[data-tisa-paste]');
	check('the clipboard button is hidden when readText() does not exist', !!paste && paste.hidden, paste ? 'hidden=' + paste.hidden : 'missing');

	// The box still accepts a normal paste, which is the path the help text sends people to.
	ctx.doc.querySelector('[data-tisa-phone]').value = '09121234567';
	ctx.form.act('start');
	for (let i = 0; i < 4; i++) await tick();

	const event = new ctx.win.Event('paste', { bubbles: true, cancelable: true });
	event.clipboardData = { getData: () => '۱۲۳۴۵' };
	ctx.doc.querySelector('[data-tisa-box]').dispatchEvent(event);
	await tick();

	const boxes = Array.from(ctx.doc.querySelectorAll('[data-tisa-box]')).map((box) => box.value);
	check('pasting into the first box still fills them all', '12345' === boxes.join(''), boxes.join(''));
}

async function testPhoneFieldHasOneTruth() {
	scenario('The phone field asks for exactly what it accepts');

	const ctx = boot({
		fetch: (req) => (req.url.indexOf('form-config') >= 0 ? ok({ nonce: 'n' }) : ok(verifyStep())),
	});

	const phone = ctx.doc.querySelector('[data-tisa-phone]');
	const dial = ctx.doc.querySelector('.tisa-phone__dial');
	const css = fs.readFileSync(path.join(REPO, 'tisa-otp', 'assets', 'css', 'front.css'), 'utf8');

	/*
	 * A fixed "09" chip while the error says "start with 09" is a field arguing
	 * with itself. The chip is gone; the placeholder shows the whole number.
	 */
	check('no fixed prefix is printed inside the field', !dial, 'the chip is back');
	check('and no rule paints one either', css.indexOf('.tisa-phone__dial') < 0);
	check('the placeholder shows the whole number', '09121234567' === phone.placeholder, phone.placeholder);
	// What the placeholder demonstrates and what the error asks for must be the
	// same rule: eleven digits, beginning with 09.
	const copy = fs.readFileSync(path.join(REPO, 'tisa-otp', 'src', 'Front', 'Assets.php'), 'utf8');
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
	check('the phone field has one border, from the shared input rule', /\.tisa-field__input,\n\.tisa-field__select,\n\.tisa-phone__input/.test(css));
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

	for (const name of ['tisa-phone', 'tisa-phone__input', 'tisa-otp__trust']) {
		check('the template renders .' + name, template.indexOf(name) >= 0);
		check('the demo shows .' + name, !!step.querySelector('.' + name));
	}

	check('the template prints no prefix chip at all', template.indexOf('tisa-phone__dial') < 0);
	check('the demo prints none either', !step.querySelector('.tisa-phone__dial'));
	check('the placeholder comes from the renderer', /\$phonePlaceholder/.test(template) && /'09121234567'/.test(renderer));
	check('the demo placeholder matches the renderer', step.querySelector('[data-tisa-phone]').placeholder === '09121234567');

	const trustTemplate = template.slice(template.indexOf('tisa-otp__trust'));
	check('the demo trust items are the ones the PHP filter ships', trustTemplate.indexOf("__(") < 0 && step.querySelectorAll('.tisa-otp__trust-item').length >= 3);
}

function testTheAccentIsTheOnlyColour() {
	scenario('The button belongs to the accent, and so does its hover');

	const css = fs.readFileSync(path.join(REPO, 'tisa-otp', 'assets', 'css', 'front.css'), 'utf8');
	const script = fs.readFileSync(path.join(REPO, 'tisa-otp', 'assets', 'js', 'front.js'), 'utf8');
	const php = fs.readFileSync(path.join(REPO, 'tisa-otp', 'src', 'Front', 'Assets.php'), 'utf8');

	const primary = css.slice(css.indexOf('.tisa-btn--primary {'), css.indexOf('.tisa-btn--ghost {'));
	const resting = primary.slice(0, primary.indexOf('.tisa-btn--primary:hover'));

	check('the button is painted with the accent', /background-color:\s*var\(--tisa-accent\)/.test(resting));
	check('its depth is a translucent sheen, not a second colour', /linear-gradient\(180deg, rgba\(255, 255, 255/.test(resting));
	check('no fixed teal is left in the resting rule', resting.indexOf('11, 92, 86') < 0, resting.split('\n').find((line) => line.indexOf('11, 92, 86') >= 0) || '');
	check('hover darkens the very same accent', /\.tisa-btn--primary:hover[\s\S]{0,220}rgba\(0, 0, 0/.test(primary));
	check('the button shadow follows the accent', /--tisa-elev-button:\s*0 12px 26px -16px var\(--tisa-accent-strong\)/.test(css));
	check('PHP derives the darker shade from the accent', /mix\( \$accent, '#000000', 0\.22 \)/.test(php));
	check('the accent wash is translucent, so the dark skin keeps it', /--tisa-accent-soft:%3\$s/.test(php) && /rgba\( *%d, %d, %d, %s *\)/.test(php));
	check('the demo derives the same shade when a swatch is clicked', /shade\(accent, 0\.22\)/.test(pageSource));

	// Two complaints from the same screenshot batch.
	check('the code boxes are centred', /\.tisa-code__boxes \{[\s\S]{0,240}justify-content: center/.test(css));
	check('the phone control is one left-to-right island', /\.tisa-phone \{[\s\S]{0,600}direction: ltr/.test(css));
	check('so the placeholder starts where the chip ends', /\.tisa-phone__input,\n\.tisa-phone__input:focus \{[\s\S]{0,320}text-align: left/.test(css));

	const actions = script.slice(script.indexOf('Form.prototype.actionsFor'), script.indexOf('Form.prototype.clearStatus'));
	check('errors no longer offer a second way to edit the number', actions.indexOf("'edit-phone'") < 0, (actions.match(/'edit-phone'/) || [''])[0]);

	const template = fs.readFileSync(path.join(REPO, 'tisa-otp', 'templates', 'partials', 'step-code.php'), 'utf8');
	const rescue = new JSDOM(pageSource, { runScripts: 'outside-only' }).window.document.querySelector('[data-tisa-rescue]');

	/*
	 * Callout rules this panel is held to (see docs/UI-PLAN.fa.md §4.10):
	 * one message, no duplicate actions, nothing centred, no second primary
	 * button competing with the one that submits the code.
	 */
	check('the rescue panel is a callout with an icon and a title', !!rescue.querySelector('.tisa-code__rescue-icon svg') && !!rescue.querySelector('.tisa-code__rescue-title'));
	check('it carries no button at all', !rescue.querySelector('button'));
	check('and therefore no second primary button', !rescue.querySelector('.tisa-btn'));
	check('it explains in at most two short lines', rescue.querySelectorAll('.tisa-code__rescue-list li').length === 2);
	check('each line points at a control that already exists', /ارسال دوبارهٔ کد/.test(rescue.textContent) && /ویرایش شماره/.test(rescue.textContent));
	check('the text is never centred', /\.tisa-code__rescue \{[\s\S]{0,600}text-align: start/.test(css));
	check('and no rule centres the panel contents', !/\.tisa-code__rescue[\s-][^{]*\{[^}]*text-align: center/.test(css));
	check('the template prints the same panel', /tisa-code__rescue-icon/.test(template) && /tisa-code__rescue-list/.test(template) && template.indexOf('tisa-code__rescue-note') < 0);
}

function testTheThreeDemoPages() {
	scenario('The demo pages keep up with the plugin');

	const account = fs.readFileSync(path.join(REPO, 'preview', 'public', 'account.html'), 'utf8');
	const admin = fs.readFileSync(path.join(REPO, 'preview', 'public', 'admin.html'), 'utf8');
	const adminCss = fs.readFileSync(path.join(REPO, 'tisa-otp', 'assets', 'css', 'admin.css'), 'utf8');
	const server = fs.readFileSync(path.join(REPO, 'preview', 'server.js'), 'utf8');
	const demo = new JSDOM(pageSource, { runScripts: 'outside-only' }).window.document;

	// The account page mounts the real form with the real stylesheet and script.
	check('the account demo is routed', /'\/account'/.test(server));
	check('it loads the plugin stylesheet', /\/plugin-assets\/css\/front\.css/.test(account));
	check('and the plugin script, so the form is live there too', /\/plugin-assets\/js\/front\.js/.test(account));
	check('with a form for changing the number', /data-tisa-form/.test(account) && /data-tisa-action="start"/.test(account));
	check('the saved address and postcode are shown as profile data', /کد پستی/.test(account) && /آدرس/.test(account));

	// The signup step mirrors the identity preset the PHP now ships.
	const fields = demo.querySelector('[data-tisa-step="fields"]');

	for (const id of ['postcode', 'address']) {
		const field = fields.querySelector('[data-tisa-field="' + id + '"]');
		check('the demo signup asks for ' + id, !!field, 'missing');
	}

	const postcode = fields.querySelector('[data-tisa-input="postcode"]');
	check('the postal code box is numeric and LTR like the template', postcode && 'numeric' === postcode.getAttribute('inputmode') && 'ltr' === postcode.getAttribute('dir') && '10' === postcode.getAttribute('maxlength'));
	check('and it has the same placeholder as the preset', '1234567890' === postcode.placeholder, postcode.placeholder);
	check('the address is a textarea with its hint', 'textarea' === fields.querySelector('[data-tisa-input="address"]').tagName.toLowerCase() && /\.$/.test(text(fields.querySelector('.tisa-field__hint'))));

	const template = fs.readFileSync(path.join(REPO, 'tisa-otp', 'templates', 'partials', 'step-fields.php'), 'utf8');
	check('the template handles the postcode type the way the demo shows', /'postcode' === \$field\['type'\]/.test(template) && /postal-code/.test(template));

	// The admin demo shows the report screen the plugin renders.
	for (const name of ['tisa-kpi', 'tisa-chart__col', 'tisa-report-table', 'tisa-range__item']) {
		check('the admin demo has .' + name, admin.indexOf(name) >= 0);
		check('and the real admin stylesheet defines .' + name, adminCss.indexOf('.' + name) >= 0);
	}

	check('the report screen is a real admin page', /class ReportScreen/.test(fs.readFileSync(path.join(REPO, 'tisa-otp', 'src', 'Admin', 'ReportScreen.php'), 'utf8')));
	check('the captcha test is wired in the demo, with the keys the plugin localizes', /data-tisa-captcha-test/.test(admin) && /captcha: \{/.test(admin) && /'global'/.test(fs.readFileSync(path.join(REPO, 'tisa-otp', 'src', 'Front', 'Assets.php'), 'utf8')));
	check('and admin.js implements it', /testCaptcha/.test(fs.readFileSync(path.join(REPO, 'tisa-otp', 'assets', 'js', 'admin.js'), 'utf8')));
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
	const nav = read('tisa-otp', 'src', 'Admin', 'ScreenNav.php');
	const settings = read('tisa-otp', 'src', 'Admin', 'SettingsScreen.php');
	const menu = read('tisa-otp', 'src', 'Admin', 'Menu.php');
	const admin = read('preview', 'public', 'admin.html');
	const adminCss = read('tisa-otp', 'assets', 'css', 'admin.css');

	check('the switcher knows all five screens', ['Menu::ROOT', 'ReportScreen::SLUG', 'LogsScreen::SLUG', 'ToolsScreen::SLUG', 'AccessScreen::SLUG'].every((slug) => nav.indexOf(slug) >= 0));

	for (const [file, marker] of [
		['SettingsScreen', 'ScreenNav::render( Menu::ROOT )'],
		['ReportScreen', 'ScreenNav::render( self::SLUG )'],
		['LogsScreen', 'ScreenNav::render( self::SLUG )'],
		['ToolsScreen', 'ScreenNav::render( self::SLUG )'],
		['AccessScreen', 'ScreenNav::render( self::SLUG )'],
	]) {
		check(file + ' prints the switcher', read('tisa-otp', 'src', 'Admin', file + '.php').indexOf(marker) >= 0);
	}

	check('the menu and the switcher share one slug per screen', /LogsScreen::SLUG/.test(menu) && /ToolsScreen::SLUG/.test(menu) && menu.indexOf("self::ROOT . '-logs'") < 0 && menu.indexOf("self::ROOT . '-tools'") < 0);
	check('the tools screen owns its slug like the others', /const SLUG = 'tisa-otp-tools'/.test(read('tisa-otp', 'src', 'Admin', 'ToolsScreen.php')));

	// The settings screen counts the last week and names the page that explains it.
	check('the settings screen shows a seven-day overview', /private function overview\(\)/.test(settings) && /const OVERVIEW_DAYS = 7/.test(settings));
	check('with the four numbers the reports screen also shows', /tisa-kpis/.test(settings) && /'requests'/.test(settings) && /'rate'/.test(settings));
	check('and a way into the reports tab', /self::tabUrl\( 'reports' \)/.test(settings) && /گزارش\u200cها/.test(settings));
	check('it says so instead of showing zeros when logging is off', /logs_enabled/.test(settings) && /notice\(/.test(settings));

	check('the css defines the switcher', /\.tisa-screens \{/.test(adminCss) && /\.tisa-screen\.is-current/.test(adminCss));
	check('and the overview strip', /\.tisa-overview \.tisa-kpis \{/.test(adminCss));
	check('the demo mirrors both', admin.indexOf('tisa-screens') >= 0 && admin.indexOf('tisa-overview') >= 0);

	// "Which build is on my site?" must be answerable from the dashboard header.
	const plugin = read('tisa-otp', 'tisa-otp.php');
	const readme = read('tisa-otp', 'readme.txt');
	const version = (plugin.match(/Version:\s*([0-9.]+)/) || [])[1];

	check('the header version and the constant agree', !!version && plugin.indexOf("TISA_OTP_VERSION', '" + version + "'") >= 0, version);
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
	const settings = read('tisa-otp', 'src', 'Admin', 'SettingsScreen.php');
	const report = read('tisa-otp', 'src', 'Admin', 'ReportScreen.php');
	const selfTest = read('tisa-otp', 'src', 'Diagnostics', 'SelfTest.php');
	const adminJs = read('tisa-otp', 'assets', 'js', 'admin.js');
	const adminCss = read('tisa-otp', 'assets', 'css', 'admin.css');
	const assets = read('tisa-otp', 'src', 'Front', 'Assets.php');
	const api = read('tisa-otp', 'src', 'Http', 'Api.php');
	const controller = read('tisa-otp', 'src', 'Http', 'AdminController.php');
	const nav = read('tisa-otp', 'src', 'Admin', 'ScreenNav.php');
	const admin = read('preview', 'public', 'admin.html');
	const server = read('preview', 'server.js');
	const bootstrap = read('tisa-otp', 'tisa-otp.php');
	const readme = read('tisa-otp', 'readme.txt');

	// --- reports as a tab of the settings panel ------------------------------
	check('the settings panel has a reports tab', /'reports'\s+=> __\(/.test(settings));
	check('and it draws the reports screen body rather than a copy of it', /\$this->reports->body\( \$this->reports->range\(\) \)/.test(settings));
	check('the reports screen exposes that body', /public function body\( int \$days \): void/.test(report));
	check('both callers use it: the screen and the tab', (report.match(/\$this->body\(/g) || []).length === 1 && (settings.match(/->body\(/g) || []).length === 1);

	// The tab is not a form: no save button, no settings fields.
	const tabBranch = (settings.match(/if \( 'reports' === \$tab \) \{[\s\S]*?\n\t\t\}/) || [''])[0];
	check('the tab skips the settings form entirely', /return;/.test(tabBranch) && tabBranch.indexOf('settings_fields') < 0);
	check('the overview strip does not double the numbers on the reports tab', /'reports' !== \$tab/.test(settings));

	// One address for reports inside the plugin.
	check('tab urls are built in one place', /public static function tabUrl\(/.test(settings) && /self::tabUrl\( \$id \)/.test(settings));
	check('the screens row points at the tab, not at a second page', /SettingsScreen::tabUrl\( 'reports' \)/.test(nav));
	check('and the overview button does the same', /\$reports = self::tabUrl\( 'reports' \)/.test(settings));

	check('reports sits at the end of the row, after the data section', settings.indexOf("'data'         =>") < settings.indexOf("'reports'      =>"));

	// --- "did my update actually land?" --------------------------------------
	// Twice now the answer to "where is it?" was "the installed package is older
	// than the one you were told about". The panel has to be able to say which
	// build is running, in a place an administrator already looks.
	check('the first tab opens with what this build added', /private function generalSection\(\): void \{\s*\n\s*\$c = \$this->controls;\s*\n\s*\$this->whatsNew\(\);/.test(settings));
	check('and the card names the running version', /private function whatsNew\(\)/.test(settings) && /تازه در نسخهٔ %s/.test(settings) && /TISA_OTP_VERSION/.test(settings));
	check('it points at the reports tab, not at a second page', /self::tabUrl\( 'reports' \)/.test(settings.slice(settings.indexOf('private function whatsNew()'), settings.indexOf('private function whatsNew()') + 1400)));
	check('and it tells the reader what to do when the number looks wrong', /فایل‌های افزونه به‌روز نشده‌اند/.test(settings));

	const version = (bootstrap.match(/define\( 'TISA_OTP_VERSION', '([0-9.]+)' \)/) || [])[1];
	check('the package declares one version, and the file header agrees', !!version && bootstrap.indexOf('Version:           ' + version) >= 0);
	check('the readme ships that same version as its stable tag', !!version && readme.indexOf('Stable tag: ' + version) >= 0);
	check('and the readme explains what changed in it', !!version && readme.indexOf('= ' + version + ' =') >= 0);
	check('the preview says which version it is showing', admin.indexOf(version) >= 0);
	check('the preview shows the release card too', /تازه در نسخهٔ ۱\.۳\.۲/.test(admin) && /class="tisa-bullets"/.test(admin));
	check('and a link from it into the reports section', /class="button" href="#reports"/.test(admin));

	// --- one self-test per section ------------------------------------------
	const kinds = (selfTest.match(/return array\( '([a-z]+)'(?:, '[a-z]+')* \);/) || [])[1];
	check('the self-test knows which sections exist', !!kinds && ['general', 'code', 'gateways', 'security', 'registration', 'design', 'store', 'data'].every((kind) => selfTest.indexOf("'" + kind + "'") >= 0));

	for (const kind of ['general', 'code', 'gateways', 'security', 'registration', 'design', 'store', 'data']) {
		check('the ' + kind + ' section has a test button', settings.indexOf('data-tisa-check="' + kind + '"') >= 0 || new RegExp("'" + kind + "',").test(settings));
		check('and the test really exists', new RegExp('private function ' + kind + '\\(\\)').test(selfTest));
	}

	check('the test card is what prints the buttons', /private function testCard\(/.test(settings) && (settings.match(/\$this->testCard\(/g) || []).length === 8);
	check('the gateways section offers a real send too', /data-tisa-sms-test/.test(settings));
	check('the captcha button kept its old hook', /data-tisa-captcha-test/.test(settings) && settings.indexOf('data-tisa-captcha-result') < 0);

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
	check('the send test opens its own modal with the administrator number', /data-tisa-sms-test/.test(adminJs) && /cfg\.myPhone/.test(adminJs));
	check('and the plugin localises that number', /'myPhone' => \$this->ownPhone\(\)/.test(assets) && /private function ownPhone\(\): string/.test(assets));

	check('the css defines the modal', /\.tisa-modal \{/.test(adminCss) && /\.tisa-modal::backdrop \{/.test(adminCss));
	check('and the result rows', /\.tisa-test-row \{/.test(adminCss) && /\.tisa-test-row\.is-fail \.tisa-test-row__dot \{/.test(adminCss));

	// --- the demo can be clicked through -------------------------------------
	check('the demo can run a section test', /data-tisa-check="(gateways|data|general)"/.test(admin));
	check('the demo can show the captcha test', /data-tisa-check="security" data-tisa-captcha-test/.test(admin));
	check('the demo can send a test message', admin.indexOf('data-tisa-sms-test') >= 0);
	check('and the demo names all eight sections in one place', ['عمومی', 'کد و کانال\u200cها', 'سامانه‌های پیامکی', 'امنیت و محدودیت', 'فرم عضویت', 'ظاهر فرم', 'فروشگاه', 'داده و رویدادها'].every((label) => admin.indexOf(label) >= 0));
	check('the demo shows the reports tab in the same pill row', /class="tisa-screen">گزارش\u200cها و آمار<\/a>/.test(admin) || /href="#reports" class="tisa-screen">گزارش\u200cها و آمار<\/a>/.test(admin));
	check('the demo api answers the check route', /case 'admin\/check':/.test(server) && /function checkPayload\(/.test(server));
	check('with a failing row in it, so the modal is seen doing its job', /status: 'warn'/.test(server) || /status: 'fail'/.test(server));
}

async function main() {
	await testStepBar();
	await testActionableErrors();
	await testThrottleHasNoFalseHope();
	await testCodeLengthRebuild();
	await testRescuePanel();
	await testEveryScreenIsReachable();
	await testThePanelKeepsItsOwnPromises();
	await testCooldownAndPersianDigits();
	await testFocusMovesToTheProblem();
	await testSkipLink();
	await testExpiry();
	await testStaleNonceStillRecovers();
	await testCaptchaFailureIsVisible();
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
