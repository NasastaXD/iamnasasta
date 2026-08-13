<?php
/**
 * Pie común a todas las maquetas: firma del sitio y vuelta al listado.
 *
 * Va aparte porque el destino de «volver» depende de por dónde entró la nota
 * (entrada del blog o CPT Noticia) y esa decisión no tiene por qué repetirse
 * —ni divergir— en cinco archivos.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$cead_volver = ( 'cead_noticia' === get_post_type() )
	? get_post_type_archive_link( 'cead_noticia' )
	: get_permalink( get_option( 'page_for_posts' ) );
if ( ! $cead_volver ) { $cead_volver = home_url( '/noticias/' ); }
?>
<footer class="cead-article-foot">
	<span><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
	<a class="cead-article-back" href="<?php echo esc_url( $cead_volver ); ?>">
		← <?php esc_html_e( 'Todas las noticias', 'cead' ); ?>
	</a>
</footer>
