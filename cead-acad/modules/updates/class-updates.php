<?php
/**
 * Actualizaciones automáticas desde GitHub (Releases privados) usando
 * plugin-update-checker. El token NUNCA se hardcodea: se lee de la constante
 * CEAD_ACAD_GITHUB_TOKEN (wp-config.php) o de la opción guardada en ajustes.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Updates {

	const REPO         = 'https://github.com/nasastaxd/iamnasasta/';
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
			$api->enableReleaseAssets( self::ASSET_REGEX );
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

			<h2><?php esc_html_e( 'Buscar actualizaciones ahora', 'cead-acad' ); ?></h2>
			<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Comprobar ahora', 'cead-acad' ); ?></a></p>
		</div>
		<?php
	}
}
