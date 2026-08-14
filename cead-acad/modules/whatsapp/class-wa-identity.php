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

	/** El teléfono como lo escribió la persona. Es lo que se muestra en su ficha. */
	const PHONE_META = '_cead_acad_phone';

	/**
	 * El mismo teléfono en forma canónica (E.164 sin '+'). Es la LLAVE con la
	 * que se busca, y existe porque el visible no sirve para eso: «0981 111 111»
	 * y «+595981111111» son la misma persona y no se parecen como texto.
	 */
	const PHONE_KEY_META = '_cead_acad_phone_e164';

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
		// Cuenta suspendida: el bot la trata como no registrada (sin datos
		// personalizados ni permisos), igual que bloquea el login en el sitio.
		if ( class_exists( 'Cead_Acad_User_Suspension' ) && Cead_Acad_User_Suspension::is_suspended( $uid ) ) {
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
	 * ¿Este número ya está en la ficha de OTRA persona?
	 *
	 * Existe para que el alta pueda rechazar un teléfono repetido, y a propósito
	 * delega en la MISMA búsqueda que usa CEADI para reconocer a quien escribe.
	 * Escribir acá una comparación propia sería el error clásico de este
	 * proyecto: dos criterios que arrancan iguales, uno se ajusta y el otro no, y
	 * el alta empieza a aceptar números que el bot después resuelve mal. Mientras
	 * sea la misma función, «lo que el alta acepta» y «lo que el bot encuentra»
	 * no pueden opinar distinto.
	 *
	 * @param string $raw     Teléfono tal como lo tipearon.
	 * @param int    $excluir Usuario a ignorar (al editar, uno mismo).
	 * @return int ID del dueño actual del número, o 0 si está libre.
	 */
	public static function phone_taken_by( $raw, $excluir = 0 ) {
		$uid = self::find_user_by_phone( $raw );
		return ( $uid && $uid !== (int) $excluir ) ? $uid : 0;
	}

	/**
	 * Guarda el teléfono de una persona por las dos puertas a la vez.
	 *
	 * `_cead_acad_phone` conserva lo que la persona escribió —«0981 111 111» se
	 * lee así en Paraguay y así tiene que verse en su ficha— y
	 * `_cead_acad_phone_e164` guarda la forma canónica, que es con la que se
	 * busca. Son el mismo dato con dos trabajos distintos: uno es para leer y el
	 * otro es una LLAVE.
	 *
	 * Todo lo que escriba el teléfono tiene que pasar por acá. Si una puerta
	 * escribe solo el visible, esa persona queda invisible para el bot.
	 */
	public static function store_phone( $user_id, $raw ) {
		$user_id = (int) $user_id;
		$raw     = trim( (string) $raw );

		update_user_meta( $user_id, self::PHONE_META, $raw );

		$clave = self::normalize_phone( $raw );
		if ( '' === $clave || strlen( $clave ) < 8 ) {
			delete_user_meta( $user_id, self::PHONE_KEY_META );
			return '';
		}
		update_user_meta( $user_id, self::PHONE_KEY_META, $clave );
		return $clave;
	}

	/**
	 * Busca el usuario por teléfono.
	 *
	 * Va por igualdad exacta sobre la forma canónica. La versión anterior hacía
	 * un LIKE sobre el número tal como estaba escrito, buscando sus últimos ocho
	 * dígitos, y decía tolerar espacios y guiones — no los toleraba: en
	 * «+595 981 111111» esos ocho dígitos no aparecen seguidos, así que el LIKE
	 * no encontraba nada. Quien escribía su número separado se registraba bien y
	 * después el bot no lo reconocía NUNCA, con el número viéndose correcto en su
	 * ficha. Un dato que es una llave no puede buscarse por como se ve.
	 *
	 * El rastreo por el meta viejo queda como red para las fichas que todavía no
	 * pasaron por la migración, y de paso las repara al encontrarlas.
	 */
	private static function find_user_by_phone( $raw ) {
		$n = self::normalize_phone( $raw );
		if ( strlen( $n ) < 8 ) {
			return 0;
		}

		$exactos = get_users( [
			'meta_key'   => self::PHONE_KEY_META,
			'meta_value' => $n,
			'number'     => 1,
			'fields'     => 'ID',
		] );
		if ( $exactos ) {
			return (int) $exactos[0];
		}

		/*
		 * La red solo hace falta mientras haya fichas sin migrar. Una vez que la
		 * migración pasó, toda ficha con teléfono tiene su clave —`store_phone()`
		 * se encarga de las nuevas—, así que no encontrarla por la clave ya
		 * significa que no está. Sin este corte, cada mensaje de un número
		 * desconocido (que son la mayoría: cualquiera puede escribirle al bot)
		 * recorrería el padrón entero para no encontrar nada.
		 */
		if ( function_exists( 'get_option' ) && get_option( 'cead_acad_phone_keys_backfilled' ) ) {
			return 0;
		}

		return self::find_legacy( $n );
	}

	/**
	 * Red para fichas sin la clave canónica todavía escrita.
	 *
	 * Compara NORMALIZANDO cada ficha, sin filtrar antes por SQL. Filtrar con un
	 * LIKE sobre los últimos dígitos parece la optimización obvia y es
	 * justamente el error original: cualquier separador parte la cadena que se
	 * busca, así que «0981 111 111» no contiene ni «81111111» ni «1111», y el
	 * candidato correcto queda afuera antes de que nadie lo compare. Un dato que
	 * puede venir con cualquier formato no se puede preseleccionar por su forma.
	 *
	 * El costo se acota por afuera: esto solo corre mientras la migración no
	 * pasó (ver `find_user_by_phone()`), no en cada mensaje para siempre.
	 *
	 * Al acertar escribe la clave: esa ficha queda migrada sola.
	 */
	private static function find_legacy( $n ) {
		$candidatos = get_users( [
			'meta_key'     => self::PHONE_META,
			'meta_value'   => '',
			'meta_compare' => '!=',
			'number'       => -1,
			'fields'       => 'ID',
		] );
		foreach ( $candidatos as $uid ) {
			$stored = (string) get_user_meta( (int) $uid, self::PHONE_META, true );
			if ( self::normalize_phone( $stored ) === $n ) {
				update_user_meta( (int) $uid, self::PHONE_KEY_META, $n );
				return (int) $uid;
			}
		}
		return 0;
	}

	/**
	 * Escribe la clave canónica de todas las fichas que tengan teléfono.
	 *
	 * Corre una vez por cambio de versión. La red de `find_legacy()` cubre el
	 * caso de a uno, pero solo cuando esa persona escribe; esto deja al padrón
	 * entero consultable de entrada, que es lo que hace falta para que un envío
	 * masivo no saltee gente.
	 *
	 * @return int Cuántas fichas se migraron.
	 */
	public static function backfill_phone_keys() {
		$usuarios = get_users( [
			'meta_key'     => self::PHONE_META,
			'meta_value'   => '',
			'meta_compare' => '!=',
			'number'       => -1,
			'fields'       => 'ID',
		] );

		$hechos = 0;
		foreach ( $usuarios as $uid ) {
			$uid = (int) $uid;
			if ( '' !== (string) get_user_meta( $uid, self::PHONE_KEY_META, true ) ) {
				continue;
			}
			$clave = self::normalize_phone( (string) get_user_meta( $uid, self::PHONE_META, true ) );
			if ( '' !== $clave && strlen( $clave ) >= 8 ) {
				update_user_meta( $uid, self::PHONE_KEY_META, $clave );
				$hechos++;
			}
		}
		return $hechos;
	}
}
