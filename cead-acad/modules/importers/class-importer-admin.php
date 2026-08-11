<?php
/**
 * UI admin: hub + upload + mapping + preview + commit.
 * Submenu "Importadores" bajo "CEAD Académico".
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Importer_Admin {

	public function boot() {
		add_action( 'admin_menu',                              [ $this, 'menu' ] );
		add_action( 'admin_post_cead_acad_import_upload',      [ $this, 'handle_upload' ] );
		add_action( 'admin_post_cead_acad_import_map',         [ $this, 'handle_mapping' ] );
		add_action( 'admin_post_cead_acad_import_commit',      [ $this, 'handle_commit' ] );
		add_action( 'admin_post_cead_acad_import_download_template', [ $this, 'handle_template' ] );
		add_action( 'admin_post_cead_acad_import_errors_csv',  [ $this, 'handle_errors_csv' ] );
	}

	public function menu() {
		// Gating por ROL (no por cap custom, que puede no haberse asignado).
		// Si es staff, registramos con 'read' (toda sesión logueada lo tiene),
		// así WordPress no bloquea con "you are not allowed". El control real
		// lo hace cead_acad_user_is_staff() acá y en cada handler.
		if ( ! cead_acad_user_is_staff() ) {
			return;
		}
		add_submenu_page(
			'cead-acad',
			__( 'Importadores', 'cead-acad' ),
			__( 'Importadores', 'cead-acad' ),
			'read',
			'cead-acad-importers',
			[ $this, 'render' ]
		);
	}

	public static function importers() {
		return [
			'students' => new Cead_Acad_Importer_Students(),
			'grades'   => new Cead_Acad_Importer_Grades(),
			'courses'  => new Cead_Acad_Importer_Courses(),
			'events'   => new Cead_Acad_Importer_Events(),
			'horarios' => new Cead_Acad_Importer_Horarios(),
		];
	}

	public function render() {
		if ( ! cead_acad_user_is_staff() ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		$job_id = isset( $_GET['job'] ) ? (int) $_GET['job'] : 0;
		if ( $job_id ) {
			$this->render_job_screen( $job_id );
			return;
		}
		include CEAD_ACAD_DIR . 'admin/views/importers-hub.php';
	}

	protected function render_job_screen( $job_id ) {
		$job = Cead_Acad_Importer_Job::find( $job_id );
		if ( ! $job ) {
			wp_die( esc_html__( 'Job no encontrado.', 'cead-acad' ) );
		}
		$importer = $this->importer_for( $job['type'] );
		if ( ! $importer ) {
			wp_die( esc_html__( 'Tipo de importador desconocido.', 'cead-acad' ) );
		}

		switch ( $job['status'] ) {
			case 'uploaded':
				$this->view_mapping( $job, $importer );
				return;
			case 'mapped':
			case 'validated':
				$this->view_preview( $job, $importer );
				return;
			case 'committed':
				$this->view_result( $job, $importer );
				return;
		}
		wp_die( esc_html__( 'Estado de job desconocido.', 'cead-acad' ) );
	}

	public function importer_for( $type ) {
		$all = self::importers();
		return $all[ $type ] ?? null;
	}

	protected function view_mapping( $job, $importer ) {
		[ $headers, $preview_rows ] = Cead_Acad_Importer_Reader::preview( $job['stored_path'], 5 );
		$suggested = $importer->suggest_mapping( $headers );
		$fields    = $importer->fields();
		include CEAD_ACAD_DIR . 'admin/views/importer-mapping.php';
	}

	protected function view_preview( $job, $importer ) {
		[ $headers, $rows ] = Cead_Acad_Importer_Reader::read_all( $job['stored_path'] );
		$mapping = Cead_Acad_Importer_Job::get_mapping( $job );
		$report  = Cead_Acad_Importer_Job::get_report( $job );
		$fields  = $importer->fields();
		include CEAD_ACAD_DIR . 'admin/views/importer-preview.php';
	}

	protected function view_result( $job, $importer ) {
		$report = Cead_Acad_Importer_Job::get_report( $job );
		include CEAD_ACAD_DIR . 'admin/views/importer-result.php';
	}

	public function handle_upload() {
		if ( ! cead_acad_user_is_staff() ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		check_admin_referer( 'cead_acad_import_upload' );

		/*
		 * Antes acá se rechazaba la subida desde el teléfono. Era una suposición
		 * sobre el dispositivo, no una limitación real: el archivo se sube igual
		 * y el mapeo funciona igual. Y `wp_is_mobile()` se decide por el
		 * user-agent, así que una tablet o un navegador de escritorio en modo
		 * responsive quedaban bloqueados sin motivo.
		 *
		 * Si la pantalla chica hace incómodo el paso de mapeo, eso se resuelve
		 * con CSS, no prohibiéndole a alguien usar su propia herramienta.
		 */

		$type = sanitize_key( $_POST['import_type'] ?? '' );
		$importer = $this->importer_for( $type );
		if ( ! $importer ) {
			wp_die( esc_html__( 'Tipo de importador no válido.', 'cead-acad' ) );
		}
		if ( empty( $_FILES['csv_file']['name'] ) ) {
			wp_die( esc_html__( 'Subí un archivo.', 'cead-acad' ) );
		}

		$file = $_FILES['csv_file'];
		if ( $file['size'] > 10 * 1024 * 1024 ) {
			wp_die( esc_html__( 'Archivo demasiado grande (máx 10MB).', 'cead-acad' ) );
		}

		// Validar extensión (CSV/TXT siempre; XLSX si el entorno lo soporta).
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, Cead_Acad_Importer_Reader::allowed_extensions(), true ) ) {
			wp_die( esc_html__( 'Formato no admitido. Subí un archivo .csv o .xlsx.', 'cead-acad' ) );
		}

		// Mover a uploads/cead-acad/imports/<hash>/<filename>.
		$base = Cead_Acad_Importer_Job::upload_base();
		Cead_Acad_Importer_Base::ensure_upload_dir( $base );
		$hash = wp_generate_password( 12, false );
		$dir  = $base . '/' . $hash;
		Cead_Acad_Importer_Base::ensure_upload_dir( $dir );

		$safe_name = sanitize_file_name( $file['name'] );
		$dest = $dir . '/' . $safe_name;
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			wp_die( esc_html__( 'No se pudo mover el archivo subido.', 'cead-acad' ) );
		}
		@chmod( $dest, 0644 );

		$job_id = Cead_Acad_Importer_Job::create( $type, $file['name'], $dest );

		wp_safe_redirect( admin_url( 'admin.php?page=cead-acad-importers&job=' . (int) $job_id ) );
		exit;
	}

	public function handle_mapping() {
		if ( ! cead_acad_user_is_staff() ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		check_admin_referer( 'cead_acad_import_map' );

		$job_id = (int) ( $_POST['job_id'] ?? 0 );
		$job = Cead_Acad_Importer_Job::find( $job_id );
		if ( ! $job ) { wp_die( esc_html__( 'Job no encontrado.', 'cead-acad' ) ); }

		$mapping = [];
		foreach ( (array) ( $_POST['mapping'] ?? [] ) as $idx => $field ) {
			$mapping[ (string) (int) $idx ] = sanitize_key( $field );
		}
		Cead_Acad_Importer_Job::set_mapping( $job_id, $mapping );

		// Validar in-line.
		$importer = $this->importer_for( $job['type'] );
		[ , $rows ] = Cead_Acad_Importer_Reader::read_all( $job['stored_path'] );
		$report = $importer->validate_all( $rows, $mapping );
		Cead_Acad_Importer_Job::update( $job_id, [
			'rows_total'  => (string) count( $rows ),
			'rows_failed' => (string) $report['summary']['failed'],
			'rows_ok'     => (string) $report['summary']['ok'],
			'report'      => wp_json_encode( $report ),
			'status'      => 'validated',
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=cead-acad-importers&job=' . (int) $job_id ) );
		exit;
	}

	public function handle_commit() {
		if ( ! cead_acad_user_is_staff() ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		check_admin_referer( 'cead_acad_import_commit' );

		$job_id = (int) ( $_POST['job_id'] ?? 0 );
		$job = Cead_Acad_Importer_Job::find( $job_id );
		if ( ! $job ) { wp_die( esc_html__( 'Job no encontrado.', 'cead-acad' ) ); }

		$importer = $this->importer_for( $job['type'] );
		$mapping  = Cead_Acad_Importer_Job::get_mapping( $job );
		[ , $rows ] = Cead_Acad_Importer_Reader::read_all( $job['stored_path'] );

		$ok = $importer->commit_all( $rows, $mapping, $job_id );
		Cead_Acad_Importer_Job::mark_committed( $job_id, $ok );

		Cead_Acad_Audit::log( 'import_committed', [
			'entity_type' => 'import_job',
			'entity_id'   => $job_id,
			'payload'     => [ 'type' => $job['type'], 'rows' => count( $rows ), 'ok' => $ok ],
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=cead-acad-importers&job=' . (int) $job_id ) );
		exit;
	}

	public function handle_template() {
		if ( ! cead_acad_user_is_staff() ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		check_admin_referer( 'cead_acad_import_template' );
		$type = sanitize_key( $_GET['type'] ?? '' );
		$file = CEAD_ACAD_DIR . 'assets/csv-templates/' . $type . '.csv';
		if ( ! file_exists( $file ) ) {
			wp_die( esc_html__( 'Plantilla no disponible.', 'cead-acad' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="plantilla-' . $type . '.csv"' );
		readfile( $file );
		exit;
	}

	public function handle_errors_csv() {
		if ( ! cead_acad_user_is_staff() ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		check_admin_referer( 'cead_acad_import_errors_csv' );
		$job_id = (int) ( $_GET['job'] ?? 0 );
		$job = Cead_Acad_Importer_Job::find( $job_id );
		if ( ! $job ) { wp_die( esc_html__( 'Job no encontrado.', 'cead-acad' ) ); }

		$report = Cead_Acad_Importer_Job::get_report( $job );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="errores-job-' . $job_id . '.csv"' );
		echo "\xEF\xBB\xBF";
		$fh = fopen( 'php://output', 'w' );
		fputcsv( $fh, [ 'Fila', 'Nivel', 'Mensaje' ] );
		foreach ( ( $report['rows'] ?? [] ) as $r ) {
			fputcsv( $fh, [ $r['n'], $r['level'], $r['message'] ] );
		}
		fclose( $fh );
		exit;
	}
}
