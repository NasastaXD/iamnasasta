<?php
defined( 'ABSPATH' ) || exit;

class Caaguazu_Rest_Handler {

    private Caaguazu_Database   $db;
    private Caaguazu_Bot_Engine $engine;
    private Caaguazu_Bridge_Client $bridge;

    public function __construct(
        Caaguazu_Database $db,
        Caaguazu_Bot_Engine $engine,
        Caaguazu_Bridge_Client $bridge
    ) {
        $this->db     = $db;
        $this->engine = $engine;
        $this->bridge = $bridge;
    }

    public function register_routes(): void {
        register_rest_route( 'caag-bot/v1', '/incoming', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_incoming' ],
            'permission_callback' => [ $this, 'validate_token' ],
        ] );

        register_rest_route( 'caag-bot/v1', '/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_status' ],
            'permission_callback' => [ $this, 'validate_token' ],
        ] );

        register_rest_route( 'caag-bot/v1', '/update-bridge-url', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_update_bridge_url' ],
            'permission_callback' => [ $this, 'validate_token' ],
        ] );
    }

    public function validate_token( WP_REST_Request $request ): bool {
        $stored   = $this->db->get_shared_token();
        $provided = $request->get_header( 'X-Caag-Token' );

        if ( empty( $stored ) || empty( $provided ) ) {
            return false;
        }

        return hash_equals( $stored, $provided );
    }

    public function handle_incoming( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();

        if ( empty( $body['from'] ) || ! isset( $body['body'] ) ) {
            return new WP_REST_Response( [ 'code' => 'bad_request', 'message' => 'Campos requeridos: from, body' ], 400 );
        }

        try {
            $this->engine->process_message( [
                'from'     => (string) $body['from'],
                'body'     => (string) $body['body'],
                'pushName' => (string) ( $body['pushName'] ?? '' ),
                'timestamp'=> (int) ( $body['timestamp'] ?? time() ),
            ] );
        } catch ( \Throwable $e ) {
            error_log( '[CaagBot] process_message exception: ' . $e->getMessage() );
            // No re-lanzamos: el bridge no debe reenviar el mensaje
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }

    public function handle_update_bridge_url( WP_REST_Request $request ): WP_REST_Response {
        $body       = $request->get_json_params();
        $bridge_url = esc_url_raw( (string) ( $body['bridge_url'] ?? '' ) );

        if ( empty( $bridge_url ) ) {
            return new WP_REST_Response( [ 'code' => 'bad_request', 'message' => 'bridge_url requerido' ], 400 );
        }

        $this->db->update_session( [ 'bridge_url' => $bridge_url ] );

        return new WP_REST_Response( [ 'updated' => true, 'bridge_url' => $bridge_url ], 200 );
    }

    public function handle_status( WP_REST_Request $request ): WP_REST_Response {
        $status = $this->bridge->get_status();

        if ( ! isset( $status['error'] ) ) {
            $this->db->update_session( [
                'connection_status' => $status['connected'] ? 'connected' : 'disconnected',
                'linked_number'     => $status['number'] ?? null,
                'qr_data'           => $status['qr'] ?? null,
                'last_heartbeat'    => current_time( 'mysql' ),
            ] );
        }

        $session = $this->db->get_session();

        return new WP_REST_Response( [
            'connected'      => $status['connected'] ?? false,
            'number'         => $status['number']    ?? null,
            'qr'             => $status['qr']        ?? null,
            'last_heartbeat' => $session->last_heartbeat ?? null,
        ], 200 );
    }
}
