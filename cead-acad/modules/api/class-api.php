<?php
/**
 * La API que consumen las apps nativas.
 *
 * El panel web es PHP renderizado en servidor: cada pantalla arma su HTML con
 * los datos ya adentro. Eso está bien para un navegador y no le sirve de nada a
 * una app, que necesita los mismos datos en crudo. Este módulo los expone bajo
 * `cead-acad/v1`, sin duplicar lógica: cada endpoint le pregunta a los mismos
 * feeds que usan las plantillas.
 *
 * Quién puede entrar es exactamente quién puede entrar al panel —gente con un
 * rol del plugin—, y se verifica con las mismas capacidades de siempre. La app
 * no es una puerta aparte con sus propios permisos: es otra ventana a lo mismo.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_API {

	const NS = 'cead-acad/v1';

	/** El error de autenticación del request actual, si hubo. */
	protected $error = null;

	/** El token en claro que vino en el request, para poder revocarlo. */
	protected $token = '';

	/** La instancia que arrancó, que es la que sabe cómo vino autenticado. */
	protected static $viva = null;

	/**
	 * El `permission_callback` de cualquier endpoint que pida sesión.
	 *
	 * Tiene que apuntar a la instancia ARRANCADA y no a una nueva: el resultado
	 * de la autenticación (token vencido, cuenta suspendida) vive en el objeto,
	 * y una instancia recién creada respondería «no hay sesión» donde hace falta
	 * decir «tu sesión venció, volvé a entrar».
	 */
	public static function gate() {
		return [ self::$viva ?: new self(), 'autenticado' ];
	}

	public function boot() {
		self::$viva = $this;
		add_action( 'rest_api_init', [ $this, 'rutas' ] );
		add_filter( 'determine_current_user', [ $this, 'usuario_por_token' ], 20 );
		add_filter( 'rest_authentication_errors', [ $this, 'error_de_autenticacion' ], 20 );
	}

	/* ---------------------------------------------------------------- rutas */

	public function rutas() {
		register_rest_route( self::NS, '/auth/login', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'login' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'usuario'     => [ 'required' => true, 'type' => 'string' ],
				'clave'       => [ 'required' => true, 'type' => 'string' ],
				'dispositivo' => [ 'required' => false, 'type' => 'string' ],
			],
		] );

		register_rest_route( self::NS, '/auth/logout', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'logout' ],
			'permission_callback' => [ $this, 'autenticado' ],
		] );

		register_rest_route( self::NS, '/auth/sesiones', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'sesiones' ],
			'permission_callback' => [ $this, 'autenticado' ],
		] );

		register_rest_route( self::NS, '/auth/sesiones/(?P<id>[a-f0-9]{12})', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'cerrar_sesion' ],
			'permission_callback' => [ $this, 'autenticado' ],
		] );

		register_rest_route( self::NS, '/me', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'me' ],
			'permission_callback' => [ $this, 'autenticado' ],
		] );
	}

	/* --------------------------------------------------------------- login */

	public function login( $req ) {
		if ( ! self::conexion_segura() ) {
			return new WP_Error( 'cead_api_sin_https', __( 'Se requiere HTTPS.', 'cead-acad' ), [ 'status' => 403 ] );
		}

		$usuario = sanitize_user( (string) $req->get_param( 'usuario' ), true );
		$clave   = (string) $req->get_param( 'clave' );

		/*
		 * El freno va después de leer el usuario porque cuenta por cuenta, no
		 * solo por IP: el colegio entero sale por una sola IP pública. Es el
		 * mismo freno que usa el formulario del panel, a propósito — si fueran
		 * dos, adivinar contraseñas por la app tendría su propio cupo.
		 */
		if ( ! cead_acad_login_permitido( $usuario ) ) {
			return new WP_Error( 'cead_api_demasiados_intentos', __( 'Demasiados intentos. Probá de nuevo en un rato.', 'cead-acad' ), [ 'status' => 429 ] );
		}

		if ( '' === $usuario || '' === $clave ) {
			return new WP_Error( 'cead_api_faltan_datos', __( 'Usuario y contraseña son obligatorios.', 'cead-acad' ), [ 'status' => 400 ] );
		}

		// `wp_authenticate` corre la misma cadena de filtros que el login del
		// panel —incluida la suspensión— pero no toca cookies: la app no las usa.
		$user = wp_authenticate( $usuario, $clave );

		if ( is_wp_error( $user ) ) {
			$suspendida = 'cead_acad_suspended' === $user->get_error_code();
			// Una cuenta suspendida no suma al contador: la contraseña puede
			// haber sido correcta, y castigar a quien la sabe no frena a nadie.
			if ( ! $suspendida ) {
				cead_acad_login_fallo( $usuario );
			}
			if ( $suspendida ) {
				return new WP_Error( 'cead_api_suspendida', $user->get_error_message(), [ 'status' => 403 ] );
			}
			return new WP_Error( 'cead_api_credenciales', __( 'Usuario o contraseña incorrectos.', 'cead-acad' ), [ 'status' => 401 ] );
		}

		if ( ! Cead_Acad_Capabilities::user_in_plugin( $user ) ) {
			return new WP_Error( 'cead_api_sin_panel', __( 'Esta cuenta no tiene acceso al panel.', 'cead-acad' ), [ 'status' => 403 ] );
		}

		cead_acad_login_ok( $usuario );

		$token = Cead_Acad_API_Tokens::emitir( $user, (string) $req->get_param( 'dispositivo' ) );

		/*
		 * El actor va explícito: en este punto todavía no hay sesión, así que
		 * `get_current_user_id()` devolvería 0 y el registro diría que nadie
		 * entró — justo en la línea que sirve para saber quién entró.
		 */
		Cead_Acad_Audit::log( 'api_login', [
			'user_id'     => $user->ID,
			'entity_type' => 'user',
			'entity_id'   => $user->ID,
		] );

		return rest_ensure_response( [
			'token'   => $token,
			'vence'   => time() + Cead_Acad_API_Tokens::VIDA_SEG,
			'usuario' => $this->perfil( $user ),
		] );
	}

	public function logout() {
		Cead_Acad_API_Tokens::revocar( $this->token );
		return rest_ensure_response( [ 'ok' => true ] );
	}

	/* ------------------------------------------------------------ sesiones */

	public function sesiones() {
		$actual = Cead_Acad_API_Tokens::partir( $this->token );

		$lista = array_map( static function ( $s ) use ( $actual ) {
			$s['actual'] = $actual && $actual[1] === $s['id'];
			return $s;
		}, Cead_Acad_API_Tokens::sesiones( get_current_user_id() ) );

		return rest_ensure_response( [ 'sesiones' => $lista ] );
	}

	public function cerrar_sesion( $req ) {
		$ok = Cead_Acad_API_Tokens::revocar_jti( get_current_user_id(), (string) $req->get_param( 'id' ) );
		if ( ! $ok ) {
			return new WP_Error( 'cead_api_sin_sesion', __( 'Esa sesión ya no existe.', 'cead-acad' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( [ 'ok' => true ] );
	}

	/* ------------------------------------------------------------------ me */

	public function me() {
		return rest_ensure_response( $this->perfil( wp_get_current_user() ) );
	}

	/**
	 * Quién es, qué puede y dónde está parado.
	 *
	 * Las capacidades van enteras porque la app arma su menú con lo mismo que
	 * la barra lateral del panel: si acá se mandara un rol a secas, la app
	 * tendría que reimplementar la tabla de qué rol puede qué, y esa tabla ya
	 * existe en un solo lugar.
	 */
	protected function perfil( $user ) {
		$rol    = cead_acad_user_role( $user->ID );
		$roles  = Cead_Acad_Capabilities::roles();
		$caps   = [];
		foreach ( array_keys( array_filter( (array) $user->allcaps ) ) as $cap ) {
			if ( str_starts_with( (string) $cap, 'cead_acad_' ) ) {
				$caps[] = $cap;
			}
		}
		sort( $caps );

		$curso_id = cead_acad_curso_actual( $user->ID );

		return [
			'id'        => (int) $user->ID,
			'nombre'    => $user->display_name,
			'email'     => $user->user_email,
			'rol'       => $rol,
			'rol_label' => (string) ( $roles[ $rol ]['display'] ?? '' ),
			'caps'      => $caps,
			'curso'     => $curso_id ? [
				'id'     => $curso_id,
				'titulo' => get_the_title( $curso_id ),
			] : null,
			'avatar'    => get_avatar_url( $user->ID, [ 'size' => 192 ] ),
		];
	}

	/* ------------------------------------------------------ autenticación */

	/**
	 * Traduce el token del header a un usuario.
	 *
	 * Se engancha en `determine_current_user` y no en un `permission_callback`
	 * propio para que, de ahí en adelante, todo el resto del plugin funcione sin
	 * enterarse: `current_user_can()`, los feeds que filtran por usuario y las
	 * capacidades de siempre ven una sesión normal.
	 */
	public function usuario_por_token( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		$token = $this->token_del_header();
		if ( '' === $token ) {
			return $user_id;
		}

		$id = Cead_Acad_API_Tokens::validar( $token );
		if ( ! $id ) {
			$this->error = new WP_Error( 'cead_api_token_invalido', __( 'Sesión vencida. Entrá de nuevo.', 'cead-acad' ), [ 'status' => 401 ] );
			return $user_id;
		}

		/*
		 * Defensa en profundidad: suspender ya destruye las sesiones y revoca
		 * por el camino del login, pero un token vivo no tiene que sobrevivir a
		 * una suspensión ni un minuto por un hueco que no vimos.
		 */
		if ( Cead_Acad_User_Suspension::is_suspended( $id ) ) {
			Cead_Acad_API_Tokens::revocar_todo( $id );
			$this->error = new WP_Error( 'cead_api_suspendida', __( 'Tu cuenta está suspendida. Contactá a la secretaría del CEAD.', 'cead-acad' ), [ 'status' => 403 ] );
			return $user_id;
		}

		$this->token = $token;

		return $id;
	}

	/**
	 * Si el token vino y no servía, el request muere acá.
	 *
	 * Sin esto, un token vencido se trataría como «no mandó nada» y el pedido
	 * seguiría como anónimo hasta chocar contra un permiso — que es un 403
	 * genérico donde la app necesita un 401 claro para saber que tiene que
	 * volver a pedir la contraseña.
	 */
	public function error_de_autenticacion( $resultado ) {
		if ( null !== $resultado && true !== $resultado ) {
			return $resultado;
		}
		return $this->error ?: $resultado;
	}

	/**
	 * `Authorization: Bearer <token>`.
	 *
	 * Se mira también `X-Cead-Token` porque hay servidores que se comen el
	 * header `Authorization` antes de que PHP lo vea (Apache en CGI es el caso
	 * clásico), y quedarse sin forma de autenticarse por una configuración del
	 * hosting no es una hipótesis: es un martes.
	 */
	protected function token_del_header() {
		$fuentes = [
			$_SERVER['HTTP_AUTHORIZATION'] ?? '',
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
		];
		foreach ( $fuentes as $bruto ) {
			$bruto = trim( (string) $bruto );
			if ( 0 === stripos( $bruto, 'bearer ' ) ) {
				return trim( substr( $bruto, 7 ) );
			}
		}
		return trim( (string) ( $_SERVER['HTTP_X_CEAD_TOKEN'] ?? '' ) );
	}

	/**
	 * ¿La contraseña puede viajar por acá?
	 *
	 * En claro está comprometida apenas sale del teléfono, así que en producción
	 * no se negocia. Pero `is_ssl()` sola no alcanza para decidirlo: mira la
	 * conexión que le llegó a PHP, y en el despliegue que documentamos —nginx
	 * con certbot adelante, el sitio en 127.0.0.1— ese último tramo es HTTP. Con
	 * `is_ssl()` a secas, un sitio perfectamente cifrado de cara al alumno se
	 * quedaría sin poder loguear a nadie, y el error no diría por qué.
	 *
	 * Por eso vale también que el sitio esté CONFIGURADO en HTTPS: esa es la URL
	 * por la que la app entra de verdad. Un sitio servido en HTTP liso sigue
	 * rechazado, que es el caso que la regla quiere frenar.
	 */
	public static function conexion_segura() {
		if ( is_ssl() ) {
			return true;
		}
		if ( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			return true;
		}
		// En una máquina de desarrollo no hay certificado y hay que poder probar.
		return in_array( wp_get_environment_type(), [ 'local', 'development' ], true );
	}

	/** Gate de los endpoints que piden sesión. */
	public function autenticado() {
		if ( $this->error ) {
			return $this->error;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'cead_api_sin_sesion', __( 'Hace falta iniciar sesión.', 'cead-acad' ), [ 'status' => 401 ] );
		}
		if ( ! Cead_Acad_Capabilities::user_in_plugin( wp_get_current_user() ) ) {
			return new WP_Error( 'cead_api_sin_panel', __( 'Esta cuenta no tiene acceso al panel.', 'cead-acad' ), [ 'status' => 403 ] );
		}
		return true;
	}
}
