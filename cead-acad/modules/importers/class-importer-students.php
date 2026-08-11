<?php
/**
 * Importador de alumnos. Formato mínimo: Alumno | Curso | Mail.
 * Crea wp_users con rol cead_acad_student y los suma al roster del curso.
 *
 * Email es OPCIONAL: si una fila no lo trae, igual se importa (se genera un
 * email interno placeholder). Dedup por documento → email → (nombre + curso).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Importer_Students extends Cead_Acad_Importer_Base {

	public function type()  { return 'students'; }
	public function label() { return __( 'Alumnado', 'cead-acad' ); }

	public function fields() {
		return [
			'nombre'    => [ 'label' => __( 'Alumno (nombre completo)', 'cead-acad' ), 'required' => true ],
			'curso'     => [ 'label' => __( 'Curso (título exacto)', 'cead-acad' ),    'required' => false ],
			'email'     => [ 'label' => __( 'Mail', 'cead-acad' ),                     'required' => false ],
			'documento' => [ 'label' => __( 'Documento (CI)', 'cead-acad' ),           'required' => false ],
			'telefono'  => [ 'label' => __( 'Teléfono', 'cead-acad' ),                 'required' => false ],
			'fecha_nac' => [ 'label' => __( 'Fecha nacimiento', 'cead-acad' ),         'required' => false ],
		];
	}

	public function validate_row( $row ) {
		$nombre = trim( (string) ( $row['nombre'] ?? '' ) );
		if ( $nombre === '' ) {
			return [ 'level' => 'error', 'message' => __( 'Falta el nombre del alumno.', 'cead-acad' ) ];
		}
		$email = trim( (string) ( $row['email'] ?? '' ) );
		if ( $email !== '' && ! is_email( $email ) ) {
			/* translators: %s: email leído del archivo */
			return [ 'level' => 'error', 'message' => sprintf( __( 'Email inválido: %s', 'cead-acad' ), $email ) ];
		}
		$curso = trim( (string) ( $row['curso'] ?? '' ) );
		if ( $curso !== '' && ! $this->resolve_course_id( $curso ) ) {
			/* translators: %s: nombre del curso leído del archivo */
			return [ 'level' => 'warn', 'message' => sprintf( __( 'Curso "%s" no encontrado — el alumno se crea sin curso.', 'cead-acad' ), $curso ) ];
		}
		return [ 'level' => 'ok' ];
	}

	public function commit_row( $row, $job_id ) {
		$nombre   = sanitize_text_field( (string) $row['nombre'] );
		$doc      = isset( $row['documento'] ) ? sanitize_text_field( $row['documento'] ) : '';
		$email    = isset( $row['email'] )     ? sanitize_email( $row['email'] )          : '';
		$fecha    = isset( $row['fecha_nac'] ) ? sanitize_text_field( $row['fecha_nac'] ) : '';
		$tel      = isset( $row['telefono'] )  ? sanitize_text_field( $row['telefono'] )  : '';
		$curso_in = isset( $row['curso'] )     ? trim( (string) $row['curso'] )           : '';

		// Separar nombre/apellido del nombre completo (heurística simple).
		$parts   = preg_split( '/\s+/', $nombre, 2 );
		$first   = $parts[0] ?? $nombre;
		$last    = $parts[1] ?? '';

		$course_id = $curso_in !== '' ? $this->resolve_course_id( $curso_in ) : 0;

		// Resolución de usuario existente: documento → email → (nombre + curso).
		$user_id = 0;
		if ( $doc !== '' ) {
			$user_id = $this->find_user_by_document( $doc );
		}
		if ( ! $user_id && $email !== '' ) {
			$existing = get_user_by( 'email', $email );
			if ( $existing ) { $user_id = (int) $existing->ID; }
		}
		if ( ! $user_id && $doc === '' && $email === '' ) {
			$user_id = $this->find_user_by_name_course( $nombre, $course_id );
		}

		if ( $user_id ) {
			$updated = wp_update_user( [
				'ID'           => $user_id,
				'display_name' => $nombre,
				'first_name'   => $first,
				'last_name'    => $last,
			] + ( $email ? [ 'user_email' => $email ] : [] ) );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		} else {
			$login = $this->generate_login( $doc, $first, $last, $nombre );
			$user_email = $email ?: $this->placeholder_email( $login );
			$user_id = wp_insert_user( [
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 16, true ),
				'user_email'   => $user_email,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => $nombre,
				'role'         => 'cead_acad_student',
			] );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}

		update_user_meta( $user_id, '_cead_acad_legal_name', $nombre );
		if ( $doc )   { update_user_meta( $user_id, '_cead_acad_document_id', $doc ); }
		if ( $fecha ) { update_user_meta( $user_id, '_cead_acad_birthdate', $fecha ); }
		if ( $tel )   { update_user_meta( $user_id, '_cead_acad_phone', $tel ); }
		if ( ! $email ) { update_user_meta( $user_id, '_cead_acad_no_email', 1 ); }
		update_user_meta( $user_id, '_cead_acad_imported_via_job', (int) $job_id );

		if ( $course_id ) {
			// Si la inscripción falla, la fila se reporta como error en vez de
			// darse por importada: el alumno existiría pero fuera del curso, y
			// el panel le mostraría todo vacío sin explicar por qué.
			if ( ! Cead_Acad_Courses_Roster::add( (int) $user_id, (int) $course_id, 'student' ) ) {
				return new WP_Error(
					'cead_acad_roster_failed',
					__( 'Se creó la cuenta pero no se pudo inscribir en el curso. Revisá y volvé a importar esta fila.', 'cead-acad' )
				);
			}
			// El meta solo se escribe si la inscripción quedó: si no, apuntaría a
			// un curso en el que la persona no está.
			update_user_meta( $user_id, '_cead_acad_current_course_id', (int) $course_id );
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

	protected function find_user_by_name_course( $nombre, $course_id ) {
		$candidates = get_users( [
			'search'         => $nombre,
			'search_columns' => [ 'display_name' ],
			'number'         => 5,
			'fields'         => 'ID',
			'role'           => 'cead_acad_student',
		] );
		foreach ( $candidates as $uid ) {
			$u = get_user_by( 'id', $uid );
			if ( $u && strcasecmp( $u->display_name, $nombre ) === 0 ) {
				if ( ! $course_id ) {
					return (int) $uid;
				}
				if ( in_array( (int) $course_id, Cead_Acad_Courses_Roster::courses_for_user( $uid ), true ) ) {
					return (int) $uid;
				}
			}
		}
		return 0;
	}

	protected function resolve_course_id( $title ) {
		$posts = get_posts( [
			'post_type'      => Cead_Acad_Courses_CPT::POST_TYPE,
			'title'          => $title,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		return $posts ? (int) $posts[0] : 0;
	}

	protected function generate_login( $doc, $first, $last, $nombre ) {
		$base = sanitize_user( strtolower( $first . ( $last ? '.' . $last : '' ) ), true );
		$base = preg_replace( '/[^a-z0-9._\-]+/', '', $base );
		if ( $base === '' && $doc !== '' ) {
			$base = 'alumno' . preg_replace( '/\D+/', '', $doc );
		}
		if ( $base === '' ) {
			$base = 'alumno' . substr( md5( $nombre . microtime() ), 0, 6 );
		}
		$login = $base;
		$i = 2;
		while ( username_exists( $login ) ) {
			$login = $base . $i;
			$i++;
		}
		return $login;
	}

	protected function placeholder_email( $login ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'cead.local';
		$email = $login . '@noemail.' . $host;
		// Evitar colisión.
		if ( email_exists( $email ) ) {
			$email = $login . '.' . substr( md5( microtime() ), 0, 5 ) . '@noemail.' . $host;
		}
		return $email;
	}
}
