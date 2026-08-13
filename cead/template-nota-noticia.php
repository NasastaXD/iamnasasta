<?php
/**
 * Template Name: Nota: Noticia
 * Template Post Type: post, page
 *
 * Fuerza la maqueta de noticia desde el selector nativo de «Plantilla», para
 * quien prefiere elegirla ahí en vez de en el recuadro «Maqueta de la nota»
 * del costado del editor — o para forzarla sobre una nota que el detector
 * automático (CEADI, o el meta guardado) clasificó distinto.
 *
 * Es la única de las cinco que no puede degradarse a sí misma (no pide
 * ningún dato), así que este archivo es el más simple de los cinco.
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
