<?php
/**
 * Encuestas del estudio (spec sección 5), sobre el módulo de encuestas que ya
 * existe: cada encuesta mensual es un `cead_acad_survey` con
 * `_cead_acad_survey_tipo = estudio`, así se muestra con el mismo formulario.
 *
 * Lo que cambia respecto de una encuesta común:
 *   - la crea un programador (WP-Cron cada hora), no una persona, a partir de
 *     config/estudio-encuestas.json; una sola vez por mes y rol (el candado
 *     es `add_option`, que es atómico: si dos corridas se pisan, una pierde);
 *   - las respuestas van a `estudio_respuestas` con seudónimo, SIN hash de IP
 *     ni navegador (spec 5.1);
 *   - es OBLIGATORIA para seguir usando el panel: pasados los días de gracia
 *     desde que la persona la recibió, el panel la manda a la encuesta (salvo
 *     la propia encuesta, el perfil, la asistencia y la pantalla del estudio).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Estudio_Encuestas {

	const CRON     = 'cead_acad_estudio_programador';
	const META_TIPO = '_cead_acad_survey_tipo';
	const META_RESPONDIDA = '_cead_acad_estudio_respondida_'; // + survey_id (operativo: no es dato del estudio)

	/** Rol del estudio → roles del plugin que lo reciben. */
	const DESTINOS = [
		'alumno'  => [ 'cead_acad_student', 'cead_acad_delegate', 'cead_acad_student_council' ],
		'docente'  => [ 'cead_acad_teacher' ],
		'director' => [ 'cead_acad_direction' ],
	];

	/** Roles a los que va la encuesta (los fija la campaña del panel maestro). */
	public static function roles() {
		$r = (array) ( Cead_Acad_Estudio::instrumentos()['roles'] ?? [ 'alumno', 'docente' ] );
		return array_values( array_intersect( $r, array_keys( self::DESTINOS ) ) );
	}

	/** Pantallas del panel que nunca se bloquean. */
	const EXCEPCIONES = [ 'panel/encuestas', 'panel/perfil', 'panel/asistencia', 'panel/estudio' ];

	public function boot() {
		add_action( self::CRON, [ __CLASS__, 'programar' ] );
		add_action( 'init', static function () {
			if ( ! wp_next_scheduled( self::CRON ) ) {
				wp_schedule_event( time() + 300, 'hourly', self::CRON );
			}
		} );
		add_action( 'template_redirect', [ __CLASS__, 'bloquear_panel' ], 5 );
		add_filter( 'rest_pre_dispatch', [ __CLASS__, 'bloquear_api' ], 10, 3 );
	}

	/* -------------------------------------------------------- consultas */

	public static function es_estudio( $survey_id ) {
		return 'estudio' === get_post_meta( (int) $survey_id, self::META_TIPO, true );
	}

	/**
	 * Encuestas del estudio abiertas para esta persona que todavía no respondió.
	 *
	 * @return array<int,array{id:int, cierra:int, bloquea_desde:int}>
	 */
	public static function pendientes( $user_id ) {
		$rol = Cead_Acad_Estudio::rol_de( $user_id );
		if ( ! isset( self::DESTINOS[ $rol ] ) ) {
			return [];
		}
		$ids = get_posts( [
			'post_type'      => Cead_Acad_Surveys_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => self::META_TIPO, 'value' => 'estudio' ],
				[ 'key' => '_cead_acad_estudio_rol', 'value' => $rol ],
			],
		] );
		$u       = get_userdata( (int) $user_id );
		$alta    = $u ? strtotime( $u->user_registered . ' UTC' ) : 0;
		$gracia  = (int) ( Cead_Acad_Estudio::instrumentos()['bloqueo']['gracia_dias'] ?? 3 ) * DAY_IN_SECONDS;
		$ahora   = time();
		$out     = [];
		foreach ( $ids as $id ) {
			$envio  = (int) get_post_meta( $id, '_cead_acad_estudio_envio', true );
			$cierre = (int) get_post_meta( $id, '_cead_acad_estudio_cierre', true );
			if ( $ahora < $envio || $ahora >= $cierre || get_user_meta( (int) $user_id, self::META_RESPONDIDA . $id, true ) ) {
				continue;
			}
			// La gracia cuenta desde que la persona la recibió (quien entra a mitad de la ventana también tiene sus días).
			$out[] = [ 'id' => (int) $id, 'cierra' => $cierre, 'bloquea_desde' => max( $envio, $alta ) + $gracia ];
		}
		return $out;
	}

	/** @return int|null El id de la encuesta que bloquea, o null. */
	public static function bloqueante( $user_id ) {
		if ( empty( Cead_Acad_Estudio::instrumentos()['bloqueo']['obligatoria'] ) ) {
			return null;
		}
		foreach ( self::pendientes( $user_id ) as $p ) {
			if ( time() >= $p['bloquea_desde'] ) {
				return $p['id'];
			}
		}
		return null;
	}

	/* ------------------------------------------------------------ bloqueo */

	public static function bloquear_panel() {
		$ruta = (string) get_query_var( Cead_Acad_Rewrites::QUERY_VAR );
		if ( ( 'panel' !== $ruta && ! str_starts_with( $ruta, 'panel/' ) ) || ! is_user_logged_in() ) {
			return;
		}
		foreach ( self::EXCEPCIONES as $e ) {
			if ( str_starts_with( $ruta, $e ) ) {
				return;
			}
		}
		$id = self::bloqueante( get_current_user_id() );
		if ( $id ) {
			wp_safe_redirect( add_query_arg( 'obligatoria', 1, cead_acad_url( 'panel/encuestas/' . $id ) ) );
			exit;
		}
	}

	/**
	 * Lo mismo para la API de la app: el contenido no sale mientras haya una
	 * encuesta vencida la gracia. La sincronización, la encuesta, la
	 * asistencia, la sesión y los avisos siguen andando (la app necesita
	 * bajar la encuesta y subir lo que hizo sin conexión).
	 */
	public static function bloquear_api( $res, $server, $req ) {
		$ruta = (string) $req->get_route();
		if ( null !== $res || ! str_starts_with( $ruta, '/' . Cead_Acad_API::NS . '/' ) || ! get_current_user_id() ) {
			return $res;
		}
		$libres = [ '/sincronizar', '/encuestas', '/asistencia', '/auth', '/me', '/push', '/estudio' ];
		$sub    = substr( $ruta, strlen( '/' . Cead_Acad_API::NS ) );
		foreach ( $libres as $l ) {
			if ( str_starts_with( $sub, $l ) ) {
				return $res;
			}
		}
		$id = self::bloqueante( get_current_user_id() );
		return $id
			? new WP_Error( 'cead_estudio_encuesta_pendiente', __( 'Respondé la encuesta del mes para seguir usando la app.', 'cead-acad' ), [ 'status' => 423, 'encuesta_id' => $id ] )
			: $res;
	}

	/* --------------------------------------------------------- respuestas */

	/**
	 * Llamado desde Cead_Acad_Surveys_Responses::submit() cuando la encuesta es
	 * del estudio. `$answers` viene por question_id; se traduce a los ids del
	 * instrumento por posición (las preguntas se crearon en el mismo orden).
	 *
	 * @return int|WP_Error
	 */
	public static function responder( $survey_id, $user_id, array $answers, $ts_cliente = null ) {
		if ( get_user_meta( (int) $user_id, self::META_RESPONDIDA . (int) $survey_id, true ) ) {
			return new WP_Error( 'already_responded', __( 'Ya respondiste esta encuesta.', 'cead-acad' ) );
		}
		$rol = Cead_Acad_Estudio::rol_de( $user_id );
		if ( $rol !== get_post_meta( $survey_id, '_cead_acad_estudio_rol', true ) ) {
			return new WP_Error( 'forbidden', __( 'Esta encuesta es para otro rol.', 'cead-acad' ) );
		}
		$snapshot  = json_decode( (string) get_post_meta( $survey_id, '_cead_acad_estudio_preguntas', true ), true ) ?: [];
		$preguntas = Cead_Acad_Surveys_Questions::for_survey( $survey_id );
		$entrada   = [];
		foreach ( array_values( $preguntas ) as $i => $q ) {
			if ( isset( $snapshot[ $i ], $answers[ $q['id'] ] ) ) {
				$val = $answers[ $q['id'] ];
				$entrada[ $snapshot[ $i ]['id'] ] = ( is_array( $val ) && 'multiple' !== ( $snapshot[ $i ]['tipo'] ?? '' ) ) ? reset( $val ) : $val;
			}
		}
		$v = Cead_Acad_Estudio_Logica::validar_respuestas( $snapshot, $entrada );
		if ( $v['errores'] ) {
			return new WP_Error( 'required_missing', implode( ' · ', $v['errores'] ) );
		}

		// Retirado: cumple con la obligación (se marca respondida), pero sus datos no se guardan.
		$consentimiento = Cead_Acad_Estudio::estado_consentimiento( $user_id );
		if ( 'retirado' !== $consentimiento && Cead_Acad_Estudio::recolectando() ) {
			global $wpdb;
			$wpdb->insert( cead_acad_table( 'estudio_respuestas' ), [
				'survey_id'           => (int) $survey_id,
				'seudonimo'           => Cead_Acad_Estudio_Logica::seudonimo( Cead_Acad_Estudio::sal(), $user_id ),
				'institucion'         => substr( (string) Cead_Acad_Estudio::config()['codigo'], 0, 8 ),
				'rol'                 => $rol,
				'curso_grupo'         => 'alumno' === $rol ? Cead_Acad_Estudio::curso_grupo( $user_id ) : null,
				'instrumento_clave'   => (string) get_post_meta( $survey_id, '_cead_acad_estudio_instrumento', true ),
				'instrumento_version' => (string) get_post_meta( $survey_id, '_cead_acad_estudio_version', true ),
				'mes'                 => (int) get_post_meta( $survey_id, '_cead_acad_estudio_mes', true ),
				'cerradas'            => wp_json_encode( $v['cerradas'] ),
				'abiertas'            => wp_json_encode( (object) $v['abiertas'] ),
				'sus_puntaje'         => $v['sus'] ? Cead_Acad_Estudio_Logica::sus( $v['sus'] ) : null,
				'con_consentimiento'  => 'activo' === $consentimiento ? 1 : 0,
				'ts_cliente'          => $ts_cliente ? gmdate( 'Y-m-d H:i:s', (int) $ts_cliente ) : null,
				'ts_servidor'         => current_time( 'mysql', 1 ),
			] );
		}
		update_user_meta( (int) $user_id, self::META_RESPONDIDA . (int) $survey_id, time() );
		Cead_Acad_Estudio::registrar( 'encuesta_respondida', [
			'user_id' => $user_id, 'canal' => defined( 'REST_REQUEST' ) && REST_REQUEST ? 'app' : 'web', 'rol' => $rol,
			'entidad_tipo' => 'encuesta', 'entidad_id' => $survey_id, 'ts_cliente' => $ts_cliente,
			'meta' => [ 'mes' => (int) get_post_meta( $survey_id, '_cead_acad_estudio_mes', true ) ],
		] );
		return (int) $survey_id;
	}

	/* -------------------------------------------------------- programador */

	public static function programar() {
		$c = Cead_Acad_Estudio::config();
		if ( ! $c['activo'] || $c['cerrado'] || ! $c['inicio'] ) {
			return;
		}
		$cfg   = Cead_Acad_Estudio::instrumentos();
		$cal   = $cfg['calendario'] ?? [];
		$ahora = time();
		if ( $ahora >= Cead_Acad_Estudio_Logica::fin_recoleccion( $c['inicio'], $cal ) ) {
			$c['cerrado'] = true;
			update_option( Cead_Acad_Estudio::OPCION, $c );
			Cead_Acad_Estudio::bitacora( 'operacion', 'estudio_cerrado', [ 'motivo' => '12 meses cumplidos' ] );
			Cead_Acad_Audit::log( 'estudio_cerrado', [ 'user_id' => 0 ] );
			return;
		}
		for ( $mes = 0; $mes <= 12; $mes++ ) {
			$v = Cead_Acad_Estudio_Logica::ventana( $c['inicio'], $mes, $cal );
			if ( $ahora < $v['envio'] || $ahora >= $v['cierre'] ) {
				continue;
			}
			foreach ( self::roles() as $rol ) {
				$candado = "cead_acad_estudio_m{$mes}_{$rol}";
				$id = (int) get_option( $candado );
				if ( ! $id ) {
					// add_option falla si ya existe: es el candado contra el doble envío.
					if ( ! add_option( $candado, -1, '', 'no' ) ) {
						continue;
					}
					$id = self::crear( $cfg, $mes, $rol, $v );
					update_option( $candado, $id, false );
				}
				if ( $id > 0 ) {
					self::recordar( $id, $rol, $v );
				}
			}
		}
	}

	protected static function crear( array $cfg, $mes, $rol, array $v ) {
		try {
			$inst = Cead_Acad_Estudio_Logica::instrumento( $cfg, $mes, $rol, Cead_Acad_Estudio::features_activas() );
		} catch ( InvalidArgumentException $e ) {
			Cead_Acad_Estudio::bitacora( 'encuestas', 'envio_sin_preguntas', [ 'mes' => $mes, 'rol' => $rol ] );
			return 0;
		}
		if ( ! $inst['preguntas'] ) {
			Cead_Acad_Estudio::bitacora( 'encuestas', 'envio_sin_preguntas', [ 'mes' => $mes, 'rol' => $rol, 'omitidas' => $inst['omitidas'] ] );
			return 0;
		}
		$titulos = [ 0 => __( 'Encuesta inicial', 'cead-acad' ), 12 => __( 'Encuesta final', 'cead-acad' ) ];
		$id = wp_insert_post( [
			'post_type'   => Cead_Acad_Surveys_CPT::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $titulos[ $mes ] ?? __( 'Encuesta del mes', 'cead-acad' ),
			'post_content'=> __( 'Son menos de 3 minutos. Es parte del estudio sobre la app del colegio.', 'cead-acad' ),
			'post_author' => 0,
		] );
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		foreach ( [
			self::META_TIPO                 => 'estudio',
			'_cead_acad_estudio_mes'         => $mes,
			'_cead_acad_estudio_rol'         => $rol,
			'_cead_acad_estudio_instrumento' => $inst['clave'],
			'_cead_acad_estudio_version'     => $inst['version'],
			'_cead_acad_estudio_preguntas'   => wp_slash( wp_json_encode( $inst['preguntas'] ) ),
			'_cead_acad_estudio_omitidas'    => wp_slash( wp_json_encode( $inst['omitidas'] ) ),
			'_cead_acad_estudio_envio'       => $v['envio'],
			'_cead_acad_estudio_cierre'      => $v['cierre'],
			// Hora LOCAL, como las encuestas normales (Cead_Acad_Surveys_CPT::is_open).
			'_cead_acad_survey_opens_at'     => wp_date( 'Y-m-d H:i:s', $v['envio'] ),
			'_cead_acad_survey_closes_at'    => wp_date( 'Y-m-d H:i:s', $v['cierre'] ),
			'_cead_acad_survey_anonymous'    => 0,
		] as $k => $val ) {
			update_post_meta( $id, $k, $val );
		}
		Cead_Acad_Surveys_Questions::replace_all( $id, array_map( static function ( $p ) {
			return [
				'type'      => Cead_Acad_Estudio_Logica::tipo_survey( $p['tipo'] ),
				'text'      => $p['texto'],
				'required'  => empty( $p['opcional'] ),
				'options'   => $p['opciones'] ?? [],
				'scale_min' => 1,
				'scale_max' => 5,
			];
		}, $inst['preguntas'] ) );
		Cead_Acad_Audiences::set( 'survey', $id, array_map( static function ( $r ) {
			return [ 'type' => 'role', 'value' => $r ];
		}, self::DESTINOS[ $rol ] ) );

		$uids = get_users( [ 'role__in' => self::DESTINOS[ $rol ], 'fields' => 'ID' ] );
		foreach ( $uids as $uid ) {
			Cead_Acad_Estudio::registrar( 'encuesta_enviada', [ 'user_id' => (int) $uid, 'canal' => 'sistema', 'rol' => $rol,
				'entidad_tipo' => 'encuesta', 'entidad_id' => $id, 'meta' => [ 'mes' => $mes ] ] );
		}
		self::avisar( $uids, $id, $inst['clave'], __( 'Respondela para seguir usando la app.', 'cead-acad' ) );
		Cead_Acad_Audit::log( 'estudio_encuesta_enviada', [ 'user_id' => 0, 'entity_type' => 'survey', 'entity_id' => $id, 'payload' => [ 'mes' => $mes, 'rol' => $rol ] ] );
		Cead_Acad_Estudio::bitacora( 'encuestas', 'envio', [ 'encuesta' => (int) $id, 'mes' => $mes, 'rol' => $rol, 'version' => $inst['version'],
			'preguntas' => count( $inst['preguntas'] ), 'omitidas' => $inst['omitidas'], 'asignadas' => count( $uids ) ] );
		return (int) $id;
	}

	/** Recordatorios (día 3 y último día): cada uno una sola vez, a quien no respondió. */
	protected static function recordar( $id, $rol, array $v ) {
		foreach ( $v['recordatorios'] as $k => $t ) {
			if ( time() < $t || ! add_option( "cead_acad_estudio_rec_{$id}_{$k}", 1, '', 'no' ) ) {
				continue;
			}
			$faltan = array_values( array_filter( get_users( [ 'role__in' => self::DESTINOS[ $rol ], 'fields' => 'ID' ] ), static function ( $uid ) use ( $id ) {
				return ! get_user_meta( (int) $uid, self::META_RESPONDIDA . $id, true );
			} ) );
			$ultimo = count( $v['recordatorios'] ) - 1 === $k;
			self::avisar( $faltan, $id, 'recordatorio', $ultimo ? __( 'Hoy es el último día. Son menos de 3 minutos.', 'cead-acad' ) : __( 'Tenés una encuesta pendiente.', 'cead-acad' ) );
		}
	}

	protected static function avisar( array $uids, $survey_id, $tipo, $cuerpo ) {
		if ( ! class_exists( 'Cead_Acad_Push' ) || ! $uids ) {
			return;
		}
		$n = Cead_Acad_Push::avisar( $uids, [
			'categoria' => 'comunicados',
			'titulo'    => get_the_title( $survey_id ),
			'cuerpo'    => $cuerpo,
			'datos'     => [ 'tipo' => 'encuesta', 'id' => (int) $survey_id ],
		] );
		if ( $n ) {
			foreach ( $uids as $uid ) {
				Cead_Acad_Estudio::registrar( 'push_enviado', [ 'user_id' => (int) $uid, 'canal' => 'sistema',
					'entidad_tipo' => 'encuesta', 'entidad_id' => $survey_id, 'meta' => [ 'tipo_aviso' => $tipo ] ] );
			}
		}
	}
}
