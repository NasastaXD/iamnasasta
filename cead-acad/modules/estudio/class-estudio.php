<?php
/**
 * Estudio piloto (Premio Nacional Juvenil de Ciencias): registro de eventos
 * de investigación.
 *
 * EL único punto de escritura es `registrar( $evento, $datos )` (spec 3.1).
 * Ahí se decide, para cada evento:
 *   - si el sitio está en el estudio y la recolección sigue abierta,
 *   - el consentimiento (retirado → no se registra; sin consentimiento → se
 *     guarda marcado y queda fuera de la exportación),
 *   - el seudónimo (HMAC con CEAD_ESTUDIO_SALT, que vive en wp-config.php),
 *   - el rol y el curso generalizado al momento del evento,
 *   - que `meta` no lleve datos personales.
 *
 * Configuración (opción `cead_acad_estudio`, la edita el equipo del estudio en
 * wp-admin → CEAD Académico → Estudio piloto):
 *   activo, codigo (I1…), inicio (Y-m-d), consentimiento_modo
 *   ('acuerdo_institucional' | 'individual'), permitir_retiro, cerrado.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Estudio {

	const OPCION = 'cead_acad_estudio';

	/** Rol del plugin → rol del estudio. */
	const ROLES = [
		'cead_acad_student'         => 'alumno',
		'cead_acad_delegate'        => 'alumno',
		'cead_acad_student_council' => 'alumno',
		'cead_acad_teacher'         => 'docente',
		'cead_acad_secretary'       => 'secretaria',
		'cead_acad_direction'       => 'director',
		'cead_acad_guardian'        => 'familia',
	];

	public function boot() {
		// sesion_inicio por la web (la app lo manda en su cola).
		add_action( 'wp_login', [ __CLASS__, 'al_iniciar_sesion' ], 20, 2 );
		// comunicado_publicado, con la cantidad de destinatarios esperados.
		add_action( 'transition_post_status', [ __CLASS__, 'al_cambiar_estado' ], 20, 3 );
	}

	/* ------------------------------------------------------------- config */

	public static function config() {
		$c = get_option( self::OPCION, [] );
		return wp_parse_args( is_array( $c ) ? $c : [], [
			'activo'              => false,
			'codigo'              => 'I1',
			'inicio'              => '',
			'consentimiento_modo' => 'acuerdo_institucional',
			'permitir_retiro'     => true,
			'cerrado'             => false,
		] );
	}

	/** La sal: SOLO de wp-config.php. Si no está, el estudio no registra nada. */
	public static function sal() {
		return defined( 'CEAD_ESTUDIO_SALT' ) ? (string) CEAD_ESTUDIO_SALT : '';
	}

	/**
	 * Instrumentos y calendario. Lo que se importó desde el panel maestro
	 * (opción) manda sobre el archivo del plugin, que es la siembra de la spec:
	 * una actualización del plugin no pisa lo que se editó en el panel.
	 */
	public static function instrumentos() {
		static $cfg = null;
		if ( null === $cfg ) {
			$importada = get_option( 'cead_acad_estudio_config', null );
			$cfg = is_array( $importada ) && $importada
				? $importada
				: ( json_decode( (string) file_get_contents( CEAD_ACAD_DIR . 'config/estudio-encuestas.json' ), true ) ?: [] );
		}
		return $cfg;
	}

	/**
	 * Features del catálogo que este sitio tiene activas, para filtrar las
	 * preguntas tagueadas. En WordPress no hay catálogo por institución: se
	 * deduce de lo que está encendido (y se puede ajustar por opción).
	 */
	public static function features_activas() {
		$base = [ 'comunicados', 'encuestas', 'encuestas_estudio', 'asistencias', 'agenda', 'horario_clases', 'recursos',
			'calificaciones', 'tareas_delegado', 'buzon', 'faq', 'carne', 'novedades', 'metricas_direccion' ];
		if ( ! get_option( 'cead_acad_modo_piloto', false ) ) {
			array_push( $base, 'whatsapp_bot', 'instagram', 'turismo' );
		}
		$ajuste = get_option( 'cead_acad_features_apagadas', [] );
		return array_values( array_diff( $base, is_array( $ajuste ) ? $ajuste : [] ) );
	}

	/**
	 * Bitácora del estudio: cambios de encuestas y operaciones sobre datos.
	 * Solo se agrega (nunca se edita ni se borra desde el plugin) y no lleva
	 * datos personales: acciones, conteos, versiones.
	 */
	public static function bitacora( $tipo, $accion, array $detalle = [] ) {
		global $wpdb;
		$wpdb->insert( cead_acad_table( 'estudio_bitacora' ), [
			'ts'      => current_time( 'mysql', 1 ),
			'tipo'    => in_array( $tipo, [ 'encuestas', 'operacion', 'configuracion', 'recoleccion' ], true ) ? $tipo : 'operacion',
			'accion'  => substr( sanitize_key( $accion ), 0, 60 ),
			'origen'  => user_can( get_current_user_id(), 'cead_acad_manage_study' ) ? 'equipo_estudio' : ( get_current_user_id() ? 'gestion' : 'sistema' ),
			'detalle' => wp_json_encode( Cead_Acad_Estudio_Logica::meta_segura_profunda( $detalle ) ),
		] );
	}

	/** ¿Se está recolectando ahora? */
	public static function recolectando() {
		$c = self::config();
		if ( ! $c['activo'] || $c['cerrado'] || ! $c['inicio'] || strlen( self::sal() ) < 32 ) {
			return false;
		}
		return time() < Cead_Acad_Estudio_Logica::fin_recoleccion( $c['inicio'], self::instrumentos()['calendario'] ?? [] );
	}

	/* ------------------------------------------------------- personas */

	public static function rol_de( $user_id ) {
		$u = get_userdata( (int) $user_id );
		if ( ! $u ) {
			return null;
		}
		foreach ( self::ROLES as $slug => $rol ) {
			if ( in_array( $slug, (array) $u->roles, true ) ) {
				return $rol;
			}
		}
		return null;
	}

	/** Nivel/grado del curso del alumno (nunca la división: identifica grupos chicos). */
	public static function curso_grupo( $user_id ) {
		foreach ( Cead_Acad_Courses_Roster::courses_for_user( (int) $user_id ) as $cid ) {
			$t = wp_get_object_terms( (int) $cid, Cead_Acad_Courses_CPT::TAX_GRADE, [ 'fields' => 'names' ] );
			if ( ! is_wp_error( $t ) && $t ) {
				return mb_substr( (string) $t[0], 0, 30 );
			}
		}
		return null;
	}

	/**
	 * 'retirado' manda (es definitivo); 'activo' = acuerdo institucional, o
	 * consentimiento propio, o familia + asentimiento; si no, 'ninguno'.
	 */
	public static function estado_consentimiento( $user_id ) {
		global $wpdb;
		$t     = cead_acad_table( 'estudio_consentimientos' );
		$filas = $wpdb->get_results( $wpdb->prepare( "SELECT tipo, estado FROM {$t} WHERE user_id = %d", (int) $user_id ), ARRAY_A ) ?: [];
		$activos = [];
		foreach ( $filas as $f ) {
			if ( 'retirado' === $f['estado'] ) {
				return 'retirado';
			}
			$activos[ $f['tipo'] ] = true;
		}
		if ( 'acuerdo_institucional' === self::config()['consentimiento_modo'] || isset( $activos['consentimiento_propio'] ) || isset( $activos['acuerdo_institucional'] ) ) {
			return 'activo';
		}
		return isset( $activos['consentimiento_familia'], $activos['asentimiento_alumno'] ) ? 'activo' : 'ninguno';
	}

	/* ----------------------------------------------------------- registrar */

	/**
	 * @param string $evento Uno de Cead_Acad_Estudio_Logica::EVENTOS.
	 * @param array  $datos  user_id, canal (web|app|bot|sistema), entidad_tipo?, entidad_id?,
	 *                       ts_cliente? (timestamp), meta? (array), rol?
	 * @return string registrado | fuera_del_estudio | retirado | sin_rol | evento_desconocido
	 */
	public static function registrar( $evento, array $datos ) {
		if ( ! in_array( $evento, Cead_Acad_Estudio_Logica::EVENTOS, true ) ) {
			return 'evento_desconocido';
		}
		if ( ! self::recolectando() ) {
			return 'fuera_del_estudio';
		}
		$uid = (int) ( $datos['user_id'] ?? 0 );
		$rol = $datos['rol'] ?? self::rol_de( $uid );
		if ( ! $uid || ! $rol ) {
			return 'sin_rol';
		}
		$consentimiento = self::estado_consentimiento( $uid );
		if ( 'retirado' === $consentimiento ) {
			return 'retirado';
		}

		global $wpdb;
		$meta = Cead_Acad_Estudio_Logica::meta_segura( $datos['meta'] ?? null );
		$wpdb->insert( cead_acad_table( 'estudio_eventos' ), [
			'seudonimo'          => Cead_Acad_Estudio_Logica::seudonimo( self::sal(), $uid ),
			'institucion'        => substr( (string) self::config()['codigo'], 0, 8 ),
			'rol'                => $rol,
			'curso_grupo'        => 'alumno' === $rol ? self::curso_grupo( $uid ) : null,
			'evento'             => $evento,
			'entidad_tipo'       => isset( $datos['entidad_tipo'] ) ? substr( (string) $datos['entidad_tipo'], 0, 20 ) : null,
			'entidad_id'         => isset( $datos['entidad_id'] ) ? (int) $datos['entidad_id'] : null,
			'canal'              => in_array( $datos['canal'] ?? '', [ 'web', 'app', 'bot', 'sistema' ], true ) ? $datos['canal'] : 'web',
			'ts_cliente'         => isset( $datos['ts_cliente'] ) ? gmdate( 'Y-m-d H:i:s', (int) $datos['ts_cliente'] ) : null,
			'ts_servidor'        => current_time( 'mysql', 1 ),
			'meta'               => $meta ? wp_json_encode( $meta ) : null,
			'con_consentimiento' => 'activo' === $consentimiento ? 1 : 0,
		] );
		return 'registrado';
	}

	/* ------------------------------------------------------------ listeners */

	public static function al_iniciar_sesion( $login, $user ) {
		self::registrar( 'sesion_inicio', [ 'user_id' => $user->ID, 'canal' => 'web' ] );
	}

	public static function al_cambiar_estado( $nuevo, $viejo, $post ) {
		if ( 'publish' !== $nuevo || 'publish' === $viejo || Cead_Acad_Broadcasts_CPT::POST_TYPE !== $post->post_type ) {
			return;
		}
		// Las audiencias se guardan en el mismo request que la publicación,
		// después de este hook: se cuentan al final del request.
		$pid = (int) $post->ID;
		add_action( 'shutdown', static function () use ( $pid ) {
			$p = get_post( $pid );
			if ( ! $p ) {
				return;
			}
			$dest = array_diff( Cead_Acad_Broadcasts_Feed::resolve_recipient_user_ids( $pid ), [ (int) $p->post_author ] );
			self::registrar( 'comunicado_publicado', [
				'user_id'      => (int) $p->post_author,
				'canal'        => defined( 'REST_REQUEST' ) && REST_REQUEST && class_exists( 'Cead_Acad_API' ) && Cead_Acad_API::por_app() ? 'app' : 'web',
				'entidad_tipo' => 'comunicado',
				'entidad_id'   => $pid,
				'ts_cliente'   => strtotime( $p->post_date_gmt . ' UTC' ),
				'meta'         => [ 'destinatarios' => count( $dest ) ],
			] );
		} );
	}
}
