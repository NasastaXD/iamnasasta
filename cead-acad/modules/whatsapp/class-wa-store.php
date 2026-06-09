<?php
/**
 * Capa de acceso a datos del módulo WhatsApp (todas las tablas wa_*).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Store {

	// ---- Mensajes / plantillas ----
	public function get_message( $key ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_messages' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT content FROM {$t} WHERE msg_key = %s", $key ) );
		return $row ? (string) $row->content : '';
	}

	public function update_message( $key, $content ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_messages' );
		return (bool) $wpdb->update( $t, [ 'content' => $content, 'updated_at' => current_time( 'mysql' ) ], [ 'msg_key' => $key ] );
	}

	public function all_messages() {
		global $wpdb;
		$t = cead_acad_table( 'wa_messages' );
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY msg_key ASC" ) ?: [];
	}

	// ---- Sesión del bridge ----
	public function session() {
		global $wpdb;
		$t = cead_acad_table( 'wa_session' );
		return $wpdb->get_row( "SELECT * FROM {$t} WHERE id = 1" ) ?: null;
	}

	public function update_session( array $data ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_session' );
		return (bool) $wpdb->update( $t, $data, [ 'id' => 1 ] );
	}

	public function bridge_url() { return (string) ( $this->session()->bridge_url ?? '' ); }
	public function shared_token() { return (string) ( $this->session()->shared_token ?? '' ); }

	// ---- Registro de números ----
	public function get_number( $phone ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_registry' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE phone = %s", $phone ) ) ?: null;
	}

	public function upsert_number( $phone, array $data ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_registry' );
		if ( $this->get_number( $phone ) ) {
			return (bool) $wpdb->update( $t, $data, [ 'phone' => $phone ] );
		}
		$data['phone']         = $phone;
		$data['registered_at'] = current_time( 'mysql' );
		return (bool) $wpdb->insert( $t, $data );
	}

	public function set_opt_out( $phone, $on ) {
		return $this->upsert_number( $phone, [ 'opt_out' => $on ? 1 : 0 ] );
	}

	public function is_opted_out( $phone ) {
		$row = $this->get_number( $phone );
		return $row && (int) $row->opt_out === 1;
	}

	public function update_last_seen( $phone ) {
		return $this->upsert_number( $phone, [ 'last_seen' => current_time( 'mysql' ) ] );
	}

	public function set_event_reminders( $phone, $on ) {
		return $this->upsert_number( $phone, [ 'event_reminders' => $on ? 1 : 0 ] );
	}

	public function has_event_reminders( $phone ) {
		$row = $this->get_number( $phone );
		return $row && (int) $row->event_reminders === 1;
	}

	public function reminder_numbers() {
		global $wpdb;
		$t = cead_acad_table( 'wa_registry' );
		return $wpdb->get_results( "SELECT * FROM {$t} WHERE event_reminders = 1 AND opt_out = 0" ) ?: [];
	}

	public function active_numbers() {
		global $wpdb;
		$t = cead_acad_table( 'wa_registry' );
		return $wpdb->get_results( "SELECT * FROM {$t} WHERE opt_out = 0" ) ?: [];
	}

	// ---- Estado conversacional ----
	public function get_state( $phone ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_state' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE phone = %s", $phone ) );
		if ( ! $row ) {
			return [ 'state' => 'idle', 'context' => [] ];
		}
		if ( strtotime( $row->updated_at ) < ( time() - 600 ) ) { // timeout 10 min
			$this->reset_state( $phone );
			return [ 'state' => 'idle', 'context' => [] ];
		}
		$ctx = [];
		if ( $row->context_data ) {
			$decoded = json_decode( $row->context_data, true );
			if ( is_array( $decoded ) ) { $ctx = $decoded; }
		}
		return [ 'state' => (string) $row->current_state, 'context' => $ctx ];
	}

	public function set_state( $phone, $state, array $context = [] ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_state' );
		$json = wp_json_encode( $context );
		$now  = current_time( 'mysql' );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE phone = %s", $phone ) );
		if ( $exists ) {
			return (bool) $wpdb->update( $t, [ 'current_state' => $state, 'context_data' => $json, 'updated_at' => $now ], [ 'phone' => $phone ] );
		}
		return (bool) $wpdb->insert( $t, [ 'phone' => $phone, 'current_state' => $state, 'context_data' => $json, 'updated_at' => $now ] );
	}

	public function reset_state( $phone ) {
		return $this->set_state( $phone, 'idle', [] );
	}

	public function cleanup_stale_states( $minutes = 60 ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_state' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE updated_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)", $minutes ) );
		return (int) $wpdb->rows_affected;
	}

	// ---- Logs ----
	public function log( $phone, $direction, $body, $action = '' ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_logs' );
		return (bool) $wpdb->insert( $t, [
			'phone' => $phone, 'direction' => $direction, 'message_body' => $body,
			'processed_action' => $action, 'created_at' => current_time( 'mysql' ),
		] );
	}

	public function recent_logs( $limit = 20 ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_logs' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY created_at DESC LIMIT %d", $limit ) ) ?: [];
	}

	public function cleanup_old_logs( $days = 90 ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_logs' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days ) );
		return (int) $wpdb->rows_affected;
	}

	public function count_messages( $direction = '', $days = 0 ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_logs' );
		$where = []; $params = [];
		if ( $direction !== '' ) { $where[] = 'direction = %s'; $params[] = $direction; }
		if ( $days > 0 ) { $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)'; $params[] = $days; }
		$sql = "SELECT COUNT(*) FROM {$t}";
		if ( $where ) { $sql .= ' WHERE ' . implode( ' AND ', $where ); }
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_var( $sql ) );
	}

	public function count_unique_users( $days = 30 ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_logs' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT phone) FROM {$t} WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", $days ) );
	}

	// ---- Sugerencias ----
	public function create_suggestion( $phone, $body, $category = 'administracion' ) {
		global $wpdb;
		$t   = cead_acad_table( 'wa_suggestions' );
		$cat = in_array( $category, [ 'administracion', 'consejo', 'direccion' ], true ) ? $category : 'administracion';
		return (bool) $wpdb->insert( $t, [ 'phone' => $phone, 'body' => $body, 'category' => $cat, 'status' => 'new', 'created_at' => current_time( 'mysql' ) ] );
	}

	public function suggestions_by_status( $status = '', $limit = 20 ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_suggestions' );
		if ( $status !== '' ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = %s AND deleted_at IS NULL ORDER BY created_at DESC LIMIT %d", $status, $limit ) ) ?: [];
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT %d", $limit ) ) ?: [];
	}

	/** Sugerencias activas (no borradas) filtradas por categoría (o todas si ''). */
	public function suggestions_by_category( $category = '', $limit = 100 ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_suggestions' );
		if ( $category !== '' ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE category = %s AND deleted_at IS NULL ORDER BY created_at DESC LIMIT %d", $category, $limit ) ) ?: [];
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT %d", $limit ) ) ?: [];
	}

	public function suggestions_trashed( $limit = 50 ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_suggestions' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT %d", $limit ) ) ?: [];
	}

	public function respond_suggestion( $id, $response, $status = '' ) {
		global $wpdb;
		$t    = cead_acad_table( 'wa_suggestions' );
		$data = [ 'response' => (string) $response ];
		if ( $status !== '' ) { $data['status'] = $status; }
		return (bool) $wpdb->update( $t, $data, [ 'id' => (int) $id ] );
	}

	public function soft_delete_suggestion( $id ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_suggestions' );
		return (bool) $wpdb->update( $t, [ 'deleted_at' => current_time( 'mysql' ) ], [ 'id' => (int) $id ] );
	}

	public function restore_suggestion( $id ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_suggestions' );
		return (bool) $wpdb->update( $t, [ 'deleted_at' => null ], [ 'id' => (int) $id ] );
	}

	public function purge_suggestion( $id ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_suggestions' );
		return (bool) $wpdb->delete( $t, [ 'id' => (int) $id ] );
	}

	public function get_suggestion( $id ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_suggestions' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ) ?: null;
	}

	public function update_suggestion_status( $id, $status ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_suggestions' );
		return (bool) $wpdb->update( $t, [ 'status' => $status ], [ 'id' => $id ] );
	}

	public function count_suggestions_by_status() {
		global $wpdb;
		$t = cead_acad_table( 'wa_suggestions' );
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) total FROM {$t} GROUP BY status" ) ?: [];
		$out = [ 'new' => 0, 'in_review' => 0, 'resolved' => 0 ];
		foreach ( $rows as $r ) { $out[ (string) $r->status ] = (int) $r->total; }
		return $out;
	}

	// ---- Programados ----
	public function create_scheduled( $message, $target, $run_at, $created_by ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_scheduled' );
		return (bool) $wpdb->insert( $t, [ 'message' => $message, 'target' => $target, 'run_at' => $run_at, 'status' => 'pending', 'created_by' => $created_by, 'created_at' => current_time( 'mysql' ) ] );
	}

	public function due_scheduled( $limit = 5 ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_scheduled' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'pending' AND run_at <= %s ORDER BY run_at ASC LIMIT %d", current_time( 'mysql' ), $limit ) ) ?: [];
	}

	public function set_scheduled_status( $id, $status ) {
		global $wpdb;
		$t = cead_acad_table( 'wa_scheduled' );
		return (bool) $wpdb->update( $t, [ 'status' => $status ], [ 'id' => $id ] );
	}
}
