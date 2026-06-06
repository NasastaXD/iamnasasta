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
    };
    var closeMenu = function () {
      menu.classList.remove('is-open');
      document.body.style.overflow = '';
      setTimeout(function () { menu.classList.add('hidden'); }, 700);
    };
    openBtn.addEventListener('click', openMenu);
    closeBtn.addEventListener('click', closeMenu);
    menu.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', closeMenu);
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
