<?php
/**
 * Auto-actualización del TEMA desde Releases de GitHub (igual que el plugin).
 *
 * El tema vive en un subdirectorio del monorepo, así que se actualiza desde el
 * ZIP adjunto al Release (asset "cead-theme.zip"). Para no chocar con los
 * Releases del plugin (mismo repo), el checker del tema solo considera
 * Releases que tengan el asset "cead-theme.zip" (RELEASE_FILTER_ALL: incluye
 * también algún prerelease viejo que haya quedado publicado así).
 *
 * Token: se reutiliza el del plugin (constante CEAD_ACAD_GITHUB_TOKEN en
 * wp-config.php o la opción guardada en CEAD Académico → Actualizaciones). Si no
 * hay plugin, se puede definir CEAD_GITHUB_TOKEN.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'after_setup_theme', function () {
	$loader = get_template_directory() . '/vendor/plugin-update-checker/plugin-update-checker.php';
	if ( ! is_readable( $loader ) ) { return; }
	require_once $loader;

	$factory = 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
	if ( ! class_exists( $factory ) ) { return; }

	$checker = $factory::buildUpdateChecker(
		'https://github.com/NasastaXD/iamnasasta/',
		get_template_directory() . '/style.css',
		'cead'
	);

	// Token (compartido con el plugin si está disponible).
	$token = '';
	if ( defined( 'CEAD_ACAD_GITHUB_TOKEN' ) && CEAD_ACAD_GITHUB_TOKEN ) {
		$token = (string) CEAD_ACAD_GITHUB_TOKEN;
	} elseif ( defined( 'CEAD_GITHUB_TOKEN' ) && CEAD_GITHUB_TOKEN ) {
		$token = (string) CEAD_GITHUB_TOKEN;
	} else {
		$token = (string) get_option( 'cead_acad_github_token', '' );
	}
	if ( $token ) { $checker->setAuthentication( $token ); }

	$api = $checker->getVcsApi();
	if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
		// Solo el zip del tema; nunca el código fuente del monorepo.
		$api->enableReleaseAssets( '/cead-theme\.zip/', 2 /* REQUIRE_RELEASE_ASSETS */ );
	}
	// Considerar solo Releases que tengan el asset del tema (incluye prereleases).
	if ( $api && method_exists( $api, 'setReleaseFilter' ) ) {
		$api->setReleaseFilter(
			function ( $version, $release ) {
				return cead_release_has_asset( $release, 'cead-theme.zip' );
			},
			3 /* RELEASE_FILTER_ALL: incluye prereleases */
		);
	}
}, 20 );

/**
 * ¿El objeto Release de GitHub tiene un asset con este nombre?
 */
function cead_release_has_asset( $release, $name ) {
	if ( ! is_object( $release ) || empty( $release->assets ) || ! is_array( $release->assets ) ) {
		return false;
	}
	foreach ( $release->assets as $asset ) {
		if ( isset( $asset->name ) && $asset->name === $name ) {
			return true;
		}
	}
	return false;
}
