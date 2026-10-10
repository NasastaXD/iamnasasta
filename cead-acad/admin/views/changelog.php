<?php
/**
 * wp-admin → CEAD Académico → Novedades.
 *
 * Variables: $entradas (ya filtradas para 'admin'), $visto (última versión vista).
 * El contenido sale de includes/changelog-data.php; el dibujo, de la misma
 * plantilla que usa el panel.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap cead-acad-admin-wrap">
	<h1><?php esc_html_e( 'Novedades', 'cead-acad' ); ?></h1>
	<p class="description">
		<?php
		printf(
			/* translators: %s: versión instalada del plugin */
			esc_html__( 'Qué cambió en cada versión de CEAD Académico. Versión instalada: %s.', 'cead-acad' ),
			'<strong>' . esc_html( CEAD_ACAD_VERSION ) . '</strong>'
		);
		?>
		<?php
		printf(
			/* translators: %s: enlace a la página de novedades del panel */
			esc_html__( 'Lo que ve el resto de la gente está en %s; acá además aparecen las notas para quien administra.', 'cead-acad' ),
			'<a href="' . esc_url( cead_acad_url( 'panel/novedades' ) ) . '">' . esc_html( cead_acad_url( 'panel/novedades' ) ) . '</a>'
		);
		?>
	</p>

	<?php
	cead_acad_template( 'panel/novedades/_lista.php', [
		'entradas' => $entradas,
		'mirada'   => 'admin',
		'visto'    => $visto,
	] );
	?>
</div>
