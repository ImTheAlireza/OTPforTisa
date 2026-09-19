/*!
 * Tisa OTP — front-end controller.
 *
 * Dependency-free. Reads its settings from `window.tisaOtp` (localized by PHP)
 * and from data attributes on `[data-tisa-form]`, so several forms can live on
 * one page with different overrides.
 */
(function (window, document) {
	'use strict';

	var cfg = window.tisaOtp || {};
	var i18n = cfg.i18n || {};

	function $(root, selector) {
		return root.querySelector(selector);
	}

	function $$(root, selector) {
		return Array.prototype.slice.call(root.querySelectorAll(selector));
	}

	function attr(el, name) {
		return el ? String(el.getAttribute('data-' + name) || '') : '';
	}

	function latinDigits(value) {
		var fa = '۰۱۲۳۴۵۶۷۸۹';
		var ar = '٠١٢٣٤٥٦٧٨٩';

		return String(value === null || value === undefined ? '' : value).replace(/[۰-۹٠-٩]/g, function (char) {
			var index = fa.indexOf(char);

			if (index < 0) {
				index = ar.indexOf(char);
			}

			return index < 0 ? char : String(index);
		});
	}

	function digitsOnly(value) {
		return latinDigits(value).replace(/\D+/g, '');
	}

	function looksLikePhone(value) {
		var digits = digitsOnly(value);

		if (12 === digits.length && '98' === digits.substr(0, 2)) {
			digits = '0' + digits.substr(2);
		}

		if (11 === digits.length && '+' === String(value).trim().substr(0, 1)) {
			digits = '0' + digits.substr(1);
		}

		if (10 === digits.length && '9' === digits.substr(0, 1)) {
			digits = '0' + digits;
		}

		return /^09\d{9}$/.test(digits);
	}

	function flag(value, fallback) {
		if (value === undefined || value === null) {
			return fallback;
		}

		// wp_localize_script stringifies booleans, the demo config does not.
		return true === value || 1 === value || '1' === value || 'true' === value;
	}

	function httpError(code, message, data, status) {
		var error = new Error(message);

		error.code = code;
		error.data = data || {};
		error.status = status || 0;

		return error;
	}

	function text(template, replacements) {
		return String(template || '').replace(/\{(\w+)\}/g, function (match, key) {
			return Object.prototype.hasOwnProperty.call(replacements || {}, key) ? replacements[key] : match;
		});
	}

	/* ---------------------------------------------------------------- Captcha */

	function Captcha(root, conf) {
		this.conf = conf || {};
		this.container = $(root, '[data-tisa-captcha]');
		this.widgetId = null;
		this.token = '';
	}

	Captcha.prototype.enabled = function () {
		return !!(this.conf && this.conf.enabled && this.conf.config && this.conf.config.siteKey);
	};

	Captcha.prototype.isScore = function () {
		return this.enabled() && 'score' === this.conf.config.kind;
	};

	Captcha.prototype.showWidget = function () {
		if (!this.enabled() || this.isScore() || !this.container) {
			return;
		}

		this.container.hidden = false;
		this.render();
	};

	Captcha.prototype.render = function () {
		var self = this;
		var siteKey = this.conf.config.siteKey;

		if (this.widgetId || !this.container) {
			return;
		}

		var onToken = function (value) {
			self.token = value || '';
		};

		try {
			if ('hcaptcha' === this.conf.provider && window.hcaptcha) {
				this.widgetId = window.hcaptcha.render(this.container, {
					sitekey: siteKey,
					callback: onToken,
					'expired-callback': function () {
						self.token = '';
					}
				});
			} else if (window.arcaptcha && window.arcaptcha.widget) {
				this.widgetId = window.arcaptcha.widget.render(this.container, {
					site_key: siteKey,
					theme: 'light',
					dir: 'rtl',
					language: 'fa',
					on_success_callback: onToken,
					on_failure_callback: function () {
						self.token = '';
					}
				});
			} else if (window.grecaptcha && 'invisible' !== this.conf.provider) {
				this.widgetId = window.grecaptcha.render(this.container, {
					sitekey: siteKey,
					callback: onToken
				});
			}
		} catch (error) {
			this.widgetId = null;
		}
	};

	Captcha.prototype.value = function () {
		if (!this.enabled()) {
			return Promise.resolve('');
		}

		if (this.isScore()) {
			return new Promise(function (resolve) {
				if (!window.grecaptcha || !window.grecaptcha.execute) {
					resolve('');
					return;
				}

				window.grecaptcha.ready(function () {
					window.grecaptcha
						.execute(cfg.captcha.config.siteKey, { action: cfg.captcha.config.action || 'tisa_otp_send' })
						.then(function (token) {
							resolve(token || '');
						})
						.catch(function () {
							resolve('');
						});
				});
			});
		}

		if (window.hcaptcha && this.widgetId !== null) {
			try {
				this.token = window.hcaptcha.getResponse(this.widgetId) || this.token;
			} catch (error) {
				this.token = this.token || '';
			}
		}

		if (window.arcaptcha && window.arcaptcha.widget && window.arcaptcha.widget.getToken) {
			try {
				this.token = window.arcaptcha.widget.getToken() || this.token;
			} catch (error) {
				this.token = this.token || '';
			}
		}

		return Promise.resolve(this.token || '');
	};

	/* ------------------------------------------------------------------- Form */

	function Form(root) {
		this.root = root;
		this.endpoint = attr(root, 'endpoint') || cfg.restUrl || '';
		this.nonce = attr(root, 'nonce') || cfg.nonce || '';
		this.cooldown = parseInt(attr(root, 'cooldown') || cfg.cooldown || 60, 10);
		this.codeLength = parseInt(attr(root, 'code-length') || cfg.codeLength || 5, 10);
		this.flow = attr(root, 'flow') || 'fields_then_code';
		this.canRegister = '1' === attr(root, 'registration');
		this.redirect = attr(root, 'redirect') || '';

		this.configUrl = attr(root, 'config-url') || cfg.configUrl || '';
		this.cacheMode = attr(root, 'cache-mode') || cfg.cacheMode || 'inline';
		this.timeoutMs = parseInt(cfg.timeoutMs || 15000, 10);
		this.autoVerify = flag(cfg.autoVerify, true);
		this.webOtp = flag(cfg.webOtp, false);

		this.nonceRequest = null;
		this.otpAbort = null;
		this.autoVerifyTimer = null;

		this.phone = '';
		this.channel = cfg.channel || 'sms';
		this.draftToken = '';
		this.verifiedToken = '';
		this.countdown = null;
		this.expiry = null;
		this.busy = false;

		this.captcha = new Captcha(root, cfg.captcha || {});

		this.stepPhone = $(root, '[data-tisa-step="phone"]');
		this.stepFields = $(root, '[data-tisa-step="fields"]');
		this.stepCode = $(root, '[data-tisa-step="code"]');
		this.statusBox = $(root, '.tisa-otp__status');
		this.phoneInput = $(root, '[data-tisa-phone]');
		this.bulk = $(root, '[data-tisa-code-bulk]');
		this.boxes = $$(root, '[data-tisa-box]');
		this.masked = $(root, '[data-tisa-masked]');
		this.resendBtn = $(root, '[data-tisa-action="resend"]');
		this.resendLabel = $(root, '[data-tisa-resend-label]');
		this.resendLive = $(root, '[data-tisa-resend-live]');
		this.honeypot = $(root, '.tisa-otp__honeypot');
		this.timestamp = $(root, '.tisa-otp__rendered');

		if (this.bulk) {
			this.bulk.maxLength = this.codeLength;
			this.bulk.placeholder = text(i18n.codePlaceholder || '', {});
		}

		this.bind();
		this.show(this.stepPhone);

		// A cached page carries a stale nonce, so fetch a fresh one up front.
		if ('auto' === this.cacheMode) {
			this.refreshNonce();
		}

		if ('always' === (cfg.captcha || {}).trigger) {
			this.captcha.showWidget();
		}
	}

	Form.prototype.bind = function () {
		var self = this;

		$$(this.root, '[data-tisa-action]').forEach(function (button) {
			button.addEventListener('click', function (event) {
				event.preventDefault();
				self.act(button.getAttribute('data-tisa-action'));
			});
		});

		if (this.phoneInput) {
			this.phoneInput.addEventListener('input', function () {
				self.phoneInput.value = latinDigits(self.phoneInput.value);
				self.clearStatus();
				self.setValid(self.phoneInput);
			});

			this.phoneInput.addEventListener('keydown', function (event) {
				if ('Enter' === event.key) {
					event.preventDefault();
					self.act('start');
				}
			});
		}

		if (this.bulk) {
			this.bulk.addEventListener('input', function () {
				self.bulk.value = digitsOnly(self.bulk.value).substr(0, self.codeLength);
				self.spread(self.bulk.value);
				self.clearStatus();
				self.stopWebOtp();
				self.setCodeInvalid(false);
				self.maybeAutoVerify();
			});

			this.bulk.addEventListener('keydown', function (event) {
				if ('Enter' === event.key) {
					event.preventDefault();
					self.act('verify');
				}
			});
		}

		this.boxes.forEach(function (box, index) {
			box.addEventListener('input', function () {
				box.value = digitsOnly(box.value).substr(0, 1);
				self.join();
				self.stopWebOtp();
				self.setCodeInvalid(false);

				if (box.value && index < self.boxes.length - 1) {
					self.boxes[index + 1].focus();
				}

				self.maybeAutoVerify();
			});

			box.addEventListener('keydown', function (event) {
				if ('Backspace' === event.key && !box.value && index > 0) {
					event.preventDefault();
					self.boxes[index - 1].focus();
				}

				if ('ArrowLeft' === event.key && index < self.boxes.length - 1) {
					self.boxes[index + 1].focus();
				}

				if ('ArrowRight' === event.key && index > 0) {
					self.boxes[index - 1].focus();
				}

				if ('Enter' === event.key) {
					event.preventDefault();
					self.act('verify');
				}
			});

			box.addEventListener('paste', function (event) {
				var pasted = event.clipboardData ? event.clipboardData.getData('text') : '';
				var value = digitsOnly(pasted).substr(0, self.codeLength);

				if (value) {
					event.preventDefault();
					self.spread(value);
					self.join();
					self.stopWebOtp();
					self.setCodeInvalid(false);
					self.maybeAutoVerify();
				}
			});
		});

		$$(this.root, '[data-tisa-input]').forEach(function (input) {
			input.addEventListener('input', function () {
				self.setValid(input);
			});
		});

		var form = $(this.root, 'form');

		if (form) {
			form.addEventListener('submit', function (event) {
				event.preventDefault();
			});
		}
	};

	Form.prototype.act = function (action) {
		if (this.busy) {
			return;
		}

		switch (action) {
			case 'start':
				this.start();
				break;
			case 'submit-fields':
				this.submitFields();
				break;
			case 'verify':
				this.verify();
				break;
			case 'resend':
				this.resend();
				break;
			case 'edit-phone':
				this.resetToPhone();
				break;
			case 'back':
				this.show(this.stepPhone);
				break;
			default:
				break;
		}
	};

	Form.prototype.basePayload = function (route) {
		return {
			phone: this.phoneInput ? this.phoneInput.value.trim() : this.phone,
			channel: this.channel,
			redirect: this.redirect,
			tisa_hp: this.honeypot ? this.honeypot.value : '',
			tisa_ts: this.timestamp ? this.timestamp.value : String(Math.floor(Date.now() / 1000)),
			route: route
		};
	};

	Form.prototype.request = function (route, payload) {
		var self = this;
		var body = payload || {};

		delete body.route;

		if (window.navigator && false === navigator.onLine) {
			return Promise.reject(httpError('offline', i18n.offline || 'Offline'));
		}

		return this.captcha.value().then(function (token) {
			if (token) {
				body.captcha_token = token;
			}

			return self.send(route, body);
		}).then(function (result) {
			// A cached page ships somebody else's nonce: refresh and retry once.
			if (self.isStaleNonce(result)) {
				return self.refreshNonce().then(function () {
					return self.send(route, body);
				}).then(function (retry) {
					return self.unwrap(retry);
				});
			}

			return self.unwrap(result);
		});
	};

	/**
	 * Fire one request, with a timeout so a dead gateway cannot hang the form.
	 */
	Form.prototype.send = function (route, body) {
		var controller = window.AbortController ? new window.AbortController() : null;
		var headers = {
			'Content-Type': 'application/json',
			'Accept': 'application/json'
		};

		if (this.nonce) {
			headers['X-WP-Nonce'] = this.nonce;
		}

		if (controller) {
			window.setTimeout(function () {
				controller.abort();
			}, this.timeoutMs);
		}

		return window.fetch(this.endpoint + route, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify(body),
			signal: controller ? controller.signal : undefined
		}).then(function (response) {
			return response.json().catch(function () {
				return null;
			}).then(function (json) {
				return { status: response.status, ok: response.ok, json: json };
			});
		}).catch(function (error) {
			var timedOut = error && 'AbortError' === error.name;

			throw httpError(
				timedOut ? 'request_timeout' : 'network_error',
				timedOut ? (i18n.timeout || 'Timeout') : (i18n.network || 'Network error')
			);
		});
	};

	/**
	 * Did WordPress reject the nonce rather than the request itself?
	 */
	Form.prototype.isStaleNonce = function (result) {
		if (!this.configUrl || !result) {
			return false;
		}

		return 403 === result.status || 'rest_cookie_invalid_nonce' === (result.json && result.json.code);
	};

	/**
	 * Turn a transport result into data, or into an error the UI can explain.
	 *
	 * The plugin answers {success, data} and marks rejections with success:false,
	 * sometimes over HTTP 200 — so the flag is checked as well as the status.
	 */
	Form.prototype.unwrap = function (result) {
		var json = result ? result.json : null;

		if (!result || !result.ok || !json || json.success === false) {
			throw httpError(
				json && json.code ? json.code : 'request_failed',
				(json && json.message) || i18n.network || 'Request failed',
				json && json.data ? json.data : {},
				result ? result.status : 0
			);
		}

		return json.data === undefined ? json : json.data;
	};

	/**
	 * Pull a fresh nonce (and endpoint) from `GET /form-config`.
	 *
	 * Full-page caches store the nonce printed into the HTML, so a cached page
	 * would send a stale token. Refreshing on mount — and retrying once after a
	 * rejection — keeps the form working behind any cache or CDN.
	 */
	Form.prototype.refreshNonce = function () {
		var self = this;

		if (!this.configUrl || !window.fetch) {
			return Promise.resolve(this.nonce);
		}

		if (this.nonceRequest) {
			return this.nonceRequest;
		}

		this.nonceRequest = window.fetch(this.configUrl, {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { Accept: 'application/json' }
		}).then(function (response) {
			return response.json();
		}).then(function (body) {
			var data = body && body.data ? body.data : body;

			if (data && data.nonce) {
				self.nonce = data.nonce;
			}

			if (data && data.restUrl) {
				self.endpoint = data.restUrl;
			}

			self.nonceRequest = null;

			return self.nonce;
		}).catch(function () {
			// Offline or REST blocked: keep the nonce we already have.
			self.nonceRequest = null;

			return self.nonce;
		});

		return this.nonceRequest;
	};

	Form.prototype.start = function () {
		var self = this;
		var value = this.phoneInput ? this.phoneInput.value : '';

		if (!looksLikePhone(value)) {
			this.say(i18n.invalidPhone || 'Invalid phone', 'error');
			this.setInvalid(this.phoneInput, i18n.invalidPhone || 'Invalid phone');

			if (this.phoneInput) {
				this.phoneInput.focus();
			}

			return;
		}

		this.phone = digitsOnly(value);
		this.clearFieldErrors();

		this.run('start', this.basePayload('start'), function (data) {
			self.handle(data);
		}, i18n.sending || '...');
	};

	Form.prototype.submitFields = function () {
		var self = this;
		var collected = this.collect();

		if (collected.errors.length) {
			this.say(i18n.fillFields || 'Fill required fields', 'error');
			collected.errors.forEach(function (problem) {
				self.setInvalid($(self.root, '[data-tisa-input="' + problem.id + '"]'), problem.message);
			});
			return;
		}

		var payload = this.basePayload('register');
		payload.fields = collected.values;

		if (this.verifiedToken) {
			payload.verified_token = this.verifiedToken;
		}

		this.run('register', payload, function (data) {
			self.handle(data);
		}, i18n.creating || '...');
	};

	Form.prototype.verify = function () {
		var self = this;
		var code = this.codeValue();

		if (code.length < this.codeLength) {
			this.say(i18n.incompleteCode || 'Code incomplete', 'error');
			this.setCodeInvalid(true);
			return;
		}

		var payload = this.basePayload('verify');
		payload.code = code;

		if (this.draftToken) {
			payload.draft_token = this.draftToken;
		}

		if (this.verifiedToken) {
			payload.verified_token = this.verifiedToken;
		}

		this.run('verify', payload, function (data) {
			self.handle(data);
		}, i18n.checking || '...');
	};

	Form.prototype.resend = function () {
		var self = this;

		if (this.resendBtn && this.resendBtn.disabled) {
			return;
		}

		var payload = this.basePayload('code');

		if (this.draftToken) {
			payload.draft_token = this.draftToken;
		}

		this.run('code', payload, function (data) {
			self.handle(data);
		}, i18n.sending || '...');
	};

	Form.prototype.run = function (name, payload, then, working) {
		var self = this;

		this.setBusy(true, name, working);

		this.request(name, payload)
			.then(function (data) {
				self.setBusy(false, name);
				then(data);
			})
			.catch(function (error) {
				self.setBusy(false, name);
				self.fail(error);
			});
	};

	Form.prototype.handle = function (data) {
		if (!data) {
			return;
		}

		this.emit('response', data);

		if (data.masked && this.masked) {
			this.masked.textContent = data.masked;
		}

		if (data.draft_token) {
			this.draftToken = data.draft_token;
		}

		if (data.verified_token) {
			this.verifiedToken = data.verified_token;
		}

		if (data.headings) {
			this.applyHeadings(data.headings);
		}

		switch (data.step) {
			case 'signed_in':
				this.say(data.message || i18n.redirecting || '...', 'success');
				this.signedIn(data);
				break;

			case 'register_form':
				this.show(this.stepFields);
				this.say(data.message || '', 'info');
				this.focusFirst();
				break;

			case 'verify':
				this.show(this.stepCode);
				this.say(data.message || '', 'success');
				this.startCooldown(data.cooldown || this.cooldown);
				this.startExpiry(data.expires_in || cfg.ttl || 120);
				if (data.code_length) {
					this.codeLength = parseInt(data.code_length, 10);
				}
				this.focusCode();
				this.startWebOtp();
				break;

			default:
				if (data.message) {
					this.say(data.message, 'info');
				}
		}
	};

	Form.prototype.signedIn = function (data) {
		var target = data.redirect || this.redirect;

		this.root.classList.add('is-signed-in');
		this.emit('signed-in', data);

		if (!target) {
			return;
		}

		window.setTimeout(function () {
			window.location.assign(target);
		}, 400);
	};

	Form.prototype.fail = function (error) {
		var payload = error && error.data ? error.data : {};

		this.say((error && error.message) || i18n.network || 'Error', 'error');
		this.emit('error', { code: error ? error.code : '', data: payload });

		if (payload.captcha_required) {
			this.captcha.showWidget();
		}

		if (payload.retry_after) {
			this.startCooldown(parseInt(payload.retry_after, 10));
		}

		if (payload.attempts_left !== undefined && null !== payload.attempts_left) {
			this.root.setAttribute('data-attempts-left', String(payload.attempts_left));
		}

		if (payload.errors && 'object' === typeof payload.errors) {
			this.markServerErrors(payload.errors);
		}

		this.clearCode();
	};

	Form.prototype.markServerErrors = function (errors) {
		var self = this;

		Object.keys(errors).forEach(function (id) {
			var input = $(self.root, '[data-tisa-input="' + id + '"]');

			if (input) {
				self.setInvalid(input, String(errors[id]));
				return;
			}

			var error = $(self.root, '[data-tisa-error="' + id + '"]');

			if (error) {
				error.hidden = false;
				error.textContent = String(errors[id]);
			}
		});
	};

	Form.prototype.collect = function () {
		var values = {};
		var errors = [];

		$$(this.root, '[data-tisa-input]').forEach(function (input) {
			var id = input.getAttribute('data-tisa-input');
			var value = 'checkbox' === input.type ? (input.checked ? '1' : '') : String(input.value || '').trim();

			values[id] = value;

			if (input.hasAttribute('required') && '' === value) {
				errors.push({ id: id, message: i18n.requiredField || 'Required' });
			} else if ('email' === input.type && value && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(value)) {
				errors.push({ id: id, message: i18n.invalidEmail || 'Invalid email' });
			}
		});

		var seen = {};

		return { values: values, errors: errors.filter(function (problem) {
			if (seen[problem.id]) {
				return false;
			}

			seen[problem.id] = true;

			return true;
		}) };
	};

	Form.prototype.clearFieldErrors = function () {
		$$(this.root, '.has-error').forEach(function (el) {
			el.classList.remove('has-error');
		});

		$$(this.root, '[data-tisa-error]').forEach(function (el) {
			el.hidden = true;
			el.textContent = '';
		});

		$$(this.root, '[aria-invalid="true"]').forEach(function (el) {
			el.setAttribute('aria-invalid', 'false');
		});
	};

	/**
	 * Mark one control invalid and say why right next to it.
	 */
	Form.prototype.setInvalid = function (input, message) {
		if (!input) {
			return;
		}

		input.classList.add('has-error');
		input.setAttribute('aria-invalid', 'true');

		var error = this.errorNodeFor(input);

		if (error && message) {
			error.hidden = false;
			error.textContent = message;
		}
	};

	/**
	 * Undo the invalid mark once the visitor starts fixing the value.
	 */
	Form.prototype.setValid = function (input) {
		if (!input) {
			return;
		}

		input.classList.remove('has-error');
		input.setAttribute('aria-invalid', 'false');

		var error = this.errorNodeFor(input);

		if (error) {
			error.hidden = true;
			error.textContent = '';
		}
	};

	Form.prototype.errorNodeFor = function (input) {
		var key = input.getAttribute('data-tisa-input') || (input === this.phoneInput ? 'phone' : '');

		return key ? $(this.root, '[data-tisa-error="' + key + '"]') : null;
	};

	/**
	 * Flag the code inputs as a group — they share one meaning.
	 */
	Form.prototype.setCodeInvalid = function (invalid) {
		var targets = this.boxes.slice();

		if (this.bulk) {
			targets.push(this.bulk);
		}

		targets.forEach(function (el) {
			el.setAttribute('aria-invalid', invalid ? 'true' : 'false');
		});
	};

	Form.prototype.show = function (step) {
		if (step !== this.stepCode) {
			this.stopWebOtp();
		}

		[this.stepPhone, this.stepFields, this.stepCode].forEach(function (el) {
			if (el) {
				el.classList.toggle('is-current', el === step);
			}
		});

		this.root.setAttribute('data-step', step === this.stepCode ? 'code' : (step === this.stepFields ? 'fields' : 'phone'));
		this.clearStatus();
		this.emit('step', { step: step ? attr(step, 'tisa-step') : '' });
	};

	Form.prototype.resetToPhone = function () {
		this.draftToken = '';
		this.verifiedToken = '';
		this.clearCode();
		window.clearInterval(this.countdown);
		this.show(this.stepPhone);

		if (this.phoneInput) {
			this.phoneInput.focus();
			this.phoneInput.select();
		}
	};

	Form.prototype.applyHeadings = function (headings) {
		var map = {
			form: this.stepPhone,
			register: this.stepFields
		};

		Object.keys(map).forEach(function (key) {
			var step = map[key];
			var title = step ? $(step, '.tisa-step__title') : null;

			if (title && headings[key]) {
				title.textContent = headings[key];
			}
		});
	};

	Form.prototype.focusFirst = function () {
		var first = $(this.stepFields || document, '[data-tisa-input]');

		if (first) {
			first.focus();
		}
	};

	Form.prototype.focusCode = function () {
		var target = 'single' === cfg.codeInput ? this.bulk : this.boxes[0];

		if (target) {
			window.setTimeout(function () {
				target.focus();
			}, 60);
		}
	};

	/**
	 * Submit the code as soon as it is complete, without a button press.
	 */
	Form.prototype.maybeAutoVerify = function () {
		var self = this;

		if (!this.autoVerify || this.busy || this.codeValue().length < this.codeLength) {
			return;
		}

		window.clearTimeout(this.autoVerifyTimer);

		// A short beat lets a paste or a fast typist land its last digit first.
		this.autoVerifyTimer = window.setTimeout(function () {
			if (!self.busy && self.codeValue().length === self.codeLength) {
				self.act('verify');
			}
		}, 180);
	};

	/**
	 * Fill the code inputs from a string, e.g. a WebOTP credential.
	 */
	Form.prototype.fillCode = function (value) {
		var digits = digitsOnly(value).substr(0, this.codeLength);

		if (this.bulk) {
			this.bulk.value = digits;
		}

		this.spread(digits);
	};

	/**
	 * Ask the browser for the SMS code (WebOTP / `navigator.credentials.get`).
	 *
	 * Requires the `@example.com #12345` binding line in the message, which the
	 * plugin appends when WebOTP is on. Android Chrome still asks the visitor to
	 * confirm the suggestion, so nothing is filled without consent.
	 */
	Form.prototype.startWebOtp = function () {
		var self = this;

		this.stopWebOtp();

		if (!this.webOtp || !window.OTPCredential || !navigator.credentials || !navigator.credentials.get) {
			return;
		}

		var options = { otp: { transport: ['sms'] } };

		if (window.AbortController) {
			this.otpAbort = new window.AbortController();
			options.signal = this.otpAbort.signal;
		}

		navigator.credentials.get(options).then(function (credential) {
			self.otpAbort = null;

			var code = credential && credential.code ? digitsOnly(credential.code).substr(0, self.codeLength) : '';

			if (!code) {
				return;
			}

			self.stopWebOtp();
			self.fillCode(code);
			self.setCodeInvalid(false);
			self.say(i18n.otpFilled || '', 'success');

			if (code.length === self.codeLength) {
				self.act('verify');
			}
		}).catch(function () {
			// Aborted, unsupported or dismissed: the visitor types it instead.
			self.otpAbort = null;
		});
	};

	/**
	 * Drop a pending WebOTP request, e.g. when the visitor starts typing.
	 */
	Form.prototype.stopWebOtp = function () {
		if (this.otpAbort) {
			try {
				this.otpAbort.abort();
			} catch (error) {
				// Already settled.
			}

			this.otpAbort = null;
		}
	};

	Form.prototype.spread = function (value) {
		var digits = digitsOnly(value);

		this.boxes.forEach(function (box, index) {
			box.value = digits.charAt(index) || '';
		});
	};

	Form.prototype.join = function () {
		if (this.bulk) {
			this.bulk.value = this.boxes.map(function (box) {
				return box.value;
			}).join('');
		}
	};

	Form.prototype.codeValue = function () {
		if (this.boxes.length && 'single' !== cfg.codeInput) {
			return this.boxes.map(function (box) {
				return box.value;
			}).join('');
		}

		return this.bulk ? digitsOnly(this.bulk.value) : '';
	};

	Form.prototype.clearCode = function () {
		if (this.bulk) {
			this.bulk.value = '';
		}

		this.spread('');
		this.setCodeInvalid(false);
	};

	Form.prototype.startCooldown = function (seconds) {
		var self = this;
		var left = parseInt(seconds, 10) || 0;

		window.clearInterval(this.countdown);

		if (!this.resendBtn) {
			return;
		}

		if (left <= 0) {
			this.resendBtn.disabled = false;

			if (this.resendLabel) {
				this.resendLabel.textContent = (cfg.labels || {}).resend || 'Resend';
			}

			this.announceResend(i18n.resendReady || '');

			return;
		}

		this.resendBtn.disabled = true;

		var tick = function () {
			if (self.resendLabel) {
				self.resendLabel.textContent = text(i18n.resendIn || '{s}s', { s: left });
			}

			// The visible label ticks every second; the live region only speaks at
			// quarter-minute marks so screen readers are not talked over.
			if (left > 0 && 0 === left % 15) {
				self.announceResend(text(i18n.resendIn || '{s}s', { s: left }));
			}

			left -= 1;

			if (left < 0) {
				window.clearInterval(self.countdown);
				self.resendBtn.disabled = false;

				if (self.resendLabel) {
					self.resendLabel.textContent = (cfg.labels || {}).resend || 'Resend';
				}

				self.announceResend(i18n.resendReady || '');
			}
		};

		tick();
		this.countdown = window.setInterval(tick, 1000);
	};

	/**
	 * Speak the resend countdown at milestones instead of every single second.
	 */
	Form.prototype.announceResend = function (message) {
		if (this.resendLive) {
			this.resendLive.textContent = message || '';
		}
	};

	Form.prototype.startExpiry = function (seconds) {
		var self = this;
		var left = parseInt(seconds, 10) || 0;

		window.clearInterval(this.expiry);

		if (left <= 0) {
			return;
		}

		this.root.setAttribute('data-expires-in', String(left));

		this.expiry = window.setInterval(function () {
			left -= 1;
			self.root.setAttribute('data-expires-in', String(Math.max(left, 0)));

			if (left <= 0) {
				window.clearInterval(self.expiry);
				self.root.classList.add('is-expired');
				self.clearCode();
			}
		}, 1000);
	};

	Form.prototype.say = function (message, type) {
		if (!this.statusBox || !message) {
			return;
		}

		var isError = 'error' === type;

		this.statusBox.hidden = false;
		this.statusBox.textContent = message;
		this.statusBox.className = 'tisa-otp__status is-' + (type || 'info');

		// Errors interrupt; everything else waits for a pause in speech.
		this.statusBox.setAttribute('role', isError ? 'alert' : 'status');
		this.statusBox.setAttribute('aria-live', isError ? 'assertive' : 'polite');
	};

	Form.prototype.clearStatus = function () {
		if (this.statusBox) {
			this.statusBox.hidden = true;
			this.statusBox.textContent = '';
		}
	};

	Form.prototype.setBusy = function (busy, name, working) {
		this.busy = busy;
		this.root.classList.toggle('is-busy', busy);
		this.root.setAttribute('aria-busy', busy ? 'true' : 'false');

		$$(this.root, '[data-tisa-action]').forEach(function (button) {
			var action = button.getAttribute('data-tisa-action');
			var label = $(button, '.tisa-btn__label');

			button.disabled = busy;
			button.setAttribute('aria-disabled', busy ? 'true' : 'false');

			if (busy && label && action === name) {
				label.dataset.tisaOriginal = label.textContent;
				label.textContent = working || label.textContent;
			} else if (!busy && label && label.dataset.tisaOriginal) {
				label.textContent = label.dataset.tisaOriginal;
				delete label.dataset.tisaOriginal;
			}
		});
	};

	Form.prototype.emit = function (name, detail) {
		try {
			this.root.dispatchEvent(new window.CustomEvent('tisa:' + name, { bubbles: true, detail: detail || {} }));
		} catch (error) {
			// Older browsers: the event is only a convenience for custom scripts.
		}
	};

	/* ------------------------------------------------------------------ Mount */

	function mount() {
		$$(document, '[data-tisa-form]').forEach(function (root) {
			if (root.dataset.tisaMounted) {
				return;
			}

			root.dataset.tisaMounted = '1';
			root.tisaForm = new Form(root);
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', mount);
	} else {
		mount();
	}

	window.tisaOtpForms = {
		config: cfg,
		refresh: mount,
		utils: { phone: looksLikePhone, digits: digitsOnly, latin: latinDigits }
	};
})(window, document);
