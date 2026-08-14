<?php
/**
 * Generador de códigos QR, en PHP puro y sin salir a internet.
 *
 * Existe por el carné. Antes el QR lo dibujaba `api.qrserver.com`, con la URL de
 * verificación metida en el query string. Eso tenía dos problemas, y el segundo
 * es el grave:
 *
 *  1. El carné dependía de que un servicio ajeno estuviera arriba y alcanzable.
 *     Se muestra en la puerta del colegio, con el wifi que haya; si el servicio
 *     no responde, la tarjeta queda con la imagen rota justo donde importa.
 *  2. El token del carné es la ÚNICA prueba de que ese carné es de esa persona:
 *     va firmado con `wp_salt('auth')` y no vence. Mandárselo a un tercero en
 *     una URL significa que ese tercero —y cualquiera que lea sus logs— se queda
 *     con una credencial permanente que abre la ficha del alumno con su nombre,
 *     su foto y su curso. Un dato así no puede salir del servidor del colegio.
 *
 * Alcance a propósito acotado: modo BYTE, versiones 1 a 10, los cuatro niveles
 * de corrección. Con eso entran URLs de sobra (una versión 10 en nivel M lleva
 * ~213 bytes) y las tablas quedan chicas y auditables. No es una librería QR
 * completa y no pretende serlo.
 *
 * Clase pura: no llama a WordPress. Se testea contra matrices de referencia
 * generadas con una implementación independiente (ver `QrTest`).
 *
 * @see ISO/IEC 18004
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_QR {

	const ECL_L = 0;
	const ECL_M = 1;
	const ECL_Q = 2;
	const ECL_H = 3;

	/** Versión máxima soportada. */
	const MAX_VERSION = 10;

	/**
	 * Codewords TOTALES (datos + corrección) por versión. Índice = versión.
	 */
	private const TOTAL_CODEWORDS = [
		1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134,
		6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346,
	];

	/**
	 * Estructura de bloques por versión y nivel:
	 * [ ecPorBloque, bloquesG1, datosG1, bloquesG2, datosG2 ].
	 *
	 * El grupo 2 (cuando existe) lleva exactamente un codeword de datos más que
	 * el grupo 1; así lo define la norma y así lo asume el intercalado.
	 */
	private const BLOCKS = [
		1  => [ [ 7, 1, 19, 0, 0 ], [ 10, 1, 16, 0, 0 ], [ 13, 1, 13, 0, 0 ], [ 17, 1, 9, 0, 0 ] ],
		2  => [ [ 10, 1, 34, 0, 0 ], [ 16, 1, 28, 0, 0 ], [ 22, 1, 22, 0, 0 ], [ 28, 1, 16, 0, 0 ] ],
		3  => [ [ 15, 1, 55, 0, 0 ], [ 26, 1, 44, 0, 0 ], [ 18, 2, 17, 0, 0 ], [ 22, 2, 13, 0, 0 ] ],
		4  => [ [ 20, 1, 80, 0, 0 ], [ 18, 2, 32, 0, 0 ], [ 26, 2, 24, 0, 0 ], [ 16, 4, 9, 0, 0 ] ],
		5  => [ [ 26, 1, 108, 0, 0 ], [ 24, 2, 43, 0, 0 ], [ 18, 2, 15, 2, 16 ], [ 22, 2, 11, 2, 12 ] ],
		6  => [ [ 18, 2, 68, 0, 0 ], [ 16, 4, 27, 0, 0 ], [ 24, 4, 19, 0, 0 ], [ 28, 4, 15, 0, 0 ] ],
		7  => [ [ 20, 2, 78, 0, 0 ], [ 18, 4, 31, 0, 0 ], [ 18, 2, 14, 4, 15 ], [ 26, 4, 13, 1, 14 ] ],
		8  => [ [ 24, 2, 97, 0, 0 ], [ 22, 2, 38, 2, 39 ], [ 22, 4, 18, 2, 19 ], [ 26, 4, 14, 2, 15 ] ],
		9  => [ [ 30, 2, 116, 0, 0 ], [ 22, 3, 36, 2, 37 ], [ 20, 4, 16, 4, 17 ], [ 24, 4, 12, 4, 13 ] ],
		10 => [ [ 18, 2, 68, 2, 69 ], [ 26, 4, 43, 1, 44 ], [ 24, 6, 19, 2, 20 ], [ 28, 6, 15, 2, 16 ] ],
	];

	/** Centros de los patrones de alineación por versión. */
	private const ALIGN = [
		1 => [], 2 => [ 6, 18 ], 3 => [ 6, 22 ], 4 => [ 6, 26 ], 5 => [ 6, 30 ],
		6 => [ 6, 34 ], 7 => [ 6, 22, 38 ], 8 => [ 6, 24, 42 ], 9 => [ 6, 26, 46 ],
		10 => [ 6, 28, 50 ],
	];

	/** Bits de relleno que sobran después del último codeword. */
	private const REMAINDER_BITS = [
		1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7,
		7 => 0, 8 => 0, 9 => 0, 10 => 0,
	];

	/** Los dos bits con que cada nivel se anuncia en la info de formato. */
	private const ECL_BITS = [ self::ECL_L => 0b01, self::ECL_M => 0b00, self::ECL_Q => 0b11, self::ECL_H => 0b10 ];

	/* ===================================================================== */
	/* API                                                                    */
	/* ===================================================================== */

	/**
	 * Devuelve la matriz del QR: array de filas, cada una array de 0/1.
	 *
	 * @param string $data Texto a codificar (se codifica como bytes crudos).
	 * @param int    $ecl  Nivel de corrección (ECL_*).
	 * @return array<int,array<int,int>>
	 * @throws InvalidArgumentException Si el texto no entra en la versión 10.
	 */
	public static function matrix( $data, $ecl = self::ECL_M ) {
		$data = (string) $data;
		$ecl  = isset( self::ECL_BITS[ $ecl ] ) ? (int) $ecl : self::ECL_M;

		$version = self::pick_version( strlen( $data ), $ecl );
		$bits    = self::encode_data( $data, $version, $ecl );
		$final   = self::interleave( $bits, $version, $ecl );

		$size     = 17 + 4 * $version;
		$reservado = self::reserved_map( $version, $size );
		$base      = self::draw_function_patterns( $version, $size );

		self::place_data( $base, $reservado, $final, $size );

		// Se prueban las ocho máscaras y gana la de menor penalización. Elegir a
		// ojo una fija produce QRs que algunos lectores no enganchan.
		$mejor = null;
		$mejor_penal = null;
		for ( $m = 0; $m < 8; $m++ ) {
			$cand = self::apply_mask( $base, $reservado, $m, $size );
			self::draw_format_info( $cand, $ecl, $m, $size );
			if ( $version >= 7 ) {
				self::draw_version_info( $cand, $version, $size );
			}
			$penal = self::penalty( $cand, $size );
			if ( null === $mejor_penal || $penal < $mejor_penal ) {
				$mejor_penal = $penal;
				$mejor       = $cand;
			}
		}

		return $mejor;
	}

	/**
	 * Devuelve el QR como SVG listo para incrustar inline en el HTML.
	 *
	 * Inline y no `<img src="data:...">` a propósito: así escala solo con la
	 * tarjeta, se imprime bien (los navegadores a veces omiten imágenes de
	 * fondo al imprimir, y el carné se imprime) y no agrega un pedido de red.
	 *
	 * @param string $data
	 * @param array  $args border (módulos), class, title, ecl.
	 * @return string SVG, o '' si el texto no se puede codificar.
	 */
	public static function svg( $data, $args = [] ) {
		$args = array_merge( [
			'border' => 2,
			'class'  => '',
			'title'  => '',
			'ecl'    => self::ECL_M,
		], (array) $args );

		try {
			$m = self::matrix( $data, (int) $args['ecl'] );
		} catch ( Exception $e ) {
			return '';
		}

		$n     = count( $m );
		$b     = max( 0, (int) $args['border'] );
		$total = $n + $b * 2;

		// Un solo <path> con todos los módulos oscuros: un rect por módulo son
		// ~1.500 nodos y el navegador lo nota al imprimir.
		$d = '';
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				if ( $m[ $y ][ $x ] ) {
					$d .= 'M' . ( $x + $b ) . ',' . ( $y + $b ) . 'h1v1h-1z';
				}
			}
		}

		$clase  = $args['class'] ? ' class="' . esc_attr( $args['class'] ) . '"' : '';
		$titulo = (string) $args['title'];

		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '"';
		$svg .= ' shape-rendering="crispEdges"' . $clase;
		$svg .= $titulo ? ' role="img" aria-label="' . esc_attr( $titulo ) . '"' : ' role="img" aria-hidden="true"';
		$svg .= '>';
		$svg .= '<rect width="' . $total . '" height="' . $total . '" fill="#fff"/>';
		$svg .= '<path fill="#000" d="' . $d . '"/>';
		$svg .= '</svg>';

		return $svg;
	}

	/* ===================================================================== */
	/* Codificación                                                           */
	/* ===================================================================== */

	/** Codewords de datos disponibles en una versión y nivel. */
	private static function data_codewords( $version, $ecl ) {
		list( $ec, $b1, $d1, $b2, $d2 ) = self::BLOCKS[ $version ][ $ecl ];
		return $b1 * $d1 + $b2 * $d2;
	}

	/** La versión más chica donde entra el texto. */
	private static function pick_version( $len, $ecl ) {
		for ( $v = 1; $v <= self::MAX_VERSION; $v++ ) {
			// 4 bits de modo + contador (8 bits hasta v9, 16 desde v10) + datos.
			$cuenta = ( $v >= 10 ) ? 16 : 8;
			$necesarios = (int) ceil( ( 4 + $cuenta + $len * 8 ) / 8 );
			if ( $necesarios <= self::data_codewords( $v, $ecl ) ) {
				return $v;
			}
		}
		throw new InvalidArgumentException( 'El texto no entra en un QR versión ' . self::MAX_VERSION . '.' );
	}

	/**
	 * Arma el flujo de codewords de datos: modo, longitud, bytes, terminador,
	 * relleno a byte y bytes de relleno alternados.
	 *
	 * @return array<int,int> Codewords (0-255).
	 */
	private static function encode_data( $data, $version, $ecl ) {
		$capacidad = self::data_codewords( $version, $ecl ) * 8;
		$cuenta    = ( $version >= 10 ) ? 16 : 8;

		$bits = '0100';                                          // modo byte
		$bits .= str_pad( decbin( strlen( $data ) ), $cuenta, '0', STR_PAD_LEFT );
		for ( $i = 0, $n = strlen( $data ); $i < $n; $i++ ) {
			$bits .= str_pad( decbin( ord( $data[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}

		// Terminador: hasta cuatro ceros, y solo los que quepan.
		$bits .= str_repeat( '0', min( 4, $capacidad - strlen( $bits ) ) );
		// Completar el byte en curso.
		if ( strlen( $bits ) % 8 ) {
			$bits .= str_repeat( '0', 8 - strlen( $bits ) % 8 );
		}

		$codewords = [];
		for ( $i = 0, $n = strlen( $bits ); $i < $n; $i += 8 ) {
			$codewords[] = bindec( substr( $bits, $i, 8 ) );
		}

		// Relleno alternado 11101100 / 00010001 hasta llenar la capacidad.
		$relleno = [ 0xEC, 0x11 ];
		$k = 0;
		while ( count( $codewords ) < $capacidad / 8 ) {
			$codewords[] = $relleno[ $k % 2 ];
			$k++;
		}

		return $codewords;
	}

	/**
	 * Parte los datos en bloques, calcula la corrección de cada uno y los
	 * intercala como manda la norma.
	 *
	 * @return array<int,int> Codewords finales, en orden de colocación.
	 */
	private static function interleave( $codewords, $version, $ecl ) {
		list( $ec_len, $b1, $d1, $b2, $d2 ) = self::BLOCKS[ $version ][ $ecl ];

		$bloques_datos = [];
		$bloques_ec    = [];
		$pos = 0;

		foreach ( [ [ $b1, $d1 ], [ $b2, $d2 ] ] as $grupo ) {
			list( $cuantos, $largo ) = $grupo;
			for ( $i = 0; $i < $cuantos; $i++ ) {
				$bloque = array_slice( $codewords, $pos, $largo );
				$pos   += $largo;
				$bloques_datos[] = $bloque;
				$bloques_ec[]    = self::reed_solomon( $bloque, $ec_len );
			}
		}

		$salida = [];
		$max_datos = max( $d1, $d2 );
		for ( $i = 0; $i < $max_datos; $i++ ) {
			foreach ( $bloques_datos as $bloque ) {
				if ( isset( $bloque[ $i ] ) ) {
					$salida[] = $bloque[ $i ];
				}
			}
		}
		for ( $i = 0; $i < $ec_len; $i++ ) {
			foreach ( $bloques_ec as $bloque ) {
				$salida[] = $bloque[ $i ];
			}
		}

		return $salida;
	}

	/* ------------------------- Reed-Solomon en GF(256) --------------------- */

	/** Tablas exp/log de GF(256) con el polinomio 0x11D. */
	private static function gf() {
		static $tablas = null;
		if ( null !== $tablas ) {
			return $tablas;
		}
		$exp = array_fill( 0, 512, 0 );
		$log = array_fill( 0, 256, 0 );
		$x = 1;
		for ( $i = 0; $i < 255; $i++ ) {
			$exp[ $i ] = $x;
			$log[ $x ] = $i;
			$x <<= 1;
			if ( $x & 0x100 ) {
				$x ^= 0x11D;
			}
		}
		for ( $i = 255; $i < 512; $i++ ) {
			$exp[ $i ] = $exp[ $i - 255 ];
		}
		return $tablas = [ $exp, $log ];
	}

	private static function gf_mul( $a, $b ) {
		if ( 0 === $a || 0 === $b ) {
			return 0;
		}
		list( $exp, $log ) = self::gf();
		return $exp[ $log[ $a ] + $log[ $b ] ];
	}

	/**
	 * Polinomio generador de grado $n: el producto de (x - α^i) para i < n.
	 *
	 * Los coeficientes van de MAYOR a menor grado, con `$g[0] === 1`. El orden
	 * importa y no es decorativo: `reed_solomon()` lee `$g[$i+1]` dando por
	 * sentado que el término líder es el primero. Guardarlo al revés produce un
	 * QR con la geometría perfecta y los bytes de corrección equivocados —
	 * indistinguible a la vista, ilegible para cualquier lector.
	 */
	private static function rs_generator( $n ) {
		static $cache = [];
		if ( isset( $cache[ $n ] ) ) {
			return $cache[ $n ];
		}
		list( $exp, ) = self::gf();
		$g = [ 1 ];
		for ( $i = 0; $i < $n; $i++ ) {
			$nuevo = array_fill( 0, count( $g ) + 1, 0 );
			foreach ( $g as $j => $coef ) {
				$nuevo[ $j ]     ^= $coef;                              // coef · x
				$nuevo[ $j + 1 ] ^= self::gf_mul( $coef, $exp[ $i ] );  // coef · α^i
			}
			$g = $nuevo;
		}
		return $cache[ $n ] = $g;
	}

	/**
	 * Codewords de corrección de un bloque.
	 *
	 * @param array $bloque
	 * @param int   $n Cuántos generar.
	 * @return array<int,int>
	 */
	private static function reed_solomon( $bloque, $n ) {
		$g = self::rs_generator( $n );
		// División polinómica: el resto son los codewords de corrección.
		$resto = array_fill( 0, $n, 0 );
		foreach ( $bloque as $byte ) {
			$factor = $byte ^ $resto[0];
			array_shift( $resto );
			$resto[] = 0;
			for ( $i = 0; $i < $n; $i++ ) {
				$resto[ $i ] ^= self::gf_mul( $g[ $i + 1 ], $factor );
			}
		}
		return $resto;
	}

	/* ===================================================================== */
	/* Matriz                                                                 */
	/* ===================================================================== */

	/** Mapa de módulos ocupados por patrones fijos y zonas reservadas. */
	private static function reserved_map( $version, $size ) {
		$r = array_fill( 0, $size, array_fill( 0, $size, false ) );

		$marcar = static function ( &$r, $x0, $y0, $w, $h ) use ( $size ) {
			for ( $y = $y0; $y < $y0 + $h; $y++ ) {
				for ( $x = $x0; $x < $x0 + $w; $x++ ) {
					if ( $x >= 0 && $y >= 0 && $x < $size && $y < $size ) {
						$r[ $y ][ $x ] = true;
					}
				}
			}
		};

		// Los tres localizadores con su separador, y la franja de formato.
		$marcar( $r, 0, 0, 9, 9 );
		$marcar( $r, $size - 8, 0, 8, 9 );
		$marcar( $r, 0, $size - 8, 9, 8 );

		// Temporizadores.
		$marcar( $r, 8, 6, $size - 16, 1 );
		$marcar( $r, 6, 8, 1, $size - 16 );

		// Alineación: se saltean los que pisarían un localizador.
		$centros = self::ALIGN[ $version ];
		$ultimo  = count( $centros ) - 1;
		foreach ( $centros as $i => $cy ) {
			foreach ( $centros as $j => $cx ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $j === $ultimo ) || ( $i === $ultimo && 0 === $j ) ) {
					continue;
				}
				$marcar( $r, $cx - 2, $cy - 2, 5, 5 );
			}
		}

		// Información de versión (dos bloques de 3x6) desde la versión 7.
		if ( $version >= 7 ) {
			$marcar( $r, 0, $size - 11, 6, 3 );
			$marcar( $r, $size - 11, 0, 3, 6 );
		}

		return $r;
	}

	/** Dibuja los patrones fijos sobre una matriz nueva. */
	private static function draw_function_patterns( $version, $size ) {
		$m = array_fill( 0, $size, array_fill( 0, $size, 0 ) );

		$localizador = static function ( &$m, $x0, $y0 ) use ( $size ) {
			for ( $y = -1; $y <= 7; $y++ ) {
				for ( $x = -1; $x <= 7; $x++ ) {
					$px = $x0 + $x;
					$py = $y0 + $y;
					if ( $px < 0 || $py < 0 || $px >= $size || $py >= $size ) {
						continue;
					}
					$borde  = ( 0 === $x || 6 === $x || 0 === $y || 6 === $y ) && $x >= 0 && $x <= 6 && $y >= 0 && $y <= 6;
					$centro = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;
					$m[ $py ][ $px ] = ( $borde || $centro ) ? 1 : 0;
				}
			}
		};

		$localizador( $m, 0, 0 );
		$localizador( $m, $size - 7, 0 );
		$localizador( $m, 0, $size - 7 );

		// Temporizadores: alternan empezando en oscuro.
		for ( $i = 8; $i < $size - 8; $i++ ) {
			$v = ( 0 === $i % 2 ) ? 1 : 0;
			$m[6][ $i ] = $v;
			$m[ $i ][6] = $v;
		}

		// Alineación.
		$centros = self::ALIGN[ $version ];
		$ultimo  = count( $centros ) - 1;
		foreach ( $centros as $i => $cy ) {
			foreach ( $centros as $j => $cx ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $j === $ultimo ) || ( $i === $ultimo && 0 === $j ) ) {
					continue;
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$anillo = ( 2 === max( abs( $dx ), abs( $dy ) ) ) || ( 0 === $dx && 0 === $dy );
						$m[ $cy + $dy ][ $cx + $dx ] = $anillo ? 1 : 0;
					}
				}
			}
		}

		// Módulo oscuro fijo.
		$m[ $size - 8 ][8] = 1;

		return $m;
	}

	/** Coloca el flujo de bits en zigzag, salteando lo reservado. */
	private static function place_data( &$m, $reservado, $codewords, $size ) {
		$bits = '';
		foreach ( $codewords as $cw ) {
			$bits .= str_pad( decbin( $cw ), 8, '0', STR_PAD_LEFT );
		}

		$i    = 0;
		$len  = strlen( $bits );
		$col  = $size - 1;
		$arriba = true;

		while ( $col > 0 ) {
			if ( 6 === $col ) {
				$col--;                                  // la columna del temporizador no cuenta
			}
			for ( $k = 0; $k < $size; $k++ ) {
				$y = $arriba ? ( $size - 1 - $k ) : $k;
				foreach ( [ $col, $col - 1 ] as $x ) {
					if ( $reservado[ $y ][ $x ] ) {
						continue;
					}
					$m[ $y ][ $x ] = ( $i < $len ) ? (int) $bits[ $i ] : 0;
					$i++;
				}
			}
			$col   -= 2;
			$arriba = ! $arriba;
		}
	}

	/** Aplica una máscara a los módulos de datos. */
	private static function apply_mask( $m, $reservado, $mask, $size ) {
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				if ( $reservado[ $y ][ $x ] ) {
					continue;
				}
				if ( self::mask_bit( $mask, $x, $y ) ) {
					$m[ $y ][ $x ] ^= 1;
				}
			}
		}
		return $m;
	}

	private static function mask_bit( $mask, $x, $y ) {
		switch ( $mask ) {
			case 0: return 0 === ( $y + $x ) % 2;
			case 1: return 0 === $y % 2;
			case 2: return 0 === $x % 3;
			case 3: return 0 === ( $y + $x ) % 3;
			case 4: return 0 === ( intdiv( $y, 2 ) + intdiv( $x, 3 ) ) % 2;
			case 5: return 0 === ( $y * $x ) % 2 + ( $y * $x ) % 3;
			case 6: return 0 === ( ( $y * $x ) % 2 + ( $y * $x ) % 3 ) % 2;
			case 7: return 0 === ( ( $y + $x ) % 2 + ( $y * $x ) % 3 ) % 2;
		}
		return false;
	}

	/** Información de formato: nivel + máscara, con BCH y XOR de la norma. */
	private static function draw_format_info( &$m, $ecl, $mask, $size ) {
		$datos = ( self::ECL_BITS[ $ecl ] << 3 ) | $mask;
		$bch   = $datos << 10;
		for ( $i = 14; $i >= 10; $i-- ) {
			if ( $bch & ( 1 << $i ) ) {
				$bch ^= 0b10100110111 << ( $i - 10 );
			}
		}
		$fmt = ( ( $datos << 10 ) | $bch ) ^ 0b101010000010010;

		for ( $i = 0; $i < 15; $i++ ) {
			$bit = ( $fmt >> $i ) & 1;

			// Copia junto al localizador superior izquierdo.
			if ( $i < 6 ) {
				$m[ $i ][8] = $bit;
			} elseif ( 6 === $i ) {
				$m[7][8] = $bit;
			} elseif ( 7 === $i ) {
				$m[8][8] = $bit;
			} elseif ( 8 === $i ) {
				$m[8][7] = $bit;
			} else {
				$m[8][ 14 - $i ] = $bit;
			}

			// Copia repartida entre los otros dos localizadores.
			if ( $i < 8 ) {
				$m[8][ $size - 1 - $i ] = $bit;
			} else {
				$m[ $size - 15 + $i ][8] = $bit;
			}
		}
	}

	/** Información de versión (solo 7 en adelante). */
	private static function draw_version_info( &$m, $version, $size ) {
		$bch = $version << 12;
		for ( $i = 17; $i >= 12; $i-- ) {
			if ( $bch & ( 1 << $i ) ) {
				$bch ^= 0b1111100100101 << ( $i - 12 );
			}
		}
		$info = ( $version << 12 ) | $bch;

		for ( $i = 0; $i < 18; $i++ ) {
			$bit = ( $info >> $i ) & 1;
			$a   = intdiv( $i, 3 );
			$b   = $i % 3;
			$m[ $size - 11 + $b ][ $a ] = $bit;
			$m[ $a ][ $size - 11 + $b ] = $bit;
		}
	}

	/* --------------------------- Penalización ------------------------------ */

	/**
	 * Puntaje de la norma: cuanto más bajo, más fácil de leer. Se calcula sobre
	 * la matriz ya enmascarada para elegir entre las ocho máscaras.
	 */
	private static function penalty( $m, $size ) {
		$total = 0;

		// N1: rachas de cinco o más del mismo color, por filas y por columnas.
		for ( $i = 0; $i < $size; $i++ ) {
			foreach ( [ 'fila', 'col' ] as $dir ) {
				$racha = 1;
				for ( $j = 1; $j < $size; $j++ ) {
					$a = ( 'fila' === $dir ) ? $m[ $i ][ $j ] : $m[ $j ][ $i ];
					$b = ( 'fila' === $dir ) ? $m[ $i ][ $j - 1 ] : $m[ $j - 1 ][ $i ];
					if ( $a === $b ) {
						$racha++;
					} else {
						if ( $racha >= 5 ) {
							$total += 3 + ( $racha - 5 );
						}
						$racha = 1;
					}
				}
				if ( $racha >= 5 ) {
					$total += 3 + ( $racha - 5 );
				}
			}
		}

		// N2: bloques de 2x2 del mismo color.
		for ( $y = 0; $y < $size - 1; $y++ ) {
			for ( $x = 0; $x < $size - 1; $x++ ) {
				$v = $m[ $y ][ $x ];
				if ( $v === $m[ $y ][ $x + 1 ] && $v === $m[ $y + 1 ][ $x ] && $v === $m[ $y + 1 ][ $x + 1 ] ) {
					$total += 3;
				}
			}
		}

		// N3: el patrón 1:1:3:1:1 con una zona clara de cuatro unidades a un
		// lado, que un lector puede confundir con un localizador.
		//
		// Se cuenta por historial de rachas y no buscando una ventana fija de
		// once módulos, por dos motivos que la versión ingenua se pierde: la
		// proporción vale a CUALQUIER escala (2:2:6:2:2 engaña igual), y el
		// afuera del símbolo cuenta como claro, así que el patrón pegado al
		// borde también penaliza. Con la ventana fija la elección de máscara se
		// aparta de la norma en cerca de la mitad de los casos.
		for ( $i = 0; $i < $size; $i++ ) {
			foreach ( [ 'fila', 'col' ] as $dir ) {
				$color = 0;
				$largo = 0;
				$hist  = array_fill( 0, 7, 0 );
				for ( $j = 0; $j < $size; $j++ ) {
					$v = ( 'fila' === $dir ) ? $m[ $i ][ $j ] : $m[ $j ][ $i ];
					if ( $v === $color ) {
						$largo++;
						continue;
					}
					self::hist_push( $hist, $largo, $size );
					if ( ! $color ) {
						$total += self::hist_count( $hist ) * 40;
					}
					$color = $v;
					$largo = 1;
				}
				$total += self::hist_terminate( $hist, $color, $largo, $size ) * 40;
			}
		}

		// N4: desbalance entre módulos oscuros y claros.
		$oscuros = 0;
		foreach ( $m as $fila ) {
			$oscuros += array_sum( $fila );
		}
		$porcentaje = $oscuros * 100 / ( $size * $size );
		$k = (int) ( abs( $porcentaje - 50 ) / 5 );
		$total += $k * 10;

		return $total;
	}

	/**
	 * Empuja una racha al historial de siete que usa la regla N3.
	 *
	 * Si el historial está vacío, la racha es la PRIMERA de la línea y se le
	 * suma el ancho del símbolo: así se cuenta como claro todo lo que hay
	 * afuera del código, que es lo que ve un lector de verdad.
	 */
	private static function hist_push( &$hist, $largo, $size ) {
		if ( 0 === $hist[0] ) {
			$largo += $size;
		}
		array_pop( $hist );
		array_unshift( $hist, $largo );
	}

	/** Cierra la línea agregando el borde claro final y cuenta lo que quede. */
	private static function hist_terminate( $hist, $color, $largo, $size ) {
		if ( $color ) {
			self::hist_push( $hist, $largo, $size );
			$largo = 0;
		}
		$largo += $size;
		self::hist_push( $hist, $largo, $size );
		return self::hist_count( $hist );
	}

	/**
	 * ¿Cuántos localizadores falsos hay en el historial? Cero, uno o dos: el
	 * núcleo 1:1:3:1:1 puede tener la zona clara de cuatro unidades de un lado,
	 * del otro, o de los dos.
	 */
	private static function hist_count( $hist ) {
		$n = $hist[1];
		$nucleo = $n > 0
			&& $hist[2] === $n
			&& $hist[3] === $n * 3
			&& $hist[4] === $n
			&& $hist[5] === $n;

		return ( $nucleo && $hist[0] >= $n * 4 && $hist[6] >= $n ? 1 : 0 )
		     + ( $nucleo && $hist[6] >= $n * 4 && $hist[0] >= $n ? 1 : 0 );
	}
}
