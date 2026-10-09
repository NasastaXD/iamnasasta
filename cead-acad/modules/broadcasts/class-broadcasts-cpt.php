<?php
/**
 * CPT cead_acad_broadcast: comunicados.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Broadcasts_CPT {

	const POST_TYPE = 'cead_acad_broadcast';
	const TAX_CAT   = 'cead_acad_broadcast_category';

	public function boot() {
		add_action( 'init', [ $this, 'register' ], 10 );
	}

	public function register() {
		register_post_type( self::POST_TYPE, [
			'labels' => [
				'name'          => __( 'Comunicados', 'cead-acad' ),
				'singular_name' => __( 'Comunicado', 'cead-acad' ),
				'add_new'       => __( 'Nuevo comunicado', 'cead-acad' ),
				'add_new_item'  => __( 'Nuevo comunicado', 'cead-acad' ),
				'edit_item'     => __( 'Editar comunicado', 'cead-acad' ),
				'view_item'     => __( 'Ver comunicado', 'cead-acad' ),
				'menu_name'     => __( 'Comunicados', 'cead-acad' ),
				'not_found'     => __( 'Sin comunicados.', 'cead-acad' ),
			],
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'cead-acad',
			'show_in_rest'        => true,
			'menu_icon'           => 'dashicons-megaphone',
			'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions' ],
			'has_archive'         => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		] );

		register_taxonomy( self::TAX_CAT, [ self::POST_TYPE ], [
			'labels' => [
				'name'          => __( 'Categorías', 'cead-acad' ),
				'singular_name' => __( 'Categoría', 'cead-acad' ),
			],
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_rest'      => true,
			'hierarchical'      => true,
			'show_admin_column' => true,
		] );
	}

	/**
	 * Siembra categorías por defecto. Idempotente. Se llama UNA vez (activación
	 * y migración de versión), NO en cada init — eso disparaba queries por request.
	 */
	public static function seed_terms() {
		$defaults = [
			'academico'      => __( 'Académico', 'cead-acad' ),
			'administrativo' => __( 'Administrativo', 'cead-acad' ),
			'eventos'        => __( 'Eventos', 'cead-acad' ),
			'urgente'        => __( 'Urgente', 'cead-acad' ),
		];
		foreach ( $defaults as $slug => $label ) {
			if ( ! term_exists( $slug, self::TAX_CAT ) ) {
				wp_insert_term( $label, self::TAX_CAT, [ 'slug' => $slug ] );
			}
		}
	}

	/**
	 * Crea un comunicado y lo publica.
	 *
	 * El único camino para crear comunicados fuera del editor del admin: lo usan
	 * la app y el bot. El texto llega con el formato de un mensaje —*negrita*
	 * con un asterisco, sin encabezados— y se guarda traducido a HTML para que
	 * el panel lo muestre leído y no como texto con asteriscos sueltos.
	 *
	 * Las audiencias, la imagen y la categoría se guardan ANTES de publicar.
	 * Todo lo que reacciona a la publicación pregunta a quién va dirigido el
	 * comunicado; si la publicación pasara primero, esa pregunta se haría sobre
	 * un comunicado sin destinatarios, y el aviso no le llegaría a nadie.
	 *
	 * Quién puede publicar a quién NO se decide acá: lo decide quien llama
	 * (`Cead_Acad_Gestion_Audiencias`), porque depende de por dónde entró el
	 * pedido y de quién lo hizo.
	 *
	 * @param array $args titulo, texto, audiencias, imagen (id de adjunto),
	 *                    categoria (slug), avisar_email, autor.
	 * @return int|WP_Error
	 */
	public static function crear( array $args ) {
		$texto = trim( (string) ( $args['texto'] ?? '' ) );
		if ( '' === $texto ) {
			return new WP_Error( 'sin_texto', __( 'El comunicado no tiene texto.', 'cead-acad' ) );
		}
		$audiencias = (array) ( $args['audiencias'] ?? [] );
		if ( ! $audiencias ) {
			return new WP_Error( 'sin_audiencia', __( 'Elegí a quién va dirigido el comunicado.', 'cead-acad' ) );
		}

		$titulo = trim( sanitize_text_field( (string) ( $args['titulo'] ?? '' ) ) );
		if ( '' === $titulo ) {
			$titulo = wp_trim_words( wp_strip_all_tags( $texto ), 10, '…' );
		}
		$html = class_exists( 'Cead_Acad_Article_Format' )
			? Cead_Acad_Article_Format::to_html_whatsapp( $texto )
			: wpautop( esc_html( $texto ) );

		$pid = wp_insert_post( [
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'draft',
			'post_title'   => $titulo,
			'post_content' => $html,
			'post_author'  => (int) ( $args['autor'] ?? 0 ),
		], true );
		if ( is_wp_error( $pid ) ) {
			return $pid;
		}

		Cead_Acad_Audiences::set( 'broadcast', $pid, $audiencias );
		if ( ! empty( $args['imagen'] ) ) {
			set_post_thumbnail( $pid, (int) $args['imagen'] );
		}
		$categoria = sanitize_title( (string) ( $args['categoria'] ?? '' ) );
		if ( '' !== $categoria && term_exists( $categoria, self::TAX_CAT ) ) {
			wp_set_object_terms( $pid, $categoria, self::TAX_CAT );
		}
		// El mismo aviso por email que ofrece el editor del admin. Tiene que
		// estar marcado antes de publicar: el aviso se dispara al publicar.
		if ( ! empty( $args['avisar_email'] ) ) {
			update_post_meta( $pid, '_cead_acad_notify_email', 1 );
		}

		wp_publish_post( $pid );

		/*
		 * El aviso de «hay un comunicado nuevo» se cuelga de acá y no de la
		 * transición de estado. Al publicar desde el editor, WordPress cambia
		 * el estado ANTES de guardar las audiencias; un aviso colgado de la
		 * transición no sabría a quién mandarlo.
		 */
		do_action( 'cead_acad_comunicado_publicado', (int) $pid );

		return (int) $pid;
	}
}
