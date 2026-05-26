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
		( new Cead_Acad_Admin_Menu() )->boot();

		// Migraciones idempotentes en cambio de versión.
		if ( get_option( 'cead_acad_db_version' ) !== CEAD_ACAD_DB_VERSION ) {
			Cead_Acad_Activator::create_tables();
			Cead_Acad_Capabilities::install();
			update_option( 'cead_acad_db_version', CEAD_ACAD_DB_VERSION );
		}
	}
}
