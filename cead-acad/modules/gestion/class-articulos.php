<?php
/**
 * Publicar una nota en el sitio del colegio, venga de donde venga.
 *
 * Vivía en el motor de WhatsApp, que era el único lugar desde donde el staff
 * publicaba sin entrar al admin. La app publica igual, así que la publicación
 * sale acá y el bot la llama también: la maqueta, la categoría, la portada y
 * el registro de auditoría no pueden depender de por dónde entró la nota.
 *
 * La IA y la app escriben en Markdown (negritas, listas, tablas). WordPress no
 * lo entiende, así que se maqueta a HTML antes de guardar.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Articulos {

	/**
	 * ¿Puede pedir que la nota salga también en las redes del colegio?
	 *
	 * Solo el director/a, igual que por WhatsApp. Allá se reconocía por el
	 * número desde el que escribía; acá, por el teléfono de su cuenta, que es
	 * el mismo número.
	 */
	public static function puede_redes( $user_id ) {
		$conf = (string) get_option( 'cead_acad_wa_director_phone', '' );
		if ( '' === trim( $conf ) || ! user_can( $user_id, 'cead_acad_manage_articles' ) ) {
			return false;
		}
		$suyo = (string) get_user_meta( (int) $user_id, Cead_Acad_Account::PHONE_META, true );
		return self::mismo_telefono( $suyo, $conf );
	}

	/** Pura, para probarla: dos teléfonos escritos distinto que son el mismo. */
	public static function mismo_telefono( $a, $b ) {
		$a = Cead_Acad_WA_Identity::normalize_phone( (string) $a );
		$b = Cead_Acad_WA_Identity::normalize_phone( (string) $b );
		return '' !== $a && $a === $b;
	}

	/** La categoría que Bit Social vigila para publicar en redes. La crea si falta. */
	public static function categoria_redes() {
		$slug = (string) get_option( 'cead_acad_wa_social_category', 'redes-sociales' );
		$slug = sanitize_title( $slug ) ?: 'redes-sociales';
		$term = get_term_by( 'slug', $slug, 'category' );
		if ( $term && ! is_wp_error( $term ) ) { return (int) $term->term_id; }
		$new = wp_insert_term( 'Redes sociales', 'category', [ 'slug' => $slug ] );
		return is_wp_error( $new ) ? 0 : (int) $new['term_id'];
	}

	/**
	 * La fecha de una nota-evento como la guarda el tema ('Y-m-d H:i:s').
	 * Acepta lo que manda la app ('Y-m-dTH:i'); '' si no es una fecha.
	 */
	public static function fecha_mysql( $valor ) {
		$f = Cead_Acad_Schedule_CPT::fecha_canonica( $valor );
		return '' === $f ? '' : str_replace( 'T', ' ', $f ) . ':00';
	}

	/** Lo que hace falta para armar el formulario en la app. */
	public static function opciones( $user_id ) {
		$categorias = [];
		foreach ( Cead_Acad_Article_Categories::listar() as $id => $nombre ) {
			$categorias[] = [ 'id' => (int) $id, 'nombre' => (string) $nombre ];
		}
		$formatos = [];
		foreach ( Cead_Acad_Article_Kind::catalogo() as $slug => $f ) {
			$formatos[] = [
				'slug'   => (string) $slug,
				'nombre' => (string) ( $f['label'] ?? $slug ),
				// Los datos que la maqueta necesita: sin ellos se publica como
				// noticia, así que la app los pide antes de dejar elegirla.
				'pide'   => array_values( array_map( 'strval', (array) ( $f['pide'] ?? [] ) ) ),
			];
		}
		return [
			'categorias' => $categorias,
			'formatos'   => $formatos,
			'redes'      => self::puede_redes( $user_id ),
		];
	}

	/**
	 * Publica una nota.
	 *
	 * @param array $args titulo, contenido (Markdown), imagen (id de adjunto),
	 *                    categoria (term_id), formato, fecha_evento,
	 *                    lugar_evento, redes (ya validado por quien llama), via.
	 * @return int|WP_Error
	 */
	public static function publicar( $user_id, array $args ) {
		$user_id = (int) $user_id;
		if ( ! user_can( $user_id, 'cead_acad_manage_articles' ) ) {
			return new WP_Error( 'cead_api_sin_permiso', __( 'No tenés permiso para publicar notas.', 'cead-acad' ), [ 'status' => 403 ] );
		}
		$titulo    = trim( sanitize_text_field( (string) ( $args['titulo'] ?? '' ) ) );
		$contenido = trim( (string) ( $args['contenido'] ?? '' ) );
		if ( '' === $titulo || '' === $contenido ) {
			return new WP_Error( 'incompleto', __( 'La nota necesita título y contenido.', 'cead-acad' ), [ 'status' => 400 ] );
		}

		$html = class_exists( 'Cead_Acad_Article_Format' ) ? Cead_Acad_Article_Format::to_html( $contenido ) : $contenido;

		$pid = wp_insert_post( [
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => $titulo,
			'post_content' => $html,
			'post_author'  => $user_id,
		], true );
		if ( is_wp_error( $pid ) ) {
			return $pid;
		}

		$imagen = (int) ( $args['imagen'] ?? 0 );
		if ( $imagen ) {
			set_post_thumbnail( $pid, $imagen );
		}
		/*
		 * La maqueta y la categoría se validan contra lo que existe AHORA: entre
		 * la propuesta y el «sí» —o entre que se escribió sin señal y que
		 * llegó— pudieron cambiar.
		 */
		$formato = Cead_Acad_Article_Kind::guardar( $pid, (string) ( $args['formato'] ?? '' ), [
			'fecha' => (string) ( $args['fecha_evento'] ?? '' ),
			'lugar' => sanitize_text_field( (string) ( $args['lugar_evento'] ?? '' ) ),
		] );
		$tema = (int) ( $args['categoria'] ?? 0 );
		if ( $tema && ! isset( Cead_Acad_Article_Categories::listar()[ $tema ] ) ) {
			$tema = 0;
		}
		if ( $tema ) {
			wp_set_post_categories( $pid, [ $tema ], true );
		}
		$redes = ! empty( $args['redes'] );
		if ( $redes ) {
			$cat = self::categoria_redes();
			if ( $cat ) {
				// append = true: no pisa la categoría temática.
				wp_set_post_categories( $pid, [ $cat ], true );
			}
		}

		// Se publica al final, con todo puesto: lo que reacciona a la
		// publicación (Bit Social, el feed) ve la nota completa.
		wp_publish_post( $pid );

		Cead_Acad_Audit::log( 'wa_article_published', [
			'user_id'     => $user_id ?: null,
			'entity_type' => 'post',
			'entity_id'   => $pid,
			'payload'     => [
				'redes'      => $redes,
				'categoria'  => $tema ?: null,
				'con_imagen' => (bool) $imagen,
				'formato'    => $formato ?: null,
				'via'        => sanitize_key( (string) ( $args['via'] ?? 'app' ) ),
			],
		] );

		return (int) $pid;
	}
}
