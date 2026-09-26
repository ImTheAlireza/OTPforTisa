(function (window, document) {
	'use strict';

	var cfg = window.signaOtp || {};
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

	function faDigits(value) {
		var fa = '۰۱۲۳۴۵۶۷۸۹';

		return String(value === null || value === undefined ? '' : value).replace(/[0-9]/g, function (char) {
			return fa.charAt(Number(char));
		});
	}

	function supportsClipboardRead() {
		return !!(window.navigator && navigator.clipboard && navigator.clipboard.readText);
	}

	function canonicalPhone(value) {
		var digits = digitsOnly(value);

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

	var CAPTCHA_GLOBALS = {
		recaptcha_v3: 'grecaptcha',
		hcaptcha: 'hcaptcha',
		arcaptcha: 'arcaptcha'
	};

	function unwrapToken(value) {
		if (!value) {
			return '';
		}

		if ('string' === typeof value) {
			return value;
		}

		if ('object' === typeof value) {
			if ('string' === typeof value.arcaptcha_token) {
				return value.arcaptcha_token;
			}

			if ('string' === typeof value.token) {
				return value.token;
			}
		}

		return '';
	}

	function mirrorVendorStyles(container) {
		if (!container) {
			return null;
		}

		if (container.signaVendorStyles) {
			return container.signaVendorStyles;
		}

		var root = container.getRootNode ? container.getRootNode() : null;

		if (!root || root === document || !root.host) {
			container.signaVendorStyles = { shadow: false };

			return container.signaVendorStyles;
		}

		var state = { shadow: true, seen: {}, sheets: 0, observer: null };

		container.signaVendorStyles = state;

		var HINT = /arcaptcha|spinner-logo|spinner-loader|bg-purple-s5/i;

		var vendorish = function (node) {
			var href = String((node.getAttribute && node.getAttribute('href')) || '');
			var text = 'LINK' === node.tagName ? '' : String(node.textContent || '');

			return HINT.test(href + ' ' + text);
		};

		var fingerprint = function (node) {
			if ('LINK' === node.tagName) {
				return 'href:' + String(node.getAttribute('href') || '');
			}

			var text = String(node.textContent || '');

			return 'text:' + text.length + ':' + text.slice(0, 96);
		};

		var place = function (copy, own) {
			if (own && own.parentNode === root) {
				root.insertBefore(copy, own);
			} else {
				root.insertBefore(copy, root.firstChild);
			}
		};

		var spread = function (node) {
			var tag = node && node.tagName ? String(node.tagName).toUpperCase() : '';

			if ('LINK' !== tag && 'STYLE' !== tag) {
				return;
			}

			if ('LINK' === tag && -1 === String(node.getAttribute('rel') || '').toLowerCase().indexOf('stylesheet')) {
				return;
			}

			var id = fingerprint(node);

			if (!id || state.seen[id]) {
				return;
			}

			state.seen[id] = true;

			try {
				var copy = 'LINK' === tag ? node.cloneNode(false) : document.createElement('style');
				var own = root.querySelector('style[data-signa-shadow-style]');

				if ('STYLE' === tag) {
					copy.textContent = node.textContent;
				}

				if (vendorish(node)) {
					copy.setAttribute('data-signa-vendor-style', '1');
					root.appendChild(copy);
				} else {
					copy.setAttribute('data-signa-page-style', '1');
					place(copy, own);
				}
			} catch (error) {
			}
		};

		var tree = function (node) {
			spread(node);

			if (node && node.querySelectorAll) {
				Array.prototype.forEach.call(node.querySelectorAll('link[rel~="stylesheet"], style'), spread);
			}
		};

		var adoptSheets = function () {
			var list = document.adoptedStyleSheets;

			if (!list || !('adoptedStyleSheets' in root)) {
				return;
			}

			try {
				for (var i = state.sheets; i < list.length; i++) {
					if (root.adoptedStyleSheets.indexOf(list[i]) < 0) {
						root.adoptedStyleSheets = root.adoptedStyleSheets.concat(list[i]);
					}
				}

				state.sheets = list.length;
			} catch (error) {
			}
		};

		Array.prototype.forEach.call(document.querySelectorAll('head link[rel~="stylesheet"], head style'), function (node) {
			if (vendorish(node)) {
				spread(node);
			}
		});

		try {
			state.observer = new MutationObserver(function (records) {
				records.forEach(function (record) {
					Array.prototype.forEach.call(record.addedNodes, tree);
				});

				adoptSheets();
			});

			state.observer.observe(document.documentElement, { childList: true, subtree: true });
		} catch (error) {
			state.observer = null;
		}

		return state;
	}

	function Captcha(root, conf) {
		this.conf = conf || {};
		this.config = this.conf.config || {};
		this.state = '';

		this.container = $(root, '[data-signa-captcha]');

		mirrorVendorStyles(this.container);

		this.widgetId = null;
		this.token = '';
		this.pending = null;
		this.unavailable = false;
		this.solving = false;
		this.onSolved = null;
	}

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
				script.setAttribute('data-signa-captcha-script', url);

				script.onload = function () {
					if (settled) {
						return;
					}

					settled = true;
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

		if (this.container.querySelector('.signa-captcha__error')) {
			this.container.textContent = '';
		}

		this.container.hidden = false;
	};

	Captcha.prototype.markUnavailable = function () {
		var self = this;

		this.unavailable = true;

		if (!this.container) {
			return;
		}

		this.show();
		this.container.textContent = '';

		var box = document.createElement('div');
		box.className = 'signa-captcha__error';
		box.setAttribute('role', 'alert');

		var message = document.createElement('p');
		message.className = 'signa-captcha__error-text';
		message.textContent = i18n.captchaLoad || 'تأیید امنیتی بارگذاری نشد.';

		var retry = document.createElement('button');
		retry.type = 'button';
		retry.className = 'signa-link';
		retry.setAttribute('data-signa-captcha-retry', '1');
		retry.textContent = i18n.captchaRetry || 'تلاش دوباره';

		retry.addEventListener('click', function () {
			self.retryNow();
		});

		box.appendChild(message);
		box.appendChild(retry);

		if (this.conf.failOpen && this.conf.siteKey) {
			var hint = document.createElement('p');
			hint.className = 'signa-captcha__error-hint';
			hint.textContent = i18n.captchaContinue || 'می‌توانید بدون تأیید امنیتی ادامه دهید.';
			box.appendChild(hint);
		}

		this.container.appendChild(box);
		this.emitRetry();
	};

	Captcha.prototype.emitRetry = function () {
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
						outcome = lib.execute(self.siteKey(), { action: self.config.action || 'signa_send' });
					} catch (error) {
						resolve('');
						return;
					}

					if (outcome && 'function' === typeof outcome.then) {
						outcome.then(function (token) {
							resolve(unwrapToken(token));
						}).catch(function () {
							resolve('');
						});

						return;
					}

					resolve(unwrapToken(outcome));
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

			return self.pause(600).then(once).then(function (again) {
				return again ? again : self.missing();
			});
		});
	};

	Captcha.prototype.pause = function (ms) {
		return new Promise(function (resolve) {
			setTimeout(resolve, ms);
		});
	};

	Captcha.prototype.widgetToken = function () {
		var value = '';

		try {
			if ('hcaptcha' === this.conf.provider && window.hcaptcha && null !== this.widgetId) {
				value = window.hcaptcha.getResponse(this.widgetId) || '';
			} else if ('arcaptcha' === this.conf.provider && window.arcaptcha) {
				value = (window.arcaptcha.getArcToken && null !== this.widgetId)
					? window.arcaptcha.getArcToken(this.widgetId) || ''
					: '';
			} else if (window.grecaptcha && null !== this.widgetId) {
				value = window.grecaptcha.getResponse(this.widgetId) || '';
			}
		} catch (error) {
			value = '';
		}

		value = unwrapToken(value) || this.fieldToken();

		if (!value) {
			value = this.token || '';
		}

		if (!value && !this.token) {
			this.solving = true;
		}

		this.token = value || this.token;

		return Promise.resolve(this.token);
	};

	Captcha.prototype.fieldToken = function () {
		var scope = null;

		if (this.container) {
			scope = this.container.closest ? this.container.closest('form') : null;
		}

		if (!scope) {
			return '';
		}

		var field = scope.querySelector('input[name="arcaptcha-token"], textarea[name="arcaptcha-token"]');

		return field && 'string' === typeof field.value ? field.value : '';
	};

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

	Captcha.prototype.waiting = function () {
		return this.enabled() && !this.isScore() && '' === this.token;
	};

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

		this.stepPhone = $(root, '[data-signa-step="phone"]');
		this.stepFields = $(root, '[data-signa-step="fields"]');
		this.stepCode = $(root, '[data-signa-step="code"]');
		this.statusBox = $(root, '.signa__status');
		this.phoneInput = $(root, '[data-signa-phone]');
		this.bulk = $(root, '[data-signa-code-bulk]');
		this.boxes = $$(root, '[data-signa-box]');
		this.masked = $(root, '[data-signa-masked]');
		this.resendBtn = $(root, '[data-signa-action="resend"]');
		this.resendLabel = $(root, '[data-signa-resend-label]');
		this.resendLive = $(root, '[data-signa-resend-live]');
		this.pasteBtn = $(root, '[data-signa-paste]');
		this.phoneChip = $(root, '[data-signa-phone-chip]');
		this.phoneChipValue = $(root, '[data-signa-phone-chip-value]');
		this.statusTitle = $(root, '[data-signa-status-title]');
		this.honeypot = $(root, '.signa__honeypot');
		this.timestamp = $(root, '.signa__rendered');

		this.statusText = $(root, '[data-signa-status-text]');
		this.statusActions = $(root, '[data-signa-status-actions]');
		this.stepMarkers = $$(root, '[data-signa-step-marker]');
		this.stepsText = $(root, '[data-signa-steps-text]');
		this.boxWrap = $(root, '[data-signa-boxes]');
		this.rescue = $(root, '[data-signa-rescue]');
		this.attempts = $(root, '[data-signa-attempts]');
		this.cooldownBar = $(root, '[data-signa-cooldown]');

		if (this.bulk) {
			this.bulk.maxLength = this.codeLength;
			this.bulk.placeholder = text(i18n.codePlaceholder || '', {});
		}

		this.bind();
		this.lockButtonWidths();
		this.show(this.stepPhone);

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

		$$(this.root, '[data-signa-action]').forEach(function (button) {
			button.addEventListener('click', function (event) {
				event.preventDefault();
				self.act(button.getAttribute('data-signa-action'));
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

		$$(this.root, '[data-signa-input]').forEach(function (input) {
			input.addEventListener('input', function () {
				self.setValid(input);
			});
		});

		var skip = $(this.root, '[data-signa-skip]');

		if (skip) {
			skip.addEventListener('click', function (event) {
				var target = $(self.root, '.signa-step.is-current input, .signa-step.is-current select');

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

	Form.prototype.bindPaste = function () {
		var self = this;

		if (!this.pasteBtn) {
			return;
		}

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
			self.say(i18n.pasteDenied || i18n.pasteManual || '', 'info');
			self.focusCode();
		});
	};

	Form.prototype.basePayload = function (route) {
		return {
			phone: this.phoneInput ? canonicalPhone(this.phoneInput.value) : this.phone,
			channel: this.channel,
			redirect: this.redirect,
			signa_hp: this.honeypot ? this.honeypot.value : '',
			signa_ts: this.timestamp ? this.timestamp.value : String(Math.floor(Date.now() / 1000)),
			signa_ft: this.formToken || '',
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

			delete body.captcha_state;

			if (!token && self.captcha.state) {
				body.captcha_state = self.captcha.state;
			}

			return self.send(route, body);
		}).then(function (result) {
			if (self.isStaleNonce(result) || self.isRecoverable(result)) {
				if (retried) {
					return self.unwrap(result);
				}

				return self.refreshConfig().then(function () {
					body.signa_ft = self.formToken || '';

					if (self.timestamp) {
						body.signa_ts = self.timestamp.value;
					}

					delete body.captcha_token;
					delete body.captcha_state;

					return self.request(route, payload, true);
				});
			}

			self.captcha.consume();

			return self.unwrap(result);
		});
	};

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

	Form.prototype.isStaleNonce = function (result) {
		if (!this.configUrl || !result) {
			return false;
		}

		return 403 === result.status || 'rest_cookie_invalid_nonce' === (result.json && result.json.code);
	};

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
				self.setInvalid($(self.root, '[data-signa-input="' + problem.id + '"]'), problem.message);
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

		if (this.isCurrent(this.stepCode)) {
			this.setCodeInvalid(true);
			this.focusCode();
		} else if (payload.errors && 'object' === typeof payload.errors) {
			this.focusFirstError();
		}
	};

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

	Form.prototype.focusFirstError = function () {
		var invalid = $(this.root, '.signa-step.is-current [aria-invalid="true"]');

		if (invalid && 'function' === typeof invalid.focus) {
			invalid.focus();

			if ('function' === typeof invalid.select) {
				try {
					invalid.select();
				} catch (error) {
				}
			}
		}
	};

	Form.prototype.markServerErrors = function (errors) {
		var self = this;

		Object.keys(errors).forEach(function (id) {
			var input = $(self.root, '[data-signa-input="' + id + '"]');

			if (input) {
				self.setInvalid(input, String(errors[id]));
				return;
			}

			var error = $(self.root, '[data-signa-error="' + id + '"]');

			if (error) {
				error.hidden = false;
				error.textContent = String(errors[id]);
			}
		});
	};

	Form.prototype.collect = function () {
		var values = {};
		var errors = [];

		$$(this.root, '[data-signa-input]').forEach(function (input) {
			var id = input.getAttribute('data-signa-input');
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

		$$(this.root, '[data-signa-error]').forEach(function (el) {
			el.hidden = true;
			el.textContent = '';
		});

		$$(this.root, '[aria-invalid="true"]').forEach(function (el) {
			el.setAttribute('aria-invalid', 'false');
		});
	};

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
		var key = input.getAttribute('data-signa-input') || (input === this.phoneInput ? 'phone' : '');

		return key ? $(this.root, '[data-signa-error="' + key + '"]') : null;
	};

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
		this.emit('step', { step: step ? attr(step, 'signa-step') : '' });
	};

	Form.prototype.markSteps = function (current) {
		var total = this.stepMarkers.length;
		var index = -1;

		if (!total) {
			return;
		}

		this.stepMarkers.forEach(function (marker, position) {
			if (marker.getAttribute('data-signa-step-marker') === current) {
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
			var title = step ? $(step, '.signa-step__title') : null;

			if (title && headings[key]) {
				title.textContent = headings[key];
			}
		});
	};

	Form.prototype.focusFirst = function () {
		var first = $(this.stepFields || document, '[data-signa-input]');

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
					}
				}
			}, 60);
		}
	};

	Form.prototype.renderBoxes = function (length) {
		var count = parseInt(length, 10);

		if (!this.boxWrap || isNaN(count) || count < 1 || count === this.boxes.length) {
			return;
		}

		var template = this.boxes[0];

		this.boxWrap.textContent = '';

		for (var index = 0; index < count; index++) {
			var box = template ? template.cloneNode(false) : document.createElement('input');

			box.className = 'signa-code__box';
			box.type = 'text';
			box.value = '';
			box.setAttribute('inputmode', 'numeric');
			box.setAttribute('maxlength', '1');
			box.setAttribute('aria-invalid', 'false');
			box.setAttribute('data-signa-box', String(index));
			box.setAttribute('autocomplete', 0 === index ? 'one-time-code' : 'off');
			box.setAttribute('aria-label', text(i18n.digitLabel || '', { n: faDigits(index + 1) }) || String(index + 1));

			this.boxWrap.appendChild(box);
		}

		this.boxes = $$(this.root, '[data-signa-box]');

		if (this.bulk) {
			this.bulk.maxLength = count;
		}

		this.bindBoxes();
		this.emit('code-length', { length: count });
	};

	Form.prototype.maybeAutoVerify = function () {
		var self = this;

		if (!this.autoVerify || this.busy || this.codeValue().length < this.codeLength) {
			return;
		}

		window.clearTimeout(this.autoVerifyTimer);

		this.autoVerifyTimer = window.setTimeout(function () {
			if (!self.busy && self.codeValue().length === self.codeLength) {
				self.act('verify');
			}
		}, 180);
	};

	Form.prototype.fillCode = function (value) {
		var digits = digitsOnly(value).substr(0, this.codeLength);

		if (this.bulk) {
			this.bulk.value = digits;
		}

		this.spread(digits);
	};

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
			self.otpAbort = null;
		});
	};

	Form.prototype.stopWebOtp = function () {
		if (this.otpAbort) {
			try {
				this.otpAbort.abort();
			} catch (error) {
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
			var label = text(i18n.resendIn || '{s}s', { s: faDigits(left) });

			if (self.resendLabel) {
				self.resendLabel.textContent = label;
			}

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
		this.cooldownBar.style.setProperty('--signa-cooldown', seconds + 's');
		fill.style.animation = 'none';
		void fill.offsetWidth;
		fill.style.animation = '';
	};

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

	Form.prototype.lockButtonWidths = function () {
		$$(this.root, '.signa-btn__label').forEach(function (label) {
			var width = label.offsetWidth;

			if (width > 0) {
				label.style.setProperty('--signa-label-width', width + 'px');
			}
		});
	};

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
		this.statusBox.className = 'signa__status is-' + (type || 'info');

		if (this.statusTitle) {
			this.statusTitle.textContent = titles[type] || '';
			this.statusTitle.hidden = !titles[type];
		}

		if (this.statusText) {
			this.statusText.textContent = message;
		} else {
			this.statusBox.textContent = message;
		}

		this.renderStatusActions(actions);

		this.statusBox.setAttribute('role', isError ? 'alert' : 'status');
		this.statusBox.setAttribute('aria-live', isError ? 'assertive' : 'polite');
	};

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
			button.className = 'signa__status-action';
			button.textContent = item.label;
			button.disabled = !!item.disabled;

			button.addEventListener('click', function () {
				self.act(item.action);
			});

			self.statusActions.appendChild(button);
		});
	};

	Form.prototype.actionsFor = function (code, payload) {
		var labels = cfg.actions || {};
		var retry = { action: 'retry', label: labels.retry || '' };
		var newCode = { action: 'resend', label: labels.newCode || '', disabled: !!(payload && payload.retry_after) };

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

		$$(this.root, '[data-signa-action]').forEach(function (button) {
			var action = button.getAttribute('data-signa-action');
			var label = $(button, '.signa-btn__label');

			button.disabled = busy;
			button.setAttribute('aria-disabled', busy ? 'true' : 'false');

			if (busy && label && action === name) {
				label.dataset.signaOriginal = label.textContent;
				label.textContent = working || label.textContent;
			} else if (!busy && label && label.dataset.signaOriginal) {
				label.textContent = label.dataset.signaOriginal;
				delete label.dataset.signaOriginal;
			}
		});
	};

	Form.prototype.emit = function (name, detail) {
		try {
			this.root.dispatchEvent(new window.CustomEvent('signa:' + name, {
				bubbles: true,
				composed: true,
				detail: detail || {}
			}));
		} catch (error) {
		}
	};

	var SHADOW = { text: null, failed: false, loading: null };

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

		if ('' === String(cfg.css || '')) {
			return false;
		}

		return !host.shadowRoot && '1' !== host.getAttribute('data-signa-no-shadow');
	}

	function absolutise(css) {
		var base = String(cfg.assets || '');

		return '' === base ? css : css.replace(/url\(\s*\.\.\//g, 'url(' + base);
	}

	function ownStylesheet(text) {
		return 'string' === typeof text &&
			text.length > 4096 &&
			text.indexOf('.signa') > 0 &&
			text.indexOf('--signa-accent') > 0;
	}

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
				SHADOW.text = ownStylesheet(text) ? absolutise(text) : '';
				SHADOW.failed = !SHADOW.text;

				return SHADOW.text;
			})
			.catch(function () {
				SHADOW.failed = true;

				return '';
			});

		return SHADOW.loading;
	}

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

					if (0 === name.indexOf('--signa-') && inner.style.getPropertyValue(name) !== value) {
						inner.style.setProperty(name, value);
					}
				});
			});

			observer.observe(host, { attributes: true, attributeFilter: ['class', 'style'] });

			host.signaShadowObserver = observer;
		} catch (error) {
		}
	}

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
			style.setAttribute('data-signa-shadow-style', '1');
			style.textContent = text;

			var inner = document.createElement('div');
			carry(inner, host);
			paint(inner);

			shadow.appendChild(style);
			shadow.appendChild(inner);

			while (host.firstChild) {
				inner.appendChild(host.firstChild);
			}

			host.setAttribute('data-signa-isolated', '1');
			mirror(host, inner);

			return inner;
		});
	}

	function unresolve(host, inner) {
		try {
			while (inner.firstChild) {
				host.appendChild(inner.firstChild);
			}

			host.removeAttribute('data-signa-isolated');
			host.setAttribute('data-signa-isolated', 'failed');

			if (host.shadowRoot) {
				host.shadowRoot.innerHTML = '<slot></slot>';
			}
		} catch (error) {
		}
	}

	function boot(host) {
		if (!isolatable(host)) {
			host.signaForm = new Form(host);

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

				unresolve(host, root);
				form = new Form(host);
			}

			host.signaForm = form;
		}).catch(function (error) {
			if (window.console && window.console.error) {
				window.console.error('[signa] the form could not be started', error);
			}
		});
	}

	function mount() {
		$$(document, '[data-signa-form]').forEach(function (host) {
			if (host.dataset.signaMounted) {
				return;
			}

			host.dataset.signaMounted = '1';

			boot(host);
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', mount);
	} else {
		mount();
	}

	window.addEventListener('load', mount);

	window.signaOtpForms = {
		config: cfg,
		refresh: mount,
		utils: { phone: looksLikePhone, digits: digitsOnly, latin: latinDigits }
	};
})(window, document);
