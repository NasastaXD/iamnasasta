<?php
$hero_image  = get_theme_mod('cead_hero_image', 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?auto=format&fit=crop&w=2000&q=80');
$hero_eb     = get_theme_mod('cead_hero_eyebrow',   'Bachillerato Técnico — Caaguazú, Paraguay');
$hero_title  = get_theme_mod('cead_hero_title',     'Formar con<br><span class="hero-accent">honor</span> y rigor.');
$hero_body   = get_theme_mod('cead_hero_body',      'Centro Educativo de Alto Desempeño “Félix de Guarania”. Una institución pública que forma técnicos con visión académica internacional, raíz paraguaya y disciplina sostenida.');
$btn1_text   = get_theme_mod('cead_hero_btn1_text', '→ Conocer CEAD');
$btn1_url    = get_theme_mod('cead_hero_btn1_url',  '#Creemos');
$btn2_text   = get_theme_mod('cead_hero_btn2_text', '→ Admisión 2026');
$btn2_url    = get_theme_mod('cead_hero_btn2_url',  '#Admision');
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
