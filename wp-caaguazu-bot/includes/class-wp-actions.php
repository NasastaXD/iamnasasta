<?php
defined( 'ABSPATH' ) || exit;

class Caaguazu_WP_Actions {

    public function insert_post( string $content, int $category_id, string $status = 'publish', ?array $media = null ): array {
        [ $title, $body ] = $this->split_title_body( $content );
        $status = in_array( $status, [ 'publish', 'draft' ], true ) ? $status : 'publish';

        $post_id = wp_insert_post( [
            'post_title'    => $title,
            'post_content'  => wp_kses_post( $body ),
            'post_status'   => $status,
            'post_type'     => 'post',
            'post_category' => $category_id > 0 ? [ $category_id ] : [],
        ], true );

        if ( is_wp_error( $post_id ) ) {
            error_log( '[CaagBot] insert_post error: ' . $post_id->get_error_message() );
            return [ 'success' => false, 'error' => $post_id->get_error_message() ];
        }

        if ( $media ) {
            $this->attach_featured_image( $post_id, $media );
        }

        return [
            'success'   => true,
            'post_id'   => $post_id,
            'status'    => $status,
            'permalink' => get_permalink( $post_id ),
            'title'     => get_the_title( $post_id ),
        ];
    }

    /** $mode: 'replace' reescribe todo; 'append' agrega al final conservando el título. */
    public function update_post( int $post_id, string $new_content, string $mode = 'replace' ): array {
        $existing = get_post( $post_id );
        if ( ! $existing ) {
            return [ 'success' => false, 'error' => 'Post no encontrado.' ];
        }

        if ( $mode === 'append' ) {
            $data = [
                'ID'           => $post_id,
                'post_content' => wp_kses_post( $existing->post_content . "\n\n" . $new_content ),
            ];
        } else {
            [ $title, $body ] = $this->split_title_body( $new_content );
            $data = [
                'ID'           => $post_id,
                'post_content' => wp_kses_post( $body ),
                'post_title'   => $title,
            ];
        }

        $result = wp_update_post( $data, true );

        if ( is_wp_error( $result ) ) {
            return [ 'success' => false, 'error' => $result->get_error_message() ];
        }

        return [
            'success'   => true,
            'post_id'   => $post_id,
            'permalink' => get_permalink( $post_id ),
            'title'     => get_the_title( $post_id ),
        ];
    }

    public function search_posts( string $term, int $count = 5 ): array {
        $query = new WP_Query( [
            's'                   => $term,
            'posts_per_page'      => $count,
            'post_status'         => 'publish',
            'ignore_sticky_posts' => true,
        ] );

        return array_map( fn( $p ) => [
            'id'        => $p->ID,
            'title'     => $p->post_title,
            'permalink' => get_permalink( $p->ID ),
            'date'      => get_the_date( 'd/m/Y', $p ),
        ], $query->posts );
    }

    public function trash_post( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return [ 'success' => false, 'error' => 'Post no encontrado.' ];
        }

        $title  = $post->post_title;
        $result = wp_trash_post( $post_id );

        if ( ! $result ) {
            return [ 'success' => false, 'error' => 'No se pudo eliminar.' ];
        }

        return [ 'success' => true, 'title' => $title ];
    }

    public function get_recent_posts( int $count = 10, int $category_id = 0 ): array {
        $args = [
            'posts_per_page' => $count,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if ( $category_id > 0 ) {
            $args['cat'] = $category_id;
        }

        $posts = get_posts( $args );

        return array_map( fn( $p ) => [
            'id'        => $p->ID,
            'title'     => $p->post_title,
            'permalink' => get_permalink( $p->ID ),
            'date'      => get_the_date( 'd/m/Y', $p ),
        ], $posts );
    }

    public function get_categories(): array {
        $allowed = get_option( 'caag_reader_categories', [] );

        $args = [ 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ];

        if ( ! empty( $allowed ) && is_array( $allowed ) ) {
            $args['include'] = array_map( 'intval', $allowed );
        }

        $cats = get_categories( $args );

        return array_map( fn( $c ) => [
            'id'   => $c->term_id,
            'name' => $c->name,
        ], $cats );
    }

    public function get_recent_posts_in_category( int $cat_id, int $count = 5 ): array {
        return $this->get_recent_posts( $count, $cat_id );
    }

    /**
     * Separa título y cuerpo: la primera línea no vacía es el título y el resto el
     * cuerpo. Si es de una sola línea, el título son los primeros 80 caracteres y el
     * cuerpo es el texto completo.
     *
     * @return array{0:string,1:string} [ título, cuerpo ]
     */
    private function split_title_body( string $content ): array {
        $content = trim( str_replace( "\r\n", "\n", $content ) );
        if ( $content === '' ) {
            return [ 'Artículo sin título', '' ];
        }

        $parts = explode( "\n", $content, 2 );
        $first = trim( $parts[0] );

        if ( isset( $parts[1] ) && trim( $parts[1] ) !== '' ) {
            $title = $this->clean_title( $first );
            $body  = trim( $parts[1] );
            return [ $title, $body ];
        }

        // Una sola línea: título recortado, cuerpo completo.
        return [ $this->clean_title( $first ), $content ];
    }

    private function clean_title( string $text ): string {
        $clean = trim( substr( strip_tags( str_replace( [ "\n", "\r" ], ' ', $text ) ), 0, 80 ) );
        return $clean !== '' ? $clean : 'Artículo sin título';
    }

    /**
     * Sube una imagen (recibida del bridge en base64) a la biblioteca de medios y la
     * fija como imagen destacada del post.
     *
     * @param array{mime?:string,data_base64?:string,filename?:string} $media
     */
    private function attach_featured_image( int $post_id, array $media ): bool {
        $allowed = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' ];
        $mime    = (string) ( $media['mime'] ?? '' );

        if ( ! isset( $allowed[ $mime ] ) || empty( $media['data_base64'] ) ) {
            return false;
        }

        $data = base64_decode( (string) $media['data_base64'], true );
        if ( $data === false || strlen( $data ) > 8 * 1024 * 1024 ) {
            return false;
        }

        $filename = sanitize_file_name( $media['filename'] ?? ( 'whatsapp-' . $post_id . '.' . $allowed[ $mime ] ) );
        $upload   = wp_upload_bits( $filename, null, $data );
        if ( ! empty( $upload['error'] ) ) {
            error_log( '[CaagBot] upload error: ' . $upload['error'] );
            return false;
        }

        $attachment_id = wp_insert_attachment( [
            'post_mime_type' => $mime,
            'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
            'post_status'    => 'inherit',
        ], $upload['file'], $post_id );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata(
            $attachment_id,
            wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
        );

        return (bool) set_post_thumbnail( $post_id, $attachment_id );
    }
}
