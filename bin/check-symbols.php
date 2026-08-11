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

/* ------------------------------------------------- 3. anclas del tema rotas */

/*
 * Los identificadores de fragmento DISTINGUEN MAYÚSCULAS. Los dos botones del
 * hero apuntaban a `#Creemos` y `#Admision` mientras los `id` reales eran
 * `creemos` y `admision`: el camino principal de la portada no llevaba a ningún
 * lado, y no había forma de notarlo salvo haciendo clic.
 *
 * Se juntan todos los `id` que el tema emite y todos los `#destino` que
 * escribe, y se avisa cuando un destino no existe — o existe pero con otra
 * caja, que es el caso traicionero.
 */
$tema = [];
$itT = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $raiz . '/cead' ) );
foreach ( $itT as $f ) {
	if ( $f->isFile() && 'php' === $f->getExtension() && false === strpos( $f->getPathname(), '/vendor/' ) ) {
		$tema[] = $f->getPathname();
	}
}

$ids = [];
foreach ( $tema as $a ) {
	if ( preg_match_all( '/\bid=["\']([A-Za-z][A-Za-z0-9_\-]*)["\']/', file_get_contents( $a ), $m ) ) {
		foreach ( $m[1] as $id ) { $ids[ $id ] = true; }
	}
}

/*
 * Tres formas de escribir un destino en este tema:
 *   href="#algo"                              — en el marcado
 *   home_url('/#algo')                        — enlaces absolutos a la portada
 *   get_theme_mod('..._url', '#algo')         — el default de un ajuste
 *
 * La tercera es la que importaba: los botones del hero son exactamente eso, y
 * por no mirarla el bug original se habría escapado igual.
 */
$patrones_ancla = [
	'/(?:href=["\']|home_url\(\s*["\']\/)#([A-Za-z][A-Za-z0-9_\-]*)/',
	'/_url["\']\s*,\s*["\']#([A-Za-z][A-Za-z0-9_\-]*)["\']/',
];

foreach ( $tema as $a ) {
	$src   = file_get_contents( $a );
	$hits  = [];
	foreach ( $patrones_ancla as $p ) {
		if ( preg_match_all( $p, $src, $m, PREG_OFFSET_CAPTURE ) ) {
			$hits = array_merge( $hits, $m[1] );
		}
	}
	foreach ( $hits as $hit ) {
		$destino = $hit[0];
		if ( isset( $ids[ $destino ] ) ) { continue; }
		// Un color hexadecimal no es un ancla.
		if ( preg_match( '/^(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $destino ) ) { continue; }

		$linea  = substr_count( substr( $src, 0, $hit[1] ), "\n" ) + 1;
		$archivo = str_replace( $raiz . '/', '', $a );

		// ¿Existe con otra caja? Ese es el error de verdad.
		$parecido = '';
		foreach ( array_keys( $ids ) as $id ) {
			if ( 0 === strcasecmp( $id, $destino ) ) { $parecido = $id; break; }
		}
		if ( '' !== $parecido ) {
			$fallos[] = "{$archivo}:{$linea}  ancla con la caja equivocada: #{$destino} — el id real es \"{$parecido}\".\n"
				. '    Los fragmentos distinguen mayúsculas: el enlace no lleva a ningún lado.';
		} else {
			$fallos[] = "{$archivo}:{$linea}  ancla a un id que no existe: #{$destino}.";
		}
	}
}

/* --------------------------------------- 4. números y versiones en los docs */

/*
 * La web y la documentación afirman números sobre el sistema. Afirmarlos es
 * fácil; acordarse de actualizarlos un año después, no: el resumen ejecutivo
 * llegó a decir 17 módulos cuando ya eran 18, y 6 tipos de contenido cuando ya
 * eran 7. Acá se comparan contra la realidad del repo.
 */
$reales = [
	'modulos' => count( glob( $raiz . '/cead-acad/modules/*', GLOB_ONLYDIR ) ),
	'roles'   => preg_match_all(
		"/'cead_acad_[a-z_]+'\s*=>\s*\[\s*\n\s*'display'/",
		file_get_contents( $raiz . '/cead-acad/includes/class-cead-acad-capabilities.php' )
	),
	'cpts'    => count( array_unique( array_merge( ...array_map(
		static function ( $a ) {
			preg_match_all( "/const POST_TYPE\s*=\s*'([^']+)'/", file_get_contents( $a ), $m );
			return $m[1];
		},
		$archivos
	) ) ) ),
];
preg_match(
	'/\$allowed\s*=\s*\[(.*?)\];/s',
	file_get_contents( $raiz . '/cead-acad/includes/helpers.php' ),
	$mt
);
$reales['tablas'] = isset( $mt[1] ) ? substr_count( $mt[1], "'" ) / 2 : 0;

$afirmados = [];
$helpers_tema = file_get_contents( $raiz . '/cead/inc/helpers.php' );
if ( preg_match( '/function cead_proyecto_numeros\(\).*?\n}/s', $helpers_tema, $mf ) ) {
	preg_match_all( "/'([a-z]+)'\s*=>\s*\[\s*(\d+)/", $mf[0], $mn, PREG_SET_ORDER );
	foreach ( $mn as $par ) { $afirmados[ $par[1] ] = (int) $par[2]; }
}

foreach ( $afirmados as $clave => $dice ) {
	if ( ! isset( $reales[ $clave ] ) ) { continue; }
	if ( $dice !== $reales[ $clave ] ) {
		$fallos[] = "cead/inc/helpers.php  cead_proyecto_numeros() afirma {$dice} {$clave}, pero hay {$reales[$clave]}.\n"
			. '    Ese número se publica en /proyecto. Actualizalo (o revisá si el cambio era intencional).';
	}
}

// Versiones escritas a mano en los .md, que se quedan viejas y contradicen a la
// página que las muestra. La wiki llegó a decir 0.44.1 con el pie en 0.59.0.
foreach ( glob( $raiz . '/cead-acad/docs/*.md' ) as $doc ) {
	$src = file_get_contents( $doc );
	if ( preg_match( '/(?:Versión del plugin|Plugin)\D{0,4}v?\*{0,2}([0-9]+\.[0-9]+\.[0-9]+)/u', $src, $mv ) ) {
		if ( $mv[1] !== $const ) {
			$fallos[] = str_replace( $raiz . '/', '', $doc ) . "  dice versión {$mv[1]}, pero el plugin va por {$const}.\n"
				. '    No escribas la versión a mano: /wiki ya la imprime desde CEAD_ACAD_VERSION.';
		}
	}
}

/* --------------------------------------------------------------- resultado */

if ( $fallos ) {
	echo "\n✗ Chequeo de coherencia: " . count( $fallos ) . " problema(s)\n\n";
	foreach ( $fallos as $f ) { echo '  ' . $f . "\n\n"; }
	exit( 1 );
}

echo "✓ Chequeo de coherencia: sin problemas (" . count( $referencias ) . " referencias propias verificadas).\n";
exit( 0 );
