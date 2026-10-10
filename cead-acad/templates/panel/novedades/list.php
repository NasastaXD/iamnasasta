<?php
/**
 * /panel/novedades: lo nuevo del panel y de la app, explicado en simple.
 *
 * El contenido sale de includes/changelog-data.php: ver ahí cómo se escribe.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$uid     = get_current_user_id();
$mirada  = Cead_Acad_Changelog::mirada_de( $uid );
$entradas = Cead_Acad_Changelog::para( $mirada );

// Se lee ANTES de anotar que la persona la vio: lo marcado como «Nuevo» es lo
// que no había visto hasta esta visita, y la próxima vez ya no.
$visto = Cead_Acad_Changelog::visto( $uid );
if ( $entradas ) {
	Cead_Acad_Changelog::marcar_visto( $uid, (string) $entradas[0]['version'] );
}

$page_title = __( 'Novedades', 'cead-acad' );

$body = function () use ( $entradas, $mirada, $visto ) {
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Lo nuevo', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Novedades', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Todo lo que fuimos sumando al panel y a la app del CEAD, explicado en simple.', 'cead-acad' ); ?></p>

		<?php
		cead_acad_template( 'panel/novedades/_lista.php', [
			'entradas' => $entradas,
			'mirada'   => $mirada,
			'visto'    => $visto,
		] );
		?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
