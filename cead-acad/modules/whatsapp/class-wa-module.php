<?php
/**
 * Orquestador del módulo WhatsApp. Construye las dependencias y arranca
 * REST, cron y panel admin. Llamado desde Cead_Acad_Plugin::boot().
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Module {

	public function boot() {
		$store       = new Cead_Acad_WA_Store();
		$bridge      = new Cead_Acad_WA_Bridge_Client( $store );
		$broadcaster = new Cead_Acad_WA_Broadcaster( $store, $bridge );
		$engine      = new Cead_Acad_WA_Engine( $store, $bridge, $broadcaster );

		( new Cead_Acad_WA_REST( $store, $engine ) )->boot();
		( new Cead_Acad_WA_Cron( $store, $bridge, $broadcaster ) )->boot();
		( new Cead_Acad_WA_Admin( $store, $bridge, $broadcaster ) )->boot();
	}
}
