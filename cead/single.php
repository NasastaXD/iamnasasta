<?php
/**
 * Entrada individual. Es lo que se ve cuando CEADI publica una noticia desde
 * WhatsApp, así que usa la misma maqueta que las noticias cargadas a mano.
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
