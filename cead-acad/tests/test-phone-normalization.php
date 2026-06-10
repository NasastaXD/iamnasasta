<?php
/**
 * Test ejecutable y autónomo (sin WordPress) de Cead_Acad_WA_Identity::normalize_phone().
 * Cubre el fix de seguridad: el match de teléfono debe ser por número normalizado
 * exacto, no por subcadena (evita identidad/permisos equivocados).
 *
 * Uso:  php cead-acad/tests/test-phone-normalization.php
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = null ) { return $d; } }

require_once __DIR__ . '/../modules/whatsapp/class-wa-identity.php';

$fail = 0;
function check( $label, $got, $expected ) {
	global $fail;
	$ok = $got === $expected;
	printf( "%s %s\n", $ok ? '✅' : '❌', $label );
	if ( ! $ok ) { printf( "    esperado: %s\n    obtenido: %s\n", var_export( $expected, true ), var_export( $got, true ) ); $fail++; }
}

$cc = '595';
$N  = fn( $raw ) => Cead_Acad_WA_Identity::normalize_phone( $raw, $cc );

// Equivalencias: distintos formatos del MISMO número → mismo E.164.
check( 'con código país',            $N( '595981123456' ), '595981123456' );
check( 'local con 0',                $N( '0981123456' ),   '595981123456' );
check( 'local sin 0',                $N( '981123456' ),    '595981123456' );
check( 'con +, espacios y guiones',  $N( '+595 981 123-456' ), '595981123456' );
check( 'prefijo internacional 00',   $N( '00595981123456' ), '595981123456' );
check( 'vacío → vacío',              $N( '' ), '' );
check( 'solo símbolos → vacío',      $N( '+-  ' ), '' );

// Los tres formatos del mismo número deben coincidir entre sí (clave del fix).
$a = $N( '595981123456' ); $b = $N( '0981123456' ); $c = $N( '981123456' );
check( 'tres formatos coinciden', ( $a === $b && $b === $c ), true );

// Números DISTINTOS no deben colapsar (no falsos positivos).
check( 'números distintos NO colisionan', $N( '0981123456' ) !== $N( '0981123457' ), true );

// Código de país configurable.
check( 'cc configurable (54)', Cead_Acad_WA_Identity::normalize_phone( '0111234567', '54' ), '54111234567' );

echo $fail === 0 ? "\nTODO OK\n" : "\n{$fail} FALLO(S)\n";
exit( $fail === 0 ? 0 : 1 );
