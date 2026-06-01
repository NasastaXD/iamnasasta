<?php
defined( 'ABSPATH' ) || exit;

class Caaguazu_Bot_Engine {

    private Caaguazu_Database      $db;
    private Caaguazu_Bridge_Client $bridge;
    private Caaguazu_WP_Actions    $wp_actions;

    /** Imagen recibida junto al mensaje actual (solo se usa al publicar). */
    private ?array $pending_media = null;

    private const DAY_NAMES = [
        1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
        5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo',
    ];

    public function __construct(
        Caaguazu_Database $db,
        Caaguazu_Bridge_Client $bridge,
        Caaguazu_WP_Actions $wp_actions
    ) {
        $this->db         = $db;
        $this->bridge     = $bridge;
        $this->wp_actions = $wp_actions;
    }

    public function process_message( array $msg ): void {
        $phone     = preg_replace( '/[^0-9]/', '', (string) ( $msg['from'] ?? '' ) );
        $body      = sanitize_textarea_field( (string) ( $msg['body']     ?? '' ) );
        $push_name = sanitize_text_field( (string) ( $msg['pushName'] ?? '' ) );
        $media     = $this->sanitize_media( $msg['media'] ?? null );

        if ( $phone === '' || ( $body === '' && ! $media ) ) {
            return;
        }

        $this->pending_media = $media;

        // Registrar número si es primera visita (rol inicial: reader = alumnado)
        $number_row = $this->db->get_number( $phone );
        if ( ! $number_row ) {
            $this->db->upsert_number( $phone, [ 'role' => 'reader', 'name' => $push_name ] );
        } elseif ( $push_name && empty( $number_row->name ) ) {
            $this->db->upsert_number( $phone, [ 'name' => $push_name ] );
        }

        $this->db->update_last_seen( $phone );

        // El estado se lee ANTES de loguear para poder redactar contenido sensible.
        $state_data = $this->db->get_user_state( $phone );
        $state      = $state_data['state'];
        $context    = $state_data['context'];

        $this->log_inbound( $phone, $state, $context, $body, (bool) $media );

        if ( $this->db->is_opted_out( $phone ) ) {
            return;
        }

        $body_lc = strtolower( trim( $body ) );

        // Comando global BAJA (cualquier estado)
        if ( $body_lc === 'baja' ) {
            $this->db->set_opt_out( $phone, true );
            $this->db->reset_user_state( $phone );
            $this->send_reply( $phone, $this->db->get_message( 'opt_out_confirmed' ), 'opt_out' );
            return;
        }

        $this->dispatch( $phone, $body, $body_lc, $state, $context, $push_name );
    }

    /**
     * Registra el mensaje entrante, redactando el contenido sensible: durante la
     * captura de un reporte, el cuerpo NO se guarda en crudo (anónimo: ni siquiera
     * se registra el contenido; confidencial: se redacta).
     */
    private function log_inbound( string $phone, string $state, array $context, string $body, bool $has_media ): void {
        if ( $state === 'alu_report_body' ) {
            $type = $context['report_type'] ?? 'anonymous';
            if ( $type === 'anonymous' ) {
                return; // no dejar rastro del contenido de reportes anónimos
            }
            $this->db->log_message( $phone, 'in', '[reporte confidencial]' );
            return;
        }
        $this->db->log_message( $phone, 'in', $body !== '' ? $body : '[imagen]' );
    }

    private function dispatch(
        string $phone,
        string $body,
        string $body_lc,
        string $state,
        array  $context,
        string $name
    ): void {
        match ( $state ) {
            'idle'                   => $this->handle_idle( $phone, $name ),

            // -------- Personal / staff --------
            'staff_menu'             => $this->handle_staff_menu( $phone, $body_lc, $context ),

            // Artículos web (D1) — submenú dentro del panel staff
            'admin_menu'             => $this->handle_admin_menu( $phone, $body_lc ),
            'admin_publish_content'  => $this->handle_admin_publish_content( $phone, $body ),
            'admin_publish_category' => $this->handle_admin_publish_category( $phone, $body_lc, $context ),
            'admin_publish_status'   => $this->handle_admin_publish_status( $phone, $body_lc, $context ),
            'admin_edit_list'        => $this->handle_admin_edit_list( $phone, $body_lc, $context ),
            'admin_edit_mode'        => $this->handle_admin_edit_mode( $phone, $body_lc, $context ),
            'admin_edit_content'     => $this->handle_admin_edit_content( $phone, $body, $context ),
            'admin_delete_list'      => $this->handle_admin_delete_list( $phone, $body_lc, $context ),
            'admin_delete_confirm'   => $this->handle_admin_delete_confirm( $phone, $body_lc, $context ),

            // Comunicados (D2)
            'staff_comm_compose'     => $this->handle_comm_compose( $phone, $body, $body_lc ),
            'staff_comm_audience'    => $this->handle_comm_audience( $phone, $body_lc, $context ),
            'staff_comm_confirm'     => $this->handle_comm_confirm( $phone, $body_lc, $context ),

            // Eventos (D7)
            'staff_event_title'      => $this->handle_event_title( $phone, $body, $body_lc ),
            'staff_event_date'       => $this->handle_event_date( $phone, $body, $body_lc, $context ),
            'staff_event_desc'       => $this->handle_event_desc( $phone, $body, $body_lc, $context ),

            // Bandeja de reportes (D5)
            'staff_reports_list'     => $this->handle_reports_list( $phone, $body_lc, $context ),
            'staff_report_view'      => $this->handle_report_view( $phone, $body_lc, $context ),
            'staff_report_note'      => $this->handle_report_note( $phone, $body, $body_lc, $context ),

            // Bandeja de sugerencias (D6)
            'staff_sugg_list'        => $this->handle_sugg_list( $phone, $body_lc, $context ),
            'staff_sugg_view'        => $this->handle_sugg_view( $phone, $body_lc, $context ),

            // Gestión de usuarios (D8 / SuperAdmin)
            'staff_users_menu'       => $this->handle_users_menu( $phone, $body_lc ),
            'staff_user_add_phone'   => $this->handle_user_add_phone( $phone, $body, $body_lc ),
            'staff_user_add_roles'   => $this->handle_user_add_roles( $phone, $body_lc, $context ),
            'staff_user_remove'      => $this->handle_user_remove( $phone, $body, $body_lc ),

            // -------- Alumnado --------
            'reader_menu'            => $this->handle_reader_menu( $phone, $body_lc ),
            'alu_horario_group'      => $this->handle_horario_group( $phone, $body_lc, $context ),
            'alu_report_type'        => $this->handle_report_type( $phone, $body_lc ),
            'alu_report_cat'         => $this->handle_report_cat( $phone, $body_lc, $context ),
            'alu_report_body'        => $this->handle_report_body( $phone, $body, $body_lc, $context ),
            'alu_suggestion_body'    => $this->handle_suggestion_body( $phone, $body, $body_lc ),
            'alu_council_menu'       => $this->handle_council_menu( $phone, $body_lc ),
            'alu_council_proposal'   => $this->handle_council_proposal( $phone, $body, $body_lc ),

            // -------- Fase 4: staff (plantillas, programación, métricas) --------
            'staff_comm_template_pick' => $this->handle_comm_template_pick( $phone, $body_lc, $context ),
            'staff_comm_when'        => $this->handle_comm_when( $phone, $body_lc, $context ),
            'staff_comm_schedule_at' => $this->handle_comm_schedule_at( $phone, $body, $body_lc, $context ),
            'staff_tpl_menu'         => $this->handle_tpl_menu( $phone, $body_lc ),
            'staff_tpl_add_name'     => $this->handle_tpl_add_name( $phone, $body, $body_lc ),
            'staff_tpl_add_body'     => $this->handle_tpl_add_body( $phone, $body, $body_lc, $context ),
            'staff_tpl_delete'       => $this->handle_tpl_delete( $phone, $body_lc, $context ),

            default                  => $this->handle_idle( $phone, $name ),
        };
    }

    // -----------------------------------------------------------------------
    // IDLE — identificación de rol
    // -----------------------------------------------------------------------

    private function handle_idle( string $phone, string $name ): void {
        if ( $this->db->is_staff( $phone ) ) {
            $greeting = $this->interpolate( $this->db->get_message( 'greeting_admin' ), [ 'name' => $name ?: 'Profe' ] );
            $this->enter_staff_menu( $phone, $greeting );
        } else {
            $greeting = $this->interpolate( $this->db->get_message( 'greeting_reader' ), [ 'name' => $name ?: 'che' ] );
            $this->db->set_user_state( $phone, 'reader_menu' );
            $this->send_reply( $phone, $greeting . "\n\n" . $this->db->get_message( 'reader_menu' ) );
        }
    }

    // -----------------------------------------------------------------------
    // Menú del personal (dinámico según capacidades)
    // -----------------------------------------------------------------------

    /** Opciones disponibles para el número según sus capacidades, en orden. */
    private function staff_options( string $phone ): array {
        $opts = [];
        if ( $this->db->has_cap( $phone, 'cap_articles' ) ) {
            $opts[] = [ 'key' => 'articles', 'label' => 'Gestión de artículos web' ];
        }
        if ( $this->db->has_cap( $phone, 'cap_broadcast' ) ) {
            $opts[] = [ 'key' => 'comm',      'label' => 'Enviar comunicado' ];
            $opts[] = [ 'key' => 'templates', 'label' => 'Plantillas de comunicados' ];
            $opts[] = [ 'key' => 'event',     'label' => 'Agregar evento al calendario' ];
        }
        if ( $this->db->has_cap( $phone, 'cap_moderate' ) ) {
            $opts[] = [ 'key' => 'reports', 'label' => 'Bandeja de reportes' ];
            $opts[] = [ 'key' => 'sugg',    'label' => 'Bandeja de sugerencias' ];
        }
        $opts[] = [ 'key' => 'metrics', 'label' => 'Métricas' ];
        if ( $this->db->has_cap( $phone, 'manage_users' ) ) {
            $opts[] = [ 'key' => 'users', 'label' => 'Gestionar usuarios y roles' ];
        }
        return $opts;
    }

    private function enter_staff_menu( string $phone, string $prefix = '' ): void {
        $opts = $this->staff_options( $phone );
        $this->db->set_user_state( $phone, 'staff_menu', [ 'options' => array_column( $opts, 'key' ) ] );

        $lines = [ $this->db->get_message( 'staff_menu_header' ) ];
        foreach ( $opts as $i => $o ) {
            $lines[] = ( $i + 1 ) . '. ' . $o['label'];
        }
        $lines[] = '0. Salir';

        $text = ( $prefix !== '' ? $prefix . "\n\n" : '' ) . implode( "\n", $lines );
        $this->send_reply( $phone, $text );
    }

    private function handle_staff_menu( string $phone, string $body_lc, array $context ): void {
        if ( in_array( $body_lc, [ '0', 'salir', 'cancelar' ], true ) ) {
            $this->db->reset_user_state( $phone );
            $this->send_reply( $phone, $this->db->get_message( 'goodbye' ) );
            return;
        }

        $keys  = $context['options'] ?? [];
        $index = (int) $body_lc - 1;

        if ( ! isset( $keys[ $index ] ) ) {
            $this->send_invalid_option( $phone );
            return;
        }

        match ( $keys[ $index ] ) {
            'articles'  => $this->open_articles( $phone ),
            'comm'      => $this->comm_start( $phone ),
            'templates' => $this->tpl_open( $phone ),
            'event'     => $this->event_start( $phone ),
            'reports'   => $this->reports_open( $phone ),
            'sugg'      => $this->sugg_open( $phone ),
            'metrics'   => $this->metrics_show( $phone ),
            'users'     => $this->users_open( $phone ),
            default     => $this->send_invalid_option( $phone ),
        };
    }

    /** Verifica capacidad; si no la tiene, avisa y vuelve al menú staff. */
    private function require_cap( string $phone, string $cap ): bool {
        if ( $this->db->has_cap( $phone, $cap ) ) {
            return true;
        }
        $this->send_reply( $phone, $this->db->get_message( 'access_denied' ) );
        $this->enter_staff_menu( $phone );
        return false;
    }

    // -----------------------------------------------------------------------
    // Artículos web (D1) — submenú
    // -----------------------------------------------------------------------

    private function open_articles( string $phone ): void {
        if ( ! $this->require_cap( $phone, 'cap_articles' ) ) {
            return;
        }
        $this->db->set_user_state( $phone, 'admin_menu' );
        $this->send_reply( $phone, $this->db->get_message( 'admin_menu' ) );
    }

    private function handle_admin_menu( string $phone, string $body_lc ): void {
        match ( $body_lc ) {
            '1'          => $this->admin_start_publish( $phone ),
            '2'          => $this->admin_show_edit_list( $phone ),
            '3'          => $this->admin_show_delete_list( $phone ),
            '4'          => $this->admin_show_links( $phone ),
            '0', 'salir', 'cancelar', 'volver' => $this->enter_staff_menu( $phone ),
            default      => $this->send_invalid_option( $phone ),
        };
    }

    private function admin_start_publish( string $phone ): void {
        $this->db->set_user_state( $phone, 'admin_publish_content' );
        $this->send_reply( $phone, $this->db->get_message( 'publish_prompt' ) );
    }

    private function admin_show_edit_list( string $phone ): void {
        $posts = $this->wp_actions->get_recent_posts( (int) get_option( 'caag_posts_per_page_admin', 10 ) );
        if ( empty( $posts ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'no_posts_found' ) );
            return;
        }
        $this->db->set_user_state( $phone, 'admin_edit_list', [ 'posts' => $posts ] );
        $this->send_reply(
            $phone,
            $this->interpolate(
                $this->db->get_message( 'edit_list_prompt' ),
                [ 'post_list' => $this->format_posts_list( $posts ) ]
            )
        );
    }

    private function admin_show_delete_list( string $phone ): void {
        $posts = $this->wp_actions->get_recent_posts( (int) get_option( 'caag_posts_per_page_admin', 10 ) );
        if ( empty( $posts ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'no_posts_found' ) );
            return;
        }
        $this->db->set_user_state( $phone, 'admin_delete_list', [ 'posts' => $posts ] );
        $this->send_reply(
            $phone,
            $this->interpolate(
                $this->db->get_message( 'delete_list_prompt' ),
                [ 'post_list' => $this->format_posts_list( $posts ) ]
            )
        );
    }

    private function admin_show_links( string $phone ): void {
        $posts   = $this->wp_actions->get_recent_posts( 5 );
        $lines   = [ '🔗 *Últimas publicaciones:*' ];
        foreach ( $posts as $p ) {
            $lines[] = "- {$p['title']}\n  {$p['permalink']}";
        }
        $lines[] = "\n🌐 Admin: " . admin_url();
        $this->send_reply( $phone, implode( "\n", $lines ) );
        $this->send_reply( $phone, $this->db->get_message( 'admin_menu' ) );
    }

    private function handle_admin_publish_content( string $phone, string $body ): void {
        $has_media = false;
        if ( $this->pending_media ) {
            set_transient( $this->media_key( $phone ), $this->pending_media, 15 * MINUTE_IN_SECONDS );
            $has_media = true;
        }

        $cats = $this->wp_actions->get_categories();
        if ( empty( $cats ) ) {
            $this->db->set_user_state( $phone, 'admin_publish_status', [
                'pending_content' => $body,
                'category_id'     => 0,
                'has_media'       => $has_media,
            ] );
            $this->send_reply( $phone, $this->db->get_message( 'publish_status_prompt' ) );
            return;
        }

        $this->db->set_user_state( $phone, 'admin_publish_category', [
            'pending_content' => $body,
            'categories'      => $cats,
            'has_media'       => $has_media,
        ] );
        $this->send_reply(
            $phone,
            $this->interpolate(
                $this->db->get_message( 'category_prompt' ),
                [ 'category_list' => $this->format_categories_list( $cats ) ]
            )
        );
    }

    private function handle_admin_publish_category( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->cancel_publish( $phone );
            return;
        }

        $cats  = $context['categories'] ?? [];
        $index = (int) $body_lc - 1;

        if ( ! isset( $cats[ $index ] ) ) {
            $this->send_invalid_option( $phone );
            return;
        }

        $this->db->set_user_state( $phone, 'admin_publish_status', [
            'pending_content' => $context['pending_content'] ?? '',
            'category_id'     => (int) $cats[ $index ]['id'],
            'has_media'       => $context['has_media'] ?? false,
        ] );
        $this->send_reply( $phone, $this->db->get_message( 'publish_status_prompt' ) );
    }

    private function handle_admin_publish_status( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->cancel_publish( $phone );
            return;
        }

        $status = match ( $body_lc ) {
            '1'     => 'publish',
            '2'     => 'draft',
            default => '',
        };

        if ( $status === '' ) {
            $this->send_invalid_option( $phone );
            return;
        }

        $media = ! empty( $context['has_media'] ) ? get_transient( $this->media_key( $phone ) ) : null;
        delete_transient( $this->media_key( $phone ) );

        $result = $this->wp_actions->insert_post(
            (string) ( $context['pending_content'] ?? '' ),
            (int) ( $context['category_id'] ?? 0 ),
            $status,
            is_array( $media ) ? $media : null
        );

        $this->db->set_user_state( $phone, 'admin_menu' );
        $this->send_publish_result( $phone, $result );
        $this->send_reply( $phone, $this->db->get_message( 'admin_menu' ) );
    }

    private function handle_admin_edit_list( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->db->set_user_state( $phone, 'admin_menu' );
            $this->send_reply( $phone, $this->db->get_message( 'edit_cancelled' ) );
            $this->send_reply( $phone, $this->db->get_message( 'admin_menu' ) );
            return;
        }

        $posts = $context['posts'] ?? [];
        $index = (int) $body_lc - 1;

        if ( ! isset( $posts[ $index ] ) ) {
            $this->send_invalid_option( $phone );
            return;
        }

        $post = $posts[ $index ];
        $this->db->set_user_state( $phone, 'admin_edit_mode', [ 'post_id' => $post['id'], 'post_title' => $post['title'] ] );
        $this->send_reply(
            $phone,
            $this->interpolate(
                $this->db->get_message( 'edit_mode_prompt' ),
                [ 'title' => $post['title'] ]
            )
        );
    }

    private function handle_admin_edit_mode( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->cancel_edit( $phone );
            return;
        }

        $mode = match ( $body_lc ) {
            '1'     => 'replace',
            '2'     => 'append',
            default => '',
        };

        if ( $mode === '' ) {
            $this->send_invalid_option( $phone );
            return;
        }

        $this->db->set_user_state( $phone, 'admin_edit_content', [
            'post_id'    => (int) ( $context['post_id'] ?? 0 ),
            'post_title' => (string) ( $context['post_title'] ?? '' ),
            'mode'       => $mode,
        ] );
        $this->send_reply(
            $phone,
            $this->interpolate(
                $this->db->get_message( 'edit_content_prompt' ),
                [ 'title' => (string) ( $context['post_title'] ?? '' ) ]
            )
        );
    }

    private function handle_admin_edit_content( string $phone, string $body, array $context ): void {
        if ( $this->is_cancel( strtolower( trim( $body ) ) ) ) {
            $this->cancel_edit( $phone );
            return;
        }

        $post_id = (int) ( $context['post_id'] ?? 0 );
        $mode    = (string) ( $context['mode'] ?? 'replace' );
        $result  = $this->wp_actions->update_post( $post_id, $body, $mode );
        $this->db->set_user_state( $phone, 'admin_menu' );

        if ( $result['success'] ) {
            $this->send_reply(
                $phone,
                $this->interpolate( $this->db->get_message( 'edit_success' ), [ 'permalink' => $result['permalink'] ] ),
                'edit_post'
            );
        } else {
            $this->send_reply( $phone, $this->db->get_message( 'error_generic' ) );
        }
        $this->send_reply( $phone, $this->db->get_message( 'admin_menu' ) );
    }

    private function handle_admin_delete_list( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->db->set_user_state( $phone, 'admin_menu' );
            $this->send_reply( $phone, $this->db->get_message( 'delete_cancelled' ) );
            $this->send_reply( $phone, $this->db->get_message( 'admin_menu' ) );
            return;
        }

        $posts = $context['posts'] ?? [];
        $index = (int) $body_lc - 1;

        if ( ! isset( $posts[ $index ] ) ) {
            $this->send_invalid_option( $phone );
            return;
        }

        $post = $posts[ $index ];
        $this->db->set_user_state( $phone, 'admin_delete_confirm', [ 'post_id' => $post['id'], 'post_title' => $post['title'] ] );
        $this->send_reply(
            $phone,
            $this->interpolate(
                $this->db->get_message( 'delete_confirm_prompt' ),
                [ 'title' => $post['title'] ]
            )
        );
    }

    private function handle_admin_delete_confirm( string $phone, string $body_lc, array $context ): void {
        if ( in_array( $body_lc, [ 'si', 'sí', 'yes' ], true ) ) {
            $post_id = (int) ( $context['post_id'] ?? 0 );
            $result  = $this->wp_actions->trash_post( $post_id );
            $this->db->set_user_state( $phone, 'admin_menu' );

            if ( $result['success'] ) {
                $this->send_reply(
                    $phone,
                    $this->interpolate( $this->db->get_message( 'delete_success' ), [ 'title' => $result['title'] ] ),
                    'delete_post'
                );
            } else {
                $this->send_reply( $phone, $this->db->get_message( 'error_generic' ) );
            }
            $this->send_reply( $phone, $this->db->get_message( 'admin_menu' ) );
        } elseif ( in_array( $body_lc, [ 'no' ], true ) ) {
            $this->db->set_user_state( $phone, 'admin_menu' );
            $this->send_reply( $phone, $this->db->get_message( 'delete_cancelled' ) );
            $this->send_reply( $phone, $this->db->get_message( 'admin_menu' ) );
        } else {
            $this->send_reply( $phone, 'Por favor responda *SI* o *NO*.' );
        }
    }

    // -----------------------------------------------------------------------
    // Comunicados (D2)
    // -----------------------------------------------------------------------

    private function comm_start( string $phone ): void {
        if ( ! $this->require_cap( $phone, 'cap_broadcast' ) ) {
            return;
        }
        $this->db->set_user_state( $phone, 'staff_comm_compose' );
        $prompt = $this->db->get_message( 'comm_compose_prompt' );
        if ( ! empty( $this->get_templates() ) ) {
            $prompt .= "\n" . $this->db->get_message( 'comm_template_hint' );
        }
        $this->send_reply( $phone, $prompt );
    }

    private function handle_comm_compose( string $phone, string $body, string $body_lc ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'comm_cancelled' ) );
            $this->enter_staff_menu( $phone );
            return;
        }

        // Atajo para usar una plantilla guardada (D4).
        $templates = $this->get_templates();
        if ( $body_lc === 'p' && ! empty( $templates ) ) {
            $this->db->set_user_state( $phone, 'staff_comm_template_pick' );
            $this->send_reply(
                $phone,
                $this->interpolate( $this->db->get_message( 'comm_template_prompt' ), [ 'template_list' => $this->format_template_list( $templates ) ] )
            );
            return;
        }

        $this->comm_ask_audience( $phone, $body );
    }

    private function handle_comm_template_pick( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'comm_cancelled' ) );
            $this->enter_staff_menu( $phone );
            return;
        }
        $templates = $this->get_templates();
        $index     = (int) $body_lc - 1;
        if ( ! isset( $templates[ $index ] ) ) {
            $this->send_invalid_option( $phone );
            return;
        }
        $this->comm_ask_audience( $phone, (string) ( $templates[ $index ]['body'] ?? '' ) );
    }

    private function comm_ask_audience( string $phone, string $message ): void {
        $this->db->set_user_state( $phone, 'staff_comm_audience', [ 'message' => $message ] );
        $this->send_reply( $phone, $this->db->get_message( 'comm_audience_prompt' ) );
    }

    private function handle_comm_audience( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'comm_cancelled' ) );
            $this->enter_staff_menu( $phone );
            return;
        }

        $target = match ( $body_lc ) {
            '1'     => 'readers',
            '2'     => 'admins',
            '3'     => 'all',
            default => '',
        };

        if ( $target === '' ) {
            $this->send_invalid_option( $phone );
            return;
        }

        $count = $this->audience_count( $target );
        if ( $count === 0 ) {
            $this->send_reply( $phone, $this->db->get_message( 'comm_empty' ) );
            $this->enter_staff_menu( $phone );
            return;
        }

        $this->db->set_user_state( $phone, 'staff_comm_when', [
            'message' => $context['message'] ?? '',
            'target'  => $target,
            'count'   => $count,
        ] );
        $this->send_reply( $phone, $this->db->get_message( 'comm_when_prompt' ) );
    }

    private function handle_comm_when( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'comm_cancelled' ) );
            $this->enter_staff_menu( $phone );
            return;
        }

        if ( $body_lc === '1' ) {
            $this->db->set_user_state( $phone, 'staff_comm_confirm', [
                'message' => $context['message'] ?? '',
                'target'  => $context['target'] ?? 'all',
            ] );
            $this->send_reply(
                $phone,
                $this->interpolate( $this->db->get_message( 'comm_confirm_prompt' ), [ 'count' => (int) ( $context['count'] ?? 0 ) ] )
            );
        } elseif ( $body_lc === '2' ) {
            $this->db->set_user_state( $phone, 'staff_comm_schedule_at', [
                'message' => $context['message'] ?? '',
                'target'  => $context['target'] ?? 'all',
            ] );
            $this->send_reply( $phone, $this->db->get_message( 'comm_schedule_prompt' ) );
        } else {
            $this->send_invalid_option( $phone );
        }
    }

    private function handle_comm_schedule_at( string $phone, string $body, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'comm_cancelled' ) );
            $this->enter_staff_menu( $phone );
            return;
        }

        $run_at = $this->parse_datetime( trim( $body ) );
        if ( $run_at === null ) {
            $this->send_reply( $phone, $this->db->get_message( 'comm_schedule_invalid' ) );
            return;
        }

        $target = (string) ( $context['target'] ?? 'all' );
        $this->db->create_scheduled_broadcast( (string) ( $context['message'] ?? '' ), $target, $run_at, $phone );
        $this->send_reply(
            $phone,
            $this->interpolate( $this->db->get_message( 'comm_scheduled_ok' ), [
                'when'  => $run_at,
                'count' => $this->audience_count( $target ),
            ] ),
            'broadcast_scheduled'
        );
        $this->enter_staff_menu( $phone );
    }

    private function handle_comm_confirm( string $phone, string $body_lc, array $context ): void {
        if ( in_array( $body_lc, [ 'si', 'sí', 'yes' ], true ) ) {
            $broadcaster = new Caaguazu_Broadcaster( $this->db, $this->bridge );
            $result      = $broadcaster->enqueue_for( (string) ( $context['message'] ?? '' ), (string) ( $context['target'] ?? 'all' ) );

            if ( ! empty( $result['busy'] ) ) {
                $this->send_reply( $phone, $this->db->get_message( 'comm_busy' ) );
            } elseif ( empty( $result['queued'] ) ) {
                $this->send_reply( $phone, $this->db->get_message( 'comm_empty' ) );
            } else {
                $this->send_reply(
                    $phone,
                    $this->interpolate( $this->db->get_message( 'comm_queued' ), [ 'total' => (int) ( $result['total'] ?? 0 ) ] ),
                    'broadcast_enqueued'
                );
            }
            $this->enter_staff_menu( $phone );
        } elseif ( in_array( $body_lc, [ 'no' ], true ) || $this->is_cancel( $body_lc ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'comm_cancelled' ) );
            $this->enter_staff_menu( $phone );
        } else {
            $this->send_reply( $phone, 'Por favor responda *SI* o *NO*.' );
        }
    }

    private function audience_count( string $target ): int {
        return match ( $target ) {
            'readers' => count( $this->db->get_numbers_by_role( 'reader' ) ),
            'admins'  => count( $this->db->get_numbers_by_role( 'admin' ) ),
            default   => count( $this->db->get_active_numbers() ),
        };
    }

    // -----------------------------------------------------------------------
    // Plantillas de comunicados (D4)
    // -----------------------------------------------------------------------

    private function get_templates(): array {
        $tpls = get_option( 'caag_comm_templates', [] );
        return is_array( $tpls ) ? array_values( $tpls ) : [];
    }

    private function save_templates( array $tpls ): void {
        update_option( 'caag_comm_templates', array_values( $tpls ), false );
    }

    private function format_template_list( array $tpls ): string {
        $lines = [];
        foreach ( $tpls as $i => $t ) {
            $lines[] = ( $i + 1 ) . '. ' . ( $t['name'] ?? 'Plantilla' );
        }
        return implode( "\n", $lines );
    }

    private function tpl_open( string $phone ): void {
        if ( ! $this->require_cap( $phone, 'cap_broadcast' ) ) {
            return;
        }
        $this->db->set_user_state( $phone, 'staff_tpl_menu' );
        $this->send_reply( $phone, $this->db->get_message( 'tpl_menu' ) );
    }

    private function handle_tpl_menu( string $phone, string $body_lc ): void {
        match ( $body_lc ) {
            '1'      => $this->tpl_list( $phone ),
            '2'      => $this->tpl_add_start( $phone ),
            '3'      => $this->tpl_delete_start( $phone ),
            '0', 'volver', 'cancelar' => $this->enter_staff_menu( $phone ),
            default  => $this->send_invalid_option( $phone ),
        };
    }

    private function tpl_list( string $phone ): void {
        $tpls = $this->get_templates();
        if ( empty( $tpls ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'tpl_empty' ) );
            $this->send_reply( $phone, $this->db->get_message( 'tpl_menu' ) );
            return;
        }
        $lines = [ $this->db->get_message( 'tpl_list_header' ) ];
        foreach ( $tpls as $i => $t ) {
            $lines[] = ( $i + 1 ) . '. *' . ( $t['name'] ?? '' ) . "*\n   " . ( $t['body'] ?? '' );
        }
        $this->send_reply( $phone, implode( "\n", $lines ) );
        $this->send_reply( $phone, $this->db->get_message( 'tpl_menu' ) );
    }

    private function tpl_add_start( string $phone ): void {
        $this->db->set_user_state( $phone, 'staff_tpl_add_name' );
        $this->send_reply( $phone, $this->db->get_message( 'tpl_add_name_prompt' ) );
    }

    private function handle_tpl_add_name( string $phone, string $body, string $body_lc ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->tpl_open( $phone );
            return;
        }
        $this->db->set_user_state( $phone, 'staff_tpl_add_body', [ 'name' => $body ] );
        $this->send_reply( $phone, $this->db->get_message( 'tpl_add_body_prompt' ) );
    }

    private function handle_tpl_add_body( string $phone, string $body, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->tpl_open( $phone );
            return;
        }
        $tpls   = $this->get_templates();
        $name   = (string) ( $context['name'] ?? 'Plantilla' );
        $tpls[] = [ 'name' => $name, 'body' => $body ];
        $this->save_templates( $tpls );
        $this->send_reply( $phone, $this->interpolate( $this->db->get_message( 'tpl_added' ), [ 'name' => $name ] ) );
        $this->tpl_open( $phone );
    }

    private function tpl_delete_start( string $phone ): void {
        $tpls = $this->get_templates();
        if ( empty( $tpls ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'tpl_empty' ) );
            $this->tpl_open( $phone );
            return;
        }
        $this->db->set_user_state( $phone, 'staff_tpl_delete' );
        $this->send_reply(
            $phone,
            $this->interpolate( $this->db->get_message( 'tpl_delete_prompt' ), [ 'template_list' => $this->format_template_list( $tpls ) ] )
        );
    }

    private function handle_tpl_delete( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->tpl_open( $phone );
            return;
        }
        $tpls  = $this->get_templates();
        $index = (int) $body_lc - 1;
        if ( ! isset( $tpls[ $index ] ) ) {
            $this->send_invalid_option( $phone );
            return;
        }
        array_splice( $tpls, $index, 1 );
        $this->save_templates( $tpls );
        $this->send_reply( $phone, $this->db->get_message( 'tpl_deleted' ) );
        $this->tpl_open( $phone );
    }

    // -----------------------------------------------------------------------
    // Métricas (D9)
    // -----------------------------------------------------------------------

    private function metrics_show( string $phone ): void {
        $in        = $this->db->count_messages( 'in', 30 );
        $out       = $this->db->count_messages( 'out', 30 );
        $users     = $this->db->count_unique_users( 30 );
        $by_role   = $this->db->count_numbers_by_role();
        $reports   = $this->db->count_reports_by_status();
        $sugg      = $this->db->count_suggestions_by_status();
        $top       = $this->db->count_top_actions( 5, 30 );

        $lines   = [ $this->db->get_message( 'metrics_header' ) ];
        $lines[] = "💬 Mensajes: {$in} entrantes · {$out} salientes";
        $lines[] = "👤 Usuarios activos: {$users}";
        $lines[] = '👥 Números: ' . ( $by_role['reader'] ?? 0 ) . ' alumnado · ' . ( $by_role['admin'] ?? 0 ) . ' personal';
        $lines[] = "📥 Reportes: {$reports['new']} nuevos · {$reports['in_review']} en revisión · {$reports['resolved']} resueltos";
        $lines[] = "💡 Sugerencias: {$sugg['new']} nuevas · {$sugg['in_review']} en revisión · {$sugg['resolved']} resueltas";
        if ( ! empty( $top ) ) {
            $lines[] = '';
            $lines[] = '🔝 *Acciones frecuentes:*';
            foreach ( $top as $action => $total ) {
                $lines[] = '• ' . $action . ': ' . $total;
            }
        }

        $this->send_reply( $phone, implode( "\n", $lines ) );
        $this->enter_staff_menu( $phone );
    }

    // -----------------------------------------------------------------------
    // Eventos del calendario (D7) — alimenta A3
    // -----------------------------------------------------------------------

    private function event_start( string $phone ): void {
        if ( ! $this->require_cap( $phone, 'cap_broadcast' ) ) {
            return;
        }
        $this->db->set_user_state( $phone, 'staff_event_title' );
        $this->send_reply( $phone, $this->db->get_message( 'event_title_prompt' ) );
    }

    private function handle_event_title( string $phone, string $body, string $body_lc ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->enter_staff_menu( $phone );
            return;
        }
        $this->db->set_user_state( $phone, 'staff_event_date', [ 'title' => $body ] );
        $this->send_reply( $phone, $this->db->get_message( 'event_date_prompt' ) );
    }

    private function handle_event_date( string $phone, string $body, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->enter_staff_menu( $phone );
            return;
        }
        $date = trim( $body );
        if ( ! $this->is_valid_date( $date ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'event_date_invalid' ) );
            return;
        }
        $this->db->set_user_state( $phone, 'staff_event_desc', [
            'title' => (string) ( $context['title'] ?? '' ),
            'date'  => $date,
        ] );
        $this->send_reply( $phone, $this->db->get_message( 'event_desc_prompt' ) );
    }

    private function handle_event_desc( string $phone, string $body, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->enter_staff_menu( $phone );
            return;
        }
        $desc = trim( $body ) === '-' ? '' : $body;
        $this->db->create_event(
            (string) ( $context['title'] ?? '' ),
            (string) ( $context['date'] ?? '' ),
            $desc,
            $phone
        );
        $this->send_reply( $phone, $this->db->get_message( 'event_saved' ), 'event_created' );
        $this->enter_staff_menu( $phone );
    }

    // -----------------------------------------------------------------------
    // Bandeja de reportes (D5) — solo Moderador (permiso más restringido)
    // -----------------------------------------------------------------------

    private function reports_open( string $phone ): void {
        if ( ! $this->require_cap( $phone, 'cap_moderate' ) ) {
            return;
        }

        $counts  = $this->db->count_reports_by_status();
        $reports = array_merge(
            $this->db->get_reports_by_status( 'new', 15 ),
            $this->db->get_reports_by_status( 'in_review', 15 )
        );

        $header = $this->interpolate( $this->db->get_message( 'reports_inbox_header' ), [
            'new'       => $counts['new'],
            'in_review' => $counts['in_review'],
            'resolved'  => $counts['resolved'],
        ] );

        if ( empty( $reports ) ) {
            $this->send_reply( $phone, $header . "\n\n" . $this->db->get_message( 'reports_empty' ) );
            $this->enter_staff_menu( $phone );
            return;
        }

        $ids   = [];
        $lines = [];
        foreach ( $reports as $i => $r ) {
            $ids[]   = (int) $r->id;
            $lines[] = ( $i + 1 ) . '. ' . $r->ref_code . ' · ' . $this->report_type_label( $r->type )
                . ' · ' . $this->status_label( $r->status ) . ' · ' . $this->short_date( $r->created_at );
        }

        $this->db->set_user_state( $phone, 'staff_reports_list', [ 'ids' => $ids ] );
        $this->send_reply( $phone, $header );
        $this->send_reply(
            $phone,
            $this->interpolate( $this->db->get_message( 'reports_list_prompt' ), [ 'report_list' => implode( "\n", $lines ) ] )
        );
    }

    private function handle_reports_list( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->enter_staff_menu( $phone );
            return;
        }
        $ids   = $context['ids'] ?? [];
        $index = (int) $body_lc - 1;
        if ( ! isset( $ids[ $index ] ) ) {
            $this->send_invalid_option( $phone );
            return;
        }
        $this->show_report( $phone, (int) $ids[ $index ] );
    }

    private function show_report( string $phone, int $id ): void {
        $report = $this->db->get_report( $id );
        if ( ! $report ) {
            $this->send_reply( $phone, $this->db->get_message( 'error_generic' ) );
            $this->reports_open( $phone );
            return;
        }

        $body  = $this->db->decrypt( (string) $report->body_enc );
        $lines = [
            '📄 *' . $report->ref_code . '*',
            'Tipo: ' . $this->report_type_label( $report->type ),
            'Estado: ' . $this->status_label( $report->status ),
            'Tema: ' . ( $report->category !== '' ? $report->category : '—' ),
            'Fecha: ' . $this->short_date( $report->created_at ),
        ];
        if ( $report->type === 'confidential' && ! empty( $report->phone ) ) {
            $lines[] = 'Contacto: +' . $report->phone;
        }
        $lines[] = '';
        $lines[] = $body !== null ? $body : '⚠️ No se pudo descifrar el contenido (clave cambiada).';
        if ( trim( (string) $report->note ) !== '' ) {
            $lines[] = '';
            $lines[] = '📝 *Notas:*';
            $lines[] = (string) $report->note;
        }

        $this->db->set_user_state( $phone, 'staff_report_view', [ 'report_id' => $id ] );
        $this->send_reply( $phone, implode( "\n", $lines ) );
        $this->send_reply( $phone, $this->db->get_message( 'report_actions_prompt' ) );
    }

    private function handle_report_view( string $phone, string $body_lc, array $context ): void {
        $id = (int) ( $context['report_id'] ?? 0 );
        match ( $body_lc ) {
            '1'      => $this->report_set_status( $phone, $id, 'in_review' ),
            '2'      => $this->report_set_status( $phone, $id, 'resolved' ),
            '3'      => $this->report_start_note( $phone, $id ),
            '0', 'volver', 'cancelar' => $this->reports_open( $phone ),
            default  => $this->send_invalid_option( $phone ),
        };
    }

    private function report_set_status( string $phone, int $id, string $status ): void {
        $this->db->update_report_status( $id, $status );
        $this->send_reply( $phone, $this->db->get_message( 'report_updated' ) );
        if ( $status === 'resolved' ) {
            $this->reports_open( $phone );
        } else {
            $this->show_report( $phone, $id );
        }
    }

    private function report_start_note( string $phone, int $id ): void {
        $this->db->set_user_state( $phone, 'staff_report_note', [ 'report_id' => $id ] );
        $this->send_reply( $phone, $this->db->get_message( 'report_note_prompt' ) );
    }

    private function handle_report_note( string $phone, string $body, string $body_lc, array $context ): void {
        $id = (int) ( $context['report_id'] ?? 0 );
        if ( $this->is_cancel( $body_lc ) ) {
            $this->show_report( $phone, $id );
            return;
        }
        $this->db->append_report_note( $id, $body );
        $this->send_reply( $phone, $this->db->get_message( 'report_updated' ) );
        $this->show_report( $phone, $id );
    }

    // -----------------------------------------------------------------------
    // Bandeja de sugerencias (D6)
    // -----------------------------------------------------------------------

    private function sugg_open( string $phone ): void {
        if ( ! $this->require_cap( $phone, 'cap_moderate' ) ) {
            return;
        }

        $items = array_merge(
            $this->db->get_suggestions( 'new', 15 ),
            $this->db->get_suggestions( 'in_review', 15 )
        );

        if ( empty( $items ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'sugg_empty' ) );
            $this->enter_staff_menu( $phone );
            return;
        }

        $ids   = [];
        $lines = [];
        foreach ( $items as $i => $s ) {
            $ids[]   = (int) $s->id;
            $preview = mb_substr( (string) $s->body, 0, 40 );
            $lines[] = ( $i + 1 ) . '. ' . $this->status_label( $s->status ) . ' · ' . $preview;
        }

        $this->db->set_user_state( $phone, 'staff_sugg_list', [ 'ids' => $ids ] );
        $this->send_reply(
            $phone,
            $this->interpolate( $this->db->get_message( 'sugg_list_prompt' ), [ 'sugg_list' => implode( "\n", $lines ) ] )
        );
    }

    private function handle_sugg_list( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->enter_staff_menu( $phone );
            return;
        }
        $ids   = $context['ids'] ?? [];
        $index = (int) $body_lc - 1;
        if ( ! isset( $ids[ $index ] ) ) {
            $this->send_invalid_option( $phone );
            return;
        }
        $this->show_suggestion( $phone, (int) $ids[ $index ] );
    }

    private function show_suggestion( string $phone, int $id ): void {
        $s = $this->db->get_suggestion( $id );
        if ( ! $s ) {
            $this->send_reply( $phone, $this->db->get_message( 'error_generic' ) );
            $this->sugg_open( $phone );
            return;
        }
        $lines = [
            '💬 *Sugerencia*',
            'Estado: ' . $this->status_label( $s->status ),
            'Fecha: ' . $this->short_date( $s->created_at ),
            ! empty( $s->phone ) ? 'De: +' . $s->phone : 'De: (sin número)',
            '',
            (string) $s->body,
        ];
        $this->db->set_user_state( $phone, 'staff_sugg_view', [ 'sugg_id' => $id ] );
        $this->send_reply( $phone, implode( "\n", $lines ) );
        $this->send_reply( $phone, $this->db->get_message( 'sugg_actions_prompt' ) );
    }

    private function handle_sugg_view( string $phone, string $body_lc, array $context ): void {
        $id = (int) ( $context['sugg_id'] ?? 0 );
        match ( $body_lc ) {
            '1'      => $this->sugg_set_status( $phone, $id, 'in_review' ),
            '2'      => $this->sugg_set_status( $phone, $id, 'resolved' ),
            '0', 'volver', 'cancelar' => $this->sugg_open( $phone ),
            default  => $this->send_invalid_option( $phone ),
        };
    }

    private function sugg_set_status( string $phone, int $id, string $status ): void {
        $this->db->update_suggestion_status( $id, $status );
        $this->send_reply( $phone, $this->db->get_message( 'sugg_updated' ) );
        if ( $status === 'resolved' ) {
            $this->sugg_open( $phone );
        } else {
            $this->show_suggestion( $phone, $id );
        }
    }

    // -----------------------------------------------------------------------
    // Gestión de usuarios y roles (D8 / SuperAdmin)
    // -----------------------------------------------------------------------

    private function users_open( string $phone ): void {
        if ( ! $this->require_cap( $phone, 'manage_users' ) ) {
            return;
        }
        $this->db->set_user_state( $phone, 'staff_users_menu' );
        $this->send_reply( $phone, $this->db->get_message( 'users_menu' ) );
    }

    private function handle_users_menu( string $phone, string $body_lc ): void {
        match ( $body_lc ) {
            '1'      => $this->users_list( $phone ),
            '2'      => $this->user_add_start( $phone ),
            '3'      => $this->user_remove_start( $phone ),
            '0', 'volver', 'cancelar' => $this->enter_staff_menu( $phone ),
            default  => $this->send_invalid_option( $phone ),
        };
    }

    private function users_list( string $phone ): void {
        $staff = $this->db->get_staff_numbers();
        $lines = [ $this->db->get_message( 'users_list_header' ) ];
        if ( empty( $staff ) ) {
            $lines[] = '(ninguno)';
        } else {
            foreach ( $staff as $s ) {
                $roles   = $this->db->get_roles( (string) $s->phone );
                $roles_s = empty( $roles ) ? 'superadmin (por defecto)' : implode( ', ', $roles );
                $lines[] = '• +' . $s->phone . ( $s->name ? ' (' . $s->name . ')' : '' ) . "\n  Roles: " . $roles_s;
            }
        }
        $this->send_reply( $phone, implode( "\n", $lines ) );
        $this->send_reply( $phone, $this->db->get_message( 'users_menu' ) );
    }

    private function user_add_start( string $phone ): void {
        $this->db->set_user_state( $phone, 'staff_user_add_phone' );
        $this->send_reply( $phone, $this->db->get_message( 'user_add_phone_prompt' ) );
    }

    private function handle_user_add_phone( string $phone, string $body, string $body_lc ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->users_open( $phone );
            return;
        }
        $target = preg_replace( '/[^0-9]/', '', $body );
        if ( strlen( $target ) < 7 ) {
            $this->send_reply( $phone, $this->db->get_message( 'user_phone_invalid' ) );
            return;
        }
        $this->db->set_user_state( $phone, 'staff_user_add_roles', [ 'new_phone' => $target ] );
        $this->send_reply(
            $phone,
            $this->interpolate( $this->db->get_message( 'user_roles_prompt' ), [ 'role_list' => $this->format_role_list() ] )
        );
    }

    private function handle_user_add_roles( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->users_open( $phone );
            return;
        }

        $target = (string) ( $context['new_phone'] ?? '' );
        $slugs  = array_keys( $this->role_options() );
        $chosen = [];
        foreach ( explode( ',', $body_lc ) as $token ) {
            $idx = (int) trim( $token ) - 1;
            if ( isset( $slugs[ $idx ] ) ) {
                $chosen[] = $slugs[ $idx ];
            }
        }
        $chosen = array_values( array_unique( $chosen ) );

        if ( empty( $chosen ) || $target === '' ) {
            $this->send_invalid_option( $phone );
            return;
        }

        $this->db->upsert_number( $target, [ 'role' => 'admin' ] );
        $this->db->set_roles( $target, $chosen );

        $this->send_reply(
            $phone,
            $this->interpolate( $this->db->get_message( 'user_added' ), [
                'phone' => '+' . $target,
                'roles' => implode( ', ', $chosen ),
            ] ),
            'user_added'
        );
        $this->users_open( $phone );
    }

    private function user_remove_start( string $phone ): void {
        $this->db->set_user_state( $phone, 'staff_user_remove' );
        $this->send_reply( $phone, $this->db->get_message( 'user_remove_prompt' ) );
    }

    private function handle_user_remove( string $phone, string $body, string $body_lc ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->users_open( $phone );
            return;
        }
        $target = preg_replace( '/[^0-9]/', '', $body );
        if ( strlen( $target ) < 7 ) {
            $this->send_reply( $phone, $this->db->get_message( 'user_phone_invalid' ) );
            return;
        }
        $this->db->upsert_number( $target, [ 'role' => 'reader', 'roles' => '' ] );
        $this->send_reply(
            $phone,
            $this->interpolate( $this->db->get_message( 'user_removed' ), [ 'phone' => '+' . $target ] ),
            'user_removed'
        );
        $this->users_open( $phone );
    }

    private function role_options(): array {
        return [
            'superadmin'  => 'SuperAdmin — todo + gestión de usuarios',
            'editor'      => 'Editor — artículos web',
            'comunicador' => 'Comunicador — comunicados y eventos',
            'moderador'   => 'Moderador — reportes y sugerencias',
        ];
    }

    private function format_role_list(): string {
        $lines = [];
        $i     = 1;
        foreach ( $this->role_options() as $label ) {
            $lines[] = $i . '. ' . $label;
            $i++;
        }
        return implode( "\n", $lines );
    }

    // -----------------------------------------------------------------------
    // Alumnado — menú principal
    // -----------------------------------------------------------------------

    private function handle_reader_menu( string $phone, string $body_lc ): void {
        match ( $body_lc ) {
            '1'      => $this->horario_start( $phone ),
            '2'      => $this->site_show( $phone ),
            '3'      => $this->events_show( $phone ),
            '4'      => $this->contact_show( $phone ),
            '5'      => $this->report_start( $phone ),
            '6'      => $this->suggestion_start( $phone ),
            '7'      => $this->faq_show( $phone ),
            '8'      => $this->council_open( $phone ),
            '9'      => $this->reminders_toggle( $phone ),
            '0', 'adios', 'adiós', 'salir' => $this->reader_exit( $phone ),
            default  => $this->send_invalid_option( $phone ),
        };
    }

    private function reader_exit( string $phone ): void {
        $this->db->reset_user_state( $phone );
        $this->send_reply( $phone, $this->db->get_message( 'goodbye' ) );
    }

    private function back_to_reader_menu( string $phone ): void {
        $this->db->set_user_state( $phone, 'reader_menu' );
        $this->send_reply( $phone, $this->db->get_message( 'reader_menu' ) );
    }

    // -------- A1 Horarios --------

    private function horario_start( string $phone ): void {
        $groups = $this->db->get_schedule_groups();
        if ( empty( $groups ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'horario_none' ) );
            $this->back_to_reader_menu( $phone );
            return;
        }

        $lines = [];
        $store = [];
        foreach ( $groups as $i => $g ) {
            $label   = trim( $g->course . ' ' . $g->division );
            $lines[] = ( $i + 1 ) . '. ' . $label;
            $store[] = [ 'course' => $g->course, 'division' => $g->division, 'label' => $label ];
        }

        $this->db->set_user_state( $phone, 'alu_horario_group', [ 'groups' => $store ] );
        $this->send_reply(
            $phone,
            $this->interpolate( $this->db->get_message( 'horario_group_prompt' ), [ 'group_list' => implode( "\n", $lines ) ] )
        );
    }

    private function handle_horario_group( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->back_to_reader_menu( $phone );
            return;
        }

        $groups = $context['groups'] ?? [];
        $index  = (int) $body_lc - 1;
        if ( ! isset( $groups[ $index ] ) ) {
            $this->send_invalid_option( $phone );
            return;
        }

        $group = $groups[ $index ];
        $rows  = $this->db->get_schedule( (string) $group['course'], (string) $group['division'] );

        $this->send_reply( $phone, $this->build_schedule_text( (string) $group['label'], $rows ) );
        $this->back_to_reader_menu( $phone );
    }

    private function build_schedule_text( string $label, array $rows ): string {
        $out = [ $this->interpolate( $this->db->get_message( 'horario_header' ), [ 'group' => $label ] ) ];

        // Grilla agrupada por día.
        $by_day = [];
        foreach ( $rows as $r ) {
            $by_day[ (int) $r->day_of_week ][] = $r;
        }
        ksort( $by_day );
        foreach ( $by_day as $dow => $day_rows ) {
            $out[] = "\n*" . ( self::DAY_NAMES[ $dow ] ?? 'Día ' . $dow ) . '*';
            foreach ( $day_rows as $r ) {
                $time  = $r->start_time ? substr( (string) $r->start_time, 0, 5 ) : '';
                $room  = $r->room ? ' (' . $r->room . ')' : '';
                $out[] = trim( $time . ' ' . $r->subject . $room );
            }
        }

        // "¿Qué tengo ahora / qué sigue?" (dentro del día actual).
        [ $now, $next ] = $this->compute_now_next( $rows );
        $out[] = '';
        $out[] = $now !== ''
            ? $this->interpolate( $this->db->get_message( 'horario_now' ), [ 'now' => $now ] )
            : $this->db->get_message( 'horario_idle' );
        if ( $next !== '' ) {
            $out[] = $this->interpolate( $this->db->get_message( 'horario_next' ), [ 'next' => $next ] );
        }

        return implode( "\n", $out );
    }

    /** Devuelve [ ahora, sigue ] como textos, considerando el día/hora actuales. */
    private function compute_now_next( array $rows ): array {
        $w   = (int) current_time( 'w' ); // 0=Dom..6=Sáb
        $dow = $w === 0 ? 7 : $w;
        $now_t = current_time( 'H:i:s' );

        $today = array_filter( $rows, fn( $r ) => (int) $r->day_of_week === $dow );
        usort( $today, fn( $a, $b ) => strcmp( (string) $a->start_time, (string) $b->start_time ) );

        $now  = '';
        $next = '';
        foreach ( $today as $r ) {
            $start = (string) $r->start_time;
            $end   = (string) $r->end_time;
            if ( $start !== '' && $start <= $now_t && ( $end === '' || $now_t < $end ) ) {
                $now = $r->subject . ( $r->room ? ' (' . $r->room . ')' : '' );
            } elseif ( $start > $now_t && $next === '' ) {
                $next = substr( $start, 0, 5 ) . ' ' . $r->subject;
            }
        }
        return [ $now, $next ];
    }

    // -------- A2 Sitio web --------

    private function site_show( string $phone ): void {
        $links = get_option( 'caag_site_links', [] );
        $lines = [ $this->db->get_message( 'site_links_header' ) ];
        if ( is_array( $links ) && ! empty( $links ) ) {
            foreach ( $links as $l ) {
                $lines[] = '• ' . ( $l['label'] ?? '' ) . "\n  " . ( $l['url'] ?? '' );
            }
        } else {
            $lines[] = 'https://cead.caaguazu.net';
        }
        $this->send_reply( $phone, implode( "\n", $lines ) );
        $this->back_to_reader_menu( $phone );
    }

    // -------- A3 Calendario --------

    private function events_show( string $phone ): void {
        $events = $this->db->get_upcoming_events( 10 );
        if ( empty( $events ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'events_none' ) );
            $this->back_to_reader_menu( $phone );
            return;
        }
        $lines = [ $this->db->get_message( 'events_header' ) ];
        foreach ( $events as $e ) {
            $line = '• ' . $this->short_date( $e->event_date ) . ' — *' . $e->title . '*';
            if ( trim( (string) $e->description ) !== '' ) {
                $line .= "\n  " . $e->description;
            }
            $lines[] = $line;
        }
        $this->send_reply( $phone, implode( "\n", $lines ) );
        $this->back_to_reader_menu( $phone );
    }

    // -------- A4 Contacto --------

    private function contact_show( string $phone ): void {
        $contacts = get_option( 'caag_contacts', [] );
        $lines    = [ $this->db->get_message( 'contact_header' ) ];
        if ( is_array( $contacts ) && ! empty( $contacts ) ) {
            foreach ( $contacts as $c ) {
                $lines[] = '• *' . ( $c['name'] ?? '' ) . "*\n  " . ( $c['detail'] ?? '' );
            }
        }
        $this->send_reply( $phone, implode( "\n", $lines ) );
        $this->back_to_reader_menu( $phone );
    }

    // -------- A5 Reporte (anónimo / confidencial) --------

    private function report_start( string $phone ): void {
        $this->db->set_user_state( $phone, 'alu_report_type' );
        $this->send_reply( $phone, $this->db->get_message( 'report_type_prompt' ) );
    }

    private function handle_report_type( string $phone, string $body_lc ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->back_to_reader_menu( $phone );
            return;
        }
        $type = match ( $body_lc ) {
            '1'     => 'anonymous',
            '2'     => 'confidential',
            default => '',
        };
        if ( $type === '' ) {
            $this->send_invalid_option( $phone );
            return;
        }

        $cats = $this->report_categories();
        $this->db->set_user_state( $phone, 'alu_report_cat', [ 'report_type' => $type, 'categories' => $cats ] );
        $lines = [];
        foreach ( $cats as $i => $c ) {
            $lines[] = ( $i + 1 ) . '. ' . $c;
        }
        $this->send_reply(
            $phone,
            $this->interpolate( $this->db->get_message( 'report_category_prompt' ), [ 'category_list' => implode( "\n", $lines ) ] )
        );
    }

    private function handle_report_cat( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'report_cancelled' ) );
            $this->back_to_reader_menu( $phone );
            return;
        }
        $cats  = $context['categories'] ?? [];
        $index = (int) $body_lc - 1;
        if ( ! isset( $cats[ $index ] ) ) {
            $this->send_invalid_option( $phone );
            return;
        }
        // El report_type se conserva en el contexto para que log_inbound pueda redactar.
        $this->db->set_user_state( $phone, 'alu_report_body', [
            'report_type' => $context['report_type'] ?? 'anonymous',
            'category'    => (string) $cats[ $index ],
        ] );
        $this->send_reply( $phone, $this->db->get_message( 'report_body_prompt' ) );
    }

    private function handle_report_body( string $phone, string $body, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'report_cancelled' ) );
            $this->back_to_reader_menu( $phone );
            return;
        }

        $type     = (string) ( $context['report_type'] ?? 'anonymous' );
        $category = (string) ( $context['category'] ?? '' );
        $ref      = $this->db->create_report( $type, $type === 'confidential' ? $phone : null, $category, $body );

        // Reenvía el reporte al número responsable configurado (si lo hay).
        $this->maybe_forward_report( $ref, $type, $category, $body, $type === 'confidential' ? $phone : null );

        $key = $type === 'anonymous' ? 'report_saved_anon' : 'report_saved_conf';
        // Acción del log de salida sin filtrar el cuerpo del reporte.
        $this->send_reply( $phone, $this->interpolate( $this->db->get_message( $key ), [ 'ref' => $ref ] ), 'report_received' );
        $this->back_to_reader_menu( $phone );
    }

    /**
     * Reenvía un reporte recién recibido al número responsable (opción
     * caag_report_forward_number). El envío va directo por el bridge y el log
     * se registra redactado: el cuerpo nunca queda en crudo en la base.
     */
    private function maybe_forward_report( string $ref, string $type, string $category, string $body, ?string $contact ): void {
        $to = preg_replace( '/[^0-9]/', '', (string) get_option( 'caag_report_forward_number', '' ) );
        if ( $to === '' || strlen( $to ) < 7 ) {
            return;
        }

        $lines = [
            '🛡️ *Nuevo reporte* ' . $ref,
            'Tipo: ' . $this->report_type_label( $type ),
            'Tema: ' . ( $category !== '' ? $category : '—' ),
        ];
        if ( $type === 'confidential' && $contact ) {
            $lines[] = 'Contacto: +' . $contact;
        }
        $lines[] = '';
        $lines[] = $body;

        $this->bridge->send_message( $to, implode( "\n", $lines ) );
        $this->db->log_message( $to, 'out', '[reporte reenviado] ' . $ref, 'report_forwarded' );
    }

    private function report_categories(): array {
        $cats = get_option( 'caag_report_categories', [] );
        return is_array( $cats ) && ! empty( $cats ) ? array_values( $cats ) : [ 'Bullying / acoso', 'Seguridad', 'Otro' ];
    }

    // -------- A6 Sugerencias / quejas --------

    private function suggestion_start( string $phone ): void {
        $this->db->set_user_state( $phone, 'alu_suggestion_body' );
        $this->send_reply( $phone, $this->db->get_message( 'suggestion_prompt' ) );
    }

    private function handle_suggestion_body( string $phone, string $body, string $body_lc ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'suggestion_cancelled' ) );
            $this->back_to_reader_menu( $phone );
            return;
        }
        $this->db->create_suggestion( $phone, $body );
        $this->send_reply( $phone, $this->db->get_message( 'suggestion_saved' ), 'suggestion_received' );
        $this->back_to_reader_menu( $phone );
    }

    // -------- A7 FAQ institucional --------

    private function faq_show( string $phone ): void {
        $faq = get_option( 'caag_faq', [] );
        if ( ! is_array( $faq ) || empty( $faq ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'faq_none' ) );
            $this->back_to_reader_menu( $phone );
            return;
        }
        $lines = [ $this->db->get_message( 'faq_header' ) ];
        foreach ( $faq as $item ) {
            $lines[] = "\n*" . ( $item['q'] ?? '' ) . "*\n" . ( $item['a'] ?? '' );
        }
        $this->send_reply( $phone, implode( "\n", $lines ), 'faq' );
        $this->back_to_reader_menu( $phone );
    }

    // -------- A8 Tablón del Consejo Estudiantil --------

    private function council_open( string $phone ): void {
        $board = (string) get_option( 'caag_council_board', '' );
        $text  = $this->db->get_message( 'council_header' );
        if ( trim( $board ) !== '' ) {
            $text .= "\n\n" . $board;
        }
        $this->send_reply( $phone, $text, 'council' );
        $this->db->set_user_state( $phone, 'alu_council_menu' );
        $this->send_reply( $phone, $this->db->get_message( 'council_menu' ) );
    }

    private function handle_council_menu( string $phone, string $body_lc ): void {
        if ( $body_lc === '1' ) {
            $this->db->set_user_state( $phone, 'alu_council_proposal' );
            $this->send_reply( $phone, $this->db->get_message( 'council_proposal_prompt' ) );
        } elseif ( $this->is_cancel( $body_lc ) ) {
            $this->back_to_reader_menu( $phone );
        } else {
            $this->send_invalid_option( $phone );
        }
    }

    private function handle_council_proposal( string $phone, string $body, string $body_lc ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->back_to_reader_menu( $phone );
            return;
        }
        // Las propuestas se guardan en el buzón de sugerencias, marcadas para el consejo.
        $this->db->create_suggestion( $phone, '[Propuesta al Consejo] ' . $body );
        $this->send_reply( $phone, $this->db->get_message( 'council_proposal_saved' ), 'council_proposal' );
        $this->back_to_reader_menu( $phone );
    }

    // -------- A3 Recordatorios opt-in --------

    private function reminders_toggle( string $phone ): void {
        $new = ! $this->db->has_event_reminders( $phone );
        $this->db->set_event_reminders( $phone, $new );
        $this->send_reply(
            $phone,
            $this->db->get_message( $new ? 'reminders_on' : 'reminders_off' ),
            'reminders_toggle'
        );
        $this->back_to_reader_menu( $phone );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function send_reply( string $phone, string $message, string $action = '' ): void {
        if ( trim( $message ) === '' ) {
            error_log( '[CaagBot] send_reply omitido: mensaje vacío (¿plantilla faltante?) acción=' . $action );
            return;
        }
        $this->bridge->send_message( $phone, $message );
        $this->db->log_message( $phone, 'out', $message, $action );
    }

    private function send_invalid_option( string $phone ): void {
        $this->send_reply( $phone, $this->db->get_message( 'invalid_option' ) );
    }

    private function send_publish_result( string $phone, array $result ): void {
        if ( $result['success'] ) {
            $key = ( $result['status'] ?? 'publish' ) === 'draft' ? 'draft_success' : 'publish_success';
            $this->send_reply(
                $phone,
                $this->interpolate( $this->db->get_message( $key ), [ 'permalink' => $result['permalink'] ] ),
                'publish_post'
            );
        } else {
            $this->send_reply( $phone, $this->db->get_message( 'error_generic' ) );
        }
    }

    private function cancel_publish( string $phone ): void {
        delete_transient( $this->media_key( $phone ) );
        $this->db->set_user_state( $phone, 'admin_menu' );
        $this->send_reply( $phone, $this->db->get_message( 'publish_cancelled' ) );
        $this->send_reply( $phone, $this->db->get_message( 'admin_menu' ) );
    }

    private function cancel_edit( string $phone ): void {
        $this->db->set_user_state( $phone, 'admin_menu' );
        $this->send_reply( $phone, $this->db->get_message( 'edit_cancelled' ) );
        $this->send_reply( $phone, $this->db->get_message( 'admin_menu' ) );
    }

    private function media_key( string $phone ): string {
        return 'caag_pubmedia_' . $phone;
    }

    /** Valida la estructura de media recibida del bridge. Devuelve null si no es válida. */
    private function sanitize_media( $media ): ?array {
        if ( ! is_array( $media ) || empty( $media['data_base64'] ) || empty( $media['mime'] ) ) {
            return null;
        }

        $allowed = [ 'image/jpeg', 'image/png', 'image/webp' ];
        $mime    = strtolower( (string) $media['mime'] );
        if ( ! in_array( $mime, $allowed, true ) ) {
            return null;
        }

        return [
            'mime'        => $mime,
            'data_base64' => (string) $media['data_base64'],
            'filename'    => sanitize_file_name( (string) ( $media['filename'] ?? 'whatsapp-image' ) ),
        ];
    }

    private function format_posts_list( array $posts ): string {
        $lines = [];
        foreach ( $posts as $i => $p ) {
            $lines[] = ( $i + 1 ) . '. ' . $p['title'] . "\n   " . $p['permalink'];
        }
        return implode( "\n\n", $lines );
    }

    private function format_categories_list( array $cats ): string {
        $lines = [];
        foreach ( $cats as $i => $c ) {
            $lines[] = ( $i + 1 ) . '. ' . $c['name'];
        }
        return implode( "\n", $lines );
    }

    private function interpolate( string $template, array $vars ): string {
        foreach ( $vars as $key => $value ) {
            $template = str_replace( '{' . $key . '}', (string) $value, $template );
        }
        return $template;
    }

    private function is_cancel( string $input ): bool {
        return in_array( $input, [ '0', 'cancelar', 'cancel' ], true );
    }

    private function is_valid_date( string $date ): bool {
        $dt = DateTime::createFromFormat( 'Y-m-d', $date );
        return $dt && $dt->format( 'Y-m-d' ) === $date;
    }

    /** Valida 'AAAA-MM-DD HH:MM' y devuelve 'Y-m-d H:i:s', o null si es inválido. */
    private function parse_datetime( string $input ): ?string {
        $dt = DateTime::createFromFormat( 'Y-m-d H:i', $input );
        if ( ! $dt || $dt->format( 'Y-m-d H:i' ) !== $input ) {
            return null;
        }
        return $dt->format( 'Y-m-d H:i:s' );
    }

    private function report_type_label( string $type ): string {
        return $type === 'confidential' ? 'Confidencial' : 'Anónimo';
    }

    private function status_label( string $status ): string {
        return match ( $status ) {
            'new'       => '🆕 Nuevo',
            'in_review' => '🔎 En revisión',
            'resolved'  => '✅ Resuelto',
            default     => $status,
        };
    }

    private function short_date( string $datetime ): string {
        $ts = strtotime( $datetime );
        return $ts ? date_i18n( 'd/m/Y', $ts ) : $datetime;
    }
}
