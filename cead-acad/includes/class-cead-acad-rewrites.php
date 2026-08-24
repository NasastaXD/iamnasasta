<?php
/**
 * Rewrite rules y enrutador del frontend: /ingresar, /registro, /recuperar, /panel/*.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Rewrites {

	const QUERY_VAR = 'cead_acad_route';

	public function boot() {
		add_action( 'init',              [ $this, 'register' ] );
		add_filter( 'query_vars',        [ $this, 'query_vars' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
		add_action( 'init',              [ $this, 'maybe_flush' ], 99 );

		// Bloquear wp-login.php para usuarios del plugin (toggle vía settings, default ON).
		add_action( 'login_init', [ $this, 'block_wp_login' ] );

		// Evitar que WordPress, ante una URL que no encuentra, "adivine" y redirija
		// a un post al azar (ej.: "Hello World"). Mejor un 404 limpio.
		add_filter( 'redirect_guess_404_permalink', '__return_false' );
	}

	public function register() {
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([^&]+)' );

		// Rutas top-level estilizadas.
		add_rewrite_rule( '^ingresar/?$',        'index.php?' . self::QUERY_VAR . '=login',          'top' );
		/*
		 * `/login` sigue existiendo, pero solo para llevar a `/ingresar`. Es la
		 * dirección que la gente tiene guardada, la que quedó en mensajes viejos
		 * y la que está impresa en cualquier papel que ya se repartió: sacarla de
		 * golpe convierte todo eso en un 404 sin explicación.
		 */
		add_rewrite_rule( '^login/?$',           'index.php?' . self::QUERY_VAR . '=login_movido', 'top' );
		add_rewrite_rule( '^registro/?$',        'index.php?' . self::QUERY_VAR . '=register',       'top' );
		add_rewrite_rule( '^recuperar/?$',       'index.php?' . self::QUERY_VAR . '=recover',        'top' );
		add_rewrite_rule( '^recuperar/restablecer/?$', 'index.php?' . self::QUERY_VAR . '=recover_reset', 'top' );
		add_rewrite_rule( '^salir/?$',           'index.php?' . self::QUERY_VAR . '=logout',         'top' );

		// Link corto de invitación: /i/<token> → página de registro con el token.
		add_rewrite_rule( '^i/([^/]+)/?$',       'index.php?' . self::QUERY_VAR . '=register&cead_acad_t=$matches[1]', 'top' );

		// Verificación pública del carné: /carne/<token>.
		add_rewrite_rule( '^carne/([^/]+)/?$',   'index.php?' . self::QUERY_VAR . '=carne&cead_acad_t=$matches[1]', 'top' );

		// PWA: manifest, service worker e íconos.
		add_rewrite_rule( '^cead-manifest\.webmanifest$', 'index.php?' . self::QUERY_VAR . '=pwa_manifest', 'top' );
		add_rewrite_rule( '^cead-sw\.js$',                'index.php?' . self::QUERY_VAR . '=pwa_sw',       'top' );
		add_rewrite_rule( '^cead-icon-([0-9]+)\.png$',    'index.php?' . self::QUERY_VAR . '=pwa_icon&cead_acad_t=$matches[1]', 'top' );
		add_rewrite_rule( '^cead-offline$',               'index.php?' . self::QUERY_VAR . '=pwa_offline',  'top' );

		// Suscripción de calendario (iCal) por token: /cal/<token>.ics
		add_rewrite_rule( '^cal/([^/]+)\.ics$',  'index.php?' . self::QUERY_VAR . '=calfeed&cead_acad_t=$matches[1]', 'top' );

		// Wiki pública del proyecto (solo lectura): /wiki y /wiki/<seccion>.
		add_rewrite_rule( '^wiki/?$',                    'index.php?' . self::QUERY_VAR . '=wiki', 'top' );
		add_rewrite_rule( '^wiki/(usuario|tecnica)/?$',  'index.php?' . self::QUERY_VAR . '=wiki&cead_acad_t=$matches[1]', 'top' );

		// Panel y sub-rutas (stub en F0; los módulos las van completando).
		add_rewrite_rule( '^panel/?$',           'index.php?' . self::QUERY_VAR . '=panel',          'top' );
		add_rewrite_rule( '^panel/(.+?)/?$',     'index.php?' . self::QUERY_VAR . '=panel/$matches[1]', 'top' );
	}

	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = 'cead_acad_t';
		return $vars;
	}

	public function maybe_flush() {
		if ( get_option( 'cead_acad_flush_rewrites' ) ) {
			flush_rewrite_rules();
			delete_option( 'cead_acad_flush_rewrites' );
		}
	}

	public function maybe_render() {
		$route = get_query_var( self::QUERY_VAR );
		if ( ! $route ) {
			return;
		}
		$this->dispatch( (string) $route );
		exit;
	}

	protected function dispatch( $route ) {
		// status_header(200) para que WP no devuelva 404.
		status_header( 200 );
		nocache_headers();

		switch ( true ) {
			case 'login_movido' === $route:
				// 301: es una mudanza definitiva, y así los navegadores y los
				// buscadores dejan de volver a la dirección vieja.
				wp_safe_redirect( cead_acad_url( 'login' ), 301 );
				exit;

			case 'login' === $route:
				cead_acad_template( 'auth/login.php' );
				return;

			case 'register' === $route:
				cead_acad_template( 'auth/register.php' );
				return;

			case 'recover' === $route:
				cead_acad_template( 'auth/recover.php' );
				return;

			case 'recover_reset' === $route:
				cead_acad_template( 'auth/recover-reset.php' );
				return;

			case 'logout' === $route:
				/*
				 * Sin nonce no se cierra nada. Un GET pelado significa que
				 * cualquier cosa que precargue enlaces desloguea al usuario:
				 * prefetch del navegador, escáneres de antivirus, la
				 * previsualización de WhatsApp al compartir una URL del panel.
				 * Es también lo que hace WordPress con su propio logout.
				 *
				 * Si el nonce falta o venció, se muestra una confirmación en vez
				 * de fallar en seco: el caso normal es un enlace viejo, no un
				 * ataque.
				 */
				$nonce = isset( $_GET['cead_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['cead_nonce'] ) ) : '';
				if ( ! wp_verify_nonce( $nonce, CEAD_ACAD_LOGOUT_ACTION ) ) {
					cead_acad_template( 'auth/logout-confirm.php' );
					return;
				}
				$redirect = cead_acad_url( 'login' );
				wp_logout();
				wp_safe_redirect( $redirect );
				exit;

			case 'carne' === $route:
				// La verificación es pública: muestra nombre, foto y curso a
				// quien tenga el token. Con la función apagada tiene que estar
				// apagada también acá, o el dato sigue expuesto sin que nadie
				// vea la feature en el panel.
				if ( ! cead_acad_carne_activo() ) {
					// `dispatch()` ya mandó un 200 y quien llama hace exit, así
					// que un `return` pelado devolvería una página en blanco
					// exitosa. Hay que decir explícitamente que no está.
					status_header( 404 );
					wp_die(
						esc_html__( 'El carné digital no está disponible.', 'cead-acad' ),
						esc_html__( 'No disponible', 'cead-acad' ),
						[ 'response' => 404 ]
					);
				}
				$token = (string) get_query_var( 'cead_acad_t' );
				cead_acad_template( 'carne/verify.php', [ 'token' => $token ] );
				return;

			case 'pwa_manifest' === $route:
				Cead_Acad_PWA::manifest();
				return;

			case 'pwa_sw' === $route:
				Cead_Acad_PWA::service_worker();
				return;

			case 'pwa_offline' === $route:
				Cead_Acad_PWA::offline_page();
				return;

			case 'pwa_icon' === $route:
				Cead_Acad_PWA::icon( (int) get_query_var( 'cead_acad_t' ) );
				return;

			case 'calfeed' === $route:
				Cead_Acad_Schedule_Feed::output_subscription( (string) get_query_var( 'cead_acad_t' ) );
				return;

			case 'wiki' === $route:
				Cead_Acad_Wiki::render( (string) get_query_var( 'cead_acad_t' ) );
				return;

			case 'panel' === $route || str_starts_with( $route, 'panel/' ):
				if ( ! is_user_logged_in() ) {
					wp_safe_redirect( add_query_arg( 'next', rawurlencode( $_SERVER['REQUEST_URI'] ?? '/panel' ), cead_acad_url( 'login' ) ) );
					exit;
				}
				if ( ! current_user_can( 'cead_acad_view_panel' ) ) {
					wp_die( esc_html__( 'No tenés permiso para acceder al panel.', 'cead-acad' ), 403 );
				}
				$sub = $route === 'panel' ? '' : substr( $route, strlen( 'panel/' ) );
				$this->dispatch_panel( $sub );
				return;
		}
	}

	protected function dispatch_panel( $sub ) {
		// Normalizar y separar.
		$sub = trim( (string) $sub, '/' );
		$parts = $sub === '' ? [] : explode( '/', $sub );
		$section = $parts[0] ?? '';

		switch ( $section ) {
			case '':
				cead_acad_template( 'panel/home.php', [ 'sub' => '' ] );
				return;

			case 'comunicados':
				$id = isset( $parts[1] ) ? (int) $parts[1] : 0;
				if ( $id > 0 ) {
					cead_acad_template( 'panel/comunicados/single.php', [ 'broadcast_id' => $id ] );
				} else {
					cead_acad_template( 'panel/comunicados/feed.php' );
				}
				return;

			case 'encuestas':
				$id = isset( $parts[1] ) ? (int) $parts[1] : 0;
				if ( $id > 0 ) {
					cead_acad_template( 'panel/encuestas/take.php', [ 'survey_id' => $id ] );
				} else {
					cead_acad_template( 'panel/encuestas/list.php' );
				}
				return;

			case 'horarios':
				cead_acad_template( 'panel/horarios/list.php' );
				return;

			case 'delegados':
				cead_acad_template( 'panel/delegados/list.php' );
				return;

			case 'calendario':
				$id = isset( $parts[1] ) ? (int) $parts[1] : 0;
				if ( $id > 0 ) {
					cead_acad_template( 'panel/calendario/single.php', [ 'event_id' => $id ] );
				} else {
					cead_acad_template( 'panel/calendario/list.php' );
				}
				return;

			case 'buzon':
				cead_acad_template( 'panel/buzon/list.php' );
				return;

			case 'recursos':
				$id = isset( $parts[1] ) ? (int) $parts[1] : 0;
				if ( $id > 0 ) {
					cead_acad_template( 'panel/recursos/single.php', [ 'resource_id' => $id ] );
				} else {
					cead_acad_template( 'panel/recursos/list.php' );
				}
				return;

			case 'boletin':
				cead_acad_template( 'panel/boletin/show.php' );
				return;

			case 'perfil':
				cead_acad_template( 'panel/perfil/show.php' );
				return;

			case 'carne':
				// Con el carné apagado la ruta no existe: se cae al inicio del
				// panel. Ocultar solo los enlaces dejaría la página viva para
				// cualquiera que se guardó la URL o la tenga en el historial.
				if ( ! cead_acad_carne_activo() ) {
					wp_safe_redirect( cead_acad_url( 'panel' ) );
					exit;
				}
				cead_acad_template( 'panel/carne/show.php' );
				return;

			case 'app':
				cead_acad_template( 'panel/app/show.php' );
				return;

			case 'tareas':
				cead_acad_template( 'panel/tareas/list.php' );
				return;

			case 'buscar':
				cead_acad_template( 'panel/buscar/results.php' );
				return;

			case 'contacto':
				cead_acad_template( 'panel/contacto/show.php' );
				return;

			case 'faq':
				cead_acad_template( 'panel/faq/list.php' );
				return;

			case 'wiki':
				// La wiki vive en /wiki (página pública, solo lectura). Desde el
				// panel es una simple redirección, como pidió la dirección.
				wp_safe_redirect( cead_acad_url( 'wiki' ) );
				exit;

			case 'delegado':
				cead_acad_template( 'panel/delegado/dashboard.php' );
				return;

			case 'secretaria':
				cead_acad_template( 'panel/secretaria/dashboard.php' );
				return;

			case 'direccion':
				cead_acad_template( 'panel/direccion/dashboard.php' );
				return;

			default:
				cead_acad_template( 'panel/home.php', [ 'sub' => $sub ] );
				return;
		}
	}

	public function block_wp_login() {
		// Solo aplica si la opción está activa (default ON).
		if ( ! (bool) get_option( 'cead_acad_block_wp_login', 1 ) ) {
			return;
		}
		// Permitir explícitamente POST de login (form propio podría redirigir aquí) y logout/lostpassword internos.
		if ( isset( $_GET['interim-login'] ) ) {
			return;
		}
		// Si es admin o ya logueado con rol no-plugin, dejar pasar.
		$user = wp_get_current_user();
		if ( $user && $user->exists() ) {
			if ( user_can( $user, 'manage_options' ) && ! Cead_Acad_Capabilities::user_in_plugin( $user ) ) {
				return;
			}
		}
		// Si es una acción específica de WP (postpass, logout, etc.) dejar pasar.
		$action = $_REQUEST['action'] ?? '';
		if ( in_array( $action, [ 'logout', 'postpass', 'resetpass', 'rp', 'register' ], true ) ) {
			return;
		}
		wp_safe_redirect( cead_acad_url( 'login' ) );
		exit;
	}
}
