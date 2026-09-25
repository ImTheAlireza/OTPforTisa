/**
 * Signa demo: a phone next to the form that receives the demo SMS.
 *
 * demo-shim.js announces `signa-demo:sms` with the code it minted for that
 * request. This file shows it the way a customer would see it: the phone
 * buzzes, a message notification drops onto the lock screen, and tapping it
 * fills the code in — like the "from Messages" suggestion on a real phone.
 *
 * Where there is room, the phone is drawn inside `[data-sg-phone]`; on small
 * screens (or pages without a mount) the same notification drops from the top
 * of the viewport instead, which is exactly where a real phone would put it.
 */
(function () {
	'use strict';

	var SENDER = '3000505';
	var SITE = 'فروشگاه نمونه';

	var ICON_MSG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4C7 4 3 7.4 3 11.5c0 2.3 1.3 4.4 3.3 5.8L5.5 21l4-2.2c.8.2 1.6.3 2.5.3 5 0 9-3.4 9-7.6S17 4 12 4Z" fill="currentColor"/></svg>';
	var ICON_CHECK = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 12.5 4 4 8-9"/></svg>';
	var ICON_STATUS = '<svg viewBox="0 0 18 12" aria-hidden="true"><rect x="0" y="8" width="3" height="4" rx="1"/><rect x="5" y="5.5" width="3" height="6.5" rx="1"/><rect x="10" y="3" width="3" height="9" rx="1"/><rect x="15" y="0" width="3" height="12" rx="1"/></svg>'
		+ '<svg viewBox="0 0 26 12" aria-hidden="true"><rect x="0.5" y="0.5" width="22" height="11" rx="3.5" fill="none" stroke="currentColor" opacity="0.5"/><rect x="2.5" y="2.5" width="15" height="7" rx="2"/><rect x="24" y="4" width="1.8" height="4" rx="0.9" opacity="0.5"/></svg>';

	var fa = function (text) {
		return String(text).replace(/[0-9]/g, function (d) {
			return '۰۱۲۳۴۵۶۷۸۹'.charAt(Number(d));
		});
	};

	function el(tag, cls, html) {
		var node = document.createElement(tag);

		if (cls) {
			node.className = cls;
		}

		if (html !== undefined) {
			node.innerHTML = html;
		}

		return node;
	}

	function clock() {
		var now = new Date();
		var time = fa(('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2));
		var date = '';

		try {
			date = new Intl.DateTimeFormat('fa-IR-u-ca-persian', { weekday: 'long', day: 'numeric', month: 'long' }).format(now);
		} catch (error) {
			date = '';
		}

		return { time: time, date: date };
	}

	/* ------------------------------------------------------------ the phone */

	var phone = null;

	function build(mount) {
		var body = el('div', 'sg-phone');
		body.setAttribute('role', 'img');
		body.setAttribute('aria-label', 'گوشی نمایشی که پیامک کد ورود را نشان می‌دهد');

		body.innerHTML = ''
			+ '<div class="sg-phone__screen">'
			+ '<div class="sg-phone__island"></div>'
			+ '<div class="sg-phone__status"><span data-sg-time></span><span class="sg-phone__icons">' + ICON_STATUS + '</span></div>'
			+ '<div class="sg-phone__lock"><div class="sg-phone__date" data-sg-date></div><div class="sg-phone__clock" data-sg-clock></div></div>'
			+ '<div class="sg-phone__stack" data-sg-stack aria-live="polite"></div>'
			+ '<div class="sg-phone__idle" data-sg-idle><span class="sg-phone__pulse"></span>منتظر پیامک…</div>'
			+ '<div class="sg-phone__home"></div>'
			+ '</div>';

		mount.appendChild(body);

		var parts = {
			root: body,
			time: body.querySelector('[data-sg-time]'),
			date: body.querySelector('[data-sg-date]'),
			clock: body.querySelector('[data-sg-clock]'),
			stack: body.querySelector('[data-sg-stack]'),
			idle: body.querySelector('[data-sg-idle]'),
			mount: mount
		};

		function tick() {
			var now = clock();
			parts.time.textContent = now.time;
			parts.clock.textContent = now.time;
			parts.date.textContent = now.date;
		}

		tick();
		window.setInterval(tick, 20000);

		return parts;
	}

	function visible(node) {
		return !!(node && node.getClientRects && node.getClientRects().length && window.getComputedStyle(node).display !== 'none');
	}

	function usePhone() {
		return phone && visible(phone.mount);
	}

	/* ------------------------------------------------------- the notification */

	function note(sms) {
		var button = el('button', 'sg-note');
		button.type = 'button';
		button.setAttribute('data-sg-code', sms.code);
		button.setAttribute('aria-label', 'پیامک کد ورود: ' + sms.code + '. برای پر شدن خودکار بزنید.');

		button.innerHTML = ''
			+ '<span class="sg-note__app">' + ICON_MSG + '</span>'
			+ '<span class="sg-note__body">'
			+ '<span class="sg-note__head"><b>پیام‌ها</b><time>اکنون</time></span>'
			+ '<span class="sg-note__from" dir="ltr">' + fa(SENDER) + '</span>'
			+ '<span class="sg-note__text">کد ورود شما به ' + SITE + ': <b class="sg-note__code" dir="ltr">' + sms.code + '</b><br>این کد را در اختیار کسی قرار ندهید.</span>'
			+ '</span>';

		button.addEventListener('click', function () {
			if (button.classList.contains('is-old')) {
				return;
			}

			button.classList.add('is-used');
			fill(sms.code);
		});

		return button;
	}

	function tip(container) {
		if (container.querySelector('.sg-tip')) {
			return;
		}

		container.appendChild(el('p', 'sg-tip', 'روی پیام بزنید تا کد خودش پر شود، یا کد را تایپ کنید.'));
	}

	var bannerTimer = 0;

	function banner(sms) {
		var wrap = document.querySelector('.sg-banner');

		if (!wrap) {
			wrap = el('div', 'sg-banner');
			document.body.appendChild(wrap);
		}

		wrap.innerHTML = '';
		var card = note(sms);
		wrap.appendChild(card);
		tip(wrap);

		// Next frame, so the drop-in transition runs.
		window.requestAnimationFrame(function () {
			wrap.classList.add('is-on');
		});

		card.addEventListener('click', function () {
			wrap.classList.remove('is-on');
		});

		window.clearTimeout(bannerTimer);
		bannerTimer = window.setTimeout(function () {
			wrap.classList.remove('is-on');
		}, 15000);
	}

	function signal(done) {
		var line = document.querySelector('[data-sg-signal]');

		if (!line || !visible(line)) {
			done();
			return;
		}

		line.classList.add('is-live');
		window.setTimeout(function () {
			line.classList.remove('is-live');
			done();
		}, 900);
	}

	function stage(name) {
		Array.prototype.forEach.call(document.querySelectorAll('[data-sg-stage]'), function (node) {
			node.setAttribute('data-sg-stage', name);
		});
	}

	function receive(sms) {
		stage('sms');

		signal(function () {
			if (!usePhone()) {
				banner(sms);
				return;
			}

			phone.idle.hidden = true;

			var done = phone.stack.querySelector('.sg-done');
			if (done) {
				done.remove();
			}

			Array.prototype.forEach.call(phone.stack.querySelectorAll('.sg-note'), function (old) {
				old.classList.add('is-old');
				old.querySelector('time').textContent = 'قبلی';
			});

			var card = note(sms);
			phone.stack.insertBefore(card, phone.stack.firstChild);
			tip(phone.stack);

			// Drop in on the next frame, and buzz.
			window.requestAnimationFrame(function () {
				card.classList.add('is-in');
			});

			phone.root.classList.remove('is-buzz');
			void phone.root.offsetWidth;
			phone.root.classList.add('is-buzz');

			if (navigator.vibrate) {
				try {
					navigator.vibrate(60);
				} catch (error) {
					// Not allowed without a gesture; the drawing buzzes anyway.
				}
			}
		});
	}

	function signedIn(info) {
		stage('done');

		var wrap = document.querySelector('.sg-banner');
		if (wrap) {
			wrap.classList.remove('is-on');
		}

		if (!usePhone()) {
			return;
		}

		phone.stack.innerHTML = '';
		var card = el('div', 'sg-done', ''
			+ '<span class="sg-done__ic">' + ICON_CHECK + '</span>'
			+ '<b>' + (info && info.created ? 'حساب ساخته شد' : 'وارد شدید') + '</b>'
			+ '<span>بدون رمز عبور، در چند ثانیه.</span>');

		var again = el('button', 'sg-done__again', 'دوباره امتحان کنید');
		again.type = 'button';
		again.addEventListener('click', function () {
			window.location.reload();
		});

		card.appendChild(again);
		phone.stack.appendChild(card);
	}

	/* --------------------------------------------- fill the waiting form in */

	function fill(code) {
		var hosts = document.querySelectorAll('[data-signa-form]');

		for (var i = 0; i < hosts.length; i++) {
			var form = hosts[i].signaForm;

			if (!form || !form.stepCode || !form.stepCode.classList.contains('is-current')) {
				continue;
			}

			if (form.boxes && form.boxes.length && (window.signaOtp || {}).codeInput !== 'single') {
				form.boxes.forEach(function (box, n) {
					box.value = code.charAt(n);
					box.dispatchEvent(new window.Event('input', { bubbles: true }));
				});
			} else if (form.bulk) {
				form.bulk.value = code;
				form.bulk.dispatchEvent(new window.Event('input', { bubbles: true }));
			}

			// Auto-verify normally takes it from here; if it is switched off in
			// the demo controls, send it the way the button would.
			(function (target) {
				window.setTimeout(function () {
					if (!target.busy && !target.root.classList.contains('is-signed-in')) {
						target.act('verify');
					}
				}, 700);
			})(form);

			return true;
		}

		return false;
	}

	/* ----------------------------------------------------------------- boot */

	function boot() {
		var mount = document.querySelector('[data-sg-phone]');

		if (mount) {
			phone = build(mount);
		}

		window.addEventListener('signa-demo:sms', function (event) {
			receive(event.detail || {});
		});

		window.addEventListener('signa-demo:signed-in', function (event) {
			signedIn(event.detail || {});
		});

		// The first step lights up as soon as somebody starts typing a number.
		document.addEventListener('focusin', function (event) {
			var path = event.composedPath ? event.composedPath() : [event.target];
			for (var i = 0; i < path.length; i++) {
				if (path[i].matches && path[i].matches('[data-signa-phone]')) {
					if (document.querySelector('[data-sg-stage="idle"]')) {
						stage('phone');
					}
					return;
				}
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	window.SignaDemoPhone = { fill: fill };
})();
