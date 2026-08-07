<?php
/**
 * Template Name: Ancho completo (Elementor)
 *
 * Página a todo el ancho, manteniendo el header y el footer del CEAD. Ideal
 * para armar landings con Elementor sin que el contenedor del tema limite el
 * ancho de las secciones.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); ?>

<main id="contenido" class="cead-fullwidth">
	<?php while ( have_posts() ) : the_post(); ?>
		<?php the_content(); ?>
	<?php endwhile; ?>
</main>

<?php get_footer();
