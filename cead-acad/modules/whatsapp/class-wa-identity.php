<?php
/**
 * Resolución de identidad: mapea un teléfono de WhatsApp a un usuario de
 * cead-acad vía el meta `_cead_acad_phone`. Personalización mixta:
 *  - con match  → datos personalizados del alumno/staff
 *  - sin match  → modo general (info pública)
 *
 * El match es por número NORMALIZADO a E.164 (exacto), no por subcadena, para
 * evitar falsos positivos que darían identidad/permisos equivocados.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Identity {

	/**
	 * @return array{user_id:int|null,is_student:bool,is_staff:bool}
	 */
	public static function resolve( $phone ) {
		static $cache = [];
		$n = self::normalize_phone( $phone );
		if ( $n === '' ) {
			return [ 'user_id' => null, 'is_student' => false, 'is_staff' => false ];
		}
		if ( isset( $cache[ $n ] ) ) {
			return $cache[ $n ];
		}

		$uid = self::find_user_by_phone( $phone );
		if ( ! $uid ) {
			return $cache[ $n ] = [ 'user_id' => null, 'is_student' => false, 'is_staff' => false ];
		}

		$user  = get_user_by( 'id', $uid );
		$roles = $user ? (array) $user->roles : [];
		return $cache[ $n ] = [
			'user_id'    => (int) $uid,
			'is_student' => in_array( 'cead_acad_student', $roles, true ),
			// "Staff del bot" = cualquiera con una capacidad operativa.
			'is_staff'   => self::has_any_staff_cap( $uid ),
		];
	}

	public static function can( $user_id, $cap ) {
		return $user_id && user_can( (int) $user_id, $cap );
	}

	private static function has_any_staff_cap( $user_id ) {
		foreach ( [ 'cead_acad_publish_broadcast', 'cead_acad_manage_schedule', 'cead_acad_manage_reports', 'cead_acad_view_metrics', 'cead_acad_manage_articles', 'cead_acad_manage_roles' ] as $cap ) {
			if ( user_can( (int) $user_id, $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normaliza un número a dígitos E.164 (sin '+') usando el código de país del
	 * colegio. Función pura (si se pasa $cc no toca WordPress) → testeable.
	 */
	public static function normalize_phone( $raw, $cc = null ) {
		if ( $cc === null ) {
			$cc = function_exists( 'get_option' ) ? (string) get_option( 'cead_acad_wa_country_code', '595' ) : '595';
		}
		$cc = preg_replace( '/\D/', '', (string) $cc );
		if ( $cc === '' ) { $cc = '595'; }
		$d = preg_replace( '/\D/', '', (string) $raw );
		if ( $d === '' ) {
			return '';
		}
		if ( str_starts_with( $d, '00' ) ) {           // prefijo internacional 00
			$d = substr( $d, 2 );
		}
		if ( str_starts_with( $d, $cc ) ) {            // ya trae código de país
			return $d;
		}
		if ( str_starts_with( $d, '0' ) ) {            // local con 0 inicial → +cc
			return $cc . substr( $d, 1 );
		}
		return $cc . $d;                               // local sin 0 → +cc
	}

	/**
	 * Busca el usuario por teléfono: estrecha con LIKE sobre los últimos dígitos
	 * (tolera formatos guardados con espacios/guiones) y CONFIRMA con igualdad
	 * exacta del número normalizado (elimina falsos positivos por subcadena).
	 */
	private static function find_user_by_phone( $raw ) {
		$n = self::normalize_phone( $raw );
		if ( strlen( $n ) < 8 ) {
			return 0;
		}
		$cc       = preg_replace( '/\D/', '', (string) ( function_exists( 'get_option' ) ? get_option( 'cead_acad_wa_country_code', '595' ) : '595' ) ) ?: '595';
		$national = str_starts_with( $n, $cc ) ? substr( $n, strlen( $cc ) ) : $n;
		$needle   = strlen( $national ) >= 8 ? substr( $national, -8 ) : $national;

		$candidates = get_users( [
			'meta_key'     => '_cead_acad_phone',
			'meta_value'   => $needle,
			'meta_compare' => 'LIKE',
			'number'       => 25,
			'fields'       => 'ID',
		] );
		foreach ( $candidates as $uid ) {
			$stored = (string) get_user_meta( (int) $uid, '_cead_acad_phone', true );
			if ( self::normalize_phone( $stored ) === $n ) {
				return (int) $uid;
			}
		}
		return 0;
	}
}
