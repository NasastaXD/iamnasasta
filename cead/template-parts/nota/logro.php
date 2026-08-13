<?php
/**
 * Maqueta «Logro» — el reconocimiento.
 *
 * Cuando un curso gana las olimpiadas, la nota es la gente: la foto con la copa
 * y los nombres. La maqueta de noticia ponía primero el titular en negro y
 * después la foto, y quedaba con el mismo tono que un cambio de horario.
 *
 * Acá la foto es el fondo y el titular va encima. Sin foto —pasa, y no es un
 * error: a veces solo llega la noticia— la portada se resuelve con el rojo del
 * colegio en vez de dejar un hueco.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$cead_cats  = get_the_category();
$cead_cat   = ( $cead_cats && ! is_wp_error( $cead_cats ) ) ? $cead_cats[0]->name : '';
$cead_foto  = has_post_thumbnail() ? get_the_post_thumbnail_url( null, 'full' ) : '';
$cead_epi   = $cead_foto ? wp_get_attachment_caption( get_post_thumbnail_id() ) : '';
?>
<article <?php post_class( 'cead-article cead-article--logro' ); ?>>

	<header class="cead-log-portada<?php echo $cead_foto ? '' : ' is-sinfoto'; ?>"
		<?php if ( $cead_foto ) : ?>style="background-image:url('<?php echo esc_url( $cead_foto ); ?>')"<?php endif; ?>>

		<div class="cead-log-velo" aria-hidden="true"></div>

		<div class="cead-log-texto">
			<span class="cead-log-sello">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
					<path d="M8 3h8v5a4 4 0 0 1-8 0V3Z"/>
					<path d="M8 4.5H5.5V6a3 3 0 0 0 3 3"/>
					<path d="M16 4.5h2.5V6a3 3 0 0 1-3 3"/>
					<path d="M12 12v4"/><path d="M9 21h6"/><path d="M10.5 16h3l.7 5h-4.4l.7-5Z"/>
				</svg>
				<?php esc_html_e( 'Logro', 'cead' ); ?>
			</span>

			<h1 class="cead-log-titulo"><?php the_title(); ?></h1>

			<div class="cead-log-meta">
				<?php if ( $cead_cat ) : ?>
					<span><?php echo esc_html( $cead_cat ); ?></span>
					<span class="cead-log-punto" aria-hidden="true">·</span>
				<?php endif; ?>
				<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
			</div>
		</div>
	</header>

	<?php if ( $cead_epi ) : ?>
		<p class="cead-log-epigrafe"><?php echo esc_html( $cead_epi ); ?></p>
	<?php endif; ?>

	<div class="cead-article-content"><?php the_content(); ?></div>

	<?php get_template_part( 'template-parts/nota/pie' ); ?>

</article>
