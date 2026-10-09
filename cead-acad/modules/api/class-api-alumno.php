<?php
/**
 * El resto del panel del alumno, para las apps.
 *
 * Recursos, encuestas, preguntas frecuentes, perfil, contacto, notificaciones
 * y las escrituras de tareas y comunicados. Igual que en `Cead_Acad_API_Panel`,
 * ninguna regla se escribe dos veces: cada endpoint llama a la misma función
 * que usa el formulario de la web, con los mismos permisos.
 *
 * Toda escritura pasa por `Cead_Acad_API::una_vez()`, porque la app las manda
 * desde una cola que se reintenta al volver la conexión. Por la misma razón
 * las acciones fijan un estado («queda hecha», «queda como favorito») en vez de
 * invertirlo: repetir un «fijar» no cambia nada; repetir un «invertir» deshace.
 *
 * No hay endpoint de búsqueda. La app busca sobre lo que ya tiene sincronizado,
 * que es lo único que sirve sin señal, y así la consulta de la web no se
 * duplica acá.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_API_Alumno {

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'rutas' ] );
	}

	public function rutas() {
		$ns   = Cead_Acad_API::NS;
		$gate = Cead_Acad_API::gate();
		$id   = '(?P<id>\d+)';

		$r = static function ( $ruta, $metodo, $cb, $args = [] ) use ( $ns, $gate ) {
			register_rest_route( $ns, $ruta, [
				'methods'             => $metodo,
				'callback'            => $cb,
				'permission_callback' => $gate,
				'args'                => $args,
			] );
		};

		$r( '/recursos', 'GET', [ $this, 'recursos' ] );
		$r( "/recursos/{$id}/favorito", 'POST', [ $this, 'favorito' ], [
			'favorito' => [ 'required' => true, 'type' => 'boolean' ],
		] );

		$r( '/encuestas', 'GET', [ $this, 'encuestas' ] );
		$r( "/encuestas/{$id}", 'GET', [ $this, 'encuesta' ] );
		$r( "/encuestas/{$id}/respuestas", 'POST', [ $this, 'responder_encuesta' ], [
			'respuestas' => [ 'required' => true, 'type' => 'object' ],
		] );

		$r( '/faq', 'GET', [ $this, 'faq' ] );

		$r( '/perfil', 'GET', [ $this, 'perfil' ] );
		$r( '/perfil', 'POST', [ $this, 'guardar_perfil' ], [
			'nombre'   => [ 'required' => false, 'type' => 'string' ],
			'telefono' => [ 'required' => false, 'type' => 'string' ],
		] );
		$r( '/perfil/foto', 'POST', [ $this, 'guardar_foto' ] );

		$r( '/contacto', 'POST', [ $this, 'contacto' ], [
			'destinatario' => [ 'required' => true, 'type' => 'string', 'enum' => [ 'direccion', 'consejo', 'administracion' ] ],
			'mensaje'      => [ 'required' => true, 'type' => 'string' ],
		] );

		$r( '/notificaciones', 'GET', [ $this, 'notificaciones' ] );
		$r( '/notificaciones/vistas', 'POST', [ $this, 'notificaciones_vistas' ] );

		$r( "/tareas/{$id}/hecha", 'POST', [ $this, 'tarea_hecha' ], [
			'hecha' => [ 'required' => true, 'type' => 'boolean' ],
		] );
		$r( "/tareas/{$id}/entrega", 'POST', [ $this, 'tarea_entrega' ] );

		$r( "/comunicados/{$id}/leido", 'POST', [ $this, 'comunicado_leido' ] );
	}

	/* ------------------------------------------------------------ recursos */

	public function recursos( $req ) {
		$uid  = get_current_user_id();
		$favs = Cead_Acad_Account::fav_ids( $uid );

		$posts = Cead_Acad_Resources_Acl::for_user( $uid, [
			'per_page' => min( 200, max( 1, (int) ( $req->get_param( 'por_pag' ) ?: 100 ) ) ),
			'paged'    => max( 1, (int) ( $req->get_param( 'pagina' ) ?: 1 ) ),
			'subject'  => (string) $req->get_param( 'materia' ),
			'type'     => (string) $req->get_param( 'tipo' ),
		] );

		return rest_ensure_response( [
			'recursos' => array_map( static function ( $p ) use ( $favs ) {
				return Cead_Acad_API_Alumno::recurso( $p, $favs );
			}, $posts ),
		] );
	}

	public static function recurso( $p, array $favs ) {
		$adjunto = (int) get_post_meta( $p->ID, '_cead_acad_resource_attachment_id', true );
		$archivo = $adjunto ? get_attached_file( $adjunto ) : '';
		return [
			'id'          => (int) $p->ID,
			'titulo'      => get_the_title( $p ),
			'descripcion' => wp_strip_all_tags( $p->post_content ),
			'fecha'       => get_post_time( 'c', true, $p ),
			'url'         => Cead_Acad_Resources_CPT::resource_url( $p->ID ) ?: null,
			// Con esto la app sabe si es un archivo para bajar y guardar sin
			// conexión, o un enlace que de todas formas necesita internet.
			'es_archivo'  => (bool) $adjunto,
			'tipo_mime'   => $adjunto ? (string) get_post_mime_type( $adjunto ) : null,
			'tamano'      => ( $archivo && file_exists( $archivo ) ) ? (int) filesize( $archivo ) : null,
			'materias'    => self::terminos( $p, Cead_Acad_Resources_CPT::TAX_SUBJECT ),
			'tipos'       => self::terminos( $p, Cead_Acad_Resources_CPT::TAX_TYPE ),
			'imagen'      => get_the_post_thumbnail_url( $p, 'medium' ) ?: null,
			'favorito'    => in_array( (int) $p->ID, $favs, true ),
		];
	}

	public function favorito( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			$uid = get_current_user_id();
			$rid = (int) $req->get_param( 'id' );
			if ( ! Cead_Acad_Resources_Acl::user_can_view( $rid, $uid ) ) {
				return new WP_Error( 'cead_api_no_visible', __( 'Ese recurso no está disponible para vos.', 'cead-acad' ), [ 'status' => 403 ] );
			}
			return [ 'favorito' => Cead_Acad_Account::fijar_favorito( $uid, $rid, (bool) $req->get_param( 'favorito' ) ) ];
		} );
	}

	/* ----------------------------------------------------------- encuestas */

	public function encuestas() {
		$uid = get_current_user_id();
		$ids = Cead_Acad_Audiences::subjects_for_user( 'survey', $uid );
		if ( ! $ids ) {
			return rest_ensure_response( [ 'encuestas' => [] ] );
		}
		$posts = get_posts( [
			'post_type'      => Cead_Acad_Surveys_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'post__in'       => $ids,
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );
		return rest_ensure_response( [
			'encuestas' => array_map( static function ( $p ) use ( $uid ) {
				return Cead_Acad_API_Alumno::encuesta_breve( $p, $uid );
			}, $posts ),
		] );
	}

	public static function encuesta_breve( $p, $uid ) {
		$anonima = Cead_Acad_Surveys_CPT::is_anonymous( $p->ID );
		return [
			'id'          => (int) $p->ID,
			'titulo'      => get_the_title( $p ),
			'descripcion' => wp_strip_all_tags( $p->post_content ),
			'abierta'     => Cead_Acad_Surveys_CPT::is_open( $p->ID ),
			'anonima'     => $anonima,
			'abre'        => (string) get_post_meta( $p->ID, '_cead_acad_survey_opens_at', true ) ?: null,
			'cierra'      => (string) get_post_meta( $p->ID, '_cead_acad_survey_closes_at', true ) ?: null,
			// En una anónima el servidor no sabe si ya respondiste —no guarda
			// quién fue—, así que se dice «no sé» en vez de mentir con un «no».
			'respondida'  => $anonima ? null : Cead_Acad_Surveys_Responses::user_already_responded( $p->ID, $uid ),
		];
	}

	public function encuesta( $req ) {
		$uid = get_current_user_id();
		$sid = (int) $req->get_param( 'id' );
		$p   = $this->encuesta_visible( $sid, $uid );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$datos              = self::encuesta_breve( $p, $uid );
		$datos['preguntas'] = self::preguntas( $sid );
		return rest_ensure_response( $datos );
	}

	public static function preguntas( $sid ) {
		return array_map( static function ( $q ) {
			return [
				'id'          => (int) $q['id'],
				'texto'       => (string) $q['text'],
				'tipo'        => (string) $q['type'],
				'obligatoria' => (bool) $q['required'],
				'opciones'    => array_values( (array) ( $q['config']['options'] ?? [] ) ),
				'min'         => isset( $q['config']['min'] ) ? (int) $q['config']['min'] : null,
				'max'         => isset( $q['config']['max'] ) ? (int) $q['config']['max'] : null,
			];
		}, (array) Cead_Acad_Surveys_Questions::for_survey( $sid ) );
	}

	public function responder_encuesta( $req ) {
		return Cead_Acad_API::una_vez( $req, function () use ( $req ) {
			$uid = get_current_user_id();
			$sid = (int) $req->get_param( 'id' );
			$p   = $this->encuesta_visible( $sid, $uid );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			if ( ! Cead_Acad_Surveys_CPT::is_open( $sid ) ) {
				return new WP_Error( 'closed', __( 'La encuesta ya cerró.', 'cead-acad' ), [ 'status' => 409 ] );
			}

			$r = Cead_Acad_Surveys_Responses::submit( $sid, $uid, (array) $req->get_param( 'respuestas' ) );
			if ( is_wp_error( $r ) ) {
				$status = 'already_responded' === $r->get_error_code() ? 409 : 400;
				return new WP_Error( $r->get_error_code(), $r->get_error_message(), [ 'status' => $status ] );
			}
			// Sin el id de la respuesta: en una anónima, devolverlo —y quedar
			// guardado en la memoria de idempotencia, junto a quién la mandó—
			// sería atar la respuesta a la persona.
			return [ 'ok' => true ];
		} );
	}

	protected function encuesta_visible( $sid, $uid ) {
		$p = get_post( $sid );
		if ( ! $p || Cead_Acad_Surveys_CPT::POST_TYPE !== $p->post_type || 'publish' !== $p->post_status
			|| ! in_array( (int) $sid, Cead_Acad_Audiences::subjects_for_user( 'survey', $uid ), true ) ) {
			return new WP_Error( 'cead_api_no_visible', __( 'Esa encuesta no está disponible para vos.', 'cead-acad' ), [ 'status' => 403 ] );
		}
		return $p;
	}

	/* ----------------------------------------------------------------- faq */

	public function faq() {
		return rest_ensure_response( [ 'preguntas' => self::lista_faq() ] );
	}

	public static function lista_faq() {
		if ( ! class_exists( 'Cead_Acad_FAQ' ) ) {
			return [];
		}
		return array_map( static function ( $f ) {
			return [
				'id'        => (int) $f->ID,
				'pregunta'  => get_the_title( $f ),
				'respuesta' => apply_filters( 'the_content', $f->post_content ),
			];
		}, Cead_Acad_FAQ::all() );
	}

	/* -------------------------------------------------------------- perfil */

	public function perfil() {
		return rest_ensure_response( self::datos_perfil( get_current_user_id() ) );
	}

	public static function datos_perfil( $uid ) {
		$u = get_userdata( $uid );
		return [
			'nombre'             => $u ? $u->display_name : '',
			'email'              => $u ? $u->user_email : '',
			'usuario'            => $u ? $u->user_login : '',
			'telefono'           => (string) get_user_meta( $uid, Cead_Acad_Account::PHONE_META, true ),
			'telefono_verificado'=> Cead_Acad_Account::is_phone_verified( $uid ),
			'foto'               => Cead_Acad_Account::avatar_url( $uid, 'medium' ) ?: null,
			'iniciales'          => Cead_Acad_Account::initials( $u ? $u->display_name : '' ),
		];
	}

	public function guardar_perfil( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			$uid = get_current_user_id();
			$u   = get_userdata( $uid );
			// Un campo que no vino queda como estaba: «no lo mandé» no es «borralo».
			$nombre   = null !== $req->get_param( 'nombre' ) ? (string) $req->get_param( 'nombre' ) : ( $u ? $u->display_name : '' );
			$telefono = null !== $req->get_param( 'telefono' ) ? (string) $req->get_param( 'telefono' ) : (string) get_user_meta( $uid, Cead_Acad_Account::PHONE_META, true );

			$r = Cead_Acad_Account::guardar_datos( $uid, $nombre, $telefono );
			if ( is_wp_error( $r ) ) {
				return new WP_Error( $r->get_error_code(), $r->get_error_message(), [ 'status' => 409 ] );
			}
			return Cead_Acad_API_Alumno::datos_perfil( $uid );
		} );
	}

	public function guardar_foto( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () {
			$uid = get_current_user_id();
			if ( empty( $_FILES['foto']['name'] ) || ! empty( $_FILES['foto']['error'] ) ) {
				return new WP_Error( 'archivo', __( 'Falta la foto.', 'cead-acad' ), [ 'status' => 400 ] );
			}
			$r = Cead_Acad_Account::guardar_avatar( $uid, 'foto' );
			if ( is_wp_error( $r ) ) {
				return new WP_Error( $r->get_error_code(), $r->get_error_message(), [ 'status' => 400 ] );
			}
			return Cead_Acad_API_Alumno::datos_perfil( $uid );
		} );
	}

	/* ------------------------------------------------------------ contacto */

	public function contacto( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			$r = Cead_Acad_Account::enviar_mensaje(
				get_current_user_id(),
				(string) $req->get_param( 'destinatario' ),
				(string) $req->get_param( 'mensaje' )
			);
			if ( is_wp_error( $r ) ) {
				return new WP_Error( $r->get_error_code(), $r->get_error_message(), [ 'status' => 400 ] );
			}
			return [ 'ok' => true ];
		} );
	}

	/* ------------------------------------------------------ notificaciones */

	public function notificaciones() {
		$uid = get_current_user_id();
		$items = array_map( static function ( $i ) {
			return [
				'tipo'   => (string) ( $i['type'] ?? '' ),
				'titulo' => (string) ( $i['title'] ?? '' ),
				'cuando' => (string) ( $i['when'] ?? '' ),
			];
		}, Cead_Acad_Notifications::items( $uid ) );

		return rest_ensure_response( [
			'notificaciones' => $items,
			'sin_leer'       => Cead_Acad_Notifications::badge_count( $uid ),
		] );
	}

	public function notificaciones_vistas( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () {
			Cead_Acad_Notifications::marcar_todo_visto( get_current_user_id() );
			return [ 'ok' => true ];
		} );
	}

	/* -------------------------------------------------------------- tareas */

	public function tarea_hecha( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			$r = Cead_Acad_Tasks_Frontend::fijar_hecha( get_current_user_id(), (int) $req->get_param( 'id' ), (bool) $req->get_param( 'hecha' ) );
			if ( is_wp_error( $r ) ) {
				return new WP_Error( $r->get_error_code(), $r->get_error_message(), [ 'status' => 403 ] );
			}
			return [ 'hecha' => $r ];
		} );
	}

	public function tarea_entrega( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			$r = Cead_Acad_Tasks_Frontend::guardar_entrega( get_current_user_id(), (int) $req->get_param( 'id' ), 'entrega' );
			if ( is_wp_error( $r ) ) {
				$status = 'forbidden' === $r->get_error_code() ? 403 : 400;
				return new WP_Error( $r->get_error_code(), $r->get_error_message(), [ 'status' => $status ] );
			}
			return [ 'ok' => true, 'archivo' => wp_get_attachment_url( $r ) ?: null ];
		} );
	}

	/* --------------------------------------------------------- comunicados */

	/**
	 * Marca un comunicado como leído sin traerlo.
	 *
	 * `GET /comunicados/{id}` ya lo marca, pero la app los lee del almacén
	 * local, sin pedir nada. Cuando alguien abre un comunicado sin señal, la
	 * app anota la lectura y la manda por acá cuando vuelve la conexión.
	 */
	public function comunicado_leido( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			$uid = get_current_user_id();
			$id  = (int) $req->get_param( 'id' );
			if ( ! Cead_Acad_Broadcasts_Feed::user_can_view( $id, $uid ) ) {
				return new WP_Error( 'cead_api_no_visible', __( 'Ese comunicado no es para vos.', 'cead-acad' ), [ 'status' => 403 ] );
			}
			Cead_Acad_Broadcasts_Reads::mark_read( $id, $uid );
			return [ 'ok' => true ];
		} );
	}

	/* ------------------------------------------------------------- interno */

	protected static function terminos( $post, $taxonomia ) {
		$t = get_the_terms( $post, $taxonomia );
		if ( ! $t || is_wp_error( $t ) ) {
			return [];
		}
		return array_values( array_map( static function ( $x ) {
			return [ 'slug' => $x->slug, 'nombre' => $x->name ];
		}, $t ) );
	}
}
