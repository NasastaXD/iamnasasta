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
$tables = [ 'invitations', 'audit_log', 'roster', 'audiences', 'broadcast_reads' ];
foreach ( $tables as $t ) {
	$name = $wpdb->prefix . 'cead_acad_' . $t;
	$wpdb->query( "DROP TABLE IF EXISTS {$name}" );
}

$options = [
	'cead_acad_db_version',
	'cead_acad_flush_rewrites',
	'cead_acad_block_wp_login',
];
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Borrar roles del plugin.
foreach ( [ 'cead_acad_direction', 'cead_acad_secretary', 'cead_acad_teacher', 'cead_acad_delegate', 'cead_acad_student', 'cead_acad_guardian' ] as $role ) {
	remove_role( $role );
}
