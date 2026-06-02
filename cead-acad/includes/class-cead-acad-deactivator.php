<?php
/**
 * Tareas al desactivar el plugin: flush rewrites. NO borra tablas ni datos.
 * El borrado destructivo está en uninstall.php (controlado por CEAD_ACAD_HARD_UNINSTALL).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Deactivator {

	public static function deactivate() {
		// Limpiar tareas programadas del módulo WhatsApp.
		if ( class_exists( 'Cead_Acad_WA_Cron' ) ) {
			Cead_Acad_WA_Cron::clear_all();
		}
		flush_rewrite_rules();
	}
}
