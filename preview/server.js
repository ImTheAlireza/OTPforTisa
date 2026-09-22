/**
 * Preview harness for the Tisa OTP plugin UI.
 *
 * There is no PHP in this sandbox, so the plugin itself cannot run. This server
 * serves the plugin's REAL front.css / front.js / admin.css / admin.js straight
 * out of the repository, wraps them in hand-written markup that mirrors what the
 * PHP templates print, answers the REST routes with the same JSON contract
 * `src/Http/Api.php` uses ({success, data} / {success:false, code, message, data}),
 * and builds an installable tisa-otp.zip on demand.
 *
 * Run with:  node preview/server.js     (then open http://localhost:4173)
 */
'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const PORT = process.env.PORT ? Number(process.env.PORT) : 4173;
const HOST = '0.0.0.0';
const REPO = path.join(__dirname, '..');
const PLUGIN = path.join(REPO, 'tisa-otp');
const PUBLIC_DIR = path.join(__dirname, 'public');
const PLUGIN_ASSETS = path.join(PLUGIN, 'assets');
const ZIP_PATH = path.join(REPO, 'tisa-otp.zip');

const MIME = {
	'.html': 'text/html; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.js': 'application/javascript; charset=utf-8',
	'.json': 'application/json; charset=utf-8',
	'.svg': 'image/svg+xml',
	'.png': 'image/png',
	'.jpg': 'image/jpeg',
	'.zip': 'application/zip',
};

/* --------------------------------------------------------- package builder */

function buildZip() {
	// Always rebuilt so the download can never lag behind the sources.
	try {
		fs.unlinkSync(ZIP_PATH);
	} catch (error) {
		// Not there yet — fine.
	}

	execFileSync(
		'python3',
		['-c', "import shutil; shutil.make_archive('tisa-otp', 'zip', '.', 'tisa-otp')"],
		{ cwd: REPO }
	);

	return fs.statSync(ZIP_PATH).size;
}

/* ------------------------------------------------------------- mock backend */

const attempts = new Map();
let importCursor = 0;
let importTotal = 240;
let nonceSeq = 0;
let tokenSeq = 0;

/**
 * Every /form-config call hands out a different nonce, like wp_create_nonce()
 * does for a fresh page load. The demo can then prove the client picks it up.
 */
function freshNonce() {
	nonceSeq += 1;

	return 'demo-nonce-' + nonceSeq;
}

/** Same idea for the signed form token minted by Support\FormToken. */
function freshFormToken() {
	tokenSeq += 1;

	return 'demo-form-token-' + tokenSeq;
}

const demoLabels = {
	send: 'دریافت کد تأیید',
	verify: 'ورود به حساب',
	resend: 'ارسال دوباره کد',
	editPhone: 'ویرایش شماره',
};

const demoI18n = {
	codePlaceholder: 'کد ۵ رقمی',
	sending: 'در حال ارسال کد…',
	checking: 'در حال بررسی کد…',
	creating: 'در حال ساخت حساب…',
	resendIn: 'ارسال دوباره تا {s} ثانیه',
	network: 'خطای شبکه. لطفاً دوباره تلاش کنید.',
	invalidPhone: 'شماره موبایل معتبر نیست.',
	fillFields: 'لطفاً فیلدهای ستاره‌دار را کامل کنید.',
	requiredField: 'این فیلد الزامی است.',
	invalidEmail: 'قالب ایمیل معتبر نیست.',
	incompleteCode: 'کد را کامل وارد کنید.',
	redirecting: 'در حال انتقال…',
	timeout: 'پاسخ سرور به‌موقع نرسید. لطفاً دوباره تلاش کنید.',
	offline: 'اتصال اینترنت برقرار نیست.',
	otpFilled: 'کد از پیامک خوانده شد.',
	resendReady: 'اکنون می‌توانید کد را دوباره ارسال کنید.',
};

/** Mirrors FormRenderer::clientConfig(). */
function formConfig() {
	return {
		restUrl: '/mock/tisa-otp/v1/',
		nonce: freshNonce(),
		formToken: freshFormToken(),
		renderedAt: Math.floor(Date.now() / 1000),
		configUrl: '/mock/tisa-otp/v1/form-config',
		cacheMode: 'auto',
		autoVerify: true,
		webOtp: false,
		timeoutMs: 15000,
		enabled: true,
		codeLength: 5,
		codeInput: 'boxes',
		cooldown: 60,
		ttl: 120,
		channel: 'sms',
		skin: 'line',
		captcha: { enabled: false, provider: 'none' },
		labels: demoLabels,
		i18n: demoI18n,
		fields: identityFields,
		headings,
		flow: 'fields_then_code',
		register: true,
	};
}

function normalizePhone(raw) {
	let digits = String(raw || '').replace(/[^\d+]/g, '');
	digits = digits.replace(/^\+/, '');
	if (digits.startsWith('0098')) digits = digits.slice(4);
	if (digits.startsWith('98') && digits.length === 12) digits = digits.slice(2);
	if (!digits.startsWith('0') && digits.length === 10) digits = '0' + digits;
	return digits;
}

function mask(phone) {
	const p = normalizePhone(phone);
	return p.length === 11 ? p.slice(0, 4) + '***' + p.slice(-3) : p;
}

function ok(data) {
	return { status: 200, body: { success: true, data } };
}

function fail(code, message, payload) {
	// Rejection::make() defaults to HTTP 200 — the client must read `success`.
	return { status: 200, body: { success: false, code, message, data: payload || {} } };
}

const identityFields = [
	{ id: 'first_name', label: 'نام', type: 'text', required: true, placeholder: '', hint: '', width: 'half', options: [] },
	{ id: 'last_name', label: 'نام خانوادگی', type: 'text', required: true, placeholder: '', hint: '', width: 'half', options: [] },
	{ id: 'user_email', label: 'ایمیل', type: 'email', required: false, placeholder: 'example@mail.com', hint: '', width: 'full', options: [] },
	{ id: 'city', label: 'شهر', type: 'text', required: false, placeholder: '', hint: '', width: 'full', options: [] },
];

const headings = {
	form: 'ورود یا عضویت',
	formHint: 'شماره موبایل خود را وارد کنید تا کد تأیید برایتان ارسال شود.',
	register: 'تکمیل اطلاعات',
	regHint: 'برای ساخت حساب کاربری، اطلاعات زیر را کامل کنید.',
};

function verifyPayload(phone, extra) {
	return Object.assign({
		step: 'verify',
		scope: 'login',
		message: 'کد ۵ رقمی پیامک شد. تا ۲ دقیقه معتبر است.',
		masked: mask(phone),
		channel: 'sms',
		via: 'smsir',
		cooldown: 60,
		expires_in: 120,
		code_length: 5,
		headings,
	}, extra || {});
}

function importState(status, note) {
	const migrated = status === 'running' ? Math.round(importCursor * 0.82) : Math.round(importTotal * 0.82);
	return {
		job_id: 'demo-job',
		status: status + (note || ''),
		total: importTotal,
		cursor: status === 'rolled_back' ? 0 : importCursor,
		migrated: status === 'rolled_back' ? 0 : migrated,
		skipped: status === 'rolled_back' ? 0 : 21,
		conflicts: status === 'rolled_back' ? 0 : 8,
		errors: status === 'done' ? [{ user_id: 117, reason: 'شماره نامعتبر بود', mask: '0935***118' }] : [],
		csv: status === 'done' ? '12,migrated,,0912***567\n18,migrated,,0935***220' : '',
		done: status !== 'running',
	};
}

/* ------------------------------------------------------- admin screen mock */

/** Mirrors AdminController::doctor(). */
function doctorPayload() {
	return {
		gateways: {
			smsir: {
				label: 'SMS.ir',
				ready: true,
				missing: [],
				issues: [],
				notes: ['الگوی «کد ورود» انتخاب شده است.'],
				mode: 'pattern',
				health_text: 'سالم — آخرین ارسال موفق',
				plan: { mode: 'pattern', sender: '3000505', template: '123456', endpoint: '' },
			},
			kavenegar: {
				label: 'کاوه‌نگار',
				ready: false,
				missing: ['کلید API'],
				issues: ['کلید API خالی است؛ این سامانه در جابه‌جایی خودکار شرکت نمی‌کند.'],
				notes: [],
				mode: 'text',
				health_text: 'بدون تاریخچه',
				plan: { mode: 'text', sender: '', template: '', endpoint: '' },
			},
		},
		channels: {
			sms: { label: 'پیامک', available: true, reason: '' },
			email: { label: 'ایمیل', available: false, reason: 'سامانهٔ ایمیل تنظیم نشده است.' },
		},
		captcha: {
			provider: 'arcaptcha',
			label: 'ARCaptcha',
			enabled: true,
			kind: 'widget',
			trigger: 'always',
			failOpen: true,
			halfConfigured: false,
			scripts: ['https://widget.arcaptcha.ir/1/api.js', 'https://arcaptcha.ir/1/api.js'],
		},
		cache_mode: 'auto',
		webotp: false,
		cron: Math.floor(Date.now() / 1000) + 900,
		debug: false,
		form_token: 'demo-doctor-token',
	};
}

function probePayload(service) {
	const urls = {
		smsir: 'https://api.sms.ir/v1/send/bulk',
		kavenegar: 'https://api.kavenegar.com/v1/lookup',
		captcha: 'https://widget.arcaptcha.ir/1/api.js',
		wordpress: 'https://api.wordpress.org/plugins/info/1.0/tisa-otp.json',
	};

	if (service === 'kavenegar') {
		return {
			service,
			url: urls[service],
			ok: false,
			ms: 812,
			status: 0,
			error: 'http_request_failed',
			message: 'دسترسی به بیرون برقرار نشد. اگر WP_HTTP_BLOCK_EXTERNAL روشن است، این دامنه را استثنا کنید.',
		};
	}

	return {
		service,
		url: urls[service] || urls.wordpress,
		ok: true,
		ms: 143,
		status: 200,
		error: '',
		message: 'دسترسی برقرار است.',
	};
}

function handleRest(route, body, headers) {
	const phone = normalizePhone(body.phone);

	// A page cache keeps serving the nonce that was printed into the HTML long
	// after WordPress stopped accepting it. The client notices, pulls a fresh one
	// from /form-config and retries — that round trip is part of the demo.
	if ('stale-nonce-from-cache' === (headers || {})['x-wp-nonce']) {
		return {
			status: 403,
			body: { code: 'rest_cookie_invalid_nonce', message: 'nonce نامعتبر است.', data: { status: 403 } },
		};
	}

	// A page cache with a long TTL also freezes the signed form token. The guard
	// says `stale_form` with `recoverable`, the client pulls /form-config and
	// sends once more — exactly what a 24-hour cache does on a real site.
	if ('stale-form-token' === body.tisa_ft) {
		return fail('stale_form', 'این فرم مدت‌ها پیش ساخته شده است. یک بار دیگر تلاش کنید.', { recoverable: true, reason: 'stale' });
	}

	switch (route) {
		case 'form-config':
			return ok(formConfig());

		case 'admin/doctor':
			return ok(doctorPayload());

		case 'admin/probe':
			return ok(probePayload(String(body.service || 'wordpress')));

		case 'admin/summary':
			return ok({ sent_today: 42, blocked: 3, failed: 1, channels: { sms: 40, email: 2 } });

		case 'admin/throttle-reset':
			return ok({ message: 'شمارنده‌های محدودیت پاک شد.', cooldowns: 4, locks: 1 });

		case 'admin/test':
			if ('09120000000' === phone) {
				// A gateway that answers 401 — the trace is the whole point.
				return fail('delivery_failed', 'پیامک ارسال نشد: کلید API نامعتبر است.', {
					gateway: 'smsir',
					error_code: 'unauthorized',
					trace: [
						{ gateway: 'smsir', sent: false, error_code: 'unauthorized', status: 401, message: 'کلید API نامعتبر است.' },
						{ gateway: 'kavenegar', sent: false, error_code: 'missing_key', status: 0, message: 'کلید API تنظیم نشده است.' },
					],
					plan: { mode: 'pattern', sender: '3000505', template: '123456' },
				});
			}

			return ok({
				sent: true,
				via: 'smsir',
				channel: 'sms',
				masked: mask(phone),
				message: 'کد آزمایشی ارسال شد.',
				plan: { mode: 'pattern', sender: '3000505', template: '123456' },
				trace: [{ gateway: 'smsir', sent: true, error_code: '', status: 200, message: '' }],
			});

		case 'start':
			if (phone === '09129999999') {
				// A gateway that never answers in time: demos the request timeout.
				return Object.assign(ok(verifyPayload(phone)), { delay: 4000 });
			}

			if (phone === '09000000000') {
				return fail('cooldown', 'تا ۴۵ ثانیهٔ دیگر می‌توانید کد تازه بگیرید.', { retry_after: 45 });
			}
			if (phone === '09111111111') {
				return fail('captcha_failed', 'اعتبارسنجی کپچا ناموفق بود. لطفاً دوباره تلاش کنید.', { captcha_required: true });
			}
			if (phone === '09121234567') {
				return ok(verifyPayload(phone));
			}
			return ok({
				step: 'register_form',
				scope: 'register',
				flow: 'fields_then_code',
				message: 'برای ساخت حساب کاربری، اطلاعات زیر را کامل کنید.',
				fields: identityFields,
				headings,
				masked: mask(phone),
			});

		case 'code':
			if (phone === '09000000000') {
				return fail('cooldown', 'تا ۴۵ ثانیهٔ دیگر می‌توانید کد تازه بگیرید.', { retry_after: 45 });
			}
			return ok(verifyPayload(phone, { message: 'کد تازه پیامک شد.' }));

		case 'verify': {
			const code = String(body.code || '');
			if (code === '12345') {
				attempts.delete(phone);
				return ok({
					step: 'signed_in',
					user_id: 42,
					redirect: '',
					display_name: 'کاربر تیسا',
					message: body.draft_token ? 'حساب شما ساخته شد. در حال انتقال…' : 'خوش آمدید. در حال انتقال…',
				});
			}

			const left = (attempts.get(phone) === undefined ? 5 : attempts.get(phone)) - 1;
			attempts.set(phone, Math.max(left, 0));

			if (left <= 0) {
				return fail('expired_code', 'این کد دیگر معتبر نیست.', { attempts_left: 0 });
			}

			return fail('invalid_code', 'کد درست نیست.', { attempts_left: left });
		}

		case 'register': {
			const fields = body.fields || {};
			const errors = {};

			if (!String(fields.first_name || '').trim()) errors.first_name = 'نام را وارد کنید.';
			if (!String(fields.last_name || '').trim()) errors.last_name = 'نام خانوادگی را وارد کنید.';
			if (fields.user_email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(fields.user_email)) {
				errors.user_email = 'ایمیل معتبر نیست.';
			}

			if (Object.keys(errors).length) {
				return fail('invalid_fields', 'فیلدهای ستاره‌دار را کامل کنید.', { errors });
			}

			return ok(verifyPayload(phone, {
				scope: 'register',
				draft_token: 'demo-draft-token',
				flow: 'fields_then_code',
				message: 'کد تأیید برای شماره شما ارسال شد.',
			}));
		}

		case 'admin/test':
			if (!/^09\d{9}$/.test(phone)) {
				return fail('invalid_phone', 'شماره موبایل معتبر نیست.');
			}
			return ok({
				sent: true,
				via: 'smsir',
				channel: body.channel || 'sms',
				masked: mask(phone),
				message: 'کد آزمایشی ارسال شد. اگر نرسید، رویدادها را ببینید.',
			});

		case 'admin/throttle-reset':
			return ok({ cleared: 12, codes: 3, message: 'شمارنده‌ها و کدهای منقضی پاک شدند.' });

		case 'admin/import/start':
			importCursor = 0;
			importTotal = 240;
			return ok(importState('running', body.dry_run ? ' (آزمایشی)' : ''));

		case 'admin/import/step':
			importCursor = Math.min(importTotal, importCursor + 100);
			return ok(importState(importCursor >= importTotal ? 'done' : 'running'));

		case 'admin/import/undo':
			return ok(importState('rolled_back'));

		case 'admin/summary':
			return ok({
				logs: { total: 1284, errors: 17, last_day: 96 },
				throttle: { cooldown_rows: 4, quota_rows: 31, per_phone: 5, per_ip: 12, per_ip_daily: 60 },
				accounts: 318,
			});

		default:
			return fail('rest_no_route', 'مسیر پیدا نشد: ' + route);
	}
}

/* -------------------------------------------------------------- http server */

function send(res, status, body, headers) {
	res.writeHead(status, Object.assign({ 'Cache-Control': 'no-store' }, headers || {}));
	res.end(body);
}

function sendJson(res, payload) {
	send(res, payload.status, JSON.stringify(payload.body), { 'Content-Type': 'application/json; charset=utf-8' });
}

function readBody(req) {
	return new Promise((resolve) => {
		let raw = '';
		req.on('data', (chunk) => { raw += chunk; });
		req.on('end', () => {
			try {
				resolve(raw ? JSON.parse(raw) : {});
			} catch (error) {
				resolve({});
			}
		});
	});
}

function serveStatic(res, filePath) {
	fs.readFile(filePath, (error, data) => {
		if (error) {
			send(res, 404, 'Not found: ' + filePath, { 'Content-Type': 'text/plain; charset=utf-8' });
			return;
		}

		send(res, 200, data, { 'Content-Type': MIME[path.extname(filePath)] || 'application/octet-stream' });
	});
}

const server = http.createServer(async (req, res) => {
	const url = new URL(req.url, 'http://' + (req.headers.host || 'localhost'));
	const pathname = decodeURIComponent(url.pathname);

	if (pathname === '/download/tisa-otp.zip' || pathname === '/tisa-otp.zip') {
		try {
			const size = buildZip();
			send(res, 200, fs.readFileSync(ZIP_PATH), {
				'Content-Type': 'application/zip',
				'Content-Length': String(size),
				'Content-Disposition': 'attachment; filename="tisa-otp.zip"',
				'X-Tisa-Built': new Date().toISOString(),
			});
		} catch (error) {
			send(res, 500, 'Could not build the package: ' + error.message, { 'Content-Type': 'text/plain; charset=utf-8' });
		}
		return;
	}

	if (pathname.startsWith('/mock/tisa-otp/v1/')) {
		const route = pathname.replace('/mock/tisa-otp/v1/', '').replace(/\/$/, '');
		const body = req.method === 'POST' ? await readBody(req) : {};
		const result = handleRest(route, body, req.headers);

		if (result.delay) {
			await new Promise((resolve) => setTimeout(resolve, result.delay));
		}

		sendJson(res, result);
		return;
	}

	if (pathname.startsWith('/plugin-assets/')) {
		const target = path.join(PLUGIN_ASSETS, pathname.replace('/plugin-assets/', ''));
		if (!target.startsWith(PLUGIN_ASSETS)) {
			send(res, 403, 'Forbidden');
			return;
		}
		serveStatic(res, target);
		return;
	}

	if (pathname === '/' || pathname === '/index.html') {
		serveStatic(res, path.join(PUBLIC_DIR, 'index.html'));
		return;
	}

	if (pathname === '/login' || pathname === '/login.html') {
		serveStatic(res, path.join(PUBLIC_DIR, 'login.html'));
		return;
	}

	if (pathname === '/admin' || pathname === '/admin.html') {
		serveStatic(res, path.join(PUBLIC_DIR, 'admin.html'));
		return;
	}

	if (pathname === '/account' || pathname === '/account.html') {
		serveStatic(res, path.join(PUBLIC_DIR, 'account.html'));
		return;
	}

	const target = path.join(PUBLIC_DIR, pathname);
	if (target.startsWith(PUBLIC_DIR) && fs.existsSync(target) && fs.statSync(target).isFile()) {
		serveStatic(res, target);
		return;
	}

	send(res, 404, 'Not found', { 'Content-Type': 'text/plain; charset=utf-8' });
});

server.listen(PORT, HOST, () => {
	console.log('Tisa OTP preview listening on http://' + HOST + ':' + PORT);
	console.log('  /                     front-end form demo');
	console.log('  /admin                admin screens demo');
	console.log('  /account              signed-in account panel demo');
	console.log('  /download/tisa-otp.zip  installable package (built on demand)');
	console.log('  plugin assets served from ' + PLUGIN_ASSETS);
});
