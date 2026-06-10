<?php
/**
 * Facade que despacha al lector correcto (CSV o XLSX) según extensión.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Importer_Reader {

	public static function is_xlsx( $path ) {
		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) === 'xlsx';
	}

	public static function read_all( $path ) {
		if ( self::is_xlsx( $path ) ) {
			return Cead_Acad_Importer_Xlsx_Reader::read_all( $path );
		}
		return Cead_Acad_Importer_Csv_Reader::read_all( $path );
	}

	public static function preview( $path, $n = 20 ) {
		if ( self::is_xlsx( $path ) ) {
			return Cead_Acad_Importer_Xlsx_Reader::preview( $path, $n );
		}
		return Cead_Acad_Importer_Csv_Reader::preview( $path, $n );
	}

	/**
	 * Extensiones aceptadas según disponibilidad del entorno.
	 *
	 * @return string[]
	 */
	public static function allowed_extensions() {
		$ext = [ 'csv', 'txt' ];
		if ( Cead_Acad_Importer_Xlsx_Reader::available() ) {
			$ext[] = 'xlsx';
		}
		return $ext;
	}
}
