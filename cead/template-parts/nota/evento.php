<?php
/**
 * Maqueta «Evento» — lo que va a pasar.
 *
 * En una convocatoria el dato es CUÁNDO, y en la maqueta de noticia quedaba
 * enterrado en el segundo párrafo. Acá abre la página: el número del día en
 * grande, el mes, la hora, el lugar, y cuánto falta ya calculado —que es la
 * cuenta que si no tiene que hacer quien lee.
 *
 * El tipo solo llega hasta acá si hay fecha guardada: `cead_nota_tipo()` degrada
 * a noticia cuando falta, así que este archivo puede darla por buena.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$cead_fecha = (string) get_post_meta( get_the_ID(), CEAD_NOTA_FECHA, true );
$cead_lugar = (string) get_post_meta( get_the_ID(), CEAD_NOTA_LUGAR, true );
$cead_ts    = strtotime( $cead_fecha );

/*
 * Sin hora cargada, la fecha llega como 00:00. Escribir «00:00» sería inventar
 * una precisión que nadie dio: un acto «a las cero horas» no existe. Si es
 * medianoche clavada se lo trata como jornada completa y no se imprime hora.
 */
$cead_con_hora = $cead_ts && ( (int) wp_date( 'Hi', $cead_ts ) !== 0 );
$cead_cuando   = cead_nota_cuando( $cead_ts );
$cead_pasado   = $cead_ts && $cead_ts < (int) current_time( 'timestamp' );

$cead_links = function_exists( 'cead_site_links' ) ? cead_site_links() : [];
$cead_cal   = $cead_links['calendario']['url'] ?? '';
?>
<article <?php post_class( 'cead-article cead-article--evento' ); ?>>

	<header class="cead-evt-ficha<?php echo $cead_pasado ? ' is-pasado' : ''; ?>">
		<div class="cead-evt-fecha" aria-hidden="true">
			<span class="cead-evt-dia"><?php echo esc_html( wp_date( 'j', $cead_ts ) ); ?></span>
			<span class="cead-evt-mes"><?php echo esc_html( wp_date( 'M', $cead_ts ) ); ?></span>
			<span class="cead-evt-anio"><?php echo esc_html( wp_date( 'Y', $cead_ts ) ); ?></span>
		</div>

		<div class="cead-evt-datos">
			<span class="cead-evt-eyebrow"><?php esc_html_e( 'Evento', 'cead' ); ?></span>
			<h1 class="cead-evt-titulo"><?php the_title(); ?></h1>

			<ul class="cead-evt-lineas">
				<li>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
						<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
					</svg>
					<span>
						<?php
						// La fecha completa en texto: el bloque grande de la izquierda
						// es decorativo (aria-hidden) y un lector de pantalla necesita
						// leer el día acá.
						echo esc_html( ucfirst( wp_date( 'l j \d\e F \d\e Y', $cead_ts ) ) );
						echo $cead_con_hora ? esc_html( ' · ' . wp_date( 'H:i', $cead_ts ) . ' h' ) : '';
						?>
					</span>
				</li>
				<?php if ( $cead_lugar ) : ?>
					<li>
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
							<path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/>
						</svg>
						<span><?php echo esc_html( $cead_lugar ); ?></span>
					</li>
				<?php endif; ?>
			</ul>

			<?php if ( $cead_cuando ) : ?>
				<span class="cead-evt-cuenta"><?php echo esc_html( $cead_cuando ); ?></span>
			<?php endif; ?>
		</div>
	</header>

	<?php if ( has_post_thumbnail() ) : ?>
		<figure class="cead-article-thumb">
			<?php the_post_thumbnail( 'large' ); ?>
		</figure>
	<?php endif; ?>

	<div class="cead-article-content"><?php the_content(); ?></div>

	<?php if ( $cead_cal ) : ?>
		<p class="cead-evt-cal">
			<a href="<?php echo esc_url( $cead_cal ); ?>"><?php esc_html_e( 'Ver el calendario del colegio', 'cead' ); ?> →</a>
		</p>
	<?php endif; ?>

	<?php get_template_part( 'template-parts/nota/pie' ); ?>

</article>
