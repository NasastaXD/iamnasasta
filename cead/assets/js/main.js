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
    // Escape cierra, que es lo que todo el mundo intenta primero.
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu.classList.contains('is-open')) { closeMenu(true); }
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
