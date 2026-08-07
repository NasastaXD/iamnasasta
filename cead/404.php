<?php
/**
 * Página no encontrada.
 *
 * Un 404 en un sitio institucional casi siempre es un enlace viejo circulando
 * por WhatsApp. En vez de dejar a la persona en un callejón sin salida, se le
 * ofrece la búsqueda y los destinos que más se buscan.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();

$cead_404 = function_exists( 'cead_site_links' ) ? cead_site_links() : [];
?>

<main id="contenido" class="container cead-page cead-404">
	<div class="eyebrow">— <?php esc_html_e( 'Error 404', 'cead' ); ?></div>
	<h1 class="cead-article-title"><?php esc_html_e( 'Esta página no existe.', 'cead' ); ?></h1>
	<div class="cead-article-rule" aria-hidden="true"></div>

	<p class="cead-404-text">
		<?php esc_html_e( 'Puede que el enlace esté viejo o que la dirección tenga un error de tipeo. Probá buscar lo que necesitás:', 'cead' ); ?>
	</p>

	<form class="cead-404-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
		<label class="sr-only" for="cead-404-s"><?php esc_html_e( 'Buscar en el sitio', 'cead' ); ?></label>
		<input class="form-input" type="search" id="cead-404-s" name="s"
		       placeholder="<?php esc_attr_e( 'Buscar…', 'cead' ); ?>">
		<button type="submit" class="cead-btn cead-btn-dark"><?php esc_html_e( 'Buscar', 'cead' ); ?></button>
	</form>

	<?php if ( $cead_404 ) : ?>
		<div class="cead-404-links">
			<div class="footer-col-title"><?php esc_html_e( 'O ir directo a', 'cead' ); ?></div>
			<ul class="cead-404-list">
				<?php foreach ( $cead_404 as $l ) : ?>
					<li><a class="underline-brand" href="<?php echo esc_url( $l['url'] ); ?>"><?php echo esc_html( $l['label'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
</main>

<?php get_footer();
