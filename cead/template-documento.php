<?php
/**
 * Template Name: Documento institucional
 * Template Post Type: post, page
 *
 * Para circulares, resoluciones y notas formales.
 *
 * Es el tono opuesto al de una noticia: nada de titular en mayúsculas ni letra
 * capital. Membrete centrado, tipografía con serifas, texto justificado y
 * bloque de firma. Pensado también para imprimirse — que es lo que termina
 * pasando con una circular — así que trae sus propios estilos de impresión.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); ?>

<main id="contenido" class="container cead-page">
	<?php while ( have_posts() ) : the_post(); ?>
		<article <?php post_class( 'cead-doc' ); ?>>

			<header class="cead-doc-head">
				<div class="cead-doc-marks" aria-hidden="true">
					<span class="logo-mark logo-mark--brand"></span>
					<span class="logo-mark logo-mark--blue"></span>
					<span class="logo-mark logo-mark--yellow"></span>
					<span class="logo-mark logo-mark--orange"></span>
				</div>
				<div class="cead-doc-org"><?php bloginfo( 'name' ); ?></div>
				<div class="cead-doc-place">
					<?php echo esc_html( get_theme_mod( 'cead_footer_note', 'Caaguazú, Paraguay' ) ); ?>
				</div>
			</header>

			<div class="cead-doc-rule" aria-hidden="true"></div>

			<div class="cead-doc-meta">
				<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
			</div>

			<h1 class="cead-doc-title"><?php the_title(); ?></h1>

			<div class="cead-doc-content"><?php the_content(); ?></div>

			<?php
			/**
			 * Firma. Se toma del campo personalizado `firma` si existe; si no,
			 * queda la línea vacía para firmar a mano sobre el papel.
			 */
			$cead_firma  = get_post_meta( get_the_ID(), 'firma', true );
			$cead_cargo  = get_post_meta( get_the_ID(), 'cargo', true );
			?>
			<div class="cead-doc-sign">
				<div class="cead-doc-sign-line" aria-hidden="true"></div>
				<?php if ( $cead_firma ) : ?>
					<div class="cead-doc-sign-name"><?php echo esc_html( $cead_firma ); ?></div>
				<?php endif; ?>
				<?php if ( $cead_cargo ) : ?>
					<div class="cead-doc-sign-role"><?php echo esc_html( $cead_cargo ); ?></div>
				<?php endif; ?>
			</div>

		</article>
	<?php endwhile; ?>
</main>

<?php get_footer();
