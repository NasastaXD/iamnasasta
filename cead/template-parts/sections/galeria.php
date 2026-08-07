<?php
/**
 * Sección Galería: acceso al archivo /galeria + vistazos previos.
 *
 * Los mosaicos se arman en dos capas:
 *   1) Hasta 2 fotos destacadas cargadas desde el Customizer (por ejemplo las
 *      aéreas del campus). Enlazan al archivo de la galería.
 *   2) Los álbumes más recientes del CPT cead_galeria, cada uno a su permalink.
 *
 * Si no hay ni fotos destacadas ni álbumes con imagen, la sección no se imprime.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$gal_archive = get_post_type_archive_link( 'cead_galeria' );
if ( ! $gal_archive ) { $gal_archive = home_url( '/galeria/' ); }

$tiles = [];

// 1) Fotos destacadas del Customizer.
for ( $i = 1; $i <= 2; $i++ ) {
	$url = get_theme_mod( "cead_galeria_foto_{$i}", '' );
	if ( ! $url ) { continue; }
	$tiles[] = [
		'img'   => $url,
		'title' => get_theme_mod( "cead_galeria_foto_{$i}_caption", '' ),
		'link'  => $gal_archive,
		'meta'  => get_theme_mod( "cead_galeria_foto_{$i}_tag", '' ),
	];
}

// 2) Álbumes recientes.
$gal_q = new WP_Query( [
	'post_type'           => 'cead_galeria',
	'posts_per_page'      => 5,
	'ignore_sticky_posts' => true,
	'no_found_rows'       => true,
] );

if ( $gal_q->have_posts() ) {
	while ( $gal_q->have_posts() ) {
		$gal_q->the_post();
		$img = cead_post_image_url( get_the_ID(), '_cead_vida_image_url', 'large' );
		if ( ! $img ) { continue; }
		$tiles[] = [
			'img'   => $img,
			'title' => get_the_title(),
			'link'  => get_permalink(),
			'meta'  => get_the_date( 'M Y' ),
		];
	}
	wp_reset_postdata();
}

if ( ! $tiles ) { return; }

$tiles = array_slice( $tiles, 0, 5 );

// El cuadro grande solo tiene sentido si hay con qué rodearlo: con una o dos
// fotos deja media grilla vacía, así que ahí van todas del mismo tamaño.
$lead = count( $tiles ) >= 3;
?>
<section id="galeria" class="section section--galeria">
  <div class="container">
    <div class="section-head section-head--split">
      <div>
        <div class="eyebrow"><?php echo esc_html( get_theme_mod( 'cead_galeria_eyebrow', '— Galería' ) ); ?></div>
        <h2 class="display-h2 display-h2--narrow"><?php echo wp_kses_post( get_theme_mod( 'cead_galeria_home_title', 'El campus, desde adentro<br>y desde el aire.' ) ); ?></h2>
      </div>
      <p class="section-intro section-intro--right"><?php echo wp_kses_post( get_theme_mod( 'cead_galeria_intro', 'Actos, competencias, aulas y proyectos. Un vistazo a lo que se vive todos los días en el CEAD.' ) ); ?></p>
    </div>

    <div class="galeria-mosaic">
      <?php foreach ( $tiles as $idx => $t ) : ?>
        <a class="reveal galeria-tile<?php echo ( $lead && 0 === $idx ) ? ' galeria-tile--lead' : ''; ?>" href="<?php echo esc_url( $t['link'] ); ?>">
          <img src="<?php echo esc_url( $t['img'] ); ?>" alt="<?php echo esc_attr( $t['title'] ?: __( 'Galería CEAD', 'cead' ) ); ?>" loading="lazy">
          <span class="galeria-tile-overlay"></span>
          <?php if ( $t['title'] || $t['meta'] ) : ?>
            <span class="galeria-tile-body">
              <?php if ( $t['meta'] ) : ?><span class="galeria-tile-meta"><?php echo esc_html( $t['meta'] ); ?></span><?php endif; ?>
              <?php if ( $t['title'] ) : ?><span class="galeria-tile-title"><?php echo esc_html( $t['title'] ); ?></span><?php endif; ?>
            </span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="galeria-cta">
      <a href="<?php echo esc_url( $gal_archive ); ?>" class="cead-btn cead-btn-dark"><?php echo esc_html( get_theme_mod( 'cead_galeria_btn_text', '→ Ver la galería completa' ) ); ?></a>
    </div>
  </div>
</section>
