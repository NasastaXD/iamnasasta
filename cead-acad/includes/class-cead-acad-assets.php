<?php
/**
 * Enqueues condicionales: CSS/JS del plugin solo en rutas del plugin.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Assets {

	public function boot() {
		add_action( 'wp_enqueue_scripts',    [ $this, 'frontend' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin' ] );
	}

	public function is_plugin_route() {
		$route = get_query_var( Cead_Acad_Rewrites::QUERY_VAR );
		return ! empty( $route );
	}

	public function frontend() {
		if ( ! $this->is_plugin_route() ) {
			return;
		}

		// Si el tema CEAD no está activo, encolar fuentes del proveedor (mismas que el tema).
		if ( ! wp_style_is( 'cead-main', 'enqueued' ) ) {
			wp_enqueue_style(
				'cead-acad-fonts',
				'https://fonts.googleapis.com/css2?family=Anton&family=Cormorant+Garamond:wght@400;500;600&family=Mulish:wght@300;400;600&display=swap',
				[],
				null
			);
		}

		$deps = wp_style_is( 'cead-main', 'enqueued' ) ? [ 'cead-main' ] : [];

		wp_enqueue_style(
			'cead-acad-frontend',
			cead_acad_asset( 'assets/css/cead-acad-frontend.css' ),
			$deps,
			CEAD_ACAD_VERSION
		);

		wp_enqueue_script(
			'cead-acad-frontend',
			cead_acad_asset( 'assets/js/cead-acad-frontend.js' ),
			[],
			CEAD_ACAD_VERSION,
			true
		);
		wp_localize_script( 'cead-acad-frontend', 'CeadAcad', [
			'nonce'  => wp_create_nonce( 'cead_acad_frontend' ),
			'urls'   => [
				'login'    => cead_acad_url( 'login' ),
				'register' => cead_acad_url( 'registro' ),
				'recover'  => cead_acad_url( 'recuperar' ),
				'panel'    => cead_acad_url( 'panel' ),
			],
			'i18n'   => [
				'genericError' => __( 'Ocurrió un error. Probá de nuevo.', 'cead-acad' ),
			],
		] );
	}

	public function admin( $hook ) {
		// Solo en pantallas del plugin (prefix cead-acad).
		if ( strpos( (string) $hook, 'cead-acad' ) === false && strpos( (string) ( $_GET['page'] ?? '' ), 'cead-acad' ) === false ) {
			return;
		}
		wp_enqueue_style(
			'cead-acad-admin',
			cead_acad_asset( 'assets/css/cead-acad-admin.css' ),
			[],
			CEAD_ACAD_VERSION
		);
	}
}
