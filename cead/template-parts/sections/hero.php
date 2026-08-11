<?php
$hero_image  = get_theme_mod('cead_hero_image', 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?auto=format&fit=crop&w=2000&q=80');
$hero_eb     = get_theme_mod('cead_hero_eyebrow',   'Bachillerato Técnico — Caaguazú, Paraguay');
$hero_title  = get_theme_mod('cead_hero_title',     'Formar con<br><span class="hero-accent">honor</span> y rigor.');
$hero_body   = get_theme_mod('cead_hero_body',      'Centro Educativo de Alto Desempeño “Félix de Guarania”. Una institución pública que forma técnicos con visión académica internacional, raíz paraguaya y disciplina sostenida.');
$btn1_text   = get_theme_mod('cead_hero_btn1_text', '→ Conocer CEAD');
$btn2_text   = get_theme_mod('cead_hero_btn2_text', '→ Admisión 2026');

/*
 * Los destinos van en minúscula porque los `id` reales lo están
 * (`id="creemos"` en values.php, `id="admision"` en admission.php) y los
 * identificadores de fragmento DISTINGUEN MAYÚSCULAS. Los defaults decían
 * "#Creemos" y "#Admision", así que los dos botones del hero —el camino
 * principal de la home— no llevaban a ningún lado: el navegador no encontraba
 * el ancla y se quedaba donde estaba.
 *
 * Solo se reescriben esos dos valores exactos, que son el default viejo y
 * siempre un error. No se baja a minúscula cualquier ancla: `#Contacto` existe
 * con mayúscula a propósito (footer.php), y normalizar a ciegas lo rompería.
 */
$anclas_rotas = [ '#Creemos' => '#creemos', '#Admision' => '#admision' ];
$btn1_url = get_theme_mod( 'cead_hero_btn1_url', '#creemos' );
$btn2_url = get_theme_mod( 'cead_hero_btn2_url', '#admision' );
$btn1_url = $anclas_rotas[ $btn1_url ] ?? $btn1_url;
$btn2_url = $anclas_rotas[ $btn2_url ] ?? $btn2_url;
?>
<section class="hero">
  <div class="hero-bg">
    <div class="hero-bg-image ken-burns" style="background-image:url('<?php echo esc_url($hero_image); ?>')"></div>
    <div class="hero-bg-gradient"></div>
  </div>

  <div class="container hero-inner">
    <div class="reveal hero-eyebrow">
      <span class="hero-eyebrow-line"></span>
      <?php echo esc_html($hero_eb); ?>
    </div>
    <h1 class="reveal hero-title"><?php echo wp_kses_post($hero_title); ?></h1>
    <div class="reveal hero-foot">
      <p class="hero-body"><?php echo wp_kses_post($hero_body); ?></p>
      <div class="hero-buttons">
        <a href="<?php echo esc_url($btn1_url); ?>" class="cead-btn cead-btn-light"><?php echo esc_html($btn1_text); ?></a>
        <a href="<?php echo esc_url($btn2_url); ?>" class="cead-btn cead-btn-dark"><?php echo esc_html($btn2_text); ?></a>
      </div>
    </div>
  </div>

  <div class="reveal hero-scroll">
    <span>Scroll</span>
    <svg class="hero-scroll-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
  </div>
</section>
