<?php
/**
 * Chequeo estático contra los fallos silenciosos que ya nos mordieron.
 *
 * El caso que motiva esto: el seeder llamaba a `Cead_Acad_Broadcasts_Audiences`,
 * una clase que no existe — la real es `Cead_Acad_Audiences`. Como la llamada
 * estaba envuelta en `class_exists()`, no reventó nada: simplemente el bloque
 * nunca se ejecutó y los comunicados quedaron sin audiencia. El código estaba
 * escrito, la función estaba «hecha», y no se veía en ningún lado.
 *
 * Ese es el patrón peligroso: un guard defensivo convierte un typo en silencio.
 *
 * Qué revisa:
 *
 *  1. Símbolos PROPIOS (Cead_Acad_*) nombrados como string en class_exists(),
 *     method_exists() o llamadas estáticas, que no estén definidos en el repo.
 *     Los símbolos externos (WordPress, PHP, otros plugins) se ignoran solos,
 *     así que no hay lista blanca que mantener.
 *
 *  2. Que la versión del header del plugin y la constante CEAD_ACAD_VERSION
 *     coincidan. Se escriben a mano en dos lugares y el workflow de release lee
 *     el header: si divergen, se publica un release con la versión equivocada.
 *
 * Uso: php bin/check-symbols.php    (sale 1 si encuentra algo)
 */

$raiz = dirname( __DIR__ );
$fallos = [];

/* ------------------------------------------------------------- 1. símbolos */

$archivos = [];
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $raiz . '/cead-acad' ) );
foreach ( $it as $f ) {
	if ( $f->isFile() && 'php' === $f->getExtension() && false === strpos( $f->getPathname(), '/vendor/' ) ) {
		$archivos[] = $f->getPathname();
	}
}

// Todo lo que el repo define.
$definidos = [];
foreach ( $archivos as $a ) {
	$src = file_get_contents( $a );
	if ( preg_match_all( '/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+([A-Za-z0-9_]+)/mi', $src, $m ) ) {
		foreach ( $m[1] as $nombre ) { $definidos[ $nombre ] = true; }
	}
}

// Todo lo que el repo nombra como string y es nuestro.
$referencias = [];
foreach ( $archivos as $a ) {
	$src = file_get_contents( $a );
	$patrones = [
		"/class_exists\(\s*'([A-Za-z0-9_]+)'/",
		"/method_exists\(\s*'([A-Za-z0-9_]+)'/",
		"/is_callable\(\s*\[\s*'([A-Za-z0-9_]+)'/",
	];
	foreach ( $patrones as $p ) {
		if ( preg_match_all( $p, $src, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $hit ) {
				$nombre = $hit[0];
				if ( 0 !== strpos( $nombre, 'Cead_Acad_' ) ) { continue; } // externo: no es asunto nuestro
				$linea = substr_count( substr( $src, 0, $hit[1] ), "\n" ) + 1;
				$referencias[] = [ $nombre, str_replace( $raiz . '/', '', $a ), $linea ];
			}
		}
	}
}

foreach ( $referencias as [ $nombre, $archivo, $linea ] ) {
	if ( ! isset( $definidos[ $nombre ] ) ) {
		$fallos[] = "{$archivo}:{$linea}  clase inexistente en un guard: {$nombre}\n"
			. "    Un class_exists() sobre un nombre mal escrito no falla: se saltea el bloque en silencio.";
	}
}

/* -------------------------------------------------------------- 2. versión */

$principal = file_get_contents( $raiz . '/cead-acad/cead-acad.php' );
preg_match( '/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)/m', $principal, $mh );
preg_match( "/define\(\s*'CEAD_ACAD_VERSION',\s*'([^']+)'/", $principal, $mc );
$header = $mh[1] ?? '';
$const  = $mc[1] ?? '';
if ( '' === $header || '' === $const ) {
	$fallos[] = 'cead-acad/cead-acad.php  no pude leer la versión del header o de la constante.';
} elseif ( $header !== $const ) {
	$fallos[] = "cead-acad/cead-acad.php  versión desincronizada: header dice {$header} y CEAD_ACAD_VERSION dice {$const}.\n"
		. '    El workflow de release lee el header; la constante es la que ve el código.';
}

/* --------------------------------------------------------------- resultado */

if ( $fallos ) {
	echo "\n✗ Chequeo de coherencia: " . count( $fallos ) . " problema(s)\n\n";
	foreach ( $fallos as $f ) { echo '  ' . $f . "\n\n"; }
	exit( 1 );
}

echo "✓ Chequeo de coherencia: sin problemas (" . count( $referencias ) . " referencias propias verificadas).\n";
exit( 0 );
