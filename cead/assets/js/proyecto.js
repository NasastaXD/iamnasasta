/**
 * Comportamiento de /proyecto: audio por sección y conteo de números.
 *
 * Todo lo que hace este archivo es OPCIONAL. Sin JavaScript la página se lee
 * completa: los números ya están escritos en el HTML y los botones de audio
 * nacen ocultos, así que no queda ningún control muerto en pantalla.
 */
(function () {
	'use strict';

	/* ------------------------------------------------------------------ audio */

	/*
	 * Dos formas de sonar, en este orden:
	 *  1. Un archivo propio, si la sección declara `data-audio-src`.
	 *  2. La voz del navegador leyendo `data-audio-text`.
	 *
	 * El botón se revela solo si alguna de las dos está disponible. Si el
	 * navegador no tiene ninguna, se queda oculto: más vale un control menos
	 * que un botón que promete y no cumple.
	 */
	var botones = Array.prototype.slice.call(document.querySelectorAll('.proyecto-audio'));

	if (botones.length) {
		var puedeHablar = 'speechSynthesis' in window && typeof window.SpeechSynthesisUtterance === 'function';
		var puedeSonar  = typeof window.Audio === 'function';
		var activo      = null; // { boton, parar }

		function detener() {
			if (!activo) { return; }
			try { activo.parar(); } catch (e) {}
			activo.boton.setAttribute('aria-pressed', 'false');
			activo.boton.classList.remove('is-playing');
			activo = null;
		}

		function reproducirArchivo(boton, src) {
			var audio = new Audio(src);
			audio.addEventListener('ended', detener);
			// Si el archivo no carga, no dejamos el botón "sonando" para siempre.
			audio.addEventListener('error', detener);
			var p = audio.play();
			if (p && typeof p.catch === 'function') { p.catch(detener); }
			return function () { audio.pause(); audio.currentTime = 0; };
		}

		function reproducirVoz(boton, texto) {
			// Cancelar lo que hubiera quedado colgado de un intento anterior:
			// Safari a veces deja la cola con contenido y no arranca.
			window.speechSynthesis.cancel();

			var u = new window.SpeechSynthesisUtterance(texto);
			u.lang = document.documentElement.lang || 'es';
			u.rate = 1;
			u.onend = detener;
			u.onerror = detener;
			window.speechSynthesis.speak(u);
			return function () { window.speechSynthesis.cancel(); };
		}

		botones.forEach(function (boton) {
			var src   = boton.getAttribute('data-audio-src');
			var texto = boton.getAttribute('data-audio-text') || '';

			// ¿Este botón puede hacer algo? Si no, se queda oculto.
			if (!(src && puedeSonar) && !(texto && puedeHablar)) { return; }
			boton.hidden = false;

			boton.addEventListener('click', function () {
				var eraEste = activo && activo.boton === boton;
				detener();
				if (eraEste) { return; } // segundo clic = pausa

				var parar = (src && puedeSonar)
					? reproducirArchivo(boton, src)
					: reproducirVoz(boton, texto);

				activo = { boton: boton, parar: parar };
				boton.setAttribute('aria-pressed', 'true');
				boton.classList.add('is-playing');
			});
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' || e.keyCode === 27) { detener(); }
		});

		// Salir de la página con la voz hablando la deja sonando en algunos
		// navegadores, incluso después de cerrar la pestaña.
		window.addEventListener('pagehide', detener);
	}

	/* --------------------------------------------------------------- números */

	/*
	 * Cuenta de 0 hasta el valor que YA está escrito en el HTML. Si el conteo
	 * no corre —sin JS, sin IntersectionObserver, o con movimiento reducido—
	 * el número se ve igual: la animación no es la fuente del dato.
	 */
	var quietos = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var cifras  = Array.prototype.slice.call(document.querySelectorAll('[data-contar]'));

	if (cifras.length && !quietos && 'IntersectionObserver' in window) {
		var contar = function (el) {
			var destino = parseInt(el.getAttribute('data-contar'), 10);
			if (!isFinite(destino)) { return; }

			var inicio = null;
			var dur    = 900;

			var paso = function (t) {
				if (inicio === null) { inicio = t; }
				var avance = Math.min((t - inicio) / dur, 1);
				// easeOutCubic: arranca rápido y frena, que es como se lee mejor.
				var suave = 1 - Math.pow(1 - avance, 3);
				el.textContent = String(Math.round(destino * suave));
				if (avance < 1) { requestAnimationFrame(paso); }
			};
			requestAnimationFrame(paso);
		};

		var obs = new IntersectionObserver(function (entradas) {
			entradas.forEach(function (en) {
				if (!en.isIntersecting) { return; }
				obs.unobserve(en.target);
				contar(en.target);
			});
		}, { rootMargin: '-60px' });

		cifras.forEach(function (c) { obs.observe(c); });
	}
})();
