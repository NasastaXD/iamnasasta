<?php
/**
 * CPT cead_acad_event: eventos / horarios / reuniones / exámenes.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Schedule_CPT {

	const POST_TYPE = 'cead_acad_event';

	const TYPES = [ 'clase', 'reunion', 'examen', 'evento' ];

	public function boot() {
		add_action( 'init',          [ $this, 'register' ], 10 );
		add_action( 'admin_notices', [ $this, 'import_notice' ] );
	}

	public function import_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->post_type !== self::POST_TYPE ) {
			return;
		}
		echo '<div class="notice notice-info"><p>';
		printf(
			/* translators: %s URL del importador */
			esc_html__( 'Podés cargar un evento suelto con «Añadir evento», o varios de una vez desde %s. El horario semanal de clases se carga en cada Curso, no acá.', 'cead-acad' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=cead-acad-importers' ) ) . '"><strong>' . esc_html__( 'Importadores', 'cead-acad' ) . '</strong></a>'
		);
		echo '</p></div>';
	}

	public function register() {
		register_post_type( self::POST_TYPE, [
			'labels' => [
				'name'          => __( 'Eventos', 'cead-acad' ),
				'singular_name' => __( 'Evento', 'cead-acad' ),
				'edit_item'     => __( 'Editar evento', 'cead-acad' ),
				'add_new'       => __( 'Añadir evento', 'cead-acad' ),
				'add_new_item'  => __( 'Añadir evento', 'cead-acad' ),
				'new_item'      => __( 'Nuevo evento', 'cead-acad' ),
				'view_item'     => __( 'Ver evento', 'cead-acad' ),
				'search_items'  => __( 'Buscar eventos', 'cead-acad' ),
				'menu_name'     => __( 'Eventos', 'cead-acad' ),
				'not_found'     => __( 'Sin eventos.', 'cead-acad' ),
			],
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'cead-acad',
			'show_in_rest'        => true,
			'menu_icon'           => 'dashicons-calendar-alt',
			'supports'            => [ 'title', 'editor', 'author' ],
			'has_archive'         => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			// Se pueden crear a mano (uno suelto: un acto, una reunión) además de
			// importarlos en lote. Antes estaba en 'do_not_allow', lo que obligaba
			// a armar un CSV incluso para cargar un único evento.
		] );

		foreach ( [
			'_cead_acad_event_start'    => 'string',
			'_cead_acad_event_end'      => 'string',
			'_cead_acad_event_all_day'  => 'boolean',
			'_cead_acad_event_location' => 'string',
			'_cead_acad_event_type'     => 'string',
		] as $key => $type ) {
			register_post_meta( self::POST_TYPE, $key, [
				'type'              => $type,
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => static function () { return current_user_can( 'cead_acad_manage_schedule' ); },
			] );
		}
	}

	public static function type_label( $type ) {
		$labels = [
			'clase'   => __( 'Clase', 'cead-acad' ),
			'reunion' => __( 'Reunión', 'cead-acad' ),
			'examen'  => __( 'Examen', 'cead-acad' ),
			'evento'  => __( 'Evento', 'cead-acad' ),
		];
		return $labels[ $type ] ?? $type;
	}
}
