<?php
/**
 * Preguntas frecuentes (FAQ). El staff las redacta y edita desde wp-admin
 * (CPT cead_acad_faq: título = pregunta, contenido = respuesta) y se muestran
 * en el panel en /panel/faq.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_FAQ {

	const POST_TYPE = 'cead_acad_faq';

	public function boot() {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register() {
		register_post_type( self::POST_TYPE, [
			'labels' => [
				'name'          => __( 'Preguntas frecuentes', 'cead-acad' ),
				'singular_name' => __( 'Pregunta frecuente', 'cead-acad' ),
				'add_new'       => __( 'Agregar pregunta', 'cead-acad' ),
				'add_new_item'  => __( 'Agregar pregunta frecuente', 'cead-acad' ),
				'edit_item'     => __( 'Editar pregunta', 'cead-acad' ),
				'menu_name'     => __( 'FAQ', 'cead-acad' ),
				'not_found'     => __( 'Sin preguntas todavía.', 'cead-acad' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'cead-acad',
			'show_in_rest'    => true,
			'menu_icon'       => 'dashicons-editor-help',
			'supports'        => [ 'title', 'editor', 'page-attributes' ],
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		] );
	}

	/** FAQs publicadas, ordenadas por "orden" (menu_order) y luego título. */
	public static function all() {
		return get_posts( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
			'no_found_rows'  => true,
		] );
	}
}
