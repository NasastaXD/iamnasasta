<?php
/**
 * Maqueta «Informe» — el documento.
 *
 * Una memoria anual o una rendición de cuentas no se lee de corrido: se busca
 * una sección, se lee, se vuelve. Con la maqueta de noticia eso era scrollear a
 * ojo por cuatro pantallas.
 *
 * Lo que cambia: el titular sale de la display condensada y pasa a la serif —un
 * documento no es un afiche—, arriba hay una ficha con los datos duros, las
 * secciones se numeran solas y se arma un índice navegable con ellas. Sin foto
 * de portada: aquí no ilustra nada.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$cead_cats = get_the_category();
$cead_cat  = ( $cead_cats && ! is_wp_error( $cead_cats ) ) ? $cead_cats[0]->name : '';

// El contenido se procesa antes de imprimirlo para poder numerar los `h2` y
// enlazarlos desde el índice.
list( $cead_html, $cead_indice ) = cead_nota_indice( apply_filters( 'the_content', get_the_content() ) );
?>
<article <?php post_class( 'cead-article cead-article--informe' ); ?>>

	<header class="cead-inf-head">
		<span class="cead-inf-eyebrow"><?php esc_html_e( 'Informe', 'cead' ); ?></span>
		<h1 class="cead-inf-titulo"><?php the_title(); ?></h1>

		<dl class="cead-inf-ficha">
			<div>
				<dt><?php esc_html_e( 'Fecha', 'cead' ); ?></dt>
				<dd><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time></dd>
			</div>
			<?php if ( $cead_cat ) : ?>
				<div>
					<dt><?php esc_html_e( 'Área', 'cead' ); ?></dt>
					<dd><?php echo esc_html( $cead_cat ); ?></dd>
				</div>
			<?php endif; ?>
			<?php if ( $cead_indice ) : ?>
				<div>
					<dt><?php esc_html_e( 'Secciones', 'cead' ); ?></dt>
					<dd><?php echo esc_html( (string) count( $cead_indice ) ); ?></dd>
				</div>
			<?php endif; ?>
		</dl>
	</header>

	<?php
	/*
	 * El índice recién vale la pena con tres secciones o más. Con una o dos, el
	 * salto está a un scroll de distancia y la lista solo agrega ruido antes del
	 * texto.
	 */
	if ( count( $cead_indice ) >= 3 ) : ?>
		<nav class="cead-inf-indice" aria-label="<?php esc_attr_e( 'Secciones del informe', 'cead' ); ?>">
			<span class="cead-inf-indice-t"><?php esc_html_e( 'Contenido', 'cead' ); ?></span>
			<ol>
				<?php foreach ( $cead_indice as $cead_it ) : ?>
					<li><a href="#<?php echo esc_attr( $cead_it['id'] ); ?>"><?php echo esc_html( $cead_it['texto'] ); ?></a></li>
				<?php endforeach; ?>
			</ol>
		</nav>
	<?php endif; ?>

	<?php
	/*
	 * Sin escapar, igual que `the_content()`: esto YA es el contenido pasado por
	 * los filtros de WordPress, que es lo que el resto del tema imprime. Meterle
	 * un `wp_kses_post()` encima no agrega seguridad —la fuente es la misma— y sí
	 * borra en silencio los `iframe` de los videos incrustados.
	 */
	?>
	<div class="cead-article-content cead-inf-cuerpo"><?php echo $cead_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>

	<div class="cead-inf-firma">
		<span class="cead-inf-firma-linea" aria-hidden="true"></span>
		<p>
			<strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong><br>
			<?php
			/* translators: %s: fecha de publicación del informe */
			printf( esc_html__( 'Documento publicado el %s.', 'cead' ), esc_html( get_the_date() ) );
			?>
		</p>
	</div>

	<?php get_template_part( 'template-parts/nota/pie' ); ?>

</article>
