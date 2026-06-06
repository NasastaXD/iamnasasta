<?php
$img = get_theme_mod('cead_adm_image', 'https://images.unsplash.com/photo-1577896851231-70ef18881754?auto=format&fit=crop&w=2000&q=80');
$stats = [
    [get_theme_mod('cead_adm_stat1_n', '400+'), get_theme_mod('cead_adm_stat1_l', 'Estudiantes')],
    [get_theme_mod('cead_adm_stat2_n', '04'),   get_theme_mod('cead_adm_stat2_l', 'Bachilleratos')],
    [get_theme_mod('cead_adm_stat3_n', '2009'), get_theme_mod('cead_adm_stat3_l', 'Fundación')],
];
?>
<section id="admision" class="section section--admission">
  <div class="admission-bg grain">
    <div class="admission-bg-image" style="background-image:url('<?php echo esc_url($img); ?>')"></div>
    <div class="admission-bg-gradient"></div>
  </div>

  <div class="container admission-inner">
    <div class="eyebrow admission-eyebrow"><?php echo esc_html(get_theme_mod('cead_adm_eyebrow', '— Admisión 2026')); ?></div>
    <h2 class="reveal admission-title"><?php echo wp_kses_post(get_theme_mod('cead_adm_title', 'Postulá<br>a CEAD.')); ?></h2>

    <div class="admission-foot">
      <p class="admission-body"><?php echo wp_kses_post(get_theme_mod('cead_adm_body', 'El proceso de admisión 2026 está abierto. Cuatro divisiones técnicas, evaluación académica, entrevista con familia y carta de compromiso al Honor Code.')); ?></p>
      <div class="admission-buttons">
        <a href="<?php echo esc_url(get_theme_mod('cead_adm_btn1_url', '#')); ?>" class="cead-btn cead-btn-light"><?php echo esc_html(get_theme_mod('cead_adm_btn1_text', '→ Inscribirse')); ?></a>
        <a href="<?php echo esc_url(get_theme_mod('cead_adm_btn2_url', '#')); ?>" class="cead-btn cead-btn-outline-white"><?php echo esc_html(get_theme_mod('cead_adm_btn2_text', '→ Calendario')); ?></a>
      </div>
    </div>

    <div class="admission-stats">
      <?php foreach ($stats as $s): ?>
        <div class="reveal admission-stat">
          <div class="admission-stat-num"><?php echo esc_html($s[0]); ?></div>
          <div class="admission-stat-label"><?php echo esc_html($s[1]); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
