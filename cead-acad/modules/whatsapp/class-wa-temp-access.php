<?php
/**
 * Acceso temporal al bot mediante una clave de un día.
 *
 * Pensado para jornadas puntuales (una presentación, una inscripción abierta)
 * donde alguien necesita operar CEADI sin tener su número cargado todavía.
 *
 * Está deliberadamente acotado:
 *  - la clave se guarda HASHEADA, nunca en claro ni en el código;
 *  - solo funciona dentro de una ventana de fechas que se define a mano;
 *  - tiene un tope de usos y cada sesión dura pocos minutos;
 *  - no inventa permisos: presta la identidad de un usuario real ya existente,
 *    así todo lo que se haga queda auditado a nombre de alguien;
 *  - los intentos fallidos están limitados y quedan registrados.
 *
 * Se apaga sola al vencer la ventana o al agotarse los usos, y se puede cortar
 * al instante desde el panel.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Temp_Access {

	const OPT_HASH   = 'cead_acad_wa_temp_hash';
	const OPT_UNTIL  = 'cead_acad_wa_temp_until';   // 'Y-m-d H:i:s' local
	const OPT_MAX    = 'cead_acad_wa_temp_max';
	const OPT_USED   = 'cead_acad_wa_temp_used';
	const OPT_USER   = 'cead_acad_wa_temp_user';    // identidad prestada
	const OPT_MIN    = 'cead_acad_wa_temp_minutes';

	/** Duración de cada sesión concedida, en minutos. */
	public static function minutes() {
		$m = (int) get_option( self::OPT_MIN, 15 );
		return ( $m >= 1 && $m <= 240 ) ? $m : 15;
	}

	public static function max_uses() {
		$m = (int) get_option( self::OPT_MAX, 5 );
		return ( $m >= 1 && $m <= 100 ) ? $m : 5;
	}

	public static function used() {
		return max( 0, (int) get_option( self::OPT_USED, 0 ) );
	}

	public static function uses_left() {
		return max( 0, self::max_uses() - self::used() );
	}

	/** Momento en que deja de servir ('' si no está configurada). */
	public static function until() {
		return (string) get_option( self::OPT_UNTIL, '' );
	}

	/** Usuario cuya identidad se presta. */
	public static function user_id() {
		return (int) get_option( self::OPT_USER, 0 );
	}

	/** ¿La clave está configurada y todavía sirve? */
	public static function active() {
		if ( '' === (string) get_option( self::OPT_HASH, '' ) ) { return false; }
		if ( ! self::user_id() ) { return false; }
		if ( self::uses_left() < 1 ) { return false; }
		$until = self::until();
		if ( '' === $until ) { return false; }
		return current_time( 'timestamp' ) < strtotime( $until );
	}

	/** Motivo por el que no está activa, para mostrarlo en el panel. */
	public static function status_label() {
		if ( '' === (string) get_option( self::OPT_HASH, '' ) ) {
			return __( 'sin configurar', 'cead-acad' );
		}
		if ( ! self::user_id() ) {
			return __( 'falta elegir a quién le presta la identidad', 'cead-acad' );
		}
		if ( self::uses_left() < 1 ) {
			return __( 'usos agotados', 'cead-acad' );
		}
		$until = self::until();
		if ( '' === $until || current_time( 'timestamp' ) >= strtotime( $until ) ) {
			return __( 'vencida', 'cead-acad' );
		}
		/* translators: 1: usos restantes, 2: fecha de vencimiento */
		return sprintf( __( 'activa · %1$d uso/s · hasta %2$s', 'cead-acad' ), self::uses_left(), $until );
	}

	/* ------------------------------------------------------------- guardado */

	/** Guarda la clave (hasheada) y su ventana. Pasar '' deja la clave actual. */
	public static function configure( $plain, $until, $max, $user_id, $minutes = 15 ) {
		$plain = (string) $plain;
		if ( '' !== $plain ) {
			update_option( self::OPT_HASH, wp_hash_password( $plain ), false );
			update_option( self::OPT_USED, 0, false ); // clave nueva, contador limpio
		}
		update_option( self::OPT_UNTIL, sanitize_text_field( (string) $until ), false );
		update_option( self::OPT_MAX, max( 1, min( 100, (int) $max ) ), false );
		update_option( self::OPT_USER, (int) $user_id, false );
		update_option( self::OPT_MIN, max( 1, min( 240, (int) $minutes ) ), false );

		Cead_Acad_Audit::log( 'wa_temp_access_configured', [
			'payload' => [ 'until' => (string) $until, 'max' => (int) $max, 'user' => (int) $user_id ],
		] );
	}

	/** Corta todo al instante: borra la clave y las sesiones vigentes. */
	public static function revoke() {
		delete_option( self::OPT_HASH );
		update_option( self::OPT_UNTIL, '', false );
		update_option( self::OPT_USED, 0, false );
		Cead_Acad_Audit::log( 'wa_temp_access_revoked' );
	}

	/** Resultado de la comparación dentro de esta petición (un mensaje = una). */
	protected static $checked = [];

	/**
	 * Descarte barato antes de tocar el hash. Verificar una contraseña es lento
	 * a propósito, y con el bot abierto al público hacerlo en cada mensaje sería
	 * una forma fácil de saturarlo. Una clave no lleva espacios ni saltos de
	 * línea, así que casi todos los mensajes se descartan acá.
	 */
	protected static function plausible( $text ) {
		$len = strlen( $text );
		if ( $len < 6 || $len > 64 ) { return false; }
		return ! preg_match( '/\s/', $text );
	}

	/** Compara contra el hash una sola vez por petición. */
	protected static function matches( $text ) {
		if ( isset( self::$checked[ $text ] ) ) { return self::$checked[ $text ]; }
		$hash = (string) get_option( self::OPT_HASH, '' );
		$ok   = ( '' !== $hash ) && wp_check_password( $text, $hash );
		self::$checked[ $text ] = $ok;
		return $ok;
	}

	/**
	 * ¿Este texto es la clave? Solo para decidir si hay que ocultarlo del
	 * historial; no concede nada.
	 */
	public static function looks_like_key( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text || ! self::plausible( $text ) ) { return false; }
		if ( '' === (string) get_option( self::OPT_HASH, '' ) ) { return false; }
		return self::matches( $text );
	}

	/* -------------------------------------------------------------- sesión */

	protected static function key( $phone ) {
		return 'cead_acad_wa_temp_' . md5( (string) $phone );
	}

	/** user_id vigente para ese número, o 0. */
	public static function granted_user( $phone ) {
		$uid = (int) get_transient( self::key( $phone ) );
		return $uid > 0 ? $uid : 0;
	}

	/**
	 * ¿El texto es la clave? Si sí, concede la sesión y descuenta un uso.
	 * Devuelve true solo cuando efectivamente concedió algo.
	 */
	public static function attempt( $phone, $text ) {
		$text = trim( (string) $text );
		if ( '' === $text || ! self::plausible( $text ) || ! self::active() ) { return false; }

		// Ya tiene sesión: no gastar otro uso.
		if ( self::granted_user( $phone ) ) { return false; }

		// Freno a la prueba de claves: pocos intentos por número.
		$tries_key = 'cead_acad_wa_temptry_' . md5( (string) $phone );
		$tries     = (int) get_transient( $tries_key );
		if ( $tries >= 5 ) { return false; }

		if ( ! self::matches( $text ) ) {
			set_transient( $tries_key, $tries + 1, 15 * MINUTE_IN_SECONDS );
			return false;
		}

		$uid = self::user_id();
		if ( ! $uid || ! get_user_by( 'id', $uid ) ) { return false; }

		set_transient( self::key( $phone ), $uid, self::minutes() * MINUTE_IN_SECONDS );
		update_option( self::OPT_USED, self::used() + 1, false );
		delete_transient( $tries_key );

		Cead_Acad_Audit::log( 'wa_temp_access_granted', [
			'user_id' => $uid,
			'payload' => [ 'phone' => (string) $phone, 'minutos' => self::minutes(), 'restantes' => self::uses_left() ],
		] );
		return true;
	}
}
