<?php
/**
 * Tareas al desactivar el plugin: flush rewrites. NO borra tablas ni datos.
 * El borrado destructivo está en uninstall.php (controlado por CEAD_ACAD_HARD_UNINSTALL).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Deactivator {

	public static function deactivate() {
		flush_rewrite_rules();
	}
}
