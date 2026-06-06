<?php
/**
 * Logo reutilizable. Si hay custom_logo, lo usa; si no, muestra el logo construido.
 */
$dark = !empty($args['dark']);
$ink  = $dark ? '#FFFFFF' : '#0B0B0B';

if (has_custom_logo()):
    the_custom_logo();
else: ?>
    <div class="cead-logo">
      <div class="cead-logo-marks" aria-hidden="true">
        <span class="logo-mark logo-mark--brand"></span>
        <span class="logo-mark logo-mark--blue"></span>
        <span class="logo-mark logo-mark--yellow"></span>
        <span class="logo-mark logo-mark--orange"></span>
      </div>
      <div class="cead-logo-text">
        <div class="cead-logo-title" style="color: <?php echo esc_attr($ink); ?>">CEAD</div>
        <div class="cead-logo-sub"   style="color: <?php echo esc_attr($ink); ?>">Félix de Guarania</div>
      </div>
    </div>
<?php endif;
