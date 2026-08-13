<?php
/**
 * Maqueta «Noticia» — la crónica.
 *
 * Es la de siempre y el destino de todo lo que no elija otra: volanta con
 * categoría y fecha, titular grande, foto, texto. Sirve para contar algo que
 * pasó, que es la mayoría de lo que publica el colegio.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$cead_cats = get_the_category();
$cead_cat  = ( $cead_cats && ! is_wp_error( $cead_cats ) ) ? $cead_cats[0]->name : '';
?>
<article <?php post_class( 'cead-article cead-article--news' ); ?>>

	<header class="cead-article-head">
		<div class="cead-article-meta">
			<?php if ( $cead_cat ) : ?>
				<span class="cead-article-cat"><?php echo esc_html( $cead_cat ); ?></span>
				<span class="cead-article-meta-sep" aria-hidden="true"></span>
			<?php endif; ?>
			<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
		</div>

		<h1 class="cead-article-title"><?php the_title(); ?></h1>
		<div class="cead-article-rule" aria-hidden="true"></div>
	</header>

	<?php if ( has_post_thumbnail() ) : ?>
		<figure class="cead-article-thumb">
			<?php the_post_thumbnail( 'large' ); ?>
			<?php
			$cead_pie = wp_get_attachment_caption( get_post_thumbnail_id() );
			if ( $cead_pie ) :
				?>
				<figcaption><?php echo esc_html( $cead_pie ); ?></figcaption>
			<?php endif; ?>
		</figure>
	<?php endif; ?>

	<div class="cead-article-content"><?php the_content(); ?></div>

	<?php get_template_part( 'template-parts/nota/pie' ); ?>

</article>
