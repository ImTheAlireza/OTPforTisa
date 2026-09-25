/**
 * Signa demo: the plugin's REST API, answered inside the browser.
 *
 * The public demo runs on plain static hosting, so there is no WordPress and
 * no PHP behind it. The plugin's real front.js and admin.js still call
 * `…/signa/v1/<route>` with window.fetch; this file answers those calls with
 * the same JSON contract the preview server uses (the mock is lifted from
 * preview/server.js at build time, see tools/build_site.js). Nothing leaves
 * the visitor's browser and no SMS is ever sent.
 */
(function () {
	'use strict';

	var mock = window.SignaDemoMock;
	var realFetch = window.fetch ? window.fetch.bind(window) : null;

	if (!mock || !realFetch) {
		return;
	}

	function lower(headers) {
		var out = {};

		if (!headers) {
			return out;
		}

		if (typeof window.Headers !== 'undefined' && headers instanceof window.Headers) {
			headers.forEach(function (value, key) {
				out[key.toLowerCase()] = value;
			});
			return out;
		}

		Object.keys(headers).forEach(function (key) {
			out[key.toLowerCase()] = headers[key];
		});

		return out;
	}

	function pairs(list) {
		var out = {};

		list.forEach(function (value, key) {
			out[key] = value;
		});

		return out;
	}

	function parse(body) {
		if (!body) {
			return {};
		}

		if (typeof body === 'string') {
			try {
				return JSON.parse(body);
			} catch (error) {
				return pairs(new window.URLSearchParams(body));
			}
		}

		if (body instanceof window.URLSearchParams || (window.FormData && body instanceof window.FormData)) {
			return pairs(body);
		}

		return {};
	}

	function aborted() {
		try {
			return new window.DOMException('The operation was aborted.', 'AbortError');
		} catch (error) {
			var fallback = new Error('The operation was aborted.');
			fallback.name = 'AbortError';
			return fallback;
		}
	}

	/* A real server takes a moment; an instant answer makes the spinner flash. */
	function later(ms, signal, produce) {
		return new Promise(function (resolve, reject) {
			if (signal && signal.aborted) {
				reject(aborted());
				return;
			}

			var timer = window.setTimeout(function () {
				Promise.resolve().then(produce).then(resolve, reject);
			}, ms);

			if (signal) {
				signal.addEventListener('abort', function () {
					window.clearTimeout(timer);
					reject(aborted());
				});
			}
		});
	}

	function json(result) {
		return new window.Response(JSON.stringify(result.body), {
			status: result.status,
			headers: { 'Content-Type': 'application/json; charset=utf-8' }
		});
	}

	/*
	 * Every request gets its own code, delivered as a demo SMS (see
	 * demo-phone.js), so the visitor goes through what their customers will:
	 * ask, read the message, type the code. The mock itself only knows one
	 * code, so a correct answer is translated to it on the way in.
	 */
	var MOCK_CODE = '12345';
	var codes = {};

	function key(phone) {
		return String(phone || '').replace(/[^0-9]/g, '').replace(/^(0098|98)/, '0');
	}

	function freshCode() {
		var code = MOCK_CODE;

		while (code === MOCK_CODE) {
			code = String(10000 + Math.floor(Math.random() * 90000));
		}

		return code;
	}

	function announce(name, detail) {
		try {
			window.dispatchEvent(new window.CustomEvent('signa-demo:' + name, { detail: detail }));
		} catch (error) {
			// Very old browsers: the form still works, only the phone stays quiet.
		}
	}

	function handle(route, body, headers) {
		var phone = key(body.phone);

		if ('verify' === route && codes[phone]) {
			body.code = String(body.code || '') === codes[phone] ? MOCK_CODE : '00000';
		}

		var result = mock.handleRest(route, body, headers);

		// In the public demo every number goes straight to the SMS: the signup
		// fields are a setting the buyer can switch on, not the first thing a
		// visitor should have to fill in.
		if ('start' === route && result.body && result.body.success && 'register_form' === (result.body.data || {}).step) {
			result = mock.handleRest('code', body, headers);

			if (result.body && result.body.data) {
				result.body.data.message = 'کد ۵ رقمی پیامک شد. تا ۲ دقیقه معتبر است.';
			}
		}

		var data = result.body && result.body.success ? result.body.data || {} : {};

		if (['start', 'code', 'register'].indexOf(route) >= 0 && 'verify' === data.step) {
			codes[phone] = freshCode();
			result.sms = { phone: phone, masked: data.masked || '', code: codes[phone], length: data.code_length || 5, ttl: data.expires_in || 120 };
		}

		if ('verify' === route && 'signed_in' === data.step) {
			delete codes[phone];
			result.signedIn = { phone: phone, created: !!body.draft_token };
		}

		return result;
	}

	window.fetch = function (input, init) {
		var url = typeof input === 'string' ? input : (input && input.url) || '';
		var options = init || {};
		var api = /demo-api\/signa\/v1\/([^?#]*)/.exec(url);

		if (api) {
			var result = handle(api[1].replace(/\/$/, ''), parse(options.body), lower(options.headers));
			var wait = (result.delay || 0) + 350 + Math.round(Math.random() * 250);

			return later(wait, options.signal, function () {
				if (result.sms) {
					announce('sms', result.sms);
				}

				if (result.signedIn) {
					announce('signed-in', result.signedIn);
				}

				return json(result);
			});
		}

		// The admin panel's live form preview posts the unsaved settings and
		// shows the page that comes back; here that page is pre-rendered.
		if (/(^|\/)form-preview(\?|$)/.test(url)) {
			var body = parse(options.body);
			var query = /[?&]step=([a-z]+)/.exec(url);
			var step = body.step || (query ? query[1] : 'phone');

			step = ['phone', 'code', 'fields'].indexOf(step) >= 0 ? step : 'phone';

			return realFetch('admin-preview-' + step + '.html');
		}

		return realFetch(input, init);
	};

	/* ---------------------------------------------------- server-only actions */

	var toastTimer = 0;

	function toast(text) {
		var box = document.getElementById('sg-toast');

		if (!box) {
			box = document.createElement('div');
			box.id = 'sg-toast';
			box.className = 'sg-toast';
			box.setAttribute('role', 'status');
			document.body.appendChild(box);
		}

		box.textContent = text;
		box.classList.add('is-on');
		window.clearTimeout(toastTimer);
		toastTimer = window.setTimeout(function () {
			box.classList.remove('is-on');
		}, 3200);
	}

	var APP_KEY = 'signa-demo-app-mode';

	function appMode(on) {
		document.documentElement.classList.toggle('signa-app', on);
		document.body.classList.toggle('signa-app', on);

		try {
			window.sessionStorage.setItem(APP_KEY, on ? '1' : '0');
		} catch (error) {
			// Private mode: the switch still works for this page.
		}
	}

	var SERVER = /example\.test\//;
	var NOTE = 'این کار در سایت واقعی روی سرور انجام می‌شود؛ در دمو فقط نمایشی است.';

	document.addEventListener('submit', function (event) {
		var form = event.target;
		var action = form.getAttribute('action') || '';

		if (!SERVER.test(action)) {
			return;
		}

		// options.php is the settings form: admin.js saves it itself in demo mode.
		if (/options\.php/.test(action)) {
			return;
		}

		event.preventDefault();

		var kind = form.querySelector('input[name="action"]');

		// The generated admin pages toggle app mode themselves; remember the
		// result so the next screen opens the same way.
		if (kind && kind.value === 'signa_app_mode') {
			window.setTimeout(function () {
				appMode(document.body.classList.contains('signa-app'));
			}, 0);
			return;
		}

		toast(NOTE);
	}, true);

	document.addEventListener('click', function (event) {
		var link = event.target.closest ? event.target.closest('a[href]') : null;

		if (link && SERVER.test(link.getAttribute('href'))) {
			event.preventDefault();
			toast(NOTE);
		}
	}, true);

	document.addEventListener('DOMContentLoaded', function () {
		var saved = '0';

		try {
			saved = window.sessionStorage.getItem(APP_KEY) || '0';
		} catch (error) {
			saved = '0';
		}

		if (saved === '1' && document.body.classList.contains('signa-admin')) {
			appMode(true);
		}
	});
})();
