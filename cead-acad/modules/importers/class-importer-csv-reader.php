<?php
/**
 * Lector CSV con sniff de delimitador y detección de header.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Importer_Csv_Reader {

	const MAX_ROWS = 5000;

	/**
	 * Sniff del delimitador en la primera línea no vacía.
	 */
	public static function sniff_delimiter( $path ) {
		$fh = @fopen( $path, 'r' );
		if ( ! $fh ) { return ','; }
		$line = '';
		while ( ! feof( $fh ) ) {
			$l = fgets( $fh );
			if ( false === $l ) { break; }
			if ( trim( $l ) !== '' ) { $line = $l; break; }
		}
		fclose( $fh );
		if ( ! $line ) { return ','; }

		$candidates = [ ',', ';', "\t", '|' ];
		$best = ',';
		$best_count = -1;
		foreach ( $candidates as $d ) {
			$c = substr_count( $line, $d );
			if ( $c > $best_count ) {
				$best_count = $c;
				$best = $d;
			}
		}
		return $best;
	}

	/**
	 * Lee todas las filas. Devuelve [ headers[], rows[][] ].
	 * Asume encabezado en la primera fila (porque sniff_header lo asume).
	 *
	 * @return array{0:string[],1:array<int,array<int,string>>}
	 */
	public static function read_all( $path, $delimiter = null, $has_header = true ) {
		if ( null === $delimiter ) {
			$delimiter = self::sniff_delimiter( $path );
		}
		$fh = @fopen( $path, 'r' );
		if ( ! $fh ) {
			return [ [], [] ];
		}
		// Strip BOM if present.
		$bom = fread( $fh, 3 );
		if ( $bom !== "\xEF\xBB\xBF" ) {
			rewind( $fh );
		}
		$headers = [];
		$rows    = [];
		$count   = 0;
		while ( ! feof( $fh ) ) {
			// Escape explícito: el default de fgetcsv() está deprecado en PHP 8.4.
			$row = fgetcsv( $fh, 0, $delimiter, '"', '\\' );
			if ( ! is_array( $row ) ) { continue; }
			if ( count( $row ) === 1 && null === ( $row[0] ?? null ) ) { continue; }
			if ( $has_header && empty( $headers ) ) {
				$headers = array_map( [ __CLASS__, 'clean_cell' ], $row );
				continue;
			}
			$row = array_map( [ __CLASS__, 'clean_cell' ], $row );
			$rows[] = $row;
			$count++;
			if ( $count >= self::MAX_ROWS ) { break; }
		}
		fclose( $fh );
		return [ $headers, $rows ];
	}

	public static function preview( $path, $n = 20 ) {
		[ $headers, $rows ] = self::read_all( $path );
		return [ $headers, array_slice( $rows, 0, $n ) ];
	}

	protected static function clean_cell( $cell ) {
		$cell = (string) $cell;
		// Quitar CSV injection prefix si vino.
		if ( $cell !== '' && in_array( $cell[0], [ '=', '+', '@' ], true ) ) {
			$cell = ltrim( $cell, "=+@" );
		}
		return trim( $cell );
	}
}
