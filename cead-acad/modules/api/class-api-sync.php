<?php
/**
 * Todo lo de una persona en un solo pedido, para guardarlo en el teléfono.
 *
 * En el colegio no hay wifi. La app sincroniza cuando tiene señal —en casa, en
 * la parada— y en el aula consulta lo que guardó. Por eso este endpoint no se
 * piensa como «una pantalla»: es la foto completa de lo que la persona puede
 * necesitar mirar sin conexión.
 *
 * Nada se calcula de nuevo acá. Cada parte sale del mismo endpoint que la app
 * usa en línea, así que lo sincronizado y lo que se ve con señal no pueden
 * contar historias distintas.
 *
 * Los datos móviles cuestan plata, y la mayoría de las veces que la app
 * sincroniza no cambió nada. Así que la respuesta lleva una versión (ETag): si
 * la app manda la que ya tiene y es la misma, el servidor contesta 304 sin
 * cuerpo. Una sincronización en la que no pasó nada cuesta unos pocos bytes.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_API_Sync {

	/** Hacia atrás, para que un evento de la semana pasada se pueda consultar. */
	const CAL_ATRAS_DIAS = 30;
	/** Hacia adelante: el resto del año lectivo, más o menos. */
	const CAL_ADELANTE_DIAS = 180;
	/** Los comunicados más viejos que esto se piden en línea. */
	const COMUNICADOS_MAX = 100;

	/** @var Cead_Acad_API_Panel */
	protected $panel;
	/** @var Cead_Acad_API_Alumno */
	protected $alumno;

	public function __construct( $panel = null, $alumno = null ) {
		$this->panel  = $panel ?: new Cead_Acad_API_Panel();
		$this->alumno = $alumno ?: new Cead_Acad_API_Alumno();
	}

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'rutas' ] );
	}

	public function rutas() {
		register_rest_route( Cead_Acad_API::NS, '/sincronizar', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'sincronizar' ],
			'permission_callback' => Cead_Acad_API::gate(),
		] );
	}

	public function sincronizar( $req ) {
		$datos   = $this->armar( get_current_user_id() );
		$version = self::version_de( $datos );

		if ( self::coincide_etag( (string) $req->get_header( 'if_none_match' ), $version ) ) {
			$r = new WP_REST_Response( null, 304 );
			$r->header( 'ETag', '"' . $version . '"' );
			return $r;
		}

		$datos['version']  = $version;
		$datos['generado'] = gmdate( 'c' );

		$r = rest_ensure_response( $datos );
		$r->header( 'ETag', '"' . $version . '"' );
		// Son datos personales: ningún proxy en el camino los guarda.
		$r->header( 'Cache-Control', 'private, no-store' );
		return $r;
	}

	/**
	 * La foto completa.
	 *
	 * Cada sección se arma por separado y una que falla no tumba a las demás:
	 * si el boletín no está permitido para este rol, la app recibe el resto y
	 * un null ahí, en vez de quedarse sin horario por culpa de las notas.
	 */
	public function armar( $uid ) {
		$hoy   = current_time( 'timestamp' );
		$desde = gmdate( 'Y-m-d', $hoy - self::CAL_ATRAS_DIAS * DAY_IN_SECONDS );
		$hasta = gmdate( 'Y-m-d', $hoy + self::CAL_ADELANTE_DIAS * DAY_IN_SECONDS );

		return [
			'perfil'      => self::datos( ( new Cead_Acad_API() )->me() ),
			'mis_datos'   => Cead_Acad_API_Alumno::datos_perfil( $uid ),
			'horario'     => self::datos( $this->panel->horario( self::pedido() ) ),
			'comunicados' => $this->comunicados( $uid ),
			'boletin'     => self::datos( $this->panel->boletin() ),
			'tareas'      => self::datos( $this->panel->tareas() ),
			'calendario'  => self::datos( $this->panel->calendario( self::pedido( [ 'desde' => $desde, 'hasta' => $hasta ] ) ) ),
			'recursos'    => self::datos( $this->alumno->recursos( self::pedido( [ 'por_pag' => 200 ] ) ) ),
			'encuestas'   => $this->encuestas( $uid ),
			'faq'         => Cead_Acad_API_Alumno::lista_faq(),
			// Las respuestas a lo que mandó, para leerlas sin señal. El buzón
			// de coordinación NO va acá: son reportes con nombres y relatos que
			// no tienen por qué quedar guardados en un teléfono.
			'mis_mensajes' => ( new Cead_Acad_Buzon() )->mios( $uid ),
			// Las categorías, para poder escribir un reporte sin señal y que
			// salga cuando vuelva.
			'categorias_reporte' => Cead_Acad_Buzon::categorias(),
			'gestion'            => $this->gestion( $uid ),
		];
	}

	/**
	 * Lo que el staff necesita para trabajar sin señal: con qué opciones armar
	 * un comunicado, un evento, una nota o una tarjeta de notas, para cargarlos
	 * en el aula y que salgan cuando vuelva la conexión.
	 *
	 * Solo opciones, nunca datos de terceros: el buzón y el directorio de
	 * delegados se miran en línea. Cada parte aparece si la persona tiene el
	 * permiso; para un alumno, esto queda vacío.
	 */
	protected function gestion( $uid ) {
		$g = [];
		if ( user_can( $uid, 'cead_acad_publish_broadcast' ) ) {
			$g['audiencias_comunicado'] = Cead_Acad_Gestion_Audiencias::opciones( $uid, 'comunicado' );
		}
		if ( user_can( $uid, 'cead_acad_manage_schedule' ) ) {
			$g['audiencias_evento'] = Cead_Acad_Gestion_Audiencias::opciones( $uid, 'evento' );
		}
		if ( user_can( $uid, 'cead_acad_record_grade' ) ) {
			$g['notas'] = Cead_Acad_Notas::opciones( $uid );
		}
		if ( user_can( $uid, 'cead_acad_manage_articles' ) ) {
			$g['articulos'] = Cead_Acad_Articulos::opciones( $uid );
		}
		if ( user_can( $uid, 'cead_acad_complete_delegate_task' ) || user_can( $uid, 'cead_acad_assign_tasks' ) ) {
			$g['tareas_del_curso'] = array_map(
				[ 'Cead_Acad_Tasks_CPT', 'ficha' ],
				Cead_Acad_Tasks_CPT::for_user( $uid, [ 'pendiente', 'en_curso', 'hecha' ] )
			);
		}
		// Un objeto vacío y no una lista vacía: la app lo lee como un mapa.
		return $g ?: new stdClass();
	}

	/**
	 * Los comunicados CON el texto completo.
	 *
	 * La lista en línea trae solo el resumen, porque el texto se pide al
	 * abrirlo. Sin señal no hay «al abrirlo»: o el texto ya está en el
	 * teléfono, o el comunicado no se puede leer en el aula, que es justo
	 * donde se necesita.
	 */
	protected function comunicados( $uid ) {
		$posts  = Cead_Acad_Broadcasts_Feed::for_user( $uid, [ 'per_page' => self::COMUNICADOS_MAX ] );
		$leidos = array_map( 'intval', (array) Cead_Acad_Broadcasts_Reads::read_ids_for_user( $uid ) );

		$lista = [];
		foreach ( $posts as $p ) {
			$c              = Cead_Acad_API_Panel::comunicado_breve( $p, $leidos );
			$c['contenido'] = apply_filters( 'the_content', $p->post_content );
			$lista[]        = $c;
		}
		return [
			'comunicados' => $lista,
			'sin_leer'    => (int) Cead_Acad_Broadcasts_Reads::count_unread_for_user( $uid ),
		];
	}

	/** Las encuestas abiertas vienen con sus preguntas, para responderlas sin señal. */
	protected function encuestas( $uid ) {
		$lista = self::datos( $this->alumno->encuestas() );
		foreach ( (array) ( $lista['encuestas'] ?? [] ) as $i => $e ) {
			if ( ! empty( $e['abierta'] ) ) {
				$lista['encuestas'][ $i ]['preguntas'] = Cead_Acad_API_Alumno::preguntas( (int) $e['id'] );
			}
		}
		return $lista;
	}

	/* ------------------------------------------------------------- puras */

	/**
	 * La versión de una foto: cambia si y solo si cambian los datos.
	 *
	 * Se calcula antes de agregar la hora de generación, que cambia siempre y
	 * haría que ninguna sincronización coincidiera nunca con la anterior.
	 */
	public static function version_de( array $datos ) {
		return substr( hash( 'sha256', (string) wp_json_encode( $datos ) ), 0, 32 );
	}

	/**
	 * ¿La versión que tiene la app es la actual?
	 *
	 * Acepta las formas en que llega un If-None-Match en la práctica: con o
	 * sin comillas, con el prefijo débil `W/` que agregan algunos proxies al
	 * comprimir, y como lista separada por comas.
	 */
	public static function coincide_etag( $header, $version ) {
		$header = trim( (string) $header );
		if ( '' === $header || '' === (string) $version ) {
			return false;
		}
		foreach ( explode( ',', $header ) as $parte ) {
			$parte = trim( $parte );
			if ( 0 === strpos( $parte, 'W/' ) ) {
				$parte = substr( $parte, 2 );
			}
			if ( trim( $parte, '"' ) === (string) $version ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------ interno */

	/** Los datos de una respuesta, o null si la sección no está permitida. */
	protected static function datos( $r ) {
		if ( is_wp_error( $r ) ) {
			return null;
		}
		return $r instanceof WP_REST_Response ? $r->get_data() : $r;
	}

	protected static function pedido( array $params = [] ) {
		$req = new WP_REST_Request( 'GET' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}
}
