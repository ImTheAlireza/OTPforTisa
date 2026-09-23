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

	/**
	 * Persian digits for prose (countdown, attempts, step numbers).
	 *
	 * Never used for values sent to the server or written into an input — those
	 * stay Latin so `inputmode="numeric"` keyboards and validation still work.
	 */
	function faDigits(value) {
		var fa = '۰۱۲۳۴۵۶۷۸۹';

		return String(value === null || value === undefined ? '' : value).replace(/[0-9]/g, function (char) {
			return fa.charAt(Number(char));
		});
	}

	/**
	 * Every spelling of an Iranian mobile number, folded to `09xxxxxxxxx`.
	 *
	 * This is what gets sent, not what got typed. Without it the field could
	 * accept `9123456789` (which the old validator did) and then post those ten
	 * digits, which the server — correctly — rejected as an invalid number.
	 */
	/**
	 * Can this browser hand us the clipboard at all?
	 *
	 * `readText` only exists in a secure context, so a plain-http site gets no
	 * promise to reject — the function is simply absent.
	 */
	function supportsClipboardRead() {
		return !!(window.navigator && navigator.clipboard && navigator.clipboard.readText);
	}

	function canonicalPhone(value) {
		var digits = digitsOnly(value);

		// `00` is how the international prefix is dialled in Iran; people paste
		// `0098912…` straight out of their contacts, so fold it first.
		if (0 === digits.indexOf('00')) {
			digits = digits.substr(2);
		}

		if (12 === digits.length && '98' === digits.substr(0, 2)) {
			return '0' + digits.substr(2);
		}

		if (11 === digits.length && '0' === digits.charAt(0)) {
			return digits;
		}

		if (11 === digits.length && '9' === digits.charAt(0)) {
			return '0' + digits.substr(1);
		}

		if (10 === digits.length && '9' === digits.charAt(0)) {
			return '0' + digits;
		}

		return digits;
	}

	function looksLikePhone(value) {
		return /^09\d{9}$/.test(canonicalPhone(value));
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

	/*
	 * Captcha loading is the part that breaks in the real world, so it is the
	 * part written defensively here.
	 *
	 * What went wrong before:
	 *   - the vendor script was assumed to be present the instant the form was
	 *     mounted, so a slow or blocked bundle meant `render()` silently did
	 *     nothing and the visitor stared at an empty space;
	 *   - ARCaptcha was called through `arcaptcha.widget.*`, an object the
	 *     library has never exposed, so the Iranian widget never rendered at all;
	 *   - every failure resolved to an empty token, and the server answered
	 *     "prove you are not a robot" while showing no robot test.
	 *
	 * Now: the script list is walked (mirrors included), the widget is rendered
	 * after the library is really there, a failure is *visible* with a retry
	 * button, and the last chance is a clear sentence instead of a dead end.
	 */

	var CAPTCHA_GLOBALS = {
		recaptcha_v3: 'grecaptcha',
		hcaptcha: 'hcaptcha',
		arcaptcha: 'arcaptcha'
	};

	function Captcha(root, conf) {
		this.conf = conf || {};
		this.config = this.conf.config || {};
		/*
		 * Why the last attempt produced no token. The server cannot see the
		 * browser, so this is the only party that can report "the challenge never
		 * became available here" — and it is reported, not assumed: the guard only
		 * acts on it for an administrator who chose to stay open during an outage.
		 */
		this.state = '';

		this.container = $(root, '[data-tisa-captcha]');
		this.widgetId = null;
		this.token = '';
		this.pending = null;
		this.unavailable = false;
		this.solving = false;
		this.onSolved = null;
	}

	/**
	 * Adopt a fresher bundle from `/form-config` (new nonce, possibly new mirror
	 * list) without losing the widget that is already on screen.
	 */
	Captcha.prototype.absorb = function (bundle) {
		if (!bundle || !bundle.enabled) {
			return;
		}

		var key;

		for (key in bundle) {
			if (Object.prototype.hasOwnProperty.call(bundle, key)) {
				this.conf[key] = bundle[key];
			}
		}

		this.config = this.conf.config || {};
		this.pending = null;
	};

	Captcha.prototype.enabled = function () {
		return !!(this.conf && this.conf.enabled && this.siteKey());
	};

	Captcha.prototype.siteKey = function () {
		return String(this.config.siteKey || this.conf.siteKey || '');
	};

	Captcha.prototype.kind = function () {
		return String(this.conf.kind || this.config.kind || 'widget');
	};

	Captcha.prototype.isScore = function () {
		return this.enabled() && 'score' === this.kind();
	};

	Captcha.prototype.globalName = function () {
		return CAPTCHA_GLOBALS[this.conf.provider] || '';
	};

	Captcha.prototype.library = function () {
		var name = this.globalName();

		return name ? window[name] || null : null;
	};

	/**
	 * Walk every script URL until one defines the library.
	 *
	 * A bundle that loads but never defines the global (blocked by an extension,
	 * a captive portal HTML page, a wrong mirror) is treated exactly like a
	 * network failure: the next URL is tried.
	 */
	Captcha.prototype.load = function () {
		var self = this;

		if (this.pending) {
			return this.pending;
		}

		if (!this.enabled()) {
			return Promise.resolve(true);
		}

		var name = this.globalName();
		var urls = (this.conf.scripts || []).slice();

		if (!urls.length && this.conf.script) {
			urls.push(this.conf.script);
		}

		if (!name || !urls.length) {
			return Promise.resolve(!!this.library());
		}

		if (this.library()) {
			this.pending = Promise.resolve(true);

			return this.pending;
		}

		this.pending = new Promise(function (resolve) {
			var index = 0;
			var budget = parseInt(self.conf.loadTimeout || 8000, 10);

			function waitForGlobal(deadline) {
				if (window[name]) {
					resolve(true);
					return;
				}

				if (Date.now() > deadline) {
					next();
					return;
				}

				window.setTimeout(function () {
					waitForGlobal(deadline);
				}, 80);
			}

			function next() {
				if (index >= urls.length) {
					resolve(false);
					return;
				}

				var url = urls[index++];
				var script = document.createElement('script');
				var settled = false;
				var timer = window.setTimeout(function () {
					settled = true;
					cleanup();
					next();
				}, budget);

				function cleanup() {
					window.clearTimeout(timer);

					if (script.parentNode) {
						script.parentNode.removeChild(script);
					}
				}

				script.async = true;
				script.defer = true;
				script.setAttribute('data-tisa-captcha-script', url);

				script.onload = function () {
					if (settled) {
						return;
					}

					settled = true;
					// Give the bundle a moment to define its global.
					waitForGlobal(Date.now() + 2500);
				};

				script.onerror = function () {
					if (settled) {
						return;
					}

					settled = true;
					cleanup();
					next();
				};

				script.src = url;
				document.head.appendChild(script);
			}

			next();
		});

		return this.pending;
	};

	/**
	 * Make the challenge ready to answer: script loaded, widget rendered.
	 */
	Captcha.prototype.prepare = function () {
		var self = this;

		if (!this.enabled()) {
			return Promise.resolve(true);
		}

		return this.load().then(function (ok) {
			if (!ok || !self.library()) {
				self.markUnavailable();
				return false;
			}

			if (!self.isScore() && !self.isInvisible()) {
				self.render();
			}

			return true;
		});
	};

	Captcha.prototype.isInvisible = function () {
		return 'invisible' === this.config.size || 'invisible' === this.conf.size;
	};

	/**
	 * Render the widget once, into the container the templates print.
	 */
	Captcha.prototype.render = function () {
		var self = this;
		var siteKey = this.siteKey();
		var lib = this.library();

		if (null !== this.widgetId || !this.container || !lib || !lib.render) {
			return;
		}

		this.show();

		var onToken = function (value) {
			self.token = value || self.token;

			if (self.onSolved) {
				var callback = self.onSolved;
				self.onSolved = null;
				callback();
			}
		};

		var params = { sitekey: siteKey, site_key: siteKey, callback: onToken };

		if ('hcaptcha' === this.conf.provider) {
			params = {
				sitekey: siteKey,
				callback: onToken,
				'expired-callback': function () {
					self.token = '';
				},
				'error-callback': function () {
					self.markUnavailable();
				}
			};

			if (this.config.lang) {
				params.hl = String(this.config.lang).replace('_', '-').split('-')[0];
			}
		} else if ('arcaptcha' === this.conf.provider) {
			params = {
				site_key: siteKey,
				lang: this.config.lang || 'fa',
				dir: this.config.dir || 'rtl',
				theme: this.config.theme || 'light',
				callback: onToken,
				'error-callback': function () {
					self.token = '';
				},
				'expired-callback': function () {
					self.token = '';
				}
			};
		}

		try {
			this.widgetId = lib.render(this.container, params);
		} catch (error) {
			this.widgetId = null;
			this.token = '';
		}
	};

	Captcha.prototype.show = function () {
		if (!this.container) {
			return;
		}

		// A previous failure left an error block behind; clear it.
		if (this.container.querySelector('.tisa-captcha__error')) {
			this.container.textContent = '';
		}

		this.container.hidden = false;
	};

	/**
	 * The script could not be loaded. Say so, and offer the way out.
	 */
	Captcha.prototype.markUnavailable = function () {
		var self = this;

		this.unavailable = true;

		if (!this.container) {
			return;
		}

		this.show();
		this.container.textContent = '';

		var box = document.createElement('div');
		box.className = 'tisa-captcha__error';
		box.setAttribute('role', 'alert');

		var message = document.createElement('p');
		message.className = 'tisa-captcha__error-text';
		message.textContent = i18n.captchaLoad || 'تأیید امنیتی بارگذاری نشد.';

		var retry = document.createElement('button');
		retry.type = 'button';
		retry.className = 'tisa-link';
		retry.setAttribute('data-tisa-captcha-retry', '1');
		retry.textContent = i18n.captchaRetry || 'تلاش دوباره';

		retry.addEventListener('click', function () {
			self.retryNow();
		});

		box.appendChild(message);
		box.appendChild(retry);

		if (this.conf.failOpen && this.conf.siteKey) {
			var hint = document.createElement('p');
			hint.className = 'tisa-captcha__error-hint';
			hint.textContent = i18n.captchaContinue || 'می‌توانید بدون تأیید امنیتی ادامه دهید.';
			box.appendChild(hint);
		}

		this.container.appendChild(box);
		this.emitRetry();
	};

	Captcha.prototype.emitRetry = function () {
		// Nothing to announce: the error block is already in a live region.
	};

	Captcha.prototype.retryNow = function () {
		this.unavailable = false;
		this.pending = null;
		this.widgetId = null;
		this.token = '';

		if (this.container) {
			this.container.textContent = '';
		}

		var self = this;

		this.prepare().then(function (ok) {
			if (!ok) {
				return;
			}

			if (self.onSolved) {
				var callback = self.onSolved;
				self.onSolved = null;
				callback();
			}
		});
	};

	/**
	 * The token for this request. Never resolves to "nothing" silently when the
	 * server is going to require a challenge: either we have a token, or the
	 * caller is told exactly why we do not.
	 */
	Captcha.prototype.value = function () {
		var self = this;

		this.state = '';

		if (!this.enabled()) {
			return Promise.resolve('');
		}

		if (this.unavailable) {
			return this.missing();
		}

		return this.prepare().then(function (ok) {
			if (!ok) {
				return self.missing();
			}

			if (self.isScore()) {
				return self.executeScore();
			}

			return self.widgetToken();
		});
	};

	/**
	 * The challenge never became usable in this browser.
	 *
	 * Two very different things used to happen here, and both were wrong: the
	 * form was sent with an empty token (so the server logged a rejection that
	 * looked like a bot), or the visitor was stopped with a message about their
	 * own identity. What is true is that the service — not the visitor — is
	 * unreachable from here, which is exactly the case `failOpen` exists for.
	 *
	 * With fail-open on, the request goes without a token and says why. With it
	 * off, the visitor is told, and nothing is sent: a rejection nobody asked for
	 * would only land in the administrator's statistics as a mystery.
	 */
	Captcha.prototype.missing = function () {
		if (!this.conf.failOpen) {
			return Promise.reject(this.error());
		}

		this.state = 'unavailable';

		return Promise.resolve('');
	};

	Captcha.prototype.error = function () {
		return httpError('captcha_unavailable', i18n.captchaLoad || 'تأیید امنیتی بارگذاری نشد.');
	};

	/**
	 * Score based challenges mint a fresh token per request — they are single
	 * use and expire in about two minutes, so caching one is a bug.
	 */
	Captcha.prototype.executeScore = function () {
		var self = this;
		var lib = this.library();

		if (!lib || !lib.execute) {
			return this.missing();
		}

		var once = function () {
			return new Promise(function (resolve) {
				var call = function () {
					var outcome;

					try {
						outcome = lib.execute(self.siteKey(), { action: self.config.action || 'tisa_otp_send' });
					} catch (error) {
						resolve('');
						return;
					}

					if (outcome && 'function' === typeof outcome.then) {
						outcome.then(function (token) {
							resolve(token || '');
						}).catch(function () {
							resolve('');
						});

						return;
					}

					resolve(typeof outcome === 'string' ? outcome : '');
				};

				if ('function' === typeof lib.ready) {
					lib.ready(call);
					return;
				}

				call();
			});
		};

		return once().then(function (token) {
			if (token) {
				return token;
			}

			/*
			 * v3 mints a token per request over the network, and that request can
			 * simply time out once — a cold connection, a filtered host, a phone
			 * waking up. One retry after a short pause turns almost all of those
			 * into a token; without it the form was posted with an empty token and
			 * the server recorded a rejection that looked like a bot.
			 */
			return self.pause(600).then(once).then(function (again) {
				return again ? again : self.missing();
			});
		});
	};

	/**
	 * A pause, so a retry is not a hammer.
	 */
	Captcha.prototype.pause = function (ms) {
		return new Promise(function (resolve) {
			setTimeout(resolve, ms);
		});
	};

	/**
	 * Checkbox challenges answer through a getter, and the token is consumed by
	 * the verification, so it is reset afterwards.
	 */
	Captcha.prototype.widgetToken = function () {
		var value = '';

		try {
			if ('hcaptcha' === this.conf.provider && window.hcaptcha && null !== this.widgetId) {
				value = window.hcaptcha.getResponse(this.widgetId) || '';
			} else if ('arcaptcha' === this.conf.provider && window.arcaptcha) {
				value = (window.arcaptcha.getArcToken && null !== this.widgetId)
					? window.arcaptcha.getArcToken(this.widgetId) || ''
					: (this.token || '');
			} else if (window.grecaptcha && null !== this.widgetId) {
				value = window.grecaptcha.getResponse(this.widgetId) || '';
			}
		} catch (error) {
			value = this.token || '';
		}

		if (!value && !this.token) {
			this.solving = true;
		}

		this.token = value || this.token;

		return Promise.resolve(this.token);
	};

	/**
	 * Forget the solved state so the next attempt asks again. Call it right after
	 * a request that carried a token.
	 */
	Captcha.prototype.consume = function () {
		var widget = this.widgetId;

		this.token = '';
		this.solving = false;

		if (null === widget) {
			return;
		}

		try {
			if ('hcaptcha' === this.conf.provider && window.hcaptcha && window.hcaptcha.reset) {
				window.hcaptcha.reset(widget);
			} else if ('arcaptcha' === this.conf.provider && window.arcaptcha && window.arcaptcha.reset) {
				window.arcaptcha.reset(widget);
			} else if (window.grecaptcha && window.grecaptcha.reset && !this.isScore()) {
				window.grecaptcha.reset(widget);
			}
		} catch (error) {
			this.widgetId = null;
		}
	};

	/**
	 * Is a challenge on screen that still needs an answer?
	 */
	Captcha.prototype.waiting = function () {
		return this.enabled() && !this.isScore() && '' === this.token;
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
		// Signed timestamp token; refreshed from /form-config, never cached.
		this.formToken = attr(root, 'form-token') || cfg.formToken || '';
		this.timeoutMs = parseInt(cfg.timeoutMs || 15000, 10);
		this.autoVerify = flag(cfg.autoVerify, true);
		this.webOtp = flag(cfg.webOtp, false);
		this.rescueAfter = parseInt(cfg.rescueAfter || 30, 10);

		this.nonceRequest = null;
		this.otpAbort = null;
		this.autoVerifyTimer = null;
		this.rescueTimer = null;
		this.lastAction = '';

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
		this.pasteBtn = $(root, '[data-tisa-paste]');
		this.phoneChip = $(root, '[data-tisa-phone-chip]');
		this.phoneChipValue = $(root, '[data-tisa-phone-chip-value]');
		this.statusTitle = $(root, '[data-tisa-status-title]');
		this.honeypot = $(root, '.tisa-otp__honeypot');
		this.timestamp = $(root, '.tisa-otp__rendered');

		this.statusText = $(root, '[data-tisa-status-text]');
		this.statusActions = $(root, '[data-tisa-status-actions]');
		this.stepMarkers = $$(root, '[data-tisa-step-marker]');
		this.stepsText = $(root, '[data-tisa-steps-text]');
		this.boxWrap = $(root, '[data-tisa-boxes]');
		this.rescue = $(root, '[data-tisa-rescue]');
		this.attempts = $(root, '[data-tisa-attempts]');
		this.cooldownBar = $(root, '[data-tisa-cooldown]');

		if (this.bulk) {
			this.bulk.maxLength = this.codeLength;
			this.bulk.placeholder = text(i18n.codePlaceholder || '', {});
		}

		this.bind();
		this.lockButtonWidths();
		this.show(this.stepPhone);

		// A cached page carries a stale nonce, so fetch a fresh one up front.
		if ('auto' === this.cacheMode) {
			this.refreshNonce();
		}

		if ('always' === (cfg.captcha || {}).trigger) {
			this.captcha.prepare();
		}
	}

	Form.prototype.bind = function () {
		var self = this;

		this.bindPaste();

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

		this.bindBoxes();

		$$(this.root, '[data-tisa-input]').forEach(function (input) {
			input.addEventListener('input', function () {
				self.setValid(input);
			});
		});

		/*
		 * The skip link points at the phone field, but on later steps that field
		 * is display:none and unfocusable — so send focus to whatever the current
		 * step actually shows.
		 */
		var skip = $(this.root, '[data-tisa-skip]');

		if (skip) {
			skip.addEventListener('click', function (event) {
				var target = $(self.root, '.tisa-step.is-current input, .tisa-step.is-current select');

				if (target) {
					event.preventDefault();
					target.focus();
				}
			});
		}

		var form = $(this.root, 'form');

		if (form) {
			form.addEventListener('submit', function (event) {
				event.preventDefault();
			});
		}
	};

	/**
	 * Wire one digit box each. Split out of `bind()` so `renderBoxes()` can call
	 * it again after replacing the inputs.
	 */
	Form.prototype.bindBoxes = function () {
		var self = this;

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
	};

	Form.prototype.act = function (action) {
		if (this.busy) {
			return;
		}

		// Remembered so the "try again" button in an error can repeat it.
		if ('retry' !== action) {
			this.lastAction = action;
		}

		switch (action) {
			case 'retry':
				this.act(this.lastAction || 'start');
				break;
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

	/**
	 * "Paste the code" — the poor cousin of WebOTP, and the only help available
	 * when the message arrives in another app on iOS or in a desktop browser.
	 */
	Form.prototype.bindPaste = function () {
		var self = this;

		if (!this.pasteBtn) {
			return;
		}

		/*
		 * Reading the clipboard needs a secure context (https or localhost) and
		 * a browser that implements readText(). Without both, the button cannot
		 * do anything — so it is not shown, and the user pastes into the first
		 * box as they would anywhere else. An honest missing button beats a
		 * button that answers "no" every time.
		 */
		if (!supportsClipboardRead()) {
			this.pasteBtn.hidden = true;

			return;
		}

		this.pasteBtn.addEventListener('click', function () {
			self.pasteCode();
		});
	};

	Form.prototype.pasteCode = function () {
		var self = this;

		if (!supportsClipboardRead()) {
			this.say(i18n.pasteManual || '', 'info');
			this.focusCode();

			return;
		}

		navigator.clipboard.readText().then(function (value) {
			var digits = digitsOnly(value).slice(0, self.codeLength);

			if (digits.length < self.codeLength) {
				self.say(i18n.pasteEmpty || '', 'info');
				self.focusCode();

				return;
			}

			self.fillCode(digits);
			self.say(i18n.pasteDone || '', 'success');
			self.maybeAutoVerify();
		}).catch(function () {
			// Refused, not broken: the first box is focused so Ctrl+V works.
			self.say(i18n.pasteDenied || i18n.pasteManual || '', 'info');
			self.focusCode();
		});
	};

	Form.prototype.basePayload = function (route) {
		return {
			phone: this.phoneInput ? canonicalPhone(this.phoneInput.value) : this.phone,
			channel: this.channel,
			redirect: this.redirect,
			tisa_hp: this.honeypot ? this.honeypot.value : '',
			tisa_ts: this.timestamp ? this.timestamp.value : String(Math.floor(Date.now() / 1000)),
			tisa_ft: this.formToken || '',
			route: route
		};
	};

	Form.prototype.request = function (route, payload, retried) {
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

			/*
			 * No token, and the browser knows why. Saying it is what lets the
			 * server tell an outage apart from a robot with a script.
			 */
			delete body.captcha_state;

			if (!token && self.captcha.state) {
				body.captcha_state = self.captcha.state;
			}

			return self.send(route, body);
		}).then(function (result) {
			/*
			 * Two things a visitor can hit through no fault of their own:
			 *  - a cached page shipped somebody else's nonce;
			 *  - a cached page (or a slow human) carried a form token the guard
			 *    considered stale, or a captcha the browser could not load.
			 * Both are answered by pulling a fresh configuration and sending once
			 * more. One retry, never a loop.
			 */
			if (self.isStaleNonce(result) || self.isRecoverable(result)) {
				if (retried) {
					return self.unwrap(result);
				}

				return self.refreshConfig().then(function () {
					/*
					 * The payload was assembled before the refresh, so the fields that
					 * came from the cached page are still in it. Swap them for the
					 * fresh ones, otherwise the retry repeats the same rejection.
					 */
					body.tisa_ft = self.formToken || '';

					if (self.timestamp) {
						body.tisa_ts = self.timestamp.value;
					}

					// A consumed token is worthless — let the captcha mint a new one.
					delete body.captcha_token;
					delete body.captcha_state;

					return self.request(route, payload, true);
				});
			}

			self.captcha.consume();

			return self.unwrap(result);
		});
	};

	/**
	 * Server said "your form token is stale" or "I cannot see a captcha token".
	 */
	Form.prototype.isRecoverable = function (result) {
		var json = result ? result.json : null;

		if (!json || json.success !== false || !json.data) {
			return false;
		}

		if (json.data.recoverable) {
			return true;
		}

		return 'captcha_missing' === json.code && this.captcha.unavailable;
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
		return this.refreshConfig();
	};

	Form.prototype.refreshConfig = function () {
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

			if (data && data.formToken) {
				self.formToken = data.formToken;
			}

			if (data && data.renderedAt && self.timestamp) {
				self.timestamp.value = String(data.renderedAt);
			}

			if (data && data.captcha) {
				self.captcha.absorb(data.captcha);
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

		this.phone = canonicalPhone(value);
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
			this.focusFirstError();
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
			this.focusCode();
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

		if (data.masked && this.phoneChip) {
			if (this.phoneChipValue) {
				this.phoneChipValue.textContent = data.masked;
			}

			this.phoneChip.hidden = false;
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
				this.root.classList.remove('is-expired');

				// Length first: the boxes have to exist before anything focuses one.
				if (data.code_length) {
					this.codeLength = parseInt(data.code_length, 10);
					this.renderBoxes(this.codeLength);
				}

				this.startCooldown(data.cooldown || this.cooldown);
				this.startExpiry(data.expires_in || cfg.ttl || 120);
				this.showAttempts(data.attempts_left);
				this.focusCode();
				this.startWebOtp();
				this.startRescue();
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
		var code = error ? error.code : '';
		var message = (error && error.message) || i18n.network || 'Error';

		// The server wanted a challenge the browser could never display: say that,
		// instead of asking the visitor to solve something that is not on screen.
		if ('captcha_missing' === code && this.captcha.unavailable) {
			message = i18n.captchaBlocked || message;
		}

		this.say(message, 'error', this.actionsFor(code, payload));
		this.emit('error', { code: code, data: payload });

		if (payload.captcha_required) {
			var self = this;

			if (payload.captcha_reset) {
				this.captcha.consume();
			}

			/*
			 * Finish what the visitor started as soon as the challenge is answered.
			 * Pressing "send" twice because a checkbox appeared is the sort of
			 * detail that decides whether a login form feels broken.
			 */
			this.captcha.onSolved = function () {
				if (!self.busy) {
					self.act(self.lastAction || 'start');
				}
			};

			this.captcha.prepare();
		}

		if (payload.retry_after) {
			this.startCooldown(parseInt(payload.retry_after, 10));
		}

		this.showAttempts(payload.attempts_left);

		if (payload.errors && 'object' === typeof payload.errors) {
			this.markServerErrors(payload.errors);
		}

		this.clearCode();

		// Put the caret back where the fix has to happen. `clearCode` resets the
		// invalid flag, so the error styling has to be re-applied after it.
		if (this.isCurrent(this.stepCode)) {
			this.setCodeInvalid(true);
			this.focusCode();
		} else if (payload.errors && 'object' === typeof payload.errors) {
			this.focusFirstError();
		}
	};

	/**
	 * Show how many tries are left, when the server bothers to say.
	 */
	Form.prototype.showAttempts = function (left) {
		var count = parseInt(left, 10);

		if (undefined === left || null === left || isNaN(count)) {
			return;
		}

		this.root.setAttribute('data-attempts-left', String(count));

		if (!this.attempts) {
			return;
		}

		if (count <= 0) {
			this.attempts.hidden = true;
			this.attempts.textContent = '';
			return;
		}

		this.attempts.hidden = false;
		this.attempts.textContent = text(i18n.attemptsLeft || '', { n: faDigits(count) });
	};

	Form.prototype.isCurrent = function (step) {
		return !!step && step.classList.contains('is-current');
	};

	/**
	 * Move focus to the first field the server or the validator rejected.
	 */
	Form.prototype.focusFirstError = function () {
		var invalid = $(this.root, '.tisa-step.is-current [aria-invalid="true"]');

		if (invalid && 'function' === typeof invalid.focus) {
			invalid.focus();

			if ('function' === typeof invalid.select) {
				try {
					invalid.select();
				} catch (error) {
					// Selects and checkboxes cannot be selected; focus is enough.
				}
			}
		}
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
			this.stopRescue();
		}

		[this.stepPhone, this.stepFields, this.stepCode].forEach(function (el) {
			if (el) {
				el.classList.toggle('is-current', el === step);
			}
		});

		var name = step === this.stepCode ? 'code' : (step === this.stepFields ? 'fields' : 'phone');

		this.root.setAttribute('data-step', name);
		this.markSteps(name);
		this.clearStatus();
		this.emit('step', { step: step ? attr(step, 'tisa-step') : '' });
	};

	/**
	 * Sync the step bar and the sentence a screen reader hears.
	 *
	 * The circles are `aria-hidden`; only the sentence is announced, so nobody
	 * has to sit through "list, 3 items, 1 of 3" on every transition.
	 */
	Form.prototype.markSteps = function (current) {
		var total = this.stepMarkers.length;
		var index = -1;

		if (!total) {
			return;
		}

		this.stepMarkers.forEach(function (marker, position) {
			if (marker.getAttribute('data-tisa-step-marker') === current) {
				index = position;
			}
		});

		if (index < 0) {
			return;
		}

		this.stepMarkers.forEach(function (marker, position) {
			marker.classList.toggle('is-done', position < index);
			marker.classList.toggle('is-current', position === index);
		});

		if (this.stepsText) {
			this.stepsText.textContent = text(i18n.stepOf || '', {
				n: faDigits(index + 1),
				total: faDigits(total),
				name: this.stepMarkers[index].textContent || ''
			});
		}
	};

	Form.prototype.resetToPhone = function () {
		this.draftToken = '';
		this.verifiedToken = '';
		this.clearCode();
		window.clearInterval(this.countdown);
		window.clearInterval(this.expiry);
		this.paintCooldown(0);
		this.root.classList.remove('is-expired');
		this.root.removeAttribute('data-attempts-left');

		if (this.attempts) {
			this.attempts.hidden = true;
			this.attempts.textContent = '';
		}

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

				if ('function' === typeof target.select) {
					try {
						target.select();
					} catch (error) {
						// Not selectable; focus alone is fine.
					}
				}
			}, 60);
		}
	};

	/**
	 * Rebuild the digit boxes when the server reports a different code length.
	 *
	 * A site can change `code_length` while a page sits in a CDN cache, so the
	 * markup and the real length disagree until this runs.
	 */
	Form.prototype.renderBoxes = function (length) {
		var count = parseInt(length, 10);

		if (!this.boxWrap || isNaN(count) || count < 1 || count === this.boxes.length) {
			return;
		}

		var template = this.boxes[0];

		this.boxWrap.textContent = '';

		for (var index = 0; index < count; index++) {
			var box = template ? template.cloneNode(false) : document.createElement('input');

			box.className = 'tisa-code__box';
			box.type = 'text';
			box.value = '';
			box.setAttribute('inputmode', 'numeric');
			box.setAttribute('maxlength', '1');
			box.setAttribute('aria-invalid', 'false');
			box.setAttribute('data-tisa-box', String(index));
			// Only the first box advertises autofill, same as the PHP template.
			box.setAttribute('autocomplete', 0 === index ? 'one-time-code' : 'off');
			box.setAttribute('aria-label', text(i18n.digitLabel || '', { n: faDigits(index + 1) }) || String(index + 1));

			this.boxWrap.appendChild(box);
		}

		this.boxes = $$(this.root, '[data-tisa-box]');

		if (this.bulk) {
			this.bulk.maxLength = count;
		}

		this.bindBoxes();
		this.emit('code-length', { length: count });
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
			this.paintCooldown(0);

			if (this.resendLabel) {
				this.resendLabel.textContent = (cfg.labels || {}).resend || 'Resend';
			}

			this.announceResend(i18n.resendReady || '');

			return;
		}

		this.resendBtn.disabled = true;
		this.paintCooldown(left);

		var tick = function () {
			// Persian digits in the sentence; the value itself stays a number.
			var label = text(i18n.resendIn || '{s}s', { s: faDigits(left) });

			if (self.resendLabel) {
				self.resendLabel.textContent = label;
			}

			// The visible label ticks every second; the live region only speaks at
			// quarter-minute marks so screen readers are not talked over.
			if (left > 0 && 0 === left % 15) {
				self.announceResend(label);
			}

			left -= 1;

			if (left < 0) {
				window.clearInterval(self.countdown);
				self.resendBtn.disabled = false;
				self.paintCooldown(0);

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
	 * Drive the thin cooldown bar with one CSS animation.
	 *
	 * Restarting it needs a reflow, otherwise the browser reuses the running
	 * animation and the bar never jumps back to full.
	 */
	Form.prototype.paintCooldown = function (seconds) {
		var fill = this.cooldownBar ? this.cooldownBar.firstElementChild : null;

		if (!this.cooldownBar || !fill) {
			return;
		}

		if (!seconds || seconds <= 0) {
			this.cooldownBar.hidden = true;
			fill.style.animation = 'none';
			return;
		}

		this.cooldownBar.hidden = false;
		this.cooldownBar.style.setProperty('--tisa-cooldown', seconds + 's');
		fill.style.animation = 'none';
		// Reading offsetWidth forces the restart.
		void fill.offsetWidth;
		fill.style.animation = '';
	};

	/**
	 * Offer a way out once waiting for the SMS stops being reasonable.
	 */
	Form.prototype.startRescue = function () {
		var self = this;

		this.stopRescue();

		if (!this.rescue || this.rescueAfter <= 0) {
			return;
		}

		this.rescueTimer = window.setTimeout(function () {
			if (self.isCurrent(self.stepCode)) {
				self.rescue.hidden = false;
			}
		}, this.rescueAfter * 1000);
	};

	Form.prototype.stopRescue = function () {
		window.clearTimeout(this.rescueTimer);
		this.rescueTimer = null;

		if (this.rescue) {
			this.rescue.hidden = true;
		}
	};

	/**
	 * Freeze each button label at its resting width.
	 *
	 * Without this, swapping "Sign in" for "Checking the code..." resizes the
	 * button mid-click and the pointer lands somewhere else.
	 */
	Form.prototype.lockButtonWidths = function () {
		$$(this.root, '.tisa-btn__label').forEach(function (label) {
			var width = label.offsetWidth;

			if (width > 0) {
				label.style.setProperty('--tisa-label-width', width + 'px');
			}
		});
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

				// Say so, and offer the one thing that helps.
				if (self.isCurrent(self.stepCode)) {
					self.say(i18n.expired || '', 'error', self.actionsFor('expired_code', {}));
				}
			}
		}, 1000);
	};

	Form.prototype.say = function (message, type, actions) {
		if (!this.statusBox || !message) {
			return;
		}

		var isError = 'error' === type;
		var titles = {
			error: i18n.problemTitle || '',
			success: i18n.doneTitle || '',
			info: i18n.noteTitle || ''
		};

		this.statusBox.hidden = false;
		this.statusBox.className = 'tisa-otp__status is-' + (type || 'info');

		if (this.statusTitle) {
			this.statusTitle.textContent = titles[type] || '';
			this.statusTitle.hidden = !titles[type];
		}

		// Older markup (and themes overriding the template) has no inner nodes.
		if (this.statusText) {
			this.statusText.textContent = message;
		} else {
			this.statusBox.textContent = message;
		}

		this.renderStatusActions(actions);

		// Errors interrupt; everything else waits for a pause in speech.
		this.statusBox.setAttribute('role', isError ? 'alert' : 'status');
		this.statusBox.setAttribute('aria-live', isError ? 'assertive' : 'polite');
	};

	/**
	 * Render the buttons offered inside a status message.
	 *
	 * `actions` is a list of `{ action, label, disabled }`; the button simply
	 * routes back into `act()`, so no new API surface is needed.
	 */
	Form.prototype.renderStatusActions = function (actions) {
		var self = this;

		if (!this.statusActions) {
			return;
		}

		this.statusActions.textContent = '';

		(actions || []).forEach(function (item) {
			if (!item || !item.label) {
				return;
			}

			var button = document.createElement('button');

			button.type = 'button';
			button.className = 'tisa-otp__status-action';
			button.textContent = item.label;
			button.disabled = !!item.disabled;

			button.addEventListener('click', function () {
				self.act(item.action);
			});

			self.statusActions.appendChild(button);
		});
	};

	/**
	 * Map a server error code to the one or two things worth offering next.
	 *
	 * Codes come from the REST layer as they are; nothing here needs the API to
	 * change (UI plan 4.7).
	 */
	Form.prototype.actionsFor = function (code, payload) {
		var labels = cfg.actions || {};
		var retry = { action: 'retry', label: labels.retry || '' };
		var newCode = { action: 'resend', label: labels.newCode || '', disabled: !!(payload && payload.retry_after) };

		/*
		 * No "edit phone" button here on purpose. The number is edited on the
		 * spot — the field itself in step 1, the chip above the boxes in step 3
		 * — so a third control that only goes back to a screen the user can
		 * already see is noise, not a next step.
		 */
		switch (code) {
			case 'network_error':
			case 'request_timeout':
			case 'server_error':
				return [retry];

			case 'invalid_code':
				return [newCode];

			case 'expired_code':
			case 'no_pending_code':
				return [newCode];

			case 'cooldown':
			case 'throttled':
			case 'blocked':
			case 'invalid_phone':
			case 'unknown_phone':
				return [];

			default:
				return (payload && payload.captcha_required) ? [] : [retry];
		}
	};

	Form.prototype.clearStatus = function () {
		if (this.statusBox) {
			this.statusBox.hidden = true;

			if (this.statusTitle) {
				this.statusTitle.textContent = '';
				this.statusTitle.hidden = true;
			}

			if (this.statusText) {
				this.statusText.textContent = '';
			} else {
				this.statusBox.textContent = '';
			}
		}

		this.renderStatusActions([]);
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
			/*
			 * `composed` so the event still reaches the page when the form lives
			 * inside a shadow root: a bubbling event stops at the shadow boundary
			 * unless it is composed, and a site listening for `tisa:sent` on
			 * `document` would silently stop hearing it.
			 */
			this.root.dispatchEvent(new window.CustomEvent('tisa:' + name, {
				bubbles: true,
				composed: true,
				detail: detail || {}
			}));
		} catch (error) {
			// Older browsers: the event is only a convenience for custom scripts.
		}
	};

	/* ------------------------------------------------------- Style isolation */

	/*
	 * The form renders inside a shadow root, with the plugin's own stylesheet
	 * injected into it. That is the difference between "the form looks like
	 * the demo" and "the form looks like whatever the theme does to buttons".
	 *
	 * Two things decide a form's look on a real site, and neither of them is
	 * WordPress rewriting anything:
	 *
	 *   1. cascade   — the plugin names one class per rule; a theme writing
	 *                  `.entry-content input` or `button { ... }` outranks it,
	 *                  so the theme wins the button, the placeholder and the
	 *                  link colours;
	 *   2. inheritance — a theme that sets a font, a letter-spacing or a bold
	 *                  weight on its content column hands those down to the
	 *                  form, which then looks like the theme's prose.
	 *
	 * A shadow root ends both: no outer selector matches inside it, and the
	 * values it inherits are declared by the form itself. The stylesheet is
	 * the same file the light DOM uses — one design, two delivery paths.
	 *
	 * It is progressive: the markup is in the page already (so a visitor with
	 * JavaScript off sees a styled form), the swap happens once the stylesheet
	 * text is in hand, and if anything goes wrong the form falls back to the
	 * light DOM rather than disappearing.
	 */

	var SHADOW = { text: null, failed: false, loading: null };

	/**
	 * Can this form be isolated, and does the site want it?
	 */
	function isolatable(host) {
		if (false === cfg.isolate || 'off' === cfg.isolate) {
			return false;
		}

		if (!window.Element || !window.Element.prototype.attachShadow) {
			return false;
		}

		if (!window.Promise || !window.fetch) {
			return false;
		}

		/*
		 * Without a stylesheet URL there is nothing to inject, and an empty
		 * `fetch('')` would fetch *this page* and inject the HTML as CSS.
		 */
		if ('' === String(cfg.css || '')) {
			return false;
		}

		// Somebody else's shadow root, or this form opted out.
		return !host.shadowRoot && '1' !== host.getAttribute('data-tisa-no-shadow');
	}

	/**
	 * Absolute URLs, because a `<style>` element resolves `url()` against the
	 * page, not against the file the text was read from: `../fonts/x.woff2`
	 * inside `assets/css/` would be requested from the site root.
	 */
	function absolutise(css) {
		var base = String(cfg.assets || '');

		return '' === base ? css : css.replace(/url\(\s*\.\.\//g, 'url(' + base);
	}

	/**
	 * The stylesheet text, fetched once per page. The URL is the same one the
	 * `<link>` already loaded, so this is a cache hit — not a second download.
	 */
	function stylesheet() {
		if (null !== SHADOW.text || SHADOW.failed) {
			return window.Promise.resolve(SHADOW.text || '');
		}

		if (SHADOW.loading) {
			return SHADOW.loading;
		}

		SHADOW.loading = window.fetch(String(cfg.css || ''), { credentials: 'same-origin' })
			.then(function (response) {
				return response && response.ok ? response.text() : '';
			})
			.then(function (text) {
				SHADOW.text = text ? absolutise(text) : '';
				return SHADOW.text;
			})
			.catch(function () {
				// Blocked, offline, a security plugin: the form simply stays in
				// the light DOM, exactly as it shipped before.
				SHADOW.failed = true;

				return '';
			});

		return SHADOW.loading;
	}

	/**
	 * Everything the form reads from its element travels with it: the classes
	 * (skins, code mode), the inline custom properties PHP printed, the
	 * `data-*` settings, and the direction.
	 */
	function carry(inner, host) {
		inner.className = host.className;

		if (host.getAttribute('dir')) {
			inner.setAttribute('dir', host.getAttribute('dir'));
		}

		if (host.getAttribute('style')) {
			inner.setAttribute('style', host.getAttribute('style'));
		}

		Array.prototype.forEach.call(host.attributes, function (attribute) {
			if (0 === attribute.name.indexOf('data-')) {
				inner.setAttribute(attribute.name, attribute.value);
			}
		});
	}

	/**
	 * The values PHP computed for this request. They are written as inline
	 * custom properties on the form itself, so they outrank anything — a theme
	 * cannot recolour the button by declaring the same variable.
	 */
	function paint(inner) {
		var vars = cfg.vars || {};

		Object.keys(vars).forEach(function (name) {
			if (!vars[name]) {
				return;
			}

			if ('' === inner.style.getPropertyValue('--' + name)) {
				inner.style.setProperty('--' + name, String(vars[name]));
			}
		});
	}

	/**
	 * Keep the inside in step with the outside.
	 *
	 * The host element stays the handle a site holds: a builder or a script
	 * that changes the accent, the skin or the width on it must still see the
	 * form change, even though the form no longer lives in that tree. Only the
	 * plugin's own properties and the class list are mirrored — a theme's
	 * styles still cannot reach in.
	 */
	function mirror(host, inner) {
		if (!window.MutationObserver) {
			return;
		}

		try {
			var observer = new window.MutationObserver(function () {
				if (inner.className !== host.className) {
					inner.className = host.className;
				}

				Array.prototype.forEach.call(host.style, function (name) {
					var value = host.style.getPropertyValue(name);

					if (0 === name.indexOf('--tisa-') && inner.style.getPropertyValue(name) !== value) {
						inner.style.setProperty(name, value);
					}
				});
			});

			observer.observe(host, { attributes: true, attributeFilter: ['class', 'style'] });

			host.tisaShadowObserver = observer;
		} catch (error) {
			// No mirroring: the values copied at build time are what the form
			// renders with, which is exactly what a static page needs.
		}
	}

	/**
	 * Put this form inside its own tree. Resolves with the element the form
	 * should be built on — the inner one, or the host when isolation is not
	 * available.
	 */
	function shell(host) {
		if (!isolatable(host)) {
			return window.Promise.resolve(host);
		}

		return stylesheet().then(function (text) {
			var shadow;

			if ('' === text) {
				return host;
			}

			try {
				shadow = host.attachShadow({ mode: 'open' });
			} catch (error) {
				return host;
			}

			var style = document.createElement('style');
			style.setAttribute('data-tisa-shadow-style', '1');
			style.textContent = text;

			var inner = document.createElement('div');
			carry(inner, host);
			paint(inner);

			shadow.appendChild(style);
			shadow.appendChild(inner);

			// Moving nodes is synchronous: the browser paints once, after the
			// swap, so there is no frame where the form is invisible.
			while (host.firstChild) {
				inner.appendChild(host.firstChild);
			}

			host.setAttribute('data-tisa-isolated', '1');
			mirror(host, inner);

			return inner;
		});
	}

	/**
	 * Undo an isolation that did not work out.
	 *
	 * A shadow root cannot be removed, but a slot makes the light DOM visible
	 * again — and the light children are styled by the very same stylesheet,
	 * so a failed swap degrades into the form as it shipped before.
	 */
	function unresolve(host, inner) {
		try {
			while (inner.firstChild) {
				host.appendChild(inner.firstChild);
			}

			host.removeAttribute('data-tisa-isolated');
			host.setAttribute('data-tisa-isolated', 'failed');

			if (host.shadowRoot) {
				host.shadowRoot.innerHTML = '<slot></slot>';
			}
		} catch (error) {
			// Nothing left to do: the form stays where it is.
		}
	}

	/* ------------------------------------------------------------------ Mount */

	/**
	 * One form, mounted where it can be styled by this plugin alone.
	 */
	function boot(host) {
		/*
		 * Without isolation there is nothing to wait for: the form mounts on
		 * the element it was printed on, synchronously, exactly as before.
		 */
		if (!isolatable(host)) {
			host.tisaForm = new Form(host);

			return;
		}

		shell(host).then(function (root) {
			var form;

			try {
				form = new Form(root);
			} catch (error) {
				if (root === host) {
					throw error;
				}

				// A bug in the controller must not cost the visitor the form.
				unresolve(host, root);
				form = new Form(host);
			}

			host.tisaForm = form;
		}).catch(function (error) {
			if (window.console && window.console.error) {
				window.console.error('[tisa-otp] the form could not be started', error);
			}
		});
	}

	/* ------------------------------------------------------------------ Mount */

	function mount() {
		$$(document, '[data-tisa-form]').forEach(function (host) {
			if (host.dataset.tisaMounted) {
				return;
			}

			// Marked before the swap so a second call cannot mount twice while
			// the stylesheet is still in flight.
			host.dataset.tisaMounted = '1';

			boot(host);
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', mount);
	} else {
		mount();
	}

	/*
	 * Once more when the page has finished loading.
	 *
	 * The WoodMart sign-in panel is printed in the footer, and a site that
	 * defers or injects its footer after `DOMContentLoaded` would otherwise
	 * leave that one form inert. `mount()` skips anything already mounted, so
	 * this costs a `querySelectorAll` and nothing else.
	 */
	window.addEventListener('load', mount);

	window.tisaOtpForms = {
		config: cfg,
		refresh: mount,
		utils: { phone: looksLikePhone, digits: digitsOnly, latin: latinDigits }
	};
})(window, document);
