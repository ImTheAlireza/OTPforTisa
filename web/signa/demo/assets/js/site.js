(function () {
	'use strict';

	function boot() {
		var progress = document.querySelector('[data-sg-progress]');
		var top = document.querySelector('[data-sg-top]');

		if (progress || top) {
			var ticking = false;
			var update = function () {
				ticking = false;
				var doc = document.documentElement;
				var max = Math.max(1, doc.scrollHeight - window.innerHeight);
				var ratio = Math.min(1, Math.max(0, window.scrollY / max));

				if (progress) {
					progress.style.width = (ratio * 100).toFixed(2) + '%';
				}

				if (top) {
					top.classList.toggle('is-scrolled', window.scrollY > 8);
				}
			};

			window.addEventListener('scroll', function () {
				if (!ticking) {
					ticking = true;
					window.requestAnimationFrame(update);
				}
			}, { passive: true });

			update();
		}

		var nav = document.querySelector('[data-sg-nav]');

		if (nav && window.IntersectionObserver) {
			var links = Array.prototype.slice.call(nav.querySelectorAll('a[href^="#"]'));
			var watch = new window.IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					if (!entry.isIntersecting) {
						return;
					}

					links.forEach(function (link) {
						var on = link.getAttribute('href') === '#' + entry.target.id;
						link.classList.toggle('is-here', on);
						if (on) {
							link.setAttribute('aria-current', 'location');
						} else {
							link.removeAttribute('aria-current');
						}
					});
				});
			}, { rootMargin: '-45% 0px -50% 0px' });

			links.forEach(function (link) {
				var target = document.getElementById(link.getAttribute('href').slice(1));
				if (target) {
					watch.observe(target);
				}
			});
		}

		var reveal = document.querySelectorAll('[data-sg-reveal]');

		if (window.IntersectionObserver) {
			var seen = new window.IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					entry.target.classList.toggle('is-live', entry.isIntersecting);
				});
			}, { threshold: 0.15 });

			Array.prototype.forEach.call(reveal, function (node) {
				seen.observe(node);
			});
		} else {
			Array.prototype.forEach.call(reveal, function (node) {
				node.classList.add('is-live');
			});
		}

		document.addEventListener('click', function (event) {
			var button = event.target.closest && event.target.closest('[data-sg-copy]');

			if (!button) {
				return;
			}

			var text = button.getAttribute('data-sg-copy');
			var done = function () {
				button.textContent = 'کپی شد ✓';
				button.classList.add('is-done');
				window.setTimeout(function () {
					button.textContent = 'کپی';
					button.classList.remove('is-done');
				}, 1600);
			};

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done, done);
			} else {
				done();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
