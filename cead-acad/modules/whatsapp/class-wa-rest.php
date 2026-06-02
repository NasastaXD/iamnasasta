<?php
/**
 * Endpoints REST que consume el bridge. Contrato preservado: namespace
 * caag-bot/v1, rutas /incoming, /status, /update-bridge-url, autenticadas
 * con el header X-Caag-Token (token compartido con el bridge).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_REST {

	private $store;
	private $engine;

	public function __construct( Cead_Acad_WA_Store $store, Cead_Acad_WA_Engine $engine ) {
		$this->store  = $store;
		$this->engine = $engine;
	}

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route( 'caag-bot/v1', '/incoming', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_incoming' ],
			'permission_callback' => [ $this, 'check_token' ],
		] );
		register_rest_route( 'caag-bot/v1', '/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_status' ],
			'permission_callback' => [ $this, 'check_token' ],
		] );
		register_rest_route( 'caag-bot/v1', '/update-bridge-url', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_update_bridge_url' ],
			'permission_callback' => [ $this, 'check_token' ],
		] );
	}

	public function check_token( WP_REST_Request $request ) {
		$provided = (string) $request->get_header( 'x-caag-token' );
		$stored   = $this->store->shared_token();
		if ( $stored === '' || $provided === '' ) {
			return new WP_Error( 'cead_acad_wa_unauthorized', 'Token no configurado.', [ 'status' => 401 ] );
		}
		if ( ! hash_equals( $stored, $provided ) ) {
			return new WP_Error( 'cead_acad_wa_unauthorized', 'Token inválido.', [ 'status' => 401 ] );
		}
		return true;
	}

	public function handle_incoming( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		$from = (string) ( $body['from'] ?? '' );
		// El JID de WhatsApp llega como 5959xxxx@s.whatsapp.net.
		$phone = preg_replace( '/[^0-9]/', '', explode( '@', $from )[0] );
		$message = [
			'from'     => $phone,
			'body'     => (string) ( $body['body'] ?? '' ),
			'pushName' => (string) ( $body['pushName'] ?? '' ),
		];
		if ( $phone === '' || trim( $message['body'] ) === '' ) {
			return new WP_REST_Response( [ 'ok' => true, 'skipped' => true ], 200 );
		}
		try {
			$this->engine->process_message( $message );
		} catch ( \Throwable $e ) {
			error_log( '[CeadAcadWA] engine error: ' . $e->getMessage() );
			return new WP_REST_Response( [ 'ok' => false ], 200 );
		}
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public function handle_status( WP_REST_Request $request ) {
		$s = $this->store->session();
		return new WP_REST_Response( [
			'ok'             => true,
			'connection'     => $s->connection_status ?? 'unknown',
			'linked_number'  => $s->linked_number ?? null,
			'last_heartbeat' => $s->last_heartbeat ?? null,
		], 200 );
	}

	public function handle_update_bridge_url( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$url  = esc_url_raw( (string) ( $body['bridge_url'] ?? '' ) );
		if ( $url === '' ) {
			return new WP_Error( 'cead_acad_wa_bad_request', 'Falta bridge_url.', [ 'status' => 400 ] );
		}
		$this->store->update_session( [ 'bridge_url' => $url ] );
		return new WP_REST_Response( [ 'ok' => true, 'bridge_url' => $url ], 200 );
	}
}
