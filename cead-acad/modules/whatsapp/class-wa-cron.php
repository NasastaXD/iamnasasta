<?php
/**
 * Tareas programadas del módulo WhatsApp: heartbeat del bridge, envío por
 * lotes, comunicados programados, recordatorios de eventos y limpieza.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Cron {

	const HEARTBEAT_EVENT = 'cead_acad_wa_heartbeat';
	const BROADCAST_EVENT = 'cead_acad_wa_broadcast';
	const SCHEDULED_EVENT = 'cead_acad_wa_scheduled';
	const REMINDERS_EVENT = 'cead_acad_wa_reminders';
	const GC_EVENT        = 'cead_acad_wa_gc';

	private $store;
	private $bridge;
	private $broadcaster;

	public function __construct( Cead_Acad_WA_Store $store, Cead_Acad_WA_Bridge_Client $bridge, Cead_Acad_WA_Broadcaster $broadcaster ) {
		$this->store       = $store;
		$this->bridge      = $bridge;
		$this->broadcaster = $broadcaster;
	}

	public function boot() {
		add_filter( 'cron_schedules', [ $this, 'add_schedules' ] );
		add_action( self::HEARTBEAT_EVENT, [ $this, 'heartbeat' ] );
		add_action( self::BROADCAST_EVENT, [ $this, 'run_broadcast' ] );
		add_action( self::SCHEDULED_EVENT, [ $this, 'run_scheduled' ] );
		add_action( self::REMINDERS_EVENT, [ $this, 'run_reminders' ] );
		add_action( self::GC_EVENT, [ $this, 'gc' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
	}

	public function add_schedules( $schedules ) {
		if ( ! isset( $schedules['cead_acad_wa_minute'] ) ) {
			$schedules['cead_acad_wa_minute'] = [ 'interval' => 60, 'display' => __( 'Cada minuto (CEAD WA)', 'cead-acad' ) ];
		}
		if ( ! isset( $schedules['cead_acad_wa_5min'] ) ) {
			$schedules['cead_acad_wa_5min'] = [ 'interval' => 300, 'display' => __( 'Cada 5 minutos (CEAD WA)', 'cead-acad' ) ];
		}
		return $schedules;
	}

	public function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HEARTBEAT_EVENT ) ) {
			wp_schedule_event( time() + 60, 'cead_acad_wa_minute', self::HEARTBEAT_EVENT );
		}
		if ( ! wp_next_scheduled( self::SCHEDULED_EVENT ) ) {
			wp_schedule_event( time() + 120, 'cead_acad_wa_5min', self::SCHEDULED_EVENT );
		}
		if ( ! wp_next_scheduled( self::REMINDERS_EVENT ) ) {
			wp_schedule_event( time() + 300, 'daily', self::REMINDERS_EVENT );
		}
		if ( ! wp_next_scheduled( self::GC_EVENT ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::GC_EVENT );
		}
	}

	public static function clear_all() {
		foreach ( [ self::HEARTBEAT_EVENT, self::BROADCAST_EVENT, self::SCHEDULED_EVENT, self::REMINDERS_EVENT, self::GC_EVENT ] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	public function heartbeat() {
		if ( $this->store->bridge_url() === '' ) {
			return;
		}
		$status = $this->bridge->status();
		if ( isset( $status['error'] ) ) {
			$this->store->update_session( [ 'connection_status' => 'unreachable', 'last_heartbeat' => current_time( 'mysql' ) ] );
			return;
		}
		$this->store->update_session( [
			'connection_status' => ! empty( $status['connected'] ) ? 'connected' : 'disconnected',
			'linked_number'     => isset( $status['number'] ) ? (string) $status['number'] : null,
			'qr_data'           => isset( $status['qr'] ) ? (string) $status['qr'] : null,
			'last_heartbeat'    => current_time( 'mysql' ),
		] );
	}

	public function run_broadcast() {
		$this->broadcaster->process_batch();
	}

	public function run_scheduled() {
		foreach ( $this->store->due_scheduled( 5 ) as $job ) {
			$res = $this->broadcaster->enqueue_for( (string) $job->message, (string) $job->target );
			if ( empty( $res['busy'] ) ) {
				// Al dispararse, también se crea el comunicado para el panel web.
				Cead_Acad_WA_Broadcaster::create_broadcast_post( (string) $job->message, (string) $job->target );
				$this->store->set_scheduled_status( (int) $job->id, 'sent' );
			}
		}
	}

	public function run_reminders() {
		$numbers = $this->store->reminder_numbers();
		if ( ! $numbers ) {
			return;
		}
		$days = max( 1, (int) get_option( 'cead_acad_wa_reminder_days', 1 ) );
		$now  = current_time( 'mysql' );
		$to   = date( 'Y-m-d H:i:s', strtotime( $now . " +{$days} day" ) );
		$tpl  = $this->store->get_message( 'event_reminder' );

		foreach ( $numbers as $n ) {
			$uid = $n->user_id ? (int) $n->user_id : 0;
			$events = $uid ? Cead_Acad_Schedule_Feed::for_user( $uid, $now, $to, 10 ) : $this->general_events_window( $now, $to, 10 );
			if ( ! $events ) {
				continue;
			}
			$lines = []; $keys = [];
			foreach ( $events as $e ) {
				// Dedup: no recordar dos veces el mismo evento al mismo número.
				$rk = 'cead_acad_wa_rem_' . md5( $n->phone . '|' . $e->ID );
				if ( get_transient( $rk ) ) { continue; }
				$start  = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
				$lines[] = '• ' . date_i18n( 'D d/m H:i', strtotime( $start ) ) . ' — ' . get_the_title( $e );
				$keys[]  = $rk;
			}
			if ( ! $lines ) { continue; }
			$msg = str_replace( '{events}', implode( "\n", $lines ), $tpl );
			$this->bridge->send_message( (string) $n->phone, $msg );
			$this->store->log( (string) $n->phone, 'out', $msg, 'event_reminder' );
			foreach ( $keys as $rk ) { set_transient( $rk, 1, 3 * DAY_IN_SECONDS ); }
			usleep( 300000 );
		}
	}

	private function general_events_window( $from, $to, $limit ) {
		global $wpdb;
		$aud = cead_acad_table( 'audiences' );
		$ids = $wpdb->get_col( "SELECT DISTINCT subject_id FROM {$aud} WHERE subject_type = 'event' AND audience_type = 'all'" );
		$ids = array_map( 'intval', $ids ?: [] );
		if ( ! $ids ) {
			return [];
		}
		return get_posts( [
			'post_type'      => Cead_Acad_Schedule_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'post__in'       => $ids,
			'posts_per_page' => (int) $limit,
			'orderby'        => 'meta_value',
			'meta_key'       => '_cead_acad_event_start',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => [
				[ 'key' => '_cead_acad_event_start', 'value' => $from, 'compare' => '>=', 'type' => 'DATETIME' ],
				[ 'key' => '_cead_acad_event_start', 'value' => $to, 'compare' => '<=', 'type' => 'DATETIME' ],
			],
		] );
	}

	public function gc() {
		$this->store->cleanup_stale_states( 60 );
		$this->store->cleanup_old_logs( 90 );
	}
}
