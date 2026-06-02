<?php
/**
 * Resolución de identidad: mapea un teléfono de WhatsApp a un usuario de
 * cead-acad vía el meta `_cead_acad_phone` (cargado por el importador de
 * alumnado). Devuelve rol/caps para la personalización mixta:
 *  - con match  → datos personalizados del alumno/staff
 *  - sin match  → modo general (info pública)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Identity {

	/**
	 * @return array{user_id:int|null,is_student:bool,is_staff:bool}
	 */
	public static function resolve( $phone ) {
		static $cache = [];
		$digits = preg_replace( '/[^0-9]/', '', (string) $phone );
		if ( $digits === '' ) {
			return [ 'user_id' => null, 'is_student' => false, 'is_staff' => false ];
		}
		if ( isset( $cache[ $digits ] ) ) {
			return $cache[ $digits ];
		}

		$uid = self::find_user_by_phone( $digits );
		if ( ! $uid ) {
			return $cache[ $digits ] = [ 'user_id' => null, 'is_student' => false, 'is_staff' => false ];
		}

		$user = get_user_by( 'id', $uid );
		$roles = $user ? (array) $user->roles : [];
		return $cache[ $digits ] = [
			'user_id'    => (int) $uid,
			'is_student' => in_array( 'cead_acad_student', $roles, true ),
			// "Staff del bot" = cualquiera con una capacidad operativa, no solo
			// dirección/secretaría (incluye docentes con publicar/agendar).
			'is_staff'   => self::has_any_staff_cap( $uid ),
		];
	}

	public static function can( $user_id, $cap ) {
		return $user_id && user_can( (int) $user_id, $cap );
	}

	private static function has_any_staff_cap( $user_id ) {
		foreach ( [ 'cead_acad_publish_broadcast', 'cead_acad_manage_schedule', 'cead_acad_manage_reports', 'cead_acad_view_metrics' ] as $cap ) {
			if ( user_can( (int) $user_id, $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Busca el usuario cuyo meta _cead_acad_phone coincide, tolerando que el
	 * número guardado tenga o no el código de país.
	 */
	private static function find_user_by_phone( $digits ) {
		// Probar coincidencias de más específica a menos (sufijos largos primero)
		// para minimizar falsos positivos. WP_Meta_Query con LIKE envuelve el
		// valor con comodines, así que comparamos por subcadena.
		$candidates = array_values( array_unique( [ $digits, ltrim( $digits, '0' ), substr( $digits, -10 ), substr( $digits, -9 ), substr( $digits, -8 ) ] ) );

		foreach ( $candidates as $needle ) {
			if ( strlen( (string) $needle ) < 6 ) {
				continue;
			}
			$found = get_users( [
				'meta_key'     => '_cead_acad_phone',
				'meta_value'   => $needle,
				'meta_compare' => 'LIKE',
				'number'       => 1,
				'fields'       => 'ID',
			] );
			if ( $found ) {
				return (int) $found[0];
			}
		}
		return 0;
	}
}
