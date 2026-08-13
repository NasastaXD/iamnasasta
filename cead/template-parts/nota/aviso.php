<?php
/**
 * Maqueta «Aviso» — lo urgente.
 *
 * «Mañana no hay clases» son cinco palabras que tienen que leerse desde la
 * puerta. En la maqueta de noticia entraban con la misma volanta, la misma foto
 * y el mismo cuerpo a 17px que una crónica de tres pantallas.
 *
 * Acá no hay foto arriba ni volanta de categoría: una banda roja que dice qué
 * es, el texto grande, y listo. Si igual vino una imagen, va abajo —donde no
 * demora la lectura pero tampoco se pierde.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<article <?php post_class( 'cead-article cead-article--aviso' ); ?>>

	<header class="cead-avi-head">
		<div class="cead-avi-banda">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
				<path d="M12 3 1.8 20.5h20.4L12 3Z"/><path d="M12 10v4.5"/><path d="M12 17.6v.1"/>
			</svg>
			<span><?php esc_html_e( 'Aviso', 'cead' ); ?></span>
			<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
		</div>

		<h1 class="cead-avi-titulo"><?php the_title(); ?></h1>
	</header>

	<div class="cead-article-content cead-avi-cuerpo"><?php the_content(); ?></div>

	<?php if ( has_post_thumbnail() ) : ?>
		<figure class="cead-article-thumb cead-avi-foto">
			<?php the_post_thumbnail( 'large' ); ?>
			<?php
			$cead_pie = wp_get_attachment_caption( get_post_thumbnail_id() );
			if ( $cead_pie ) :
				?>
				<figcaption><?php echo esc_html( $cead_pie ); ?></figcaption>
			<?php endif; ?>
		</figure>
	<?php endif; ?>

	<?php get_template_part( 'template-parts/nota/pie' ); ?>

</article>
