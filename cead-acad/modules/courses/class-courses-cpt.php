<?php
/**
 * CPT cead_acad_course + taxonomías cohort y grade_level.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Courses_CPT {

	const POST_TYPE = 'cead_acad_course';
	const TAX_COHORT = 'cead_acad_cohort';
	const TAX_GRADE  = 'cead_acad_grade_level';

	public function boot() {
		add_action( 'init',           [ $this, 'register' ], 10 );
		add_action( 'admin_notices',  [ $this, 'import_notice' ] );
	}

	public function import_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->post_type !== self::POST_TYPE ) {
			return;
		}
		echo '<div class="notice notice-info"><p>';
		printf(
			/* translators: %s URL del importador */
			esc_html__( 'Los cursos se crean masivamente vía %s.', 'cead-acad' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=cead-acad-importers' ) ) . '"><strong>' . esc_html__( 'Importadores → Cursos CSV', 'cead-acad' ) . '</strong></a>'
		);
		echo '</p></div>';
	}

	public function register() {
		register_post_type( self::POST_TYPE, [
			'labels' => [
				'name'          => __( 'Cursos', 'cead-acad' ),
				'singular_name' => __( 'Curso', 'cead-acad' ),
				'edit_item'     => __( 'Editar curso', 'cead-acad' ),
				'view_item'     => __( 'Ver curso', 'cead-acad' ),
				'search_items'  => __( 'Buscar cursos', 'cead-acad' ),
				'not_found'     => __( 'Sin cursos.', 'cead-acad' ),
				'menu_name'     => __( 'Cursos', 'cead-acad' ),
			],
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'cead-acad',
			'show_in_rest'        => true,
			'menu_icon'           => 'dashicons-welcome-learn-more',
			'supports'            => [ 'title', 'editor', 'excerpt', 'page-attributes' ],
			'has_archive'         => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			// Crear cursos solo desde Importadores → Cursos CSV.
			'capabilities'        => [ 'create_posts' => 'do_not_allow' ],
		] );

		register_taxonomy( self::TAX_COHORT, [ self::POST_TYPE ], [
			'labels' => [
				'name'          => __( 'Cohortes (años)', 'cead-acad' ),
				'singular_name' => __( 'Cohorte', 'cead-acad' ),
			],
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_rest'      => true,
			'hierarchical'      => false,
			'show_admin_column' => true,
		] );

		register_taxonomy( self::TAX_GRADE, [ self::POST_TYPE ], [
			'labels' => [
				'name'          => __( 'Grados', 'cead-acad' ),
				'singular_name' => __( 'Grado', 'cead-acad' ),
			],
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_rest'      => true,
			'hierarchical'      => false,
			'show_admin_column' => true,
		] );

		// Meta fields (REST exposable).
		foreach ( [
			'_cead_acad_turno'      => __( 'Turno', 'cead-acad' ),       // mañana/tarde/noche
			'_cead_acad_division'   => __( 'División', 'cead-acad' ),    // ID del CPT cead_division del tema
			'_cead_acad_delegate'   => __( 'Delegado/a', 'cead-acad' ),  // user_id
			'_cead_acad_tutor'      => __( 'Tutor/a', 'cead-acad' ),     // user_id
		] as $key => $label ) {
			register_post_meta( self::POST_TYPE, $key, [
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => static function () { return current_user_can( 'cead_acad_manage_courses' ); },
			] );
		}
	}

	/**
	 * Devuelve cursos como [ id => título ] para selects. Memoizada por request.
	 */
	public static function options() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title FROM {$wpdb->posts}
			 WHERE post_type = %s AND post_status = 'publish'
			 ORDER BY menu_order ASC, post_title ASC",
			self::POST_TYPE
		), ARRAY_A );
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['ID'] ] = $r['post_title'];
		}
		return $cache = $out;
	}
}
