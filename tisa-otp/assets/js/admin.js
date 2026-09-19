/*!
 * Tisa OTP — admin screen behaviour.
 *
 * Handles the small amount of interactivity the settings and tools screens need:
 * toggles, card radios, the colour picker, the media picker, the field repeater,
 * the REST-driven tools (test send, throttle reset, importer) and the browser-side
 * generator for the emergency code.
 */
(function (window, document) {
	'use strict';

	var cfg = window.tisaOtpAdmin || {};
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
		box.className = 'tisa-result is-' + (type || 'info');
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
					throw new Error((body && body.message) || 'Request failed');
				}

				return body.data === undefined ? body : body.data;
			});
		});
	}

	function busy(button, on, label) {
		if (!button) {
			return;
		}

		if (on) {
			button.dataset.tisaLabel = button.textContent;
			button.disabled = true;
			button.textContent = label || i18n.working || '...';
		} else {
			button.disabled = false;
			button.textContent = button.dataset.tisaLabel || button.textContent;
		}
	}

	/* Toggles, cards and colour picker -------------------------------------- */

	function initControls() {
		$$('.tisa-toggle').forEach(function (toggle) {
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

		$$('.tisa-cards').forEach(function (group) {
			var sync = function () {
				$$('.tisa-card', group).forEach(function (card) {
					var input = card.querySelector('input[type="radio"]');
					card.classList.toggle('is-selected', !!(input && input.checked));
				});
			};

			sync();
			group.addEventListener('change', sync);
		});

		if (window.jQuery && window.jQuery.fn.wpColorPicker) {
			window.jQuery('.tisa-color').wpColorPicker();
		}

		var saved = document.querySelector('[data-tisa-saved]');

		if (saved && /[?&]settings-updated=true/.test(window.location.search)) {
			saved.hidden = false;
			window.setTimeout(function () {
				saved.hidden = true;
			}, 2600);
		}

		$$('[data-tisa-confirm]').forEach(function (link) {
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

		$$('[data-tisa-pick-media]').forEach(function (button) {
			button.addEventListener('click', function () {
				var target = document.getElementById(button.getAttribute('data-tisa-pick-media'));

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
		$$('[data-tisa-repeater]').forEach(function (repeater) {
			var rows = repeater.querySelector('[data-tisa-rows]');
			var add = repeater.querySelector('[data-tisa-add-row]');
			var template = repeater.querySelector('[data-tisa-row-template]');

			var reindex = function () {
				$$('.tisa-repeater__row', rows).forEach(function (row, index) {
					$$('input,select,textarea', row).forEach(function (input) {
						input.name = input.name.replace(/\[\d+\]/, '[' + index + ']');
					});
				});
			};

			if (add && template && rows) {
				add.addEventListener('click', function () {
					var html = template.innerHTML.replace(/__i__/g, String($$('.tisa-repeater__row', rows).length));
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
					var trigger = event.target.closest('[data-tisa-remove-row]');

					if (!trigger) {
						return;
					}

					var row = trigger.closest('.tisa-repeater__row');

					if (row) {
						row.remove();
						reindex();
					}
				});
			}
		});
	}

	/* Tools ----------------------------------------------------------------- */

	function initTools() {
		var testButton = document.querySelector('[data-tisa-test-send]');

		if (testButton) {
			testButton.addEventListener('click', function () {
				var phone = document.querySelector('[data-tisa-test-phone]');
				var channel = document.querySelector('[data-tisa-test-channel]');
				var result = document.querySelector('[data-tisa-test-result]');

				busy(testButton, true);

				api('admin/test', {
					phone: phone ? phone.value : '',
					channel: channel ? channel.value : 'sms'
				}).then(function (data) {
					busy(testButton, false);
					say(result, (data.message || i18n.done || 'Done') + (data.via ? ' — ' + data.via : ''), 'success');
				}).catch(function (error) {
					busy(testButton, false);
					say(result, error.message, 'error');
				});
			});
		}

		var resetButton = document.querySelector('[data-tisa-reset-throttle]');

		if (resetButton) {
			resetButton.addEventListener('click', function () {
				var result = document.querySelector('[data-tisa-house-result]');

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

	function initImporter() {
		var box = document.querySelector('[data-tisa-import]');

		if (!box) {
			return;
		}

		var startButton = box.querySelector('[data-tisa-import-start]');
		var undoButton = box.querySelector('[data-tisa-import-undo]');
		var progress = box.querySelector('.tisa-progress');
		var bar = box.querySelector('[data-tisa-progress-bar]');
		var result = box.querySelector('[data-tisa-import-result]');
		var report = box.querySelector('[data-tisa-import-report]');
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
				var source = box.querySelector('[data-tisa-import-source]');
				var custom = box.querySelector('[data-tisa-import-custom]');
				var conflict = box.querySelector('[data-tisa-import-conflict]');
				var dry = box.querySelector('[data-tisa-import-dry]');

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
		var button = document.querySelector('[data-tisa-emergency-generate]');

		if (!button) {
			return;
		}

		button.addEventListener('click', function () {
			var field = document.querySelector('[data-tisa-emergency-code]');

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

	ready(function () {
		initControls();
		initMedia();
		initRepeater();
		initTools();
		initEmergency();
	});
})(window, document);
