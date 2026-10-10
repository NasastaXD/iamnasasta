<?php
/**
 * Las notas de una versión del plugin, en Markdown, para el Release de GitHub.
 *
 *   php bin/release-notes.php 0.97.0
 *
 * Salen de cead-acad/includes/changelog-data.php, el mismo archivo que alimenta
 * la página «Novedades» del panel y de wp-admin: lo que dice el Release es lo
 * mismo que lee la gente. No imprime nada si esa versión no tiene entrada (el
 * workflow se queda con su texto genérico).
 *
 * El Release es además lo que muestra WordPress en «Ver detalles» al actualizar
 * el plugin, así que quien administra lo lee antes de actualizar.
 */

define( 'ABSPATH', __DIR__ . '/' );

$version = $argv[1] ?? '';
if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
	fwrite( STDERR, "Uso: php bin/release-notes.php X.Y.Z\n" );
	exit( 2 );
}

require dirname( __DIR__ ) . '/cead-acad/includes/class-cead-acad-changelog.php';

echo Cead_Acad_Changelog::notas_markdown( $version );
