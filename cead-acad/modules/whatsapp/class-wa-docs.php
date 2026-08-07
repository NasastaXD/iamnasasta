<?php
/**
 * Lectura de documentos que llegan por WhatsApp (PDF, Word, texto plano).
 *
 * La idea es que CEADI pueda LEER una circular, una resolución o una nota y
 * responder sobre su contenido, sin que nadie tenga que copiar y pegar el texto.
 *
 * Se extrae el texto acá, en PHP, y se le pasa a la IA como contexto. No se
 * manda el archivo al proveedor: es más barato, más rápido, y no requiere que el
 * modelo acepte adjuntos.
 *
 * Alcance real, sin promesas de más:
 *   - .docx / .odt → fiable (son ZIP con XML adentro; se lee el XML).
 *   - .txt / .csv / .md → trivial.
 *   - .pdf → se leen los PDF "de texto" (los que genera Word, LibreOffice, un
 *     sistema). Un PDF ESCANEADO es una foto adentro de un PDF: no tiene texto
 *     que extraer y hace falta OCR, que esto no hace. En ese caso se avisa y se
 *     pide que manden la foto directo (ahí sí la mira el modelo con visión).
 *   - .doc / .xls viejos (binarios) → no; el bridge ya avisa que los conviertan.
 *
 * Las planillas de notas (.xlsx) NO pasan por acá: tienen su propio camino en
 * Cead_Acad_Grades_Sheet, que las entiende como datos y no como texto.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Docs {

	/** Tope del archivo que se intenta leer, en bytes reales. */
	const MAX_BYTES = 8388608; // 8 MB (el mismo tope que aplica el bridge)

	/** Tope de texto que se le pasa a la IA, en caracteres. */
	const MAX_CHARS = 12000;

	/** ¿La lectura de documentos está activa? */
	public static function enabled() {
		return (bool) get_option( 'cead_acad_wa_docs_enabled', 0 );
	}

	/**
	 * ¿Este adjunto es un documento de los que sabemos leer? No incluye .xlsx:
	 * esa es una planilla y tiene su propio flujo.
	 */
	public static function is_document( $media ) {
		return '' !== self::kind( $media );
	}

	/**
	 * Tipo de documento: 'pdf', 'docx', 'odt', 'text' o '' si no es ninguno.
	 * Mira el mime y, si no alcanza, la extensión: WhatsApp etiqueta los
	 * adjuntos de forma muy despareja según el teléfono que los mande.
	 */
	public static function kind( $media ) {
		if ( ! is_array( $media ) ) { return ''; }
		$mime = strtolower( trim( (string) ( $media['mime'] ?? '' ) ) );
		$name = strtolower( trim( (string) ( $media['filename'] ?? '' ) ) );

		if ( 'application/pdf' === $mime || self::ends_with( $name, '.pdf' ) ) { return 'pdf'; }
		if ( false !== strpos( $mime, 'wordprocessingml' ) || self::ends_with( $name, '.docx' ) ) { return 'docx'; }
		if ( false !== strpos( $mime, 'opendocument.text' ) || self::ends_with( $name, '.odt' ) ) { return 'odt'; }

		// Texto plano. Se excluye el CSV: eso es una planilla, no un documento.
		if ( self::ends_with( $name, '.csv' ) ) { return ''; }
		if ( 0 === strpos( $mime, 'text/' ) || self::ends_with( $name, '.txt' ) || self::ends_with( $name, '.md' ) ) {
			return 'text';
		}
		return '';
	}

	/**
	 * Extrae el texto del documento.
	 *
	 * Devuelve [ 'ok' => bool, 'text' => string, 'kind' => string,
	 *            'filename' => string, 'reason' => string ].
	 * `reason` explica en castellano por qué no se pudo, para poder decírselo a
	 * la persona en vez de fallar en silencio.
	 */
	public static function extract( $media ) {
		$kind = self::kind( $media );
		$name = (string) ( $media['filename'] ?? '' );
		$fail = static function ( $reason ) use ( $kind, $name ) {
			return [ 'ok' => false, 'text' => '', 'kind' => $kind, 'filename' => $name, 'reason' => $reason ];
		};

		if ( '' === $kind ) { return $fail( 'No es un documento que pueda leer.' ); }
		if ( empty( $media['data_base64'] ) ) { return $fail( 'El archivo llegó vacío.' ); }

		$bytes = base64_decode( (string) $media['data_base64'], true );
		if ( false === $bytes || '' === $bytes ) { return $fail( 'El archivo llegó dañado.' ); }
		if ( strlen( $bytes ) > self::MAX_BYTES ) { return $fail( 'El archivo es muy pesado.' ); }

		switch ( $kind ) {
			case 'text':
				$text = self::clean( $bytes );
				break;
			case 'docx':
				$text = self::from_zip_xml( $bytes, 'word/document.xml' );
				break;
			case 'odt':
				$text = self::from_zip_xml( $bytes, 'content.xml' );
				break;
			case 'pdf':
				$text = self::from_pdf( $bytes );
				break;
			default:
				$text = '';
		}

		if ( '' === trim( (string) $text ) ) {
			// El caso frecuente y con solución: el PDF es un escaneo.
			$reason = ( 'pdf' === $kind )
				? 'El PDF no tiene texto: parece un escaneo (una foto adentro de un PDF).'
				: 'No pude sacar texto del archivo.';
			return $fail( $reason );
		}

		return [
			'ok'       => true,
			'text'     => self::truncate( $text ),
			'kind'     => $kind,
			'filename' => $name,
			'reason'   => '',
		];
	}

	/** Etiqueta legible del tipo, para hablarle a la persona. */
	public static function kind_label( $kind ) {
		$labels = [
			'pdf'  => 'PDF',
			'docx' => 'documento de Word',
			'odt'  => 'documento de texto',
			'text' => 'archivo de texto',
		];
		return $labels[ $kind ] ?? 'documento';
	}

	/* ------------------------------------------------------------------ */

	/**
	 * .docx y .odt son archivos ZIP con un XML adentro. Se abre el ZIP, se lee
	 * ese XML y se lo convierte a texto respetando los saltos de párrafo.
	 */
	protected static function from_zip_xml( $bytes, $entry ) {
		if ( ! class_exists( 'ZipArchive' ) ) { return ''; }

		$tmp = wp_tempnam( 'cead-doc' );
		if ( ! $tmp ) { return ''; }
		if ( false === file_put_contents( $tmp, $bytes ) ) {
			@unlink( $tmp );
			return '';
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp ) ) {
			@unlink( $tmp );
			return '';
		}
		$xml = $zip->getFromName( $entry );
		$zip->close();
		@unlink( $tmp );

		if ( false === $xml || '' === $xml ) { return ''; }
		return self::xml_to_text( $xml );
	}

	/**
	 * XML de Word/ODT a texto plano. Los saltos de párrafo y de línea del
	 * documento se convierten en saltos reales antes de borrar las etiquetas;
	 * si no, todo el documento queda pegado en un solo renglón ilegible.
	 */
	protected static function xml_to_text( $xml ) {
		// Fin de párrafo (Word: </w:p>, ODT: </text:p> y </text:h>).
		$xml = preg_replace( '#</(w:p|text:p|text:h)>#i', "\n", $xml );
		// Saltos de línea sueltos y tabulaciones dentro del párrafo.
		$xml = preg_replace( '#<(w:br|w:cr|text:line-break)\s*/?>#i', "\n", $xml );
		$xml = preg_replace( '#<(w:tab|text:tab)\s*/?>#i', "\t", $xml );
		// Fin de celda de tabla: separador, para que una tabla no quede pegada.
		$xml = preg_replace( '#</(w:tc|table:table-cell)>#i', "\t", $xml );

		$text = wp_strip_all_tags( $xml );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		return self::clean( $text );
	}

	/**
	 * Texto de un PDF "de texto". Se recorren los streams del archivo, se
	 * descomprimen los que estén en Flate y se juntan los operadores de texto
	 * (Tj y TJ). Alcanza para lo que manda una institución: circulares,
	 * resoluciones y notas generadas desde Word o un sistema.
	 *
	 * Un PDF escaneado no tiene ninguno de estos operadores y devuelve '', que
	 * es justo lo que hace falta para poder avisar que se necesita OCR.
	 */
	protected static function from_pdf( $bytes ) {
		if ( ! preg_match_all( '/stream\r?\n?(.*?)endstream/s', $bytes, $m ) ) { return ''; }

		$out = '';
		foreach ( $m[1] as $stream ) {
			$data = trim( $stream, "\r\n" );
			if ( '' === $data ) { continue; }

			// La gran mayoría viene con FlateDecode. El @ es a propósito: un
			// stream que no sea Flate (una imagen, una fuente) hace ruido y no
			// es un error que haya que reportar, simplemente no es texto.
			if ( function_exists( 'gzuncompress' ) ) {
				$un = @gzuncompress( $data );
				if ( false !== $un ) { $data = $un; }
			}
			if ( false === strpos( $data, 'Tj' ) && false === strpos( $data, 'TJ' ) ) { continue; }
			$out .= self::pdf_ops_to_text( $data ) . "\n";
		}
		return self::clean( $out );
	}

	/**
	 * Junta el texto de un stream de PDF. Hay dos formas de mostrar texto:
	 *
	 *   (Hola mundo) Tj              → una cadena sola
	 *   [(Ho) -250 (la mun) 20 (do)] TJ → un array, donde los números son ajustes
	 *                                     de espaciado entre letras (kerning)
	 *
	 * La segunda es la que usa Word, y hay que juntar TODOS los pedazos: si se
	 * toma solo el último, las palabras salen mutiladas.
	 *
	 * Td, TD, T* y ET marcan renglón nuevo, así que se traducen a un salto.
	 */
	protected static function pdf_ops_to_text( $data ) {
		$re = '/\[((?:\\\\.|[^\\\\\[\]])*)\]\s*TJ'   // array TJ
			. '|\(((?:\\\\.|[^\\\\()])*)\)\s*Tj'      // cadena Tj
			. '|(?:T\*|Td|TD|ET)/s';                  // renglón nuevo

		if ( ! preg_match_all( $re, $data, $mm, PREG_SET_ORDER ) ) { return ''; }

		$text = '';
		foreach ( $mm as $m ) {
			$head = isset( $m[0][0] ) ? $m[0][0] : '';
			if ( '[' === $head ) {
				// Todos los literales de adentro del array, en orden.
				if ( preg_match_all( '/\(((?:\\\\.|[^\\\\()])*)\)/s', $m[1], $lit ) ) {
					foreach ( $lit[1] as $l ) { $text .= self::pdf_unescape( $l ); }
				}
			} elseif ( '(' === $head ) {
				$text .= self::pdf_unescape( $m[2] ?? '' );
			} else {
				$text .= "\n";
			}
		}
		return $text;
	}

	/** Secuencias de escape de una cadena literal de PDF. */
	protected static function pdf_unescape( $s ) {
		$map = [
			'\\n' => "\n", '\\r' => "\r", '\\t' => "\t",
			'\\(' => '(',  '\\)' => ')',  '\\\\' => '\\',
		];
		$s = strtr( $s, $map );
		// Octales tipo \350 (acentos en algunas codificaciones).
		$s = preg_replace_callback( '/\\\\([0-7]{1,3})/', static function ( $m ) {
			return chr( octdec( $m[1] ) );
		}, $s );
		return $s;
	}

	/** Normaliza espacios y saltos, y se asegura de devolver UTF-8 válido. */
	protected static function clean( $text ) {
		$text = (string) $text;
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			// Los PDF viejos suelen venir en Latin-1.
			$conv = @iconv( 'Windows-1252', 'UTF-8//IGNORE', $text );
			$text = ( false !== $conv ) ? $conv : mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		// Caracteres de control que ensucian el prompt (deja \n y \t).
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		return trim( (string) $text );
	}

	/** Corta el texto al tope, avisando que se cortó. */
	protected static function truncate( $text ) {
		if ( mb_strlen( $text ) <= self::MAX_CHARS ) { return $text; }
		return mb_substr( $text, 0, self::MAX_CHARS )
			. "\n\n[...] (El documento sigue: se leyó solo el principio.)";
	}

	protected static function ends_with( $haystack, $needle ) {
		$len = strlen( $needle );
		return $len > 0 && substr( $haystack, -$len ) === $needle;
	}
}
