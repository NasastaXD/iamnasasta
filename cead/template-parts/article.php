<?php
/**
 * Cuerpo de una nota (entrada del blog o CPT Noticia).
 *
 * Se comparte entre single.php y single-cead_noticia.php: las noticias que
 * publica CEADI desde WhatsApp entran como entradas normales, y las cargadas a
 * mano pueden estar en cualquiera de los dos lados. Que se vean igual no
 * debería depender de por dónde entraron.
 *
 * Espera estar dentro del loop.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$cead_cats = get_the_category();
$cead_cat  = ( $cead_cats && ! is_wp_error( $cead_cats ) ) ? $cead_cats[0]->name : '';

// Volver al listado que corresponda según de dónde salió la nota.
$cead_volver = ( 'cead_noticia' === get_post_type() )
	? get_post_type_archive_link( 'cead_noticia' )
	: get_permalink( get_option( 'page_for_posts' ) );
if ( ! $cead_volver ) { $cead_volver = home_url( '/noticias/' ); }
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

	<footer class="cead-article-foot">
		<span><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
		<a class="cead-article-back" href="<?php echo esc_url( $cead_volver ); ?>">
			← <?php esc_html_e( 'Todas las noticias', 'cead' ); ?>
		</a>
	</footer>

</article>
