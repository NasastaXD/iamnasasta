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

$defaults = [
	1 => [ 'Instagram', '@cead.caaguazu', 'cead_social_ig_url', '#E1306C' ],
	2 => [ 'Facebook',  'CEAD Félix de Guarania', 'cead_social_fb_url', '#1877F2' ],
	3 => [ 'YouTube',   'CEAD Caaguazú', 'cead_social_yt_url', '#FF0000' ],
	4 => [ 'WhatsApp',  'Consultas', 'cead_social_in_url', '#25D366' ],
];

$cards = [];
foreach ( $defaults as $n => $d ) {
	$url = trim( (string) get_theme_mod( "cead_redes_{$n}_url", '' ) );
	if ( '' === $url || '#' === $url ) {
		$url = trim( (string) get_theme_mod( $d[2], '' ) );
	}
	if ( '' === $url || '#' === $url ) { continue; }

	$cards[] = [
		'name'   => get_theme_mod( "cead_redes_{$n}_name",   $d[0] ),
		'handle' => get_theme_mod( "cead_redes_{$n}_handle", $d[1] ),
		'color'  => get_theme_mod( "cead_redes_{$n}_color",  $d[3] ),
		'url'    => $url,
	];
}

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
