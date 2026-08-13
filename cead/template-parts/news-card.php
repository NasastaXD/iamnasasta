<?php
/**
 * Tarjeta de una nota en un listado (portada de noticias, archivo, búsqueda).
 *
 * Se comparte entre el archivo de Noticias y el listado genérico (index.php).
 * Antes index.php imprimía the_excerpt() suelto: las tablas del fixture salían
 * aplanadas en un párrafo interminable y la foto ni aparecía.
 *
 * Espera estar dentro del loop.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$cead_cats = get_the_category();
$cead_cat  = ( $cead_cats && ! is_wp_error( $cead_cats ) ) ? $cead_cats[0]->name : '';

// El resumen se arma del extracto si lo hay; si no, del contenido SIN etiquetas
// (una tabla convertida a texto no dice nada, así que se descarta lo que no
// sean párrafos antes de recortar).
$cead_resumen = get_the_excerpt();
if ( ! $cead_resumen ) {
	$cead_resumen = wp_strip_all_tags( strip_shortcodes( get_the_content() ) );
}
$cead_resumen = wp_trim_words( $cead_resumen, 24 );

/*
 * El sello de maqueta en la tarjeta.
 *
 * En un listado, «se suspenden las clases del jueves» y «así fue la jornada del
 * jueves» son dos titulares parecidos con urgencias opuestas, y hasta acá se
 * veían iguales. La noticia no lleva sello: es la mayoría de lo que hay y un
 * sello en todas las tarjetas no distingue nada.
 */
$cead_tipo  = function_exists( 'cead_nota_tipo' ) ? cead_nota_tipo() : 'noticia';
$cead_tipos = function_exists( 'cead_nota_tipos' ) ? cead_nota_tipos() : [];
$cead_sello = ( 'noticia' !== $cead_tipo && isset( $cead_tipos[ $cead_tipo ] ) )
	? (string) $cead_tipos[ $cead_tipo ]['label']
	: '';
?>
<a class="reveal cead-news-card" href="<?php the_permalink(); ?>">
	<div class="cead-news-thumb">
		<?php if ( $cead_sello ) : ?>
			<span class="cead-news-sello cead-news-sello--<?php echo esc_attr( $cead_tipo ); ?>"><?php echo esc_html( $cead_sello ); ?></span>
		<?php endif; ?>
		<?php if ( has_post_thumbnail() ) : ?>
			<?php the_post_thumbnail( 'large' ); ?>
		<?php endif; ?>
	</div>
	<div class="cead-news-body">
		<div class="eyebrow">
			<?php if ( $cead_cat ) : ?>
				<?php echo esc_html( $cead_cat ); ?> ·
			<?php endif; ?>
			<?php echo esc_html( get_the_date() ); ?>
		</div>
		<h3 class="cead-news-title"><?php the_title(); ?></h3>
		<p class="cead-news-text"><?php echo esc_html( $cead_resumen ); ?></p>
	</div>
</a>
