<?php
/**
 * Consentimiento y transparencia (spec 2.7 y 6).
 *
 * - /panel/estudio: qué se recolecta, qué no, por cuánto tiempo, el estado de
 *   la persona y el botón para retirarse.
 * - Retirarse: se dejan de registrar sus eventos y sale de toda exportación
 *   futura. NO libera de la encuesta mensual (es un acuerdo de uso con la
 *   institución): se sigue pidiendo, pero lo que responde no se guarda.
 * - Importador CSV para consentimientos firmados en papel
 *   (columnas: usuario o email, tipo, version_documento, fecha).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Estudio_Consentimientos {

	const TIPOS = [ 'consentimiento_familia', 'asentimiento_alumno', 'consentimiento_propio', 'acuerdo_institucional' ];

	public function boot() {
		add_action( 'admin_post_cead_acad_estudio_retirar', [ $this, 'retirar_web' ] );
		add_action( 'admin_post_cead_acad_estudio_dar', [ $this, 'dar_web' ] );
		add_action( 'rest_api_init', [ $this, 'rutas' ] );
	}

	public function rutas() {
		register_rest_route( Cead_Acad_API::NS, '/estudio', [ 'methods' => 'GET', 'callback' => [ $this, 'api_estado' ], 'permission_callback' => Cead_Acad_API::gate() ] );
		register_rest_route( Cead_Acad_API::NS, '/estudio/retirar', [ 'methods' => 'POST', 'callback' => [ $this, 'api_retirar' ], 'permission_callback' => Cead_Acad_API::gate() ] );
	}

	/** Lo que se le explica a la persona (mismo texto en la web y en la app). */
	public static function transparencia() {
		return [
			'que_se_recolecta' => [
				__( 'Cuándo abrís un comunicado (no lo que pensás de él).', 'cead-acad' ),
				__( 'Cuánto tarda la toma de asistencia (docentes).', 'cead-acad' ),
				__( 'Tus respuestas a la encuesta mensual de 3 minutos.', 'cead-acad' ),
				__( 'Cuándo iniciás sesión, si un aviso llegó y si la app tuvo errores.', 'cead-acad' ),
			],
			'como'          => __( 'Con un seudónimo: un código que no permite saber quién sos sin una clave que solo tiene el equipo del estudio y que se destruye al terminar.', 'cead-acad' ),
			'que_no'        => [
				__( 'Tu nombre, usuario, correo, teléfono, CI o fecha de nacimiento.', 'cead-acad' ),
				__( 'Tu IP o tu navegador.', 'cead-acad' ),
				__( 'Tus notas.', 'cead-acad' ),
				__( 'El texto de tus mensajes o reportes.', 'cead-acad' ),
			],
			'cuanto_tiempo' => __( '12 meses desde el inicio del estudio. Después se dejan de juntar datos y lo exportado queda anónimo para siempre.', 'cead-acad' ),
		];
	}

	public static function retirar( $user_id ) {
		global $wpdb;
		if ( ! Cead_Acad_Estudio::config()['permitir_retiro'] ) {
			return new WP_Error( 'cead_estudio_retiro', __( 'El retiro no está habilitado.', 'cead-acad' ) );
		}
		$t = cead_acad_table( 'estudio_consentimientos' );
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET estado = 'retirado', retirado_en = %s WHERE user_id = %d AND estado = 'activo'", current_time( 'mysql', 1 ), (int) $user_id ) );
		if ( ! $n ) {
			// Sin filas previas (modo acuerdo): igual queda constancia del retiro.
			$wpdb->insert( $t, [ 'user_id' => (int) $user_id, 'tipo' => 'consentimiento_propio', 'version_documento' => 'retiro',
				'fecha' => current_time( 'Y-m-d' ), 'estado' => 'retirado', 'retirado_en' => current_time( 'mysql', 1 ), 'creado_en' => current_time( 'mysql', 1 ) ] );
		}
		Cead_Acad_Audit::log( 'estudio_consentimiento_retirado', [ 'user_id' => (int) $user_id ] );
		return true;
	}

	public static function dar( $user_id, $tipo, $version, $cargado_por = null, $fecha = null ) {
		global $wpdb;
		if ( ! in_array( $tipo, self::TIPOS, true ) ) {
			return new WP_Error( 'cead_estudio_tipo', __( 'Tipo de consentimiento inválido.', 'cead-acad' ) );
		}
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . cead_acad_table( 'estudio_consentimientos' ) . ' (user_id, tipo, version_documento, fecha, cargado_por, estado, creado_en) VALUES (%d, %s, %s, %s, %d, %s, %s)',
			(int) $user_id, $tipo, substr( (string) $version, 0, 40 ), $fecha ?: current_time( 'Y-m-d' ), (int) $cargado_por, 'activo', current_time( 'mysql', 1 )
		) );
		return true;
	}

	/**
	 * Importa un CSV de consentimientos en papel. Pura sobre el texto + un
	 * buscador de usuarios inyectable (para probarla sin WordPress).
	 *
	 * @param callable $buscar fn(string $usuario_o_email): int user_id|0
	 * @param callable $guardar fn(int $uid, string $tipo, string $version, ?string $fecha): void
	 * @return array{ok:int, errores: array<int,array{fila:int,error:string}>}
	 */
	public static function importar_csv( $texto, callable $buscar, callable $guardar ) {
		$texto = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $texto );
		$lineas = array_values( array_filter( preg_split( '/\r\n|\r|\n/', $texto ), 'strlen' ) );
		if ( ! $lineas ) {
			return [ 'ok' => 0, 'errores' => [ [ 'fila' => 1, 'error' => 'vacio' ] ] ];
		}
		$sep = substr_count( $lineas[0], ';' ) > substr_count( $lineas[0], ',' ) ? ';' : ',';
		$enc = array_map( static function ( $c ) { return strtolower( trim( $c ) ); }, str_getcsv( $lineas[0], $sep, '"', '\\' ) );
		$col = static function ( $n ) use ( $enc ) {
			$i = array_search( $n, $enc, true );
			return false === $i ? -1 : $i;
		};
		$iu = $col( 'usuario' ) >= 0 ? $col( 'usuario' ) : $col( 'email' );
		[ $it, $iv, $if ] = [ $col( 'tipo' ), $col( 'version_documento' ), $col( 'fecha' ) ];
		if ( $iu < 0 || $it < 0 || $iv < 0 ) {
			return [ 'ok' => 0, 'errores' => [ [ 'fila' => 1, 'error' => 'encabezados' ] ] ];
		}
		$ok = 0;
		$errores = [];
		foreach ( array_slice( $lineas, 1 ) as $n => $linea ) {
			$f    = str_getcsv( $linea, $sep, '"', '\\' );
			$tipo = trim( $f[ $it ] ?? '' );
			if ( ! in_array( $tipo, self::TIPOS, true ) ) {
				$errores[] = [ 'fila' => $n + 2, 'error' => 'tipo' ];
				continue;
			}
			$uid = (int) $buscar( trim( $f[ $iu ] ?? '' ) );
			if ( ! $uid ) {
				// La fila, nunca el correo: el informe no repite datos personales.
				$errores[] = [ 'fila' => $n + 2, 'error' => 'usuario_no_encontrado' ];
				continue;
			}
			$fecha = ( $if >= 0 && preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $f[ $if ] ?? '' ) ) ) ? trim( $f[ $if ] ) : null;
			$guardar( $uid, $tipo, trim( $f[ $iv ] ?? '' ), $fecha );
			$ok++;
		}
		return [ 'ok' => $ok, 'errores' => $errores ];
	}

	/* ---------------------------------------------------------------- web */

	public function retirar_web() {
		check_admin_referer( 'cead_acad_estudio_retirar' );
		$r = self::retirar( get_current_user_id() );
		wp_safe_redirect( add_query_arg( is_wp_error( $r ) ? [ 'err' => 1 ] : [ 'retirado' => 1 ], cead_acad_url( 'panel/estudio' ) ) );
		exit;
	}

	/** Modo individual: el alumno da su asentimiento, o el docente / mayor de 18 su consentimiento. */
	public function dar_web() {
		check_admin_referer( 'cead_acad_estudio_dar' );
		$tipo = 'alumno' === Cead_Acad_Estudio::rol_de( get_current_user_id() ) && empty( $_POST['mayor'] ) ? 'asentimiento_alumno' : 'consentimiento_propio';
		self::dar( get_current_user_id(), $tipo, 'v1' );
		wp_safe_redirect( add_query_arg( 'dado', 1, cead_acad_url( 'panel/estudio' ) ) );
		exit;
	}

	public function api_estado() {
		$c = Cead_Acad_Estudio::config();
		return [
			'participa'           => (bool) $c['activo'],
			'consentimiento_modo' => $c['consentimiento_modo'],
			'permitir_retiro'     => (bool) $c['permitir_retiro'],
			'estado'              => Cead_Acad_Estudio::estado_consentimiento( get_current_user_id() ),
			'pendientes'          => Cead_Acad_Estudio_Encuestas::pendientes( get_current_user_id() ),
			'bloqueo'             => Cead_Acad_Estudio_Encuestas::bloqueante( get_current_user_id() ),
		] + self::transparencia();
	}

	public function api_retirar() {
		$r = self::retirar( get_current_user_id() );
		return is_wp_error( $r ) ? new WP_Error( $r->get_error_code(), $r->get_error_message(), [ 'status' => 409 ] ) : [ 'ok' => true ];
	}
}
