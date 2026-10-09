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

	/**
	 * Crea el comunicado como post `cead_acad_broadcast` (visible en el panel web
	 * y en "Comunicados" del bot) con la audiencia mapeada. Estático para que lo
	 * usen tanto el motor del bot como el panel de wp-admin.
	 */
	/**
	 * @param string $titulo Título propio para el panel web. Vacío = se
	 *                       deriva del mensaje (lo que hacían siempre los
	 *                       comunicados sin IA — un aviso corto de un par de
	 *                       líneas queda bien así; solo se nota cuando el
	 *                       mensaje es largo y el recorte cae a la mitad de
	 *                       una frase, que es exactamente lo que evita darle
	 *                       un título propio a CEADI cuando redacta).
	 */
	public static function create_broadcast_post( $message, $target, $attachment_id = 0, $titulo = '' ) {
		/*
		 * El mensaje es texto de WhatsApp —*negrita* con un asterisco, sin
		 * encabezados ni tablas— y ESE es el que se manda a los teléfonos, tal
		 * cual, en `enqueue()`. La copia del panel la arma el módulo de
		 * comunicados, que es el mismo camino que usa la app.
		 */
		$pid = Cead_Acad_Broadcasts_CPT::crear( [
			'titulo'     => $titulo,
			'texto'      => '' !== (string) $message ? $message : 'Comunicado',
			'audiencias' => self::map_target_to_audiences( $target ),
			'imagen'     => (int) $attachment_id,
			'autor'      => get_current_user_id(),
		] );
		return is_wp_error( $pid ) ? 0 : (int) $pid;
	}

	public static function map_target_to_audiences( $target ) {
		return match ( $target ) {
			'students' => [ [ 'type' => 'role', 'value' => 'cead_acad_student' ], [ 'type' => 'role', 'value' => 'cead_acad_delegate' ] ],
			'staff'    => [ [ 'type' => 'role', 'value' => 'cead_acad_direction' ], [ 'type' => 'role', 'value' => 'cead_acad_secretary' ], [ 'type' => 'role', 'value' => 'cead_acad_teacher' ] ],
			default    => [ [ 'type' => 'all', 'value' => '*' ] ],
		};
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

	public function enqueue_for( $message, $target, $image = null ) {
		return $this->enqueue( $message, $this->resolve_phones( $target ), $image );
	}

	public function enqueue( $message, array $phones, $image = null ) {
		if ( $this->is_active() ) {
			return [ 'queued' => false, 'busy' => true ];
		}
		$phones = array_values( array_filter( $phones, fn( $p ) => $p !== '' ) );
		$job = [
			'message' => $message,
			'image'   => ( is_array( $image ) && ! empty( $image['path'] ) ) ? [ 'path' => $image['path'], 'mime' => $image['mime'] ?? 'image/jpeg' ] : null,
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
		// El lote usa sleep() entre mensajes; evitar que el max_execution_time lo corte.
		@set_time_limit( 0 );
		$end = min( $job['cursor'] + self::BATCH_SIZE, $job['total'] );
		// Imagen (si la hay): leer una vez y reenviar a cada destinatario.
		$image_b64 = null; $image_mime = 'image/jpeg';
		if ( ! empty( $job['image']['path'] ) && file_exists( $job['image']['path'] ) ) {
			$raw = @file_get_contents( $job['image']['path'] );
			if ( $raw !== false ) {
				$image_b64  = base64_encode( $raw );
				$image_mime = (string) ( $job['image']['mime'] ?? 'image/jpeg' );
			}
		}
		for ( $i = $job['cursor']; $i < $end; $i++ ) {
			$phone = (string) ( $job['phones'][ $i ] ?? '' );
			if ( $phone === '' ) { continue; }
			if ( $image_b64 !== null ) {
				$result = $this->bridge->send_image( $phone, $image_b64, $image_mime, (string) $job['message'] );
			} else {
				$result = $this->bridge->send_message( $phone, $job['message'] );
			}
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
