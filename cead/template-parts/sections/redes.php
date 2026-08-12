<?php
/**
 * Sección Redes sociales: tarjetas configurables que redirigen a los perfiles.
 *
 * Cada tarjeta se configura en el Customizer (CEAD → Redes sociales). Si una
 * tarjeta no tiene URL propia, cae en la que ya está cargada en el footer, así
 * no hay que cargar los enlaces dos veces. Las tarjetas sin URL se omiten, y si
 * no queda ninguna la sección no se imprime.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// La lista y la resolución de URLs viven en cead_social_links() (inc/helpers.php),
// compartidas con el footer: antes cada plantilla la armaba por su cuenta y
// cargar la URL en un lado la mostraba solo en uno de los dos.
$cards = cead_social_links();

if ( ! $cards ) { return; }
?>
<section id="redes" class="section section--redes">
  <div class="container">
    <div class="section-head section-head--center">
      <div class="eyebrow eyebrow--centered"><?php echo esc_html( get_theme_mod( 'cead_redes_eyebrow', '— Redes' ) ); ?></div>
      <h2 class="display-h2 display-h2--narrow"><?php echo wp_kses_post( get_theme_mod( 'cead_redes_title', 'Seguinos.' ) ); ?></h2>
      <p class="section-intro"><?php echo wp_kses_post( get_theme_mod( 'cead_redes_intro', 'Publicamos actos, resultados y avisos en nuestras redes. Ahí también se anuncian los cambios de calendario.' ) ); ?></p>
    </div>

    <div class="redes-grid">
      <?php foreach ( $cards as $c ) : ?>
        <a class="reveal redes-card"
           href="<?php echo esc_url( $c['url'] ); ?>"
           target="_blank"
           rel="noopener noreferrer"
           style="--redes-color: <?php echo esc_attr( $c['color'] ); ?>">
          <span class="redes-card-dot" aria-hidden="true"></span>
          <span class="redes-card-name"><?php echo esc_html( $c['name'] ); ?></span>
          <?php if ( $c['handle'] ) : ?>
            <span class="redes-card-handle"><?php echo esc_html( $c['handle'] ); ?></span>
          <?php endif; ?>
          <span class="redes-card-arrow" aria-hidden="true">→</span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
