<?php
/**
 * Saca los textos del código a un Markdown editable, y los vuelve a meter.
 *
 * Existe porque escribir la copia dentro de las plantillas está bien para
 * programar pero es pésimo para revisarla: hay que abrir doce archivos y leer
 * entre etiquetas. Con esto la copia se lee de corrido, se corrige en un solo
 * lugar, y vuelve a su sitio exacto.
 *
 * El ID de cada texto es `clave#N`, donde N es la posición de esa cadena
 * traducible dentro de su archivo. Es determinista: no hay que guardar ningún
 * mapa aparte, y mientras no se agreguen ni saquen cadenas del archivo, el ID
 * sigue apuntando a lo mismo.
 *
 *   php bin/textos.php extraer  > TEXTOS.md
 *   php bin/textos.php aplicar TEXTOS.md
 *
 * `aplicar` no toca los textos que no cambiaron, así que se puede correr
 * cuantas veces haga falta.
 */

$raiz = dirname( __DIR__ );

/** Archivos cuya copia se maneja por acá: clave => ruta. */
function cead_textos_mapa() {
	return [
		'hero'         => 'cead/template-parts/proyecto/hero.php',
		'problema'     => 'cead/template-parts/proyecto/problema.php',
		'caras'        => 'cead/template-parts/proyecto/caras.php',
		'panel'        => 'cead/template-parts/proyecto/panel.php',
		'ceadi'        => 'cead/template-parts/proyecto/ceadi.php',
		'arquitectura' => 'cead/template-parts/proyecto/arquitectura.php',
		'numeros'      => 'cead/template-parts/proyecto/numeros.php',
		'seguridad'    => 'cead/template-parts/proyecto/seguridad.php',
		'cierre'       => 'cead/template-parts/proyecto/cierre.php',
		'organigrama'  => 'cead/template-parts/proyecto/svg/organigrama.php',
		'pantalla'     => 'cead/template-parts/proyecto/svg/pantalla.php',
		'helpers'      => 'cead/inc/helpers.php',
		'nav'          => 'cead/template-parts/nav.php',
		'footer'       => 'cead/footer.php',
		'logout'       => 'cead-acad/templates/auth/logout-confirm.php',
		'shell'        => 'cead-acad/templates/panel/shell.php',
		'cursos'       => 'cead-acad/modules/courses/class-courses-admin.php',
	];
}

/** Las funciones de i18n cuyo primer argumento es texto para la gente. */
const CEAD_TEXTOS_PATRON = '/\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|_e|__)\(\s*([\'"])(.*?)(?<!\\\\)\1/su';

/**
 * Cadenas traducibles de un archivo, en orden de aparición.
 *
 * @return array<int,array{texto:string,inicio:int,largo:int,comilla:string}>
 */
function cead_textos_leer( $ruta ) {
	$src = (string) file_get_contents( $ruta );
	preg_match_all( CEAD_TEXTOS_PATRON, $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );

	$out = [];
	foreach ( $m as $i => $hit ) {
		$out[ $i + 1 ] = [
			'texto'   => $hit[2][0],
			'inicio'  => $hit[2][1],
			'largo'   => strlen( $hit[2][0] ),
			'comilla' => $hit[1][0],
		];
	}
	return $out;
}

/** De cómo se escribe en PHP a cómo se lee en el Markdown. */
function cead_textos_desescapar( $s, $comilla ) {
	return "'" === $comilla
		? str_replace( [ "\\'", '\\\\' ], [ "'", '\\' ], $s )
		: stripcslashes( $s );
}

/** Y al revés. */
function cead_textos_escapar( $s, $comilla ) {
	return "'" === $comilla
		? str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $s )
		: addcslashes( $s, "\"\\\$" );
}

/* ------------------------------------------------------------------ extraer */

if ( 'extraer' === ( $argv[1] ?? '' ) ) {
	foreach ( cead_textos_mapa() as $clave => $rel ) {
		$ruta = $raiz . '/' . $rel;
		if ( ! is_readable( $ruta ) ) { continue; }
		echo "\n## {$rel}\n\n";
		foreach ( cead_textos_leer( $ruta ) as $n => $c ) {
			echo "### [{$clave}#{$n}]\n" . cead_textos_desescapar( $c['texto'], $c['comilla'] ) . "\n\n";
		}
	}
	exit( 0 );
}

/* ------------------------------------------------------------------ aplicar */

if ( 'aplicar' === ( $argv[1] ?? '' ) ) {
	$doc = $argv[2] ?? '';
	if ( ! $doc || ! is_readable( $doc ) ) {
		fwrite( STDERR, "Uso: php bin/textos.php aplicar <archivo.md>\n" );
		exit( 1 );
	}

	// Parsear: cada `### [clave#N]` seguido del texto hasta el próximo encabezado.
	$lineas  = explode( "\n", (string) file_get_contents( $doc ) );
	$nuevos  = [];   // clave => [n => texto]
	$actual  = null;
	$buffer  = [];

	$cerrar = static function () use ( &$actual, &$buffer, &$nuevos ) {
		if ( null === $actual ) { return; }
		[ $clave, $n ] = $actual;
		$nuevos[ $clave ][ $n ] = rtrim( implode( "\n", $buffer ) );
		$actual = null;
		$buffer = [];
	};

	foreach ( $lineas as $l ) {
		if ( preg_match( '/^###\s*\[([a-z]+)#(\d+)\]\s*$/', $l, $m ) ) {
			$cerrar();
			$actual = [ $m[1], (int) $m[2] ];
			continue;
		}
		// Cualquier otro encabezado o separador corta el bloque en curso.
		if ( preg_match( '/^(#{1,3}\s|---\s*$)/', $l ) ) { $cerrar(); continue; }
		if ( null !== $actual ) { $buffer[] = $l; }
	}
	$cerrar();

	$mapa    = cead_textos_mapa();
	$tocados = 0;
	$avisos  = [];

	foreach ( $nuevos as $clave => $items ) {
		if ( ! isset( $mapa[ $clave ] ) ) {
			$avisos[] = "clave desconocida: {$clave}";
			continue;
		}
		$ruta = $raiz . '/' . $mapa[ $clave ];
		$src  = (string) file_get_contents( $ruta );
		$cad  = cead_textos_leer( $ruta );

		// De atrás para adelante: así los offsets de los anteriores siguen valiendo.
		krsort( $items );
		foreach ( $items as $n => $texto ) {
			if ( ! isset( $cad[ $n ] ) ) {
				$avisos[] = "{$clave}#{$n} no existe (el archivo tiene " . count( $cad ) . ' cadenas)';
				continue;
			}
			$viejo = cead_textos_desescapar( $cad[ $n ]['texto'], $cad[ $n ]['comilla'] );
			if ( $texto === $viejo || '' === $texto ) { continue; }

			// Los huecos de datos tienen que sobrevivir a la reescritura.
			preg_match_all( '/%(?:\d+\$)?[sd]/', $viejo, $hv );
			preg_match_all( '/%(?:\d+\$)?[sd]/', $texto, $hn );
			if ( $hv[0] && array_count_values( $hv[0] ) != array_count_values( $hn[0] ) ) {
				$avisos[] = "{$clave}#{$n} perdió o cambió un hueco de datos ("
					. implode( ' ', $hv[0] ) . ' → ' . ( $hn[0] ? implode( ' ', $hn[0] ) : 'ninguno' ) . '). NO se aplicó.';
				continue;
			}

			$src = substr_replace(
				$src,
				cead_textos_escapar( $texto, $cad[ $n ]['comilla'] ),
				$cad[ $n ]['inicio'],
				$cad[ $n ]['largo']
			);
			$tocados++;
		}
		file_put_contents( $ruta, $src );
	}

	foreach ( $avisos as $a ) { echo "  ⚠ {$a}\n"; }
	echo "\n✓ {$tocados} texto(s) actualizado(s)";
	echo $avisos ? ' · ' . count( $avisos ) . " aviso(s)\n" : "\n";
	exit( $avisos ? 1 : 0 );
}

fwrite( STDERR, "Uso:\n  php bin/textos.php extraer  > TEXTOS.md\n  php bin/textos.php aplicar TEXTOS.md\n" );
exit( 1 );
