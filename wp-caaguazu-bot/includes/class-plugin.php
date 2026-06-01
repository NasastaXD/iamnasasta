<?php
defined( 'ABSPATH' ) || exit;

class Caaguazu_Plugin {

    private Caaguazu_Database       $db;
    private Caaguazu_Bridge_Client  $bridge;
    private Caaguazu_WP_Actions     $wp_actions;
    private Caaguazu_Bot_Engine     $engine;
    private Caaguazu_Rest_Handler   $rest;
    private Caaguazu_Broadcaster    $broadcaster;
    private Caaguazu_Admin          $admin;

    public function __construct() {
        $this->db          = new Caaguazu_Database();
        $this->bridge      = new Caaguazu_Bridge_Client( $this->db );
        $this->wp_actions  = new Caaguazu_WP_Actions();
        $this->engine      = new Caaguazu_Bot_Engine( $this->db, $this->bridge, $this->wp_actions );
        $this->rest        = new Caaguazu_Rest_Handler( $this->db, $this->engine, $this->bridge );
        $this->broadcaster = new Caaguazu_Broadcaster( $this->db, $this->bridge );
        $this->admin       = new Caaguazu_Admin( $this->db, $this->bridge, $this->broadcaster );
    }

    public function run(): void {
        $this->define_common_hooks();
        $this->define_public_hooks();
        $this->define_admin_hooks();
        $this->define_cron_hooks();
    }

    private function define_common_hooks(): void {
        add_filter( 'cron_schedules', [ $this, 'register_cron_intervals' ] );
        // Migración de esquema/plantillas; en plugins_loaded para cubrir también REST y cron.
        add_action( 'plugins_loaded', [ 'Caaguazu_Activator', 'maybe_upgrade' ] );
    }

    private function define_public_hooks(): void {
        add_action( 'rest_api_init', [ $this->rest, 'register_routes' ] );
    }

    private function define_admin_hooks(): void {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'admin_menu',             [ $this->admin, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts',  [ $this->admin, 'enqueue_scripts' ] );
        add_action( 'admin_post_caag_save_messages', [ $this->admin, 'save_messages' ] );
        add_action( 'admin_post_caag_save_config',   [ $this->admin, 'save_config' ] );

        // AJAX handlers
        $ajax_actions = [
            'caag_get_status',
            'caag_restart_bridge',
            'caag_logout_bridge',
            'caag_test_bridge',
            'caag_send_broadcast',
            'caag_broadcast_progress',
            'caag_add_admin_number',
            'caag_remove_admin_number',
        ];
        foreach ( $ajax_actions as $action ) {
            add_action( "wp_ajax_{$action}", [ $this->admin, 'handle_ajax' ] );
        }
    }

    private function define_cron_hooks(): void {
        add_action( 'caag_heartbeat_event',       [ $this, 'run_heartbeat'   ] );
        add_action( 'caag_log_cleanup_event',     [ $this, 'run_log_cleanup' ] );
        add_action( 'caag_broadcast_batch_event', [ $this->broadcaster, 'process_batch' ] );
        add_action( 'caag_reminders_event',       [ $this, 'run_reminders' ] );
        add_action( 'caag_scheduled_event',       [ $this, 'run_scheduled_broadcasts' ] );
    }

    public function register_cron_intervals( array $schedules ): array {
        $schedules['caag_five_minutes'] = [
            'interval' => 300,
            'display'  => 'Cada 5 minutos (Caaguazú Bot)',
        ];
        return $schedules;
    }

    public function run_heartbeat(): void {
        $status = $this->bridge->get_status();
        $update = [ 'last_heartbeat' => current_time( 'mysql' ) ];

        if ( ! isset( $status['error'] ) ) {
            $update['connection_status'] = $status['connected'] ? 'connected' : 'disconnected';
            $update['linked_number']     = $status['number'] ?? null;
            $update['qr_data']           = $status['qr']     ?? null;
        } else {
            $update['connection_status'] = 'disconnected';
        }

        $this->db->update_session( $update );
    }

    public function run_log_cleanup(): void {
        $this->db->cleanup_old_logs( 90 );
        $this->db->cleanup_stale_states( 60 );
    }

    /**
     * Recordatorios opt-in (A3): envía un aviso a los números suscritos por los
     * eventos que ocurren dentro de N días (configurable). Un solo mensaje por día.
     */
    public function run_reminders(): void {
        $days   = max( 0, (int) get_option( 'caag_reminder_days', 1 ) );
        $target = date( 'Y-m-d', strtotime( current_time( 'mysql' ) . " +{$days} day" ) );
        $events = $this->db->get_events_to_remind( $target );
        if ( empty( $events ) ) {
            return;
        }

        $numbers = $this->db->get_reminder_numbers();
        $phones  = array_values( array_filter( array_map( fn( $n ) => (string) $n->phone, $numbers ), fn( $p ) => $p !== '' ) );

        if ( ! empty( $phones ) ) {
            $message = $this->interpolate_reminder( $events );
            $result  = $this->broadcaster->enqueue( $message, $phones );
            if ( empty( $result['queued'] ) ) {
                return; // ocupado: reintenta en la próxima corrida sin marcar
            }
        }

        foreach ( $events as $e ) {
            $this->db->mark_event_reminded( (int) $e->id );
        }
    }

    private function interpolate_reminder( array $events ): string {
        $lines = [];
        foreach ( $events as $e ) {
            $line = '• *' . $e->title . '*';
            if ( trim( (string) $e->description ) !== '' ) {
                $line .= ' — ' . $e->description;
            }
            $lines[] = $line;
        }
        $tpl = $this->db->get_message( 'event_reminder' );
        return str_replace( '{events}', implode( "\n", $lines ), $tpl );
    }

    /**
     * Programación de envíos (D3): despacha comunicados cuya hora ya llegó.
     * Procesa de a uno para respetar el control de volumen del broadcaster.
     */
    public function run_scheduled_broadcasts(): void {
        $due = $this->db->get_due_scheduled( 5 );
        foreach ( $due as $job ) {
            $result = $this->broadcaster->enqueue_for( (string) $job->message, (string) $job->target );
            if ( ! empty( $result['busy'] ) ) {
                break; // hay un envío en curso; reintentar en la próxima corrida
            }
            $this->db->set_scheduled_status( (int) $job->id, 'sent' );
        }
    }
}
