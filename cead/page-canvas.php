<?php
/**
 * Template Name: Lienzo Elementor (en blanco)
 *
 * Página sin header ni footer del tema: lienzo limpio para construir todo con
 * Elementor (similar al "Canvas" de Elementor) pero conservando los estilos y
 * fuentes del CEAD. Útil para landings especiales o páginas de campaña.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'cead-body cead-canvas' ); ?>>
<?php wp_body_open(); ?>
	<main id="contenido" class="cead-canvas-main">
		<?php
		while ( have_posts() ) :
			the_post();
			the_content();
		endwhile;
		?>
	</main>
<?php wp_footer(); ?>
</body>
</html>
