<?php
/**
 * Da formato al artículo que escribe CEADI antes de publicarlo.
 *
 * La IA redacta en Markdown (es como escriben los modelos), pero WordPress no
 * lo entiende: publicaba literalmente los asteriscos de las negritas y las
 * tablas con barritas quedaban como un choclo de texto. Acá se traduce a HTML
 * de verdad, con las clases del tema, para que la noticia salga maquetada.
 *
 * Es un conversor acotado a propósito: solo lo que la IA usa de verdad
 * (títulos, negrita, cursiva, listas, tablas, separadores, citas). No pretende
 * ser un Markdown completo.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Article_Format {

	/**
	 * Molde de cada tipo de nota, para que CEADI no tenga que improvisar la
	 * estructura cada vez. Se le pasan al modelo junto con la herramienta de
	 * publicar, así saca la nota armada de una sola pasada.
	 *
	 * La clave es el slug de la categoría del sitio. Lo que no tenga molde
	 * propio usa el general.
	 */
	public static function templates() {
		return [
			'noticias'  => 'Entrada con lo importante (qué pasó o qué va a pasar, cuándo y dónde). '
				. 'Después el desarrollo en secciones con ##. Si hay una frase de Dirección o de un docente, va como cita con >. '
				. 'Cerrá con lo que sigue o dónde consultar.',
			'avisos'    => 'Arrancá con la instrucción concreta, sin vueltas. '
				. 'Después una lista con los datos sueltos (fecha, hora, lugar, qué llevar). '
				. 'Dejá claro a quiénes alcanza (qué cursos o turnos) y hasta cuándo rige.',
			'deportes'  => 'Entrada con el evento y en qué instancia está (fase, semifinal, final). '
				. 'Los partidos SIEMPRE en tabla, con columnas #, Equipo, vs, Equipo y Modalidad. '
				. 'Una sección ## por turno o categoría. Si hay resultados o campeones, destacalos al final.',
			'academico' => 'Entrada con el hecho académico (fechas de examen, entrega de boletines, inicio de periodo). '
				. 'Lista con las fechas y los requisitos. Aclarará qué cursos alcanza. '
				. 'Si hay algo que el alumnado tiene que hacer, ponelo al final y bien visible.',
			'recursos'  => 'Entrada con qué es el recurso y para qué sirve. '
				. 'Aclarará para quién es (curso, materia). Cerrá con el enlace o dónde retirarlo.',
		];
	}

	/**
	 * Las plantillas en texto, listas para meter en la descripción de la
	 * herramienta. Solo se listan las categorías que existen de verdad en el
	 * sitio, para no describirle al modelo secciones que no puede elegir.
	 *
	 * @param string[] $categorias Nombres de categoría disponibles.
	 */
	public static function templates_hint( array $categorias ) {
		$moldes = self::templates();
		$lineas = [];
		foreach ( $categorias as $nombre ) {
			$slug = sanitize_title( $nombre );
			if ( isset( $moldes[ $slug ] ) ) {
				$lineas[] = '- ' . $nombre . ': ' . $moldes[ $slug ];
			}
		}
		if ( ! $lineas ) { return ''; }
		return " Según la categoría, seguí este molde:\n" . implode( "\n", $lineas );
	}

	/**
	 * Markdown → HTML listo para publicar.
	 *
	 * Si el texto ya viene en HTML (por ejemplo, pegado a mano desde el panel)
	 * se devuelve tal cual: no hay que re-procesar lo que ya está maquetado.
	 */
	public static function to_html( $texto ) {
		$texto = trim( (string) $texto );
		if ( '' === $texto ) { return ''; }
		if ( self::looks_like_html( $texto ) ) { return $texto; }

		$texto = str_replace( [ "\r\n", "\r" ], "\n", $texto );
		$lineas = explode( "\n", $texto );

		$out    = [];
		$buffer = [];   // párrafo en curso
		$lista  = null; // 'ul' | 'ol' | null
		$tabla  = [];   // filas de la tabla en curso
		$primer_parrafo = true;

		$cerrar_parrafo = static function () use ( &$buffer, &$out, &$primer_parrafo ) {
			if ( ! $buffer ) { return; }
			$html  = self::inline( implode( ' ', $buffer ) );
			// El primer párrafo hace de bajada: va más grande, como en cualquier
			// noticia impresa.
			$clase = $primer_parrafo ? ' class="cead-lead"' : '';
			$out[] = '<p' . $clase . '>' . $html . '</p>';
			$primer_parrafo = false;
			$buffer = [];
		};
		$cerrar_lista = static function () use ( &$lista, &$out ) {
			if ( ! $lista ) { return; }
			$out[] = '</' . $lista . '>';
			$lista = null;
		};
		$cerrar_tabla = static function () use ( &$tabla, &$out ) {
			if ( ! $tabla ) { return; }
			$out[] = self::render_table( $tabla );
			$tabla = [];
		};

		foreach ( $lineas as $linea ) {
			$l = trim( $linea );

			// Fila de tabla: | a | b | c |
			if ( '' !== $l && '|' === $l[0] && substr( $l, -1 ) === '|' ) {
				$cerrar_parrafo();
				$cerrar_lista();
				$tabla[] = $l;
				continue;
			}
			$cerrar_tabla();

			// Línea vacía: corta el párrafo y la lista.
			if ( '' === $l ) {
				$cerrar_parrafo();
				$cerrar_lista();
				continue;
			}

			// Separador: --- o ***
			if ( preg_match( '/^(\-{3,}|\*{3,}|_{3,})$/', $l ) ) {
				$cerrar_parrafo();
				$cerrar_lista();
				$out[] = '<hr class="cead-sep">';
				continue;
			}

			// Título: #, ##, ###
			if ( preg_match( '/^(#{1,6})\s+(.*)$/u', $l, $m ) ) {
				$cerrar_parrafo();
				$cerrar_lista();
				// El h1 de la página ya es el título de la noticia, así que acá
				// se arranca en h2. Tanto # como ## son «sección» (la IA usa uno
				// u otro sin criterio fijo) y tienen que verse igual; recién
				// desde ### se considera subsección.
				$nivel = ( strlen( $m[1] ) <= 2 ) ? 2 : min( 4, strlen( $m[1] ) );
				$out[] = '<h' . $nivel . '>' . self::inline( $m[2] ) . '</h' . $nivel . '>';
				$primer_parrafo = false;
				continue;
			}

			// Una línea que es solo negrita funciona como subtítulo: la IA
			// escribe así los bloques («**TURNO MAÑANA**»).
			if ( preg_match( '/^\*\*(.+)\*\*:?$/u', $l, $m ) ) {
				$cerrar_parrafo();
				$cerrar_lista();
				$out[] = '<h2>' . self::inline( $m[1] ) . '</h2>';
				$primer_parrafo = false;
				continue;
			}

			// Cita: > texto
			if ( preg_match( '/^>\s?(.*)$/u', $l, $m ) ) {
				$cerrar_parrafo();
				$cerrar_lista();
				$out[] = '<blockquote><p>' . self::inline( $m[1] ) . '</p></blockquote>';
				$primer_parrafo = false;
				continue;
			}

			// Lista numerada: 1. algo
			if ( preg_match( '/^\d+[\.\)]\s+(.*)$/u', $l, $m ) ) {
				$cerrar_parrafo();
				if ( 'ol' !== $lista ) { $cerrar_lista(); $out[] = '<ol>'; $lista = 'ol'; }
				$out[] = '<li>' . self::inline( $m[1] ) . '</li>';
				$primer_parrafo = false;
				continue;
			}

			// Lista con viñetas: - algo / * algo / • algo
			if ( preg_match( '/^[\-\*\x{2022}]\s+(.*)$/u', $l, $m ) ) {
				$cerrar_parrafo();
				if ( 'ul' !== $lista ) { $cerrar_lista(); $out[] = '<ul>'; $lista = 'ul'; }
				$out[] = '<li>' . self::inline( $m[1] ) . '</li>';
				$primer_parrafo = false;
				continue;
			}

			$cerrar_lista();
			$buffer[] = $l;
		}

		$cerrar_parrafo();
		$cerrar_lista();
		$cerrar_tabla();

		return implode( "\n", $out );
	}

	/**
	 * WhatsApp → HTML, para la copia de un comunicado que se ve en el panel.
	 *
	 * Un comunicado es DOS textos con un solo origen: lo que llega al chat de
	 * cada destinatario, tal cual, y lo que queda como post en el panel web.
	 * El mensaje que se manda por WhatsApp NUNCA pasa por acá — sigue siendo
	 * exactamente lo que se escribió, letra por letra. Esto solo genera la
	 * segunda vida de ese mismo texto, así que entiende la sintaxis de
	 * WhatsApp (`*negrita*` con un asterisco) y no la de Markdown de un
	 * artículo (`**negrita**` con dos), que es justo al revés.
	 *
	 * Deliberadamente más chico que `to_html()`: un comunicado no lleva
	 * subtítulos, tablas ni listas — es un mensaje de chat, no una nota.
	 */
	public static function to_html_whatsapp( $texto ) {
		$texto = trim( (string) $texto );
		if ( '' === $texto ) { return ''; }
		if ( self::looks_like_html( $texto ) ) { return $texto; }

		$texto    = str_replace( [ "\r\n", "\r" ], "\n", $texto );
		$parrafos = preg_split( '/\n{2,}/', $texto );

		$html = [];
		foreach ( $parrafos as $p ) {
			$p = trim( $p );
			if ( '' === $p ) { continue; }
			// Un salto de línea SIMPLE adentro de un párrafo es un renglón
			// nuevo del mismo mensaje, no un párrafo aparte — así se ve en
			// WhatsApp, y separarlo en <p> distintos le cambiaría el ritmo.
			$linea  = str_replace( "\n", '<br>', self::inline_whatsapp( $p ) );
			$html[] = '<p>' . $linea . '</p>';
		}
		return implode( "\n", $html );
	}

	/** Como `inline()`, pero con la sintaxis de énfasis de WhatsApp. */
	protected static function inline_whatsapp( $texto ) {
		$t = esc_html( (string) $texto );

		// *negrita* — un asterisco. Con dos sería Markdown de artículo, que
		// nadie escribe a mano en un chat.
		$t = preg_replace( '/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/us', '<strong>$1</strong>', $t );
		// _cursiva_
		$t = preg_replace( '/(?<![\w_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\w_])/us', '<em>$1</em>', $t );
		// ~tachado~ — WhatsApp también lo soporta.
		$t = preg_replace( '/(?<![\w~])~(?!\s)([^~\n]+?)(?<!\s)~(?![\w~])/us', '<del>$1</del>', $t );

		// URLs sueltas, igual que en inline().
		$t = preg_replace_callback( '/(?<!["\'>=])\bhttps?:\/\/[^\s<]+/u', static function ( $m ) {
			$url = esc_url( html_entity_decode( $m[0], ENT_QUOTES, 'UTF-8' ) );
			return '<a href="' . $url . '">' . $url . '</a>';
		}, $t );

		return $t;
	}

	/**
	 * ¿Ya es HTML? Se mira si arranca con una etiqueta de bloque; así el
	 * contenido que alguien pegó ya maquetado no se toca.
	 */
	protected static function looks_like_html( $texto ) {
		return (bool) preg_match( '/^\s*<(p|div|h[1-6]|ul|ol|table|figure|section|!--)\b/i', $texto );
	}

	/** Formato dentro de una línea: negrita, cursiva, código y enlaces. */
	protected static function inline( $texto ) {
		$t = esc_html( (string) $texto );

		// **negrita** y __negrita__
		$t = preg_replace( '/\*\*(.+?)\*\*/us', '<strong>$1</strong>', $t );
		$t = preg_replace( '/(?<![\w*])__(.+?)__(?![\w*])/us', '<strong>$1</strong>', $t );
		// *cursiva* y _cursiva_ (sin comerse los ** ya resueltos)
		$t = preg_replace( '/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/us', '<em>$1</em>', $t );
		$t = preg_replace( '/(?<![\w_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\w_])/us', '<em>$1</em>', $t );
		// `código`
		$t = preg_replace( '/`([^`\n]+)`/u', '<code>$1</code>', $t );
		// [texto](url)
		$t = preg_replace_callback( '/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/u', static function ( $m ) {
			return '<a href="' . esc_url( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ) ) . '">' . $m[1] . '</a>';
		}, $t );
		// URLs sueltas.
		$t = preg_replace_callback( '/(?<!["\'>=])\bhttps?:\/\/[^\s<]+/u', static function ( $m ) {
			$url = esc_url( html_entity_decode( $m[0], ENT_QUOTES, 'UTF-8' ) );
			return '<a href="' . $url . '">' . $url . '</a>';
		}, $t );

		return $t;
	}

	/**
	 * Filas «| a | b |» a una tabla HTML. La fila de guiones (|---|---|) se usa
	 * como señal de que la anterior era el encabezado, y no se imprime.
	 */
	protected static function render_table( array $filas ) {
		$parseada = [];
		$sep_en   = -1;
		foreach ( $filas as $i => $fila ) {
			$celdas = array_map( 'trim', explode( '|', trim( $fila, "| \t" ) ) );
			$solo_guiones = true;
			foreach ( $celdas as $c ) {
				if ( ! preg_match( '/^:?-{2,}:?$/', $c ) ) { $solo_guiones = false; break; }
			}
			if ( $solo_guiones && $celdas ) { $sep_en = $i; continue; }
			$parseada[] = $celdas;
		}
		if ( ! $parseada ) { return ''; }

		$con_encabezado = ( $sep_en === 1 );
		$html = '<figure class="wp-block-table cead-table"><table>';
		if ( $con_encabezado ) {
			$html .= '<thead><tr>';
			foreach ( array_shift( $parseada ) as $c ) {
				$html .= '<th>' . self::inline( $c ) . '</th>';
			}
			$html .= '</tr></thead>';
		}
		$html .= '<tbody>';
		foreach ( $parseada as $fila ) {
			$html .= '<tr>';
			foreach ( $fila as $c ) {
				$html .= '<td>' . self::inline( $c ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table></figure>';
		return $html;
	}
}
