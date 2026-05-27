<?php
defined( 'ABSPATH' ) || exit;

class Caaguazu_Broadcaster {

    const JOB_OPTION  = 'caag_broadcast_jobs';
    const BATCH_EVENT = 'caag_broadcast_batch_event';
    const BATCH_SIZE  = 10;

    private Caaguazu_Database      $db;
    private Caaguazu_Bridge_Client $bridge;

    public function __construct( Caaguazu_Database $db, Caaguazu_Bridge_Client $bridge ) {
        $this->db     = $db;
        $this->bridge = $bridge;
    }

    /**
     * Resuelve los destinatarios según el target del panel y encola el envío.
     * target: 'all' | 'readers' | 'admins' | 'category'. $custom tiene prioridad.
     */
    public function enqueue_for( string $message, string $target, string $custom = '', int $category_id = 0 ): array {
        if ( $custom !== '' ) {
            $phones = array_filter(
                array_map( fn( $p ) => preg_replace( '/[^0-9]/', '', trim( $p ) ), explode( ',', $custom ) ),
                fn( $p ) => strlen( $p ) >= 7
            );
        } elseif ( $target === 'category' ) {
            // Solo se permite segmentar por categorías visibles para lectores.
            $allowed = array_map( fn( $c ) => (int) $c['id'], ( new Caaguazu_WP_Actions() )->get_categories() );
            if ( ! in_array( $category_id, $allowed, true ) ) {
                return [ 'queued' => false, 'total' => 0 ];
            }
            $phones = array_map( fn( $n ) => (string) $n->phone, $this->db->get_subscribers_by_category( $category_id ) );
        } else {
            $numbers = match ( $target ) {
                'readers' => $this->db->get_numbers_by_role( 'reader' ),
                'admins'  => $this->db->get_numbers_by_role( 'admin' ),
                default   => $this->db->get_active_numbers(),
            };
            $phones = array_map( fn( $n ) => (string) $n->phone, $numbers );
        }

        return $this->enqueue( $message, array_values( array_unique( array_filter( $phones, fn( $p ) => $p !== '' ) ) ) );
    }

    public function enqueue( string $message, array $phones ): array {
        if ( $this->is_job_active() ) {
            return [ 'queued' => false, 'busy' => true ];
        }

        $phones = array_values( $phones );
        $job    = [
            'message'    => $message,
            'phones'     => $phones,
            'cursor'     => 0,
            'sent'       => 0,
            'failed'     => 0,
            'total'      => count( $phones ),
            'status'     => count( $phones ) > 0 ? 'running' : 'done',
            'started_at' => time(),
        ];

        update_option( self::JOB_OPTION, $job, false );

        if ( $job['total'] > 0 && ! wp_next_scheduled( self::BATCH_EVENT ) ) {
            wp_schedule_single_event( time(), self::BATCH_EVENT );
            spawn_cron();
        }

        return [ 'queued' => true, 'total' => $job['total'] ];
    }

    /**
     * ¿Hay un envío realmente en curso? Un job 'running' se considera activo si tiene un
     * evento de cron programado o se inició hace menos de 5 minutos; si no, está estancado
     * y puede ser reemplazado (recuperación).
     */
    private function is_job_active(): bool {
        $job = get_option( self::JOB_OPTION, null );
        if ( ! is_array( $job ) || ( $job['status'] ?? '' ) !== 'running' ) {
            return false;
        }
        if ( wp_next_scheduled( self::BATCH_EVENT ) ) {
            return true;
        }
        $started = is_numeric( $job['started_at'] ?? null ) ? (int) $job['started_at'] : 0;
        return $started > ( time() - 5 * MINUTE_IN_SECONDS );
    }

    /** Procesa un lote y reprograma el siguiente hasta agotar la cola. */
    public function process_batch(): void {
        $job = get_option( self::JOB_OPTION, null );
        if ( ! is_array( $job ) || ( $job['status'] ?? '' ) !== 'running' ) {
            return;
        }

        $end = min( $job['cursor'] + self::BATCH_SIZE, $job['total'] );

        for ( $i = $job['cursor']; $i < $end; $i++ ) {
            $phone = (string) ( $job['phones'][ $i ] ?? '' );
            if ( $phone === '' ) {
                continue;
            }

            $result = $this->bridge->send_message( $phone, $job['message'] );

            if ( isset( $result['error'] ) ) {
                $job['failed']++;
            } else {
                $job['sent']++;
            }

            // Pausa para evitar rate limiting de WhatsApp.
            if ( $i < $end - 1 ) {
                sleep( 1 );
            }
        }

        $job['cursor'] = $end;

        if ( $job['cursor'] >= $job['total'] ) {
            $job['status'] = 'done';
            update_option( self::JOB_OPTION, $job, false );
            $this->log_broadcast( $job['message'], [ 'sent' => $job['sent'], 'failed' => $job['failed'] ] );
        } else {
            update_option( self::JOB_OPTION, $job, false );
            wp_schedule_single_event( time() + 1, self::BATCH_EVENT );
            spawn_cron();
        }
    }

    public function get_progress(): array {
        $job = get_option( self::JOB_OPTION, null );
        if ( ! is_array( $job ) ) {
            return [ 'status' => 'idle' ];
        }

        return [
            'status'    => (string) ( $job['status'] ?? 'idle' ),
            'sent'      => (int) ( $job['sent'] ?? 0 ),
            'failed'    => (int) ( $job['failed'] ?? 0 ),
            'total'     => (int) ( $job['total'] ?? 0 ),
            'processed' => (int) ( $job['cursor'] ?? 0 ),
        ];
    }

    /**
     * Si hay un job en curso sin evento de cron programado, reprograma uno. El poll del
     * panel llama a esto para destrabar colas detenidas (incluso en hosts que bloquean el
     * loopback de WP-Cron, ya que el navegador del admin actúa como disparador).
     */
    public function kick_if_stalled(): void {
        $job = get_option( self::JOB_OPTION, null );
        if ( is_array( $job ) && ( $job['status'] ?? '' ) === 'running' && ! wp_next_scheduled( self::BATCH_EVENT ) ) {
            wp_schedule_single_event( time(), self::BATCH_EVENT );
            spawn_cron();
        }
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
