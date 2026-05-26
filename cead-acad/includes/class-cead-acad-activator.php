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

		// El seeding de términos lo hace Cead_Acad_Plugin::maybe_seed_terms() en
		// init (cuando las taxonomías ya están registradas), guardado por opción.

		// Crear carpeta protegida para uploads del plugin (importadores en F4 la usarán).
		self::ensure_upload_dir();
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset           = $wpdb->get_charset_collate();
		$invitations       = cead_acad_table( 'invitations' );
		$audit             = cead_acad_table( 'audit_log' );
		$roster            = cead_acad_table( 'roster' );
		$audiences         = cead_acad_table( 'audiences' );
		$broadcast_reads   = cead_acad_table( 'broadcast_reads' );
		$survey_questions  = cead_acad_table( 'survey_questions' );
		$survey_responses  = cead_acad_table( 'survey_responses' );
		$survey_answers    = cead_acad_table( 'survey_answers' );
		$grades            = cead_acad_table( 'grades' );
		$import_jobs       = cead_acad_table( 'import_jobs' );

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

		$sql_survey_questions = "CREATE TABLE {$survey_questions} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			survey_id    BIGINT(20) UNSIGNED NOT NULL,
			position     INT(10) UNSIGNED NOT NULL DEFAULT 0,
			type         VARCHAR(20) NOT NULL,
			text         TEXT NOT NULL,
			required     TINYINT(1) NOT NULL DEFAULT 0,
			config       LONGTEXT NULL,
			created_at   DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY survey_id (survey_id),
			KEY position (survey_id, position)
		) {$charset};";

		$sql_survey_responses = "CREATE TABLE {$survey_responses} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			survey_id     BIGINT(20) UNSIGNED NOT NULL,
			user_id       BIGINT(20) UNSIGNED NULL,
			submitted_at  DATETIME NOT NULL,
			ip_hash       VARCHAR(64) NULL,
			user_agent    VARCHAR(255) NULL,
			PRIMARY KEY  (id),
			KEY survey_id (survey_id),
			KEY user_id (user_id),
			UNIQUE KEY survey_user (survey_id, user_id)
		) {$charset};";

		$sql_survey_answers = "CREATE TABLE {$survey_answers} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			response_id  BIGINT(20) UNSIGNED NOT NULL,
			question_id  BIGINT(20) UNSIGNED NOT NULL,
			value_text   TEXT NULL,
			value_json   LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY response_id (response_id),
			KEY question_id (question_id)
		) {$charset};";

		$sql_grades = "CREATE TABLE {$grades} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			student_user_id BIGINT(20) UNSIGNED NOT NULL,
			course_id       BIGINT(20) UNSIGNED NOT NULL,
			subject_term_id BIGINT(20) UNSIGNED NOT NULL,
			cohort_term_id  BIGINT(20) UNSIGNED NULL,
			period          VARCHAR(20) NOT NULL,
			score           DECIMAL(5,2) NULL,
			letter          VARCHAR(8) NULL,
			comments        TEXT NULL,
			recorded_by     BIGINT(20) UNSIGNED NULL,
			recorded_at     DATETIME NOT NULL,
			import_job_id   BIGINT(20) UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY grade_unique (student_user_id, course_id, subject_term_id, period),
			KEY course_subject (course_id, subject_term_id),
			KEY student (student_user_id),
			KEY import_job (import_job_id)
		) {$charset};";

		$sql_import_jobs = "CREATE TABLE {$import_jobs} (
			id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			type              VARCHAR(40) NOT NULL,
			original_filename VARCHAR(255) NOT NULL,
			stored_path       VARCHAR(500) NOT NULL,
			status            VARCHAR(20) NOT NULL DEFAULT 'uploaded',
			mapping           LONGTEXT NULL,
			report            LONGTEXT NULL,
			rows_total        INT(10) UNSIGNED NOT NULL DEFAULT 0,
			rows_ok           INT(10) UNSIGNED NOT NULL DEFAULT 0,
			rows_failed       INT(10) UNSIGNED NOT NULL DEFAULT 0,
			created_by        BIGINT(20) UNSIGNED NOT NULL,
			created_at        DATETIME NOT NULL,
			committed_at      DATETIME NULL,
			PRIMARY KEY  (id),
			KEY type (type),
			KEY status (status),
			KEY created_by (created_by)
		) {$charset};";

		dbDelta( $sql_invitations );
		dbDelta( $sql_audit );
		dbDelta( $sql_roster );
		dbDelta( $sql_audiences );
		dbDelta( $sql_broadcast_reads );
		dbDelta( $sql_survey_questions );
		dbDelta( $sql_survey_responses );
		dbDelta( $sql_survey_answers );
		dbDelta( $sql_grades );
		dbDelta( $sql_import_jobs );
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
