/*!
 * CEAD Académico — frontend JS
 * Solo wiring liviano por ahora. En fases siguientes se agrega router del panel,
 * fetch wrapper con X-WP-Nonce, y modules específicos.
 */
(function () {
	'use strict';

	// Foco al primer input visible en cualquier form de auth.
	document.addEventListener('DOMContentLoaded', function () {
		var input = document.querySelector('.cead-acad-form input:not([type=hidden])');
		if (input && !input.value) {
			try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); }
		}
	});
})();
