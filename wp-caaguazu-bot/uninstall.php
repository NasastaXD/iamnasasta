<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$tables = [
    'caag_bot_messages',
    'caag_registered_numbers',
    'caag_conversation_logs',
    'caag_session',
    'caag_user_state',
    'caag_reports',
    'caag_suggestions',
    'caag_events',
    'caag_schedules',
    'caag_scheduled',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore
}

$options = [
    'caag_broadcast_log',
    'caag_broadcast_jobs',
    'caag_reader_categories',
    'caag_contact_email',
    'caag_posts_per_page_reader',
    'caag_posts_per_page_admin',
    'caag_bot_db_version',
    'caag_report_key',
    'caag_site_links',
    'caag_contacts',
    'caag_report_categories',
    'caag_faq',
    'caag_council_board',
    'caag_comm_templates',
    'caag_reminder_days',
];
foreach ( $options as $option ) {
    delete_option( $option );
}

wp_clear_scheduled_hook( 'caag_heartbeat_event' );
wp_clear_scheduled_hook( 'caag_log_cleanup_event' );
wp_clear_scheduled_hook( 'caag_broadcast_batch_event' );
wp_clear_scheduled_hook( 'caag_reminders_event' );
wp_clear_scheduled_hook( 'caag_scheduled_event' );
