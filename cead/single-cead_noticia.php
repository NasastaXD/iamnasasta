<?php
/**
 * Noticia individual (CPT). Misma maqueta que las entradas: ver template-parts/article.php.
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
