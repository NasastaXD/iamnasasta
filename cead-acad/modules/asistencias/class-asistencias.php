<?php
/**
 * Asistencia (spec sección 4).
 *
 * Toma por curso y día (y bloque, si se usa), en la web y en la app, con uso
 * sin conexión: la app precarga la lista (GET /asistencia/cursos), toma lista
 * sin señal y la manda cuando vuelve (POST /asistencia con Idempotency-Key).
 *
 * Cada fila tiene `clave_idempotencia` = clave del envío + alumno: el mismo
 * envío reintentado no duplica ni pisa; un envío nuevo con otro estado es una
 * corrección y queda en el historial.
 *
 * P2 del estudio: la app manda la hora real de inicio (abrió la lista) y de
 * fin (guardó), y se registran `asistencia_inicio` / `asistencia_fin`.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Asistencias {

	const CAP     = 'cead_acad_take_attendance';
	const ESTADOS = [ 'presente', 'ausente', 'tarde', 'justificado' ];

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'rutas' ] );
		add_action( 'admin_post_cead_acad_asistencia', [ $this, 'guardar_web' ] );
	}

	public function rutas() {
		$ns = Cead_Acad_API::NS;
		register_rest_route( $ns, '/asistencia/cursos', [ 'methods' => 'GET', 'callback' => [ $this, 'api_cursos' ], 'permission_callback' => Cead_Acad_API::gate() ] );
		register_rest_route( $ns, '/asistencia', [ 'methods' => 'POST', 'callback' => [ $this, 'api_guardar' ], 'permission_callback' => Cead_Acad_API::gate() ] );
		register_rest_route( $ns, '/asistencia/consolidado', [ 'methods' => 'GET', 'callback' => [ $this, 'api_consolidado' ], 'permission_callback' => Cead_Acad_API::gate() ] );
		register_rest_route( $ns, '/asistencia/mia', [ 'methods' => 'GET', 'callback' => [ $this, 'api_mia' ], 'permission_callback' => Cead_Acad_API::gate() ] );
	}

	/* ------------------------------------------------------------ permisos */

	public static function puede_gestionar( $user_id ) {
		return user_can( (int) $user_id, self::CAP ) && user_can( (int) $user_id, 'cead_acad_manage_courses' );
	}

	/** Docente del curso (en el roster) o dirección/secretaría. Verificado en el servidor, siempre. */
	public static function puede_tomar( $user_id, $curso_id ) {
		if ( ! user_can( (int) $user_id, self::CAP ) ) {
			return false;
		}
		if ( self::puede_gestionar( $user_id ) ) {
			return true;
		}
		return in_array( (int) $user_id, array_map( 'intval', Cead_Acad_Courses_Roster::users_in_course( (int) $curso_id, 'teacher' ) ), true );
	}

	/** Alumnos de la lista: alumnado y delegados del curso. */
	public static function alumnos( $curso_id ) {
		return array_values( array_unique( array_map( 'intval', array_merge(
			Cead_Acad_Courses_Roster::users_in_course( (int) $curso_id, 'student' ),
			Cead_Acad_Courses_Roster::users_in_course( (int) $curso_id, 'delegate' )
		) ) ) );
	}

	public static function cursos_de( $user_id ) {
		if ( self::puede_gestionar( $user_id ) ) {
			return array_map( 'intval', get_posts( [ 'post_type' => Cead_Acad_Courses_CPT::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ] ) );
		}
		return array_values( array_filter( Cead_Acad_Courses_Roster::courses_for_user( (int) $user_id ), static function ( $cid ) use ( $user_id ) {
			return self::puede_tomar( $user_id, $cid );
		} ) );
	}

	/* --------------------------------------------------------------- lógica */

	/** Clave de cada fila: la del envío + el alumno. Pura (probada). */
	public static function clave_fila( $clave_envio, $alumno_id ) {
		return substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $clave_envio ), 0, 100 ) . ':' . (int) $alumno_id;
	}

	/**
	 * Guarda una toma completa.
	 *
	 * @param array $lista [ [ 'alumno' => int, 'estado' => string ], … ]
	 * @return array|WP_Error guardados, correcciones, rechazados
	 */
	public static function guardar( $user_id, $curso_id, $fecha, $bloque, array $lista, $clave, $ts_inicio = null, $ts_fin = null, $canal = 'web' ) {
		global $wpdb;
		if ( ! self::puede_tomar( $user_id, $curso_id ) ) {
			return new WP_Error( 'cead_asistencia_permiso', __( 'No das clase en ese curso.', 'cead-acad' ), [ 'status' => 403 ] );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $fecha ) || strtotime( $fecha ) > time() + DAY_IN_SECONDS ) {
			return new WP_Error( 'cead_asistencia_fecha', __( 'Fecha inválida.', 'cead-acad' ), [ 'status' => 400 ] );
		}
		$bloque  = substr( sanitize_text_field( (string) $bloque ), 0, 20 );
		$validos = self::alumnos( $curso_id );
		$t       = cead_acad_table( 'asistencias' );
		$th      = cead_acad_table( 'asistencias_historial' );
		$r       = [ 'guardados' => 0, 'correcciones' => 0, 'rechazados' => [] ];
		$ts_sql  = $ts_fin ? gmdate( 'Y-m-d H:i:s', (int) $ts_fin ) : null;

		foreach ( $lista as $item ) {
			$alumno = (int) ( $item['alumno'] ?? 0 );
			$estado = (string) ( $item['estado'] ?? '' );
			if ( ! in_array( $alumno, $validos, true ) || ! in_array( $estado, self::ESTADOS, true ) ) {
				$r['rechazados'][] = $alumno;
				continue;
			}
			$cf = self::clave_fila( $clave, $alumno );
			$ex = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, estado, clave_idempotencia FROM {$t} WHERE curso_id = %d AND fecha = %s AND bloque = %s AND alumno_id = %d",
				(int) $curso_id, $fecha, $bloque, $alumno
			), ARRAY_A );
			if ( $ex && $ex['clave_idempotencia'] === $cf ) {
				continue; // reintento del mismo envío: nada que hacer
			}
			$fila = [ 'estado' => $estado, 'cargado_por' => (int) $user_id, 'ts_cliente' => $ts_sql, 'ts_servidor' => current_time( 'mysql', 1 ), 'clave_idempotencia' => $cf ];
			if ( $ex ) {
				$wpdb->update( $t, $fila, [ 'id' => (int) $ex['id'] ] );
				if ( $ex['estado'] !== $estado ) {
					$wpdb->insert( $th, [ 'asistencia_id' => (int) $ex['id'], 'estado_anterior' => $ex['estado'], 'estado_nuevo' => $estado, 'cambiado_por' => (int) $user_id, 'cambiado_en' => current_time( 'mysql', 1 ) ] );
					$r['correcciones']++;
				}
			} else {
				$wpdb->insert( $t, $fila + [ 'curso_id' => (int) $curso_id, 'fecha' => $fecha, 'bloque' => $bloque, 'alumno_id' => $alumno ] );
				$wpdb->insert( $th, [ 'asistencia_id' => (int) $wpdb->insert_id, 'estado_anterior' => null, 'estado_nuevo' => $estado, 'cambiado_por' => (int) $user_id, 'cambiado_en' => current_time( 'mysql', 1 ) ] );
			}
			$r['guardados']++;
		}

		$duracion = ( $ts_inicio && $ts_fin && $ts_fin >= $ts_inicio && $ts_fin - $ts_inicio <= 7200 ) ? (int) ( $ts_fin - $ts_inicio ) : null;
		Cead_Acad_Estudio::registrar( 'asistencia_inicio', [ 'user_id' => $user_id, 'canal' => $canal, 'rol' => 'docente', 'entidad_tipo' => 'asistencia', 'ts_cliente' => $ts_inicio, 'meta' => [ 'fecha' => $fecha ] ] );
		Cead_Acad_Estudio::registrar( 'asistencia_fin', [ 'user_id' => $user_id, 'canal' => $canal, 'rol' => 'docente', 'entidad_tipo' => 'asistencia', 'ts_cliente' => $ts_fin,
			'meta' => array_filter( [ 'fecha' => $fecha, 'alumnos' => count( $lista ), 'duracion_s' => $duracion, 'correcciones' => $r['correcciones'] ], static function ( $v ) { return null !== $v; } ) ] );
		return $r;
	}

	/** Consolidado del día por curso (dirección y secretaría). */
	public static function consolidado( $fecha ) {
		global $wpdb;
		$t = cead_acad_table( 'asistencias' );
		$filas = $wpdb->get_results( $wpdb->prepare(
			"SELECT curso_id, bloque, estado, COUNT(*) AS n, MAX(ts_servidor) AS ultima FROM {$t} WHERE fecha = %s GROUP BY curso_id, bloque, estado",
			$fecha
		), ARRAY_A ) ?: [];
		$out = [];
		foreach ( $filas as $f ) {
			$k = $f['curso_id'] . '|' . $f['bloque'];
			$out[ $k ] = ( $out[ $k ] ?? [ 'curso_id' => (int) $f['curso_id'], 'curso' => get_the_title( (int) $f['curso_id'] ), 'bloque' => $f['bloque'], 'presente' => 0, 'ausente' => 0, 'tarde' => 0, 'justificado' => 0, 'ultima_carga' => null ] );
			$out[ $k ][ $f['estado'] ] = (int) $f['n'];
			$out[ $k ]['ultima_carga'] = max( (string) $out[ $k ]['ultima_carga'], (string) $f['ultima'] );
		}
		return array_values( $out );
	}

	/* ----------------------------------------------------------------- REST */

	public function api_cursos() {
		$uid = get_current_user_id();
		if ( ! user_can( $uid, self::CAP ) ) {
			return new WP_Error( 'cead_asistencia_permiso', __( 'Sin permiso.', 'cead-acad' ), [ 'status' => 403 ] );
		}
		$def = (string) ( get_option( 'cead_acad_asistencia_estado_defecto', 'presente' ) );
		return [ 'estado_por_defecto' => in_array( $def, self::ESTADOS, true ) ? $def : 'presente', 'cursos' => array_map( static function ( $cid ) {
			return [ 'id' => $cid, 'nombre' => get_the_title( $cid ), 'alumnos' => array_map( static function ( $a ) {
				$u = get_userdata( $a );
				return [ 'id' => $a, 'nombre' => $u ? $u->display_name : '' ];
			}, self::alumnos( $cid ) ) ];
		}, self::cursos_de( $uid ) ) ];
	}

	public function api_guardar( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			$clave = (string) ( $req->get_header( 'idempotency_key' ) ?: wp_generate_uuid4() );
			return self::guardar( get_current_user_id(), (int) $req->get_param( 'curso_id' ), (string) $req->get_param( 'fecha' ),
				(string) $req->get_param( 'bloque' ), (array) $req->get_param( 'lista' ), $clave,
				Cead_Acad_Estudio_Logica::parsear_ts( $req->get_param( 'inicio' ) ), Cead_Acad_Estudio_Logica::parsear_ts( $req->get_param( 'fin' ) ), 'app' );
		} );
	}

	public function api_consolidado( $req ) {
		$uid = get_current_user_id();
		if ( ! self::puede_gestionar( $uid ) ) {
			return new WP_Error( 'cead_asistencia_permiso', __( 'Solo dirección y secretaría.', 'cead-acad' ), [ 'status' => 403 ] );
		}
		$fecha = (string) ( $req->get_param( 'fecha' ) ?: current_time( 'Y-m-d' ) );
		Cead_Acad_Estudio::registrar( 'asistencia_consolidada', [ 'user_id' => $uid, 'canal' => 'app', 'entidad_tipo' => 'asistencia', 'meta' => [ 'fecha' => $fecha ] ] );
		return [ 'fecha' => $fecha, 'cursos' => self::consolidado( $fecha ) ];
	}

	/** El alumno ve su propio registro (la familia no: el vínculo está fuera de esta etapa). */
	public function api_mia() {
		global $wpdb;
		$t = cead_acad_table( 'asistencias' );
		return [ 'registros' => $wpdb->get_results( $wpdb->prepare(
			"SELECT fecha, bloque, estado FROM {$t} WHERE alumno_id = %d ORDER BY fecha DESC LIMIT 120", get_current_user_id()
		), ARRAY_A ) ?: [] ];
	}

	/* ------------------------------------------------------------------ web */

	public function guardar_web() {
		check_admin_referer( 'cead_acad_asistencia' );
		$uid   = get_current_user_id();
		$curso = (int) ( $_POST['curso_id'] ?? 0 );
		$fecha = sanitize_text_field( wp_unslash( $_POST['fecha'] ?? '' ) );
		$lista = [];
		foreach ( (array) ( $_POST['estado'] ?? [] ) as $alumno => $estado ) {
			$lista[] = [ 'alumno' => (int) $alumno, 'estado' => sanitize_key( (string) $estado ) ];
		}
		// La web no anda sin conexión: inicio = cuando se abrió la lista (firmado
		// en el formulario), fin = ahora.
		$inicio = (int) ( $_POST['inicio'] ?? 0 );
		$firma  = (string) ( $_POST['inicio_firma'] ?? '' );
		$inicio = hash_equals( wp_hash( 'asistencia|' . $inicio . '|' . $uid ), $firma ) ? $inicio : null;
		$r = self::guardar( $uid, $curso, $fecha, sanitize_text_field( wp_unslash( $_POST['bloque'] ?? '' ) ), $lista,
			sanitize_key( (string) ( $_POST['clave'] ?? wp_generate_uuid4() ) ), $inicio, time(), 'web' );
		$q = is_wp_error( $r ) ? [ 'err' => $r->get_error_code() ] : [ 'ok' => 1 ];
		wp_safe_redirect( add_query_arg( $q + [ 'curso' => $curso, 'fecha' => $fecha ], cead_acad_url( 'panel/asistencia' ) ) );
		exit;
	}
}
