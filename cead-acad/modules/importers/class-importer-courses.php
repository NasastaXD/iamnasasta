<?php
/**
 * Importador de cursos. Crea posts cead_acad_course con cohort + grade_level
 * (taxonomías) y meta (turno, división del tema). Idempotente por título.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Importer_Courses extends Cead_Acad_Importer_Base {

	public function type()  { return 'courses'; }
	public function label() { return __( 'Cursos', 'cead-acad' ); }

	public function fields() {
		return [
			'titulo'      => [ 'label' => __( 'Título del curso', 'cead-acad' ),               'required' => true ],
			'cohorte'     => [ 'label' => __( 'Cohorte / año lectivo', 'cead-acad' ),         'required' => true ],
			'grado'       => [ 'label' => __( 'Grado (1ro/2do/3ro)', 'cead-acad' ),           'required' => false ],
			'turno'       => [ 'label' => __( 'Turno (manana/tarde/noche)', 'cead-acad' ),    'required' => false ],
			'division'    => [ 'label' => __( 'División (título del bachillerato)', 'cead-acad' ), 'required' => false ],
			'descripcion' => [ 'label' => __( 'Descripción', 'cead-acad' ),                   'required' => false ],
		];
	}

	public function validate_row( $row ) {
		$titulo  = trim( (string) ( $row['titulo'] ?? '' ) );
		$cohorte = trim( (string) ( $row['cohorte'] ?? '' ) );
		if ( $titulo === '' ) {
			return [ 'level' => 'error', 'message' => __( 'Falta título del curso.', 'cead-acad' ) ];
		}
		if ( $cohorte === '' ) {
			return [ 'level' => 'error', 'message' => __( 'Falta cohorte.', 'cead-acad' ) ];
		}
		$turno = trim( (string) ( $row['turno'] ?? '' ) );
		if ( $turno !== '' && ! in_array( strtolower( $turno ), [ 'manana', 'mañana', 'tarde', 'noche' ], true ) ) {
			/* translators: %s: turno leído del archivo */
			return [ 'level' => 'warn', 'message' => sprintf( __( 'Turno desconocido "%s" — se importa sin turno.', 'cead-acad' ), $turno ) ];
		}
		return [ 'level' => 'ok' ];
	}

	public function commit_row( $row, $job_id ) {
		$titulo  = sanitize_text_field( (string) $row['titulo'] );
		$cohorte = sanitize_text_field( (string) $row['cohorte'] );
		$grado   = isset( $row['grado'] )       ? sanitize_text_field( $row['grado'] )       : '';
		$turno   = isset( $row['turno'] )       ? strtolower( sanitize_text_field( $row['turno'] ) ) : '';
		$div_in  = isset( $row['division'] )    ? trim( (string) $row['division'] )          : '';
		$desc    = isset( $row['descripcion'] ) ? sanitize_textarea_field( $row['descripcion'] ) : '';

		if ( 'mañana' === $turno ) { $turno = 'manana'; }
		if ( $turno && ! in_array( $turno, [ 'manana', 'tarde', 'noche' ], true ) ) {
			$turno = '';
		}

		// Idempotencia por título exacto.
		$existing = get_posts( [
			'post_type'      => Cead_Acad_Courses_CPT::POST_TYPE,
			'title'          => $titulo,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post_status'    => 'any',
		] );

		$post_data = [
			'post_type'    => Cead_Acad_Courses_CPT::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $titulo,
			'post_content' => $desc,
		];

		if ( $existing ) {
			$post_data['ID'] = (int) $existing[0];
			$post_id = wp_update_post( $post_data, true );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Taxonomías.
		wp_set_object_terms( $post_id, [ $cohorte ], Cead_Acad_Courses_CPT::TAX_COHORT, false );
		if ( $grado !== '' ) {
			wp_set_object_terms( $post_id, [ $grado ], Cead_Acad_Courses_CPT::TAX_GRADE, false );
		}

		// Meta.
		if ( $turno ) {
			update_post_meta( $post_id, '_cead_acad_turno', $turno );
		}
		if ( $div_in !== '' ) {
			$div_id = $this->resolve_division_id( $div_in );
			if ( $div_id ) {
				update_post_meta( $post_id, '_cead_acad_division', (int) $div_id );
			}
		}
		update_post_meta( $post_id, '_cead_acad_imported_via_job', (int) $job_id );

		return true;
	}

	protected function resolve_division_id( $title ) {
		if ( ! post_type_exists( 'cead_division' ) ) {
			return 0;
		}
		$posts = get_posts( [
			'post_type'      => 'cead_division',
			'title'          => $title,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );
		return $posts ? (int) $posts[0] : 0;
	}
}
