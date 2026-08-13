<?php
/**
 * Qué maqueta le toca a una publicación, del lado del plugin.
 *
 * El catálogo de maquetas NO vive acá: vive en el tema (`cead/inc/notas.php`),
 * porque qué maquetas existen depende de qué archivos hay en
 * `template-parts/nota/`. Este lado solo pregunta, valida y guarda.
 *
 * La consecuencia importante: si el tema no ofrece tipos —está desactivado, es
 * otro, es una versión anterior a esto— acá no se elige nada y todo se publica
 * como siempre. Nunca se guarda un tipo que el tema no sepa dibujar, que sería
 * la forma silenciosa de romper la página: el meta queda escrito, nadie lo ve, y
 * el día que el tema vuelva aparecen notas con maquetas que nadie eligió.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Article_Kind {

	/**
	 * Dónde se guarda.
	 *
	 * Estas tres cadenas están escritas también en el tema, que es quien las
	 * lee. Es una frontera entre dos proyectos y no hay constante compartida
	 * posible, así que `bin/check-symbols.php` cruza que digan lo mismo: si
	 * divergen, el plugin escribiría un meta que el tema nunca busca y las notas
	 * saldrían todas con la maqueta por defecto sin un solo error a la vista.
	 */
	const META       = '_cead_nota_tipo';
	const META_FECHA = '_cead_nota_fecha';
	const META_LUGAR = '_cead_nota_lugar';

	/** El tipo al que se cae cuando el elegido no se sostiene. */
	const DEFECTO = 'noticia';

	/**
	 * El catálogo que publica el tema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function catalogo() {
		$tipos = apply_filters( 'cead_nota_tipos', [] );
		return is_array( $tipos ) ? $tipos : [];
	}

	/** ¿Hay maquetas para elegir? Si no, ni se le ofrece la opción al modelo. */
	public static function hay() {
		return (bool) self::catalogo();
	}

	/**
	 * El slug definitivo, validado contra el catálogo Y contra los datos.
	 *
	 * Las dos validaciones son distintas y hacen falta las dos:
	 *
	 * - Contra el catálogo, porque el modelo puede devolver cualquier cosa en un
	 *   campo de texto, incluido un tipo que se le ocurrió.
	 * - Contra los datos, porque puede elegir bien y olvidarse del dato: un
	 *   `evento` sin fecha dibujaría el bloque de fecha vacío. Es el caso real —
	 *   el modelo acierta el tipo mucho más seguido de lo que completa todos los
	 *   argumentos.
	 *
	 * @param string               $slug  Lo que eligió quien sea.
	 * @param array<string,string> $datos Campos disponibles: ['fecha' => …, 'lugar' => …].
	 * @return string Slug válido, o '' si no hay catálogo (no se guarda nada).
	 */
	public static function resolver( $slug, array $datos = [] ) {
		$cat = self::catalogo();
		if ( ! $cat ) { return ''; }

		$defecto = isset( $cat[ self::DEFECTO ] ) ? self::DEFECTO : (string) array_key_first( $cat );
		$slug    = sanitize_key( (string) $slug );

		if ( '' === $slug || ! isset( $cat[ $slug ] ) ) { return $defecto; }

		foreach ( (array) ( $cat[ $slug ]['pide'] ?? [] ) as $campo ) {
			if ( '' === trim( (string) ( $datos[ $campo ] ?? '' ) ) ) { return $defecto; }
		}

		return $slug;
	}

	/** Cómo se llama, para mostrárselo a la persona antes de publicar. */
	public static function label( $slug ) {
		$cat = self::catalogo();
		return (string) ( $cat[ $slug ]['label'] ?? $slug );
	}

	/**
	 * Guarda el tipo y sus datos en el post.
	 *
	 * La fecha se normaliza a formato MySQL local: el tema hace `strtotime()`
	 * sobre lo guardado, y un «14/8 a las 9» crudo no lo entiende.
	 */
	public static function guardar( $post_id, $slug, array $datos = [] ) {
		$slug = self::resolver( $slug, $datos );
		if ( '' === $slug ) { return ''; }

		update_post_meta( (int) $post_id, self::META, $slug );

		$fecha = trim( (string) ( $datos['fecha'] ?? '' ) );
		if ( '' !== $fecha ) {
			update_post_meta( (int) $post_id, self::META_FECHA, $fecha );
		}
		$lugar = trim( (string) ( $datos['lugar'] ?? '' ) );
		if ( '' !== $lugar ) {
			update_post_meta( (int) $post_id, self::META_LUGAR, $lugar );
		}

		return $slug;
	}
}
