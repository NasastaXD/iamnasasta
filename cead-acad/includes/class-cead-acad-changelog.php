<?php
/**
 * Novedades: lo que cambió en cada versión, para quien usa el panel y para quien
 * administra.
 *
 * Los datos viven en `changelog-data.php` (una sola fuente). Acá está lo que se
 * hace con ellos: qué ve cada quien, qué ya vio cada persona y cómo se lee una
 * fecha. Casi todo es puro (recibe datos, devuelve datos) para poder probarlo sin
 * WordPress.
 *
 * Tres miradas, de menos a más:
 *   - 'todos'  el alumnado y las familias: solo lo que les importa.
 *   - 'equipo' dirección, secretaría, docentes, delegados/as y consejo: además,
 *              lo que cambia su trabajo.
 *   - 'admin'  wp-admin: todo, más las notas técnicas.
 * Una novedad se ve desde la mirada que le corresponde o una más amplia.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Changelog {

	/** Qué versión vio por última vez cada persona (meta de usuario). */
	const META_VISTO = 'cead_acad_novedades_visto';

	/** De menos a más: una novedad con nivel N se ve desde una mirada de nivel >= N. */
	const NIVELES = [ 'todos' => 0, 'equipo' => 1, 'admin' => 2 ];

	/** Roles que cuentan como «el equipo» (ven las novedades de gestión). */
	const ROLES_EQUIPO = [
		'cead_acad_direction',
		'cead_acad_secretary',
		'cead_acad_teacher',
		'cead_acad_delegate',
		'cead_acad_student_council',
	];

	/** Tipos de novedad: slug => [ singular, plural ]. El orden es el de los filtros. */
	const TIPOS = [
		'nuevo'   => [ 'Nuevo', 'Nuevos' ],
		'mejora'  => [ 'Mejora', 'Mejoras' ],
		'arreglo' => [ 'Arreglo', 'Arreglos' ],
	];

	const MESES = [
		1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
		7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
	];

	/* ------------------------------------------------------------- los datos */

	/** Todas las entradas, de la más nueva a la más vieja. */
	public static function todas() {
		static $cache = null;
		if ( null === $cache ) {
			$datos = require __DIR__ . '/changelog-data.php';
			$cache = self::ordenar( is_array( $datos ) ? $datos : [] );
		}
		return $cache;
	}

	/**
	 * De la versión más nueva a la más vieja, comparando como VERSIONES: la 1.10
	 * es más nueva que la 1.2, aunque como texto sea menor.
	 */
	public static function ordenar( $entradas ) {
		usort( $entradas, static function ( $a, $b ) {
			return version_compare( (string) ( $b['version'] ?? '0' ), (string) ( $a['version'] ?? '0' ) );
		} );
		return $entradas;
	}

	/**
	 * Lo que se ve desde una mirada: las entradas con sus ítems filtrados, sin
	 * las que se quedan sin ninguno.
	 *
	 * @param string     $mirada 'todos' | 'equipo' | 'admin'.
	 * @param array|null $todas  Para probar con datos propios.
	 */
	public static function para( $mirada, $todas = null ) {
		$todas  = null === $todas ? self::todas() : self::ordenar( $todas );
		$salida = [];
		foreach ( $todas as $entrada ) {
			$items = array_values( array_filter(
				(array) ( $entrada['items'] ?? [] ),
				static function ( $item ) use ( $mirada ) {
					return self::ve( $item, $mirada );
				}
			) );
			if ( $items ) {
				$entrada['items'] = $items;
				$salida[]         = $entrada;
			}
		}
		return $salida;
	}

	/** ¿Esta novedad se ve desde esta mirada? */
	public static function ve( $item, $mirada ) {
		$nivel_item   = self::NIVELES[ $item['para'] ?? 'todos' ] ?? 0;
		$nivel_mirada = self::NIVELES[ $mirada ] ?? 0;
		return $nivel_item <= $nivel_mirada;
	}

	/** Cuántas novedades hay de cada tipo en una entrada: [ 'nuevo' => 3, … ] (solo los que tienen). */
	public static function contar( $entrada ) {
		$n = [];
		foreach ( array_keys( self::TIPOS ) as $tipo ) {
			$cuantas = count( array_filter(
				(array) ( $entrada['items'] ?? [] ),
				static function ( $i ) use ( $tipo ) { return ( $i['tipo'] ?? '' ) === $tipo; }
			) );
			if ( $cuantas ) { $n[ $tipo ] = $cuantas; }
		}
		return $n;
	}

	/** Cuántas novedades de cada tipo hay en toda la lista (para los filtros). */
	public static function contar_todas( $entradas ) {
		$n = array_fill_keys( array_keys( self::TIPOS ), 0 );
		foreach ( $entradas as $e ) {
			foreach ( self::contar( $e ) as $tipo => $cuantas ) { $n[ $tipo ] += $cuantas; }
		}
		return $n;
	}

	/* ------------------------------------------------------- qué ya se vio */

	/**
	 * ¿Hay algo que esta persona no vio? La entrada más nueva que ella puede ver,
	 * comparada con la última versión que vio.
	 */
	public static function hay_nuevas( $visto, $entradas ) {
		$entradas = self::ordenar( $entradas );
		if ( ! $entradas ) { return false; }
		return version_compare( (string) $entradas[0]['version'], (string) $visto, '>' );
	}

	/**
	 * Las versiones que son «nuevas para vos»: las más nuevas que lo último que
	 * viste. Quien nunca entró a esta página ve marcada solo la última, no las
	 * veinte: todo marcado como nuevo es lo mismo que nada marcado.
	 *
	 * @return string[] Las versiones.
	 */
	public static function frescas( $entradas, $visto ) {
		$entradas = self::ordenar( $entradas );
		if ( ! $entradas ) { return []; }
		if ( '' === (string) $visto ) { return [ (string) $entradas[0]['version'] ]; }
		$v = [];
		foreach ( $entradas as $e ) {
			if ( version_compare( (string) $e['version'], (string) $visto, '>' ) ) { $v[] = (string) $e['version']; }
		}
		return $v;
	}

	/** La mirada que le toca a una persona en el panel. */
	public static function mirada_de( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id && user_can( $user_id, 'manage_options' ) ) {
			return 'equipo'; // administrar WordPress no es ser del equipo del panel: ve lo mismo que el equipo.
		}
		$u = $user_id ? get_user_by( 'id', $user_id ) : null;
		$roles = $u ? (array) $u->roles : [];
		return array_intersect( $roles, self::ROLES_EQUIPO ) ? 'equipo' : 'todos';
	}

	public static function visto( $user_id ) {
		return (string) get_user_meta( (int) $user_id, self::META_VISTO, true );
	}

	/** Anota que la persona vio hasta esta versión. Nunca la baja. */
	public static function marcar_visto( $user_id, $version ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || '' === (string) $version ) { return; }
		if ( version_compare( (string) $version, self::visto( $user_id ), '>' ) ) {
			update_user_meta( $user_id, self::META_VISTO, (string) $version );
		}
	}

	/** Atajo: ¿esta persona tiene novedades sin ver desde esta mirada? */
	public static function hay_nuevas_para( $user_id, $mirada ) {
		return self::hay_nuevas( self::visto( $user_id ), self::para( $mirada ) );
	}

	/* ------------------------------------------------------------ las cosas chicas */

	/** '2026-10-10' → '10 de octubre de 2026'. Una fecha que no se entiende vuelve tal cual. */
	public static function fecha_larga( $ymd ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $ymd, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return (string) $ymd;
		}
		return sprintf( '%d de %s de %d', (int) $m[3], self::MESES[ (int) $m[2] ], (int) $m[1] );
	}

	/** El nombre de un tipo: singular o plural. */
	public static function etiqueta( $tipo, $plural = false ) {
		return self::TIPOS[ $tipo ][ $plural ? 1 : 0 ] ?? $tipo;
	}

	/** El identificador de una entrada para usar como ancla: '0.97.0' → 'v0-97-0'. */
	public static function ancla( $version ) {
		return 'v' . preg_replace( '/[^0-9a-z]+/i', '-', (string) $version );
	}

	/**
	 * Las notas de una versión en Markdown, para el Release de GitHub (y de ahí,
	 * la pantalla de actualización de WordPress). Vacío si no hay entrada.
	 */
	public static function notas_markdown( $version, $todas = null ) {
		$todas = null === $todas ? self::todas() : $todas;
		foreach ( $todas as $e ) {
			if ( (string) $e['version'] !== (string) $version ) { continue; }
			$md = '## ' . $e['titulo'] . "\n\n" . $e['resumen'] . "\n";
			foreach ( array_keys( self::TIPOS ) as $tipo ) {
				$items = array_filter( (array) $e['items'], static function ( $i ) use ( $tipo ) { return $i['tipo'] === $tipo; } );
				if ( ! $items ) { continue; }
				$md .= "\n### " . self::etiqueta( $tipo, true ) . "\n\n";
				foreach ( $items as $i ) {
					$md .= '- **' . $i['titulo'] . '**' . ( empty( $i['area'] ) ? '' : ' _(' . $i['area'] . ')_' ) . ' — ' . $i['texto'] . "\n";
					if ( ! empty( $i['admin'] ) ) {
						$md .= '  - _Para quien administra:_ ' . $i['admin'] . "\n";
					}
				}
			}
			return $md;
		}
		return '';
	}
}
