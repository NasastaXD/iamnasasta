<?php
/**
 * Importador de calificaciones. Idempotente por (alumno, materia, periodo).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Importer_Grades extends Cead_Acad_Importer_Base {

	public function type()  { return 'grades'; }
	public function label() { return __( 'Calificaciones', 'cead-acad' ); }

	public function fields() {
		return [
			'documento' => [ 'label' => __( 'Documento alumno', 'cead-acad' ), 'required' => true ],
			'curso'     => [ 'label' => __( 'Curso (título)', 'cead-acad' ),    'required' => true ],
			'materia'   => [ 'label' => __( 'Materia (nombre)', 'cead-acad' ),  'required' => true ],
			'periodo'   => [ 'label' => __( 'Periodo', 'cead-acad' ),           'required' => true ],
			'nota'      => [ 'label' => __( 'Nota numérica', 'cead-acad' ),     'required' => false ],
			'letra'     => [ 'label' => __( 'Letra', 'cead-acad' ),             'required' => false ],
			'comentario'=> [ 'label' => __( 'Comentario', 'cead-acad' ),        'required' => false ],
		];
	}

	public function validate_row( $row ) {
		$doc     = trim( (string) ( $row['documento'] ?? '' ) );
		$curso   = trim( (string) ( $row['curso']     ?? '' ) );
		$materia = trim( (string) ( $row['materia']   ?? '' ) );
		$periodo = trim( (string) ( $row['periodo']   ?? '' ) );
		$nota    = trim( (string) ( $row['nota']      ?? '' ) );

		if ( ! $doc || ! $curso || ! $materia || ! $periodo ) {
			return [ 'level' => 'error', 'message' => __( 'Faltan campos obligatorios.', 'cead-acad' ) ];
		}
		if ( ! $this->find_student_id( $doc ) ) {
			/* translators: %s: número de documento del alumno */
			return [ 'level' => 'error', 'message' => sprintf( __( 'Alumno con documento %s no encontrado. Importá primero el alumnado.', 'cead-acad' ), $doc ) ];
		}
		if ( ! $this->resolve_course_id( $curso ) ) {
			/* translators: %s: nombre del curso leído del archivo */
			return [ 'level' => 'error', 'message' => sprintf( __( 'Curso "%s" no existe.', 'cead-acad' ), $curso ) ];
		}
		if ( $nota !== '' && ! is_numeric( $nota ) ) {
			/* translators: %s: valor de nota leído del archivo */
			return [ 'level' => 'error', 'message' => sprintf( __( 'Nota inválida: %s', 'cead-acad' ), $nota ) ];
		}
		return [ 'level' => 'ok' ];
	}

	public function commit_row( $row, $job_id ) {
		global $wpdb;
		$student_id = $this->find_student_id( $row['documento'] );
		$course_id  = $this->resolve_course_id( $row['curso'] );
		$subject_id = $this->resolve_or_create_subject_term( $row['materia'] );
		$period     = sanitize_text_field( $row['periodo'] );
		$score      = isset( $row['nota'] )       && $row['nota'] !== ''   ? (float) $row['nota'] : null;
		$letter     = isset( $row['letra'] )      ? sanitize_text_field( $row['letra'] ) : '';
		$comments   = isset( $row['comentario'] ) ? sanitize_textarea_field( $row['comentario'] ) : '';

		$table = cead_acad_table( 'grades' );
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE student_user_id = %d AND course_id = %d AND subject_term_id = %d AND period = %s",
			$student_id, $course_id, $subject_id, $period
		) );

		$data = [
			'student_user_id' => (int) $student_id,
			'course_id'       => (int) $course_id,
			'subject_term_id' => (int) $subject_id,
			'period'          => $period,
			'score'           => $score,
			'letter'          => $letter,
			'comments'        => $comments,
			'recorded_by'     => get_current_user_id(),
			'recorded_at'     => current_time( 'mysql', 1 ),
			'import_job_id'   => (int) $job_id,
		];
		$formats = [ '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%d', '%s', '%d' ];

		if ( $existing ) {
			$wpdb->update( $table, $data, [ 'id' => (int) $existing ], $formats, [ '%d' ] );
		} else {
			$wpdb->insert( $table, $data, $formats );
		}
		return true;
	}

	protected function find_student_id( $doc ) {
		$users = get_users( [
			'meta_key'   => '_cead_acad_document_id',
			'meta_value' => $doc,
			'number'     => 1,
			'fields'     => 'ID',
		] );
		return $users ? (int) $users[0] : 0;
	}

	protected function resolve_course_id( $title ) {
		$posts = get_posts( [
			'post_type'      => Cead_Acad_Courses_CPT::POST_TYPE,
			'title'          => $title,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );
		return $posts ? (int) $posts[0] : 0;
	}

	protected function resolve_or_create_subject_term( $name ) {
		$term = get_term_by( 'name', $name, Cead_Acad_Resources_CPT::TAX_SUBJECT );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		$res = wp_insert_term( $name, Cead_Acad_Resources_CPT::TAX_SUBJECT );
		return is_wp_error( $res ) ? 0 : (int) $res['term_id'];
	}
}
