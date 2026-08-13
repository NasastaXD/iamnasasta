<?php
/**
 * Template Name: Nota: Informe
 * Template Post Type: post, page
 *
 * Fuerza la maqueta de informe desde el selector nativo de «Plantilla».
 * Ver `template-nota-evento.php` para el porqué de este patrón.
 *
 * No confundir con «Documento institucional» (`template-documento.php`): esa
 * es una plantilla más vieja e independiente, con membrete y bloque de firma,
 * pensada para circulares que se imprimen. Esta es la maqueta «Informe» del
 * sistema de notas —serif, ficha de datos, índice navegable—, la misma que
 * usa CEADI cuando redacta un documento largo. Quedan las dos porque tocar la
 * plantilla vieja arriesgaría el aspecto de circulares que ya estén
 * publicadas con ella.
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
