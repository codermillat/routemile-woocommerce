/**
 * RouteMile — dynamic greeting updater.
 *
 * Overrides the server-rendered greeting with the visitor's LOCAL time
 * greeting (morning / afternoon / evening / night) and re-evaluates on
 * tab visibility change (catches the user returning after midnight) and
 * every 15 minutes for long-open sessions. The server's first render
 * stays as the fallback when JS is disabled.
 *
 * The element to update carries data-routew-greeting (added in
 * class-routew-my-account.php).
 */
(function () {
	'use strict';

	function greeting(h) {
		if (h >= 5  && h < 12) return 'Good morning';
		if (h >= 12 && h < 17) return 'Good afternoon';
		if (h >= 17 && h < 22) return 'Good evening';
		return 'Good night';
	}

	function update() {
		var el = document.querySelector('[data-routew-greeting]');
		if (el) {
			el.textContent = greeting(new Date().getHours());
		}
	}

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	ready(update);
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden) update();
	});
	setInterval(update, 15 * 60 * 1000);
})();
