<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$tables = [
    'caag_bot_messages',
    'caag_registered_numbers',
    'caag_conversation_logs',
    'caag_session',
    'caag_user_state',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore
}

delete_option( 'caag_broadcast_log' );
delete_option( 'caag_reader_categories' );
wp_clear_scheduled_hook( 'caag_heartbeat_event' );
wp_clear_scheduled_hook( 'caag_log_cleanup_event' );
