<?php
/**
 * Rewrite rules y enrutador del frontend: /login, /registro, /recuperar, /panel/*.
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
	}

	public function register() {
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([^&]+)' );

		// Rutas top-level estilizadas.
		add_rewrite_rule( '^login/?$',           'index.php?' . self::QUERY_VAR . '=login',          'top' );
		add_rewrite_rule( '^registro/?$',        'index.php?' . self::QUERY_VAR . '=register',       'top' );
		add_rewrite_rule( '^recuperar/?$',       'index.php?' . self::QUERY_VAR . '=recover',        'top' );
		add_rewrite_rule( '^recuperar/restablecer/?$', 'index.php?' . self::QUERY_VAR . '=recover_reset', 'top' );
		add_rewrite_rule( '^salir/?$',           'index.php?' . self::QUERY_VAR . '=logout',         'top' );

		// Panel y sub-rutas (stub en F0; los módulos las van completando).
		add_rewrite_rule( '^panel/?$',           'index.php?' . self::QUERY_VAR . '=panel',          'top' );
		add_rewrite_rule( '^panel/(.+?)/?$',     'index.php?' . self::QUERY_VAR . '=panel/$matches[1]', 'top' );
	}

	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
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
				$redirect = cead_acad_url( 'login' );
				wp_logout();
				wp_safe_redirect( $redirect );
				exit;

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
				$id = isset( $parts[1] ) ? (int) $parts[1] : 0;
				if ( $id > 0 ) {
					cead_acad_template( 'panel/horarios/single.php', [ 'event_id' => $id ] );
				} else {
					cead_acad_template( 'panel/horarios/list.php' );
				}
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
