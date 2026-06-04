<?php
/**
 * Cliente saliente hacia el bridge Baileys. Preserva el contrato existente:
 * POST {bridge_url}/api/send|status|restart|logout con header X-Caag-Token.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Bridge_Client {

	private $store;

	public function __construct( Cead_Acad_WA_Store $store ) {
		$this->store = $store;
	}

	public function send_message( $to, $message ) {
		return $this->request( 'POST', '/api/send', [ 'to' => $to, 'message' => $message ] );
	}

	public function send_image( $to, $image_base64, $mime, $caption = '' ) {
		return $this->request( 'POST', '/api/send-image', [
			'to'           => $to,
			'image_base64' => $image_base64,
			'mime'         => $mime,
			'caption'      => $caption,
		] );
	}

	/** Edita un mensaje ya enviado (Baileys: { text, edit: key }). */
	public function edit_message( $to, $message, $msg_id ) {
		return $this->request( 'POST', '/api/edit', [ 'to' => $to, 'message' => $message, 'msg_id' => $msg_id ] );
	}

	/** Actualiza el perfil del bot: nombre visible y/o descripción ("info"). */
	public function set_profile( $name = null, $about = null ) {
		$body = [];
		if ( $name !== null )  { $body['name'] = $name; }
		if ( $about !== null ) { $body['about'] = $about; }
		return $this->request( 'POST', '/api/profile', $body );
	}

	/** Actualiza la foto de perfil del bot (imagen en base64). */
	public function set_profile_picture( $image_base64 ) {
		return $this->request( 'POST', '/api/profile-picture', [ 'image_base64' => $image_base64 ] );
	}

	public function status()  { return $this->request( 'GET',  '/api/status' ); }
	public function restart() { return $this->request( 'POST', '/api/restart' ); }
	public function logout()  { return $this->request( 'POST', '/api/logout' ); }

	private function request( $method, $path, $body = [] ) {
		$url = rtrim( $this->store->bridge_url(), '/' );
		if ( $url === '' ) {
			return [ 'error' => 'URL del bridge no configurada.' ];
		}
		$args = [
			'method'  => $method,
			'timeout' => 15,
			'headers' => [
				'X-Caag-Token' => $this->store->shared_token(),
				'Content-Type' => 'application/json',
			],
		];
		if ( $method === 'POST' && ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url . $path, $args );
		if ( is_wp_error( $response ) ) {
			error_log( '[CeadAcadWA] bridge error: ' . $response->get_error_message() );
			return [ 'error' => $response->get_error_message() ];
		}
		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code !== 200 ) {
			return [ 'error' => "HTTP $code", 'body' => $data ];
		}
		return is_array( $data ) ? $data : [ 'raw' => $raw ];
	}
}
