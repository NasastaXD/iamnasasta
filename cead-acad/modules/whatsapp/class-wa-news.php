<?php
/**
 * Lo que se publicó en el sitio, para que CEADI lo tenga presente.
 *
 * Antes de esto el bot no sabía nada de las noticias: se publicaba una nota y
 * si alguien preguntaba «¿qué pasó con el torneo?» contestaba que no tenía
 * información, aunque estuviera publicado hace dos días.
 *
 * Van dos caminos, por costo:
 * - `digest()` es el resumen de lo reciente y se le pega al prompt en TODOS los
 *   turnos, así que está cacheado y acotado. Es lo que hace que conteste sin
 *   buscar cuando la respuesta es de esta semana.
 * - `search()` sale a buscar de verdad y solo corre cuando el modelo decide
 *   usar la herramienta. Ahí no hay ventana de fechas: sirve para lo viejo.
 *
 * Solo entra contenido PÚBLICO (`post` y `cead_noticia`). Los comunicados
 * quedan afuera a propósito aunque también sean «novedades»: están segmentados
 * por audiencia y este texto se le muestra igual a todo el mundo, así que
 * meterlos acá filtraría avisos del personal al alumnado. Para eso ya está la
 * acción `comunicados`, que respeta a quién le corresponde cada uno.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_News {

	/** Ventana del resumen, en días. */
	const WINDOW_DAYS = 90;

	/** Cuántas notas entran en el resumen del prompt. */
	const DIGEST_MAX = 25;

	/** Cuántas devuelve una búsqueda. */
	const SEARCH_MAX = 6;

	const CACHE_KEY = 'cead_acad_wa_news_digest';
	const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/** Post types públicos que cuentan como «novedades del colegio». */
	protected static function types() {
		return [ 'post', 'cead_noticia' ];
	}

	/**
	 * Resumen de lo publicado en la ventana. Cacheado: se lee en cada turno de
	 * IA y no vale la pena consultar la base para algo que cambia cuando se
	 * publica una nota, no cuando alguien escribe.
	 */
	public static function digest() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) { return (string) $cached; }

		$posts = get_posts( [
			'post_type'        => self::types(),
			'post_status'      => 'publish',
			'numberposts'      => self::DIGEST_MAX,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'date_query'       => [ [ 'after' => self::WINDOW_DAYS . ' days ago' ] ],
			'suppress_filters' => false,
		] );

		$out = '';
		if ( $posts ) {
			$lines = [];
			foreach ( $posts as $p ) {
				$lines[] = self::line( $p, 22 );
			}
			$out = implode( "\n", $lines );
		}

		set_transient( self::CACHE_KEY, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Busca en todo lo publicado, sin límite de fecha. Devuelve texto listo
	 * para el modelo, o '' si no hay nada.
	 */
	public static function search( $query ) {
		$query = trim( (string) $query );
		if ( '' === $query ) { return ''; }

		$posts = get_posts( [
			'post_type'        => self::types(),
			'post_status'      => 'publish',
			'numberposts'      => self::SEARCH_MAX,
			's'                => $query,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => false,
		] );
		if ( ! $posts ) { return ''; }

		$lines = [];
		foreach ( $posts as $p ) {
			$lines[] = self::line( $p, 45, true );
		}
		return implode( "\n", $lines );
	}

	/** Una nota en una línea: fecha, título, resumen y enlace. */
	protected static function line( $p, $words, $with_link = false ) {
		$fecha = get_the_date( 'j M Y', $p );
		$txt   = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ), $words );
		$line  = '- [' . $fecha . '] ' . get_the_title( $p );
		if ( '' !== $txt ) { $line .= ': ' . $txt; }
		if ( $with_link ) { $line .= ' — ' . get_permalink( $p ); }
		return $line;
	}

	/**
	 * El resumen se rearma cuando se publica o se edita algo, así no hay que
	 * esperar a que venza el caché para que CEADI sepa de una nota nueva.
	 */
	public static function hooks() {
		foreach ( [ 'save_post', 'deleted_post', 'trashed_post' ] as $h ) {
			add_action( $h, [ __CLASS__, 'flush' ] );
		}
	}

	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}
}
