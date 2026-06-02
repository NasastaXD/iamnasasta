<?php
/**
 * Singleton de boot. Engancha módulos en plugins_loaded.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Cead_Acad_Plugin {

	private static $instance = null;

	private function __construct() {}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		load_plugin_textdomain( 'cead-acad', false, dirname( CEAD_ACAD_BASENAME ) . '/languages' );

		// Filtro de capabilities: admins reciben automáticamente las caps del plugin.
		add_filter( 'user_has_cap', [ 'Cead_Acad_Capabilities', 'grant_plugin_caps_to_admins' ], 10, 4 );

		// CPTs administrativos usan classic editor (no block editor). Comunicados
		// queda en Gutenberg porque es contenido rico.
		add_filter( 'use_block_editor_for_post_type', [ $this, 'disable_block_editor_for_admin_cpts' ], 10, 2 );

		// Seeding de términos: una vez por versión, después de registrar taxonomías
		// (init prioridad 20). Guard por opción autoloaded = check barato por request.
		add_action( 'init', [ $this, 'maybe_seed_terms' ], 20 );

		// El menú padre "CEAD Académico" debe registrarse ANTES que los submenús.
		( new Cead_Acad_Admin_Menu() )->boot();

		( new Cead_Acad_Rewrites() )->boot();
		( new Cead_Acad_Assets() )->boot();
		( new Cead_Acad_Invitations() )->boot();
		( new Cead_Acad_Auth_Controller() )->boot();
		( new Cead_Acad_Password_Reset() )->boot();
		( new Cead_Acad_Courses_CPT() )->boot();
		( new Cead_Acad_Courses_Admin() )->boot();
		( new Cead_Acad_Broadcasts_CPT() )->boot();
		( new Cead_Acad_Broadcasts_Targeting() )->boot();
		( new Cead_Acad_Broadcasts_Feed() )->boot();
		( new Cead_Acad_Surveys_CPT() )->boot();
		( new Cead_Acad_Surveys_Admin() )->boot();
		( new Cead_Acad_Surveys_Frontend() )->boot();
		( new Cead_Acad_Schedule_CPT() )->boot();
		( new Cead_Acad_Schedule_Admin() )->boot();
		( new Cead_Acad_Schedule_Feed() )->boot();
		( new Cead_Acad_Resources_CPT() )->boot();
		( new Cead_Acad_Resources_Admin() )->boot();
		( new Cead_Acad_Importer_Admin() )->boot();
		( new Cead_Acad_Tasks_CPT() )->boot();
		( new Cead_Acad_WA_Module() )->boot();

		// Migraciones idempotentes en cambio de versión.
		if ( get_option( 'cead_acad_db_version' ) !== CEAD_ACAD_DB_VERSION ) {
			Cead_Acad_Activator::create_tables();
			update_option( 'cead_acad_db_version', CEAD_ACAD_DB_VERSION );
		}

		// Re-instalar capabilities en cada cambio de versión del plugin (no solo
		// del schema), para refrescar caps del rol administrator tras un upgrade.
		if ( get_option( 'cead_acad_caps_version' ) !== CEAD_ACAD_VERSION ) {
			Cead_Acad_Capabilities::install();
			update_option( 'cead_acad_caps_version', CEAD_ACAD_VERSION );
		}
	}

	/**
	 * Siembra términos por defecto una sola vez por versión. El check es una
	 * opción autoloaded (sin query extra); las queries term_exists() reales solo
	 * corren cuando la versión sembrada no coincide.
	 */
	public function maybe_seed_terms() {
		if ( get_option( 'cead_acad_terms_seeded' ) === CEAD_ACAD_VERSION ) {
			return;
		}
		Cead_Acad_Broadcasts_CPT::seed_terms();
		Cead_Acad_Resources_CPT::seed_terms();
		update_option( 'cead_acad_terms_seeded', CEAD_ACAD_VERSION );
	}

	public function disable_block_editor_for_admin_cpts( $use_block_editor, $post_type ) {
		$classic = [
			'cead_acad_course',
			'cead_acad_event',
			'cead_acad_resource',
			'cead_acad_task',
		];
		if ( in_array( $post_type, $classic, true ) ) {
			return false;
		}
		return $use_block_editor;
	}
}
