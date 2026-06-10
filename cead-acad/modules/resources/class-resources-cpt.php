<?php
/**
 * CPT cead_acad_resource: recursos pedagógicos (mapas conceptuales,
 * PDFs, links, imágenes, videos).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Resources_CPT {

	const POST_TYPE = 'cead_acad_resource';

	const TAX_SUBJECT = 'cead_acad_subject';
	const TAX_TYPE    = 'cead_acad_resource_type';

	public function boot() {
		add_action( 'init', [ $this, 'register' ], 10 );
	}

	public function register() {
		register_post_type( self::POST_TYPE, [
			'labels' => [
				'name'          => __( 'Recursos', 'cead-acad' ),
				'singular_name' => __( 'Recurso', 'cead-acad' ),
				'add_new'       => __( 'Nuevo recurso', 'cead-acad' ),
				'add_new_item'  => __( 'Nuevo recurso', 'cead-acad' ),
				'edit_item'     => __( 'Editar recurso', 'cead-acad' ),
				'menu_name'     => __( 'Recursos', 'cead-acad' ),
				'not_found'     => __( 'Sin recursos.', 'cead-acad' ),
			],
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'cead-acad',
			'show_in_rest'        => true,
			'menu_icon'           => 'dashicons-portfolio',
			'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'author' ],
			'has_archive'         => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		] );

		register_taxonomy( self::TAX_SUBJECT, [ self::POST_TYPE ], [
			'labels' => [
				'name'          => __( 'Materias', 'cead-acad' ),
				'singular_name' => __( 'Materia', 'cead-acad' ),
			],
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_rest'      => true,
			'hierarchical'      => false,
			'show_admin_column' => true,
		] );

		register_taxonomy( self::TAX_TYPE, [ self::POST_TYPE ], [
			'labels' => [
				'name'          => __( 'Tipos', 'cead-acad' ),
				'singular_name' => __( 'Tipo', 'cead-acad' ),
			],
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_rest'      => true,
			'hierarchical'      => false,
			'show_admin_column' => true,
		] );

		register_post_meta( self::POST_TYPE, '_cead_acad_resource_url', [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'esc_url_raw',
			'auth_callback'     => static function () { return current_user_can( 'cead_acad_upload_resource' ); },
		] );
		register_post_meta( self::POST_TYPE, '_cead_acad_resource_attachment_id', [
			'type'              => 'integer',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => static function () { return current_user_can( 'cead_acad_upload_resource' ); },
		] );
	}

	/**
	 * Siembra tipos por defecto. Idempotente. Se llama UNA vez (activación y
	 * migración de versión), NO en cada init.
	 */
	public static function seed_terms() {
		$defaults = [
			'mapa-conceptual' => __( 'Mapa conceptual', 'cead-acad' ),
			'pdf'             => __( 'PDF', 'cead-acad' ),
			'enlace'          => __( 'Enlace', 'cead-acad' ),
			'imagen'          => __( 'Imagen', 'cead-acad' ),
			'video'           => __( 'Video', 'cead-acad' ),
		];
		foreach ( $defaults as $slug => $label ) {
			if ( ! term_exists( $slug, self::TAX_TYPE ) ) {
				wp_insert_term( $label, self::TAX_TYPE, [ 'slug' => $slug ] );
			}
		}
	}

	/**
	 * Devuelve URL del recurso (attachment o URL externa).
	 */
	public static function resource_url( $post_id ) {
		$attach = (int) get_post_meta( $post_id, '_cead_acad_resource_attachment_id', true );
		if ( $attach ) {
			$url = wp_get_attachment_url( $attach );
			if ( $url ) {
				return $url;
			}
		}
		return (string) get_post_meta( $post_id, '_cead_acad_resource_url', true );
	}
}
