<?php
/**
 * Memoria persistente de CEADI.
 *
 * Es otra cosa que la memoria de conversación de Cead_Acad_WA_AI: aquella son
 * los últimos turnos de UNA charla, viven en un transient y se vencen solos.
 * Esto son hechos que el colegio quiere que CEADI sepa siempre — «las clases
 * empiezan 7:10», «la coordinadora de tercero es X» — y valen para todo el
 * mundo, no para un número.
 *
 * Se guardan en una option y no en un CPT a propósito: esto se lee en CADA
 * turno de IA para armar el prompt, así que tiene que costar una sola lectura
 * ya cacheada y no una consulta a posts. Por eso también está acotado: entran
 * MAX entradas y cada una se recorta, para que la memoria no se coma el
 * contexto ni crezca sin techo.
 *
 * Escribir acá lo cambia para todos, así que el permiso lo controla quien
 * llama (el motor solo se lo ofrece a administradores).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Memory {

	const OPTION = 'cead_acad_wa_ai_memories';

	/** Techo de entradas. Al llegar, una nueva desplaza a la más vieja. */
	const MAX = 40;

	/** Largo máximo de cada entrada, en caracteres. */
	const MAX_LEN = 300;

	/** Todas las memorias, de la más nueva a la más vieja. */
	public static function all() {
		$raw = get_option( self::OPTION, [] );
		if ( ! is_array( $raw ) ) { return []; }
		$out = [];
		foreach ( $raw as $m ) {
			if ( ! is_array( $m ) || empty( $m['text'] ) ) { continue; }
			$out[] = [
				'id'      => (string) ( $m['id'] ?? '' ),
				'text'    => (string) $m['text'],
				'created' => (int) ( $m['created'] ?? 0 ),
				'author'  => (int) ( $m['author'] ?? 0 ),
			];
		}
		return $out;
	}

	/**
	 * Guarda una memoria. Devuelve el id, o WP_Error si el texto no sirve o ya
	 * estaba. Duplicar se rechaza en vez de acumular: si CEADI vuelve a proponer
	 * algo que ya sabe, no tiene sentido tenerlo dos veces en el prompt.
	 */
	public static function add( $text, $author = 0 ) {
		$text = self::clean( $text );
		if ( '' === $text ) {
			return new WP_Error( 'empty', __( 'La memoria está vacía.', 'cead-acad' ) );
		}
		$list = self::all();
		foreach ( $list as $m ) {
			if ( 0 === strcasecmp( $m['text'], $text ) ) {
				return new WP_Error( 'duplicate', __( 'Eso ya lo tengo guardado.', 'cead-acad' ) );
			}
		}
		$id = uniqid( 'm', false );
		array_unshift( $list, [
			'id'      => $id,
			'text'    => $text,
			'created' => time(),
			'author'  => (int) $author,
		] );
		self::save( array_slice( $list, 0, self::MAX ) );
		return $id;
	}

	/**
	 * Borra por id o por texto. Se acepta el texto porque por WhatsApp nadie va
	 * a tipear un id: se dice «olvidate de lo del horario». Con el texto se
	 * busca la coincidencia parcial y, si hay más de una, no se borra ninguna y
	 * se devuelven las candidatas para que la persona elija.
	 */
	public static function remove( $needle ) {
		$hit = self::locate( $needle );
		if ( is_wp_error( $hit ) ) { return $hit; }
		$list = self::all();
		unset( $list[ $hit['index'] ] );
		self::save( array_values( $list ) );
		return $hit['text'];
	}

	/**
	 * Qué borraría `remove()`, sin borrarlo. Sirve para confirmar mostrando el
	 * texto real de la memoria y no lo que la persona escribió de memoria.
	 * Comparte el resolvedor con `remove()` a propósito: si la búsqueda cambia,
	 * lo que se confirma y lo que se borra no pueden quedar desalineados.
	 */
	public static function remove_preview( $needle ) {
		$hit = self::locate( $needle );
		return is_wp_error( $hit ) ? $hit : $hit['text'];
	}

	/** Ubica una memoria por id o por texto. Devuelve [index, text] o WP_Error. */
	protected static function locate( $needle ) {
		$needle = trim( (string) $needle );
		if ( '' === $needle ) {
			return new WP_Error( 'empty', __( 'No me dijiste qué olvidar.', 'cead-acad' ) );
		}
		$list = self::all();
		if ( ! $list ) {
			return new WP_Error( 'not_found', __( 'No tengo memorias guardadas.', 'cead-acad' ) );
		}

		foreach ( $list as $i => $m ) {
			if ( $m['id'] === $needle ) {
				return [ 'index' => $i, 'text' => $m['text'] ];
			}
		}

		$hits = [];
		foreach ( $list as $i => $m ) {
			if ( false !== stripos( $m['text'], $needle ) ) { $hits[ $i ] = $m['text']; }
		}
		if ( ! $hits ) {
			return new WP_Error( 'not_found', __( 'No encontré ninguna memoria que diga eso.', 'cead-acad' ) );
		}
		if ( count( $hits ) > 1 ) {
			return new WP_Error( 'ambiguous', __( 'Hay varias que coinciden.', 'cead-acad' ), array_values( $hits ) );
		}
		$i = array_key_first( $hits );
		return [ 'index' => $i, 'text' => $hits[ $i ] ];
	}

	/** Vacía la memoria entera. Devuelve cuántas se borraron. */
	public static function clear() {
		$n = count( self::all() );
		self::save( [] );
		return $n;
	}

	/**
	 * Bloque para el prompt. Vacío si no hay nada, así no se gasta contexto en
	 * un encabezado sin contenido.
	 */
	public static function context() {
		$list = self::all();
		if ( ! $list ) { return ''; }
		$out = [];
		foreach ( $list as $m ) { $out[] = '- ' . $m['text']; }
		return implode( "\n", $out );
	}

	/** Listado numerado, para mostrarle las memorias a la persona por chat. */
	public static function render_list() {
		$list = self::all();
		if ( ! $list ) {
			return __( 'No tengo memorias guardadas todavía.', 'cead-acad' );
		}
		$out = [ sprintf( __( '🧠 *Lo que tengo guardado* (%d)', 'cead-acad' ), count( $list ) ), '' ];
		foreach ( $list as $i => $m ) {
			$out[] = sprintf( '*%d.* %s', $i + 1, $m['text'] );
		}
		return implode( "\n", $out );
	}

	/** Resuelve «la 2» de la lista de arriba a su texto. '' si no existe. */
	public static function text_at( $position ) {
		$list = self::all();
		$i    = (int) $position - 1;
		return isset( $list[ $i ] ) ? $list[ $i ]['text'] : '';
	}

	protected static function clean( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, self::MAX_LEN );
		}
		return substr( $text, 0, self::MAX_LEN );
	}

	protected static function save( array $list ) {
		// Sin autoload: se lee solo en los turnos de IA, no en cada request del
		// sitio, así que no tiene por qué estar en todas las páginas.
		update_option( self::OPTION, $list, false );
	}
}
