<?php
/**
 * Centro de notificaciones in-app: agrega comunicados sin leer, próximos
 * eventos y tareas pendientes en el desplegable de la campana. Sin push.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Notifications {

	public function boot() {
		add_action( 'admin_post_cead_acad_notif_seen', [ $this, 'handle_seen' ] );
	}

	/**
	 * Items para el desplegable. Cada item: type, title, url, when (string).
	 */
	public static function items( $user_id ) {
		$out = [];

		// Comunicados sin leer.
		$recent = class_exists( 'Cead_Acad_Broadcasts_Feed' ) ? Cead_Acad_Broadcasts_Feed::for_user( $user_id, [ 'per_page' => 8 ] ) : [];
		$read   = class_exists( 'Cead_Acad_Broadcasts_Reads' ) ? Cead_Acad_Broadcasts_Reads::read_ids_for_user( $user_id ) : [];
		foreach ( $recent as $p ) {
			if ( in_array( (int) $p->ID, $read, true ) ) { continue; }
			$out[] = [
				'type'  => 'comunicado',
				'icon'  => '📣',
				'title' => get_the_title( $p ),
				'url'   => cead_acad_url( 'panel/comunicados/' . $p->ID ),
				'when'  => get_the_date( '', $p ),
			];
		}

		// Próximos eventos (7 días).
		if ( class_exists( 'Cead_Acad_Schedule_Feed' ) ) {
			$from   = current_time( 'Y-m-d 00:00:00' );
			$to     = date( 'Y-m-d 23:59:59', strtotime( '+7 days', current_time( 'timestamp' ) ) );
			$events = Cead_Acad_Schedule_Feed::for_user( $user_id, $from, $to, 5 );
			foreach ( $events as $e ) {
				$st = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
				$out[] = [
					'type'  => 'evento',
					'icon'  => '📅',
					'title' => get_the_title( $e ),
					'url'   => cead_acad_url( 'panel/calendario/' . $e->ID ),
					'when'  => $st ? date_i18n( 'd/m H:i', strtotime( $st ) ) : '',
				];
			}
		}

		// Tareas pendientes que vencen pronto.
		if ( class_exists( 'Cead_Acad_Tasks_Frontend' ) ) {
			$soon = strtotime( '+7 days', current_time( 'timestamp' ) );
			foreach ( Cead_Acad_Tasks_Frontend::tasks_for_user( $user_id ) as $t ) {
				if ( Cead_Acad_Tasks_Frontend::is_done( $user_id, $t->ID ) ) { continue; }
				if ( 'cancelada' === (string) get_post_meta( $t->ID, '_cead_acad_task_status', true ) ) { continue; }
				$due = (string) get_post_meta( $t->ID, '_cead_acad_task_due_date', true );
				if ( $due && strtotime( $due . ' 23:59:59' ) > $soon ) { continue; }
				$out[] = [
					'type'  => 'tarea',
					'icon'  => '📝',
					'title' => get_the_title( $t ),
					'url'   => cead_acad_url( 'panel/tareas' ),
					'when'  => $due ? __( 'vence ', 'cead-acad' ) . date_i18n( 'd/m', strtotime( $due ) ) : '',
				];
			}
		}

		return $out;
	}

	/** Cantidad para el badge: comunicados sin leer. */
	public static function badge_count( $user_id ) {
		return class_exists( 'Cead_Acad_Broadcasts_Reads' ) ? (int) Cead_Acad_Broadcasts_Reads::count_unread_for_user( $user_id ) : 0;
	}

	/** Marca todos los comunicados visibles como leídos. */
	public function handle_seen() {
		if ( ! is_user_logged_in() ) { wp_safe_redirect( cead_acad_url( 'login' ) ); exit; }
		check_admin_referer( 'cead_acad_notif_seen' );

		$uid = get_current_user_id();
		$ids = class_exists( 'Cead_Acad_Audiences' ) ? Cead_Acad_Audiences::subjects_for_user( 'broadcast', $uid ) : [];
		foreach ( (array) $ids as $bid ) {
			Cead_Acad_Broadcasts_Reads::mark_read( (int) $bid, $uid );
		}

		$ref = wp_get_referer();
		wp_safe_redirect( $ref ? $ref : cead_acad_url( 'panel' ) );
		exit;
	}
}
