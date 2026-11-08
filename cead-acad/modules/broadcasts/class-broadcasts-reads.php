<?php
/**
 * Read receipts de comunicados.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Broadcasts_Reads {

	/**
	 * Marca un comunicado como leído por un usuario. Idempotente: cuenta la
	 * PRIMERA apertura («leído» = abrió el comunicado, definición del estudio).
	 *
	 * @param int|null $ts_cliente Hora real de la apertura en el dispositivo
	 *                             (la app la manda; puede ser horas antes de
	 *                             llegar si se leyó sin conexión).
	 * @param string   $canal      web | app | bot
	 */
	public static function mark_read( $broadcast_id, $user_id, $ts_cliente = null, $canal = 'web' ) {
		global $wpdb;
		$table = cead_acad_table( 'broadcast_reads' );
		$ahora = time();

		// Se guardan las dos horas. Si la del teléfono es imposible (posterior a
		// la llegada o anterior a la publicación) se marca dudosa: el análisis
		// usa entonces la del servidor.
		$post    = get_post( (int) $broadcast_id );
		$pub     = $post ? strtotime( $post->post_date_gmt . ' UTC' ) : null;
		$dudoso  = Cead_Acad_Estudio_Logica::ts_cliente_dudoso( $ts_cliente, $ahora, $pub );

		// prepare() convierte null en '': el NULL de ts_cliente va literal.
		$ts_sql = null === $ts_cliente ? 'NULL' : $wpdb->prepare( '%s', gmdate( 'Y-m-d H:i:s', (int) $ts_cliente ) );
		$n = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (broadcast_id, user_id, read_at, ts_cliente, canal, ts_cliente_dudoso) VALUES (%d, %d, %s, {$ts_sql}, %s, %d)",
			(int) $broadcast_id, (int) $user_id, gmdate( 'Y-m-d H:i:s', $ahora ),
			in_array( $canal, [ 'web', 'app', 'bot' ], true ) ? $canal : 'web', $dudoso ? 1 : 0
		) );

		if ( 1 === (int) $n && class_exists( 'Cead_Acad_Estudio' ) ) {
			Cead_Acad_Estudio::registrar( 'comunicado_leido', [
				'user_id' => (int) $user_id, 'canal' => $canal, 'entidad_tipo' => 'comunicado', 'entidad_id' => (int) $broadcast_id,
				'ts_cliente' => $ts_cliente, 'meta' => [ 'ts_cliente_dudoso' => $dudoso ],
			] );
		}
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
