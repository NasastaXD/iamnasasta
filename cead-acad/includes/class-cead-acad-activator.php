<?php
/**
 * Crea tablas custom, registra roles y caps, flushea rewrites.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Activator {

	public static function activate() {
		self::create_tables();
		Cead_Acad_Capabilities::install();

		// Marcar pendiente flush en el siguiente init para que las rewrites del plugin existan.
		update_option( 'cead_acad_flush_rewrites', 1 );
		update_option( 'cead_acad_db_version', CEAD_ACAD_DB_VERSION );

		// Crear carpeta protegida para uploads del plugin (importadores en F4 la usarán).
		self::ensure_upload_dir();
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset         = $wpdb->get_charset_collate();
		$invitations     = cead_acad_table( 'invitations' );
		$audit           = cead_acad_table( 'audit_log' );
		$roster          = cead_acad_table( 'roster' );
		$audiences       = cead_acad_table( 'audiences' );
		$broadcast_reads = cead_acad_table( 'broadcast_reads' );

		$sql_invitations = "CREATE TABLE {$invitations} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			token_hash      VARCHAR(64) NOT NULL,
			email           VARCHAR(190) NULL,
			role            VARCHAR(40) NOT NULL,
			course_id       BIGINT(20) UNSIGNED NULL,
			invited_by      BIGINT(20) UNSIGNED NOT NULL,
			expires_at      DATETIME NOT NULL,
			used_at         DATETIME NULL,
			used_by_user_id BIGINT(20) UNSIGNED NULL,
			created_at      DATETIME NOT NULL,
			revoked_at      DATETIME NULL,
			metadata        LONGTEXT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY email (email),
			KEY expires_at (expires_at)
		) {$charset};";

		$sql_audit = "CREATE TABLE {$audit} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id      BIGINT(20) UNSIGNED NULL,
			action       VARCHAR(80) NOT NULL,
			entity_type  VARCHAR(60) NULL,
			entity_id    BIGINT(20) UNSIGNED NULL,
			payload      LONGTEXT NULL,
			ip           VARCHAR(64) NULL,
			created_at   DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY action (action),
			KEY user_id (user_id),
			KEY entity (entity_type, entity_id)
		) {$charset};";

		$sql_roster = "CREATE TABLE {$roster} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id         BIGINT(20) UNSIGNED NOT NULL,
			course_id       BIGINT(20) UNSIGNED NOT NULL,
			role_in_course  VARCHAR(20) NOT NULL DEFAULT 'student',
			start_date      DATE NULL,
			end_date        DATE NULL,
			status          VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at      DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_course (user_id, course_id),
			KEY course_id (course_id),
			KEY status (status)
		) {$charset};";

		$sql_audiences = "CREATE TABLE {$audiences} (
			id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			subject_type   VARCHAR(40) NOT NULL,
			subject_id     BIGINT(20) UNSIGNED NOT NULL,
			audience_type  VARCHAR(20) NOT NULL,
			audience_value VARCHAR(80) NOT NULL,
			created_at     DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY subject (subject_type, subject_id),
			KEY audience (audience_type, audience_value)
		) {$charset};";

		$sql_broadcast_reads = "CREATE TABLE {$broadcast_reads} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			broadcast_id BIGINT(20) UNSIGNED NOT NULL,
			user_id      BIGINT(20) UNSIGNED NOT NULL,
			read_at      DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY broadcast_user (broadcast_id, user_id),
			KEY user_id (user_id)
		) {$charset};";

		dbDelta( $sql_invitations );
		dbDelta( $sql_audit );
		dbDelta( $sql_roster );
		dbDelta( $sql_audiences );
		dbDelta( $sql_broadcast_reads );
	}

	protected static function ensure_upload_dir() {
		$uploads = wp_upload_dir();
		$base = trailingslashit( $uploads['basedir'] ) . 'cead-acad';
		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}
		$htaccess = $base . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "deny from all\n" );
		}
		$index = $base . '/index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' );
		}
	}
}
