<?php
defined( 'ABSPATH' ) || exit;

class Caaguazu_Admin {

    private Caaguazu_Database      $db;
    private Caaguazu_Bridge_Client $bridge;
    private Caaguazu_Broadcaster   $broadcaster;

    public function __construct(
        Caaguazu_Database $db,
        Caaguazu_Bridge_Client $bridge,
        Caaguazu_Broadcaster $broadcaster
    ) {
        $this->db          = $db;
        $this->bridge      = $bridge;
        $this->broadcaster = $broadcaster;
    }

    // -----------------------------------------------------------------------
    // Menús
    // -----------------------------------------------------------------------

    public function register_menus(): void {
        add_menu_page(
            'Caaguazú Bot',
            'Caaguazú Bot',
            'manage_options',
            'caag-bot',
            [ $this, 'render_status_page' ],
            'dashicons-whatsapp',
            80
        );

        $subpages = [
            [ 'caag-bot',           'Estado',              [ $this, 'render_status_page'    ] ],
            [ 'caag-bot-qr',        'Vincular WhatsApp',   [ $this, 'render_qr_page'        ] ],
            [ 'caag-bot-messages',  'Mensajes del bot',    [ $this, 'render_messages_page'  ] ],
            [ 'caag-bot-broadcast', 'Broadcast',           [ $this, 'render_broadcast_page' ] ],
            [ 'caag-bot-config',    'Configuración',       [ $this, 'render_config_page'    ] ],
        ];

        foreach ( $subpages as [ $slug, $title, $cb ] ) {
            add_submenu_page( 'caag-bot', "Caaguazú Bot — $title", $title, 'manage_options', $slug, $cb );
        }
    }

    // -----------------------------------------------------------------------
    // Scripts
    // -----------------------------------------------------------------------

    public function enqueue_scripts( string $hook ): void {
        $caag_pages = [ 'toplevel_page_caag-bot', 'caagu-bot_page_caag-bot-qr', 'caagu-bot_page_caag-bot-messages', 'caagu-bot_page_caag-bot-broadcast', 'caagu-bot_page_caag-bot-config' ];

        // El hook puede variar según la versión de WP; cargamos en cualquier página del plugin
        if ( strpos( $hook, 'caag-bot' ) === false ) {
            return;
        }

        wp_enqueue_script(
            'caag-admin',
            CAAG_BOT_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            CAAG_BOT_VERSION,
            true
        );

        wp_localize_script( 'caag-admin', 'caagBot', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'caag_admin_nonce' ),
            'strings' => [
                'confirmLogout'    => '¿Está seguro? Esto cerrará la sesión de WhatsApp.',
                'confirmBroadcast' => '¿Enviar este mensaje a todos los destinatarios seleccionados?',
                'sending'          => 'Enviando...',
                'success'          => 'Listo',
                'error'            => 'Error',
            ],
        ] );
    }

    // -----------------------------------------------------------------------
    // Páginas
    // -----------------------------------------------------------------------

    public function render_status_page(): void {
        $this->check_permission();
        include CAAG_BOT_DIR . 'admin/views/page-status.php';
    }

    public function render_qr_page(): void {
        $this->check_permission();
        include CAAG_BOT_DIR . 'admin/views/page-qr.php';
    }

    public function render_messages_page(): void {
        $this->check_permission();
        include CAAG_BOT_DIR . 'admin/views/page-messages.php';
    }

    public function render_broadcast_page(): void {
        $this->check_permission();
        include CAAG_BOT_DIR . 'admin/views/page-broadcast.php';
    }

    public function render_config_page(): void {
        $this->check_permission();
        include CAAG_BOT_DIR . 'admin/views/page-config.php';
    }

    // -----------------------------------------------------------------------
    // Guardado de formularios (admin_post_*)
    // -----------------------------------------------------------------------

    public function save_messages(): void {
        $this->check_permission();
        check_admin_referer( 'caag_save_messages' );

        $msgs = $_POST['caag_msg'] ?? [];
        if ( is_array( $msgs ) ) {
            foreach ( $msgs as $key => $content ) {
                $this->db->update_message(
                    sanitize_key( $key ),
                    wp_kses_post( wp_unslash( $content ) )
                );
            }
        }

        wp_redirect( admin_url( 'admin.php?page=caag-bot-messages&updated=1' ) );
        exit;
    }

    public function save_config(): void {
        $this->check_permission();
        check_admin_referer( 'caag_save_config' );

        // Bridge URL y token
        $this->db->update_session( [
            'bridge_url'   => esc_url_raw( wp_unslash( $_POST['caag_bridge_url']   ?? '' ) ),
            'shared_token' => sanitize_text_field( wp_unslash( $_POST['caag_token'] ?? '' ) ),
        ] );

        // Categorías para lectores
        $cats = array_map( 'intval', (array) ( $_POST['caag_reader_categories'] ?? [] ) );
        update_option( 'caag_reader_categories', $cats );

        // Email de contacto
        update_option( 'caag_contact_email', sanitize_email( wp_unslash( $_POST['caag_contact_email'] ?? '' ) ) );

        // Posts por página
        update_option( 'caag_posts_per_page_reader', max( 1, min( 10, (int) ( $_POST['caag_posts_reader'] ?? 5 ) ) ) );
        update_option( 'caag_posts_per_page_admin',  max( 1, min( 20, (int) ( $_POST['caag_posts_admin']  ?? 10 ) ) ) );

        wp_redirect( admin_url( 'admin.php?page=caag-bot-config&updated=1' ) );
        exit;
    }

    // -----------------------------------------------------------------------
    // AJAX dispatcher
    // -----------------------------------------------------------------------

    public function handle_ajax(): void {
        $this->check_permission();
        check_ajax_referer( 'caag_admin_nonce', 'nonce' );

        $action = sanitize_key( $_POST['action'] ?? '' );

        match ( $action ) {
            'caag_get_status'         => $this->ajax_get_status(),
            'caag_restart_bridge'     => $this->ajax_restart_bridge(),
            'caag_logout_bridge'      => $this->ajax_logout_bridge(),
            'caag_test_bridge'        => $this->ajax_test_bridge(),
            'caag_send_broadcast'     => $this->ajax_send_broadcast(),
            'caag_add_admin_number'   => $this->ajax_add_admin_number(),
            'caag_remove_admin_number'=> $this->ajax_remove_admin_number(),
            default                   => wp_send_json_error( [ 'message' => 'Acción no reconocida.' ] ),
        };
    }

    private function ajax_get_status(): void {
        $status  = $this->bridge->get_status();
        $session = $this->db->get_session();
        $logs    = $this->db->get_logs( '', 20 );

        if ( ! isset( $status['error'] ) ) {
            $this->db->update_session( [
                'connection_status' => $status['connected'] ? 'connected' : 'disconnected',
                'linked_number'     => $status['number'] ?? null,
                'qr_data'           => $status['qr']     ?? null,
                'last_heartbeat'    => current_time( 'mysql' ),
            ] );
        }

        wp_send_json_success( [
            'connected'      => $status['connected'] ?? false,
            'number'         => $status['number']    ?? null,
            'qr'             => $status['qr']        ?? null,
            'last_heartbeat' => $session->last_heartbeat ?? '—',
            'logs'           => $logs,
        ] );
    }

    private function ajax_restart_bridge(): void {
        $result = $this->bridge->restart();
        wp_send_json_success( $result );
    }

    private function ajax_logout_bridge(): void {
        $result = $this->bridge->logout();
        $this->db->update_session( [ 'connection_status' => 'disconnected', 'linked_number' => null ] );
        wp_send_json_success( $result );
    }

    private function ajax_test_bridge(): void {
        $status = $this->bridge->get_status();
        if ( isset( $status['error'] ) ) {
            wp_send_json_error( [ 'message' => $status['error'] ] );
        } else {
            wp_send_json_success( [ 'connected' => $status['connected'] ?? false ] );
        }
    }

    private function ajax_send_broadcast(): void {
        $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        $target  = sanitize_key( $_POST['target'] ?? 'all' );
        $custom  = sanitize_text_field( wp_unslash( $_POST['custom_numbers'] ?? '' ) );

        if ( empty( $message ) ) {
            wp_send_json_error( [ 'message' => 'El mensaje no puede estar vacío.' ] );
            return;
        }

        if ( $custom !== '' ) {
            $phones = array_map(
                fn( $p ) => preg_replace( '/[^0-9]/', '', trim( $p ) ),
                explode( ',', $custom )
            );
            $phones = array_filter( $phones, fn( $p ) => strlen( $p ) >= 7 );
            $result = $this->broadcaster->send_to_numbers( $message, array_values( $phones ) );
        } else {
            $role   = match ( $target ) { 'readers' => 'reader', 'admins' => 'admin', default => '' };
            $result = $this->broadcaster->send_to_all( $message, $role );
        }

        wp_send_json_success( $result );
    }

    private function ajax_add_admin_number(): void {
        $phone = preg_replace( '/[^0-9]/', '', (string) ( $_POST['phone'] ?? '' ) );
        $name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

        if ( strlen( $phone ) < 7 ) {
            wp_send_json_error( [ 'message' => 'Número inválido.' ] );
            return;
        }

        $this->db->upsert_number( $phone, [ 'role' => 'admin', 'name' => $name ] );
        wp_send_json_success( [ 'phone' => $phone, 'name' => $name ] );
    }

    private function ajax_remove_admin_number(): void {
        $phone = preg_replace( '/[^0-9]/', '', (string) ( $_POST['phone'] ?? '' ) );

        if ( $phone === '' ) {
            wp_send_json_error( [ 'message' => 'Número inválido.' ] );
            return;
        }

        $this->db->upsert_number( $phone, [ 'role' => 'reader' ] );
        wp_send_json_success( [ 'phone' => $phone ] );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function check_permission(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tiene permisos para acceder a esta página.', 'caag-bot' ) );
        }
    }
}
