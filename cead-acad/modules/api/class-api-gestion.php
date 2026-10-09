<?php
/**
 * Lo que el staff hacía por WhatsApp, ahora desde la app.
 *
 * Publicar comunicados, cargar eventos, contestar el buzón y mirar los números
 * de Dirección. Con el bot dado de baja, esto es lo que lo reemplaza; y como
 * con el resto de la API, nada se decide acá: cada endpoint llama a la misma
 * función que usa el panel web (`Cead_Acad_Broadcasts_CPT::crear`,
 * `Cead_Acad_Schedule_CPT::crear`, `Cead_Acad_Buzon`, `Cead_Acad_Metricas`),
 * y quién puede publicar a quién lo decide `Cead_Acad_Gestion_Audiencias`.
 *
 * También están acá los reportes del alumnado, porque son la otra punta del
 * buzón: mandar uno, seguirlo por su código y ver las respuestas a los
 * mensajes propios.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_API_Gestion {

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

		$r( '/gestion/audiencias', 'GET', [ $this, 'audiencias' ], [
			'para' => [ 'required' => false, 'type' => 'string', 'enum' => [ 'comunicado', 'evento' ], 'default' => 'comunicado' ],
		] );

		// `audiencias` puede llegar como lista (JSON) o como texto JSON (en
		// un multipart con imagen, donde todo es texto): se acepta las dos.
		$r( '/gestion/comunicados', 'POST', [ $this, 'publicar_comunicado' ], [
			'titulo'       => [ 'required' => false, 'type' => 'string' ],
			'texto'        => [ 'required' => true, 'type' => 'string' ],
			'audiencias'   => [ 'required' => true ],
			'categoria'    => [ 'required' => false, 'type' => 'string' ],
			'avisar_email' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
		] );

		$r( '/gestion/eventos', 'POST', [ $this, 'cargar_evento' ], [
			'titulo'      => [ 'required' => true, 'type' => 'string' ],
			'detalle'     => [ 'required' => false, 'type' => 'string' ],
			'inicio'      => [ 'required' => true, 'type' => 'string' ],
			'fin'         => [ 'required' => false, 'type' => 'string' ],
			'todo_el_dia' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			'lugar'       => [ 'required' => false, 'type' => 'string' ],
			'tipo'        => [ 'required' => false, 'type' => 'string', 'enum' => Cead_Acad_Schedule_CPT::TYPES ],
			'audiencias'  => [ 'required' => true ],
		] );

		$r( '/gestion/buzon', 'GET', [ $this, 'buzon' ] );
		$r( "/gestion/buzon/(?P<tipo>reporte|sugerencia)/{$id}", 'POST', [ $this, 'actuar_buzon' ], [
			'accion'    => [ 'required' => true, 'type' => 'string', 'enum' => array_values( array_unique( array_merge( Cead_Acad_Buzon::ACCIONES_REPORTE, Cead_Acad_Buzon::ACCIONES_SUGERENCIA ) ) ) ],
			'respuesta' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
		] );

		$r( '/gestion/metricas', 'GET', [ $this, 'metricas' ] );

		// Notas.
		$r( '/gestion/notas/opciones', 'GET', [ $this, 'opciones_notas' ] );
		$r( '/gestion/notas', 'GET', [ $this, 'notas_del_curso' ], [
			'curso_id' => [ 'required' => true, 'type' => 'integer' ],
		] );
		$r( '/gestion/notas', 'POST', [ $this, 'cargar_nota' ], [
			'alumno_id'     => [ 'required' => true, 'type' => 'integer' ],
			'curso_id'      => [ 'required' => true, 'type' => 'integer' ],
			'materia_id'    => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
			'materia_nueva' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
			'periodo'       => [ 'required' => true, 'type' => 'string' ],
			'nota'          => [ 'required' => true, 'type' => 'number' ],
			'comentario'    => [ 'required' => false, 'type' => 'string', 'default' => '' ],
		] );

		// Invitaciones.
		$r( '/gestion/invitaciones', 'GET', [ $this, 'invitaciones' ] );
		$r( '/gestion/invitaciones', 'POST', [ $this, 'invitar' ], [
			'rol'      => [ 'required' => true, 'type' => 'string' ],
			'usos'     => [ 'required' => false, 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 1000 ],
			'curso_id' => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
			'email'    => [ 'required' => false, 'type' => 'string', 'default' => '' ],
		] );
		$r( "/gestion/invitaciones/{$id}/revocar", 'POST', [ $this, 'revocar_invitacion' ] );

		// Notas del sitio (artículos).
		$r( '/gestion/articulos/opciones', 'GET', [ $this, 'opciones_articulos' ] );
		$r( '/gestion/articulos', 'POST', [ $this, 'publicar_articulo' ], [
			'titulo'       => [ 'required' => true, 'type' => 'string' ],
			'contenido'    => [ 'required' => true, 'type' => 'string' ],
			'categoria'    => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
			'formato'      => [ 'required' => false, 'type' => 'string', 'default' => '' ],
			'fecha_evento' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
			'lugar_evento' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
			'redes'        => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
		] );

		// Tareas del curso (las del delegado/a).
		$r( '/delegado/tareas', 'GET', [ $this, 'tareas_del_curso' ] );
		$r( "/delegado/tareas/{$id}/estado", 'POST', [ $this, 'estado_tarea' ], [
			'estado' => [ 'required' => true, 'type' => 'string', 'enum' => Cead_Acad_Tasks_CPT::STATUSES ],
		] );
		$r( '/gestion/tareas', 'POST', [ $this, 'asignar_tarea' ], [
			'titulo'    => [ 'required' => true, 'type' => 'string' ],
			'detalle'   => [ 'required' => false, 'type' => 'string', 'default' => '' ],
			'curso_id'  => [ 'required' => true, 'type' => 'integer' ],
			'prioridad' => [ 'required' => false, 'type' => 'string', 'enum' => Cead_Acad_Tasks_CPT::PRIORITIES, 'default' => 'normal' ],
			'vence'     => [ 'required' => false, 'type' => 'string', 'default' => '' ],
		] );

		$r( '/delegados', 'GET', [ $this, 'delegados' ] );

		// La otra punta del buzón: el alumnado.
		$r( '/reportes/categorias', 'GET', [ $this, 'categorias_reporte' ] );
		$r( '/reportes', 'POST', [ $this, 'reportar' ], [
			'tipo'      => [ 'required' => true, 'type' => 'string', 'enum' => [ 'anonimo', 'confidencial' ] ],
			'categoria' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
			'texto'     => [ 'required' => true, 'type' => 'string' ],
		] );
		// Solo con forma de código, para que no se confunda con /categorias.
		$r( '/reportes/(?P<codigo>[Rr][Pp][Tt]-[0-9A-Fa-f]{6})', 'GET', [ $this, 'estado_reporte' ] );
		$r( '/mis-mensajes', 'GET', [ $this, 'mis_mensajes' ] );
	}

	/* ---------------------------------------------------------- audiencias */

	public function audiencias( $req ) {
		$uid  = get_current_user_id();
		$para = 'evento' === $req->get_param( 'para' ) ? 'evento' : 'comunicado';
		$cap  = 'evento' === $para ? 'cead_acad_manage_schedule' : 'cead_acad_publish_broadcast';
		if ( ! user_can( $uid, $cap ) ) {
			return self::sin_permiso();
		}
		return rest_ensure_response( Cead_Acad_Gestion_Audiencias::opciones( $uid, $para ) );
	}

	/** Lo que llegó en `audiencias`, como lista, venga como venga. */
	public static function lista_audiencias( $bruto ) {
		if ( is_string( $bruto ) ) {
			$bruto = json_decode( $bruto, true );
		}
		return is_array( $bruto ) ? $bruto : null;
	}

	/* --------------------------------------------------------- comunicados */

	public function publicar_comunicado( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			$uid        = get_current_user_id();
			$audiencias = Cead_Acad_Gestion_Audiencias::para_comunicado( $uid, self::lista_audiencias( $req->get_param( 'audiencias' ) ) );
			if ( is_wp_error( $audiencias ) ) {
				return $audiencias;
			}
			if ( '' === trim( (string) $req->get_param( 'texto' ) ) ) {
				return new WP_Error( 'sin_texto', __( 'El comunicado no tiene texto.', 'cead-acad' ), [ 'status' => 400 ] );
			}

			// La imagen, si vino, se sube antes: si falla, no sale un
			// comunicado sin la imagen que se eligió.
			$imagen = 0;
			if ( ! empty( $_FILES['imagen']['name'] ) ) {
				$imagen = self::subir_imagen( 'imagen' );
				if ( is_wp_error( $imagen ) ) {
					return $imagen;
				}
			}

			$pid = Cead_Acad_Broadcasts_CPT::crear( [
				'titulo'       => (string) $req->get_param( 'titulo' ),
				'texto'        => (string) $req->get_param( 'texto' ),
				'audiencias'   => $audiencias,
				'imagen'       => $imagen,
				'categoria'    => (string) $req->get_param( 'categoria' ),
				'avisar_email' => (bool) $req->get_param( 'avisar_email' ),
				'autor'        => $uid,
			] );
			if ( is_wp_error( $pid ) ) {
				if ( $imagen ) {
					wp_delete_attachment( $imagen, true );
				}
				return new WP_Error( $pid->get_error_code(), $pid->get_error_message(), [ 'status' => 400 ] );
			}
			return [ 'comunicado' => Cead_Acad_API_Panel::comunicado_breve( get_post( $pid ), [] ) ];
		} );
	}

	/**
	 * Sube una imagen del multipart. Solo imágenes: el comunicado la muestra
	 * como portada, y un PDF ahí no se ve.
	 *
	 * @return int|WP_Error El id del adjunto.
	 */
	protected static function subir_imagen( $clave ) {
		if ( ! empty( $_FILES[ $clave ]['error'] ) ) {
			return new WP_Error( 'subida', __( 'No se pudo subir la imagen.', 'cead-acad' ), [ 'status' => 400 ] );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_handle_upload( $clave, 0, [], [ 'test_form' => false ] );
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'subida', __( 'No se pudo subir la imagen.', 'cead-acad' ), [ 'status' => 400 ] );
		}
		// Lo que dice el teléfono del tipo de archivo no alcanza: se mira lo
		// que WordPress reconoció al guardarlo.
		if ( ! wp_attachment_is_image( $id ) ) {
			wp_delete_attachment( $id, true );
			return new WP_Error( 'tipo', __( 'La imagen tiene que ser una foto (JPG, PNG o WebP).', 'cead-acad' ), [ 'status' => 400 ] );
		}
		return (int) $id;
	}

	/* ------------------------------------------------------------- eventos */

	public function cargar_evento( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			$uid        = get_current_user_id();
			$audiencias = Cead_Acad_Gestion_Audiencias::para_evento( $uid, self::lista_audiencias( $req->get_param( 'audiencias' ) ) );
			if ( is_wp_error( $audiencias ) ) {
				return $audiencias;
			}
			$pid = Cead_Acad_Schedule_CPT::crear( [
				'titulo'      => (string) $req->get_param( 'titulo' ),
				'detalle'     => (string) $req->get_param( 'detalle' ),
				'inicio'      => (string) $req->get_param( 'inicio' ),
				'fin'         => (string) $req->get_param( 'fin' ),
				'todo_el_dia' => (bool) $req->get_param( 'todo_el_dia' ),
				'lugar'       => (string) $req->get_param( 'lugar' ),
				'tipo'        => (string) ( $req->get_param( 'tipo' ) ?: 'evento' ),
				'audiencias'  => $audiencias,
				'autor'       => $uid,
			] );
			if ( is_wp_error( $pid ) ) {
				return new WP_Error( $pid->get_error_code(), $pid->get_error_message(), [ 'status' => 400 ] );
			}
			return [
				'evento' => [
					'id'     => (int) $pid,
					'titulo' => get_the_title( $pid ),
					'inicio' => (string) get_post_meta( $pid, '_cead_acad_event_start', true ),
					'fin'    => (string) get_post_meta( $pid, '_cead_acad_event_end', true ) ?: null,
				],
			];
		} );
	}

	/* --------------------------------------------------------------- buzón */

	public function buzon() {
		$r = ( new Cead_Acad_Buzon() )->listar( get_current_user_id() );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public function actuar_buzon( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			$buzon = new Cead_Acad_Buzon();
			$tipo  = (string) $req->get_param( 'tipo' );
			$id    = (int) $req->get_param( 'id' );
			$r     = $buzon->actuar(
				get_current_user_id(),
				$tipo,
				$id,
				(string) $req->get_param( 'accion' ),
				(string) $req->get_param( 'respuesta' )
			);
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			// Cómo quedó, para que la app lo muestre sin pedir la lista entera.
			// Si se borró para siempre, ya no hay nada que mostrar.
			$store = new Cead_Acad_WA_Store();
			$fila  = 'reporte' === $tipo ? $store->get_report( $id ) : $store->get_suggestion( $id );
			if ( ! $fila ) {
				return [ 'ok' => true, 'item' => null ];
			}
			return [
				'ok'   => true,
				'item' => 'reporte' === $tipo ? $buzon->reporte( $fila ) : $buzon->sugerencia( $fila ),
			];
		} );
	}

	/* ------------------------------------------------------------ métricas */

	public function metricas() {
		if ( ! current_user_can( 'cead_acad_view_metrics' ) ) {
			return self::sin_permiso();
		}
		return rest_ensure_response( Cead_Acad_Metricas::datos() );
	}

	/* --------------------------------------------------------------- notas */

	public function opciones_notas() {
		$r = Cead_Acad_Notas::opciones( get_current_user_id() );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public function notas_del_curso( $req ) {
		$r = Cead_Acad_Notas::del_curso( get_current_user_id(), (int) $req->get_param( 'curso_id' ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public function cargar_nota( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			return Cead_Acad_Notas::cargar( get_current_user_id(), [
				'alumno_id'     => (int) $req->get_param( 'alumno_id' ),
				'curso_id'      => (int) $req->get_param( 'curso_id' ),
				'materia_id'    => (int) $req->get_param( 'materia_id' ),
				'materia_nueva' => (string) $req->get_param( 'materia_nueva' ),
				'periodo'       => (string) $req->get_param( 'periodo' ),
				'nota'          => $req->get_param( 'nota' ),
				'comentario'    => (string) $req->get_param( 'comentario' ),
				'origen'        => 'app',
			] );
		} );
	}

	/* -------------------------------------------------------- invitaciones */

	public function invitaciones() {
		if ( ! current_user_can( 'cead_acad_manage_invitations' ) ) {
			return self::sin_permiso();
		}
		return rest_ensure_response( [
			'roles'        => self::roles_invitables( get_current_user_id() ),
			'invitaciones' => array_map( [ __CLASS__, 'invitacion' ], (array) Cead_Acad_Invitations::list_recent( 50 ) ),
		] );
	}

	/** Los roles para los que esta persona puede generar una invitación. */
	public static function roles_invitables( $user_id ) {
		$out = [];
		foreach ( Cead_Acad_Capabilities::roles() as $slug => $cfg ) {
			if ( Cead_Acad_Invitations::puede_asignar( $user_id, $slug ) ) {
				$out[] = [ 'valor' => $slug, 'nombre' => (string) $cfg['display'] ];
			}
		}
		return $out;
	}

	public static function invitacion( array $row ) {
		$roles  = Cead_Acad_Capabilities::roles();
		$estado = Cead_Acad_Invitations::status( $row );
		$token  = Cead_Acad_Invitations::plain_token( $row );
		$curso  = (int) ( $row['course_id'] ?? 0 );
		return [
			'id'        => (int) $row['id'],
			'rol'       => (string) $row['role'],
			'rol_label' => (string) ( $roles[ $row['role'] ]['display'] ?? $row['role'] ),
			'curso'     => $curso ? [ 'id' => $curso, 'titulo' => get_the_title( $curso ) ] : null,
			'email'     => (string) ( $row['email'] ?? '' ) ?: null,
			'estado'    => $estado,
			'usos'      => max( 1, (int) ( $row['max_uses'] ?? 1 ) ),
			'restantes' => Cead_Acad_Invitations::uses_left( $row ),
			'vence'     => gmdate( 'c', strtotime( $row['expires_at'] . ' UTC' ) ),
			'creada'    => gmdate( 'c', strtotime( $row['created_at'] . ' UTC' ) ),
			// El link solo mientras sirve: uno vencido o revocado no se comparte.
			'link'      => ( 'valid' === $estado && '' !== $token ) ? Cead_Acad_Invitations::registration_url( $token ) : null,
		];
	}

	public function invitar( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			$uid = get_current_user_id();
			$rol = (string) $req->get_param( 'rol' );
			if ( ! current_user_can( 'cead_acad_manage_invitations' ) ) {
				return self::sin_permiso();
			}
			if ( ! isset( Cead_Acad_Capabilities::roles()[ $rol ] ) ) {
				return new WP_Error( 'rol_invalido', __( 'Ese rol no existe.', 'cead-acad' ), [ 'status' => 400 ] );
			}
			if ( ! Cead_Acad_Invitations::puede_asignar( $uid, $rol ) ) {
				return new WP_Error( 'cead_api_sin_permiso', __( 'No podés invitar con el rol Dirección.', 'cead-acad' ), [ 'status' => 403 ] );
			}
			$email = sanitize_email( (string) $req->get_param( 'email' ) );
			$curso = (int) $req->get_param( 'curso_id' );

			$tokens = Cead_Acad_Invitations::create( [
				'role'      => $rol,
				'max_uses'  => (int) $req->get_param( 'usos' ),
				'course_id' => $curso ?: null,
				'email'     => $email ?: null,
			] );
			if ( ! $tokens ) {
				return new WP_Error( 'cead_api_error', __( 'No se pudo crear la invitación.', 'cead-acad' ), [ 'status' => 500 ] );
			}
			$fila = Cead_Acad_Invitations::find_by_token( $tokens[0] );
			return [ 'invitacion' => $fila ? self::invitacion( $fila ) : [ 'link' => Cead_Acad_Invitations::registration_url( $tokens[0] ) ] ];
		} );
	}

	public function revocar_invitacion( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			if ( ! current_user_can( 'cead_acad_manage_invitations' ) ) {
				return self::sin_permiso();
			}
			$fila = Cead_Acad_Invitations::find_by_id( (int) $req->get_param( 'id' ) );
			if ( ! $fila ) {
				return new WP_Error( 'no_existe', __( 'Esa invitación no existe.', 'cead-acad' ), [ 'status' => 404 ] );
			}
			// Revocar una de Dirección es tan delicado como crearla.
			if ( ! Cead_Acad_Invitations::puede_asignar( get_current_user_id(), (string) $fila['role'] ) ) {
				return self::sin_permiso();
			}
			Cead_Acad_Invitations::revoke( (int) $fila['id'] );
			return [ 'invitacion' => self::invitacion( Cead_Acad_Invitations::find_by_id( (int) $fila['id'] ) ?: $fila ) ];
		} );
	}

	/* ----------------------------------------------------------- artículos */

	public function opciones_articulos() {
		if ( ! current_user_can( 'cead_acad_manage_articles' ) ) {
			return self::sin_permiso();
		}
		return rest_ensure_response( Cead_Acad_Articulos::opciones( get_current_user_id() ) );
	}

	public function publicar_articulo( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			$uid = get_current_user_id();
			if ( ! current_user_can( 'cead_acad_manage_articles' ) ) {
				return self::sin_permiso();
			}
			// Pedir redes sin ser el director/a no es un error: se publica
			// igual, solo en el sitio, y la respuesta lo dice.
			$redes = (bool) $req->get_param( 'redes' ) && Cead_Acad_Articulos::puede_redes( $uid );

			$imagen = 0;
			if ( ! empty( $_FILES['imagen']['name'] ) ) {
				$imagen = self::subir_imagen( 'imagen' );
				if ( is_wp_error( $imagen ) ) {
					return $imagen;
				}
			}
			$pid = Cead_Acad_Articulos::publicar( $uid, [
				'titulo'       => (string) $req->get_param( 'titulo' ),
				'contenido'    => (string) $req->get_param( 'contenido' ),
				'imagen'       => $imagen,
				'categoria'    => (int) $req->get_param( 'categoria' ),
				'formato'      => (string) $req->get_param( 'formato' ),
				'fecha_evento' => Cead_Acad_Articulos::fecha_mysql( $req->get_param( 'fecha_evento' ) ),
				'lugar_evento' => (string) $req->get_param( 'lugar_evento' ),
				'redes'        => $redes,
				'via'          => 'app',
			] );
			if ( is_wp_error( $pid ) ) {
				if ( $imagen ) {
					wp_delete_attachment( $imagen, true );
				}
				return $pid;
			}
			return [
				'articulo' => [
					'id'     => (int) $pid,
					'titulo' => get_the_title( $pid ),
					'url'    => get_permalink( $pid ),
					'redes'  => $redes,
				],
			];
		} );
	}

	/* --------------------------------------------------- tareas del curso */

	public function tareas_del_curso() {
		$uid = get_current_user_id();
		if ( ! current_user_can( 'cead_acad_complete_delegate_task' ) && ! current_user_can( 'cead_acad_assign_tasks' ) ) {
			return self::sin_permiso();
		}
		$tareas = Cead_Acad_Tasks_CPT::for_user( $uid, [ 'pendiente', 'en_curso', 'hecha' ] );
		return rest_ensure_response( [ 'tareas' => array_map( [ 'Cead_Acad_Tasks_CPT', 'ficha' ], $tareas ) ] );
	}

	public function estado_tarea( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			$id = (int) $req->get_param( 'id' );
			$r  = Cead_Acad_Tasks_CPT::fijar_estado( get_current_user_id(), $id, (string) $req->get_param( 'estado' ) );
			return is_wp_error( $r ) ? $r : [ 'tarea' => Cead_Acad_Tasks_CPT::ficha( get_post( $id ) ) ];
		} );
	}

	public function asignar_tarea( $req ) {
		return Cead_Acad_API::una_vez( $req, static function () use ( $req ) {
			$pid = Cead_Acad_Tasks_CPT::crear( [
				'titulo'    => (string) $req->get_param( 'titulo' ),
				'detalle'   => (string) $req->get_param( 'detalle' ),
				'curso_id'  => (int) $req->get_param( 'curso_id' ),
				'prioridad' => (string) $req->get_param( 'prioridad' ),
				'vence'     => (string) $req->get_param( 'vence' ),
				'autor'     => get_current_user_id(),
			] );
			return is_wp_error( $pid ) ? $pid : [ 'tarea' => Cead_Acad_Tasks_CPT::ficha( get_post( $pid ) ) ];
		} );
	}

	/* ----------------------------------------------------------- delegados */

	/**
	 * El directorio de delegados, con sus teléfonos.
	 *
	 * Solo en línea, nunca en la sincronización: son teléfonos de terceros, y
	 * cada vez que alguien los mira queda registrado, igual que en la web.
	 */
	public function delegados() {
		if ( ! current_user_can( 'cead_acad_view_delegates' ) ) {
			return self::sin_permiso();
		}
		$lista = Cead_Acad_Courses_Roster::delegates();
		Cead_Acad_Audit::log( 'delegates_viewed', [
			'entity_type' => 'user',
			'entity_id'   => get_current_user_id(),
			'payload'     => [ 'fichas' => count( $lista ), 'via' => 'app' ],
		] );
		return rest_ensure_response( [ 'delegados' => array_values( $lista ) ] );
	}

	/* ---------------------------------------------- reportes del alumnado */

	public function categorias_reporte() {
		return rest_ensure_response( [ 'categorias' => Cead_Acad_Buzon::categorias() ] );
	}

	public function reportar( $req ) {
		$hacer = static function () use ( $req ) {
			$codigo = ( new Cead_Acad_Buzon() )->crear_reporte(
				get_current_user_id(),
				(string) $req->get_param( 'tipo' ),
				(string) $req->get_param( 'categoria' ),
				(string) $req->get_param( 'texto' )
			);
			return is_wp_error( $codigo ) ? $codigo : [ 'codigo' => $codigo ];
		};

		if ( 'anonimo' !== $req->get_param( 'tipo' ) ) {
			return Cead_Acad_API::una_vez( $req, $hacer );
		}

		/*
		 * Un reporte anónimo no pasa por `una_vez()`: ahí la respuesta se
		 * guarda con una clave armada con el id de quien la pidió, y la
		 * respuesta es el código del reporte. Quedaría escrito, una semana,
		 * quién mandó qué reporte anónimo. Acá la clave es solo la que generó
		 * el teléfono, que nadie más conoce.
		 */
		$clave = Cead_Acad_API::clave_idempotencia( $req->get_header( 'idempotency_key' ) );
		if ( false === $clave ) {
			return new WP_Error( 'cead_api_clave_invalida', __( 'Idempotency-Key con formato inválido.', 'cead-acad' ), [ 'status' => 400 ] );
		}
		$tk = null !== $clave ? 'cead_api_idem_anon_' . hash( 'sha256', $clave ) : null;
		if ( $tk ) {
			$guardada = get_transient( $tk );
			if ( is_array( $guardada ) && isset( $guardada['codigo'] ) ) {
				$resp = rest_ensure_response( [ 'codigo' => (string) $guardada['codigo'] ] );
				$resp->header( 'Idempotent-Replay', 'true' );
				return $resp;
			}
		}
		$r = $hacer();
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( $tk ) {
			set_transient( $tk, [ 'codigo' => $r['codigo'] ], Cead_Acad_API::IDEM_VIDA_SEG );
		}
		return rest_ensure_response( $r );
	}

	public function estado_reporte( $req ) {
		$r = ( new Cead_Acad_Buzon() )->estado_reporte( get_current_user_id(), (string) $req->get_param( 'codigo' ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( null === $r ) {
			return new WP_Error( 'no_existe', __( 'No hay ningún reporte con ese código.', 'cead-acad' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( $r );
	}

	public function mis_mensajes() {
		return rest_ensure_response( ( new Cead_Acad_Buzon() )->mios( get_current_user_id() ) );
	}

	/* -------------------------------------------------------------- ayudas */

	protected static function sin_permiso() {
		return new WP_Error( 'cead_api_sin_permiso', __( 'No tenés permiso para esto.', 'cead-acad' ), [ 'status' => 403 ] );
	}
}
