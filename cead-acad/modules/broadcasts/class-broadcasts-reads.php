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
		$read = self::read_ids_for_user( $user_id );
		$unread = array_diff( $visible, $read );
		// Filtrar por publicados.
		global $wpdb;
		if ( ! $unread ) { return 0; }
		$ph = implode( ',', array_fill( 0, count( $unread ), '%d' ) );
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ($ph) AND post_status = 'publish' AND post_type = %s",
			...array_merge( array_map( 'intval', $unread ), [ Cead_Acad_Broadcasts_CPT::POST_TYPE ] )
		) );
		return (int) $count;
	}
}
