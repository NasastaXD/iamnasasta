<?php
/**
 * Borrado destructivo de datos del plugin. Solo se ejecuta si la constante
 * CEAD_ACAD_HARD_UNINSTALL está definida en wp-config.php como true.
 * En cualquier otro caso, conserva tablas y datos.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

if ( ! defined( 'CEAD_ACAD_HARD_UNINSTALL' ) || ! CEAD_ACAD_HARD_UNINSTALL ) {
	return;
}

global $wpdb;
$tables = [ 'invitations', 'audit_log', 'roster', 'audiences', 'broadcast_reads', 'survey_questions', 'survey_responses', 'survey_answers', 'grades', 'import_jobs', 'wa_session', 'wa_registry', 'wa_state', 'wa_messages', 'wa_reports', 'wa_suggestions', 'wa_scheduled', 'wa_logs' ];
foreach ( $tables as $t ) {
	$name = $wpdb->prefix . 'cead_acad_' . $t;
	$wpdb->query( "DROP TABLE IF EXISTS {$name}" );
}

$options = [
	'cead_acad_db_version',
	'cead_acad_flush_rewrites',
	'cead_acad_block_wp_login',
	'cead_acad_caps_version',
	'cead_acad_terms_seeded',
	'cead_acad_wa_crypto_key',
	'cead_acad_wa_site_links',
	'cead_acad_wa_contacts',
	'cead_acad_wa_report_categories',
	'cead_acad_wa_faq',
	'cead_acad_wa_council_board',
	'cead_acad_wa_reminder_days',
	'cead_acad_wa_report_forward_number',
	'cead_acad_wa_comm_templates',
	'cead_acad_wa_broadcast_job',
];
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Borrar roles del plugin.
foreach ( [ 'cead_acad_direction', 'cead_acad_secretary', 'cead_acad_teacher', 'cead_acad_delegate', 'cead_acad_student', 'cead_acad_guardian', 'cead_acad_student_council' ] as $role ) {
	remove_role( $role );
}
