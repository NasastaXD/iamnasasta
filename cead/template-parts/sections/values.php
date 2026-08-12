<?php
$values = [];
for ($i = 1; $i <= 4; $i++) {
    $values[] = [
        'n'     => cead_pad2($i),
        'title' => get_theme_mod("cead_value_{$i}_title", ['Honor','Disciplina','Respeto','Integridad'][$i-1]),
        'color' => get_theme_mod("cead_value_{$i}_color", ['#E93B3C','#49A3C8','#EDDF58','#F4B74C'][$i-1]),
        'shape' => get_theme_mod("cead_value_{$i}_shape", ['square','diamond','rect','circle'][$i-1]),
        'body'  => get_theme_mod("cead_value_{$i}_body",  ''),
    ];
}
?>
<section id="creemos" class="section section--values">
  <div class="container">
    <div class="section-head section-head--center">
      <div class="eyebrow"><?php echo esc_html(get_theme_mod('cead_values_eyebrow', '— Creemos')); ?></div>
      <h2 class="display-h2" data-split><?php echo wp_kses_post(get_theme_mod('cead_values_title', 'Cuatro valores.<br>Una sola institución.')); ?></h2>
      <p class="section-intro"><?php echo wp_kses_post(get_theme_mod('cead_values_intro', 'Toda la cultura de CEAD descansa sobre estos cuatro pilares. No son aspiraciones: son criterios diarios de admisión, evaluación y graduación.')); ?></p>
    </div>

    <div class="values-grid">
      <?php foreach ($values as $vi => $v): ?>
        <div class="reveal values-card" style="--i:<?php echo (int) $vi; ?>">
          <div class="values-card-head">
            <?php echo cead_value_shape_html($v['shape'], $v['color']); /* HTML autorizado */ ?>
            <span class="values-card-num" style="color: <?php echo esc_attr($v['color']); ?>"><?php echo esc_html($v['n']); ?></span>
          </div>
          <h3 class="values-card-title"><?php echo esc_html($v['title']); ?></h3>
          <p class="values-card-body"><?php echo esc_html($v['body']); ?></p>
          <div class="values-card-bar" style="background: <?php echo esc_attr($v['color']); ?>"></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
