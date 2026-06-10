<?php
/**
 * Audit log: escritura centralizada en la tabla cead_acad_audit_log y
 * hooks de eventos globales (login OK / login fallido).
 *
 * Uso desde cualquier módulo:
 *   Cead_Acad_Audit::log( 'user_created', [ 'entity_type' => 'user', 'entity_id' => $id ] );
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Audit {

	public function boot() {
		add_action( 'wp_login', [ __CLASS__, 'on_login' ], 10, 2 );
		add_action( 'wp_login_failed', [ __CLASS__, 'on_login_failed' ], 10, 1 );
	}

	/**
	 * Registra una acción en el audit log.
	 *
	 * @param string $action Slug corto de la acción (p.ej. 'user_created').
	 * @param array  $args   { entity_type?, entity_id?, payload? (array|string), user_id? }
	 * @return bool
	 */
	public static function log( $action, array $args = [] ) {
		global $wpdb;

		$payload = $args['payload'] ?? null;
		if ( is_array( $payload ) ) {
			$payload = wp_json_encode( $payload );
		}

		return (bool) $wpdb->insert( cead_acad_table( 'audit_log' ), [
			'user_id'     => isset( $args['user_id'] ) ? (int) $args['user_id'] : ( get_current_user_id() ?: null ),
			'action'      => substr( sanitize_key( $action ), 0, 80 ),
			'entity_type' => isset( $args['entity_type'] ) ? substr( sanitize_key( $args['entity_type'] ), 0, 60 ) : null,
			'entity_id'   => isset( $args['entity_id'] ) ? (int) $args['entity_id'] : null,
			'payload'     => $payload,
			'ip'          => self::client_ip(),
			'created_at'  => current_time( 'mysql' ),
		] );
	}

	public static function on_login( $user_login, $user ) {
		self::log( 'login_success', [
			'user_id'     => $user->ID,
			'entity_type' => 'user',
			'entity_id'   => $user->ID,
		] );
	}

	public static function on_login_failed( $user_login ) {
		self::log( 'login_failed', [
			'user_id' => null,
			'payload' => [ 'login' => sanitize_user( $user_login, true ) ],
		] );
	}

	/**
	 * IP del cliente (sin confiar en headers spoofeables salvo que no haya
	 * REMOTE_ADDR, p.ej. detrás de un proxy mal configurado).
	 */
	protected static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( $ip, 0, 64 );
	}

	// -------------------------------------------------------------------------
	// Lectura (para la UI de Registros)
	// -------------------------------------------------------------------------

	/**
	 * Consulta paginada del audit log.
	 *
	 * @param array $filters { action?, user_id?, date_from?, date_to?, page?, per_page? }
	 * @return array{rows:array,total:int}
	 */
	public static function query( array $filters = [] ) {
		global $wpdb;
		$t = cead_acad_table( 'audit_log' );

		$where  = [];
		$params = [];

		if ( ! empty( $filters['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = sanitize_key( $filters['action'] );
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $filters['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $filters['date_to'] . ' 23:59:59';
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';

		$count_sql = "SELECT COUNT(*) FROM {$t}{$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) : $wpdb->get_var( $count_sql ) );

		$per_page = max( 1, min( 200, (int) ( $filters['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$rows_sql = "SELECT * FROM {$t}{$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...array_merge( $params, [ $per_page, $offset ] ) ), ARRAY_A ) ?: [];

		return [ 'rows' => $rows, 'total' => $total ];
	}

	/** Acciones distintas registradas (para el select de filtro). */
	public static function distinct_actions() {
		global $wpdb;
		$t = cead_acad_table( 'audit_log' );
		return $wpdb->get_col( "SELECT DISTINCT action FROM {$t} ORDER BY action ASC" ) ?: [];
	}
}
