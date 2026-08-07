<?php
/**
 * Contenido público del sitio: Recursos, Noticias, Galería, Eventos públicos
 * (puente con el plugin CEAD Académico) y ajustes de Mapa/Cómo llegar.
 *
 * Privacidad: los eventos del plugin (cead_acad_event) son internos. Acá solo
 * se muestran los que el staff marcó explícitamente como "públicos", y solo su
 * título, fecha y lugar. Nada de datos sensibles.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ============================================================
 * 1) CPTs públicos
 * ============================================================ */

add_action( 'init', function () {

	// --- Recursos públicos (descargas / enlaces) ---
	register_post_type( 'cead_recurso', [
		'labels' => [
			'name'          => 'Recursos',
			'singular_name' => 'Recurso',
			'add_new_item'  => 'Agregar recurso',
			'edit_item'     => 'Editar recurso',
			'menu_name'     => 'Recursos',
			'not_found'     => 'Sin recursos.',
		],
		'public'        => true,
		'has_archive'   => 'recursos',
		'show_in_rest'  => true,
		'menu_position' => 23,
		'menu_icon'     => 'dashicons-portfolio',
		'supports'      => [ 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ],
		'rewrite'       => [ 'slug' => 'recurso' ],
	] );

	register_taxonomy( 'cead_recurso_cat', 'cead_recurso', [
		'labels'            => [ 'name' => 'Categorías de recurso', 'singular_name' => 'Categoría' ],
		'public'            => true,
		'hierarchical'      => true,
		'show_in_rest'      => true,
		'show_admin_column' => true,
		'rewrite'           => [ 'slug' => 'recursos-cat' ],
	] );

	// --- Noticias / Novedades ---
	register_post_type( 'cead_noticia', [
		'labels' => [
			'name'          => 'Noticias',
			'singular_name' => 'Noticia',
			'add_new_item'  => 'Agregar noticia',
			'edit_item'     => 'Editar noticia',
			'menu_name'     => 'Noticias',
			'not_found'     => 'Sin noticias.',
		],
		'public'        => true,
		'has_archive'   => 'noticias',
		'show_in_rest'  => true,
		'menu_position' => 24,
		'menu_icon'     => 'dashicons-megaphone',
		'supports'      => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author' ],
		'rewrite'       => [ 'slug' => 'noticia' ],
	] );

	// --- Galería (álbumes de vida estudiantil) ---
	register_post_type( 'cead_galeria', [
		'labels' => [
			'name'          => 'Galería',
			'singular_name' => 'Álbum',
			'add_new_item'  => 'Agregar álbum',
			'edit_item'     => 'Editar álbum',
			'menu_name'     => 'Galería',
			'not_found'     => 'Sin álbumes.',
		],
		'public'        => true,
		'has_archive'   => 'galeria',
		'show_in_rest'  => true,
		'menu_position' => 25,
		'menu_icon'     => 'dashicons-format-gallery',
		'supports'      => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
		'rewrite'       => [ 'slug' => 'album' ],
	] );
}, 11 );

// Refrescar permalinks una vez al activar el tema (para los archives nuevos).
add_action( 'after_switch_theme', function () { update_option( 'cead_flush_public', 1 ); } );
add_action( 'init', function () {
	if ( get_option( 'cead_flush_public' ) ) {
		flush_rewrite_rules();
		delete_option( 'cead_flush_public' );
	}
}, 99 );

/* ============================================================
 * 2) Meta del recurso (URL del archivo o enlace)
 * ============================================================ */

add_action( 'init', function () {
	register_post_meta( 'cead_recurso', '_cead_recurso_url', [
		'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'esc_url_raw',
	] );
} );

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'cead_recurso_meta', 'Enlace / archivo del recurso', function ( $post ) {
		wp_nonce_field( 'cead_recurso_meta', 'cead_recurso_meta_nonce' );
		$url = get_post_meta( $post->ID, '_cead_recurso_url', true );
		echo '<p><label><strong>URL del recurso</strong> (archivo de la biblioteca de medios o enlace externo)</label><br>';
		echo '<input type="url" name="cead_recurso_url" value="' . esc_attr( $url ) . '" style="width:100%" placeholder="https://…"></p>';
		echo '<p class="description">Subí el PDF en Medios y pegá su URL, o pegá un enlace externo. Dejalo vacío si el recurso se explica solo con el texto.</p>';
	}, 'cead_recurso', 'side', 'high' );
} );

add_action( 'save_post_cead_recurso', function ( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
	if ( ! isset( $_POST['cead_recurso_meta_nonce'] ) || ! wp_verify_nonce( $_POST['cead_recurso_meta_nonce'], 'cead_recurso_meta' ) ) { return; }
	if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
	update_post_meta( $post_id, '_cead_recurso_url', esc_url_raw( wp_unslash( $_POST['cead_recurso_url'] ?? '' ) ) );
} );

/* ============================================================
 * 3) Eventos públicos — puente con el plugin (opt-in por evento)
 * ============================================================ */

// Checkbox "mostrar en la web pública" en el editor de eventos del plugin.
add_action( 'add_meta_boxes', function () {
	if ( ! post_type_exists( 'cead_acad_event' ) ) { return; }
	add_meta_box( 'cead_public_event', 'Web pública del CEAD', function ( $post ) {
		wp_nonce_field( 'cead_public_event', 'cead_public_event_nonce' );
		$on = get_post_meta( $post->ID, '_cead_public_event', true );
		echo '<label><input type="checkbox" name="cead_public_event" value="1" ' . checked( $on, '1', false ) . '> Mostrar este evento en la web pública</label>';
		echo '<p class="description">Solo se publican <strong>título, fecha y lugar</strong>. Dejalo sin marcar para eventos internos.</p>';
	}, 'cead_acad_event', 'side' );
} );

add_action( 'save_post_cead_acad_event', function ( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
	if ( ! isset( $_POST['cead_public_event_nonce'] ) || ! wp_verify_nonce( $_POST['cead_public_event_nonce'], 'cead_public_event' ) ) { return; }
	if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
	update_post_meta( $post_id, '_cead_public_event', isset( $_POST['cead_public_event'] ) ? '1' : '' );
} );

/**
 * Próximos eventos marcados como públicos. Devuelve WP_Post[].
 */
function cead_upcoming_public_events( $limit = 6 ) {
	if ( ! post_type_exists( 'cead_acad_event' ) ) { return []; }
	return get_posts( [
		'post_type'      => 'cead_acad_event',
		'post_status'    => 'publish',
		'posts_per_page' => (int) $limit,
		'orderby'        => 'meta_value',
		'meta_key'       => '_cead_acad_event_start',
		'order'          => 'ASC',
		'no_found_rows'  => true,
		'meta_query'     => [
			'relation' => 'AND',
			[ 'key' => '_cead_public_event', 'value' => '1', 'compare' => '=' ],
			[ 'key' => '_cead_acad_event_start', 'value' => current_time( 'Y-m-d H:i:s' ), 'compare' => '>=', 'type' => 'DATETIME' ],
		],
	] );
}

/**
 * Render reutilizable de la lista de eventos públicos (sección y shortcode).
 */
function cead_render_public_events( $limit = 6 ) {
	$events = cead_upcoming_public_events( $limit );
	if ( ! $events ) {
		return '<p class="cead-events-empty">' . esc_html__( 'No hay eventos próximos por ahora.', 'cead' ) . '</p>';
	}
	$out = '<ul class="cead-events-list">';
	foreach ( $events as $e ) {
		$start = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
		$loc   = (string) get_post_meta( $e->ID, '_cead_acad_event_location', true );
		$ts    = $start ? strtotime( $start ) : 0;
		$out  .= '<li class="cead-event">';
		$out  .= '<span class="cead-event-date"><span class="d">' . esc_html( $ts ? date_i18n( 'd', $ts ) : '—' ) . '</span><span class="m">' . esc_html( $ts ? date_i18n( 'M', $ts ) : '' ) . '</span></span>';
		$out  .= '<span class="cead-event-body"><span class="cead-event-title">' . esc_html( get_the_title( $e ) ) . '</span>';
		$meta  = [];
		if ( $ts )  { $meta[] = date_i18n( 'H:i', $ts ) . ' hs'; }
		if ( $loc ) { $meta[] = '📍 ' . $loc; }
		if ( $meta ) { $out .= '<span class="cead-event-meta">' . esc_html( implode( ' · ', $meta ) ) . '</span>'; }
		$out  .= '</span></li>';
	}
	$out .= '</ul>';
	return $out;
}

// Shortcode para usar en cualquier página o en Elementor: [cead_eventos limite="6"]
add_shortcode( 'cead_eventos', function ( $atts ) {
	$a = shortcode_atts( [ 'limite' => 6 ], $atts );
	return cead_render_public_events( (int) $a['limite'] );
} );

/* ============================================================
 * 4) Filtro de categoría en el archivo de recursos (?rc=slug)
 * ============================================================ */

add_action( 'pre_get_posts', function ( $q ) {
	if ( is_admin() || ! $q->is_main_query() ) { return; }
	if ( $q->is_post_type_archive( 'cead_recurso' ) && ! empty( $_GET['rc'] ) ) {
		$q->set( 'tax_query', [ [
			'taxonomy' => 'cead_recurso_cat',
			'field'    => 'slug',
			'terms'    => sanitize_title( wp_unslash( $_GET['rc'] ) ),
		] ] );
	}

	/* ============================================================
	 * 5) /noticias muestra también las entradas del blog
	 * ============================================================
	 * Conviven dos formas de cargar una noticia: el CPT «Noticias» (a mano,
	 * desde el menú lateral) y las entradas normales, que es lo que publica
	 * CEADI desde WhatsApp — y tienen que seguir siendo entradas porque el
	 * plugin de redes sociales trabaja sobre entradas y categorías.
	 *
	 * Sin esto, lo publicado desde el bot no aparecía en /noticias y el
	 * listado se veía vacío aunque la nota estuviera publicada.
	 */
	if ( $q->is_post_type_archive( 'cead_noticia' ) ) {
		$q->set( 'post_type', [ 'cead_noticia', 'post' ] );
	}
} );
