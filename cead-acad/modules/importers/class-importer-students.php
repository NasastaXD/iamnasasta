<?php
/**
 * Importador de alumnos. Crea wp_users con rol cead_acad_student,
 * los meta de identidad, y si hay course_id los suma al roster.
 * Idempotente por documento_id (CI) o por email.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Importer_Students extends Cead_Acad_Importer_Base {

	public function type()  { return 'students'; }
	public function label() { return __( 'Alumnado', 'cead-acad' ); }

	public function fields() {
		return [
			'documento'   => [ 'label' => __( 'Documento (CI)', 'cead-acad' ),   'required' => true ],
			'nombres'     => [ 'label' => __( 'Nombres', 'cead-acad' ),         'required' => true ],
			'apellidos'   => [ 'label' => __( 'Apellidos', 'cead-acad' ),       'required' => true ],
			'email'       => [ 'label' => __( 'Email', 'cead-acad' ),           'required' => false ],
			'fecha_nac'   => [ 'label' => __( 'Fecha nacimiento', 'cead-acad' ),'required' => false ],
			'telefono'    => [ 'label' => __( 'Teléfono', 'cead-acad' ),        'required' => false ],
			'curso'       => [ 'label' => __( 'Curso (título exacto)', 'cead-acad' ), 'required' => false ],
		];
	}

	public function validate_row( $row ) {
		$doc = trim( (string) ( $row['documento'] ?? '' ) );
		$nom = trim( (string) ( $row['nombres']   ?? '' ) );
		$ape = trim( (string) ( $row['apellidos'] ?? '' ) );
		if ( $doc === '' ) {
			return [ 'level' => 'error', 'message' => __( 'Falta documento.', 'cead-acad' ) ];
		}
		if ( $nom === '' || $ape === '' ) {
			return [ 'level' => 'error', 'message' => __( 'Faltan nombres o apellidos.', 'cead-acad' ) ];
		}
		$email = trim( (string) ( $row['email'] ?? '' ) );
		if ( $email !== '' && ! is_email( $email ) ) {
			return [ 'level' => 'error', 'message' => sprintf( __( 'Email inválido: %s', 'cead-acad' ), $email ) ];
		}
		// Resolución de curso (warn si no existe).
		$curso = trim( (string) ( $row['curso'] ?? '' ) );
		if ( $curso !== '' && ! $this->resolve_course_id( $curso ) ) {
			return [ 'level' => 'warn', 'message' => sprintf( __( 'Curso "%s" no encontrado — alumno se crea sin curso.', 'cead-acad' ), $curso ) ];
		}
		return [ 'level' => 'ok' ];
	}

	public function commit_row( $row, $job_id ) {
		$doc      = sanitize_text_field( (string) $row['documento'] );
		$nombres  = sanitize_text_field( (string) $row['nombres'] );
		$apell    = sanitize_text_field( (string) $row['apellidos'] );
		$email    = isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '';
		$fecha    = isset( $row['fecha_nac'] ) ? sanitize_text_field( $row['fecha_nac'] ) : '';
		$tel      = isset( $row['telefono'] )  ? sanitize_text_field( $row['telefono'] )  : '';
		$curso_in = isset( $row['curso'] ) ? trim( (string) $row['curso'] ) : '';

		$display = trim( $nombres . ' ' . $apell );

		// Buscar user existente por meta documento o por email.
		$user_id = $this->find_user_by_document( $doc );
		if ( ! $user_id && $email ) {
			$existing = get_user_by( 'email', $email );
			if ( $existing ) { $user_id = (int) $existing->ID; }
		}

		if ( $user_id ) {
			// Update.
			wp_update_user( [
				'ID'           => $user_id,
				'display_name' => $display,
				'first_name'   => $nombres,
				'last_name'    => $apell,
				'user_email'   => $email ?: get_userdata( $user_id )->user_email,
			] );
		} else {
			// Create.
			$login = $this->generate_login( $doc, $nombres, $apell );
			$user_id = wp_insert_user( [
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 16, true ),
				'user_email'   => $email ?: ( $login . '@noemail.local' ),
				'first_name'   => $nombres,
				'last_name'    => $apell,
				'display_name' => $display,
				'role'         => 'cead_acad_student',
			] );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}

		update_user_meta( $user_id, '_cead_acad_legal_name',  $display );
		update_user_meta( $user_id, '_cead_acad_document_id', $doc );
		if ( $fecha ) update_user_meta( $user_id, '_cead_acad_birthdate', $fecha );
		if ( $tel )   update_user_meta( $user_id, '_cead_acad_phone',     $tel );
		update_user_meta( $user_id, '_cead_acad_imported_via_job', (int) $job_id );

		// Roster si hay curso.
		if ( $curso_in !== '' ) {
			$course_id = $this->resolve_course_id( $curso_in );
			if ( $course_id ) {
				Cead_Acad_Courses_Roster::add( (int) $user_id, (int) $course_id, 'student' );
				update_user_meta( $user_id, '_cead_acad_current_course_id', (int) $course_id );
			}
		}

		return true;
	}

	protected function find_user_by_document( $doc ) {
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

	protected function generate_login( $doc, $nombres, $apell ) {
		$base = sanitize_user( strtolower( $nombres . '.' . $apell ), true );
		$base = preg_replace( '/[^a-z0-9._\-]+/', '', $base );
		if ( $base === '' ) {
			$base = 'alumno' . preg_replace( '/\D+/', '', $doc );
		}
		$login = $base;
		$i = 2;
		while ( username_exists( $login ) ) {
			$login = $base . $i;
			$i++;
		}
		return $login;
	}
}
