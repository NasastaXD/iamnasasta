<?php
/**
 * CRUD de jobs de importación (tabla cead_acad_import_jobs).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Importer_Job {

	public static function create( $type, $original, $stored ) {
		global $wpdb;
		$table = cead_acad_table( 'import_jobs' );
		$wpdb->insert(
			$table,
			[
				'type'              => sanitize_key( $type ),
				'original_filename' => sanitize_file_name( $original ),
				'stored_path'       => $stored,
				'status'            => 'uploaded',
				'rows_total'        => 0,
				'rows_ok'           => 0,
				'rows_failed'       => 0,
				'created_by'        => get_current_user_id(),
				'created_at'        => current_time( 'mysql', 1 ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	public static function find( $id ) {
		global $wpdb;
		$table = cead_acad_table( 'import_jobs' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
	}

	public static function update( $id, $data ) {
		global $wpdb;
		$table = cead_acad_table( 'import_jobs' );
		$formats = array_fill( 0, count( $data ), '%s' );
		$wpdb->update( $table, $data, [ 'id' => (int) $id ], $formats, [ '%d' ] );
	}

	public static function set_mapping( $id, $mapping ) {
		self::update( $id, [
			'mapping' => wp_json_encode( $mapping ),
			'status'  => 'mapped',
		] );
	}

	public static function set_report( $id, $report, $rows_ok, $rows_failed ) {
		self::update( $id, [
			'report'      => wp_json_encode( $report ),
			'rows_ok'     => (string) (int) $rows_ok,
			'rows_failed' => (string) (int) $rows_failed,
			'status'      => 'validated',
		] );
	}

	public static function mark_committed( $id, $rows_ok ) {
		global $wpdb;
		$table = cead_acad_table( 'import_jobs' );
		$wpdb->update(
			$table,
			[
				'status'       => 'committed',
				'committed_at' => current_time( 'mysql', 1 ),
				'rows_ok'      => (int) $rows_ok,
			],
			[ 'id' => (int) $id ],
			[ '%s', '%s', '%d' ],
			[ '%d' ]
		);
	}

	public static function recent( $limit = 30 ) {
		global $wpdb;
		$table = cead_acad_table( 'import_jobs' );
		$limit = (int) $limit;
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit}", ARRAY_A );
	}

	public static function get_mapping( $job ) {
		if ( empty( $job['mapping'] ) ) { return []; }
		$m = json_decode( $job['mapping'], true );
		return is_array( $m ) ? $m : [];
	}

	public static function get_report( $job ) {
		if ( empty( $job['report'] ) ) { return []; }
		$r = json_decode( $job['report'], true );
		return is_array( $r ) ? $r : [];
	}

	/**
	 * Devuelve el upload base con .htaccess deny ya creado por el activator.
	 */
	public static function upload_base() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'cead-acad/imports';
	}
}
