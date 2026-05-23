<?php
defined( 'ABSPATH' ) || exit;

class Caaguazu_Deactivator {

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'caag_heartbeat_event' );
        wp_clear_scheduled_hook( 'caag_log_cleanup_event' );
    }
}
