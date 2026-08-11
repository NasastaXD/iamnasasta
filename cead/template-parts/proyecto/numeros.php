<?php
/**
 * Sección 7 — El tamaño de la cosa.
 *
 * Los números salen de `cead_proyecto_numeros()` (inc/helpers.php), que CI
 * compara contra la realidad en cada push. No se escriben acá a mano.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$numeros = cead_proyecto_numeros();
?>
<section id="numeros" class="proy-section proy-section--numeros">
	<div class="container">
		<header class="proy-head proy-head--centro">
			<p class="reveal eyebrow"><?php esc_html_e( '— En números', 'cead' ); ?></p>
			<h2 class="reveal proy-h2"><?php esc_html_e( 'No es una demo. Está en producción.', 'cead' ); ?></h2>
			<?php
			cead_audio_button(
				__( 'Para dar una idea del tamaño: el sistema tiene dieciocho módulos, dieciocho tablas propias de base de datos, siete roles distintos y siete tipos de contenido. No es una maqueta: es lo que el colegio usa todos los días.', 'cead' ),
				'numeros.mp3'
			);
			?>
		</header>

		<ul class="proy-numeros-grid">
			<?php foreach ( $numeros as $clave => $n ) : ?>
				<li class="reveal proy-numero">
					<?php
					/*
					 * El número va escrito en el HTML, no lo pone el JavaScript:
					 * sin JS o con el conteo desactivado por `prefers-reduced-motion`,
					 * el dato tiene que estar igual. La animación solo lo cuenta
					 * desde cero hasta el valor que ya está acá.
					 */
					?>
					<span class="proy-numero-n" data-contar="<?php echo (int) $n[0]; ?>"><?php echo (int) $n[0]; ?></span>
					<span class="proy-numero-lbl"><?php echo esc_html( $n[1] ); ?></span>
					<span class="proy-numero-txt"><?php echo esc_html( $n[2] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
