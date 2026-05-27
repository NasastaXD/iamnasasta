<?php
defined( 'ABSPATH' ) || exit;

class Caaguazu_Broadcaster {

    private Caaguazu_Database      $db;
    private Caaguazu_Bridge_Client $bridge;

    public function __construct( Caaguazu_Database $db, Caaguazu_Bridge_Client $bridge ) {
        $this->db     = $db;
        $this->bridge = $bridge;
    }

    /**
     * Envía un mensaje a todos los números del rol indicado.
     * role_filter: '' = todos, 'reader' = solo lectores, 'admin' = solo admins.
     */
    public function send_to_all( string $message, string $role_filter = '' ): array {
        $numbers = $role_filter !== ''
            ? $this->db->get_numbers_by_role( $role_filter )
            : $this->db->get_all_numbers();

        $phones = array_filter(
            array_map( fn( $n ) => (string) $n->phone, $numbers ),
            fn( $p ) => $p !== ''
        );

        return $this->send_to_numbers( $message, array_values( $phones ) );
    }

    public function send_to_numbers( string $message, array $phones ): array {
        $sent   = 0;
        $failed = 0;
        $errors = [];

        foreach ( $phones as $phone ) {
            $result = $this->bridge->send_message( $phone, $message );

            if ( isset( $result['error'] ) ) {
                $failed++;
                $errors[] = "$phone: {$result['error']}";
            } else {
                $sent++;
                $this->db->log_message( $phone, 'out', $message, 'broadcast' );
            }

            // Pausa para evitar rate limiting de WhatsApp
            sleep( 1 );
        }

        $this->log_broadcast( $message, [ 'sent' => $sent, 'failed' => $failed ] );

        return [ 'sent' => $sent, 'failed' => $failed, 'errors' => $errors ];
    }

    private function log_broadcast( string $message, array $result ): void {
        $log   = get_option( 'caag_broadcast_log', [] );
        $log[] = [
            'date'    => current_time( 'mysql' ),
            'message' => substr( $message, 0, 100 ),
            'sent'    => $result['sent'],
            'failed'  => $result['failed'],
        ];

        // Mantener solo los últimos 50 registros
        if ( count( $log ) > 50 ) {
            $log = array_slice( $log, -50 );
        }

        update_option( 'caag_broadcast_log', $log, false );
    }
}
