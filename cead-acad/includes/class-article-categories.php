<?php
/**
 * Las categorías que puede llevar una publicación del sitio, y cómo resolver
 * un nombre escrito a mano (o por la IA) a la categoría real.
 *
 * Estaba adentro del motor de WhatsApp, en dos métodos privados. Salió acá
 * cuando el extractor de Instagram necesitó lo mismo: la alternativa era
 * copiar el criterio, y dos copias de una comparación difusa se separan sin que
 * nadie lo note — una acepta «deporte» por «Deportes» y la otra no, y la nota
 * queda sin categoría según por dónde entró.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Article_Categories {

	/**
	 * Las categorías temáticas: term_id => nombre.
	 *
	 * Se saltea la de redes sociales (que es un canal, no un tema) y la de
	 * «sin categoría», que no le dice nada a nadie.
	 *
	 * @return array<int,string>
	 */
	public static function listar() {
		$social = (string) get_option( 'cead_acad_wa_social_category', 'redes-sociales' );
		$social = sanitize_title( $social ) ?: 'redes-sociales';

		$terms = get_terms( [
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'number'     => 30,
			'orderby'    => 'name',
		] );
		if ( is_wp_error( $terms ) ) { return []; }

		$out = [];
		foreach ( $terms as $t ) {
			if ( $t->slug === $social || $t->slug === 'uncategorized' || $t->slug === 'sin-categoria' ) { continue; }
			$out[ (int) $t->term_id ] = $t->name;
		}
		return $out;
	}

	/**
	 * Resuelve un nombre al term_id real.
	 *
	 * Compara sin acentos ni mayúsculas y acepta coincidencia parcial
	 * («deporte» → «Deportes»). NUNCA crea categorías nuevas: si no existe, la
	 * nota sale sin categoría en vez de ensuciar la taxonomía con lo que se le
	 * haya ocurrido al modelo.
	 *
	 * @return int term_id, o 0 si no encaja ninguna.
	 */
	public static function resolver( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) { return 0; }

		$norm = static function ( $s ) {
			$s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s;
			return trim( strtolower( $s ) );
		};
		$needle = $norm( $name );
		if ( '' === $needle ) { return 0; }

		$cats = self::listar();
		// Exacta primero: «Arte» no puede caer en «Artes plásticas» solo porque
		// se recorre en otro orden.
		foreach ( $cats as $id => $label ) {
			if ( $norm( $label ) === $needle ) { return (int) $id; }
		}
		foreach ( $cats as $id => $label ) {
			$hay = $norm( $label );
			if ( str_contains( $hay, $needle ) || str_contains( $needle, $hay ) ) { return (int) $id; }
		}
		return 0;
	}
}
