<?php
/**
 * Cargar notas: quién, en qué cursos, y la carga misma.
 *
 * Hasta ahora la única carga de una nota suelta pasaba por CEADI en WhatsApp,
 * que hacía las comprobaciones dentro del motor del bot. La app necesita las
 * mismas —que el curso sea de quien carga, que el alumno siga en el curso—,
 * así que viven acá y el bot llama a esto también.
 *
 * Pensado para cargar sin señal: la app baja `opciones()` al sincronizar
 * (cursos, alumnos, materias, periodos, escala), el docente carga en el aula,
 * y la nota sale cuando vuelve la conexión. Por eso `cargar()` vuelve a
 * comprobar todo al recibirla: entre que se cargó en el teléfono y que llegó,
 * el alumno pudo cambiar de curso.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Notas {

	/**
	 * Los cursos en los que esta persona puede cargar notas.
	 *
	 * @return int[]|null Null = todos (Dirección y Secretaría).
	 */
	public static function alcance_cursos( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) { return []; }
		if ( user_can( $user_id, 'cead_acad_manage_courses' ) ) { return null; }

		$ids = class_exists( 'Cead_Acad_Courses_Roster' )
			? Cead_Acad_Courses_Roster::courses_for_user( $user_id )
			: [];
		// Cursos donde figura como tutor/a aunque no esté en el roster.
		$tutor = get_posts( [
			'post_type'      => Cead_Acad_Courses_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 50,
			'meta_key'       => '_cead_acad_tutor',
			'meta_value'     => $user_id,
		] );
		return array_values( array_unique( array_map( 'intval', array_merge( (array) $ids, (array) $tutor ) ) ) );
	}

	/** Los ids de los cursos del alcance, resolviendo «todos». */
	public static function cursos( $user_id ) {
		$alcance = self::alcance_cursos( $user_id );
		return null === $alcance
			? array_map( 'intval', array_keys( cead_acad_courses_for_select() ) )
			: $alcance;
	}

	/**
	 * Todo lo que hace falta para cargar notas sin señal.
	 *
	 * @return array|WP_Error
	 */
	public static function opciones( $user_id ) {
		if ( ! user_can( $user_id, 'cead_acad_record_grade' ) ) {
			return self::sin_permiso();
		}

		$cursos = [];
		foreach ( self::cursos( $user_id ) as $cid ) {
			if ( ! Cead_Acad_Grades_Writer::user_can_grade_course( $user_id, $cid ) ) {
				continue;
			}
			$cursos[] = [
				'id'      => $cid,
				'titulo'  => get_the_title( $cid ),
				'alumnos' => self::alumnos( $cid ),
			];
		}

		$materias = get_terms( [ 'taxonomy' => 'cead_acad_subject', 'hide_empty' => false, 'orderby' => 'name' ] );
		$materias = is_wp_error( $materias ) ? [] : array_map( static function ( $t ) {
			return [ 'id' => (int) $t->term_id, 'nombre' => (string) $t->name ];
		}, $materias );

		$W = 'Cead_Acad_Grades_Writer';
		return [
			'periodos' => $W::periods(),
			'escala'   => [
				'maxima'    => $W::score_max(),
				'aprobado'  => $W::score_pass(),
				'etiquetas' => $W::score_labels(),
			],
			'materias' => array_values( $materias ),
			'cursos'   => $cursos,
		];
	}

	/** Alumnos y delegados activos de un curso, por nombre. */
	public static function alumnos( $course_id ) {
		$ids = array_merge(
			(array) Cead_Acad_Courses_Roster::users_in_course( (int) $course_id, 'student' ),
			(array) Cead_Acad_Courses_Roster::users_in_course( (int) $course_id, 'delegate' )
		);
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) { return []; }
		return array_map( static function ( $u ) {
			return [ 'id' => (int) $u->ID, 'nombre' => (string) $u->display_name ];
		}, get_users( [ 'include' => $ids, 'orderby' => 'display_name' ] ) );
	}

	/**
	 * Carga (o corrige) una nota.
	 *
	 * @param array $args alumno_id, curso_id, materia_id | materia_nueva,
	 *                    periodo, nota, comentario, origen.
	 * @return array|WP_Error
	 */
	public static function cargar( $user_id, array $args ) {
		$user_id = (int) $user_id;
		$curso   = (int) ( $args['curso_id'] ?? 0 );
		$alumno  = (int) ( $args['alumno_id'] ?? 0 );

		if ( ! Cead_Acad_Grades_Writer::user_can_grade_course( $user_id, $curso ) ) {
			return new WP_Error( 'cead_api_sin_permiso', __( 'No podés cargar notas en ese curso.', 'cead-acad' ), [ 'status' => 403 ] );
		}
		// El alumno tiene que seguir en el curso: pudo cambiar entre que se
		// propuso (o se cargó sin señal) y que llegó.
		$del_curso = array_column( self::alumnos( $curso ), 'id' );
		if ( ! in_array( $alumno, $del_curso, true ) ) {
			return new WP_Error( 'fuera_del_curso', __( 'Ese alumno ya no figura en el curso, así que no se cargó la nota.', 'cead-acad' ), [ 'status' => 409 ] );
		}

		// Una materia nueva solo se crea si se pidió con todas las letras: un
		// typo no tiene que inventar una materia.
		$materia = (int) ( $args['materia_id'] ?? 0 );
		$nueva   = trim( (string) ( $args['materia_nueva'] ?? '' ) );
		if ( ! $materia && '' !== $nueva ) {
			$materia = (int) Cead_Acad_Grades_Writer::match_subject( $nueva, $curso, true )['term_id'];
		}
		if ( ! $materia ) {
			return new WP_Error( 'cead_grade_subject', __( 'Falta la materia.', 'cead-acad' ), [ 'status' => 400 ] );
		}

		$r = Cead_Acad_Grades_Writer::record( [
			'student_user_id' => $alumno,
			'course_id'       => $curso,
			'subject_term_id' => $materia,
			'period'          => (string) ( $args['periodo'] ?? '' ),
			'score'           => $args['nota'] ?? null,
			'comments'        => (string) ( $args['comentario'] ?? '' ),
			'recorded_by'     => $user_id,
			'source'          => (string) ( $args['origen'] ?? 'app' ),
		] );
		if ( is_wp_error( $r ) ) {
			return new WP_Error( $r->get_error_code(), $r->get_error_message(), [ 'status' => 400 ] );
		}

		$guardada = Cead_Acad_Grades_Writer::find( $alumno, $curso, $materia, Cead_Acad_Grades_Writer::norm_period( $args['periodo'] ?? '' ) );
		return [
			'id'       => (int) $r['id'],
			'creada'   => (bool) $r['created'],
			'anterior' => isset( $r['previous']['score'] ) ? (float) $r['previous']['score'] : null,
			'nota'     => $guardada ? self::fila( $guardada ) : null,
		];
	}

	/**
	 * Las notas ya cargadas de un curso.
	 *
	 * @return array|WP_Error
	 */
	public static function del_curso( $user_id, $course_id ) {
		$course_id = (int) $course_id;
		if ( ! user_can( $user_id, 'cead_acad_view_course_grades' ) ) {
			return self::sin_permiso();
		}
		$alcance = self::alcance_cursos( $user_id );
		if ( null !== $alcance && ! in_array( $course_id, $alcance, true ) ) {
			return new WP_Error( 'cead_api_sin_permiso', __( 'Ese curso no es tuyo.', 'cead-acad' ), [ 'status' => 403 ] );
		}
		return [
			'curso' => [ 'id' => $course_id, 'titulo' => get_the_title( $course_id ) ],
			'notas' => array_map( [ __CLASS__, 'fila' ], (array) Cead_Acad_Grades_Db::for_course( $course_id ) ),
		];
	}

	public static function fila( array $r ) {
		$materia = get_term( (int) $r['subject_term_id'] );
		$alumno  = get_user_by( 'id', (int) $r['student_user_id'] );
		return [
			'id'         => (int) $r['id'],
			'alumno_id'  => (int) $r['student_user_id'],
			'alumno'     => $alumno ? (string) $alumno->display_name : '',
			'materia_id' => (int) $r['subject_term_id'],
			'materia'    => ( $materia && ! is_wp_error( $materia ) ) ? (string) $materia->name : '',
			'periodo'    => (string) $r['period'],
			'nota'       => null !== $r['score'] ? (float) $r['score'] : null,
			'letra'      => (string) ( $r['letter'] ?? '' ),
			'comentario' => (string) ( $r['comments'] ?? '' ),
		];
	}

	protected static function sin_permiso() {
		return new WP_Error( 'cead_api_sin_permiso', __( 'No tenés permiso para esto.', 'cead-acad' ), [ 'status' => 403 ] );
	}
}
