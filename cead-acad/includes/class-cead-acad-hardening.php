<?php
/**
 * Cierre de las puertas que WordPress deja abiertas por defecto.
 *
 * El panel corre sobre usuarios de WordPress. Esa decisión se revisó y se
 * sostiene —migrar a cuentas propias serían 269 llamadas en 57 archivos, con el
 * vínculo teléfono↔persona de CEADI en el medio—, pero tiene una contrapartida
 * que hay que pagar explícitamente: un usuario de WordPress no es solo una
 * fila, es también un montón de superficie que nadie de este colegio necesita.
 *
 * Bloquear `wp-login.php` (que ya se hacía) NO alcanza, y ese es el punto de
 * este archivo: hay al menos tres formas de autenticarse contra WordPress sin
 * pasar nunca por esa pantalla. Un candado en la puerta principal no sirve de
 * nada si la ventana del fondo está abierta.
 *
 * Todo lo de acá está prendido de fábrica. No hay interruptor porque no se me
 * ocurre un motivo legítimo para querer nada de esto abierto en un colegio.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Hardening {

	public function boot() {
		/*
		 * 1. XML-RPC. La más grave de todas.
		 *
		 * `xmlrpc.php` acepta usuario y contraseña sin pasar por el formulario,
		 * así que el bloqueo de wp-login.php no lo toca. Y `system.multicall`
		 * permite meter CIENTOS de intentos de contraseña en UNA sola petición
		 * HTTP: además de saltear la pantalla, esquiva cualquier límite por
		 * cantidad de pedidos, incluido el nuestro.
		 *
		 * No lo usa nada de este proyecto: el bridge de WhatsApp habla por REST
		 * con su propio token, y no hay apps de escritorio publicando.
		 */
		add_filter( 'xmlrpc_enabled', '__return_false' );

		/*
		 * Y aparte los pingbacks, que sobreviven a `xmlrpc_enabled` porque no
		 * necesitan autenticación. `pingback.ping` convierte al sitio en un
		 * intermediario para escanear redes ajenas o para sumarse a una
		 * inundación contra un tercero, sin que nadie acá se entere.
		 */
		add_filter( 'xmlrpc_methods', [ $this, 'quitar_pingback' ] );
		add_filter( 'wp_headers', [ $this, 'quitar_cabecera_pingback' ] );

		/*
		 * 2. Contraseñas de aplicación (WordPress 5.6+).
		 *
		 * Son credenciales alternativas que entran por REST y no pasan por el
		 * formulario ni por el bloqueo de wp-login. Una que se genere una vez
		 * sigue sirviendo para siempre, aunque después le cambien la contraseña
		 * a la persona: es exactamente la puerta trasera que uno NO quiere que
		 * quede si alguna cuenta se compromete un rato.
		 *
		 * Nadie del panel las necesita. Se apagan para la gente del plugin y se
		 * dejan a los administradores de WordPress, que sí pueden tener una
		 * herramienta externa enganchada.
		 */
		add_filter( 'wp_is_application_passwords_available_for_user', [ $this, 'app_passwords_para' ], 10, 2 );

		/*
		 * 3. Enumeración de usuarios.
		 *
		 * Saber CÓMO SE LLAMAN las cuentas es la mitad del trabajo de quien
		 * quiere entrar adivinando: sin eso hay que acertar usuario y
		 * contraseña; con eso, solo la contraseña. WordPress lo regala por dos
		 * lados distintos y los dos hay que taparlos.
		 */
		add_action( 'template_redirect', [ $this, 'bloquear_archivo_de_autor' ] );
		add_filter( 'rest_endpoints', [ $this, 'cerrar_listado_de_usuarios' ] );

		/*
		 * 4. No confirmar si un usuario existe.
		 *
		 * WordPress distingue «ese usuario no existe» de «la contraseña es
		 * incorrecta». Es cómodo y le dice a cualquiera qué cuentas hay.
		 */
		add_filter( 'login_errors', [ $this, 'error_generico' ] );
	}

	/* --------------------------------------------------------------- XML-RPC */

	public function quitar_pingback( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return $methods;
	}

	public function quitar_cabecera_pingback( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/* --------------------------------------------- contraseñas de aplicación */

	/**
	 * @param bool    $disponible Lo que venía decidido.
	 * @param WP_User $user       Para quién.
	 */
	public function app_passwords_para( $disponible, $user ) {
		if ( $user instanceof WP_User && class_exists( 'Cead_Acad_Capabilities' )
			&& Cead_Acad_Capabilities::user_in_plugin( $user ) ) {
			return false;
		}
		return $disponible;
	}

	/* ------------------------------------------------------- enumeración */

	/**
	 * `/?author=1` redirige al archivo de ese autor, y la URL resultante
	 * contiene su nombre de usuario. Es la forma más vieja y más fácil de
	 * sacarle la lista de cuentas a un WordPress: se prueba 1, 2, 3…
	 *
	 * El tema no tiene `author.php` ni enlaza archivos de autor por ningún lado,
	 * así que acá no se rompe nada: es una función que no se usa y solo filtra.
	 */
	public function bloquear_archivo_de_autor() {
		if ( is_admin() || ! is_author() ) {
			return;
		}
		// A quien ya está adentro y puede ver la lista de usuarios no le
		// escondemos nada que no pueda mirar igual.
		if ( is_user_logged_in() && current_user_can( 'list_users' ) ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * `/wp-json/wp/v2/users` devuelve la lista de usuarios con nombre y slug a
	 * cualquiera, sin autenticación.
	 *
	 * Se cierra SOLO para quien no está autenticado. Cerrarlo del todo rompería
	 * el editor de bloques, que consulta ese endpoint para elegir autor — y una
	 * medida de seguridad que rompe el trabajo diario se termina desactivando
	 * entera, que es peor que no haberla puesto.
	 */
	public function cerrar_listado_de_usuarios( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}
		foreach ( [ '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ] as $ruta ) {
			if ( isset( $endpoints[ $ruta ] ) ) {
				unset( $endpoints[ $ruta ] );
			}
		}
		return $endpoints;
	}

	/* ------------------------------------------------------------- mensajes */

	/**
	 * Un solo mensaje para «no existe» y para «contraseña incorrecta».
	 *
	 * Aplica a la pantalla de WordPress, que igual está bloqueada para la gente
	 * del plugin; se deja por los administradores y porque el bloqueo tiene
	 * excepciones (interim-login, resetpass).
	 */
	public function error_generico( $error ) {
		if ( is_string( $error ) && '' !== $error ) {
			return __( 'Usuario o contraseña incorrectos.', 'cead-acad' );
		}
		return $error;
	}
}
