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

	/*
	 * El prefijo del panel sale de la URL que ya localiza PHP, no de un '/panel'
	 * escrito a mano. Con WordPress instalado en un subdirectorio el panel vive
	 * en `/sitio/panel/…`, y comparando contra '/panel' pelado ningún enlace
	 * pasaba el filtro: el router se apagaba entero, en silencio. Al revés,
	 * cualquier página que empezara con esas letras —`/paneles-solares`— entraba
	 * como si fuera del panel. La barra final es la que cierra las dos puertas.
	 */
	var BASE = (function () {
		try {
			var p = new URL(window.CeadAcad && CeadAcad.urls && CeadAcad.urls.panel, location.href).pathname;
			return p.replace(/\/+$/, '') + '/';
		} catch (e) { return '/panel/'; }
	})();

	function enElPanel(pathname) {
		return pathname === BASE.slice(0, -1) || pathname.indexOf(BASE) === 0;
	}

	function mismaBase(url) {
		try {
			var u = new URL(url, location.href);
			return u.origin === location.origin && enElPanel(u.pathname);
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
			if (!enElPanel(new URL(r.url, location.href).pathname)) {
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

		/*
		 * El título VISIBLE de la topbar, no solo el de la pestaña. Sin esto se
		 * cambiaba de pantalla y el encabezado seguía diciendo el nombre de la
		 * anterior —«Horarios» sobre la lista de comunicados— hasta la próxima
		 * recarga completa, que con el router ya no llega nunca.
		 */
		var h1Nuevo = doc.querySelector('.cead-acad-panel-topbar-title');
		var h1Ahora = document.querySelector('.cead-acad-panel-topbar-title');
		if (h1Nuevo && h1Ahora) { h1Ahora.textContent = h1Nuevo.textContent; }

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
	 * Pero solo cuando el formulario filtra la pantalla en la que ya estás: se
	 * compara el destino con la ruta actual. El buscador de la topbar también es
	 * un GET, y manda a `/panel/buscar`, que es OTRA pantalla — dejarlo con el
	 * scroll viejo aterrizaba a media página de los resultados, mirando lo que
	 * hubiera a esa altura, y sin mover el foco al contenido nuevo.
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
		navegar(url.toString(), { mantenerScroll: url.pathname === location.pathname });
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

	/* ============================================================ CEADI ==
	 * El punto de abajo a la derecha. Tocarlo despliega un menú con las dos
	 * formas de hablarle —el chat de acá o WhatsApp— y desde ahí se abre el
	 * chat. Vive fuera del <main>, así que el router no lo toca y la
	 * conversación sobrevive al cambio de pantalla.
	 *
	 * Tres estados en `data-estado`: `cerrado`, `menu`, `abierto`. Si la
	 * plantilla no dibujó el menú (no hay número de WhatsApp cargado), el
	 * estado `menu` no existe y el punto abre el chat directo.
	 * ==================================================================== */
	(function ceadi() {
		var caja = document.getElementById('cead-ceadi');
		if (!caja || !window.CeadAcad || !CeadAcad.rest) { return; }

		var panel   = document.getElementById('cead-ceadi-panel');
		var menu    = document.getElementById('cead-ceadi-menu');
		var abrir   = document.getElementById('cead-ceadi-abrir');
		var irChat  = document.getElementById('cead-ceadi-chat');
		var cerrar  = document.getElementById('cead-ceadi-cerrar');
		var hilo    = document.getElementById('cead-ceadi-hilo');
		var form    = document.getElementById('cead-ceadi-form');
		var input   = document.getElementById('cead-ceadi-input');
		var enviarB = form ? form.querySelector('button[type=submit]') : null;
		var ocupado = false;

		function estado()      { return caja.dataset.estado; }
		function estaAbierto() { return estado() === 'abierto'; }

		function abrirMenu() {
			caja.dataset.estado = 'menu';
			menu.hidden = false;
			abrir.setAttribute('aria-expanded', 'true');
			focusSinSaltar(menu.querySelector('button, a'));
		}
		function cerrarMenu(devolverFoco) {
			caja.dataset.estado = 'cerrado';
			menu.hidden = true;
			abrir.setAttribute('aria-expanded', 'false');
			if (devolverFoco) { focusSinSaltar(abrir); }
		}

		function abrirChat() {
			if (menu) { menu.hidden = true; }
			caja.dataset.estado = 'abierto';
			panel.hidden = false;
			abrir.setAttribute('aria-expanded', 'true');
			focusSinSaltar(input);
			hilo.scrollTop = hilo.scrollHeight;
		}
		function cerrarChat(devolverFoco) {
			caja.dataset.estado = 'cerrado';
			panel.hidden = true;
			abrir.setAttribute('aria-expanded', 'false');
			if (devolverFoco) { focusSinSaltar(abrir); }
		}

		abrir.addEventListener('click', function () {
			// Sin menú dibujado hay una sola cosa que hacer, así que se hace.
			if (!menu) { abrirChat(); return; }
			if (estado() === 'menu') { cerrarMenu(true); } else { abrirMenu(); }
		});
		if (irChat) { irChat.addEventListener('click', abrirChat); }
		cerrar.addEventListener('click', function () { cerrarChat(true); });

		// Tocar fuera cierra el menú. El chat no: se cierra con su ✕ o con
		// Escape, porque cerrarlo de un toque perdido en medio de escribir
		// sería peor que dejarlo abierto.
		document.addEventListener('click', function (e) {
			if (estado() === 'menu' && !caja.contains(e.target)) { cerrarMenu(false); }
		});

		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape' && e.keyCode !== 27) { return; }
			// Se cierra de a una capa: el chat primero, el menú después.
			if (estaAbierto())          { cerrarChat(true); }
			else if (estado() === 'menu') { cerrarMenu(true); }
		});

		function burbuja(texto, quien) {
			var d = document.createElement('div');
			d.className = 'cead-ceadi-msg cead-ceadi-msg--' + quien;
			d.textContent = texto;
			hilo.appendChild(d);
			hilo.scrollTop = hilo.scrollHeight;
			return d;
		}

		function pensando() {
			var d = document.createElement('div');
			d.className = 'cead-ceadi-pensando';
			d.innerHTML = '<span></span><span></span><span></span>';
			hilo.appendChild(d);
			hilo.scrollTop = hilo.scrollHeight;
			return d;
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (ocupado) { return; }
			var texto = (input.value || '').trim();
			if (!texto) { return; }

			burbuja(texto, 'yo');
			input.value = '';
			ocupado = true;
			if (enviarB) { enviarB.disabled = true; }
			var puntos = pensando();

			fetch(CeadAcad.rest.ceadi, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': CeadAcad.rest.nonce
				},
				body: JSON.stringify({ mensaje: texto })
			}).then(function (r) {
				return r.json().then(function (data) { return { ok: r.ok, data: data }; });
			}).then(function (res) {
				puntos.remove();
				if (res.ok && res.data && res.data.respuesta) {
					burbuja(res.data.respuesta, 'bot');
				} else {
					// El mensaje del servidor es más útil que uno genérico: distingue
					// "esperá unos segundos" de "CEADI no está disponible".
					burbuja((res.data && res.data.message) || CeadAcad.i18n.genericError, 'error');
				}
			}).catch(function () {
				puntos.remove();
				burbuja(CeadAcad.i18n.genericError, 'error');
			}).then(function () {
				ocupado = false;
				if (enviarB) { enviarB.disabled = false; }
				focusSinSaltar(input);
			});
		});
	})();
})();
