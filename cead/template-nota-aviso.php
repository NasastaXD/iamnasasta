<?php
/**
 * Template Name: Nota: Aviso
 * Template Post Type: post, page
 *
 * Fuerza la maqueta de aviso desde el selector nativo de «Plantilla».
 * Ver `template-nota-evento.php` para el porqué de este patrón.
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
