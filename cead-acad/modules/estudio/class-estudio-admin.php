<?php
/**
 * wp-admin → CEAD Académico → Estudio piloto.
 *
 * Para el equipo del estudio (rol `cead_acad_study_team`, o administradores):
 *   - configuración (código de institución, inicio, modo de consentimiento),
 *   - tasa de respuesta por mes y rol,
 *   - importación de consentimientos en papel (también dirección/secretaría),
 *   - línea base en papel (spec 4.3),
 *   - revisión manual de las respuestas abiertas (spec 5.4),
 *   - exportación de investigación (spec 7): ZIP con CSV seudonimizados,
 *     k ≥ 5, sin campos personales, con diccionario. Cada exportación queda
 *     en el audit_log.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Estudio_Admin {

	const CAP_EXPORT = 'cead_acad_research_export';
	const CAP_GESTION = 'cead_acad_manage_study';

	public function boot() {
		add_action( 'admin_menu', [ $this, 'menu' ], 30 );
		foreach ( [ 'config', 'consentimientos', 'linea_base', 'revisar', 'exportar', 'importar_config' ] as $a ) {
			add_action( "admin_post_cead_acad_estudio_{$a}", [ $this, $a ] );
		}
	}

	public function menu() {
		if ( ! current_user_can( self::CAP_GESTION ) && ! current_user_can( 'cead_acad_manage_invitations' ) ) {
			return;
		}
		add_submenu_page( 'cead-acad', __( 'Estudio piloto', 'cead-acad' ), __( 'Estudio piloto', 'cead-acad' ), 'read', 'cead-acad-estudio', [ $this, 'render' ] );
	}

	/* --------------------------------------------------------- exportación */

	/**
	 * Arma los CSV a partir de filas ya leídas. Pura (probada en
	 * EstudioExportTest): filtra por consentimiento vigente AL EXPORTAR,
	 * aplica k-anonimato y nunca emite columnas prohibidas.
	 *
	 * @param array<string,true> $con_consentimiento seudónimos con consentimiento vigente
	 * @return array{archivos: array<string,string>, resumen: array}
	 */
	public static function construir_exportacion( array $eventos, array $respuestas, array $asistencias, array $linea_base, array $con_consentimiento, $k = Cead_Acad_Estudio_Logica::K ) {
		$vale = static function ( $f ) use ( $con_consentimiento ) {
			return ! empty( $f['con_consentimiento'] ) && isset( $con_consentimiento[ $f['seudonimo'] ] );
		};
		$ev = Cead_Acad_Estudio_Logica::k_anonimato( array_values( array_filter( $eventos, $vale ) ), $k );
		$rs = Cead_Acad_Estudio_Logica::k_anonimato( array_values( array_filter( $respuestas, $vale ) ), $k );

		$cols_ev = [ 'seudonimo', 'institucion', 'rol', 'curso_grupo', 'evento', 'entidad_tipo', 'entidad_id', 'canal', 'ts_cliente', 'ts_servidor', 'meta' ];
		$filas_ev = array_map( static function ( $f ) use ( $cols_ev ) {
			return array_map( static function ( $c ) use ( $f ) { return $f[ $c ] ?? ''; }, $cols_ev );
		}, $ev['filas'] );

		$preguntas = [];
		foreach ( $rs['filas'] as $r ) {
			$preguntas += array_flip( array_keys( (array) json_decode( (string) $r['cerradas'], true ) ) );
		}
		$preguntas = array_keys( $preguntas );
		sort( $preguntas );
		$cols_rs = array_merge( [ 'seudonimo', 'institucion', 'rol', 'curso_grupo', 'mes', 'instrumento', 'version' ], $preguntas, [ 'sus_puntaje', 'abiertas_revisadas', 'ts_cliente', 'ts_servidor' ] );
		$filas_rs = array_map( static function ( $r ) use ( $preguntas ) {
			$c = (array) json_decode( (string) $r['cerradas'], true );
			return array_merge(
				[ $r['seudonimo'], $r['institucion'], $r['rol'], $r['curso_grupo'], $r['mes'], $r['instrumento_clave'], $r['instrumento_version'] ],
				array_map( static function ( $p ) use ( $c ) { return $c[ $p ] ?? ''; }, $preguntas ),
				// El texto libre solo sale en su versión revisada a mano (spec 5.4).
				[ $r['sus_puntaje'], (string) ( $r['abiertas_revisadas'] ?? '' ), $r['ts_cliente'], $r['ts_servidor'] ]
			);
		}, $rs['filas'] );

		$asis = array_values( array_filter( $asistencias, static function ( $a ) use ( $k ) { return (int) $a['alumnos'] >= $k; } ) );
		$cols_as = [ 'institucion', 'curso_grupo', 'fecha', 'tomas', 'alumnos', 'presente', 'ausente', 'tarde', 'justificado' ];

		$dicc = [
			[ 'eventos.csv', 'seudonimo', 'HMAC-SHA256(sal del estudio, id de usuario). No reversible sin la sal, que se destruye al cerrar el estudio.' ],
			[ 'eventos.csv', 'curso_grupo', "Nivel/grado generalizado. '(agrupado)' = cursos con menos de 5 personas, juntados." ],
			[ 'eventos.csv', 'ts_cliente', 'Hora del dispositivo, UTC: la hora real del hecho (puede ser anterior a la llegada si fue sin conexión).' ],
			[ 'eventos.csv', 'ts_servidor', 'Hora de llegada al servidor, UTC.' ],
			[ 'eventos.csv', 'meta', 'JSON sin datos personales (destinatarios, duracion_s, ts_cliente_dudoso…).' ],
			[ 'respuestas.csv', '<bloque>.<pregunta>', 'Respuesta cerrada: Likert 1–5 (1 = muy en desacuerdo, 5 = muy de acuerdo) u opción. Ver config/estudio-encuestas.json.' ],
			[ 'respuestas.csv', 'sus_puntaje', 'System Usability Scale 0–100 (meses 4 y 12).' ],
			[ 'respuestas.csv', 'abiertas_revisadas', 'Texto libre revisado a mano (sin nombres). Vacío si no se revisó.' ],
			[ 'asistencias.csv', 'alumnos', 'Alumnos distintos con registro ese día en ese nivel. Filas con menos de 5 se suprimen.' ],
			[ 'linea_base_papel.csv', 'minutos_toma', 'Minutos cronometrados de la toma en papel (método anterior).' ],
		];

		$csv = [ 'Cead_Acad_Admin_Exports', 'to_csv' ];
		return [
			'archivos' => [
				'eventos.csv'          => $csv( $cols_ev, $filas_ev ),
				'respuestas.csv'       => $csv( $cols_rs, $filas_rs ),
				'asistencias.csv'      => $csv( $cols_as, array_map( static function ( $a ) use ( $cols_as ) {
					return array_map( static function ( $c ) use ( $a ) { return $a[ $c ] ?? ''; }, $cols_as );
				}, $asis ) ),
				'linea_base_papel.csv' => $csv( [ 'institucion', 'fecha', 'curso_grupo', 'minutos_toma', 'consolidado_llego_en', 'discrepancias' ], array_map( 'array_values', $linea_base ) ),
				'diccionario.csv'      => $csv( [ 'archivo', 'columna', 'descripcion' ], $dicc ),
			],
			'resumen' => [
				'eventos' => count( $ev['filas'] ), 'respuestas' => count( $rs['filas'] ), 'asistencias' => count( $asis ),
				'suprimidas' => $ev['suprimidas'] + $rs['suprimidas'] + ( count( $asistencias ) - count( $asis ) ),
				'generalizadas' => $ev['generalizadas'] + $rs['generalizadas'],
			],
		];
	}

	public function exportar() {
		check_admin_referer( 'cead_acad_estudio_exportar' );
		if ( ! current_user_can( self::CAP_EXPORT ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'cead-acad' ), 403 );
		}
		global $wpdb;
		$sal = Cead_Acad_Estudio::sal();
		$cod = (string) Cead_Acad_Estudio::config()['codigo'];

		// Consentimiento vigente calculado AHORA: quien se retiró después del evento tampoco sale.
		$con = [];
		$uids = get_users( [ 'role__in' => array_keys( Cead_Acad_Estudio::ROLES ), 'fields' => 'ID' ] );
		foreach ( $uids as $uid ) {
			if ( 'activo' === Cead_Acad_Estudio::estado_consentimiento( $uid ) ) {
				$con[ Cead_Acad_Estudio_Logica::seudonimo( $sal, $uid ) ] = true;
			}
		}
		$eventos    = $wpdb->get_results( 'SELECT * FROM ' . cead_acad_table( 'estudio_eventos' ) . ' ORDER BY id', ARRAY_A ) ?: [];
		$respuestas = $wpdb->get_results( 'SELECT * FROM ' . cead_acad_table( 'estudio_respuestas' ) . ' ORDER BY id', ARRAY_A ) ?: [];

		$asistencias = [];
		$ta = cead_acad_table( 'asistencias' );
		foreach ( $wpdb->get_results( "SELECT curso_id, fecha, COUNT(DISTINCT alumno_id) AS alumnos, COUNT(DISTINCT SUBSTRING_INDEX(clave_idempotencia, ':', 1)) AS tomas,
				SUM(estado = 'presente') AS presente, SUM(estado = 'ausente') AS ausente, SUM(estado = 'tarde') AS tarde, SUM(estado = 'justificado') AS justificado
				FROM {$ta} GROUP BY curso_id, fecha ORDER BY fecha", ARRAY_A ) ?: [] as $a ) { // phpcs:ignore WordPress.DB
			$t = wp_get_object_terms( (int) $a['curso_id'], Cead_Acad_Courses_CPT::TAX_GRADE, [ 'fields' => 'names' ] );
			$asistencias[] = [ 'institucion' => $cod, 'curso_grupo' => ( ! is_wp_error( $t ) && $t ) ? $t[0] : Cead_Acad_Estudio_Logica::AGRUPADO ] + $a;
		}
		$linea = array_map( static function ( $f ) use ( $cod ) {
			return [ $cod, $f['fecha'] ?? '', $f['curso_grupo'] ?? '', $f['minutos_toma'] ?? '', $f['consolidado_llego_en'] ?? '', $f['discrepancias'] ?? '' ];
		}, (array) get_option( 'cead_acad_estudio_linea_base', [] ) );

		$x = self::construir_exportacion( $eventos, $respuestas, $asistencias, $linea, $con );
		$archivo = wp_tempnam( 'estudio.zip' );
		$zip = new ZipArchive();
		$zip->open( $archivo, ZipArchive::OVERWRITE );
		foreach ( $x['archivos'] as $n => $c ) {
			$zip->addFromString( $n, "\xEF\xBB\xBF" . $c );
		}
		$zip->close();
		Cead_Acad_Audit::log( 'estudio_exportacion', [ 'payload' => $x['resumen'] ] );
		Cead_Acad_Estudio::bitacora( 'operacion', 'exportacion_investigacion', $x['resumen'] );

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="estudio-' . $cod . '-' . gmdate( 'Y-m-d' ) . '.zip"' );
		readfile( $archivo );
		@unlink( $archivo );
		exit;
	}

	/* --------------------------------------------------------- acciones */

	public function config() {
		check_admin_referer( 'cead_acad_estudio_config' );
		if ( ! current_user_can( self::CAP_GESTION ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'cead-acad' ), 403 );
		}
		$c = Cead_Acad_Estudio::config();
		$c['activo']              = ! empty( $_POST['activo'] );
		$c['codigo']              = preg_match( '/^I[0-9]{1,4}$/', (string) ( $_POST['codigo'] ?? '' ) ) ? (string) $_POST['codigo'] : $c['codigo'];
		$c['inicio']              = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $_POST['inicio'] ?? '' ) ) ? (string) $_POST['inicio'] : $c['inicio'];
		$c['consentimiento_modo'] = 'individual' === ( $_POST['consentimiento_modo'] ?? '' ) ? 'individual' : 'acuerdo_institucional';
		$c['permitir_retiro']     = ! empty( $_POST['permitir_retiro'] );
		$antes = Cead_Acad_Estudio::config();
		update_option( Cead_Acad_Estudio::OPCION, $c );
		Cead_Acad_Estudio::bitacora( 'configuracion', 'estudio_configurado', [ 'antes' => $antes, 'despues' => $c ] );
		Cead_Acad_Audit::log( 'estudio_configurado', [ 'payload' => array_intersect_key( $c, array_flip( [ 'activo', 'codigo', 'inicio', 'consentimiento_modo', 'permitir_retiro' ] ) ) ] );
		$this->volver( 'config' );
	}

	public function consentimientos() {
		check_admin_referer( 'cead_acad_estudio_consentimientos' );
		if ( ! current_user_can( self::CAP_GESTION ) && ! current_user_can( 'cead_acad_manage_invitations' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'cead-acad' ), 403 );
		}
		$texto = isset( $_FILES['csv']['tmp_name'] ) && is_uploaded_file( $_FILES['csv']['tmp_name'] ) ? (string) file_get_contents( $_FILES['csv']['tmp_name'] ) : '';
		$quien = get_current_user_id();
		$r = Cead_Acad_Estudio_Consentimientos::importar_csv( $texto, static function ( $id ) {
			$u = is_email( $id ) ? get_user_by( 'email', $id ) : get_user_by( 'login', $id );
			return $u ? $u->ID : 0;
		}, static function ( $uid, $tipo, $version, $fecha ) use ( $quien ) {
			Cead_Acad_Estudio_Consentimientos::dar( $uid, $tipo, $version, $quien, $fecha );
		} );
		Cead_Acad_Audit::log( 'estudio_consentimientos_importados', [ 'payload' => [ 'ok' => $r['ok'], 'errores' => count( $r['errores'] ) ] ] );
		set_transient( 'cead_acad_estudio_import_' . $quien, $r, 300 );
		Cead_Acad_Estudio::bitacora( 'operacion', 'consentimientos_importados', [ 'ok' => $r['ok'], 'errores' => count( $r['errores'] ) ] );
		$this->volver( 'consentimientos' );
	}

	public function linea_base() {
		check_admin_referer( 'cead_acad_estudio_linea_base' );
		if ( ! current_user_can( self::CAP_GESTION ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'cead-acad' ), 403 );
		}
		$lb   = (array) get_option( 'cead_acad_estudio_linea_base', [] );
		$curso = (int) ( $_POST['curso_id'] ?? 0 );
		$t    = $curso ? wp_get_object_terms( $curso, Cead_Acad_Courses_CPT::TAX_GRADE, [ 'fields' => 'names' ] ) : [];
		$lb[] = [
			'fecha'                => sanitize_text_field( wp_unslash( $_POST['fecha'] ?? '' ) ),
			'curso_grupo'          => ( ! is_wp_error( $t ) && $t ) ? $t[0] : '',
			'minutos_toma'         => max( 0, (float) ( $_POST['minutos_toma'] ?? 0 ) ),
			'consolidado_llego_en' => sanitize_text_field( wp_unslash( $_POST['consolidado_llego_en'] ?? '' ) ),
			'discrepancias'        => max( 0, (int) ( $_POST['discrepancias'] ?? 0 ) ),
			'canal'                => 'papel',
		];
		update_option( 'cead_acad_estudio_linea_base', $lb, false );
		Cead_Acad_Estudio::bitacora( 'operacion', 'linea_base_cargada', [ 'fecha' => end( $lb )['fecha'] ] );
		$this->volver( 'linea_base' );
	}

	public function revisar() {
		check_admin_referer( 'cead_acad_estudio_revisar' );
		if ( ! current_user_can( self::CAP_GESTION ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'cead-acad' ), 403 );
		}
		global $wpdb;
		$limpias = array_filter( array_map( static function ( $v ) {
			return mb_substr( sanitize_textarea_field( wp_unslash( (string) $v ) ), 0, 500 );
		}, (array) ( $_POST['abiertas'] ?? [] ) ), 'strlen' );
		$wpdb->update( cead_acad_table( 'estudio_respuestas' ), [ 'abiertas_revisadas' => wp_json_encode( (object) $limpias ), 'revisada_en' => current_time( 'mysql', 1 ) ], [ 'id' => (int) ( $_POST['id'] ?? 0 ) ] );
		Cead_Acad_Estudio::bitacora( 'operacion', 'abierta_revisada', [ 'respuesta' => (int) ( $_POST['id'] ?? 0 ) ] );
		$this->volver( 'revisar' );
	}

	/**
	 * Encuestas editadas en el panel maestro (acción exportar_config_wp): se
	 * sube el JSON, se valida y reemplaza al de la spec. Lo ya enviado no
	 * cambia; queda en la bitácora con el hash del archivo y qué cambió.
	 */
	public function importar_config() {
		check_admin_referer( 'cead_acad_estudio_importar_config' );
		if ( ! current_user_can( self::CAP_GESTION ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'cead-acad' ), 403 );
		}
		$texto = isset( $_FILES['config']['tmp_name'] ) && is_uploaded_file( $_FILES['config']['tmp_name'] ) ? (string) file_get_contents( $_FILES['config']['tmp_name'] ) : '';
		$cfg   = json_decode( $texto, true );
		$err   = Cead_Acad_Estudio_Logica::validar_config( $cfg );
		if ( $err ) {
			set_transient( 'cead_acad_estudio_config_err_' . get_current_user_id(), $err, 300 );
			$this->volver( 'importar_config_error' );
		}
		$antes = Cead_Acad_Estudio::instrumentos();
		update_option( 'cead_acad_estudio_config', $cfg, false );
		Cead_Acad_Estudio::bitacora( 'encuestas', 'config_importada', [
			'sha256'      => hash( 'sha256', $texto ),
			'roles'       => $cfg['roles'] ?? [ 'alumno', 'docente' ],
			'meses'       => count( $cfg['meses'] ),
			'versiones'   => self::versiones_de( $cfg ),
			'antes'       => self::versiones_de( $antes ),
		] );
		$this->volver( 'importar_config' );
	}

	/** bloque → versión vigente, para leer la bitácora de un vistazo. */
	public static function versiones_de( $cfg ) {
		$out = [];
		foreach ( (array) ( $cfg['instrumentos'] ?? [] ) as $bloque => $def ) {
			foreach ( (array) $def as $rol => $b ) {
				if ( is_array( $b ) && ! empty( $b['versiones'] ) ) {
					$out[ "$bloque.$rol" ] = end( $b['versiones'] )['version'] ?? null;
				}
			}
		}
		return $out;
	}

	protected function volver( $seccion ) {
		wp_safe_redirect( add_query_arg( [ 'page' => 'cead-acad-estudio', 'ok' => $seccion ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------------------------------------ pantalla */

	public function render() {
		global $wpdb;
		$c   = Cead_Acad_Estudio::config();
		$te  = cead_acad_table( 'estudio_respuestas' );
		$sal = strlen( Cead_Acad_Estudio::sal() ) >= 32;
		$tasas = [];
		foreach ( get_posts( [ 'post_type' => Cead_Acad_Surveys_CPT::POST_TYPE, 'posts_per_page' => 30, 'meta_key' => Cead_Acad_Estudio_Encuestas::META_TIPO, 'meta_value' => 'estudio', 'fields' => 'ids' ] ) as $sid ) {
			$rol = (string) get_post_meta( $sid, '_cead_acad_estudio_rol', true );
			$destinatarios = count( get_users( [ 'role__in' => Cead_Acad_Estudio_Encuestas::DESTINOS[ $rol ] ?? [], 'fields' => 'ID' ] ) );
			$resp = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->usermeta . ' WHERE meta_key = %s', Cead_Acad_Estudio_Encuestas::META_RESPONDIDA . $sid ) );
			$tasas[] = [ (int) get_post_meta( $sid, '_cead_acad_estudio_mes', true ), $rol, $resp, $destinatarios ];
		}
		$abiertas = current_user_can( self::CAP_GESTION ) ? ( $wpdb->get_results( "SELECT id, abiertas FROM {$te} WHERE abiertas <> '{}' AND revisada_en IS NULL ORDER BY id LIMIT 20", ARRAY_A ) ?: [] ) : [];
		$import = get_transient( 'cead_acad_estudio_import_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Estudio piloto', 'cead-acad' ); ?></h1>
			<?php if ( ! $sal ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Falta CEAD_ESTUDIO_SALT en wp-config.php (mínimo 32 caracteres). Sin ella no se registra nada del estudio.', 'cead-acad' ); ?></p></div>
			<?php endif; ?>

			<?php if ( current_user_can( self::CAP_GESTION ) ) : ?>
			<h2><?php esc_html_e( 'Configuración', 'cead-acad' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cead_acad_estudio_config"><?php wp_nonce_field( 'cead_acad_estudio_config' ); ?>
				<p><label><input type="checkbox" name="activo" value="1" <?php checked( $c['activo'] ); ?>> <?php esc_html_e( 'Este sitio participa del estudio', 'cead-acad' ); ?></label></p>
				<p><label><?php esc_html_e( 'Código de institución', 'cead-acad' ); ?> <input name="codigo" value="<?php echo esc_attr( $c['codigo'] ); ?>" pattern="I[0-9]{1,4}" size="5"></label>
				   <label><?php esc_html_e( 'Inicio del estudio', 'cead-acad' ); ?> <input type="date" name="inicio" value="<?php echo esc_attr( $c['inicio'] ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Consentimiento', 'cead-acad' ); ?>
					<select name="consentimiento_modo">
						<option value="acuerdo_institucional" <?php selected( $c['consentimiento_modo'], 'acuerdo_institucional' ); ?>><?php esc_html_e( 'Acuerdo institucional (todos)', 'cead-acad' ); ?></option>
						<option value="individual" <?php selected( $c['consentimiento_modo'], 'individual' ); ?>><?php esc_html_e( 'Individual (familia + asentimiento, o propio)', 'cead-acad' ); ?></option>
					</select></label>
				   <label><input type="checkbox" name="permitir_retiro" value="1" <?php checked( $c['permitir_retiro'] ); ?>> <?php esc_html_e( 'Permitir retirarse', 'cead-acad' ); ?></label></p>
				<?php if ( $c['cerrado'] ) : ?><p><strong><?php esc_html_e( 'La recolección terminó (12 meses). Exportá por última vez y borrá CEAD_ESTUDIO_SALT de wp-config.php.', 'cead-acad' ); ?></strong></p><?php endif; ?>
				<?php submit_button( __( 'Guardar', 'cead-acad' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Tasa de respuesta', 'cead-acad' ); ?></h2>
			<table class="widefat striped" style="max-width:600px"><thead><tr><th><?php esc_html_e( 'Mes', 'cead-acad' ); ?></th><th><?php esc_html_e( 'Rol', 'cead-acad' ); ?></th><th><?php esc_html_e( 'Respondieron', 'cead-acad' ); ?></th><th>%</th></tr></thead><tbody>
			<?php foreach ( $tasas as [ $mes, $rol, $resp, $dest ] ) : ?>
				<tr><td><?php echo (int) $mes; ?></td><td><?php echo esc_html( $rol ); ?></td><td><?php echo (int) $resp . ' / ' . (int) $dest; ?></td><td><?php echo $dest ? (int) round( 100 * $resp / $dest ) : '—'; ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Consentimientos en papel (CSV)', 'cead-acad' ); ?></h2>
			<p><?php esc_html_e( 'Columnas: usuario (o email), tipo (consentimiento_familia, asentimiento_alumno, consentimiento_propio), version_documento, fecha (AAAA-MM-DD).', 'cead-acad' ); ?></p>
			<?php if ( is_array( $import ) ) : ?><p><?php echo esc_html( sprintf( __( 'Importados: %1$d · con error: %2$d', 'cead-acad' ), $import['ok'], count( $import['errores'] ) ) ); ?></p><?php endif; ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cead_acad_estudio_consentimientos"><?php wp_nonce_field( 'cead_acad_estudio_consentimientos' ); ?>
				<input type="file" name="csv" accept=".csv,text/csv" required> <?php submit_button( __( 'Importar', 'cead-acad' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( current_user_can( self::CAP_GESTION ) ) : ?>
			<h2><?php esc_html_e( 'Línea base en papel', 'cead-acad' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cead_acad_estudio_linea_base"><?php wp_nonce_field( 'cead_acad_estudio_linea_base' ); ?>
				<input type="date" name="fecha" required>
				<select name="curso_id"><?php foreach ( get_posts( [ 'post_type' => Cead_Acad_Courses_CPT::POST_TYPE, 'posts_per_page' => -1 ] ) as $cu ) : ?><option value="<?php echo (int) $cu->ID; ?>"><?php echo esc_html( $cu->post_title ); ?></option><?php endforeach; ?></select>
				<input type="number" step="0.1" min="0" name="minutos_toma" placeholder="<?php esc_attr_e( 'minutos', 'cead-acad' ); ?>" required>
				<input type="datetime-local" name="consolidado_llego_en" title="<?php esc_attr_e( 'Hora en que el consolidado llegó a dirección', 'cead-acad' ); ?>">
				<input type="number" min="0" name="discrepancias" placeholder="<?php esc_attr_e( 'discrepancias', 'cead-acad' ); ?>">
				<?php submit_button( __( 'Agregar', 'cead-acad' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Respuestas abiertas por revisar', 'cead-acad' ); ?></h2>
			<p><?php esc_html_e( 'Quitá nombres u otros datos que identifiquen a alguien. Solo la versión revisada sale en la exportación.', 'cead-acad' ); ?></p>
			<?php foreach ( $abiertas as $a ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1em">
					<input type="hidden" name="action" value="cead_acad_estudio_revisar"><input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>"><?php wp_nonce_field( 'cead_acad_estudio_revisar' ); ?>
					<?php foreach ( (array) json_decode( $a['abiertas'], true ) as $k => $v ) : ?>
						<p><label><?php echo esc_html( $k ); ?><br><textarea name="abiertas[<?php echo esc_attr( $k ); ?>]" rows="2" cols="80"><?php echo esc_textarea( $v ); ?></textarea></label></p>
					<?php endforeach; ?>
					<?php submit_button( __( 'Guardar revisión', 'cead-acad' ), 'small', 'submit', false ); ?>
				</form>
			<?php endforeach; ?>
			<?php endif; ?>

			<?php if ( current_user_can( self::CAP_GESTION ) ) : ?>
			<h2><?php esc_html_e( 'Encuestas del panel maestro', 'cead-acad' ); ?></h2>
			<p><?php esc_html_e( 'Las encuestas se editan en el panel maestro de la app. Exportá ahí la configuración para WordPress y subila acá: reemplaza a la de la spec (lo ya enviado no cambia).', 'cead-acad' ); ?></p>
			<?php $cerr = get_transient( 'cead_acad_estudio_config_err_' . get_current_user_id() ); if ( 'importar_config_error' === ( $_GET['ok'] ?? '' ) && is_array( $cerr ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( implode( ' · ', $cerr ) ); ?></p></div>
			<?php endif; ?>
			<p><?php echo esc_html( sprintf( __( 'Roles: %1$s · features activas: %2$s', 'cead-acad' ), implode( ', ', Cead_Acad_Estudio_Encuestas::roles() ), implode( ', ', Cead_Acad_Estudio::features_activas() ) ) ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cead_acad_estudio_importar_config"><?php wp_nonce_field( 'cead_acad_estudio_importar_config' ); ?>
				<input type="file" name="config" accept=".json,application/json" required> <?php submit_button( __( 'Importar configuración', 'cead-acad' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Bitácora del estudio', 'cead-acad' ); ?></h2>
			<p><?php esc_html_e( 'Permanente: cambios de encuestas, envíos (con preguntas omitidas por features), operaciones sobre datos y cambios de configuración. Sin datos personales.', 'cead-acad' ); ?></p>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Cuándo (UTC)', 'cead-acad' ); ?></th><th><?php esc_html_e( 'Tipo', 'cead-acad' ); ?></th><th><?php esc_html_e( 'Acción', 'cead-acad' ); ?></th><th><?php esc_html_e( 'Origen', 'cead-acad' ); ?></th><th><?php esc_html_e( 'Detalle', 'cead-acad' ); ?></th></tr></thead><tbody>
			<?php foreach ( $wpdb->get_results( 'SELECT * FROM ' . cead_acad_table( 'estudio_bitacora' ) . ' ORDER BY id DESC LIMIT 100', ARRAY_A ) ?: [] as $f ) : ?>
				<tr><td><?php echo esc_html( $f['ts'] ); ?></td><td><?php echo esc_html( $f['tipo'] ); ?></td><td><?php echo esc_html( $f['accion'] ); ?></td><td><?php echo esc_html( $f['origen'] ); ?></td><td><code style="white-space:pre-wrap"><?php echo esc_html( mb_substr( (string) $f['detalle'], 0, 400 ) ); ?></code></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php endif; ?>

			<?php if ( current_user_can( self::CAP_EXPORT ) ) : ?>
			<h2><?php esc_html_e( 'Exportación de investigación', 'cead-acad' ); ?></h2>
			<p><?php esc_html_e( 'ZIP con eventos, respuestas, asistencias agregadas, línea base y diccionario. Seudonimizado, k ≥ 5, sin datos personales, solo personas con consentimiento vigente.', 'cead-acad' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cead_acad_estudio_exportar"><?php wp_nonce_field( 'cead_acad_estudio_exportar' ); ?>
				<?php submit_button( __( 'Exportar', 'cead-acad' ), 'primary', 'submit', false ); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
