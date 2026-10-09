<?php
/**
 * Los avisos al teléfono, desde el lado de la app: registrar el dispositivo,
 * darlo de baja, elegir qué avisos recibir y probar que llegan.
 *
 * El dispositivo se registra desde la sesión de la app y queda atado a ella
 * (ver `Cead_Acad_Push`): un registro hecho con cookies de la web no tiene
 * sesión a la que atarse, y se rechaza.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_API_Push {

	/** Pruebas por persona y por minuto: cada una es una llamada a Firebase. */
	const PRUEBAS_MINUTO = 3;

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'rutas' ] );
	}

	public function rutas() {
		$ns   = Cead_Acad_API::NS;
		$gate = Cead_Acad_API::gate();

		$r = static function ( $ruta, $metodo, $cb, $args = [] ) use ( $ns, $gate ) {
			register_rest_route( $ns, $ruta, [
				'methods'             => $metodo,
				'callback'            => $cb,
				'permission_callback' => $gate,
				'args'                => $args,
			] );
		};

		$r( '/dispositivos', 'POST', [ $this, 'registrar' ], [
			'token'      => [ 'required' => true, 'type' => 'string' ],
			'plataforma' => [ 'required' => true, 'type' => 'string', 'enum' => [ 'android', 'ios' ] ],
			'nombre'     => [ 'required' => false, 'type' => 'string', 'default' => '' ],
		] );
		$r( '/dispositivos/baja', 'POST', [ $this, 'baja' ], [
			'token' => [ 'required' => true, 'type' => 'string' ],
		] );
		$r( '/dispositivos/prueba', 'POST', [ $this, 'prueba' ] );

		$r( '/notificaciones/preferencias', 'GET', [ $this, 'preferencias' ] );
		$r( '/notificaciones/preferencias', 'POST', [ $this, 'guardar_preferencias' ], [
			'preferencias' => [ 'required' => true, 'type' => 'object' ],
		] );
	}

	public function registrar( $req ) {
		$r = Cead_Acad_Push::registrar(
			get_current_user_id(),
			(string) $req->get_param( 'token' ),
			(string) $req->get_param( 'plataforma' ),
			Cead_Acad_API::jti_actual(),
			(string) $req->get_param( 'nombre' )
		);
		return is_wp_error( $r ) ? $r : rest_ensure_response( [ 'ok' => true ] );
	}

	public function baja( $req ) {
		Cead_Acad_Push::dar_de_baja( get_current_user_id(), (string) $req->get_param( 'token' ) );
		return rest_ensure_response( [ 'ok' => true ] );
	}

	/**
	 * Manda un aviso a los teléfonos de quien lo pide. Sirve para comprobar que
	 * Firebase quedó bien configurado sin tener que publicar nada.
	 */
	public function prueba() {
		$uid   = get_current_user_id();
		$clave = 'cead_acad_push_prueba_' . $uid . '_' . (int) floor( time() / MINUTE_IN_SECONDS );
		$n     = (int) get_transient( $clave );
		if ( $n >= self::PRUEBAS_MINUTO ) {
			return new WP_Error( 'muchas', __( 'Esperá un momento antes de volver a probar.', 'cead-acad' ), [ 'status' => 429 ] );
		}
		set_transient( $clave, $n + 1, 2 * MINUTE_IN_SECONDS );
		return rest_ensure_response( Cead_Acad_Push::prueba( $uid ) );
	}

	public function preferencias() {
		return rest_ensure_response( [ 'preferencias' => Cead_Acad_Push::preferencias( get_current_user_id() ) ] );
	}

	public function guardar_preferencias( $req ) {
		$nuevas = $req->get_param( 'preferencias' );
		return rest_ensure_response( [
			'preferencias' => Cead_Acad_Push::guardar_preferencias( get_current_user_id(), is_array( $nuevas ) ? $nuevas : [] ),
		] );
	}
}
