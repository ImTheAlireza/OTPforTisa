(function () {
	'use strict';
const attempts = new Map();
let importCursor = 0;
let importTotal = 240;
let nonceSeq = 0;
let tokenSeq = 0;

function freshNonce() {
	nonceSeq += 1;

	return 'demo-nonce-' + nonceSeq;
}

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

function formConfig() {
	return {
		restUrl: 'demo-api/signa/v1/',
		nonce: freshNonce(),
		formToken: freshFormToken(),
		renderedAt: Math.floor(Date.now() / 1000),
		configUrl: 'demo-api/signa/v1/form-config',
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

function checkPayload(kind) {
	const rows = {
		general: {
			title: 'آزمایش تنظیمات عمومی',
			summary: 'نسخه‌ها، جدول‌ها و زمان‌بند.',
			rows: [
				{ label: 'نسخه PHP', value: '8.2.18', status: 'ok' },
				{ label: 'نسخه وردپرس', value: '6.9', status: 'ok' },
				{ label: 'جدول‌های افزونه', value: 'سالم', status: 'ok' },
				{ label: 'زمان‌بند پاک‌سازی', value: '2026-09-22 14:20', status: 'ok' },
				{ label: 'وضعیت افزونه', value: 'فعال', status: 'ok' },
				{ label: 'یکپارچگی بستهٔ نصب‌شده', value: 'درست — ۱۴۸ فایل بررسی شد', status: 'ok', note: 'همهٔ فایل‌ها هش خودشان را در build.json دارند.' },
			],
		},
		code: {
			title: 'آزمایش کد یکبارمصرف',
			summary: 'هیچ پیامکی ارسال نمی‌شود.',
			rows: [
				{ label: 'طول کد', value: '۵ رقم', status: 'ok' },
				{ label: 'اعتبار کد', value: '۱۲۰ ثانیه', status: 'ok' },
				{ label: 'انبار کد', value: 'جدول اختصاصی', status: 'info', note: 'database' },
				{ label: 'ساخت کد', value: '۴۸۲۱۳', status: 'ok', note: 'همان تنظیمات همین صفحه.' },
				{ label: 'ذخیره و بازخوانی', value: 'درست', status: 'ok', note: 'برای شمارهٔ آزمایشی ذخیره و بی‌درنگ باطل شد.' },
			],
		},
		gateways: {
			title: 'آزمایش سامانه‌های پیامکی',
			summary: 'آماده / ناقص / خطای واقعی.',
			rows: [
				{ label: 'SMS.ir — اصلی', value: '3000505', status: 'ok', note: 'الگوی «کد ورود» انتخاب شده است.' },
				{ label: 'کاوه‌نگار — پشتیبان', value: 'آماده نیست', status: 'warn', note: 'ناقص: کلید API' },
				{ label: 'ترتیب تلاش', value: 'SMS.ir → کاوه‌نگار', status: 'ok', note: 'اگر سامانهٔ اول خطا بدهد، بعدی امتحان می‌شود.' },
				{ label: 'کانال ارسال کد', value: 'پیامک', status: 'ok' },
				{ label: 'کلید API و اعتبار', value: 'وصل نشد', status: 'fail', note: 'خودِ وردپرس این درخواست را رد کرد، نه فایروال هاست: در wp-config.php گزینهٔ WP_HTTP_BLOCK_EXTERNAL روشن است و api.sms.ir در WP_ACCESSIBLE_HOSTS نیست.' },
				{ label: 'شماره خط', value: '3000505', status: 'info', note: 'تا وقتی خروجی باز نشود یا «ارسال مستقیم» روشن نشود، فهرست خط‌های حساب خوانده نمی‌شود.' },
				{ label: 'دسترسی این سرور به سامانه', value: 'بسته است', status: 'fail', note: 'api.sms.ir — خودِ وردپرس این درخواست را رد کرد … یا در پیشرفته › کلیدهای داده و ارسال «ارسال مستقیم» را روشن کنید.' },
				{ label: 'ارسال واقعی', value: 'آزمایش جدا', status: 'info', note: 'این آزمایش چیزی ارسال نمی‌کند.' },
			],
		},
		security: {
			title: 'آزمایش امنیت و کپچا',
			summary: 'از سمت سرور و در همین مرورگر.',
			rows: [
				{ label: 'سرویس کپچا', value: 'آرکپچا', status: 'ok' },
				{ label: 'کلید سایت', value: 'ARC••••••SITE', status: 'ok' },
				{ label: 'کلید مخفی', value: 'ثبت شده', status: 'ok' },
				{ label: 'نوع چالش', value: 'ویجت (نسخهٔ ۲)', status: 'ok', note: 'کلید نسخهٔ ۳ روی این سایت نیست؛ اگر حسابتان v3 است، کلید «نسخه ۳» را روشن کنید.' },
				{
					label: 'فهرست اسکریپت‌ها',
					value: '۳ نشانی',
					status: 'ok',
					note: 'به همین ترتیب امتحان می‌شوند تا کتابخانه پیدا شود: https://widget.arcaptcha.ir/1/api.js — https://nwidget.arcaptcha.ir/1/api.js — https://widget.arcaptcha.co/1/api.js',
				},
				{ label: 'توکن چطور خوانده می‌شود', value: 'سه راه', status: 'ok', note: 'getArcToken(widgetId)، فیلد مخفی arcaptcha-token داخل فرم، و شکل شیئی { arcaptcha_token } جریان نامرئی.' },
				{ label: 'زمان نمایش', value: 'همیشه', status: 'info', note: 'چالش از همان اولین درخواست خواسته می‌شود.' },
				{ label: 'چالش دیدنی است؟', value: 'بله', status: 'ok', note: 'کاربر چالش را می‌بیند و باید کاملش کند.' },
				{ label: 'فهرست معاف', value: 'خاموش', status: 'info', note: 'هیچ شماره‌ای از کپچا معاف نیست.' },
				{ label: 'ردشدن به‌خاطر کپچا (۷ روز)', value: '۹ درخواست', status: 'ok', note: '۸ درخواست از ربات یا اسکریپت بود (بدون مرورگر)؛ کپچا کار خودش را کرد. ۱ درخواست از مرورگر واقعی بود و توکن نرسید؛ اگر تکرار شد «باز ماندن ورود» یا نشانی جایگزین اسکریپت را ببینید.' },
				{ label: 'عبور بدون کپچا (۷ روز)', value: '۲ درخواست', status: 'info', note: 'مرورگر این کاربران نتوانست کپچا را بیاورد و «باز ماندن ورود» روشن است؛ هانی‌پات و سقف ارسال همچنان اعمال شدند.' },
				{ label: 'نمایش در این مرورگر', value: 'در همین پنجره ادامه دارد…', status: 'info' },
			],
		},
		registration: {
			title: 'آزمایش فرم عضویت',
			summary: 'گام‌ها، فیلدها و مقصد ذخیره.',
			rows: [
				{ label: 'فرم عضویت', value: 'روشن', status: 'info' },
				{ label: 'ترتیب گام‌ها', value: 'اول مشخصات، بعد کد', status: 'ok' },
				{ label: 'فیلدهای فعال', value: 'ایمیل · کد پستی · آدرس', status: 'ok' },
				{ label: 'فیلدهای اجباری', value: 'ایمیل', status: 'info' },
				{ label: 'کلید متای شماره', value: 'signa_phone', status: 'ok' },
			],
		},
		design: {
			title: 'آزمایش ظاهر فرم',
			summary: 'رنگ‌های واقعی فرم و نسبت کنتراست.',
			rows: [
				{ label: 'رنگ تأکید روی کارت سفید', value: '5.47:1', status: 'ok', note: 'بلندی، پیوندها و دکمه‌های متن‌دار.' },
				{ label: 'متن سفید روی رنگ تأکید', value: '5.47:1', status: 'ok' },
				{ label: 'متن روی پس‌زمینهٔ فرم', value: '17.75:1', status: 'ok' },
				{ label: 'رنگ تأکید', value: '#0f766e', status: 'info' },
				{ label: 'فونت فرم', value: 'وزیرمتن (همراه افزونه)', status: 'ok', note: 'همان فونتی که پیش‌نمایش با آن ساخته شده، همراه افزونه روی همین سایت سرو می‌شود.' },
			],
		},
		store: {
			title: 'آزمایش فروشگاه',
			summary: 'وضعیت ووکامرس و اثر تنظیمات.',
			rows: [
				{ label: 'ووکامرس', value: '9.4.1', status: 'ok' },
				{ label: 'فرم حساب کاربری', value: 'جایگزین می‌شود', status: 'info' },
				{ label: 'همگام‌سازی شماره صورتحساب', value: 'روشن', status: 'ok' },
				{ label: 'شمارهٔ صورتحساب در سایت', value: 'پیدا شد', status: 'ok' },
			],
		},
		data: {
			title: 'آزمایش داده و رویدادها',
			summary: 'یک رویداد نوشته و بلافاصله خوانده می‌شود.',
			rows: [
				{ label: 'ثبت رویدادها', value: 'روشن', status: 'ok' },
				{ label: 'جدول رویدادها', value: 'موجود', status: 'ok', note: 'wp_signa_logs' },
				{ label: 'رویدادهای ثبت‌شده', value: '۱٬۲۸۴', status: 'info', note: '۱۷ موردش خطا بوده است.' },
				{ label: 'نگهداری', value: '۷ روز', status: 'info' },
				{ label: 'نوشتن و خواندن', value: 'درست', status: 'ok', note: 'یک رویداد admin.self_test نوشته و بلافاصله پیدا شد.' },
			],
		},
	};

	const payload = rows[kind] || rows.general;
	const ok = payload.rows.every((row) => row.status !== 'fail');

	return { kind: kind, ok: ok, title: payload.title, summary: payload.summary, rows: payload.rows };
}

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
			scripts: [
				'https://widget.arcaptcha.ir/1/api.js',
				'https://nwidget.arcaptcha.ir/1/api.js',
				'https://widget.arcaptcha.co/1/api.js',
			],
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
		wordpress: 'https://api.wordpress.org/plugins/info/1.0/signa.json',
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

	if ('stale-nonce-from-cache' === (headers || {})['x-wp-nonce']) {
		return {
			status: 403,
			body: { code: 'rest_cookie_invalid_nonce', message: 'nonce نامعتبر است.', data: { status: 403 } },
		};
	}

	if ('stale-form-token' === body.signa_ft) {
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
				return fail('delivery_failed', 'اتصال خروجی این سرور به سامانه پیامکی بسته است (پورت ۴۴۳ باز نمی‌شود). فایروال هاست باید دامنهٔ سامانه را برای این سایت باز کند.', {
					gateway: 'smsir',
					error_code: 'transport',
					reason: 'CONNECT: cURL error 7: Failed to connect to api.sms.ir port 443: Connection timed out',
					trace: [
						{ gateway: 'smsir', sent: false, error_code: 'transport', status: 0, message: 'اتصال خروجی این سرور به سامانه پیامکی بسته است (پورت ۴۴۳ باز نمی‌شود).', reason: 'CONNECT: cURL error 7: Failed to connect to api.sms.ir port 443' },
						{ gateway: 'kavenegar', sent: false, error_code: 'missing_key', status: 0, message: 'کلید API تنظیم نشده است.', reason: '' },
					],

					plan: { mode: 'pattern', sender: '3000505', template: '123456' },
				});
			}
			return ok({
				sent: true,
				via: 'smsir',
				channel: body.channel || 'sms',
				carrier: 'email',
				carrier_label: 'ایمیل',
				direct: false,
				fix: 'در پیشرفته › کلیدهای داده و ارسال «ارسال مستقیم» را روشن کنید؛ افزونه خودش درخواست را می‌فرستد و لازم نیست wp-config.php را عوض کنید.',
				masked: mask(phone),
				trace: [
					{ gateway: 'smsir', sent: false, error_code: 'transport', status: 0, message: 'خود وردپرس اجازهٔ این درخواست را نمی‌دهد: در wp-config.php گزینهٔ WP_HTTP_BLOCK_EXTERNAL روشن است و دامنهٔ سامانهٔ پیامکی در WP_ACCESSIBLE_HOSTS نیست. یا آن گزینه را بردارید یا دامنه را به فهرست اضافه کنید: define( \'WP_ACCESSIBLE_HOSTS\', \'api.sms.ir\' );', reason: 'BLOCKED: http_request_not_executed — WordPress blocks outbound HTTP: api.sms.ir is not in WP_ACCESSIBLE_HOSTS.' },
					{ gateway: 'email', sent: true, error_code: '', status: 200, message: 'کد از راه ایمیل ارسال شد.', reason: '' },
				],
				plan: {
					mode: 'pattern',
					sender: '3000505',
					template: '123456',
					issues: ['وردپرس درخواست‌های خروجی به api.sms.ir را بسته است؛ در پیشرفته › کلیدهای داده و ارسال «ارسال مستقیم» را روشن کنید یا دامنه را در WP_ACCESSIBLE_HOSTS بگذارید.'],
				},
				message: 'پیامک ارسال نشد؛ کد آزمایشی از راه ایمیل رفت. علت شکست پیامک در همین پنجره آمده است.',
			});

		case 'start':
			if (phone === '09129999999') {
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
					display_name: 'کاربر سیگنا',
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

		case 'admin/check':
			return ok(checkPayload(String(body.kind || 'general')));

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

	window.SignaDemoMock = { handleRest: handleRest };
})();
