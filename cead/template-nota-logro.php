<?php
/**
 * Template Name: Nota: Logro
 * Template Post Type: post, page
 *
 * Fuerza la maqueta de logro desde el selector nativo de «Plantilla».
 * Ver `template-nota-evento.php` para el porqué de este patrón.
 *
 * No confundir con «Nota con portada» (`template-portada.php`): esa es una
 * plantilla más vieja e independiente —foto a sangre completa, sin el sello
 * «Logro» ni la firma amarilla de reconocimiento— pensada para actos y
 * eventos en general. Esta es la maqueta «Logro» del sistema de notas, la
 * misma que usa CEADI para un premio o un campeonato. Quedan las dos porque
 * tocar la plantilla vieja arriesgaría el aspecto de notas ya publicadas.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); ?>

<main id="contenido" class="container cead-single cead-page">
	<?php
	while ( have_posts() ) :
		the_post();
		get_template_part( 'template-parts/article' );
	endwhile;
	?>
</main>

<?php get_footer();
