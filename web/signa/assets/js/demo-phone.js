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
	var ICON_AGAIN = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4.5h4.5"/></svg>';
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
		wrap.classList.remove('is-done');
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

	// When the first SMS of this round arrived: the success card says how long
	// it took from there to being signed in.
	var started = 0;

	function receive(sms) {
		stage('sms');

		if (!started) {
			started = Date.now();
		}

		signal(function () {
			if (!usePhone()) {
				banner(sms);
				return;
			}

			phone.idle.hidden = true;

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

	function doneCard(info, next) {
		var card = el('div', 'sg-done', ''
			+ '<span class="sg-done__ic">' + ICON_CHECK + '</span>'
			+ '<b>' + (info && info.created ? 'حساب ساخته شد' : 'وارد شدید') + '</b>'
			+ '<span>بدون رمز عبور، در چند ثانیه.</span>');

		var again = el('button', 'sg-done__again', 'دوباره امتحان کنید');
		again.type = 'button';
		again.addEventListener('click', function () {
			window.location.reload();
		});

		if (next) {
			var go = el('a', 'sg-done__again sg-done__go', 'صفحهٔ حساب کاربری');
			go.href = next;
			card.appendChild(go);
			again.classList.add('sg-done__again--ghost');
		}

		card.appendChild(again);

		return card;
	}

	function mask(phone) {
		var digits = String(phone || '').replace(/\D/g, '');

		return digits.length >= 8 ? digits.slice(0, 4) + '***' + digits.slice(-3) : digits;
	}

	function escape(text) {
		return String(text).replace(/[&<>"]/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
		});
	}

	/**
	 * Signed in on the stage: the form, the signal and the phone step back and
	 * one card takes their place, in the colour the visitor picked.
	 */
	function win(stageEl, info, next) {
		var frame = stageEl.querySelector('[data-sg-frame]');
		var host = stageEl.querySelector('[data-signa-form]');
		var accent = host ? window.getComputedStyle(host).getPropertyValue('--signa-accent').trim() : '';
		var seconds = started ? Math.max(1, Math.round((Date.now() - started) / 1000)) : 0;
		var old = stageEl.querySelector('.sg-win');

		if (old) {
			old.remove();
		}

		var box = el('div', 'sg-win' + (frame && frame.classList.contains('is-dark') ? ' is-dark' : ''));
		box.setAttribute('role', 'status');
		box.setAttribute('aria-live', 'polite');

		if (/^#[0-9a-f]{3,8}$/i.test(accent)) {
			box.style.setProperty('--win', accent);
		}

		box.innerHTML = ''
			+ '<div class="sg-win__card">'
			+ '<span class="sg-win__badge" aria-hidden="true"><i></i><i></i>' + ICON_CHECK + '</span>'
			+ '<p class="sg-win__kicker">ورود موفق</p>'
			+ '<h3 class="sg-win__title" tabindex="-1">' + (info && info.created ? 'حساب ساخته شد و وارد شدید' : 'وارد شدید') + '</h3>'
			+ '<p class="sg-win__sub">بدون رمز عبور، فقط با یک پیامک.</p>'
			+ '<dl class="sg-win__facts">'
			+ (info && info.phone ? '<div><dt>شماره</dt><dd dir="ltr">' + escape(fa(mask(info.phone))) + '</dd></div>' : '')
			+ (seconds ? '<div><dt>از پیامک تا ورود</dt><dd>' + fa(seconds) + ' ثانیه</dd></div>' : '')
			+ '<div><dt>رمز عبور</dt><dd>لازم نشد</dd></div>'
			+ '</dl>'
			+ '<div class="sg-win__actions"></div>'
			+ '</div>';

		var actions = box.querySelector('.sg-win__actions');
		var again = el('button', 'sg-win__again', ICON_AGAIN + '<span>امتحان دوباره</span>');
		again.type = 'button';
		again.setAttribute('data-sg-again', '');
		again.addEventListener('click', reset);
		actions.appendChild(again);

		if (next) {
			var go = el('a', 'sg-win__go', 'صفحهٔ حساب کاربری');
			go.href = next;
			actions.appendChild(go);
		}

		// Where the form and the phone were: from the top of the form, as tall
		// as the taller of the two.
		var top = 0;
		var height = 0;
		var right = 0;
		var left = 0;

		if (frame) {
			var stageRect = stageEl.getBoundingClientRect();
			var frameRect = frame.getBoundingClientRect();
			var phoneRect = phone && usePhone() ? phone.root.getBoundingClientRect() : { height: 0 };

			top = frameRect.top - stageRect.top;
			height = Math.max(frameRect.height, phoneRect.height);
			// From the form's edge across to the phone's, and no further: the
			// studio under them stays usable.
			var far = phone && usePhone() ? phone.mount.getBoundingClientRect() : frameRect;

			right = Math.max(0, stageRect.right - frameRect.right);
			left = Math.max(0, Math.min(far.left, frameRect.left) - stageRect.left);
		}

		box.style.top = top + 'px';
		box.style.right = right + 'px';
		box.style.left = left + 'px';

		if (height > 0) {
			box.style.minHeight = height + 'px';
		}

		stageEl.appendChild(box);
		stageEl.classList.add('is-done');
		sleepers(stageEl).forEach(function (node) {
			node.setAttribute('inert', '');
			node.setAttribute('aria-hidden', 'true');
		});

		window.requestAnimationFrame(function () {
			box.classList.add('is-on');
		});

		var title = box.querySelector('.sg-win__title');

		try {
			title.focus({ preventScroll: true });
		} catch (error) {
			title.focus();
		}

		// The whole card in view, buttons included: measured on the card
		// itself, because the box around it is as tall as the form was.
		// From the layout, not getBoundingClientRect(): the card is still
		// mid-way through its entrance transform here.
		var card = box.querySelector('.sg-win__card');
		var cardTop = stageEl.getBoundingClientRect().top + top + card.offsetTop;
		var rect = { top: cardTop, height: card.offsetHeight, bottom: cardTop + card.offsetHeight };
		var bar = document.querySelector('[data-sg-top], .sg-strip');
		var head = (bar ? Math.max(0, bar.getBoundingClientRect().bottom) : 70) + 14;

		if (rect.height && (rect.top < head || rect.bottom > window.innerHeight - 16) && window.scrollBy) {
			var room = window.innerHeight - head;
			var shift = rect.height < room ? rect.top - head - (room - rect.height) / 2 : rect.top - head - 12;

			window.scrollBy({ top: shift, behavior: 'smooth' });
		}
	}

	/** What steps back while the card is up. */
	function sleepers(stageEl) {
		return Array.prototype.slice.call(stageEl.querySelectorAll('[data-sg-frame], .stage__link, [data-sg-phone]'));
	}

	/**
	 * Back to the start without reloading: the look chosen in the studio, the
	 * scroll position and the page all stay; only the round starts over.
	 */
	function reset() {
		var stageEl = document.querySelector('.stage');
		var wrap = document.querySelector('.sg-banner');

		if (wrap) {
			wrap.classList.remove('is-on');
		}

		started = 0;
		stage('idle');

		if (phone) {
			phone.stack.innerHTML = '';
			phone.idle.hidden = false;
		}

		Array.prototype.forEach.call(document.querySelectorAll('[data-signa-form]'), function (host) {
			var form = host.signaForm;

			if (!form) {
				return;
			}

			form.root.classList.remove('is-signed-in');

			if (form.clearStatus) {
				form.clearStatus();
			}

			var input = (host.shadowRoot || host).querySelector('[data-signa-phone]');

			if (input) {
				input.value = '';
				input.dispatchEvent(new window.Event('input', { bubbles: true }));
			}

			form.resetToPhone();
		});

		if (!stageEl) {
			return;
		}

		sleepers(stageEl).forEach(function (node) {
			node.removeAttribute('inert');
			node.removeAttribute('aria-hidden');
		});
		stageEl.classList.remove('is-done');

		var box = stageEl.querySelector('.sg-win');

		if (box) {
			box.classList.remove('is-on');
			window.setTimeout(function () {
				box.remove();
			}, 400);
		}
	}

	function signedIn(info) {
		stage('done');

		var mount = document.querySelector('[data-sg-phone]');
		var next = mount ? mount.getAttribute('data-sg-next') : '';
		var wrap = document.querySelector('.sg-banner');
		var stageEl = document.querySelector('.stage');

		if (wrap) {
			wrap.classList.remove('is-on');
		}

		if (stageEl) {
			win(stageEl, info, next);
			return;
		}

		// Pages without the stage: the card drops in from the top.
		if (!wrap) {
			wrap = el('div', 'sg-banner');
			document.body.appendChild(wrap);
		}

		wrap.innerHTML = '';
		wrap.appendChild(doneCard(info, next));
		wrap.classList.add('is-on', 'is-done');
		window.clearTimeout(bannerTimer);
		bannerTimer = window.setTimeout(function () {
			wrap.classList.remove('is-on');
		}, 9000);
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

	window.SignaDemoPhone = { fill: fill, reset: reset };
})();
