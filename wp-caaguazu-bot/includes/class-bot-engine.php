<?php
defined( 'ABSPATH' ) || exit;

class Caaguazu_Bot_Engine {

    private Caaguazu_Database      $db;
    private Caaguazu_Bridge_Client $bridge;
    private Caaguazu_WP_Actions    $wp_actions;

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

        if ( $phone === '' || $body === '' ) {
            return;
        }

        // Registrar número si es primera visita (rol inicial: reader)
        $number_row = $this->db->get_number( $phone );
        if ( ! $number_row ) {
            $this->db->upsert_number( $phone, [ 'role' => 'reader', 'name' => $push_name ] );
        } elseif ( $push_name && empty( $number_row->name ) ) {
            $this->db->upsert_number( $phone, [ 'name' => $push_name ] );
        }

        $this->db->update_last_seen( $phone );
        $this->db->log_message( $phone, 'in', $body );

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

        $state_data = $this->db->get_user_state( $phone );
        $state      = $state_data['state'];
        $context    = $state_data['context'];

        $this->dispatch( $phone, $body, $body_lc, $state, $context, $push_name );
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
            'admin_menu'             => $this->handle_admin_menu( $phone, $body_lc ),
            'admin_publish_content'  => $this->handle_admin_publish_content( $phone, $body ),
            'admin_publish_category' => $this->handle_admin_publish_category( $phone, $body_lc, $context ),
            'admin_edit_list'        => $this->handle_admin_edit_list( $phone, $body_lc, $context ),
            'admin_edit_content'     => $this->handle_admin_edit_content( $phone, $body, $context ),
            'admin_delete_list'      => $this->handle_admin_delete_list( $phone, $body_lc, $context ),
            'admin_delete_confirm'   => $this->handle_admin_delete_confirm( $phone, $body_lc, $context ),
            'reader_menu'            => $this->handle_reader_menu( $phone, $body_lc, $name ),
            'reader_category_list'   => $this->handle_reader_category_list( $phone, $body_lc, $context ),
            default                  => $this->handle_idle( $phone, $name ),
        };
    }

    // -----------------------------------------------------------------------
    // Handlers de estados
    // -----------------------------------------------------------------------

    private function handle_idle( string $phone, string $name ): void {
        if ( $this->db->is_admin_phone( $phone ) ) {
            $greeting = $this->interpolate( $this->db->get_message( 'greeting_admin' ), [ 'name' => $name ?: 'Admin' ] );
            $this->db->set_user_state( $phone, 'admin_menu' );
            $this->send_reply( $phone, $greeting . "\n\n" . $this->db->get_message( 'admin_menu' ) );
        } else {
            $greeting = $this->interpolate( $this->db->get_message( 'greeting_reader' ), [ 'name' => $name ?: 'amigo/a' ] );
            $this->db->set_user_state( $phone, 'reader_menu' );
            $this->send_reply( $phone, $greeting . "\n\n" . $this->db->get_message( 'reader_menu' ) );
        }
    }

    private function handle_admin_menu( string $phone, string $body_lc ): void {
        match ( $body_lc ) {
            '1'          => $this->admin_start_publish( $phone ),
            '2'          => $this->admin_show_edit_list( $phone ),
            '3'          => $this->admin_show_delete_list( $phone ),
            '4'          => $this->admin_show_links( $phone ),
            '0', 'salir', 'cancelar' => $this->admin_exit( $phone ),
            default      => $this->send_invalid_option( $phone ),
        };
    }

    private function admin_start_publish( string $phone ): void {
        $this->db->set_user_state( $phone, 'admin_publish_content' );
        $this->send_reply( $phone, $this->db->get_message( 'publish_prompt' ) );
    }

    private function admin_show_edit_list( string $phone ): void {
        $posts = $this->wp_actions->get_recent_posts( 10 );
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
        $posts = $this->wp_actions->get_recent_posts( 10 );
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
    }

    private function admin_exit( string $phone ): void {
        $this->db->reset_user_state( $phone );
        $this->send_reply( $phone, $this->db->get_message( 'goodbye' ) );
    }

    private function handle_admin_publish_content( string $phone, string $body ): void {
        $cats = $this->wp_actions->get_categories();
        if ( empty( $cats ) ) {
            $result = $this->wp_actions->insert_post( $body, 0 );
            $this->db->reset_user_state( $phone );
            $this->send_publish_result( $phone, $result );
            return;
        }
        $this->db->set_user_state( $phone, 'admin_publish_category', [
            'pending_content' => $body,
            'categories'      => $cats,
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
            $this->db->set_user_state( $phone, 'admin_menu' );
            $this->send_reply( $phone, $this->db->get_message( 'publish_cancelled' ) );
            $this->send_reply( $phone, $this->db->get_message( 'admin_menu' ) );
            return;
        }

        $cats  = $context['categories'] ?? [];
        $index = (int) $body_lc - 1;

        if ( ! isset( $cats[ $index ] ) ) {
            $this->send_invalid_option( $phone );
            return;
        }

        $cat_id = (int) $cats[ $index ]['id'];
        $result = $this->wp_actions->insert_post( $context['pending_content'] ?? '', $cat_id );
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
        $this->db->set_user_state( $phone, 'admin_edit_content', [ 'post_id' => $post['id'], 'post_title' => $post['title'] ] );
        $this->send_reply(
            $phone,
            $this->interpolate(
                $this->db->get_message( 'edit_content_prompt' ),
                [ 'title' => $post['title'] ]
            )
        );
    }

    private function handle_admin_edit_content( string $phone, string $body, array $context ): void {
        if ( $this->is_cancel( strtolower( trim( $body ) ) ) ) {
            $this->db->set_user_state( $phone, 'admin_menu' );
            $this->send_reply( $phone, $this->db->get_message( 'edit_cancelled' ) );
            $this->send_reply( $phone, $this->db->get_message( 'admin_menu' ) );
            return;
        }

        $post_id = (int) ( $context['post_id'] ?? 0 );
        $result  = $this->wp_actions->update_post( $post_id, $body );
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

    private function handle_reader_menu( string $phone, string $body_lc, string $name ): void {
        match ( $body_lc ) {
            '1'                       => $this->reader_show_categories( $phone ),
            '2'                       => $this->reader_show_recent( $phone ),
            '0', 'adios', 'adiós', 'salir' => $this->reader_exit( $phone ),
            default                   => $this->send_invalid_option( $phone ),
        };
    }

    private function reader_show_categories( string $phone ): void {
        $cats = $this->wp_actions->get_categories();
        if ( empty( $cats ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'no_posts_found' ) );
            return;
        }
        $this->db->set_user_state( $phone, 'reader_category_list', [ 'categories' => $cats ] );
        $this->send_reply(
            $phone,
            $this->interpolate(
                $this->db->get_message( 'category_prompt' ),
                [ 'category_list' => $this->format_categories_list( $cats ) ]
            )
        );
    }

    private function reader_show_recent( string $phone ): void {
        $count = (int) get_option( 'caag_posts_per_page_reader', 5 );
        $posts = $this->wp_actions->get_recent_posts( $count );
        if ( empty( $posts ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'no_posts_found' ) );
            return;
        }
        $header = $this->db->get_message( 'recent_posts_header' );
        $this->send_reply( $phone, $header . "\n\n" . $this->format_posts_list( $posts ) );
    }

    private function reader_exit( string $phone ): void {
        $this->db->reset_user_state( $phone );
        $this->send_reply( $phone, $this->db->get_message( 'goodbye' ) );
    }

    private function handle_reader_category_list( string $phone, string $body_lc, array $context ): void {
        if ( $this->is_cancel( $body_lc ) ) {
            $this->db->set_user_state( $phone, 'reader_menu' );
            $this->send_reply( $phone, $this->db->get_message( 'reader_menu' ) );
            return;
        }

        $cats  = $context['categories'] ?? [];
        $index = (int) $body_lc - 1;

        if ( ! isset( $cats[ $index ] ) ) {
            $this->send_invalid_option( $phone );
            return;
        }

        $cat_id = (int) $cats[ $index ]['id'];
        $count  = (int) get_option( 'caag_posts_per_page_reader', 5 );
        $posts  = $this->wp_actions->get_recent_posts_in_category( $cat_id, $count );
        $this->db->set_user_state( $phone, 'reader_menu' );

        if ( empty( $posts ) ) {
            $this->send_reply( $phone, $this->db->get_message( 'no_posts_found' ) );
        } else {
            $header = $this->db->get_message( 'recent_posts_header' );
            $this->send_reply( $phone, $header . "\n\n" . $this->format_posts_list( $posts ) );
        }

        $this->send_reply( $phone, $this->db->get_message( 'reader_menu' ) );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function send_reply( string $phone, string $message, string $action = '' ): void {
        $this->bridge->send_message( $phone, $message );
        $this->db->log_message( $phone, 'out', $message, $action );
    }

    private function send_invalid_option( string $phone ): void {
        $this->send_reply( $phone, $this->db->get_message( 'invalid_option' ) );
    }

    private function send_publish_result( string $phone, array $result ): void {
        if ( $result['success'] ) {
            $this->send_reply(
                $phone,
                $this->interpolate( $this->db->get_message( 'publish_success' ), [ 'permalink' => $result['permalink'] ] ),
                'publish_post'
            );
        } else {
            $this->send_reply( $phone, $this->db->get_message( 'error_generic' ) );
        }
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
}
