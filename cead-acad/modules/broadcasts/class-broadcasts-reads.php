<?php
/**
 * Read receipts de comunicados.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Broadcasts_Reads {

	/**
	 * Marca un comunicado como leído por un usuario. Idempotente.
	 */
	public static function mark_read( $broadcast_id, $user_id ) {
		global $wpdb;
		$table = cead_acad_table( 'broadcast_reads' );
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (broadcast_id, user_id, read_at) VALUES (%d, %d, %s)",
			(int) $broadcast_id, (int) $user_id, current_time( 'mysql', 1 )
		) );
	}

	/**
	 * @return int[] broadcast IDs leídos por el usuario
	 */
	public static function read_ids_for_user( $user_id ) {
		global $wpdb;
		$table = cead_acad_table( 'broadcast_reads' );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT broadcast_id FROM {$table} WHERE user_id = %d",
			(int) $user_id
		) );
		return array_map( 'intval', $ids ?: [] );
	}

	public static function count_unread_for_user( $user_id ) {
		$visible = Cead_Acad_Audiences::subjects_for_user( 'broadcast', $user_id );
		if ( ! $visible ) {
			return 0;
		}
		global $wpdb;
		$reads = cead_acad_table( 'broadcast_reads' );
		$ph    = implode( ',', array_fill( 0, count( $visible ), '%d' ) );
		// LEFT JOIN: cuenta los visibles publicados que NO tienen read del usuario.
		// Orden de placeholders: user_id (JOIN), post_type (WHERE), IN (...) de IDs visibles.
		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} p
				LEFT JOIN {$reads} r ON r.broadcast_id = p.ID AND r.user_id = %d
				WHERE p.post_type = %s
				AND p.post_status = 'publish'
				AND p.ID IN ($ph)
				AND r.id IS NULL";
		$args = array_merge( [ (int) $user_id, Cead_Acad_Broadcasts_CPT::POST_TYPE ], array_map( 'intval', $visible ) );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
	}
}
