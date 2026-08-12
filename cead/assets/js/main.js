/* CEAD — interacciones */

(function () {
  'use strict';

  // ---------- NAV: scrolled state ----------
  var nav = document.getElementById('site-nav');
  if (nav) {
    var onScroll = function () {
      if (window.scrollY > window.innerHeight * 1.4) nav.classList.add('is-scrolled');
      else nav.classList.remove('is-scrolled');
    };
    document.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // ---------- Mega menu ----------
  var menu     = document.getElementById('mega-menu');
  var openBtn  = document.getElementById('menu-open');
  var closeBtn = document.getElementById('menu-close');
  if (menu && openBtn && closeBtn) {
    /*
     * Cuánto tarda el barrido del panel en irse, leído del MISMO token que lo
     * anima (`--dur-menu` en styles.css).
     *
     * Acá había un `700` escrito a mano que replicaba ese token. El día que se
     * acelerara la transición sin acordarse de esta línea, el overlay quedaba
     * con `display:block` —invisible pero presente, tapando los clics— 700ms
     * de más después de cerrarse. Leyéndolo no se puede desincronizar.
     */
    var duracionMenu = (function () {
      var v = getComputedStyle(document.documentElement).getPropertyValue('--dur-menu').trim();
      var n = parseFloat(v);
      if (!n) { return 420; }
      return /ms$/.test(v) ? n : n * 1000;
    })();

    var openMenu = function () {
      menu.classList.remove('hidden');
      requestAnimationFrame(function () { menu.classList.add('is-open'); });
      document.body.style.overflow = 'hidden';
      openBtn.setAttribute('aria-expanded', 'true');
      // El menú tapa toda la pantalla: el foco tiene que entrar con él, si no
      // quien navega con teclado sigue tabulando por detrás.
      closeBtn.focus();
    };
    var closeMenu = function (devolverFoco) {
      menu.classList.remove('is-open');
      document.body.style.overflow = '';
      openBtn.setAttribute('aria-expanded', 'false');
      setTimeout(function () { menu.classList.add('hidden'); }, duracionMenu);
      if (devolverFoco === true) { openBtn.focus(); }
    };
    openBtn.addEventListener('click', openMenu);
    closeBtn.addEventListener('click', function () { closeMenu(true); });
    menu.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () { closeMenu(false); });
    });

    /*
     * El foco queda encerrado mientras el menú está abierto.
     *
     * El menú es `role="dialog" aria-modal="true"` y tapa la pantalla entera,
     * pero el foco se escapaba igual: al llegar al último enlace, el siguiente
     * Tab saltaba al contenido de atrás — invisible, tapado por el overlay— y
     * quien navega con teclado se perdía sin ninguna pista de dónde estaba.
     *
     * Se recalculan los focusables en cada Tab en vez de guardarlos al abrir:
     * el mega menú se arma con lo que el sitio sirve, así que su contenido
     * cambia entre páginas.
     */
    var SELECTOR_FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

    document.addEventListener('keydown', function (e) {
      if (!menu.classList.contains('is-open')) { return; }

      // Escape cierra, que es lo que todo el mundo intenta primero.
      if (e.key === 'Escape') { closeMenu(true); return; }

      if (e.key !== 'Tab') { return; }

      var focusables = Array.prototype.filter.call(
        menu.querySelectorAll(SELECTOR_FOCUSABLE),
        function (el) { return el.offsetParent !== null; }
      );
      if (!focusables.length) { return; }

      var primero = focusables[0];
      var ultimo  = focusables[focusables.length - 1];

      // Si el foco se fue afuera (o nunca entró), lo traemos de vuelta.
      if (!menu.contains(document.activeElement)) {
        e.preventDefault();
        (e.shiftKey ? ultimo : primero).focus();
        return;
      }
      if (e.shiftKey && document.activeElement === primero) {
        e.preventDefault();
        ultimo.focus();
      } else if (!e.shiftKey && document.activeElement === ultimo) {
        e.preventDefault();
        primero.focus();
      }
    });
  }

  // ---------- Movimiento: ¿lo quiere esta persona? ----------
  var quietud = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');
  function menosMovimiento() { return quietud && quietud.matches; }

  /*
   * Escalonado automático.
   *
   * Las secciones que ya traen `--i` escrito en la plantilla se quedan como
   * están. Esto es para las grillas que NO lo tienen: en vez de ir a tocar
   * cada plantilla, se les numera los hijos acá y la cascada sale sola. El
   * efecto es el que más se nota de todo el sitio y era el que estaba a medio
   * aplicar.
   */
  var GRILLAS = '.values-grid, .divisions-grid, .life-grid, .cead-news-grid, .cead-gallery-grid, .cead-res-grid, .redes-grid, [data-stagger]';
  document.querySelectorAll(GRILLAS).forEach(function (grilla) {
    Array.prototype.forEach.call(grilla.children, function (hijo, i) {
      if (!hijo.style.getPropertyValue('--i')) { hijo.style.setProperty('--i', i); }
      if (!hijo.classList.contains('reveal')) { hijo.classList.add('reveal'); }
    });
  });

  /*
   * Títulos partidos en palabras.
   *
   * Cada palabra se descubre por recorte, una detrás de otra. En Anton, que es
   * una display pesada, esto se lee como una placa de título de documental —
   * mucho más rotundo que un fundido, y sigue siendo sobrio.
   *
   * Se parte por PALABRA y no por letra a propósito: por letra queda festivo y
   * además rompe el subrayado y la selección de texto.
   */
  document.querySelectorAll('[data-split]').forEach(function (el) {
    if (el.dataset.splitDone) { return; }

    /*
     * Se recorren los nodos de texto y se dejan intactos los elementos.
     * Es la parte que importa: estos títulos salen del Customizer y traen
     * `<br>` y `<span>` de acento adentro. Partir por `textContent` los
     * aplanaría — se perdería el salto de línea y el color de la palabra
     * destacada, que es justo lo que hace al título.
     */
    var n = 0;
    var partir = function (nodo) {
      var hijos = Array.prototype.slice.call(nodo.childNodes);
      hijos.forEach(function (hijo) {
        if (hijo.nodeType === 1) { partir(hijo); return; }   // elemento: adentro
        if (hijo.nodeType !== 3) { return; }                  // ni texto ni elemento
        var texto = hijo.nodeValue;
        if (!texto.trim()) { return; }

        var frag = document.createDocumentFragment();
        texto.split(/(\s+)/).forEach(function (trozo) {
          if (!trozo) { return; }
          if (!trozo.trim()) { frag.appendChild(document.createTextNode(trozo)); return; }
          var caja = document.createElement('span');
          caja.className = 'split-w';
          caja.style.setProperty('--i', n++);
          var dentro = document.createElement('span');
          dentro.textContent = trozo;
          caja.appendChild(dentro);
          frag.appendChild(caja);
        });
        hijo.parentNode.replaceChild(frag, hijo);
      });
    };

    partir(el);
    if (n) { el.dataset.splitDone = '1'; }
  });

  /*
   * Revelado al entrar en pantalla.
   *
   * Se observan también los `[data-split]`: esos títulos no están dentro de un
   * `.reveal` (viven sueltos en el encabezado de cada sección), así que sin
   * esto nunca recibirían el `.is-in` que dispara el revelado palabra por
   * palabra y se quedarían escondidos para siempre.
   */
  var A_REVELAR = '.reveal, [data-split]';
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.classList.add('is-in');
          io.unobserve(e.target);
        }
      });
    }, { rootMargin: '-80px' });
    document.querySelectorAll(A_REVELAR).forEach(function (el) { io.observe(el); });
  } else {
    // Fallback sin IO: mostrar todo
    document.querySelectorAll(A_REVELAR).forEach(function (el) { el.classList.add('is-in'); });
  }

  /* ==========================================================
     Efectos ligados al scroll y al cursor.
     Todo lo de acá abajo es decoración: si la persona pidió menos
     movimiento, no se engancha ni un listener.
     ========================================================== */
  if (menosMovimiento()) { return; }

  /*
   * Un solo listener de scroll para todo, con rAF.
   *
   * Cada efecto que se enganche por su cuenta a `scroll` cuesta un recálculo
   * de layout por evento, y el scroll dispara decenas por segundo. Con un
   * único punto que lee posiciones y reparte, el costo es uno solo y
   * sincronizado con el pintado.
   */
  var tareasScroll = [];
  var pendiente = false;
  function alScrollear() {
    if (pendiente) { return; }
    pendiente = true;
    requestAnimationFrame(function () {
      pendiente = false;
      var y = window.scrollY || window.pageYOffset;
      for (var i = 0; i < tareasScroll.length; i++) { tareasScroll[i](y); }
    });
  }

  // Barra de progreso de lectura.
  var progreso = document.createElement('div');
  progreso.className = 'cead-scroll-progress';
  progreso.setAttribute('aria-hidden', 'true');
  document.body.appendChild(progreso);
  tareasScroll.push(function (y) {
    var alto = document.documentElement.scrollHeight - window.innerHeight;
    progreso.style.transform = 'scaleX(' + (alto > 0 ? Math.min(y / alto, 1) : 0) + ')';
  });

  /*
   * Paralaje. El elemento se mueve una fracción de lo que se mueve la página,
   * así que el fondo queda "más lejos" que el texto. `data-parallax` es la
   * intensidad (0.15 = se mueve un 15% de lo que scrolleás).
   *
   * Solo se anima lo que está en pantalla: mover cosas que nadie ve es pagar
   * composición de gusto.
   */
  var conParalaje = [];
  document.querySelectorAll('[data-parallax]').forEach(function (el) {
    conParalaje.push({ el: el, k: parseFloat(el.dataset.parallax) || 0.15, visible: false });
  });
  if (conParalaje.length && 'IntersectionObserver' in window) {
    var ioP = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        for (var i = 0; i < conParalaje.length; i++) {
          if (conParalaje[i].el === e.target) { conParalaje[i].visible = e.isIntersecting; }
        }
      });
    }, { rootMargin: '20%' });
    conParalaje.forEach(function (p) { ioP.observe(p.el); });

    tareasScroll.push(function () {
      for (var i = 0; i < conParalaje.length; i++) {
        var p = conParalaje[i];
        if (!p.visible) { continue; }
        var caja = p.el.getBoundingClientRect();
        var centro = caja.top + caja.height / 2 - window.innerHeight / 2;
        p.el.style.transform = 'translate3d(0,' + (-centro * p.k).toFixed(2) + 'px,0)';
      }
    });
  }

  document.addEventListener('scroll', alScrollear, { passive: true });
  window.addEventListener('resize', alScrollear, { passive: true });
  alScrollear();

  /*
   * Botones magnéticos: el botón se corre unos pocos píxeles hacia el cursor
   * cuando está cerca. Es sutil —máximo 6px— pero hace que el sitio se sienta
   * "vivo bajo la mano" en vez de una lámina quieta.
   *
   * Solo con mouse: en pantalla táctil no hay cursor que seguir, y engancharlo
   * ahí solo gastaría batería.
   */
  if (window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    document.querySelectorAll('.cead-btn, [data-magnetic]').forEach(function (btn) {
      btn.addEventListener('pointermove', function (e) {
        var c = btn.getBoundingClientRect();
        var dx = (e.clientX - (c.left + c.width / 2)) / c.width;
        var dy = (e.clientY - (c.top + c.height / 2)) / c.height;
        btn.style.setProperty('--mx', (dx * 12).toFixed(2) + 'px');
        btn.style.setProperty('--my', (dy * 8).toFixed(2) + 'px');
      });
      btn.addEventListener('pointerleave', function () {
        btn.style.setProperty('--mx', '0px');
        btn.style.setProperty('--my', '0px');
      });
    });

    /*
     * Reflejo que sigue al cursor sobre las tarjetas. Se guardan las
     * coordenadas como variables CSS y el brillo lo dibuja la hoja de
     * estilos — así el JS no toca nunca ni un color ni un gradiente.
     */
    document.querySelectorAll('.values-card, .division-card, .cead-news-card, [data-spotlight]').forEach(function (card) {
      card.addEventListener('pointermove', function (e) {
        var c = card.getBoundingClientRect();
        card.style.setProperty('--px', (((e.clientX - c.left) / c.width) * 100).toFixed(1) + '%');
        card.style.setProperty('--py', (((e.clientY - c.top) / c.height) * 100).toFixed(1) + '%');
      });
    });
  }
})();
