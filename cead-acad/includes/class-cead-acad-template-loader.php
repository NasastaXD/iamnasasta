<?php
/**
 * Carga templates desde el plugin permitiendo override en el tema activo:
 *   <theme>/cead-acad/<ruta>.php  ←  override (gana)
 *   <plugin>/templates/<ruta>.php ←  fallback
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Template_Loader {

	public function locate( $relative ) {
		$relative = ltrim( $relative, '/' );
		$candidates = [
			trailingslashit( get_stylesheet_directory() ) . 'cead-acad/' . $relative,
			trailingslashit( get_template_directory() )   . 'cead-acad/' . $relative,
			CEAD_ACAD_DIR . 'templates/' . $relative,
		];
		foreach ( $candidates as $path ) {
			if ( file_exists( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	public function render( $relative, $args = [] ) {
		$path = $this->locate( $relative );
		if ( ! $path ) {
			return;
		}
		if ( is_array( $args ) && $args ) {
			extract( $args, EXTR_SKIP );
		}
		include $path;
	}
}
