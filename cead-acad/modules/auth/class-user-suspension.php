<?php
/**
 * Suspensión de usuarios: bloquea el login sin borrar datos (alternativa
 * reversible al borrado). Marca por user meta; al suspender se destruyen
 * las sesiones activas del usuario.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_User_Suspension {

	const META = '_cead_acad_suspended';

	public function boot() {
		// Prioridad 30: corre después de la validación de credenciales de core
		// (20), así el mensaje de suspensión solo se muestra con password válida
		// y no filtra información ante intentos con credenciales incorrectas.
		add_filter( 'authenticate', [ __CLASS__, 'block_suspended' ], 30, 1 );

		// Defensa en profundidad: además de destruir las sesiones al suspender,
		// re-chequeamos en cada request. Cubre cualquier camino que setee la
		// cookie sin pasar por authenticate (registro, application passwords).
		add_action( 'init', [ __CLASS__, 'enforce_on_request' ], 1 );
	}

	public static function enforce_on_request() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$uid = get_current_user_id();
		// Nunca expulsamos a un administrador (no se los puede suspender desde la
		// UI; este guard evita lockouts por meta huérfana).
		if ( user_can( $uid, 'manage_options' ) || ! self::is_suspended( $uid ) ) {
			return;
		}
		wp_logout();
		if ( ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! is_admin() ) {
			wp_safe_redirect( add_query_arg( 'err', 'suspended', cead_acad_url( 'login' ) ) );
			exit;
		}
	}

	public static function block_suspended( $user ) {
		if ( $user instanceof WP_User && self::is_suspended( $user->ID ) ) {
			return new WP_Error(
				'cead_acad_suspended',
				__( 'Tu cuenta está suspendida. Contactá a la secretaría del CEAD.', 'cead-acad' )
			);
		}
		return $user;
	}

	public static function is_suspended( $user_id ) {
		return (bool) get_user_meta( (int) $user_id, self::META, true );
	}

	public static function suspend( $user_id ) {
		$user_id = (int) $user_id;
		update_user_meta( $user_id, self::META, time() );

		// Cerrar las sesiones abiertas: la suspensión aplica de inmediato.
		$sessions = WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();

		Cead_Acad_Audit::log( 'user_suspended', [
			'entity_type' => 'user',
			'entity_id'   => $user_id,
		] );
	}

	public static function reactivate( $user_id ) {
		$user_id = (int) $user_id;
		delete_user_meta( $user_id, self::META );

		Cead_Acad_Audit::log( 'user_reactivated', [
			'entity_type' => 'user',
			'entity_id'   => $user_id,
		] );
	}
}
