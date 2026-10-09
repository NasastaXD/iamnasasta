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

		add_action( 'cead_acad_buzon_respuesta', [ __CLASS__, 'avisar_buzon' ], 10, 4 );
	}

	/**
	 * Coordinación contestó en el buzón: si quien escribió dejó su número, se
	 * le avisa por WhatsApp. Es el mismo aviso que antes salía desde la
	 * plantilla del panel, con las mismas palabras.
	 */
	public static function avisar_buzon( $tipo, $fila, $accion, $respuesta ) {
		$mensaje = self::mensaje_buzon( $tipo, $fila, $accion, $respuesta );
		if ( null !== $mensaje ) {
			self::notify( (string) $fila->phone, $mensaje );
		}
	}

	/** El texto del aviso, o null si no corresponde avisar. Pura, para probarla. */
	public static function mensaje_buzon( $tipo, $fila, $accion, $respuesta ) {
		$respuesta = (string) $respuesta;
		$extra     = '' !== $respuesta ? "\n\n{$respuesta}" : '';
		if ( ! is_object( $fila ) || empty( $fila->phone ) ) {
			return null;
		}

		if ( 'reporte' === $tipo ) {
			// A un anónimo no se le escribe: no tiene número, y si lo tuviera
			// no debería usarse.
			if ( 'confidential' !== ( $fila->type ?? '' ) ) {
				return null;
			}
			$ref = (string) $fila->ref_code;
			switch ( $accion ) {
				case 'respond': return '' !== $respuesta ? "💬 Respuesta a tu reporte {$ref}:\n\n{$respuesta}" : null;
				case 'accept':  return "✅ Tu reporte {$ref} fue recibido y aceptado." . $extra;
			}
			return null;
		}

		if ( 'sugerencia' === $tipo ) {
			switch ( $accion ) {
				case 'respond': return '' !== $respuesta ? "💬 Respuesta a tu sugerencia:\n\n{$respuesta}" : null;
				case 'accept':  return '✅ Tu sugerencia fue aceptada.' . $extra;
				case 'deny':    return '❌ Tu sugerencia fue rechazada.' . $extra;
			}
		}
		return null;
	}

	/**
	 * Envía un mensaje suelto por WhatsApp a un número (p. ej. la respuesta de
	 * coordinación a un reporte/sugerencia desde el panel). Devuelve true si salió.
	 */
	public static function notify( $phone, $message ) {
		$phone = preg_replace( '/[^0-9]/', '', (string) $phone );
		if ( strlen( $phone ) < 7 || trim( (string) $message ) === '' ) {
			return false;
		}
		$store  = new Cead_Acad_WA_Store();
		$bridge = new Cead_Acad_WA_Bridge_Client( $store );
		$res    = $bridge->send_message( $phone, $message );
		$ok     = is_array( $res ) && ! empty( $res['sent'] );
		if ( $ok ) {
			$store->log( $phone, 'out', $message, 'panel_reply' );
		}
		return $ok;
	}
}
