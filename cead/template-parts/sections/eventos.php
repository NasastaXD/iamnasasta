<?php
/**
 * Sección de próximos eventos públicos (puente con el plugin CEAD Académico).
 * No se muestra si no hay eventos marcados como públicos.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$cead_events = function_exists( 'cead_upcoming_public_events' ) ? cead_upcoming_public_events( 5 ) : [];
if ( ! $cead_events ) { return; }
?>
<section id="eventos" class="section section--eventos">
  <div class="container">
    <div class="section-head">
      <div class="eyebrow"><?php echo esc_html( get_theme_mod( 'cead_eventos_eyebrow', '— Agenda' ) ); ?></div>
      <h2 class="display-h2 display-h2--narrow"><?php echo esc_html( get_theme_mod( 'cead_eventos_title', 'Próximos eventos' ) ); ?></h2>
    </div>
    <?php echo cead_render_public_events( 5 ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML escapado dentro del helper. ?>
  </div>
</section>
