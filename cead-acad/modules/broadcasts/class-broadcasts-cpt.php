<?php
/**
 * CPT cead_acad_broadcast: comunicados.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Broadcasts_CPT {

	const POST_TYPE = 'cead_acad_broadcast';
	const TAX_CAT   = 'cead_acad_broadcast_category';

	public function boot() {
		add_action( 'init', [ $this, 'register' ], 10 );
	}

	public function register() {
		register_post_type( self::POST_TYPE, [
			'labels' => [
				'name'          => __( 'Comunicados', 'cead-acad' ),
				'singular_name' => __( 'Comunicado', 'cead-acad' ),
				'add_new'       => __( 'Nuevo comunicado', 'cead-acad' ),
				'add_new_item'  => __( 'Nuevo comunicado', 'cead-acad' ),
				'edit_item'     => __( 'Editar comunicado', 'cead-acad' ),
				'view_item'     => __( 'Ver comunicado', 'cead-acad' ),
				'menu_name'     => __( 'Comunicados', 'cead-acad' ),
				'not_found'     => __( 'Sin comunicados.', 'cead-acad' ),
			],
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'cead-acad',
			'show_in_rest'        => true,
			'menu_icon'           => 'dashicons-megaphone',
			'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions' ],
			'has_archive'         => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		] );

		register_taxonomy( self::TAX_CAT, [ self::POST_TYPE ], [
			'labels' => [
				'name'          => __( 'Categorías', 'cead-acad' ),
				'singular_name' => __( 'Categoría', 'cead-acad' ),
			],
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_rest'      => true,
			'hierarchical'      => true,
			'show_admin_column' => true,
		] );

		// Seed de categorías por defecto (idempotente).
		if ( ! term_exists( 'academico', self::TAX_CAT ) ) {
			wp_insert_term( __( 'Académico', 'cead-acad' ), self::TAX_CAT, [ 'slug' => 'academico' ] );
		}
		if ( ! term_exists( 'administrativo', self::TAX_CAT ) ) {
			wp_insert_term( __( 'Administrativo', 'cead-acad' ), self::TAX_CAT, [ 'slug' => 'administrativo' ] );
		}
		if ( ! term_exists( 'eventos', self::TAX_CAT ) ) {
			wp_insert_term( __( 'Eventos', 'cead-acad' ), self::TAX_CAT, [ 'slug' => 'eventos' ] );
		}
		if ( ! term_exists( 'urgente', self::TAX_CAT ) ) {
			wp_insert_term( __( 'Urgente', 'cead-acad' ), self::TAX_CAT, [ 'slug' => 'urgente' ] );
		}
	}
}
