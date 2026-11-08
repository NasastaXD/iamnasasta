<?php
/**
 * Lógica pura del estudio piloto (sin base de datos ni hooks), para poder
 * probarla con PHPUnit sin WordPress.
 *
 * Es la misma lógica que el backend nuevo (repo Colegios,
 * supabase/functions/_shared/*.ts): si se cambia una, se cambia la otra. Los
 * dos leen los instrumentos del mismo archivo (config/estudio-encuestas.json).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Estudio_Logica {

	const K = 5;
	const AGRUPADO = '(agrupado)';
	const TOLERANCIA_RELOJ = 120; // segundos

	const EVENTOS = [
		'comunicado_publicado', 'comunicado_leido',
		'asistencia_inicio', 'asistencia_fin', 'asistencia_consolidada',
		'encuesta_enviada', 'encuesta_respondida',
		'sesion_inicio', 'push_enviado', 'push_abierto',
		'sync_encolado', 'sync_aplicado', 'sync_rechazado',
		'error_app',
	];

	/** Nunca salen en una exportación de investigación (spec 2.3). */
	const PROHIBIDAS = [ 'user_id', 'nombre', 'usuario', 'email', 'telefono', 'ci', 'documento', 'fecha_nacimiento', 'ip', 'user_agent', 'nota', 'texto', 'mensaje' ];

	/* ------------------------------------------------------------ seudónimo */

	/**
	 * HMAC-SHA256(sal, user_id). La sal vive en wp-config.php
	 * (CEAD_ESTUDIO_SALT), nunca en la base: una copia de la base no alcanza
	 * para re-identificar a nadie.
	 */
	public static function seudonimo( $sal, $user_id ) {
		if ( ! is_string( $sal ) || strlen( $sal ) < 32 ) {
			throw new InvalidArgumentException( 'CEAD_ESTUDIO_SALT ausente o demasiado corta (mínimo 32 caracteres)' );
		}
		return hash_hmac( 'sha256', (string) $user_id, $sal );
	}

	/* ----------------------------------------------------------------- meta */

	/**
	 * `meta` sin información personal: claves cortas con forma de
	 * identificador, nada que suene a dato personal o texto libre, valores
	 * primitivos y textos de hasta 60 caracteres.
	 */
	public static function meta_segura( $meta ) {
		if ( ! is_array( $meta ) ) {
			return null;
		}
		$prohibidas = '/(nombre|name|mail|correo|tel|phone|ci$|cedula|documento|nacim|birth|ip$|^ip|user_?agent|ua$|texto|mensaje|message|nota|grade|token|clave|password|direccion|address)/i';
		$out = [];
		foreach ( $meta as $k => $v ) {
			if ( ! is_string( $k ) || ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $k ) || preg_match( $prohibidas, $k ) ) {
				continue;
			}
			if ( is_bool( $v ) || is_int( $v ) || ( is_float( $v ) && is_finite( $v ) ) ) {
				$out[ $k ] = $v;
			} elseif ( is_string( $v ) && mb_strlen( $v ) <= 60 ) {
				$out[ $k ] = $v;
			}
		}
		return $out ?: null;
	}

	/* ------------------------------------------------------------- tiempos */

	/**
	 * La hora del teléfono es dudosa si es posterior a la llegada al servidor
	 * o anterior a la publicación de lo que dice haber leído (spec 3.3).
	 *
	 * @param int|null $cli Timestamp del cliente.
	 */
	public static function ts_cliente_dudoso( $cli, $srv, $publicado = null ) {
		if ( null === $cli ) {
			return false;
		}
		if ( $cli > $srv + self::TOLERANCIA_RELOJ ) {
			return true;
		}
		return null !== $publicado && $cli < $publicado - self::TOLERANCIA_RELOJ;
	}

	/** Un `ts_cliente` del pedido (ISO 8601 o epoch en ms) → timestamp, o null. */
	public static function parsear_ts( $v ) {
		if ( is_numeric( $v ) ) {
			$n = (float) $v;
			return (int) ( $n > 1e11 ? $n / 1000 : $n );
		}
		if ( ! is_string( $v ) || '' === $v ) {
			return null;
		}
		$t = strtotime( $v );
		return false === $t ? null : $t;
	}

	/* --------------------------------------------------------- k-anonimato */

	/**
	 * Grupo institución × rol × curso con menos de k personas → el curso se
	 * generaliza; si el agrupado sigue debajo de k → se suprime. Cuenta
	 * personas distintas (seudónimos), no filas.
	 *
	 * @return array{filas: array, generalizadas: int, suprimidas: int}
	 */
	public static function k_anonimato( array $filas, $k = self::K ) {
		$clave  = static function ( $f, $curso ) {
			return $f['institucion'] . "\0" . $f['rol'] . "\0" . (string) $curso;
		};
		$contar = static function ( $filas ) use ( $clave ) {
			$m = [];
			foreach ( $filas as $f ) {
				$m[ $clave( $f, $f['curso_grupo'] ) ][ $f['seudonimo'] ] = true;
			}
			return array_map( 'count', $m );
		};

		$por_curso     = $contar( $filas );
		$generalizadas = 0;
		foreach ( $filas as &$f ) {
			if ( $por_curso[ $clave( $f, $f['curso_grupo'] ) ] < $k ) {
				$f['curso_grupo'] = self::AGRUPADO;
				$generalizadas++;
			}
		}
		unset( $f );

		$por_grupo = $contar( $filas );
		$salida    = array_values( array_filter( $filas, static function ( $f ) use ( $por_grupo, $clave, $k ) {
			return $por_grupo[ $clave( $f, $f['curso_grupo'] ) ] >= $k;
		} ) );

		return [ 'filas' => $salida, 'generalizadas' => $generalizadas, 'suprimidas' => count( $filas ) - count( $salida ) ];
	}

	/* ------------------------------------------------------------------ SUS */

	/** System Usability Scale 0–100: impares (r − 1), pares (5 − r), suma × 2,5. */
	public static function sus( array $r ) {
		if ( count( $r ) !== 10 ) {
			throw new InvalidArgumentException( 'SUS requiere 10 respuestas' );
		}
		$suma = 0;
		foreach ( array_values( $r ) as $i => $v ) {
			if ( ! is_int( $v ) || $v < 1 || $v > 5 ) {
				throw new InvalidArgumentException( 'respuesta SUS fuera de rango' );
			}
			$suma += 0 === $i % 2 ? $v - 1 : 5 - $v;
		}
		return $suma * 2.5;
	}

	/* ----------------------------------------------------------- calendario */

	/** Domingo de Pascua (algoritmo anónimo gregoriano). @return string Y-m-d */
	public static function pascua( $y ) {
		$a = $y % 19; $b = intdiv( $y, 100 ); $c = $y % 100;
		$d = intdiv( $b, 4 ); $e = $b % 4; $f = intdiv( $b + 8, 25 );
		$g = intdiv( $b - $f + 1, 3 );
		$h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i = intdiv( $c, 4 ); $k = $c % 4;
		$l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$mes = intdiv( $h + $l - 7 * $m + 114, 31 );
		$dia = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;
		return sprintf( '%04d-%02d-%02d', $y, $mes, $dia );
	}

	public static function feriados( $y, array $cfg = [] ) {
		$fijos = [ '01-01', '03-01', '05-01', '05-14', '05-15', '06-12', '08-15', '09-29', '12-08', '12-25' ];
		$s = [];
		foreach ( $fijos as $md ) {
			$s[ "$y-$md" ] = true;
		}
		$p = new DateTimeImmutable( self::pascua( $y ), new DateTimeZone( 'UTC' ) );
		$s[ $p->modify( '-3 days' )->format( 'Y-m-d' ) ] = true; // Jueves Santo
		$s[ $p->modify( '-2 days' )->format( 'Y-m-d' ) ] = true; // Viernes Santo
		foreach ( (array) ( $cfg['feriados_quitar'] ?? [] ) as $q ) {
			unset( $s[ $q ] );
		}
		foreach ( (array) ( $cfg['feriados_extra'] ?? [] ) as $x ) {
			if ( str_starts_with( (string) $x, "$y-" ) ) {
				$s[ $x ] = true;
			}
		}
		return $s;
	}

	public static function es_habil( DateTimeImmutable $d, array $cfg = [] ) {
		if ( (int) $d->format( 'N' ) >= 6 ) {
			return false;
		}
		return ! isset( self::feriados( (int) $d->format( 'Y' ), $cfg )[ $d->format( 'Y-m-d' ) ] );
	}

	public static function siguiente_habil( DateTimeImmutable $d, array $cfg = [] ) {
		while ( ! self::es_habil( $d, $cfg ) ) {
			$d = $d->modify( '+1 day' );
		}
		return $d;
	}

	/**
	 * Ventana de la encuesta del mes `$mes` (0..12): envío el primer día hábil
	 * del mes a la hora configurada (Paraguay, UTC−3 fijo desde 2024), cierre
	 * a los N días, recordatorios a los días indicados y el último día.
	 * Mes 0 sale el día de inicio (o el siguiente hábil).
	 *
	 * @return array{mes:int, envio:int, cierre:int, recordatorios:int[]} timestamps UTC
	 */
	public static function ventana( $inicio, $mes, array $cfg = [] ) {
		$cfg += [ 'hora_envio' => '10:00', 'utc_offset_horas' => -3, 'ventana_dias' => 7, 'recordatorios_dias' => [ 3 ] ];
		if ( ! is_int( $mes ) || $mes < 0 || $mes > 12 ) {
			throw new InvalidArgumentException( 'mes fuera de rango' );
		}
		$utc = new DateTimeZone( 'UTC' );
		$i   = new DateTimeImmutable( $inicio, $utc );
		$dia = 0 === $mes
			? self::siguiente_habil( $i, $cfg )
			: self::siguiente_habil( $i->modify( 'first day of this month' )->modify( "+{$mes} months" ), $cfg );
		[ $h, $m ] = array_map( 'intval', explode( ':', $cfg['hora_envio'] ) );
		$envio  = $dia->getTimestamp() + ( $h - (int) $cfg['utc_offset_horas'] ) * 3600 + $m * 60;
		$cierre = $envio + (int) $cfg['ventana_dias'] * DAY_IN_SECONDS;
		$rec    = [];
		foreach ( (array) $cfg['recordatorios_dias'] as $d ) {
			$t = $envio + (int) $d * DAY_IN_SECONDS;
			if ( $t < $cierre ) {
				$rec[] = $t;
			}
		}
		$rec[] = $cierre - DAY_IN_SECONDS; // «el último día»
		return [ 'mes' => $mes, 'envio' => $envio, 'cierre' => $cierre, 'recordatorios' => $rec ];
	}

	/** La recolección termina al cerrar la ventana de la encuesta final (mes 12). */
	public static function fin_recoleccion( $inicio, array $cfg = [] ) {
		return self::ventana( $inicio, 12, $cfg )['cierre'];
	}

	/* ------------------------------------------------------- instrumentos */

	/**
	 * Une los bloques del mes (config['meses']) tomando la última versión de
	 * cada uno. Los ids se prefijan con el bloque: `mensual.a_tiempo`.
	 *
	 * @param string $rol alumno|docente
	 */
	public static function instrumento( array $cfg, $mes, $rol ) {
		$def = null;
		foreach ( (array) ( $cfg['meses'] ?? [] ) as $m ) {
			if ( (int) $m['mes'] === (int) $mes ) {
				$def = $m;
			}
		}
		if ( ! $def ) {
			throw new InvalidArgumentException( "no hay encuesta para el mes $mes" );
		}
		$preguntas = [];
		$versiones = [];
		foreach ( $def['bloques'] as $bloque ) {
			$b = $cfg['instrumentos'][ $bloque ][ $rol ] ?? $cfg['instrumentos'][ $bloque ]['todos'] ?? null;
			if ( ! $b || empty( $b['versiones'] ) ) {
				throw new InvalidArgumentException( "el bloque $bloque no tiene versión para $rol" );
			}
			$v = end( $b['versiones'] );
			$versiones[] = $bloque . '@' . $v['version'];
			foreach ( $v['preguntas'] as $p ) {
				$p['id']     = $bloque . '.' . $p['id'];
				$preguntas[] = $p;
			}
		}
		return [
			'clave'     => sprintf( 'estudio-m%02d-%s', $mes, $rol ),
			'mes'       => (int) $mes,
			'rol'       => $rol,
			'version'   => implode( '+', $versiones ),
			'preguntas' => $preguntas,
		];
	}

	/**
	 * Valida respuestas contra el instrumento. Separa el texto libre (que no
	 * se exporta sin revisión manual) y arma el vector SUS si está completo.
	 *
	 * @return array{cerradas: array, abiertas: array, sus: int[]|null, errores: string[]}
	 */
	public static function validar_respuestas( array $preguntas, array $entrada ) {
		$cerradas = [];
		$abiertas = [];
		$errores  = [];
		$ids      = array_column( $preguntas, 'id' );
		foreach ( array_keys( $entrada ) as $k ) {
			if ( ! in_array( $k, $ids, true ) ) {
				$errores[] = "pregunta desconocida: $k";
			}
		}
		foreach ( $preguntas as $p ) {
			$v = $entrada[ $p['id'] ] ?? null;
			if ( null === $v || '' === $v ) {
				if ( empty( $p['opcional'] ) ) {
					$errores[] = "falta responder: {$p['id']}";
				}
				continue;
			}
			switch ( $p['tipo'] ) {
				case 'likert5':
					$n = filter_var( $v, FILTER_VALIDATE_INT );
					if ( false === $n || $n < 1 || $n > 5 ) {
						$errores[] = "{$p['id']}: debe ser 1 a 5";
					} else {
						$cerradas[ $p['id'] ] = $n;
					}
					break;
				case 'numero':
					$n = is_numeric( $v ) ? $v + 0 : null;
					if ( null === $n || ( isset( $p['min'] ) && $n < $p['min'] ) || ( isset( $p['max'] ) && $n > $p['max'] ) ) {
						$errores[] = "{$p['id']}: número fuera de rango";
					} else {
						$cerradas[ $p['id'] ] = $n;
					}
					break;
				case 'opcion':
					if ( ! is_string( $v ) || ! in_array( $v, (array) ( $p['opciones'] ?? [] ), true ) ) {
						$errores[] = "{$p['id']}: opción inválida";
					} else {
						$cerradas[ $p['id'] ] = $v;
					}
					break;
				case 'texto_corto':
					$t = mb_substr( trim( (string) $v ), 0, (int) ( $p['max_largo'] ?? 280 ) );
					if ( '' !== $t ) {
						$abiertas[ $p['id'] ] = $t;
					}
					break;
			}
		}
		$sus_ids = array_values( array_filter( $ids, static function ( $id ) {
			return str_starts_with( $id, 'sus.' );
		} ) );
		$sus = null;
		if ( 10 === count( $sus_ids ) && count( array_intersect_key( $cerradas, array_flip( $sus_ids ) ) ) === 10 ) {
			$sus = array_map( static function ( $id ) use ( $cerradas ) {
				return $cerradas[ $id ];
			}, $sus_ids );
		}
		return [ 'cerradas' => $cerradas, 'abiertas' => $abiertas, 'sus' => $sus, 'errores' => $errores ];
	}

	/**
	 * Tipo de pregunta del instrumento → tipo del módulo de encuestas
	 * (para mostrarla con el mismo formulario que las demás encuestas).
	 */
	public static function tipo_survey( $tipo ) {
		return [ 'likert5' => 'scale', 'opcion' => 'radio', 'numero' => 'text', 'texto_corto' => 'text' ][ $tipo ] ?? 'text';
	}
}
