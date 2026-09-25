/**
 * Signa demo: the "form appearance" studio under the live form.
 *
 * Every control does what the matching setting on the plugin's «ظاهر فرم»
 * tab does, the same way the plugin does it: a skin class and a few
 * `--signa-*` custom properties on the form element. front.js mirrors both
 * into the form's shadow root, so nothing here reaches inside it.
 *
 * The choices are kept for the session, so they survive the reload after a
 * sign-in and carry over between the landing page and the demo.
 */
(function () {
	'use strict';

	var KEY = 'signa-demo-look';
	var DEFAULTS = { skin: 'line', accent: '#0f766e', radius: 14, code: 'boxes' };
	var DARK = { slate: true, glass: true };

	var fa = function (text) {
		return String(text).replace(/[0-9]/g, function (d) {
			return '۰۱۲۳۴۵۶۷۸۹'.charAt(Number(d));
		});
	};

	function load() {
		var look = {};

		try {
			look = JSON.parse(window.sessionStorage.getItem(KEY) || '{}') || {};
		} catch (error) {
			look = {};
		}

		return {
			skin: /^(line|card|glass|slate|pill)$/.test(look.skin) ? look.skin : DEFAULTS.skin,
			accent: /^#[0-9a-f]{6}$/i.test(look.accent) ? look.accent.toLowerCase() : DEFAULTS.accent,
			radius: Math.max(0, Math.min(24, parseInt(look.radius, 10) >= 0 ? parseInt(look.radius, 10) : DEFAULTS.radius)),
			code: 'single' === look.code ? 'single' : 'boxes'
		};
	}

	function save(look) {
		try {
			window.sessionStorage.setItem(KEY, JSON.stringify(look));
		} catch (error) {
			// Private mode: the look simply resets on the next page.
		}
	}

	/* Same rules as Assets::mix() in PHP, so the shades match the plugin's. */
	function shade(hex, ratio) {
		var out = '#';

		for (var i = 0; i < 3; i++) {
			var part = Math.round(parseInt(hex.substr(1 + i * 2, 2), 16) * (1 - ratio));
			out += ('0' + part.toString(16)).slice(-2);
		}

		return out;
	}

	function rgba(hex, alpha) {
		return 'rgba(' + parseInt(hex.substr(1, 2), 16) + ', ' + parseInt(hex.substr(3, 2), 16) + ', ' + parseInt(hex.substr(5, 2), 16) + ', ' + alpha + ')';
	}

	function swapClass(node, prefix, value) {
		Array.prototype.slice.call(node.classList).forEach(function (name) {
			if (name.indexOf(prefix) === 0) {
				node.classList.remove(name);
			}
		});
		node.classList.add(prefix + value);
	}

	function pick(container, attribute, value) {
		if (!container) {
			return;
		}

		Array.prototype.forEach.call(container.querySelectorAll('[data-' + attribute + ']'), function (button) {
			var on = button.getAttribute('data-' + attribute) === value;
			button.classList.toggle('is-on', on);
			button.setAttribute('aria-checked', on ? 'true' : 'false');
		});
	}

	function boot() {
		var studio = document.querySelector('[data-sg-studio]');
		var host = document.querySelector('[data-signa-form]');

		if (!studio || !host) {
			return;
		}

		var frame = document.querySelector('[data-sg-frame]');
		var skins = studio.querySelector('[data-sg-skins]');
		var accents = studio.querySelector('[data-sg-accents]');
		var codes = studio.querySelector('[data-sg-codes]');
		var color = studio.querySelector('[data-sg-color]');
		var hex = studio.querySelector('[data-sg-hex]');
		var radius = studio.querySelector('[data-sg-radius]');
		var radiusOut = studio.querySelector('[data-sg-radius-out]');
		var look = load();

		function apply() {
			swapClass(host, 'signa-skin-', look.skin);
			swapClass(host, 'signa-code-', look.code);
			(window.signaOtp || {}).skin = look.skin;
			(window.signaOtp || {}).codeInput = look.code;

			host.style.setProperty('--signa-accent', look.accent);
			host.style.setProperty('--signa-accent-strong', shade(look.accent, 0.22));
			host.style.setProperty('--signa-accent-soft', rgba(look.accent, 0.14));
			host.style.setProperty('--signa-radius', look.radius + 'px');
			host.style.setProperty('--signa-radius-card', Math.round(look.radius * 1.3) + 'px');

			if (frame) {
				frame.classList.toggle('is-dark', !!DARK[look.skin]);
				frame.style.setProperty('--sg-accent', look.accent);
			}

			studio.style.setProperty('--sg-accent', look.accent);
			pick(skins, 'skin', look.skin);
			pick(accents, 'accent', look.accent);
			pick(codes, 'code', look.code);

			var custom = accents.querySelector('.swatch--any');
			var preset = !!accents.querySelector('[data-accent="' + look.accent + '"]');
			custom.classList.toggle('is-on', !preset);
			custom.style.setProperty('--c', preset ? 'transparent' : look.accent);

			color.value = look.accent;
			hex.textContent = look.accent;
			radius.value = look.radius;
			radiusOut.textContent = fa(look.radius);

			save(look);
		}

		function set(name, value) {
			look[name] = value;
			apply();
		}

		skins.addEventListener('click', function (event) {
			var button = event.target.closest('[data-skin]');
			if (button) {
				set('skin', button.getAttribute('data-skin'));
			}
		});

		accents.addEventListener('click', function (event) {
			var button = event.target.closest('[data-accent]');
			if (button) {
				set('accent', button.getAttribute('data-accent'));
			}
		});

		color.addEventListener('input', function () {
			set('accent', color.value.toLowerCase());
		});

		radius.addEventListener('input', function () {
			set('radius', parseInt(radius.value, 10) || 0);
		});

		codes.addEventListener('click', function (event) {
			var button = event.target.closest('[data-code]');
			if (button) {
				set('code', button.getAttribute('data-code'));
			}
		});

		studio.querySelector('[data-sg-reset]').addEventListener('click', function () {
			look = { skin: DEFAULTS.skin, accent: DEFAULTS.accent, radius: DEFAULTS.radius, code: DEFAULTS.code };
			apply();
		});

		// Keys 1–5 switch skins, as on the plugin's preview.
		document.addEventListener('keydown', function (event) {
			var target = event.composedPath ? event.composedPath()[0] : event.target;

			if (event.ctrlKey || event.metaKey || event.altKey || (target && target.matches && target.matches('input, textarea, select, [contenteditable]'))) {
				return;
			}

			var order = ['line', 'card', 'glass', 'slate', 'pill'];
			var index = Number(event.key);

			if (index >= 1 && index <= order.length) {
				set('skin', order[index - 1]);
			}
		});

		/* Scenarios: fill a number in and send, as a visitor would. */
		var scenarios = studio.querySelector('[data-sg-scenarios]');

		scenarios.addEventListener('click', function (event) {
			var button = event.target.closest('[data-phone]');
			var form = host.signaForm;

			if (!button || !form) {
				return;
			}

			if (form.root.classList.contains('is-signed-in')) {
				window.location.reload();
				return;
			}

			var phone = button.getAttribute('data-phone');

			if ('new' === phone) {
				phone = '0935' + String(1000000 + Math.floor(Math.random() * 8999999));
			}

			var run = function () {
				var scope = host.shadowRoot || host;
				var input = scope.querySelector('[data-signa-phone]');

				input.value = phone;
				input.dispatchEvent(new window.Event('input', { bubbles: true }));
				form.act('start');
			};

			if (form.busy) {
				return;
			}

			if (form.stepCode && form.stepCode.classList.contains('is-current')) {
				form.act('edit-phone');
				window.setTimeout(run, 250);
			} else {
				run();
			}

			if (frame && frame.getBoundingClientRect && frame.getBoundingClientRect().top < 0) {
				frame.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		});

		apply();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
