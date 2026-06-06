<?php
/**
 * Actualizaciones automáticas desde GitHub (Releases privados) usando
 * plugin-update-checker. El token NUNCA se hardcodea: se lee de la constante
 * CEAD_ACAD_GITHUB_TOKEN (wp-config.php) o de la opción guardada en ajustes.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Updates {

	// OJO: el casing del owner debe coincidir EXACTO con GitHub (NasastaXD), porque
	// plugin-update-checker compara la URL del asset de forma sensible a mayúsculas
	// para adjuntar el token en la descarga. Con minúsculas, la descarga da 404.
	const REPO         = 'https://github.com/NasastaXD/iamnasasta/';
	const SLUG         = 'cead-acad';
	const OPTION_TOKEN = 'cead_acad_github_token';
	const ASSET_REGEX  = '/cead-acad\.zip/';

	/** @var object|null */
	protected $checker = null;

	public function boot() {
		add_action( 'admin_menu',  [ $this, 'menu' ], 20 );
		add_action( 'admin_init',  [ $this, 'register_settings' ] );

		$loader = CEAD_ACAD_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! file_exists( $loader ) ) {
			return;
		}
		require_once $loader;

		$factory = 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return;
		}

		$this->checker = $factory::buildUpdateChecker( self::REPO, CEAD_ACAD_FILE, self::SLUG );

		// Repo privado: autenticar con el token.
		$token = self::token();
		if ( $token ) {
			$this->checker->setAuthentication( $token );
		}

		// El plugin vive en un subdirectorio del monorepo: usamos el zip adjunto
		// al Release (asset) en vez del código fuente completo del repo.
		$api = $this->checker->getVcsApi();
		if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
			// REQUIRE: nunca caer al zip del código fuente (monorepo) si falta el asset.
			$api->enableReleaseAssets( self::ASSET_REGEX, 2 /* REQUIRE_RELEASE_ASSETS */ );
		}
		// Considerar solo Releases que tengan el asset del plugin. Así los Releases
		// del TEMA (cead-theme.zip) del mismo repo no afectan al plugin.
		if ( $api && method_exists( $api, 'setReleaseFilter' ) ) {
			$api->setReleaseFilter(
				function ( $version, $release ) {
					if ( ! is_object( $release ) || empty( $release->assets ) || ! is_array( $release->assets ) ) {
						return false;
					}
					foreach ( $release->assets as $asset ) {
						if ( isset( $asset->name ) && $asset->name === 'cead-acad.zip' ) {
							return true;
						}
					}
					return false;
				}
			);
		}
	}

	/** Token: constante (prioritaria) u opción guardada. */
	public static function token() {
		if ( defined( 'CEAD_ACAD_GITHUB_TOKEN' ) && CEAD_ACAD_GITHUB_TOKEN ) {
			return (string) CEAD_ACAD_GITHUB_TOKEN;
		}
		return (string) get_option( self::OPTION_TOKEN, '' );
	}

	public static function token_from_constant() {
		return defined( 'CEAD_ACAD_GITHUB_TOKEN' ) && CEAD_ACAD_GITHUB_TOKEN;
	}

	public function register_settings() {
		register_setting( 'cead_acad_updates', self::OPTION_TOKEN, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );
	}

	public function menu() {
		add_submenu_page(
			'cead-acad',
			__( 'Actualizaciones', 'cead-acad' ),
			__( 'Actualizaciones', 'cead-acad' ),
			'manage_options',
			'cead-acad-updates',
			[ $this, 'render' ]
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}

		$from_const = self::token_from_constant();
		$has_token  = (bool) self::token();
		$check_url  = wp_nonce_url( add_query_arg( [ 'puc_check_for_updates' => 1, 'puc_slug' => self::SLUG ], admin_url( 'plugins.php' ) ), 'puc_check_for_updates' );

		$test = null;
		if ( isset( $_GET['cead_test'] ) && check_admin_referer( 'cead_test' ) ) {
			$test = $this->test_connection();
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Actualizaciones del plugin', 'cead-acad' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s versión actual */
					esc_html__( 'Versión instalada: %s. Las actualizaciones se publican como Releases en GitHub y aparecen en Plugins → Actualizaciones.', 'cead-acad' ),
					'<strong>' . esc_html( CEAD_ACAD_VERSION ) . '</strong>'
				);
				?>
			</p>

			<div class="notice notice-<?php echo $has_token ? 'success' : 'warning'; ?> inline" style="padding:8px 12px">
				<p style="margin:0">
					<strong><?php esc_html_e( 'Estado del token de GitHub', 'cead-acad' ); ?>:</strong>
					<?php
					if ( $from_const ) {
						esc_html_e( '✅ definido en wp-config.php (CEAD_ACAD_GITHUB_TOKEN).', 'cead-acad' );
					} elseif ( $has_token ) {
						esc_html_e( '✅ guardado en ajustes.', 'cead-acad' );
					} else {
						esc_html_e( '❌ falta. Sin token no se pueden leer los Releases del repo privado.', 'cead-acad' );
					}
					?>
				</p>
			</div>

			<?php if ( ! $from_const ) : ?>
				<form method="post" action="options.php">
					<?php settings_fields( 'cead_acad_updates' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="cead_acad_github_token"><?php esc_html_e( 'Token de acceso (GitHub)', 'cead-acad' ); ?></label></th>
							<td>
								<input type="password" id="cead_acad_github_token" name="<?php echo esc_attr( self::OPTION_TOKEN ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_TOKEN, '' ) ); ?>" class="regular-text" autocomplete="off">
								<p class="description"><?php esc_html_e( 'Token personal con permiso de lectura del repositorio (scope "repo" o fine-grained con acceso de solo lectura a Contents).', 'cead-acad' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button(); ?>
				</form>
			<?php else : ?>
				<p><em><?php esc_html_e( 'El token está fijado por constante en wp-config.php, así que este campo está deshabilitado.', 'cead-acad' ); ?></em></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Diagnóstico', 'cead-acad' ); ?></h2>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'cead_test', 1 ), 'cead_test' ) ); ?>"><?php esc_html_e( 'Probar conexión con GitHub', 'cead-acad' ); ?></a>
				<a class="button button-primary" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Comprobar actualizaciones ahora', 'cead-acad' ); ?></a>
			</p>

			<?php if ( $test ) : ?>
				<div class="notice notice-<?php echo $test['ok'] ? 'success' : 'error'; ?> inline" style="padding:8px 12px">
					<p style="margin:0 0 4px"><strong><?php esc_html_e( 'Resultado de la prueba', 'cead-acad' ); ?>:</strong></p>
					<ul style="margin:0 0 0 18px;list-style:disc">
						<li><?php esc_html_e( 'Token detectado', 'cead-acad' ); ?>: <code><?php echo $test['token_present'] ? esc_html( $test['token_hint'] ) : '—'; ?></code></li>
						<li><?php esc_html_e( 'Respuesta de GitHub (releases/latest)', 'cead-acad' ); ?>: <code>HTTP <?php echo esc_html( $test['code'] ); ?></code></li>
						<?php if ( $test['ok'] ) : ?>
							<li><?php esc_html_e( 'Release encontrado', 'cead-acad' ); ?>: <code><?php echo esc_html( $test['tag'] ); ?></code> · <?php esc_html_e( 'asset', 'cead-acad' ); ?>: <code><?php echo esc_html( $test['asset'] ); ?></code></li>
						<?php else : ?>
							<li style="color:#b32d2e"><?php echo esc_html( $test['msg'] ); ?></li>
						<?php endif; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Prueba directa contra la API de GitHub con el token configurado. */
	protected function test_connection() {
		$token = self::token();
		$url   = 'https://api.github.com/repos/NasastaXD/iamnasasta/releases/latest';
		$args  = [
			'timeout' => 15,
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'CEAD-Academico',
			],
		];
		if ( $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$out = [
			'ok'            => false,
			'code'          => 0,
			'tag'           => '',
			'asset'         => '',
			'msg'           => '',
			'token_present' => (bool) $token,
			'token_hint'    => $token ? ( substr( $token, 0, 4 ) . '…' . substr( $token, -4 ) . ' (' . strlen( $token ) . ' chars)' ) : '',
		];

		$res = wp_remote_get( $url, $args );
		if ( is_wp_error( $res ) ) {
			$out['msg'] = $res->get_error_message();
			return $out;
		}
		$out['code'] = (int) wp_remote_retrieve_response_code( $res );
		$body        = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 === $out['code'] && is_array( $body ) ) {
			$out['ok']    = true;
			$out['tag']   = (string) ( $body['tag_name'] ?? '' );
			$assets       = $body['assets'] ?? [];
			$out['asset'] = $assets ? (string) ( $assets[0]['name'] ?? '' ) : __( '(sin asset)', 'cead-acad' );
		} elseif ( 404 === $out['code'] ) {
			$out['msg'] = $token
				? __( 'El token no tiene acceso a este repositorio privado (revisá que sea del owner correcto, con permiso Contents: Read, y que no esté vencido).', 'cead-acad' )
				: __( 'No hay token configurado: GitHub devuelve 404 para repos privados sin autenticar.', 'cead-acad' );
		} elseif ( 401 === $out['code'] ) {
			$out['msg'] = __( 'Token inválido o vencido (401).', 'cead-acad' );
		} else {
			$out['msg'] = sprintf( __( 'Respuesta inesperada de GitHub (HTTP %d).', 'cead-acad' ), $out['code'] );
		}
		return $out;
	}
}
