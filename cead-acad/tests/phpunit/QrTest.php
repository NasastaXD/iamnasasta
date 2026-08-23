<?php
/**
 * El QR del carné se dibuja en el servidor del colegio.
 *
 * Antes lo generaba `api.qrserver.com` recibiendo la URL de verificación —con
 * el token firmado adentro— en el query string. Eso le regalaba a un tercero
 * una credencial permanente de cada alumno que abriera su carné, y dejaba la
 * tarjeta rota cuando ese servicio no respondía.
 *
 * Un QR mal armado no avisa: se ve exactamente igual que uno bueno, cuadrados
 * negros y blancos, y el problema aparece recién cuando alguien intenta
 * escanearlo en la puerta. Por eso los tests no miran «que devuelva algo» sino
 * que DECODIFICAN lo generado y verifican que vuelva el texto original.
 *
 * Las matrices congeladas se verificaron contra un decodificador independiente
 * (OpenCV) antes de fijarlas: 71 casos entre las versiones 1 a 10 y los cuatro
 * niveles de corrección, todos legibles.
 */

use PHPUnit\Framework\TestCase;

final class QrTest extends TestCase {

	/* ------------------------- lo que se ve en pantalla -------------------- */

	/**
	 * La razón de ser de toda esta clase: el SVG no puede pedirle nada a nadie.
	 *
	 * Si alguien «simplifica» esto volviendo a un servicio externo, el carné va
	 * a seguir viéndose perfecto y el token va a volver a salir del colegio sin
	 * que nadie lo note.
	 */
	public function test_el_svg_no_sale_a_internet(): void {
		$svg = Cead_Acad_QR::svg( 'https://cead.edu.py/carne/123-a1b2c3d4e5f60718' );

		$this->assertStringStartsWith( '<svg', $svg );

		// Nada que traiga algo de afuera. El navegador solo busca por estos
		// atributos, así que sin ellos no hay pedido posible.
		$this->assertDoesNotMatchRegularExpression( '/\b(src|href|xlink:href)\s*=/i', $svg );

		// Y ninguna URL suelta. Se descuenta el `xmlns`, que es un identificador
		// del formato SVG y no una dirección que el navegador visite.
		$sinNamespace = str_replace( 'http://www.w3.org/2000/svg', '', $svg );
		$this->assertStringNotContainsString( 'http', $sinNamespace );
	}

	/** El token tampoco puede aparecer escrito dentro del SVG. */
	public function test_el_svg_no_filtra_el_token(): void {
		$svg = Cead_Acad_QR::svg( 'https://cead.edu.py/carne/123-a1b2c3d4e5f60718' );

		$this->assertStringNotContainsString( 'a1b2c3d4e5f60718', $svg );
	}

	public function test_el_svg_respeta_el_borde_pedido(): void {
		$svg = Cead_Acad_QR::svg( 'A', [ 'border' => 4 ] );

		// Versión 1 son 21 módulos; con borde 4 de cada lado, 29.
		$this->assertStringContainsString( 'viewBox="0 0 29 29"', $svg );
	}

	/** Un texto que no entra no puede romper la página: devuelve vacío. */
	public function test_un_texto_gigante_devuelve_vacio_y_no_explota(): void {
		$this->assertSame( '', Cead_Acad_QR::svg( str_repeat( 'x', 5000 ) ) );
	}

	/* ---------------------------- que se pueda leer ------------------------ */

	/**
	 * Ida y vuelta completa: se codifica, se desenmascara, se leen los bits en
	 * zigzag y tiene que volver el texto original.
	 *
	 * Se usan casos de un solo bloque de datos para poder decodificar sin
	 * depender de la tabla de bloques de la propia clase — si el test usara esa
	 * tabla para leer lo que esa tabla escribió, un error en la tabla pasaría
	 * los tests igual.
	 *
	 * @dataProvider casosDeUnSoloBloque
	 */
	public function test_lo_generado_se_vuelve_a_leer( string $texto, int $ecl ): void {
		$m = Cead_Acad_QR::matrix( $texto, $ecl );

		$this->assertSame( $texto, self::decodificar( $m ) );
	}

	/**
	 * Solo casos que caen en versiones de UN bloque de datos (v1 y v2 en
	 * cualquier nivel; v3 en L y M). Una URL de carné en Q o H se parte en dos
	 * bloques intercalados y este decodificador chico no los sabe rearmar —
	 * esos casos los cubren las matrices congeladas.
	 */
	public static function casosDeUnSoloBloque(): array {
		return [
			'una letra'          => [ 'A', Cead_Acad_QR::ECL_L ],
			'texto corto'        => [ 'HOLA CEAD', Cead_Acad_QR::ECL_L ],
			'texto corto (M)'    => [ 'HOLA CEAD', Cead_Acad_QR::ECL_M ],
			'texto corto (H)'    => [ 'HOLA CEAD', Cead_Acad_QR::ECL_H ],
			'url de carné'       => [ 'https://cead.edu.py/carne/123-a1b2c3d4e5f60718', Cead_Acad_QR::ECL_L ],
			'otra url de carné'  => [ 'https://cead.edu.py/carne/1-0000000000000000', Cead_Acad_QR::ECL_L ],
			'con ñ y acentos'    => [ 'Ñandutí, Caaguazú', Cead_Acad_QR::ECL_L ],
			'exactamente 1 byte' => [ 'x', Cead_Acad_QR::ECL_M ],
		];
	}

	/**
	 * Las matrices exactas de casos ya verificados con un lector real.
	 *
	 * Congelarlas es lo que convierte a esta clase en algo mantenible: cualquier
	 * retoque en la codificación, el enmascarado o las tablas cambia el hash y
	 * salta acá, en vez de descubrirse con un alumno en la puerta.
	 *
	 * @dataProvider matricesCongeladas
	 */
	public function test_la_matriz_no_cambia_sin_aviso( string $texto, int $ecl, int $lado, string $hash ): void {
		$m = Cead_Acad_QR::matrix( $texto, $ecl );

		$this->assertCount( $lado, $m, 'Cambió la versión elegida para este texto.' );

		$plano = implode( '', array_map( static fn( $f ) => implode( '', $f ), $m ) );
		$this->assertSame( $hash, substr( hash( 'sha256', $plano ), 0, 16 ) );
	}

	public static function matricesCongeladas(): array {
		return [
			'url de carné'   => [ 'https://cead.edu.py/carne/123-a1b2c3d4e5f60718', Cead_Acad_QR::ECL_M, 33, '7d46e142ba1733ea' ],
			'una letra'      => [ 'A', Cead_Acad_QR::ECL_L, 21, 'c70a942b285013ce' ],
			'texto corto'    => [ 'HOLA CEAD', Cead_Acad_QR::ECL_H, 25, 'c04620d4d8322218' ],
			'120 caracteres' => [ str_repeat( 'x', 120 ), Cead_Acad_QR::ECL_Q, 53, 'fd2a7e24e87b6c41' ],
			'con ñ'          => [ 'Ñandutí, Caaguazú', Cead_Acad_QR::ECL_M, 25, '31bceb3b85e91a24' ],
		];
	}

	/* --------------------------- la estructura fija ------------------------ */

	/** Los tres localizadores tienen que estar donde un lector los busca. */
	public function test_estan_los_tres_localizadores(): void {
		$m = Cead_Acad_QR::matrix( 'https://cead.edu.py/carne/123-a1b2c3d4e5f60718' );
		$n = count( $m );

		foreach ( [ [ 0, 0 ], [ 0, $n - 7 ], [ $n - 7, 0 ] ] as [ $y0, $x0 ] ) {
			for ( $y = 0; $y < 7; $y++ ) {
				for ( $x = 0; $x < 7; $x++ ) {
					$borde   = ( 0 === $x || 6 === $x || 0 === $y || 6 === $y );
					$centro  = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;
					$esperado = ( $borde || $centro ) ? 1 : 0;
					$this->assertSame( $esperado, $m[ $y0 + $y ][ $x0 + $x ], "Localizador roto en ($x0,$y0)." );
				}
			}
		}
	}

	/** Los temporizadores alternan, y el módulo oscuro fijo está prendido. */
	public function test_temporizadores_y_modulo_oscuro(): void {
		$m = Cead_Acad_QR::matrix( 'https://cead.edu.py/carne/123-a1b2c3d4e5f60718' );
		$n = count( $m );

		for ( $i = 8; $i < $n - 8; $i++ ) {
			$esperado = ( 0 === $i % 2 ) ? 1 : 0;
			$this->assertSame( $esperado, $m[6][ $i ], "Temporizador horizontal en $i." );
			$this->assertSame( $esperado, $m[ $i ][6], "Temporizador vertical en $i." );
		}

		$this->assertSame( 1, $m[ $n - 8 ][8], 'Falta el módulo oscuro fijo.' );
	}

	/**
	 * La info de formato tiene que anunciar el nivel de corrección REAL. Si
	 * anuncia otro, el lector intenta corregir con el polinomio equivocado y no
	 * lee nada, aunque los datos estén perfectos.
	 *
	 * @dataProvider nivelesDeCorreccion
	 */
	public function test_el_formato_anuncia_el_nivel_correcto( int $ecl, int $bitsEsperados ): void {
		$m = Cead_Acad_QR::matrix( 'https://cead.edu.py/carne/123-a1b2c3d4e5f60718', $ecl );

		$this->assertSame( $bitsEsperados, self::bitsDeNivel( $m ) );
	}

	public static function nivelesDeCorreccion(): array {
		return [
			'L' => [ Cead_Acad_QR::ECL_L, 0b01 ],
			'M' => [ Cead_Acad_QR::ECL_M, 0b00 ],
			'Q' => [ Cead_Acad_QR::ECL_Q, 0b11 ],
			'H' => [ Cead_Acad_QR::ECL_H, 0b10 ],
		];
	}

	/** Las dos copias de la info de formato tienen que decir lo mismo. */
	public function test_las_dos_copias_del_formato_coinciden(): void {
		$m = Cead_Acad_QR::matrix( 'https://cead.edu.py/carne/123-a1b2c3d4e5f60718' );
		$n = count( $m );

		for ( $i = 0; $i < 15; $i++ ) {
			if ( $i < 6 )       { $a = $m[ $i ][8]; }
			elseif ( 6 === $i ) { $a = $m[7][8]; }
			elseif ( 7 === $i ) { $a = $m[8][8]; }
			elseif ( 8 === $i ) { $a = $m[8][7]; }
			else                { $a = $m[8][ 14 - $i ]; }

			$b = ( $i < 8 ) ? $m[8][ $n - 1 - $i ] : $m[ $n - 15 + $i ][8];

			$this->assertSame( $a, $b, "El bit $i del formato difiere entre las dos copias." );
		}
	}

	/** A más texto, versión más grande; y el mismo texto no encoge. */
	public function test_la_version_crece_con_el_texto(): void {
		$chico  = count( Cead_Acad_QR::matrix( 'A' ) );
		$medio  = count( Cead_Acad_QR::matrix( str_repeat( 'x', 60 ) ) );
		$grande = count( Cead_Acad_QR::matrix( str_repeat( 'x', 180 ) ) );

		$this->assertLessThan( $medio, $chico );
		$this->assertLessThan( $grande, $medio );
	}

	/**
	 * Más corrección de errores deja menos lugar para datos, así que el mismo
	 * texto necesita una versión igual o más grande. Al revés sería señal de que
	 * la tabla de bloques está cruzada.
	 */
	public function test_mas_correccion_nunca_achica_el_codigo(): void {
		$texto = 'https://cead.edu.py/carne/123-a1b2c3d4e5f60718';

		$l = count( Cead_Acad_QR::matrix( $texto, Cead_Acad_QR::ECL_L ) );
		$m = count( Cead_Acad_QR::matrix( $texto, Cead_Acad_QR::ECL_M ) );
		$q = count( Cead_Acad_QR::matrix( $texto, Cead_Acad_QR::ECL_Q ) );
		$h = count( Cead_Acad_QR::matrix( $texto, Cead_Acad_QR::ECL_H ) );

		$this->assertLessThanOrEqual( $m, $l );
		$this->assertLessThanOrEqual( $q, $m );
		$this->assertLessThanOrEqual( $h, $q );
	}

	/* ------------------------------ herramientas --------------------------- */

	/** Los dos bits de nivel que anuncia la info de formato. */
	private static function bitsDeNivel( array $m ): int {
		$f = [];
		for ( $i = 0; $i < 6; $i++ ) { $f[ $i ] = $m[ $i ][8]; }
		$f[6] = $m[7][8];
		$f[7] = $m[8][8];
		$f[8] = $m[8][7];
		for ( $i = 9; $i < 15; $i++ ) { $f[ $i ] = $m[8][ 14 - $i ]; }

		$v = 0;
		for ( $i = 0; $i < 15; $i++ ) { $v |= ( $f[ $i ] << $i ); }
		$v ^= 0b101010000010010;

		return ( $v >> 13 ) & 0b11;
	}

	/**
	 * Decodificador mínimo, escrito aparte a propósito.
	 *
	 * No corrige errores ni de-intercala bloques: alcanza para los casos de un
	 * solo bloque, que es justamente donde puede leer sin pedirle a la clase
	 * bajo test que le explique cómo repartió los datos.
	 */
	private static function decodificar( array $m ): string {
		$n = count( $m );

		// 1. Qué módulos son estructura y cuáles datos.
		$res = self::mapaReservado( $n );

		// 2. Sacar la máscara del formato y deshacerla.
		$mask = self::mascaraDelFormato( $m );
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				if ( ! $res[ $y ][ $x ] && self::mascara( $mask, $x, $y ) ) {
					$m[ $y ][ $x ] ^= 1;
				}
			}
		}

		// 3. Leer los bits en zigzag.
		$bits   = '';
		$col    = $n - 1;
		$arriba = true;
		while ( $col > 0 ) {
			if ( 6 === $col ) { $col--; }
			for ( $k = 0; $k < $n; $k++ ) {
				$y = $arriba ? ( $n - 1 - $k ) : $k;
				foreach ( [ $col, $col - 1 ] as $x ) {
					if ( ! $res[ $y ][ $x ] ) {
						$bits .= $m[ $y ][ $x ];
					}
				}
			}
			$col   -= 2;
			$arriba = ! $arriba;
		}

		// 4. Modo (4 bits) + longitud (8 bits hasta la versión 9) + bytes.
		$version = ( $n - 17 ) / 4;
		$modo    = bindec( substr( $bits, 0, 4 ) );
		if ( 0b0100 !== $modo ) {
			return '(modo inesperado: ' . $modo . ')';
		}
		$anchoLargo = ( $version >= 10 ) ? 16 : 8;
		$largo      = bindec( substr( $bits, 4, $anchoLargo ) );

		$texto = '';
		$pos   = 4 + $anchoLargo;
		for ( $i = 0; $i < $largo; $i++ ) {
			$texto .= chr( bindec( substr( $bits, $pos, 8 ) ) );
			$pos   += 8;
		}
		return $texto;
	}

	/** Los centros de alineación de las versiones que se usan acá. */
	private static function centrosAlineacion( int $version ): array {
		$tabla = [
			1 => [], 2 => [ 6, 18 ], 3 => [ 6, 22 ], 4 => [ 6, 26 ], 5 => [ 6, 30 ],
			6 => [ 6, 34 ], 7 => [ 6, 22, 38 ], 8 => [ 6, 24, 42 ], 9 => [ 6, 26, 46 ],
			10 => [ 6, 28, 50 ],
		];
		return $tabla[ $version ] ?? [];
	}

	private static function mapaReservado( int $n ): array {
		$r = array_fill( 0, $n, array_fill( 0, $n, false ) );
		$marcar = static function ( &$r, $x0, $y0, $w, $h ) use ( $n ) {
			for ( $y = $y0; $y < $y0 + $h; $y++ ) {
				for ( $x = $x0; $x < $x0 + $w; $x++ ) {
					if ( $x >= 0 && $y >= 0 && $x < $n && $y < $n ) { $r[ $y ][ $x ] = true; }
				}
			}
		};

		$marcar( $r, 0, 0, 9, 9 );
		$marcar( $r, $n - 8, 0, 8, 9 );
		$marcar( $r, 0, $n - 8, 9, 8 );
		$marcar( $r, 8, 6, $n - 16, 1 );
		$marcar( $r, 6, 8, 1, $n - 16 );

		$version = (int) ( ( $n - 17 ) / 4 );
		$centros = self::centrosAlineacion( $version );
		$ultimo  = count( $centros ) - 1;
		foreach ( $centros as $i => $cy ) {
			foreach ( $centros as $j => $cx ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $j === $ultimo ) || ( $i === $ultimo && 0 === $j ) ) {
					continue;
				}
				$marcar( $r, $cx - 2, $cy - 2, 5, 5 );
			}
		}

		if ( $version >= 7 ) {
			$marcar( $r, 0, $n - 11, 6, 3 );
			$marcar( $r, $n - 11, 0, 3, 6 );
		}

		return $r;
	}

	private static function mascaraDelFormato( array $m ): int {
		$f = [];
		for ( $i = 0; $i < 6; $i++ ) { $f[ $i ] = $m[ $i ][8]; }
		$f[6] = $m[7][8];
		$f[7] = $m[8][8];
		$f[8] = $m[8][7];
		for ( $i = 9; $i < 15; $i++ ) { $f[ $i ] = $m[8][ 14 - $i ]; }

		$v = 0;
		for ( $i = 0; $i < 15; $i++ ) { $v |= ( $f[ $i ] << $i ); }
		$v ^= 0b101010000010010;

		return ( $v >> 10 ) & 0b111;
	}

	private static function mascara( int $mask, int $x, int $y ): bool {
		switch ( $mask ) {
			case 0: return 0 === ( $y + $x ) % 2;
			case 1: return 0 === $y % 2;
			case 2: return 0 === $x % 3;
			case 3: return 0 === ( $y + $x ) % 3;
			case 4: return 0 === ( intdiv( $y, 2 ) + intdiv( $x, 3 ) ) % 2;
			case 5: return 0 === ( ( $y * $x ) % 2 + ( $y * $x ) % 3 );
			case 6: return 0 === ( ( $y * $x ) % 2 + ( $y * $x ) % 3 ) % 2;
			case 7: return 0 === ( ( $y + $x ) % 2 + ( $y * $x ) % 3 ) % 2;
		}
		return false;
	}
}
