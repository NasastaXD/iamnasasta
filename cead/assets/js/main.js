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
      setTimeout(function () { menu.classList.add('hidden'); }, 700);
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

  // ---------- Reveals on view ----------
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.classList.add('is-in');
          io.unobserve(e.target);
        }
      });
    }, { rootMargin: '-80px' });
    document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });
  } else {
    // Fallback sin IO: mostrar todo
    document.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('is-in'); });
  }
})();
