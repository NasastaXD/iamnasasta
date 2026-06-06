<?php
$raw   = get_theme_mod('cead_marquee_items', "Honor\nDisciplina\nRespeto\nIntegridad\nCaaguazú\nFélix de Guarania");
$items = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw))));
if (empty($items)) { $items = ['Honor', 'Disciplina', 'Respeto', 'Integridad', 'Caaguazú', 'Félix de Guarania']; }
?>
<div class="marquee-wrap">
  <div class="marquee">
    <?php for ($r = 0; $r < 3; $r++): ?>
      <?php foreach ($items as $it): ?>
        <div class="marquee-item">
          <span class="marquee-text"><?php echo esc_html($it); ?></span>
          <span class="marquee-dot"></span>
        </div>
      <?php endforeach; ?>
    <?php endfor; ?>
  </div>
</div>
