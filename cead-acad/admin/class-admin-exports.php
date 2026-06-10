<?php
/**
 * Pantalla "CEAD Académico → Exportar": descarga masiva en CSV de usuarios,
 * calificaciones, inscripciones y comunicados. Cierra el ciclo de los
 * importadores (mismo patrón de descarga que el export de encuestas).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Admin_Exports {

	public function boot() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
		add_action( 'admin_post_cead_acad_export_data', [ $this, 'handle_export' ] );
	}

	/** Mismo gate que los importadores: secretaría/dirección/admin. */
	protected function user_can_export() {
		return current_user_can( 'cead_acad_import_data' ) || current_user_can( 'manage_options' );
	}

	public function register_menu() {
		if ( ! cead_acad_user_is_staff() || ! $this->user_can_export() ) {
			return;
		}
		add_submenu_page(
			'cead-acad',
			__( 'Exportar', 'cead-acad' ),
			__( 'Exportar', 'cead-acad' ),
			'read',
			'cead-acad-exports',
			[ $this, 'render' ]
		);
	}

	public function render() {
		if ( ! $this->user_can_export() ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}

		$types = [
			'users'      => [ __( 'Usuarios', 'cead-acad' ), __( 'Todos los usuarios del plugin con rol, email, teléfono, documento y cursos.', 'cead-acad' ) ],
			'grades'     => [ __( 'Calificaciones', 'cead-acad' ), __( 'Boletín completo: alumno, curso, materia, período, nota y comentarios.', 'cead-acad' ) ],
			'roster'     => [ __( 'Inscripciones', 'cead-acad' ), __( 'Relación usuario↔curso con rol en el curso, estado y fechas.', 'cead-acad' ) ],
			'broadcasts' => [ __( 'Comunicados', 'cead-acad' ), __( 'Listado de comunicados con audiencia, destinatarios y lecturas.', 'cead-acad' ) ],
		];

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Exportar datos', 'cead-acad' ) . '</h1>';
		echo '<p>' . esc_html__( 'Descargá los datos del sistema en CSV (UTF-8, compatible con Excel). Útil para respaldos y reportes.', 'cead-acad' ) . '</p>';

		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		foreach ( $types as $type => [ $label, $desc ] ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=cead_acad_export_data&type=' . $type ), 'cead_acad_export_' . $type );
			echo '<tr>';
			echo '<td style="width:160px"><strong>' . esc_html( $label ) . '</strong></td>';
			echo '<td>' . esc_html( $desc ) . '</td>';
			echo '<td style="width:140px;text-align:right"><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Descargar CSV', 'cead-acad' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	public function handle_export() {
		$type = sanitize_key( $_GET['type'] ?? '' );
		check_admin_referer( 'cead_acad_export_' . $type );
		if ( ! $this->user_can_export() ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}

		switch ( $type ) {
			case 'users':
				[ $header, $rows ] = $this->users_data();
				break;
			case 'grades':
				[ $header, $rows ] = $this->grades_data();
				break;
			case 'roster':
				[ $header, $rows ] = $this->roster_data();
				break;
			case 'broadcasts':
				[ $header, $rows ] = $this->broadcasts_data();
				break;
			default:
				wp_die( esc_html__( 'Tipo de exportación inválido.', 'cead-acad' ) );
		}

		Cead_Acad_Audit::log( 'data_exported', [
			'payload' => [ 'type' => $type, 'rows' => count( $rows ) ],
		] );

		$filename = 'cead-' . $type . '-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo "\xEF\xBB\xBF"; // BOM utf8 para Excel.
		echo $this->to_csv( $header, $rows );
		exit;
	}

	// -------------------------------------------------------------------------
	// Datasets
	// -------------------------------------------------------------------------

	protected function users_data() {
		$roles_cfg = Cead_Acad_Capabilities::roles();
		$users     = get_users( [
			'role__in' => array_keys( $roles_cfg ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		] );

		$header = [
			__( 'ID', 'cead-acad' ), __( 'Usuario', 'cead-acad' ), __( 'Nombre', 'cead-acad' ),
			__( 'Email', 'cead-acad' ), __( 'Teléfono', 'cead-acad' ), __( 'Documento', 'cead-acad' ),
			__( 'Rol', 'cead-acad' ), __( 'Cursos', 'cead-acad' ), __( 'Alta', 'cead-acad' ),
		];
		// Precarga el meta de todos los usuarios de una (evita N+1 por fila).
		update_meta_cache( 'user', wp_list_pluck( $users, 'ID' ) );

		$rows = [];
		foreach ( $users as $u ) {
			$role_labels = [];
			foreach ( (array) $u->roles as $r ) {
				$role_labels[] = $roles_cfg[ $r ]['display'] ?? $r;
			}
			$course_ids    = Cead_Acad_Courses_Roster::courses_for_user( $u->ID );
			$course_titles = array_filter( array_map( 'get_the_title', $course_ids ) );

			$rows[] = [
				$u->ID,
				$u->user_login,
				$u->display_name,
				$u->user_email,
				get_user_meta( $u->ID, '_cead_acad_phone', true ),
				get_user_meta( $u->ID, '_cead_acad_document_id', true ),
				implode( ', ', $role_labels ),
				implode( ', ', $course_titles ),
				$u->user_registered,
			];
		}
		return [ $header, $rows ];
	}

	protected function grades_data() {
		global $wpdb;
		$t       = cead_acad_table( 'grades' );
		$records = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY course_id ASC, student_user_id ASC, period ASC", ARRAY_A ) ?: [];

		// Precarga de cachés para evitar N+1 (un query por alumno/curso/materia).
		$this->prime_caches(
			array_map( 'intval', wp_list_pluck( $records, 'student_user_id' ) ),
			array_map( 'intval', wp_list_pluck( $records, 'course_id' ) ),
			array_map( 'intval', wp_list_pluck( $records, 'subject_term_id' ) )
		);

		$header = [
			__( 'Alumno', 'cead-acad' ), __( 'Documento', 'cead-acad' ), __( 'Curso', 'cead-acad' ),
			__( 'Materia', 'cead-acad' ), __( 'Período', 'cead-acad' ), __( 'Nota', 'cead-acad' ),
			__( 'Letra', 'cead-acad' ), __( 'Comentarios', 'cead-acad' ), __( 'Registrada el', 'cead-acad' ),
		];
		$rows = [];
		foreach ( $records as $g ) {
			$student = get_user_by( 'id', (int) $g['student_user_id'] );
			$subject = get_term( (int) $g['subject_term_id'], Cead_Acad_Resources_CPT::TAX_SUBJECT );

			$rows[] = [
				$student ? $student->display_name : '#' . $g['student_user_id'],
				$student ? get_user_meta( $student->ID, '_cead_acad_document_id', true ) : '',
				get_the_title( (int) $g['course_id'] ) ?: '#' . $g['course_id'],
				( $subject && ! is_wp_error( $subject ) ) ? $subject->name : '#' . $g['subject_term_id'],
				$g['period'],
				$g['score'],
				$g['letter'],
				$g['comments'],
				$g['recorded_at'],
			];
		}
		return [ $header, $rows ];
	}

	protected function roster_data() {
		global $wpdb;
		$t       = cead_acad_table( 'roster' );
		$records = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY course_id ASC, user_id ASC", ARRAY_A ) ?: [];

		$this->prime_caches(
			array_map( 'intval', wp_list_pluck( $records, 'user_id' ) ),
			array_map( 'intval', wp_list_pluck( $records, 'course_id' ) )
		);

		$header = [
			__( 'Usuario', 'cead-acad' ), __( 'Email', 'cead-acad' ), __( 'Curso', 'cead-acad' ),
			__( 'Rol en el curso', 'cead-acad' ), __( 'Estado', 'cead-acad' ),
			__( 'Desde', 'cead-acad' ), __( 'Hasta', 'cead-acad' ),
		];
		$rows = [];
		foreach ( $records as $r ) {
			$u = get_user_by( 'id', (int) $r['user_id'] );
			$rows[] = [
				$u ? $u->display_name : '#' . $r['user_id'],
				$u ? $u->user_email : '',
				get_the_title( (int) $r['course_id'] ) ?: '#' . $r['course_id'],
				$r['role_in_course'],
				$r['status'],
				$r['start_date'],
				$r['end_date'],
			];
		}
		return [ $header, $rows ];
	}

	protected function broadcasts_data() {
		global $wpdb;
		$reads_table = cead_acad_table( 'broadcast_reads' );

		$posts = get_posts( [
			'post_type'      => Cead_Acad_Broadcasts_CPT::POST_TYPE,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		// Precarga los autores de una sola vez (evita un query por comunicado).
		$this->prime_caches( array_map( 'intval', wp_list_pluck( $posts, 'post_author' ) ) );

		$header = [
			__( 'ID', 'cead-acad' ), __( 'Título', 'cead-acad' ), __( 'Estado', 'cead-acad' ),
			__( 'Publicado', 'cead-acad' ), __( 'Autor', 'cead-acad' ), __( 'Audiencia', 'cead-acad' ),
			__( 'Destinatarios', 'cead-acad' ), __( 'Lecturas', 'cead-acad' ),
		];
		$rows = [];
		foreach ( $posts as $p ) {
			$audience   = Cead_Acad_Audiences::get( 'broadcast', $p->ID );
			$recipients = 'publish' === $p->post_status ? Cead_Acad_Broadcasts_Feed::resolve_recipient_user_ids( $p->ID ) : [];
			$reads      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reads_table} WHERE broadcast_id = %d", $p->ID ) );
			$author     = get_user_by( 'id', (int) $p->post_author );

			$rows[] = [
				$p->ID,
				get_the_title( $p ),
				$p->post_status,
				$p->post_date,
				$author ? $author->display_name : '',
				wp_strip_all_tags( Cead_Acad_Audiences::describe( $audience ) ),
				count( $recipients ),
				$reads,
			];
		}
		return [ $header, $rows ];
	}

	/**
	 * Precarga en caché usuarios, posts y términos para evitar el patrón N+1
	 * (un query por fila dentro de los loops de exportación).
	 *
	 * @param int[] $user_ids
	 * @param int[] $post_ids
	 * @param int[] $term_ids
	 */
	protected function prime_caches( array $user_ids = [], array $post_ids = [], array $term_ids = [] ) {
		$user_ids = array_filter( array_unique( $user_ids ) );
		$post_ids = array_filter( array_unique( $post_ids ) );
		$term_ids = array_filter( array_unique( $term_ids ) );

		if ( $user_ids ) {
			// cache_users() precarga los objetos WP_User; el meta lo traemos aparte.
			cache_users( $user_ids );
			update_meta_cache( 'user', $user_ids );
		}
		if ( $post_ids ) {
			_prime_post_caches( $post_ids, false, false );
		}
		if ( $term_ids ) {
			// Calienta la caché de términos con una sola consulta.
			get_terms( [ 'include' => $term_ids, 'hide_empty' => false ] );
		}
	}

	// -------------------------------------------------------------------------
	// CSV
	// -------------------------------------------------------------------------

	protected function to_csv( array $header, array $rows ) {
		$fh = fopen( 'php://memory', 'r+' );
		fputcsv( $fh, array_map( [ __CLASS__, 'sanitize_cell' ], $header ), ',', '"', '\\' );
		foreach ( $rows as $row ) {
			fputcsv( $fh, array_map( [ __CLASS__, 'sanitize_cell' ], $row ), ',', '"', '\\' );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}

	/** CSV injection guard (mismo criterio que el export de encuestas). */
	protected static function sanitize_cell( $cell ) {
		$cell = (string) $cell;
		if ( $cell !== '' && in_array( $cell[0], [ '=', '+', '-', '@' ], true ) ) {
			$cell = "'" . $cell;
		}
		return $cell;
	}
}
