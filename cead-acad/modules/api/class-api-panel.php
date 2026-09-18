<?php
/**
 * Las pantallas del panel, en crudo, para las apps.
 *
 * Todo lo de acá es de lectura y ninguna consulta se escribe de nuevo: cada
 * endpoint le pregunta al mismo feed que usa la plantilla web. Es una decisión,
 * no una comodidad — si la app tuviera su propia idea de «qué comunicados ve
 * esta persona» o «cuál es su curso», las dos respuestas se irían separando y
 * el día que difieran las dos van a parecer correctas.
 *
 * Los permisos también son los de siempre. Un endpoint no puede ser la forma
 * barata de ver algo que en el panel está cerrado, así que el boletín pide la
 * misma capacidad que la plantilla y un comunicado suelto se verifica contra la
 * audiencia antes de devolver una sola letra.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_API_Panel {

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'rutas' ] );
	}

	public function rutas() {
		$ns   = Cead_Acad_API::NS;
		$gate = Cead_Acad_API::gate();

		register_rest_route( $ns, '/horario', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'horario' ],
			'permission_callback' => $gate,
			'args'                => [
				'curso' => [ 'required' => false, 'type' => 'integer' ],
			],
		] );

		register_rest_route( $ns, '/comunicados', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'comunicados' ],
			'permission_callback' => $gate,
			'args'                => [
				'pagina'   => [ 'required' => false, 'type' => 'integer' ],
				'por_pag'  => [ 'required' => false, 'type' => 'integer' ],
			],
		] );

		register_rest_route( $ns, '/comunicados/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'comunicado' ],
			'permission_callback' => $gate,
		] );

		register_rest_route( $ns, '/boletin', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'boletin' ],
			'permission_callback' => $gate,
		] );

		register_rest_route( $ns, '/tareas', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'tareas' ],
			'permission_callback' => $gate,
		] );

		register_rest_route( $ns, '/calendario', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'calendario' ],
			'permission_callback' => $gate,
			'args'                => [
				'desde' => [ 'required' => false, 'type' => 'string' ],
				'hasta' => [ 'required' => false, 'type' => 'string' ],
			],
		] );
	}

	/* ------------------------------------------------------------- horario */

	public function horario( $req ) {
		$user_id = get_current_user_id();
		$pedido  = (int) $req->get_param( 'curso' );

		/*
		 * Mirar el horario de OTRO curso es una consulta aparte y con permiso
		 * propio (delegados y arriba). Sin el permiso, el parámetro no es un
		 * error: simplemente se ignora y cada uno ve el suyo.
		 */
		$curso = ( $pedido && current_user_can( 'cead_acad_view_other_schedules' ) )
			? $pedido
			: cead_acad_curso_actual( $user_id );

		if ( ! $curso ) {
			return rest_ensure_response( [
				'curso'  => null,
				'dias'   => [],
				'motivo' => 'sin_curso',
			] );
		}

		return rest_ensure_response( [
			'curso' => [ 'id' => $curso, 'titulo' => get_the_title( $curso ) ],
			'dias'  => self::franjas_por_dia( $curso ),
		] );
	}

	/**
	 * El horario semanal de un curso, agrupado por día y ordenado por hora.
	 *
	 * El dato crudo es una lista plana en un meta del curso. Agruparlo acá y no
	 * en la app es lo mismo que hace la plantilla web: es la forma en que se
	 * mira un horario, no una decisión de cada cliente.
	 */
	public static function franjas_por_dia( $curso_id ) {
		$raw = get_post_meta( (int) $curso_id, '_cead_acad_horario', true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$por_dia = [];
		foreach ( $raw as $f ) {
			$dia = (int) ( $f['dia'] ?? 0 );
			if ( $dia < 1 || $dia > 7 ) {
				continue;
			}
			$por_dia[ $dia ][] = [
				'inicio'  => (string) ( $f['inicio'] ?? '' ),
				'fin'     => (string) ( $f['fin'] ?? '' ),
				'materia' => (string) ( $f['materia'] ?? '' ),
				'docente' => (string) ( $f['docente'] ?? '' ),
				'aula'    => (string) ( $f['aula'] ?? '' ),
			];
		}
		ksort( $por_dia );

		$out = [];
		foreach ( $por_dia as $dia => $franjas ) {
			usort( $franjas, static function ( $a, $b ) {
				return strcmp( $a['inicio'], $b['inicio'] );
			} );
			$out[] = [ 'dia' => $dia, 'franjas' => $franjas ];
		}
		return $out;
	}

	/* --------------------------------------------------------- comunicados */

	public function comunicados( $req ) {
		$user_id = get_current_user_id();
		$pagina  = max( 1, (int) ( $req->get_param( 'pagina' ) ?: 1 ) );
		$por_pag = min( 50, max( 1, (int) ( $req->get_param( 'por_pag' ) ?: 20 ) ) );

		$posts  = Cead_Acad_Broadcasts_Feed::for_user( $user_id, [
			'per_page' => $por_pag,
			'paged'    => $pagina,
		] );
		$leidos = array_map( 'intval', (array) Cead_Acad_Broadcasts_Reads::read_ids_for_user( $user_id ) );

		$items = [];
		foreach ( $posts as $p ) {
			$items[] = self::comunicado_breve( $p, $leidos );
		}

		return rest_ensure_response( [
			'comunicados' => $items,
			'pagina'      => $pagina,
			'sin_leer'    => (int) Cead_Acad_Broadcasts_Reads::count_unread_for_user( $user_id ),
		] );
	}

	/**
	 * Un comunicado entero.
	 *
	 * Acá está el chequeo que importa de toda la clase: los ids son correlativos
	 * y adivinables, así que sin verificar la audiencia cualquiera con sesión
	 * podría leer el comunicado dirigido a otro curso probando números.
	 */
	public function comunicado( $req ) {
		$id      = (int) $req->get_param( 'id' );
		$user_id = get_current_user_id();

		if ( ! Cead_Acad_Broadcasts_Feed::user_can_view( $id, $user_id ) ) {
			return new WP_Error( 'cead_api_no_visible', __( 'Ese comunicado no es para vos.', 'cead-acad' ), [ 'status' => 403 ] );
		}

		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'cead_api_no_existe', __( 'Ese comunicado no existe.', 'cead-acad' ), [ 'status' => 404 ] );
		}

		Cead_Acad_Broadcasts_Reads::mark_read( $id, $user_id );

		$datos = self::comunicado_breve( $post, [ $id ] );
		$datos['contenido'] = apply_filters( 'the_content', $post->post_content );

		return rest_ensure_response( $datos );
	}

	protected static function comunicado_breve( $post, array $leidos ) {
		return [
			'id'        => (int) $post->ID,
			'titulo'    => get_the_title( $post ),
			'fecha'     => get_post_time( 'c', true, $post ),
			'resumen'   => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'imagen'    => get_the_post_thumbnail_url( $post, 'medium_large' ) ?: null,
			'categoria' => self::primera_categoria( $post ),
			'leido'     => in_array( (int) $post->ID, $leidos, true ),
		];
	}

	protected static function primera_categoria( $post ) {
		$terms = get_the_terms( $post, Cead_Acad_Broadcasts_CPT::TAX_CAT );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return null;
		}
		$t = reset( $terms );
		return [ 'slug' => $t->slug, 'nombre' => $t->name ];
	}

	/* ------------------------------------------------------------- boletín */

	public function boletin() {
		// La misma capacidad que pide la plantilla web. Un endpoint no puede ser
		// la puerta barata a algo que en el panel está cerrado.
		if ( ! current_user_can( 'cead_acad_view_own_grades' ) ) {
			return new WP_Error( 'cead_api_sin_permiso', __( 'No tenés permiso para ver boletines.', 'cead-acad' ), [ 'status' => 403 ] );
		}

		$boletin = Cead_Acad_Grades_Db::bulletin_for_student( get_current_user_id() );

		$materias = [];
		foreach ( (array) $boletin as $m ) {
			$notas = [];
			foreach ( (array) ( $m['grades'] ?? [] ) as $periodo => $n ) {
				$notas[ (string) $periodo ] = [
					'nota'         => isset( $n['score'] ) ? $n['score'] : null,
					'letra'        => (string) ( $n['letter'] ?? '' ),
					'comentarios'  => (string) ( $n['comments'] ?? '' ),
					'cargada'      => (string) ( $n['recorded_at'] ?? '' ),
				];
			}
			$materias[] = [
				'materia' => (string) ( $m['subject_name'] ?? '' ),
				'curso'   => (string) ( $m['course_title'] ?? '' ),
				'notas'   => (object) $notas,
			];
		}

		return rest_ensure_response( [
			'materias' => $materias,
			'periodos' => array_values( self::periodos( $boletin ) ),
		] );
	}

	/** Los períodos que de verdad aparecen, para que la app arme el encabezado. */
	protected static function periodos( $boletin ) {
		$vistos = [];
		foreach ( (array) $boletin as $m ) {
			foreach ( array_keys( (array) ( $m['grades'] ?? [] ) ) as $p ) {
				$vistos[ $p ] = $p;
			}
		}
		return $vistos;
	}

	/* -------------------------------------------------------------- tareas */

	public function tareas() {
		$user_id = get_current_user_id();

		$items = [];
		foreach ( Cead_Acad_Tasks_Frontend::tasks_for_user( $user_id ) as $t ) {
			$estado = (string) get_post_meta( $t->ID, '_cead_acad_task_status', true );
			// Las canceladas no son una tarea con estado raro: dejaron de ser
			// tarea. La plantilla web también las saca.
			if ( 'cancelada' === $estado ) {
				continue;
			}
			$items[] = [
				'id'        => (int) $t->ID,
				'titulo'    => get_the_title( $t ),
				'detalle'   => wp_strip_all_tags( $t->post_content ),
				'vence'     => (string) get_post_meta( $t->ID, '_cead_acad_task_due_date', true ) ?: null,
				'prioridad' => (string) get_post_meta( $t->ID, '_cead_acad_task_priority', true ) ?: 'normal',
				'hecha'     => Cead_Acad_Tasks_Frontend::is_done( $user_id, $t->ID ),
			];
		}

		return rest_ensure_response( [ 'tareas' => $items ] );
	}

	/* ---------------------------------------------------------- calendario */

	public function calendario( $req ) {
		$desde = self::fecha( $req->get_param( 'desde' ) );
		$hasta = self::fecha( $req->get_param( 'hasta' ) );

		$eventos = Cead_Acad_Schedule_Feed::for_user( get_current_user_id(), $desde, $hasta );

		$items = [];
		foreach ( (array) $eventos as $e ) {
			$items[] = [
				'id'      => (int) $e->ID,
				'titulo'  => get_the_title( $e ),
				'detalle' => wp_strip_all_tags( $e->post_content ),
				'inicio'  => (string) get_post_meta( $e->ID, '_cead_acad_event_start', true ),
				'fin'     => (string) get_post_meta( $e->ID, '_cead_acad_event_end', true ),
				'lugar'   => (string) get_post_meta( $e->ID, '_cead_acad_event_location', true ),
			];
		}

		return rest_ensure_response( [ 'eventos' => $items ] );
	}

	/**
	 * Una fecha `Y-m-d` del cliente, o null.
	 *
	 * Null no es un descarte: es lo que el feed espera para «usá tu rango por
	 * defecto». Una fecha con cualquier otra forma se trata igual que no haber
	 * mandado nada, porque inventar un rango a partir de basura devolvería una
	 * lista que parece una respuesta.
	 */
	protected static function fecha( $valor ) {
		$valor = trim( (string) $valor );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valor ) ) {
			return null;
		}
		return $valor;
	}
}
