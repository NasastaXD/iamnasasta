<?php
/**
 * Planillas de notas que llegan por WhatsApp.
 *
 * La idea es no cambiarle la forma de trabajar al docente: manda la misma
 * planilla que ya usa, el sistema la lee, la interpreta y carga las notas.
 * El archivo NO se archiva: se escribe en un temporal solo porque el lector
 * de xlsx necesita un path, y se borra apenas se leyó. Lo que queda es la
 * grilla en un transient de corta vida, para poder confirmar la carga y
 * responder preguntas sobre ella durante un rato.
 *
 * Formatos: .xlsx y .csv. El .xls viejo (binario) no entra: el lector trabaja
 * sobre zip + XML.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Grades_Sheet {

	/** Cuánto vive la planilla en memoria para trabajar con ella. */
	const TTL = 1800; // 30 min

	const MAX_ROWS = 300;
	const MAX_COLS = 40;

	/* ------------------------------------------------------------ lectura */

	/** ¿El adjunto parece una planilla? */
	public static function looks_like_sheet( $media ) {
		if ( ! is_array( $media ) ) { return false; }
		$mime = strtolower( (string) ( $media['mime'] ?? '' ) );
		$name = strtolower( (string) ( $media['filename'] ?? '' ) );
		if ( str_ends_with( $name, '.xlsx' ) || str_ends_with( $name, '.csv' ) ) { return true; }
		return false !== strpos( $mime, 'spreadsheet' )
			|| false !== strpos( $mime, 'excel' )
			|| false !== strpos( $mime, 'csv' );
	}

	/**
	 * Convierte el adjunto en una grilla de filas. Devuelve WP_Error si no se
	 * puede leer. El temporal se borra siempre, incluso si falla.
	 *
	 * @return array{rows:array,sheets:int}|WP_Error
	 */
	public static function read( $media ) {
		if ( ! is_array( $media ) || empty( $media['data_base64'] ) ) {
			return new WP_Error( 'cead_sheet_empty', __( 'No pude leer el archivo.', 'cead-acad' ) );
		}
		$name = strtolower( (string) ( $media['filename'] ?? '' ) );
		$mime = strtolower( (string) ( $media['mime'] ?? '' ) );
		$bytes = base64_decode( (string) $media['data_base64'], true );
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'cead_sheet_empty', __( 'El archivo llegó vacío.', 'cead-acad' ) );
		}

		$is_csv = str_ends_with( $name, '.csv' ) || false !== strpos( $mime, 'csv' );

		if ( $is_csv ) {
			$rows = self::read_csv_string( $bytes );
			return is_wp_error( $rows ) ? $rows : [ 'rows' => $rows, 'sheets' => 1 ];
		}

		if ( ! class_exists( 'Cead_Acad_Importer_Xlsx_Reader' ) || ! Cead_Acad_Importer_Xlsx_Reader::available() ) {
			return new WP_Error( 'cead_sheet_noreader', __( 'Este servidor no puede abrir archivos de Excel.', 'cead-acad' ) );
		}

		$tmp = wp_tempnam( 'cead-planilla.xlsx' );
		if ( ! $tmp ) {
			return new WP_Error( 'cead_sheet_tmp', __( 'No pude preparar el archivo para leerlo.', 'cead-acad' ) );
		}
		$written = file_put_contents( $tmp, $bytes );
		$sheets  = 1;
		$rows    = null;
		if ( false !== $written ) {
			$sheets = self::count_sheets( $tmp );
			$rows   = Cead_Acad_Importer_Xlsx_Reader::read_all( $tmp );
		}
		// El archivo no se guarda: se borra apenas se leyó.
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! is_array( $rows ) || ! $rows ) {
			return new WP_Error( 'cead_sheet_unreadable', __( 'No pude leer la planilla. ¿Podés guardarla como .xlsx y reenviarla?', 'cead-acad' ) );
		}
		return [ 'rows' => self::trim_grid( $rows ), 'sheets' => $sheets ];
	}

	/** CSV en memoria, detectando el separador más probable. */
	protected static function read_csv_string( $bytes ) {
		$text = self::strip_bom( $bytes );
		if ( ! function_exists( 'mb_check_encoding' ) || ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$conv = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $text, 'UTF-8', 'ISO-8859-1' ) : $text;
			$text = $conv ?: $text;
		}
		$first = strtok( $text, "\n" );
		$best  = ',';
		$count = 0;
		foreach ( [ ',', ';', "\t", '|' ] as $sep ) {
			$n = substr_count( (string) $first, $sep );
			if ( $n > $count ) { $count = $n; $best = $sep; }
		}
		$rows = [];
		$fh   = fopen( 'php://temp', 'r+' );
		if ( ! $fh ) { return new WP_Error( 'cead_sheet_tmp', __( 'No pude leer el CSV.', 'cead-acad' ) ); }
		fwrite( $fh, $text );
		rewind( $fh );
		while ( ( $r = fgetcsv( $fh, 0, $best ) ) !== false ) {
			$rows[] = array_map( static fn( $c ) => trim( (string) $c ), (array) $r );
			if ( count( $rows ) >= self::MAX_ROWS ) { break; }
		}
		fclose( $fh );
		return $rows ? self::trim_grid( $rows ) : new WP_Error( 'cead_sheet_unreadable', __( 'El CSV vino vacío.', 'cead-acad' ) );
	}

	protected static function strip_bom( $s ) {
		return ( 0 === strncmp( $s, "\xEF\xBB\xBF", 3 ) ) ? substr( $s, 3 ) : $s;
	}

	/** Cuántas hojas tiene el libro (para avisar que se leyó la primera). */
	protected static function count_sheets( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) { return 1; }
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) { return 1; }
		$wb = $zip->getFromName( 'xl/workbook.xml' );
		$zip->close();
		if ( ! $wb ) { return 1; }
		return max( 1, substr_count( $wb, '<sheet ' ) );
	}

	/** Recorta filas/columnas vacías y limita el tamaño. */
	protected static function trim_grid( $rows ) {
		$out = [];
		foreach ( array_slice( (array) $rows, 0, self::MAX_ROWS ) as $r ) {
			$r = array_slice( array_values( (array) $r ), 0, self::MAX_COLS );
			$r = array_map( static fn( $c ) => trim( (string) $c ), $r );
			if ( '' !== implode( '', $r ) ) { $out[] = $r; }
		}
		return $out;
	}

	/* --------------------------------------------- memoria de corta vida */

	protected static function key( $phone ) {
		return 'cead_acad_wa_sheet_' . md5( (string) $phone );
	}

	/** Guarda la planilla para trabajar con ella un rato (no la archiva). */
	public static function remember( $phone, array $data ) {
		set_transient( self::key( $phone ), $data, self::TTL );
	}

	public static function recall( $phone ) {
		$d = get_transient( self::key( $phone ) );
		return is_array( $d ) ? $d : null;
	}

	public static function forget( $phone ) {
		delete_transient( self::key( $phone ) );
	}

	/* --------------------------------------------------- vista para la IA */

	/**
	 * Convierte la grilla en texto compacto y numerado para que el modelo pueda
	 * mirarla. Se recorta para no inflar el prompt.
	 */
	public static function to_text( array $rows, $max_rows = 25, $max_len = 3500 ) {
		$out = [];
		foreach ( array_slice( $rows, 0, $max_rows ) as $i => $r ) {
			$cells = array_map( static fn( $c ) => ( '' === $c ? '·' : mb_substr( (string) $c, 0, 40 ) ), $r );
			$out[] = ( $i + 1 ) . ': ' . implode( ' | ', $cells );
		}
		$txt = implode( "\n", $out );
		if ( count( $rows ) > $max_rows ) {
			/* translators: %d: filas restantes */
			$txt .= "\n" . sprintf( __( '… (%d filas más)', 'cead-acad' ), count( $rows ) - $max_rows );
		}
		return mb_substr( $txt, 0, $max_len );
	}

	/* ------------------------------------------------------ interpretación */

	/**
	 * Aplica el mapeo que decidió la IA y arma las filas listas para cargar.
	 * No toca la base: solo resuelve alumnos y calcula notas.
	 *
	 * @param array $rows    Grilla completa.
	 * @param array $mapping [ fila_encabezado, col_alumno, col_nota, formato, puntaje_total ]
	 * @param int   $course_id Curso donde buscar a los alumnos.
	 * @return array{ok:array,fallidas:array,total:int}
	 */
	public static function build_rows( array $rows, array $mapping, $course_id ) {
		$header = max( 0, (int) ( $mapping['fila_encabezado'] ?? 1 ) ); // 1-based; 0 = sin encabezado
		$c_name = (int) ( $mapping['col_alumno'] ?? 1 ) - 1;
		$c_val  = (int) ( $mapping['col_nota'] ?? 2 ) - 1;
		$fmt    = (string) ( $mapping['formato'] ?? 'nota' );
		$total  = $mapping['puntaje_total'] ?? null;

		$ok   = [];
		$bad  = [];
		$seen = [];

		foreach ( $rows as $idx => $r ) {
			$line = $idx + 1;
			if ( $line <= $header ) { continue; }

			$name = trim( (string) ( $r[ $c_name ] ?? '' ) );
			$raw  = trim( (string) ( $r[ $c_val ] ?? '' ) );
			if ( '' === $name ) { continue; }
			// Una fila de totales/promedios no es un alumno.
			if ( preg_match( '/^(promedio|total|media|observaciones?)\b/i', $name ) ) { continue; }

			if ( '' === $raw ) {
				$bad[] = [ 'linea' => $line, 'nombre' => $name, 'motivo' => __( 'sin nota', 'cead-acad' ) ];
				continue;
			}

			$score = self::value_to_score( $raw, $fmt, $total );
			if ( null === $score ) {
				$bad[] = [ 'linea' => $line, 'nombre' => $name, 'motivo' => __( 'nota ilegible', 'cead-acad' ) ];
				continue;
			}

			$hit = Cead_Acad_Grades_Writer::match_student_in_course( (int) $course_id, $name );
			if ( 'exact' !== $hit['status'] ) {
				$bad[] = [
					'linea'  => $line,
					'nombre' => $name,
					'motivo' => ( 'ambiguous' === $hit['status'] )
						? __( 'hay más de un alumno con ese nombre', 'cead-acad' )
						: __( 'no está en el curso', 'cead-acad' ),
				];
				continue;
			}
			$sid = (int) $hit['matches'][0]['id'];
			// Un mismo alumno dos veces en la planilla: se avisa, no se pisa.
			if ( isset( $seen[ $sid ] ) ) {
				$bad[] = [ 'linea' => $line, 'nombre' => $name, 'motivo' => __( 'repetido en la planilla', 'cead-acad' ) ];
				continue;
			}
			$seen[ $sid ] = true;

			$ok[] = [
				'student_id' => $sid,
				'nombre'     => (string) $hit['matches'][0]['name'],
				'score'      => $score,
				'origen'     => $raw,
			];
		}

		return [ 'ok' => $ok, 'fallidas' => $bad, 'total' => count( $ok ) + count( $bad ) ];
	}

	/** Convierte el valor de una celda en nota de la escala del colegio. */
	public static function value_to_score( $raw, $formato = 'nota', $puntaje_total = null ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) { return null; }
		$raw = str_replace( ',', '.', $raw );

		// «45/60» dentro de la misma celda.
		if ( preg_match( '#^(\d+(?:\.\d+)?)\s*/\s*(\d+(?:\.\d+)?)$#', $raw, $m ) ) {
			$pct = Cead_Acad_Grades_Writer::points_to_percent( $m[1], $m[2] );
			return ( null === $pct ) ? null : Cead_Acad_Grades_Writer::percent_to_score( $pct );
		}
		// «75%» explícito en la celda.
		if ( preg_match( '/^(\d+(?:\.\d+)?)\s*%$/', $raw, $m ) ) {
			return Cead_Acad_Grades_Writer::percent_to_score( $m[1] );
		}
		if ( ! is_numeric( $raw ) ) { return null; }

		$n = (float) $raw;
		if ( 'porcentaje' === $formato ) {
			return Cead_Acad_Grades_Writer::percent_to_score( $n );
		}
		if ( 'puntaje' === $formato && is_numeric( $puntaje_total ) ) {
			$pct = Cead_Acad_Grades_Writer::points_to_percent( $n, $puntaje_total );
			return ( null === $pct ) ? null : Cead_Acad_Grades_Writer::percent_to_score( $pct );
		}
		// Nota directa: tiene que caer dentro de la escala.
		$max = Cead_Acad_Grades_Writer::score_max();
		if ( $n < 0 || $n > $max ) { return null; }
		return round( $n, 2 );
	}
}
