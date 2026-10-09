<?php
/**
 * Avisos al teléfono (push).
 *
 * Con el bot de WhatsApp dado de baja, esto es lo que hace que un comunicado
 * llegue a la gente sin que tenga que abrir la app: es el reemplazo del
 * «te escribió el colegio». Tres decisiones lo ordenan:
 *
 *  1. Un aviso no se manda dentro de la petición que lo causa. Publicar un
 *     comunicado a todo el colegio son cientos de llamadas a Firebase; hacerlas
 *     mientras la secretaria espera que cargue la página sería convertir cada
 *     publicación en un cuelgue. Se encolan en lotes por wp-cron.
 *  2. Un dispositivo está atado a la sesión de la app que lo registró. Si esa
 *     sesión se cierra —logout, contraseña cambiada, vencimiento— el aviso
 *     deja de ir a ese teléfono. Sin esto, alguien que presta o vende el
 *     celular seguiría recibiendo los avisos de la cuenta que dejó.
 *  3. Lo que se ve con el teléfono bloqueado es genérico donde el contenido es
 *     delicado: el buzón avisa «tenés una respuesta», nunca la respuesta.
 *
 * Sin credenciales de Firebase (`Cead_Acad_Push_Fcm::disponible()` falso) todo
 * esto se salta en silencio: la app funciona igual, solo que sin timbre.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Push {

	const META_DISPOSITIVOS = '_cead_acad_push_devices';
	const META_PREFS        = '_cead_acad_push_prefs';
	/** Para encontrar de quién es un token sin recorrer a todos los usuarios. */
	const META_INDICE       = '_cead_acad_push_t_';
	const META_AVISADO      = '_cead_acad_publicado_avisado';

	const EVENTO_LOTE = 'cead_acad_push_lote';

	/** Dispositivos por persona. Más que eso es alguien que reinstala sin parar. */
	const MAX_DISPOSITIVOS = 5;
	/** Personas por lote de wp-cron. */
	const LOTE = 40;
	/** Una publicación es «reciente» si se publicó hace menos de esto (para el editor del admin). */
	const RECIENTE_SEG = 600;

	/** Categorías de aviso que cada persona puede apagar. */
	const CATEGORIAS = [ 'comunicados', 'eventos', 'tareas', 'notas', 'buzon' ];

	public function boot() {
		add_action( self::EVENTO_LOTE, [ __CLASS__, 'enviar_lote' ], 10, 2 );
		add_action( 'cead_acad_api_sesion_cerrada', [ __CLASS__, 'sesion_cerrada' ], 10, 2 );

		add_action( 'cead_acad_comunicado_publicado', [ __CLASS__, 'al_publicar_comunicado' ] );
		add_action( 'cead_acad_evento_publicado', [ __CLASS__, 'al_publicar_evento' ] );
		add_action( 'cead_acad_tarea_asignada', [ __CLASS__, 'al_asignar_tarea' ] );
		add_action( 'cead_acad_nota_cargada', [ __CLASS__, 'al_cargar_nota' ], 10, 3 );
		add_action( 'cead_acad_buzon_respuesta', [ __CLASS__, 'al_responder_buzon' ], 10, 4 );
		add_action( 'cead_acad_buzon_reporte_nuevo', [ __CLASS__, 'al_llegar_reporte' ] );
		add_action( 'cead_acad_buzon_mensaje_nuevo', [ __CLASS__, 'al_llegar_mensaje' ] );
	}

	/* ------------------------------------------------------- dispositivos */

	/**
	 * Registra (o actualiza) el teléfono de una sesión.
	 *
	 * Un token de FCM pertenece a UNA persona a la vez: si alguien inicia
	 * sesión en un teléfono donde antes estaba otra, el token pasa a la nueva
	 * y se le saca a la anterior. Sin eso, los avisos de la primera seguirían
	 * apareciendo en el teléfono de la segunda.
	 *
	 * @return true|WP_Error
	 */
	public static function registrar( $user_id, $token, $plataforma, $jti, $nombre = '' ) {
		$token = trim( (string) $token );
		if ( ! self::token_valido( $token ) ) {
			return new WP_Error( 'token_invalido', __( 'El token del dispositivo no es válido.', 'cead-acad' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $plataforma, [ 'android', 'ios' ], true ) ) {
			return new WP_Error( 'plataforma_invalida', __( 'Plataforma desconocida.', 'cead-acad' ), [ 'status' => 400 ] );
		}
		if ( '' === (string) $jti ) {
			return new WP_Error( 'sin_sesion', __( 'Hay que registrar el dispositivo desde la sesión de la app.', 'cead-acad' ), [ 'status' => 400 ] );
		}

		$user_id = (int) $user_id;
		$clave   = self::clave( $token );

		// Si el token estaba en otra cuenta, sale de ahí.
		foreach ( self::duenos( $clave ) as $otro ) {
			if ( (int) $otro !== $user_id ) {
				self::quitar( (int) $otro, $clave );
			}
		}

		$lista           = self::dispositivos( $user_id );
		$lista[ $clave ] = [
			'token'      => $token,
			'plataforma' => $plataforma,
			'jti'        => (string) $jti,
			'nombre'     => mb_substr( sanitize_text_field( (string) $nombre ), 0, 60 ),
			'alta'       => time(),
		];
		$lista = self::podar( $lista );
		update_user_meta( $user_id, self::META_DISPOSITIVOS, $lista );
		update_user_meta( $user_id, self::META_INDICE . $clave, '1' );
		return true;
	}

	/** Baja de un dispositivo por su token. */
	public static function dar_de_baja( $user_id, $token ) {
		self::quitar( (int) $user_id, self::clave( trim( (string) $token ) ) );
	}

	/** Pura: deja a lo sumo MAX_DISPOSITIVOS, los más nuevos. */
	public static function podar( array $lista ) {
		if ( count( $lista ) <= self::MAX_DISPOSITIVOS ) {
			return $lista;
		}
		uasort( $lista, static function ( $a, $b ) {
			return (int) ( $b['alta'] ?? 0 ) <=> (int) ( $a['alta'] ?? 0 );
		} );
		return array_slice( $lista, 0, self::MAX_DISPOSITIVOS, true );
	}

	/** Los tokens de FCM miden cientos de caracteres y no llevan espacios. */
	public static function token_valido( $token ) {
		return 1 === preg_match( '/^[A-Za-z0-9_:\-\.]{20,4096}$/', (string) $token );
	}

	public static function clave( $token ) {
		return substr( hash( 'sha256', (string) $token ), 0, 16 );
	}

	public static function dispositivos( $user_id ) {
		$l = get_user_meta( (int) $user_id, self::META_DISPOSITIVOS, true );
		return is_array( $l ) ? $l : [];
	}

	protected static function quitar( $user_id, $clave ) {
		$lista = self::dispositivos( $user_id );
		unset( $lista[ $clave ] );
		if ( $lista ) {
			update_user_meta( $user_id, self::META_DISPOSITIVOS, $lista );
		} else {
			delete_user_meta( $user_id, self::META_DISPOSITIVOS );
		}
		delete_user_meta( $user_id, self::META_INDICE . $clave );
	}

	/** @return int[] */
	protected static function duenos( $clave ) {
		return array_map( 'intval', get_users( [ 'meta_key' => self::META_INDICE . $clave, 'meta_compare' => 'EXISTS', 'fields' => 'ID' ] ) );
	}

	/** Se cerró una sesión: el teléfono que estaba atado a ella deja de recibir avisos. */
	public static function sesion_cerrada( $user_id, $jti ) {
		foreach ( self::dispositivos( $user_id ) as $clave => $d ) {
			if ( null === $jti || '' === (string) $jti || ( $d['jti'] ?? '' ) === (string) $jti ) {
				self::quitar( (int) $user_id, $clave );
			}
		}
	}

	/* ------------------------------------------------------- preferencias */

	/** @return array<string,bool> Todas las categorías; las que nunca se tocaron, activas. */
	public static function preferencias( $user_id ) {
		$guardadas = get_user_meta( (int) $user_id, self::META_PREFS, true );
		return self::completar_preferencias( is_array( $guardadas ) ? $guardadas : [] );
	}

	/** Pura. */
	public static function completar_preferencias( array $guardadas ) {
		$out = [];
		foreach ( self::CATEGORIAS as $c ) {
			$out[ $c ] = array_key_exists( $c, $guardadas ) ? (bool) $guardadas[ $c ] : true;
		}
		return $out;
	}

	public static function guardar_preferencias( $user_id, array $nuevas ) {
		$actuales = self::preferencias( $user_id );
		foreach ( self::CATEGORIAS as $c ) {
			if ( array_key_exists( $c, $nuevas ) ) {
				$actuales[ $c ] = (bool) $nuevas[ $c ];
			}
		}
		update_user_meta( (int) $user_id, self::META_PREFS, $actuales );
		return $actuales;
	}

	/* ------------------------------------------------------------- avisos */

	/**
	 * Encola un aviso para una lista de personas.
	 *
	 * @param int[]  $user_ids
	 * @param array  $aviso    categoria, titulo, cuerpo, datos.
	 * @param int[]  $excluir  Quién no lo recibe (típicamente, quien lo causó).
	 * @return int Cuántas personas quedaron en cola.
	 */
	public static function avisar( array $user_ids, array $aviso, array $excluir = [] ) {
		if ( ! ( new Cead_Acad_Push_Fcm() )->disponible() ) {
			return 0;
		}
		$ids = array_values( array_diff( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ), array_map( 'intval', $excluir ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$aviso = self::limpiar_aviso( $aviso );
		foreach ( array_chunk( $ids, self::LOTE ) as $lote ) {
			wp_schedule_single_event( time(), self::EVENTO_LOTE, [ $lote, $aviso ] );
		}
		spawn_cron();
		return count( $ids );
	}

	/** Pura: lo que viaja en la cola, acotado. */
	public static function limpiar_aviso( array $aviso ) {
		$datos = [];
		foreach ( (array) ( $aviso['datos'] ?? [] ) as $k => $v ) {
			$datos[ sanitize_key( (string) $k ) ] = (string) $v;
		}
		return [
			'categoria' => in_array( $aviso['categoria'] ?? '', self::CATEGORIAS, true ) ? $aviso['categoria'] : 'comunicados',
			'titulo'    => mb_substr( wp_strip_all_tags( (string) ( $aviso['titulo'] ?? '' ) ), 0, 80 ),
			'cuerpo'    => mb_substr( wp_strip_all_tags( (string) ( $aviso['cuerpo'] ?? '' ) ), 0, 160 ),
			'datos'     => $datos,
		];
	}

	/** El trabajo de wp-cron: manda el aviso a cada teléfono de cada persona del lote. */
	public static function enviar_lote( $user_ids, $aviso ) {
		$fcm = new Cead_Acad_Push_Fcm();
		if ( ! $fcm->disponible() || ! is_array( $user_ids ) || ! is_array( $aviso ) ) {
			return [ 'enviados' => 0, 'descartados' => 0, 'fallidos' => 0 ];
		}
		@set_time_limit( 0 );
		return self::enviar_ahora( $fcm, $user_ids, $aviso );
	}

	/**
	 * Separado de `enviar_lote()` para poder probarlo con un transporte de mentira.
	 *
	 * @param bool $respetar_preferencias Falso solo para el aviso de prueba, que
	 *                                    la persona pidió a propósito.
	 */
	public static function enviar_ahora( Cead_Acad_Push_Fcm $fcm, array $user_ids, array $aviso, $respetar_preferencias = true ) {
		$r = [ 'enviados' => 0, 'descartados' => 0, 'fallidos' => 0 ];
		foreach ( $user_ids as $uid ) {
			$uid = (int) $uid;
			if ( $respetar_preferencias && ! self::permite( self::preferencias( $uid ), (string) ( $aviso['categoria'] ?? '' ) ) ) {
				continue;
			}
			foreach ( self::dispositivos( $uid ) as $clave => $d ) {
				// La sesión que lo registró ya no existe (venció, o la
				// podaron): ese teléfono ya no es de esta cuenta.
				if ( ! Cead_Acad_API_Tokens::existe( $uid, (string) ( $d['jti'] ?? '' ) ) ) {
					self::quitar( $uid, $clave );
					$r['descartados']++;
					continue;
				}
				switch ( $fcm->enviar( (string) $d['token'], $aviso ) ) {
					case Cead_Acad_Push_Fcm::OK:
						$r['enviados']++;
						break;
					case Cead_Acad_Push_Fcm::DESCARTAR:
						self::quitar( $uid, $clave );
						$r['descartados']++;
						break;
					default:
						$r['fallidos']++;
				}
			}
		}
		return $r;
	}

	/** Pura. */
	public static function permite( array $prefs, $categoria ) {
		return ! array_key_exists( $categoria, $prefs ) || ! empty( $prefs[ $categoria ] );
	}

	/**
	 * Un aviso de prueba a los teléfonos de esta persona, sin cola, para poder
	 * comprobar que Firebase quedó bien configurado.
	 */
	public static function prueba( $user_id ) {
		$fcm = new Cead_Acad_Push_Fcm();
		if ( ! $fcm->disponible() ) {
			return [ 'configurado' => false, 'enviados' => 0, 'descartados' => 0, 'fallidos' => 0 ];
		}
		$aviso = self::limpiar_aviso( [
			'categoria' => 'buzon',
			'titulo'    => 'CEAD',
			'cuerpo'    => __( 'Los avisos funcionan. 🎉', 'cead-acad' ),
			'datos'     => [ 'tipo' => 'prueba' ],
		] );
		// La prueba va aunque la persona haya apagado alguna categoría: la pidió.
		return [ 'configurado' => true ] + self::enviar_ahora( $fcm, [ (int) $user_id ], $aviso, false );
	}

	/* ---------------------------------------------------------- listeners */

	public static function al_publicar_comunicado( $pid ) {
		$p = get_post( (int) $pid );
		if ( ! $p ) { return; }
		self::avisar(
			Cead_Acad_Broadcasts_Feed::resolve_recipient_user_ids( $p->ID ),
			self::aviso_comunicado( $p->ID, get_the_title( $p ) ),
			[ (int) $p->post_author ]
		);
	}

	public static function aviso_comunicado( $id, $titulo ) {
		return [
			'categoria' => 'comunicados',
			'titulo'    => __( 'Nuevo comunicado', 'cead-acad' ),
			'cuerpo'    => $titulo,
			'datos'     => [ 'tipo' => 'comunicado', 'id' => (int) $id ],
		];
	}

	public static function al_publicar_evento( $pid ) {
		$p = get_post( (int) $pid );
		if ( ! $p ) { return; }
		$inicio = Cead_Acad_Schedule_CPT::fecha_canonica( get_post_meta( $p->ID, '_cead_acad_event_start', true ) );
		self::avisar(
			Cead_Acad_Broadcasts_Feed::resolve_recipient_user_ids( $p->ID, 'event' ),
			self::aviso_evento( $p->ID, get_the_title( $p ), $inicio ),
			[ (int) $p->post_author ]
		);
	}

	public static function aviso_evento( $id, $titulo, $inicio ) {
		$cuando = '' !== $inicio ? ' · ' . date_i18n( 'j M · H:i', strtotime( $inicio ) ) : '';
		return [
			'categoria' => 'eventos',
			'titulo'    => __( 'Nuevo evento', 'cead-acad' ),
			'cuerpo'    => $titulo . $cuando,
			'datos'     => [ 'tipo' => 'evento', 'id' => (int) $id ],
		];
	}

	public static function al_asignar_tarea( $pid ) {
		$p = get_post( (int) $pid );
		if ( ! $p ) { return; }
		$curso = (int) get_post_meta( $p->ID, '_cead_acad_task_course', true );
		if ( ! $curso ) { return; }
		self::avisar(
			(array) Cead_Acad_Courses_Roster::users_in_course( $curso, 'delegate' ),
			[
				'categoria' => 'tareas',
				'titulo'    => __( 'Tarea nueva para tu curso', 'cead-acad' ),
				'cuerpo'    => get_the_title( $p ),
				'datos'     => [ 'tipo' => 'tarea', 'id' => (int) $p->ID ],
			],
			[ (int) $p->post_author ]
		);
	}

	public static function al_cargar_nota( $alumno_id, $materia, $periodo ) {
		self::avisar( [ (int) $alumno_id ], self::aviso_nota( (string) $materia, (string) $periodo ) );
	}

	/** Sin la nota en el texto: la pantalla bloqueada la ve cualquiera. */
	public static function aviso_nota( $materia, $periodo ) {
		return [
			'categoria' => 'notas',
			'titulo'    => __( 'Hay una nota nueva', 'cead-acad' ),
			'cuerpo'    => trim( $materia . ' · ' . $periodo, ' ·' ),
			'datos'     => [ 'tipo' => 'boletin' ],
		];
	}

	/**
	 * Coordinación contestó en el buzón.
	 *
	 * Solo a quien se sabe quién es: un reporte anónimo no guarda de quién es,
	 * y no tiene que averiguarse para avisarle. Y sin el texto de la
	 * respuesta: es algo que quien escribió lee con la app abierta.
	 */
	public static function al_responder_buzon( $tipo, $fila, $accion, $respuesta ) {
		if ( ! is_object( $fila ) || empty( $fila->user_id ) ) {
			return;
		}
		self::avisar( [ (int) $fila->user_id ], self::aviso_buzon_respuesta( $tipo, $fila ) );
	}

	/** Pura. Nunca lleva el texto de la respuesta. */
	public static function aviso_buzon_respuesta( $tipo, $fila ) {
		return [
			'categoria' => 'buzon',
			'titulo'    => __( 'Te respondieron', 'cead-acad' ),
			'cuerpo'    => 'reporte' === $tipo
				? sprintf( /* translators: %s: código del reporte */ __( 'Hay novedades de tu reporte %s.', 'cead-acad' ), (string) ( $fila->ref_code ?? '' ) )
				: __( 'Tu mensaje al colegio tiene una respuesta.', 'cead-acad' ),
			'datos'     => [ 'tipo' => 'mis_mensajes' ],
		];
	}

	public static function al_llegar_reporte( $codigo ) {
		self::avisar( self::con_capacidad( 'cead_acad_manage_reports' ), [
			'categoria' => 'buzon',
			'titulo'    => __( 'Buzón', 'cead-acad' ),
			'cuerpo'    => __( 'Entró un reporte nuevo.', 'cead-acad' ),
			'datos'     => [ 'tipo' => 'buzon' ],
		] );
	}

	/** @param string $para direccion|consejo|administracion */
	public static function al_llegar_mensaje( $para ) {
		// Lo del Consejo lo ve el Consejo (y Dirección, que ve todo); el resto,
		// quien maneja los reportes.
		self::avisar(
			self::con_capacidad( 'consejo' === $para ? 'cead_acad_manage_suggestions' : 'cead_acad_manage_reports' ),
			[
				'categoria' => 'buzon',
				'titulo'    => __( 'Buzón', 'cead-acad' ),
				'cuerpo'    => __( 'Tenés un mensaje nuevo.', 'cead-acad' ),
				'datos'     => [ 'tipo' => 'buzon' ],
			]
		);
	}

	/** Las personas cuyo rol tiene esa capacidad. */
	public static function con_capacidad( $cap ) {
		$roles = [];
		foreach ( wp_roles()->roles as $slug => $def ) {
			if ( ! empty( $def['capabilities'][ $cap ] ) ) {
				$roles[] = $slug;
			}
		}
		return $roles ? array_map( 'intval', get_users( [ 'role__in' => $roles, 'fields' => 'ID' ] ) ) : [];
	}

	/* ------------------------------------------------- publicación, una vez */

	/**
	 * Avisa que algo se publicó, UNA sola vez.
	 *
	 * Lo llaman tanto los caminos nuevos (que publican con todo ya guardado)
	 * como el editor del admin, donde WordPress pasa a «publicado» ANTES de
	 * guardar el formulario: avisar en esa transición sería avisar a una
	 * audiencia que todavía no se guardó. Por eso el editor llama recién al
	 * terminar de guardar, y la marca evita repetirlo en cada edición.
	 *
	 * @param string $accion Nombre de la acción a disparar.
	 */
	public static function marcar_y_avisar( $post_id, $accion ) {
		$post_id = (int) $post_id;
		if ( get_post_meta( $post_id, self::META_AVISADO, true ) ) {
			return false;
		}
		update_post_meta( $post_id, self::META_AVISADO, time() );
		do_action( $accion, $post_id );
		return true;
	}

	/**
	 * ¿Se publicó hace poco? El editor del admin lo usa para no avisar de algo
	 * que ya estaba publicado desde antes de que existieran los avisos: la
	 * primera vez que alguien corrigiera una errata de un evento viejo, saldría
	 * como «nuevo».
	 */
	public static function es_reciente( $post, $ahora = null ) {
		$ahora = $ahora ?? time();
		$pub   = strtotime( (string) ( $post->post_date_gmt ?? '' ) . ' UTC' );
		return $pub && ( $ahora - $pub ) <= self::RECIENTE_SEG;
	}
}
