<?php
defined( 'ABSPATH' ) || exit;

class Caaguazu_Bridge_Client {

    private string $url;
    private string $token;

    public function __construct( Caaguazu_Database $db ) {
        $this->url   = rtrim( $db->get_bridge_url(), '/' );
        $this->token = $db->get_shared_token();
    }

    public function send_message( string $to, string $message ): array {
        return $this->make_request( 'POST', '/api/send', [ 'to' => $to, 'message' => $message ] );
    }

    public function get_status(): array {
        return $this->make_request( 'GET', '/api/status' );
    }

    public function restart(): array {
        return $this->make_request( 'POST', '/api/restart' );
    }

    public function logout(): array {
        return $this->make_request( 'POST', '/api/logout' );
    }

    private function make_request( string $method, string $path, array $body = [] ): array {
        if ( empty( $this->url ) ) {
            return [ 'error' => 'URL del bridge no configurada.' ];
        }

        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => [
                'X-Caag-Token' => $this->token,
                'Content-Type' => 'application/json',
            ],
        ];

        if ( $method === 'POST' && ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $this->url . $path, $args );

        if ( is_wp_error( $response ) ) {
            error_log( '[CaagBot] Bridge error: ' . $response->get_error_message() );
            return [ 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code !== 200 ) {
            error_log( "[CaagBot] Bridge HTTP $code: $raw" );
            return [ 'error' => "HTTP $code", 'body' => $data ];
        }

        return is_array( $data ) ? $data : [ 'raw' => $raw ];
    }
}
