<?php
/**
 * Entrada individual. Es lo que se ve cuando CEADI publica una noticia desde
 * WhatsApp, así que usa la misma maqueta que las noticias cargadas a mano.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); ?>

<main class="container cead-single" style="padding-top:8rem;padding-bottom:6rem">
	<?php
	while ( have_posts() ) :
		the_post();
		get_template_part( 'template-parts/article' );
	endwhile;
	?>
</main>

<?php get_footer();
