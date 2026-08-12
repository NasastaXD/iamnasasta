/*!
 * CEAD Académico — frontend JS del panel.
 *
 * Lo que hace acá adentro, en orden de importancia:
 *
 *  1. Navegación suave: los enlaces del panel no recargan la página entera.
 *     Se pide el HTML nuevo, se cambia SOLO el contenido y el shell (sidebar,
 *     topbar, scroll del menú) se queda quieto. Con View Transitions donde
 *     existe, el cambio es un fundido en vez de un parpadeo blanco.
 *  2. Formularios GET en el lugar: el selector de "ver otro curso" en Horarios
 *     recargaba y te devolvía al tope de la página, así que había que
 *     scrollear de nuevo hasta donde estabas. Ahora se resuelve sin moverte.
 *  3. Prefetch al pasar el mouse (o al apoyar el dedo): cuando hacés clic, la
 *     respuesta muchas veces ya está.
 *
 * Todo esto es progresivo: sin JavaScript, o si algo falla, los enlaces y los
 * formularios siguen andando como siempre porque nunca se les saca el
 * comportamiento nativo — se los intercepta, y ante cualquier duda se deja
 * pasar la navegación normal.
 */
(function () {
	'use strict';

	var CONTENIDO = '#cead-acad-contenido';
	var enPanel   = document.body && document.body.classList.contains('cead-acad-panel');

	/* ----------------------------------------------------------- utilidades */

	function reduceMotion() {
		return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	/** Enfoca sin arrastrar el scroll (Safari viejo no soporta preventScroll). */
	function focusSinSaltar(el) {
		if (!el) { return; }
		try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
	}

	// Foco al primer input visible en cualquier form de auth (login, registro…).
	document.addEventListener('DOMContentLoaded', function () {
		var input = document.querySelector('.cead-acad-form input:not([type=hidden])');
		if (input && !input.value) { focusSinSaltar(input); }
	});

	if (!enPanel) { return; }

	/* --------------------------------------------------- barra de progreso */

	var barra;
	function progresoIniciar() {
		if (!barra) {
			barra = document.createElement('div');
			barra.className = 'cead-acad-progress';
			document.body.appendChild(barra);
		}
		barra.classList.remove('is-done');
		// Reinicia la animación aunque ya estuviera corriendo.
		void barra.offsetWidth;
		barra.classList.add('is-loading');
	}
	function progresoTerminar() {
		if (!barra) { return; }
		barra.classList.remove('is-loading');
		barra.classList.add('is-done');
	}

	/* ------------------------------------------------------- navegación */

	var soporteVT = typeof document.startViewTransition === 'function';
	var cache     = new Map();   // url -> { t: momento, p: Promise<string> }
	var CACHE_MAX = 24;
	/*
	 * El caché existe para que el clic aproveche lo que ya se pidió al pasar el
	 * mouse por encima. Nada más. Con vencimiento corto: el panel muestra datos
	 * que cambian solos (notificaciones sin leer, tareas por vencer), y volver a
	 * una pantalla visitada hace diez minutos tiene que traer lo de ahora, no
	 * una foto vieja.
	 */
	var CACHE_TTL = 30000;
	var pedidoActual = 0;        // descarta respuestas de clics ya superados

	function mismaBase(url) {
		try {
			var u = new URL(url, location.href);
			return u.origin === location.origin && u.pathname.indexOf('/panel') === 0;
		} catch (e) { return false; }
	}

	function traer(url) {
		var guardado = cache.get(url);
		if (guardado && (Date.now() - guardado.t) < CACHE_TTL) { return guardado.p; }
		var p = fetch(url, {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'fetch' }
		}).then(function (r) {
			// Un redirect a login, un 403 o un 500 se dejan al navegador: que
			// haga la navegación de verdad en vez de que intentemos adivinar.
			if (!r.ok) { throw new Error('HTTP ' + r.status); }
			if (new URL(r.url, location.href).pathname.indexOf('/panel') !== 0) {
				throw new Error('redirect fuera del panel');
			}
			return r.text();
		});
		cache.set(url, { t: Date.now(), p: p });
		if (cache.size > CACHE_MAX) { cache.delete(cache.keys().next().value); }
		// Un fallo no se cachea: el próximo intento tiene que poder reintentar.
		p.catch(function () { cache.delete(url); });
		return p;
	}

	function prefetch(url) {
		if (!mismaBase(url)) { return; }
		var guardado = cache.get(url);
		if (guardado && (Date.now() - guardado.t) < CACHE_TTL) { return; }
		traer(url).catch(function () {});
	}

	/**
	 * Aplica el HTML nuevo: contenido, título, y el estado activo del menú.
	 *
	 * El sidebar y la topbar NO se reemplazan enteros: se les actualiza solo lo
	 * que cambia (qué ítem está activo, el contador de la campana). Reemplazarlos
	 * cortaría el scroll del menú y el foco de quien navega con teclado.
	 */
	function aplicar(html, opciones) {
		var doc    = new DOMParser().parseFromString(html, 'text/html');
		var nuevo  = doc.querySelector(CONTENIDO);
		var actual = document.querySelector(CONTENIDO);
		if (!nuevo || !actual) { return false; }

		actual.innerHTML = nuevo.innerHTML;
		if (doc.title) { document.title = doc.title; }

		// Ítem activo del menú lateral.
		var linksNuevos = doc.querySelectorAll('.cead-acad-panel-sidebar a');
		var linksAhora  = document.querySelectorAll('.cead-acad-panel-sidebar a');
		if (linksNuevos.length === linksAhora.length) {
			for (var i = 0; i < linksAhora.length; i++) {
				linksAhora[i].className = linksNuevos[i].className;
				if (linksNuevos[i].hasAttribute('aria-current')) {
					linksAhora[i].setAttribute('aria-current', linksNuevos[i].getAttribute('aria-current'));
				} else {
					linksAhora[i].removeAttribute('aria-current');
				}
			}
		}

		// Campana de notificaciones (el contador cambia al leer comunicados).
		var notifNuevo = doc.querySelector('.cead-acad-notif');
		var notifAhora = document.querySelector('.cead-acad-notif');
		if (notifNuevo && notifAhora) { notifAhora.innerHTML = notifNuevo.innerHTML; }

		/*
		 * `innerHTML` inserta los <script> pero NO los ejecuta (lo dice la
		 * spec). Alguna pantalla del panel trae uno inline —el botón de
		 * "Instalar app", por ejemplo— y sin esto quedaría dibujado pero
		 * muerto: se vería igual y no haría nada al tocarlo. Se los vuelve a
		 * crear para que corran, igual que en una carga normal.
		 */
		var scripts = actual.querySelectorAll('script');
		for (var s = 0; s < scripts.length; s++) {
			var viejo = scripts[s];
			var nuevoScript = document.createElement('script');
			for (var a = 0; a < viejo.attributes.length; a++) {
				nuevoScript.setAttribute(viejo.attributes[a].name, viejo.attributes[a].value);
			}
			nuevoScript.textContent = viejo.textContent;
			viejo.parentNode.replaceChild(nuevoScript, viejo);
		}

		if (!opciones || !opciones.mantenerScroll) {
			window.scrollTo(0, 0);
			// Que quien navega con teclado o lector de pantalla sepa que cambió
			// la pantalla: sin esto el foco se queda donde estaba.
			focusSinSaltar(actual);
		} else {
			// Cambió el contenido pero NO movemos el foco (estaría sacando a la
			// persona del filtro que acaba de usar): se anuncia y listo.
			anunciar(document.title.split('·')[0].trim());
		}

		document.dispatchEvent(new CustomEvent('cead:contenido', { detail: { url: location.href } }));
		return true;
	}

	/** Región viva para lectores de pantalla: dice qué se actualizó, sin robar foco. */
	var region;
	function anunciar(texto) {
		if (!region) {
			region = document.createElement('div');
			region.className = 'cead-acad-sr-only';
			region.setAttribute('aria-live', 'polite');
			region.setAttribute('role', 'status');
			document.body.appendChild(region);
		}
		region.textContent = texto;
	}

	/**
	 * Va a una URL sin recargar. Si algo sale mal —red, HTML inesperado, una
	 * respuesta que no es del panel— cae a la navegación normal del navegador,
	 * que siempre funciona.
	 */
	function navegar(url, opciones) {
		opciones = opciones || {};
		var miPedido = ++pedidoActual;

		progresoIniciar();

		return traer(url).then(function (html) {
			if (miPedido !== pedidoActual) { return; } // hubo un clic más nuevo

			if (opciones.push !== false) {
				history.pushState({ ceadPanel: true }, '', url);
			}

			var pintar = function () { aplicar(html, opciones); };
			if (soporteVT && !reduceMotion()) {
				document.startViewTransition(pintar);
			} else {
				pintar();
			}
			progresoTerminar();
		}).catch(function () {
			if (miPedido !== pedidoActual) { return; }
			progresoTerminar();
			location.href = url; // el navegador se encarga
		});
	}

	/* ------------------------------------------------------------- enlaces */

	function enlaceNavegable(a, e) {
		if (!a || e.defaultPrevented) { return false; }
		if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return false; }
		if (a.target && a.target !== '_self') { return false; }
		if (a.hasAttribute('download') || a.getAttribute('rel') === 'external') { return false; }
		if (a.dataset.ceadSoft === 'off') { return false; }
		var href = a.getAttribute('href') || '';
		if (!href || href.charAt(0) === '#') { return false; }
		// mismaBase() ya descarta lo externo y lo que no es del panel: un
		// mailto: o un tel: no tienen nuestro origin, así que caen solos.
		if (!mismaBase(a.href)) { return false; }
		// Un enlace a la MISMA url con hash es un salto interno, no una navegación.
		var u = new URL(a.href, location.href);
		if (u.hash && u.pathname === location.pathname && u.search === location.search) { return false; }
		return true;
	}

	document.addEventListener('click', function (e) {
		var a = e.target.closest ? e.target.closest('a[href]') : null;
		if (!enlaceNavegable(a, e)) { return; }
		e.preventDefault();
		// En celular el menú queda abierto tapando todo: cerrarlo es parte de
		// que la navegación se sienta terminada.
		if (typeof window.ceadCerrarNav === 'function') { window.ceadCerrarNav(false); }
		navegar(a.href);
	});

	// Prefetch: al pasar el mouse por encima o al apoyar el dedo.
	function alApuntar(e) {
		var a = e.target.closest ? e.target.closest('a[href]') : null;
		if (a && a.dataset.ceadSoft !== 'off' && mismaBase(a.href)) { prefetch(a.href); }
	}
	document.addEventListener('mouseover', alApuntar, { passive: true });
	document.addEventListener('touchstart', alApuntar, { passive: true });

	/* -------------------------------------------------------- formularios */

	/**
	 * Formularios GET dentro del panel, resueltos sin moverte de donde estás.
	 *
	 * Es el caso del selector de "ver el horario de otro curso": con la
	 * navegación normal el navegador recarga y te deja arriba de todo, así que
	 * había que bajar otra vez hasta el selector para ver el resultado que
	 * acabás de pedir. Acá se mantiene el scroll exactamente donde estaba.
	 *
	 * Los POST no se tocan: mandan datos, y un envío a medias por un problema
	 * de red es mucho peor que un parpadeo.
	 */
	document.addEventListener('submit', function (e) {
		var form = e.target;
		if (!form || e.defaultPrevented) { return; }
		if ((form.method || 'get').toLowerCase() !== 'get') { return; }
		if (form.dataset.ceadSoft === 'off') { return; }

		var accion = form.getAttribute('action') || location.href;
		if (!mismaBase(accion)) { return; }

		var params = new URLSearchParams(new FormData(form));
		var url    = new URL(accion, location.href);
		url.search = params.toString();

		e.preventDefault();
		navegar(url.toString(), { mantenerScroll: true });
	});

	/* ------------------------------------------------------ atrás/adelante */

	window.addEventListener('popstate', function (e) {
		if (!e.state || !e.state.ceadPanel) { return; }
		if (!mismaBase(location.href)) { return; }
		navegar(location.href, { push: false });
	});

	// La entrada inicial también necesita estado, o el primer "atrás" después
	// de navegar en suave no dispara popstate con nuestra marca.
	if (!history.state || !history.state.ceadPanel) {
		history.replaceState({ ceadPanel: true }, '', location.href);
	}
})();
