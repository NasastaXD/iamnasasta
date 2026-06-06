<?php
/**
 * CPT cead_acad_survey: contenedor de la encuesta (título, descripción,
 * ventana de fechas, ¿es anónima?). Las preguntas y respuestas van en
 * tablas custom (NO postmeta).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Surveys_CPT {

	const POST_TYPE = 'cead_acad_survey';

	const TYPES = [ 'radio', 'checkbox', 'text', 'long_text', 'scale', 'date' ];

	public function boot() {
		add_action( 'init', [ $this, 'register' ], 10 );
	}

	public function register() {
		register_post_type( self::POST_TYPE, [
			'labels' => [
				'name'          => __( 'Encuestas', 'cead-acad' ),
				'singular_name' => __( 'Encuesta', 'cead-acad' ),
				'add_new'       => __( 'Nueva encuesta', 'cead-acad' ),
				'add_new_item'  => __( 'Nueva encuesta', 'cead-acad' ),
				'edit_item'     => __( 'Editar encuesta', 'cead-acad' ),
				'view_item'     => __( 'Ver encuesta', 'cead-acad' ),
				'menu_name'     => __( 'Encuestas', 'cead-acad' ),
				'not_found'     => __( 'Sin encuestas.', 'cead-acad' ),
			],
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'cead-acad',
			'show_in_rest'        => false, // El editor de bloques no es óptimo para builder custom.
			'menu_icon'           => 'dashicons-feedback',
			'supports'            => [ 'title', 'editor', 'author' ],
			'has_archive'         => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		] );

		foreach ( [
			'_cead_acad_survey_opens_at'  => 'string',
			'_cead_acad_survey_closes_at' => 'string',
			'_cead_acad_survey_anonymous' => 'boolean',
		] as $key => $type ) {
			register_post_meta( self::POST_TYPE, $key, [
				'type'              => $type,
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => static function () { return current_user_can( 'cead_acad_create_survey' ); },
			] );
		}
	}

	public static function is_open( $survey_id ) {
		$opens  = (string) get_post_meta( $survey_id, '_cead_acad_survey_opens_at', true );
		$closes = (string) get_post_meta( $survey_id, '_cead_acad_survey_closes_at', true );
		$now    = current_time( 'timestamp' );
		if ( $opens && strtotime( $opens ) > $now ) {
			return false;
		}
		if ( $closes && strtotime( $closes ) < $now ) {
			return false;
		}
		return get_post_status( $survey_id ) === 'publish';
	}

	public static function is_anonymous( $survey_id ) {
		return (bool) get_post_meta( $survey_id, '_cead_acad_survey_anonymous', true );
	}
}
