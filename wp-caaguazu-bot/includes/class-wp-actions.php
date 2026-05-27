<?php
defined( 'ABSPATH' ) || exit;

class Caaguazu_WP_Actions {

    public function insert_post( string $content, int $category_id ): array {
        $title = $this->generate_title( $content );

        $post_id = wp_insert_post( [
            'post_title'    => $title,
            'post_content'  => wp_kses_post( $content ),
            'post_status'   => 'publish',
            'post_type'     => 'post',
            'post_category' => $category_id > 0 ? [ $category_id ] : [],
        ], true );

        if ( is_wp_error( $post_id ) ) {
            error_log( '[CaagBot] insert_post error: ' . $post_id->get_error_message() );
            return [ 'success' => false, 'error' => $post_id->get_error_message() ];
        }

        return [
            'success'   => true,
            'post_id'   => $post_id,
            'permalink' => get_permalink( $post_id ),
            'title'     => get_the_title( $post_id ),
        ];
    }

    public function update_post( int $post_id, string $new_content ): array {
        $result = wp_update_post( [
            'ID'           => $post_id,
            'post_content' => wp_kses_post( $new_content ),
            'post_title'   => $this->generate_title( $new_content ),
        ], true );

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

    private function generate_title( string $content ): string {
        $clean = substr( strip_tags( str_replace( [ "\n", "\r" ], ' ', $content ) ), 0, 80 );
        $clean = trim( $clean );
        return $clean !== '' ? $clean : 'Artículo sin título';
    }
}
