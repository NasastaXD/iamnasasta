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
	'cead_acad_rewrites_version',
	'cead_acad_block_wp_login',
	'cead_acad_caps_version',
	'cead_acad_terms_seeded',
	'cead_acad_fixture_intercead2026_seeded',
	'cead_acad_classic_editor_posts',
	'cead_acad_banned_words',
	'cead_acad_panel_intro',
	'cead_acad_ceadi_intro',
	'cead_acad_wa_crypto_key',
	'cead_acad_wa_site_links',
	'cead_acad_wa_contacts',
	'cead_acad_wa_report_categories',
	'cead_acad_wa_faq',
	'cead_acad_wa_council_board',
	'cead_acad_wa_reminder_days',
	'cead_acad_wa_report_forward_number',
	'cead_acad_wa_country_code',
	'cead_acad_wa_comm_templates',
	'cead_acad_wa_broadcast_job',
	'cead_acad_wa_bot_name',
	'cead_acad_wa_bot_number',
	'cead_acad_wa_bot_about',
	'cead_acad_wa_default_mode',
	'cead_acad_wa_director_phone',
	'cead_acad_wa_social_category',
	'cead_acad_wa_registered_only',
	// IA / transcripción de voz: incluye credenciales de proveedores externos.
	'cead_acad_wa_ai_enabled',
	'cead_acad_wa_ai_endpoint',
	'cead_acad_wa_ai_endpoint_is_base',
	'cead_acad_wa_ai_key',
	'cead_acad_wa_ai_model',
	'cead_acad_wa_ai_temp',
	'cead_acad_wa_ai_maxtokens',
	'cead_acad_wa_ai_prompt',
	'cead_acad_wa_ai_knowledge',
	'cead_acad_wa_ai_memory',
	'cead_acad_wa_ai_memories',
	'cead_acad_wa_stt_enabled',
	'cead_acad_wa_stt_endpoint',
	'cead_acad_wa_stt_key',
	'cead_acad_wa_stt_model',
	'cead_acad_wa_vision_enabled',
	'cead_acad_wa_vision_model',
	'cead_acad_wa_docs_enabled',
	// Acceso temporal por clave.
	'cead_acad_wa_temp_hash',
	'cead_acad_wa_temp_until',
	'cead_acad_wa_temp_max',
	'cead_acad_wa_temp_used',
	'cead_acad_wa_temp_user',
	'cead_acad_wa_temp_minutes',
	// Notas / calificaciones.
	'cead_acad_grades_periods',
	'cead_acad_grades_scale_bands',
	'cead_acad_grades_score_max',
	'cead_acad_grades_score_pass',
];
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Borrar roles del plugin.
foreach ( [ 'cead_acad_direction', 'cead_acad_secretary', 'cead_acad_teacher', 'cead_acad_delegate', 'cead_acad_student', 'cead_acad_guardian', 'cead_acad_student_council' ] as $role ) {
	remove_role( $role );
}
