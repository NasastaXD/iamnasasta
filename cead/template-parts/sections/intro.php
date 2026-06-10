<?php
/**
 * Intro overlay del home — una vez por sesión.
 *
 * Dos modos:
 *  - 3D (WebGL/Three.js): las cuatro baldosas de la marca se ensamblan en el
 *    logo con luz y parallax, la palabra CEAD emerge al frente y la escena
 *    hace zoom hacia el hero. Ver assets/js/intro-3d.js.
 *  - Fallback CSS (sin JS, sin WebGL, sin módulos ES o prefers-reduced-motion):
 *    la animación de letras original, que corre sola sin JavaScript.
 */
?>
<div id="intro-overlay" class="intro-overlay" aria-hidden="true">
  <div id="intro-3d-mount" class="intro-3d-mount"></div>

  <div class="intro-3d-text">
    <div class="intro-3d-word">
      <span>C</span><span>E</span><span>A</span><span>D</span>
    </div>
    <p class="intro-3d-sub"><?php echo esc_html__( 'Centro Educativo de Alto Desempeño · Félix de Guarania', 'cead' ); ?></p>
  </div>

  <div id="intro-line" class="intro-line"></div>
  <div id="intro-letters" class="intro-letters">
    <span class="intro-letter" style="--x: -25vw;">C</span>
    <span class="intro-letter" style="--x: -8vw;">E</span>
    <span class="intro-letter" style="--x: 8vw;">A</span>
    <span class="intro-letter" style="--x: 25vw;">D</span>
  </div>
</div>
<script>
/* Gate síncrono (antes del primer paint): decide skip / 3D / fallback CSS. */
(function () {
  var ov = document.getElementById('intro-overlay');
  if (!ov) { return; }
  try {
    if (sessionStorage.getItem('cead_intro_seen')) { ov.className += ' intro-skip'; return; }
    sessionStorage.setItem('cead_intro_seen', '1');
  } catch (e) {}
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var modules = 'noModule' in document.createElement('script');
  var webgl = false;
  try {
    var c = document.createElement('canvas');
    webgl = !!(window.WebGLRenderingContext && (c.getContext('webgl') || c.getContext('experimental-webgl')));
  } catch (e) {}
  if (!reduced && modules && webgl) {
    document.documentElement.className += ' cead-intro-3d';
    // Red de seguridad: si el módulo 3D nunca completa (CDN caído, error),
    // soltar el overlay igual. El módulo la cancela al tomar control.
    window.__ceadIntroFailsafe = setTimeout(function () { ov.className += ' is-done'; }, 8000);
  }
})();
</script>
<script type="module" src="<?php echo esc_url( CEAD_URI . '/assets/js/intro-3d.js?ver=' . CEAD_VERSION ); ?>"></script>
