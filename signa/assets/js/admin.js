/*!
 * Signa — admin screen behaviour.
 *
 * Handles the small amount of interactivity the settings and tools screens need:
 * toggles, card radios, the colour picker, the media picker, the field repeater,
 * the REST-driven tools (test send, throttle reset, importer) and the browser-side
 * generator for the emergency code.
 */
(function (window, document) {
	'use strict';

	var cfg = window.signaOtpAdmin || {};
	var i18n = cfg.i18n || {};
	var $$ = function (selector, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(selector));
	};

	function say(box, message, type) {
		if (!box) {
			return;
		}

		box.hidden = false;
		box.textContent = message || '';
		box.className = 'signa-result is-' + (type || 'info');
	}

	function api(route, payload) {
		return window.fetch((cfg.restUrl || '') + route, {
			method: payload ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			body: payload ? JSON.stringify(payload) : undefined
		}).then(function (response) {
			return response.json().then(function (body) {
				if (!response.ok || !body || body.success === false) {
					var error = new Error((body && body.message) || 'Request failed');

					// Keep the payload: the trace of a failed delivery lives here.
					error.data = (body && body.data) || {};
					error.code = (body && body.code) || '';

					throw error;
				}

				return body.data === undefined ? body : body.data;
			});
		});
	}

	/*
	 * One modal, used by every self-test: reports and event lists are pages, but
	 * "test this section" must not cost a page load. `<dialog>` brings the focus
	 * trap, the ESC key and the top layer with it.
	 */
	function modal(title) {
		var opener = document.activeElement;
		var dialog = document.createElement('dialog');
		dialog.className = 'signa-modal';

		var head = document.createElement('div');
		head.className = 'signa-modal__head';

		var heading = document.createElement('h2');
		heading.className = 'signa-modal__title';
		heading.textContent = title || '';

		var state = document.createElement('span');
		state.className = 'signa-modal__state';
		state.hidden = true;

		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'signa-modal__close';
		close.setAttribute('aria-label', i18n.close || 'بستن');
		close.textContent = '×';

		head.appendChild(heading);
		head.appendChild(state);
		head.appendChild(close);

		var body = document.createElement('div');
		body.className = 'signa-modal__body';

		var foot = document.createElement('div');
		foot.className = 'signa-modal__foot';

		dialog.appendChild(head);
		dialog.appendChild(body);
		dialog.appendChild(foot);
		document.body.appendChild(dialog);

		function finish() {
			dialog.remove();

			if (opener && opener.focus) {
				opener.focus();
			}
		}

		/*
		 * `close()` fires the event that puts the focus back. Where the element
		 * cannot be a real modal, the same markup is shown as a plain overlay and
		 * torn down by hand, so a test can still be read on an old browser
		 * instead of throwing.
		 */
		function dismiss() {
			if ('function' === typeof dialog.close) {
				dialog.close();

				return;
			}

			finish();
		}

		close.addEventListener('click', dismiss);

		// A click on the backdrop lands on the dialog element itself.
		dialog.addEventListener('click', function (event) {
			if (event.target === dialog) {
				dismiss();
			}
		});

		dialog.addEventListener('close', finish);

		if ('function' === typeof dialog.showModal) {
			dialog.showModal();
		} else {
			dialog.classList.add('signa-modal--fallback');
			dialog.setAttribute('open', '');

			document.addEventListener('keydown', function (event) {
				if ('Escape' === event.key && dialog.parentNode) {
					finish();
				}
			});
		}

		return {
			dialog: dialog,
			title: heading,
			state: state,
			body: body,
			foot: foot,
			close: close,
			button: function (label, primary, onClick) {
				var button = document.createElement('button');

				button.type = 'button';
				button.className = 'button' + (primary ? ' button-primary' : '');
				button.textContent = label;
				button.addEventListener('click', onClick);
				foot.appendChild(button);

				return button;
			}
		};
	}

	function statusWord(status) {
		var words = {
			ok: i18n.statusOk || 'ok',
			warn: i18n.statusWarn || 'warning',
			fail: i18n.statusFail || 'problem',
			info: i18n.statusInfo || 'info'
		};

		return words[status] || words.info;
	}

	/*
	 * One result row. The colour dot is decoration; the word is the actual
	 * information, and it is in the DOM for screen readers.
	 */
	function checkRow(row) {
		var status = row.status || 'info';
		var item = document.createElement('li');
		item.className = 'signa-test-row is-' + status;

		var dot = document.createElement('span');
		dot.className = 'signa-test-row__dot';
		dot.setAttribute('aria-hidden', 'true');

		var text = document.createElement('div');
		text.className = 'signa-test-row__text';

		var line = document.createElement('p');
		line.className = 'signa-test-row__line';

		var label = document.createElement('span');
		label.className = 'signa-test-row__label';
		label.textContent = row.label || '';

		var value = document.createElement('span');
		value.className = 'signa-test-row__value';
		value.textContent = row.value === undefined || row.value === null ? '' : String(row.value);

		var hidden = document.createElement('span');
		hidden.className = 'screen-reader-text';
		hidden.textContent = statusWord(status);

		line.appendChild(hidden);
		line.appendChild(label);
		line.appendChild(value);
		text.appendChild(line);

		if (row.note) {
			var note = document.createElement('p');
			note.className = 'signa-test-row__note';
			note.textContent = row.note;
			text.appendChild(note);
		}

		if (row.why) {
			var why = document.createElement('p');
			why.className = 'signa-test-row__why';
			why.dir = 'ltr';
			why.textContent = row.why;
			text.appendChild(why);
		}

		item.appendChild(dot);
		item.appendChild(text);

		return item;
	}

	/**
	 * Run one section's self-test and draw it in a modal.
	 */
	function runCheck(kind, button) {
		var box = modal(i18n.testing || '');
		var list = document.createElement('ul');
		var waiting = document.createElement('p');

		list.className = 'signa-test-rows';
		waiting.className = 'signa-modal__note';
		waiting.textContent = i18n.working || '…';

		box.body.appendChild(list);
		box.body.appendChild(waiting);
		box.close.focus();

		busy(button, true);

		function done() {
			busy(button, false);
			waiting.remove();
			box.button(i18n.close || 'بستن', false, function () {
				box.dialog.close();
			});
			box.button(i18n.rerun || 'اجرای دوباره', true, function () {
				box.dialog.close();
				runCheck(kind, button);
			});
		}

		api('admin/check', { kind: kind }).then(function (data) {
			box.title.textContent = data.title || '';
			box.state.hidden = false;
			box.state.textContent = data.ok ? (i18n.statusOk || '') : (i18n.statusFail || '');
			box.state.className = 'signa-modal__state is-' + (data.ok ? 'ok' : 'fail');

			if (data.summary) {
				var summary = document.createElement('p');
				summary.className = 'signa-modal__summary';
				summary.textContent = data.summary;
				box.body.insertBefore(summary, list);
			}

			(data.rows || []).forEach(function (row) {
				list.appendChild(checkRow(row));
			});

			done();

			// The captcha test is the one check that cannot run on the server:
			// it has to load the real script in the administrator's browser.
			if ('security' === kind) {
				testCaptcha(function (row) {
					list.appendChild(checkRow(row));
				});
			}
		}).catch(function (error) {
			list.appendChild(checkRow({
				label: i18n.failed || '',
				value: error.message,
				status: 'fail'
			}));
			done();
		});
	}

	/**
	 * The one test that is only useful if the administrator types a number they
	 * actually hold: a real code, through the real gateway chain.
	 */
	function runSendTest(opener) {
		var box = modal(i18n.smsTitle || '');
		var list = document.createElement('ul');

		list.className = 'signa-test-rows';
		box.body.appendChild(list);

		var intro = document.createElement('p');
		intro.className = 'signa-modal__summary';
		intro.textContent = i18n.smsIntro || '';
		box.body.appendChild(intro);

		var row = document.createElement('p');
		row.className = 'signa-modal__form';

		var phone = document.createElement('input');
		phone.type = 'tel';
		phone.dir = 'ltr';
		phone.className = 'regular-text';
		phone.placeholder = '09xxxxxxxxx';
		phone.value = cfg.myPhone || '';

		var channel = document.createElement('select');
		[['sms', i18n.smsChannel || ''], ['email', i18n.emailChannel || '']].forEach(function (option) {
			var node = document.createElement('option');

			node.value = option[0];
			node.textContent = option[1];
			channel.appendChild(node);
		});

		row.appendChild(phone);
		row.appendChild(channel);
		box.body.appendChild(row);

		var send = box.button(i18n.smsSend || '', true, function () {
			var number = phone.value.trim();

			list.innerHTML = '';

			if (!number) {
				list.appendChild(checkRow({ label: i18n.smsPhone || '', value: '', status: 'fail', note: i18n.smsNeedPhone || '' }));
				phone.focus();

				return;
			}

			busy(send, true);

			api('admin/test', { phone: number, channel: channel.value }).then(function (data) {
				busy(send, false);
				/*
				 * The code went out, but not through the channel this button
				 * asks about. A green «ارسال شد» on a failed SMS test is the
				 * kind of green that costs an owner an afternoon.
				 */
				list.appendChild(checkRow({
					label: false === data.direct ? (i18n.smsNotSent || '') : (i18n.smsSent || ''),
					value: data.masked || number,
					status: false === data.direct ? 'warn' : 'ok',
					note: (data.via ? (i18n.smsVia || '') + ' ' + (data.carrier_label || data.carrier || data.via) : '') + (data.message ? ' — ' + data.message : '')
				}));

				traceRows(list, data.trace, data.plan, data.fix);
			}).catch(function (error) {
				busy(send, false);

				var data = error.data || {};

				list.appendChild(checkRow({
					label: i18n.failed || '',
					value: error.message,
					status: 'fail',
					note: data.reason || ''
				}));

				traceRows(list, data.trace, data.plan, data.fix);
			});
		});

		box.button(i18n.close || 'بستن', false, function () {
			box.dialog.close();
		});

		phone.focus();

		// The opener is only used for focus return; keep the parameter honest.
		void opener;
	}

	/**
	 * Every gateway that was tried, in order, with what it answered.
	 */
	function traceRows(list, trace, plan, fix) {
		(trace || []).forEach(function (step) {
			/*
			 * The Persian sentence says what to do; the reason is the sentence
			 * from the server that says what happened ("DNS: could not resolve
			 * host api.sms.ir"). Both belong here — "transport" alone is what
			 * this project was told off for — but the server's sentence is
			 * English and machine-shaped, so it gets its own quiet line
			 * underneath instead of being run into the Persian one.
			 */
			list.appendChild(checkRow({
				label: (step.gateway || '') + (step.sent ? ' (' + (i18n.traceSent || '') + ')' : ''),
				value: step.error_code || step.status || '',
				status: step.sent ? 'ok' : 'fail',
				note: step.message || '',
				why: step.reason || ''
			}));
		});

		if (plan && plan.issues && plan.issues.length) {
			list.appendChild(checkRow({
				label: i18n.planIssues || '',
				value: '',
				status: 'warn',
				note: plan.issues.join(' · ')
			}));
		}

		if (fix) {
			list.appendChild(checkRow({
				label: i18n.fix || '',
				value: '',
				status: 'info',
				note: fix
			}));
		}
	}

	function initSelfTests() {
		$$('[data-signa-check]').forEach(function (button) {
			button.addEventListener('click', function () {
				runCheck(button.getAttribute('data-signa-check'), button);
			});
		});

		$$('[data-signa-sms-test]').forEach(function (button) {
			button.addEventListener('click', function () {
				runSendTest(button);
			});
		});
	}

	function busy(button, on, label) {
		if (!button) {
			return;
		}

		if (on) {
			button.dataset.signaLabel = button.textContent;
			button.disabled = true;
			button.textContent = label || i18n.working || '...';
		} else {
			button.disabled = false;
			button.textContent = button.dataset.signaLabel || button.textContent;
		}
	}

	/* Toggles, cards and colour picker -------------------------------------- */

	function initControls() {
		$$('.signa-toggle').forEach(function (toggle) {
			var input = toggle.querySelector('input');

			if (!input) {
				return;
			}

			var sync = function () {
				toggle.classList.toggle('is-on', input.checked);
			};

			sync();
			input.addEventListener('change', sync);
		});

		$$('.signa-cards').forEach(function (group) {
			var sync = function () {
				$$('.signa-card', group).forEach(function (card) {
					var input = card.querySelector('input[type="radio"]');
					card.classList.toggle('is-selected', !!(input && input.checked));
				});
			};

			sync();
			group.addEventListener('change', sync);
		});

		if (window.jQuery && window.jQuery.fn.wpColorPicker) {
			window.jQuery('.signa-color').wpColorPicker();
		}

		var saved = document.querySelector('[data-signa-saved]');

		if (saved && /[?&]settings-updated=true/.test(window.location.search)) {
			saved.hidden = false;
			window.setTimeout(function () {
				saved.hidden = true;
			}, 2600);
		}

		$$('[data-signa-confirm]').forEach(function (link) {
			link.addEventListener('click', function (event) {
				if (!window.confirm(i18n.confirm || 'Are you sure?')) {
					event.preventDefault();
				}
			});
		});
	}

	/* Media picker ---------------------------------------------------------- */

	function initMedia() {
		if (!window.wp || !window.wp.media) {
			return;
		}

		var frame = null;

		$$('[data-signa-pick-media]').forEach(function (button) {
			button.addEventListener('click', function () {
				var target = document.getElementById(button.getAttribute('data-signa-pick-media'));

				if (!target) {
					return;
				}

				if (!frame) {
					frame = window.wp.media({
						title: i18n.pickImage || 'Media',
						multiple: false,
						library: { type: 'image' }
					});
				}

				frame.off('select');
				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					target.value = attachment.url || '';
					target.dispatchEvent(new window.Event('change'));
				});

				frame.open();
			});
		});
	}

	/* Registration field repeater ------------------------------------------- */

	function initRepeater() {
		$$('[data-signa-repeater]').forEach(function (repeater) {
			var rows = repeater.querySelector('[data-signa-rows]');
			var add = repeater.querySelector('[data-signa-add-row]');
			var template = repeater.querySelector('[data-signa-row-template]');

			var reindex = function () {
				$$('.signa-repeater__row', rows).forEach(function (row, index) {
					$$('input,select,textarea', row).forEach(function (input) {
						input.name = input.name.replace(/\[\d+\]/, '[' + index + ']');
					});
				});
			};

			if (add && template && rows) {
				add.addEventListener('click', function () {
					var html = template.innerHTML.replace(/__i__/g, String($$('.signa-repeater__row', rows).length));
					rows.insertAdjacentHTML('beforeend', html);
					reindex();

					var last = rows.lastElementChild;
					var first = last ? last.querySelector('input') : null;

					if (first) {
						first.focus();
					}
				});
			}

			if (rows) {
				rows.addEventListener('click', function (event) {
					var trigger = event.target.closest('[data-signa-remove-row]');

					if (!trigger) {
						return;
					}

					var row = trigger.closest('.signa-repeater__row');

					if (row) {
						row.remove();
						reindex();
					}
				});
			}
		});
	}

	/* Tools ----------------------------------------------------------------- */

	/**
	 * Try to load the captcha the way the login page does.
	 *
	 * The dashboard can reach the captcha API over HTTP and still have every
	 * visitor blocked — the script is loaded by the *browser*, and that is the
	 * step that fails most often (an ad blocker, a DNS filter, a mirror that
	 * moved). So this walks the same URL list the front end walks and reports
	 * the first one that actually answers.
	 */
	function testCaptcha(report) {
		var captcha = cfg.captcha || {};
		var urls = [captcha.script].concat(captcha.fallbacks || []).filter(Boolean);
		var index = 0;

		function finish(ok, message, url) {
			report({
				label: i18n.captchaRow || '',
				value: url || '',
				status: ok ? 'ok' : 'fail',
				note: message
			});
		}

		report({
			label: i18n.captchaTrying || '',
			value: String(urls.length),
			status: 'info',
			note: urls.join(' · ')
		});

		if (!urls.length) {
			finish(false, i18n.captchaNoScript || '');

			return;
		}

		function attempt() {
			if (index >= urls.length) {
				finish(false, i18n.captchaBlocked || '');

				return;
			}

			var url = urls[index++];
			var script = document.createElement('script');
			var settled = false;
			var timer = window.setTimeout(function () {
				if (!settled) {
					settled = true;
					script.remove();
					attempt();
				}
			}, 8000);

			script.src = url;
			script.async = true;

			script.onload = function () {
				window.clearTimeout(timer);


				if (settled) {
					return;
				}

				settled = true;

				// The file can load and still not register its API: that is the
				// failure the plugin's own changelog was written about.
				var name = captcha.global;

				if (name && !window[name]) {
					attempt();

					return;
				}

				finish(true, i18n.captchaOk || '', url);
			};

			script.onerror = function () {
				window.clearTimeout(timer);

				if (!settled) {
					settled = true;
					attempt();
				}
			};

			document.head.appendChild(script);
		}

		attempt();
	}

	function initTools() {
		initDoctor();

		// The captcha button belongs to the security section's self-test (initSelfTests).

		var testButton = document.querySelector('[data-signa-test-send]');

		if (testButton) {
			testButton.addEventListener('click', function () {
				var phone = document.querySelector('[data-signa-test-phone]');
				var channel = document.querySelector('[data-signa-test-channel]');
				var result = document.querySelector('[data-signa-test-result]');

				busy(testButton, true);

				api('admin/test', {
					phone: phone ? phone.value : '',
					channel: channel ? channel.value : 'sms'
				}).then(function (data) {
					busy(testButton, false);
					say(result, (data.message || i18n.done || 'Done') + (data.via ? ' — ' + data.via : ''), 'success');
					describeTrace(data);
				}).catch(function (error) {
					busy(testButton, false);
					say(result, error.message, 'error');
					describeTrace(error.data || {});
				});
			});
		}

		var resetButton = document.querySelector('[data-signa-reset-throttle]');

		if (resetButton) {
			resetButton.addEventListener('click', function () {
				var result = document.querySelector('[data-signa-house-result]');

				busy(resetButton, true);

				api('admin/throttle-reset', {}).then(function (data) {
					busy(resetButton, false);
					say(result, data.message || i18n.done || 'Done', 'success');
				}).catch(function (error) {
					busy(resetButton, false);
					say(result, error.message, 'error');
				});
			});
		}

		initImporter();
	}

	/**
	 * Render every gateway that was tried, in order, with the upstream reason.
	 * This is the difference between "the code was not sent" and "SMS.ir answered
	 * 401: کلید API نامعتبر است".
	 */
	function describeTrace(payload) {
		var box = document.querySelector('[data-signa-doctor-result]');
		var trace = payload && payload.trace ? payload.trace : [];

		if (!box || !trace.length) {
			return;
		}

		box.hidden = false;
		box.textContent = '';
		box.className = 'signa-result is-info';

		var title = document.createElement('p');
		title.textContent = i18n.traceTitle || 'مسیر تلاش برای ارسال:';
		box.appendChild(title);

		var list = document.createElement('ul');
		list.className = 'signa-trace';

		trace.forEach(function (step) {
			var item = document.createElement('li');
			item.className = step.sent ? 'is-good' : 'is-bad';
			item.textContent = (step.gateway || step.configured) + ' — ' +
				(step.sent
					? (i18n.traceSent || 'ارسال شد')
					: (step.error_code || 'failed') + (step.status ? ' (HTTP ' + step.status + ')' : '') + (step.message ? ' — ' + step.message : ''));
			list.appendChild(item);
		});

		box.appendChild(list);
	}

	/* Doctor ---------------------------------------------------------------- */

	function initDoctor() {
		var card = document.querySelector('[data-signa-doctor]');

		if (!card) {
			return;
		}

		var report = card.querySelector('[data-signa-doctor-report]');
		var result = card.querySelector('[data-signa-doctor-result]');
		var button = card.querySelector('[data-signa-doctor-refresh]');

		function load() {
			busy(button, true);

			api('admin/doctor').then(function (data) {
				busy(button, false);
				render(data);
			}).catch(function (error) {
				busy(button, false);
				say(result, error.message, 'error');
			});
		}

		function render(data) {
			report.hidden = false;
			report.textContent = '';

			report.appendChild(row(
				(cfg.i18n && cfg.i18n.captcha) || 'کپچا',
				captchaSummary(data.captcha),
				true
			));

			var gateways = data.gateways || {};

			Object.keys(gateways).forEach(function (id) {
				var gateway = gateways[id] || {};
				var plan = gateway.plan || {};
				var healthy = !!(gateway.health && gateway.health.ok);

				var box = row(
					(gateway.label || id) + (gateway.active ? ' • سامانه اصلی' : (gateway.backup ? ' • پشتیبان' : '')),
					[].concat(
							summaryLine(plan, healthy),
						gateway.resting ? [restingLine(gateway.blocked_until)] : [],
						(gateway.issues || []).concat(plan.issues || []),
						(gateway.notes || []).concat(plan.notes || []),
						[gateway.health_text || '']
					),
					healthy && !gateway.resting && (gateway.issues || []).length === 0 && (plan.issues || []).length === 0
				);

				if (plan.endpoint) {
					box.appendChild(probeButton(id, plan.endpoint, result));
				}

				report.appendChild(box);
			});

			if (data.channels && data.channels.email) {
				report.appendChild(row(
					data.channels.email.label || 'ایمیل',
					[data.channels.email.available ? (cfg.i18n && cfg.i18n.ok) || 'فعال' : (data.channels.email.reason || '')],
					!!data.channels.email.available
				));
			}
		}

		/**
		 * A gateway that failed three times in a row is skipped for a while, so the
		 * screen has to say that — otherwise "why is the backup sending?" becomes
		 * the next support question.
		 */
		function restingLine(until) {
			var left = Math.max(0, Math.ceil((until - Math.floor(Date.now() / 1000)) / 60));

			return 'موقتاً کنار گذاشته شده است؛ ' + left + ' دقیقه دیگر دوباره امتحان می‌شود.';
		}

		function summaryLine(plan, healthy) {
			if ('pattern' === plan.mode) {
				return 'مسیر ارسال: پترن' + (plan.template ? ' (' + plan.template + ')' : '') + (plan.sender ? ' • خط ' + plan.sender : '');
			}

			return 'مسیر ارسال: متن آزاد' + (plan.sender ? ' • خط ' + plan.sender : ' • بدون شماره خط');
		}

		function row(title, lines, ok) {
			var box = document.createElement('div');
			box.className = 'signa-doctor__row' + (ok ? ' is-good' : ' is-bad');

			var head = document.createElement('p');
			head.className = 'signa-doctor__title';
			head.textContent = title;
			box.appendChild(head);

			(lines || []).forEach(function (line) {
				if (!line) {
					return;
				}

				var item = document.createElement('p');
				item.className = 'signa-doctor__line';
				item.textContent = line;
				box.appendChild(item);
			});

			return box;
		}

		function captchaSummary(captcha) {
			captcha = captcha || {};

			if (!captcha.enabled) {
				return [captcha.halfConfigured
					? 'سرویس انتخاب شده اما کلیدها کامل نیست؛ کپچا نمایش داده نمی‌شود.'
					: 'کپچا غیرفعال است.'];
			}

			return [
				'سرویس: ' + (captcha.label || captcha.provider) + ' • نوع: ' + (captcha.kind || '—'),
				'حالت: ' + ('always' === captcha.trigger ? 'همیشه' : 'پس از چند تلاش') + ' • در قطعی سرویس: ' + (captcha.failOpen ? 'ورود باز می‌ماند' : 'ورود بسته می‌شود'),
				(captcha.scripts || []).length + ' نشانی اسکریپت برای امتحان کردن'
			].concat(
				(captcha.scripts || []).length ? [probeTarget(captcha.scripts[0])] : []
			);
		}

		function probeTarget(url) {
			return 'اسکریپت: ' + url;
		}

		function probeButton(service, url, resultBox) {
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'button button-small';
			button.textContent = 'بررسی دسترسی خروجی';

			button.addEventListener('click', function () {
				busy(button, true);

				api('admin/probe', { service: service }).then(function (data) {
					busy(button, false);
					say(resultBox, data.message + ' — ' + data.url, data.ok ? 'success' : 'error');
				}).catch(function (error) {
					busy(button, false);
					say(resultBox, error.message, 'error');
				});
			});

			return button;
		}

		if (button) {
			button.addEventListener('click', load);
		}
	}

	function initImporter() {
		var box = document.querySelector('[data-signa-import]');

		if (!box) {
			return;
		}

		var startButton = box.querySelector('[data-signa-import-start]');
		var undoButton = box.querySelector('[data-signa-import-undo]');
		var progress = box.querySelector('.signa-progress');
		var bar = box.querySelector('[data-signa-progress-bar]');
		var result = box.querySelector('[data-signa-import-result]');
		var report = box.querySelector('[data-signa-import-report]');
		var jobId = '';

		var render = function (data) {
			jobId = data.job_id || jobId;

			if (progress) {
				progress.hidden = false;

				var total = data.total > 0 ? data.total : 1;
				var percent = Math.min(100, Math.round((data.cursor / total) * 100));

				if (bar) {
					bar.style.width = percent + '%';
					bar.textContent = percent + '%';
				}
			}

			if (report) {
				var lines = [
					'migrated: ' + data.migrated,
					'skipped: ' + data.skipped,
					'conflicts: ' + data.conflicts,
					'status: ' + data.status
				];

				(data.errors || []).forEach(function (error) {
					lines.push('! user ' + (error.user_id || '?') + ' — ' + (error.reason || ''));
				});

				report.hidden = false;
				report.textContent = lines.join('\n') + (data.csv ? '\n\nundo:\n' + data.csv : '');
			}

			if (undoButton) {
				undoButton.hidden = !jobId;
			}

			say(result, data.done ? (i18n.done || 'Done') + ' — ' + data.status : (i18n.working || 'Working') + '…', data.done ? 'success' : 'info');

			return data;
		};

		var step = function () {
			return api('admin/import/step', { job_id: jobId }).then(function (data) {
				render(data);

				if (!data.done) {
					return window.setTimeout(step, 40);
				}

				busy(startButton, false);

				return data;
			}).catch(function (error) {
				busy(startButton, false);
				say(result, error.message, 'error');
			});
		};

		if (startButton) {
			startButton.addEventListener('click', function () {
				var source = box.querySelector('[data-signa-import-source]');
				var custom = box.querySelector('[data-signa-import-custom]');
				var conflict = box.querySelector('[data-signa-import-conflict]');
				var dry = box.querySelector('[data-signa-import-dry]');

				busy(startButton, true);
				say(result, i18n.working || 'Working', 'info');

				api('admin/import/start', {
					source: source && source.value ? source.value : 'custom',
					custom_key: custom ? custom.value.trim() : '',
					conflict: conflict ? conflict.value : 'skip',
					dry_run: !!(dry && dry.checked)
				}).then(function (data) {
					render(data);

					if (data.done) {
						busy(startButton, false);
						return data;
					}

					return step();
				}).catch(function (error) {
					busy(startButton, false);
					say(result, error.message, 'error');
				});
			});
		}

		if (undoButton) {
			undoButton.addEventListener('click', function () {
				if (!jobId) {
					return;
				}

				busy(undoButton, true);

				api('admin/import/undo', { job_id: jobId }).then(function (data) {
					busy(undoButton, false);
					render(data);
					say(result, i18n.done || 'Done', 'success');
				}).catch(function (error) {
					busy(undoButton, false);
					say(result, error.message, 'error');
				});
			});
		}
	}

	/**
	 * The emergency code is generated in the browser, never on the server, so
	 * the plaintext exists only in the administrator's own tab.
	 */
	function initEmergency() {
		var button = document.querySelector('[data-signa-emergency-generate]');

		if (!button) {
			return;
		}

		button.addEventListener('click', function () {
			var field = document.querySelector('[data-signa-emergency-code]');

			if (!field) {
				return;
			}

			var length = 8;
			var digits = '';

			if (window.crypto && window.crypto.getRandomValues) {
				var buffer = new Uint32Array(length);
				window.crypto.getRandomValues(buffer);

				for (var i = 0; i < length; i++) {
					digits += String(buffer[i] % 10);
				}
			} else {
				for (var j = 0; j < length; j++) {
					digits += String(Math.floor(Math.random() * 10));
				}
			}

			field.value = digits;
			field.focus();
		});
	}

	function ready(callback) {
		if ('loading' === document.readyState) {
			document.addEventListener('DOMContentLoaded', callback);
		} else {
			callback();
		}
	}

	/*
	 * `admin_body_class` can only reach the body, but the WordPress toolbar
	 * reserves its room on <html>. Modern browsers get this from `:has()` in the
	 * stylesheet; this line is for the ones that do not.
	 */
	function initAppMode() {
		if (document.body && document.body.classList.contains('signa-app')) {
			document.documentElement.classList.add('signa-app');
		}
	}

	ready(function () {
		initAppMode();
		initControls();
		initSelfTests();
		initMedia();
		initRepeater();
		initTools();
		initEmergency();
	});
})(window, document);
