<?php
/**
 * Lector XLSX liviano (sin PhpSpreadsheet). Usa ZipArchive + SimpleXML.
 * Lee la primera hoja. Resuelve sharedStrings e inlineStr.
 *
 * Limitación conocida: las fechas en XLSX se guardan como número de serie de
 * Excel; para columnas de fecha conviene que el archivo las tenga como texto
 * (ej. "2026-12-10 08:00"). Para nombres, emails, cursos, notas — funciona bien.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Importer_Xlsx_Reader {

	const MAX_ROWS = 5000;

	public static function available() {
		return class_exists( 'ZipArchive' ) && function_exists( 'simplexml_load_string' );
	}

	/**
	 * @return array{0:string[],1:array<int,array<int,string>>}
	 */
	public static function read_all( $path ) {
		if ( ! self::available() ) {
			return [ [], [] ];
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return [ [], [] ];
		}

		// Shared strings.
		$shared = [];
		$ss = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $ss ) {
			$xml = @simplexml_load_string( $ss );
			if ( $xml && isset( $xml->si ) ) {
				foreach ( $xml->si as $si ) {
					$shared[] = self::si_text( $si );
				}
			}
		}

		// Resolver el archivo de la primera hoja vía workbook + rels (robusto),
		// con fallback a sheet1.xml.
		$sheet_path = self::first_sheet_path( $zip );
		$sheet = $zip->getFromName( $sheet_path );
		if ( false === $sheet ) {
			$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		}
		$zip->close();
		if ( false === $sheet ) {
			return [ [], [] ];
		}

		$xml = @simplexml_load_string( $sheet );
		if ( ! $xml || ! isset( $xml->sheetData ) ) {
			return [ [], [] ];
		}

		$rows  = [];
		$count = 0;
		foreach ( $xml->sheetData->row as $row ) {
			$cells   = [];
			$max_col = -1;
			foreach ( $row->c as $c ) {
				$ref  = (string) $c['r'];
				$col  = $ref ? self::col_index( $ref ) : ( $max_col + 1 );
				$type = (string) $c['t'];
				$val  = '';
				if ( 's' === $type ) {
					$idx = (int) $c->v;
					$val = $shared[ $idx ] ?? '';
				} elseif ( 'inlineStr' === $type ) {
					$val = isset( $c->is ) ? self::si_text( $c->is ) : '';
				} else {
					$val = isset( $c->v ) ? (string) $c->v : '';
				}
				$cells[ $col ] = trim( $val );
				$max_col = max( $max_col, $col );
			}
			$row_arr = [];
			for ( $i = 0; $i <= $max_col; $i++ ) {
				$row_arr[ $i ] = $cells[ $i ] ?? '';
			}
			// Saltar filas totalmente vacías.
			if ( '' !== implode( '', $row_arr ) ) {
				$rows[] = $row_arr;
				$count++;
				if ( $count >= self::MAX_ROWS ) { break; }
			}
		}

		$headers = $rows ? array_shift( $rows ) : [];
		$headers = array_map( 'strval', (array) $headers );
		return [ $headers, $rows ];
	}

	public static function preview( $path, $n = 20 ) {
		[ $headers, $rows ] = self::read_all( $path );
		return [ $headers, array_slice( $rows, 0, $n ) ];
	}

	protected static function first_sheet_path( $zip ) {
		$wb = $zip->getFromName( 'xl/workbook.xml' );
		$rels = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		if ( false === $wb || false === $rels ) {
			return 'xl/worksheets/sheet1.xml';
		}
		$wb_xml = @simplexml_load_string( $wb );
		$rels_xml = @simplexml_load_string( $rels );
		if ( ! $wb_xml || ! $rels_xml ) {
			return 'xl/worksheets/sheet1.xml';
		}
		// Primer sheet → r:id.
		$rid = '';
		if ( isset( $wb_xml->sheets->sheet ) ) {
			$first = $wb_xml->sheets->sheet[0];
			foreach ( $first->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' ) as $k => $v ) {
				if ( 'id' === $k ) { $rid = (string) $v; }
			}
		}
		if ( ! $rid ) {
			return 'xl/worksheets/sheet1.xml';
		}
		foreach ( $rels_xml->Relationship as $rel ) {
			if ( (string) $rel['Id'] === $rid ) {
				$target = (string) $rel['Target'];
				$target = ltrim( $target, '/' );
				if ( strpos( $target, 'xl/' ) !== 0 ) {
					$target = 'xl/' . $target;
				}
				return $target;
			}
		}
		return 'xl/worksheets/sheet1.xml';
	}

	protected static function si_text( $si ) {
		$text = '';
		if ( isset( $si->t ) ) {
			$text .= (string) $si->t;
		}
		if ( isset( $si->r ) ) {
			foreach ( $si->r as $r ) {
				$text .= (string) $r->t;
			}
		}
		return $text;
	}

	protected static function col_index( $ref ) {
		if ( ! preg_match( '/^([A-Z]+)/', $ref, $m ) ) {
			return 0;
		}
		$letters = $m[1];
		$num = 0;
		$len = strlen( $letters );
		for ( $i = 0; $i < $len; $i++ ) {
			$num = $num * 26 + ( ord( $letters[ $i ] ) - ord( 'A' ) + 1 );
		}
		return $num - 1;
	}
}
