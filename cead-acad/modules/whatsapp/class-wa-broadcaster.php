<?php
/**
 * Envío masivo por WhatsApp con control de volumen: job único en opción,
 * procesado en lotes por cron con pausa entre mensajes.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Broadcaster {

	const JOB_OPTION = 'cead_acad_wa_broadcast_job';
	const BATCH_SIZE = 10;

	private $store;
	private $bridge;

	public function __construct( Cead_Acad_WA_Store $store, Cead_Acad_WA_Bridge_Client $bridge ) {
		$this->store  = $store;
		$this->bridge = $bridge;
	}

	/** Resuelve los teléfonos de una audiencia del registro WA. */
	public function resolve_phones( $target ) {
		$numbers = $this->store->active_numbers();
		$phones  = [];
		foreach ( $numbers as $n ) {
			$phone = (string) $n->phone;
			if ( $phone === '' ) { continue; }
			$is_staff = $n->user_id ? cead_acad_user_is_staff( (int) $n->user_id ) : false;
			$keep = match ( $target ) {
				'staff'    => $is_staff,
				'students' => ! $is_staff,
				default    => true, // all
			};
			if ( $keep ) { $phones[] = $phone; }
		}
		return array_values( array_unique( $phones ) );
	}

	public function count_for( $target ) {
		return count( $this->resolve_phones( $target ) );
	}

	public function enqueue_for( $message, $target ) {
		return $this->enqueue( $message, $this->resolve_phones( $target ) );
	}

	public function enqueue( $message, array $phones ) {
		if ( $this->is_active() ) {
			return [ 'queued' => false, 'busy' => true ];
		}
		$phones = array_values( array_filter( $phones, fn( $p ) => $p !== '' ) );
		$job = [
			'message' => $message,
			'phones'  => $phones,
			'cursor'  => 0,
			'sent'    => 0,
			'failed'  => 0,
			'total'   => count( $phones ),
			'status'  => count( $phones ) > 0 ? 'running' : 'done',
			'started' => time(),
		];
		update_option( self::JOB_OPTION, $job, false );
		if ( $job['total'] > 0 && ! wp_next_scheduled( Cead_Acad_WA_Cron::BROADCAST_EVENT ) ) {
			wp_schedule_single_event( time(), Cead_Acad_WA_Cron::BROADCAST_EVENT );
			spawn_cron();
		}
		return [ 'queued' => true, 'total' => $job['total'] ];
	}

	private function is_active() {
		$job = get_option( self::JOB_OPTION, null );
		if ( ! is_array( $job ) || ( $job['status'] ?? '' ) !== 'running' ) {
			return false;
		}
		if ( wp_next_scheduled( Cead_Acad_WA_Cron::BROADCAST_EVENT ) ) {
			return true;
		}
		$started = (int) ( $job['started'] ?? 0 );
		return $started > ( time() - 5 * MINUTE_IN_SECONDS );
	}

	public function process_batch() {
		$job = get_option( self::JOB_OPTION, null );
		if ( ! is_array( $job ) || ( $job['status'] ?? '' ) !== 'running' ) {
			return;
		}
		$end = min( $job['cursor'] + self::BATCH_SIZE, $job['total'] );
		for ( $i = $job['cursor']; $i < $end; $i++ ) {
			$phone = (string) ( $job['phones'][ $i ] ?? '' );
			if ( $phone === '' ) { continue; }
			$result = $this->bridge->send_message( $phone, $job['message'] );
			if ( isset( $result['error'] ) ) { $job['failed']++; } else { $job['sent']++; }
			if ( $i < $end - 1 ) { sleep( 1 ); }
		}
		$job['cursor'] = $end;
		if ( $job['cursor'] >= $job['total'] ) {
			$job['status'] = 'done';
			update_option( self::JOB_OPTION, $job, false );
		} else {
			update_option( self::JOB_OPTION, $job, false );
			wp_schedule_single_event( time() + 1, Cead_Acad_WA_Cron::BROADCAST_EVENT );
			spawn_cron();
		}
	}

	public function progress() {
		$job = get_option( self::JOB_OPTION, null );
		if ( ! is_array( $job ) ) {
			return [ 'status' => 'idle' ];
		}
		return [
			'status' => (string) ( $job['status'] ?? 'idle' ),
			'sent'   => (int) ( $job['sent'] ?? 0 ),
			'failed' => (int) ( $job['failed'] ?? 0 ),
			'total'  => (int) ( $job['total'] ?? 0 ),
		];
	}
}
