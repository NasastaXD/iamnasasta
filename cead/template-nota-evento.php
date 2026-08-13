<?php
/**
 * Template Name: Nota: Evento
 * Template Post Type: post, page
 *
 * Fuerza la maqueta de evento desde el selector nativo de «Plantilla».
 *
 * Comparte la MISMA validación que decide si un evento se degrada a noticia
 * (vía `cead_nota_tipo( null, 'evento' )`, en `inc/notas.php`): elegir esto a
 * mano sin cargar la fecha en el recuadro del costado tampoco tiene que
 * dibujar el bloque de fecha vacío. No hay una segunda copia de esa regla acá
 * — si la hubiera, las dos podrían desalinearse con el tiempo.
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
