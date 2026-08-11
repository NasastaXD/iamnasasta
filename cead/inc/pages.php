<?php
/**
 * Páginas institucionales y su creación automática.
 *
 * Antes se creaban 16 páginas, todas con el mismo «Contenido en preparación»:
 * el menú se veía completo pero casi todo llevaba a una página vacía. Ahora se
 * crea un conjunto corto de páginas que la institución sí va a llenar, y las
 * secciones que se cubren con contenido real (noticias, galería, recursos,
 * bachilleratos) se enlazan directo a su archivo en vez de duplicarse en una
 * página suelta.
 *
 * Las páginas viejas NO se borran: si ya hay contenido cargado en alguna, se
 * conserva. Las que sobren se pueden eliminar a mano desde Páginas.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Páginas institucionales: slug => título.
 *
 * Cortas y concretas. «Admisión» junta proceso, requisitos y calendario, que
 * antes eran tres páginas separadas y en la práctica es un solo texto.
 */
function cead_institutional_pages() {
	return [
		'sobre-cead'    => __( 'Sobre CEAD', 'cead' ),
		'historia'      => __( 'Historia', 'cead' ),
		'honor-code'    => __( 'Honor Code', 'cead' ),
		'autoridades'   => __( 'Autoridades', 'cead' ),
		'admision'      => __( 'Admisión', 'cead' ),
		'bachilleratos' => __( 'Bachilleratos', 'cead' ),
	];
}

/**
 * Texto con el que nacen las páginas sembradas.
 *
 * Vive en una constante para que sembrar y detectar usen exactamente la misma
 * cadena. Si estuviera escrita dos veces, cambiar una y no la otra dejaría
 * páginas vacías colándose en el menú sin que nadie se entere.
 */
const CEAD_PAGE_PLACEHOLDER = '<p>Contenido en preparación. Editá esta página desde <strong>Páginas</strong> en el panel.</p>';

/**
 * ¿La página sigue con el texto de relleno (o directamente vacía)?
 *
 * Se compara sobre el texto pelado y con los espacios normalizados, para que
 * no dependa de si el editor guardó saltos de línea distintos o envolvió el
 * párrafo en un bloque de Gutenberg.
 */
function cead_page_is_placeholder( $post ) {
	$post = get_post( $post );
	if ( ! $post ) { return true; }

	$limpiar = static function ( $html ) {
		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $html ) ) );
	};

	$actual = $limpiar( $post->post_content );
	return '' === $actual || $actual === $limpiar( CEAD_PAGE_PLACEHOLDER );
}

/**
 * Sección de la portada que cubre el tema cuando la página todavía está vacía.
 *
 * La home YA tiene contenido real sobre admisión y bachilleratos. Si esas
 * páginas siguen sin escribirse, sacarlas del menú sin más dejaría al sitio sin
 * ninguna forma de llegar a algo que sí existe. Se manda al ancla de la portada
 * y el visitante llega a contenido de verdad igual.
 */
function cead_page_fallback_anchor( $slug ) {
	$map = [
		'admision'      => '#admision',
		'bachilleratos' => '#divisiones',
	];
	return isset( $map[ $slug ] ) ? home_url( '/' . $map[ $slug ] ) : '';
}

/**
 * URL de una página por slug, o '' si no existe **o si sigue vacía**.
 *
 * Devuelve vacío a propósito: quien arma un menú puede saltear el ítem en vez
 * de imprimir un enlace a un 404. Es el mismo criterio que usan las redes
 * sociales del footer — mejor nada que un enlace que no lleva a ningún lado.
 *
 * El criterio se extendió a las páginas vacías: una página sembrada que todavía
 * dice «Contenido en preparación» es, para quien la visita, lo mismo que un 404
 * —peor, porque promete algo y no lo cumple—. En cuanto se le escribe contenido
 * real, vuelve al menú sola: no hay que acordarse de nada.
 */
function cead_page_url( $slug ) {
	$p = get_page_by_path( $slug );
	if ( ! $p ) { return ''; }
	if ( cead_page_is_placeholder( $p ) ) {
		return cead_page_fallback_anchor( $slug );
	}
	return get_permalink( $p );
}

/**
 * Menú institucional listo para pintar: [ label, url ], ya sin los destinos
 * que no existen. Es la fuente única del nav y del footer.
 */
function cead_site_links() {
	$links = [];

	foreach ( cead_institutional_pages() as $slug => $label ) {
		$url = cead_page_url( $slug );
		if ( $url ) { $links[ $slug ] = [ 'label' => $label, 'url' => $url ]; }
	}

	// Archivos de contenido real: existen solos, no necesitan página.
	foreach ( [
		'noticias' => [ __( 'Noticias', 'cead' ), 'cead_noticia' ],
		'galeria'  => [ __( 'Galería', 'cead' ),  'cead_galeria' ],
		'recursos' => [ __( 'Recursos', 'cead' ), 'cead_recurso' ],
	] as $key => $def ) {
		$url = get_post_type_archive_link( $def[1] );
		if ( $url ) { $links[ $key ] = [ 'label' => $def[0], 'url' => $url ]; }
	}

	/*
	 * La plataforma. Va aparte de las institucionales porque su contenido vive
	 * en la plantilla, no en el post: la página está en blanco a propósito y no
	 * debe tratarse como «vacía».
	 */
	$proyecto = get_page_by_path( CEAD_PROYECTO_SLUG );
	if ( $proyecto ) {
		$links['proyecto'] = [ 'label' => __( 'La plataforma', 'cead' ), 'url' => get_permalink( $proyecto ) ];
	}

	return $links;
}

/** Los cuatro bachilleratos, desde el CPT. Vacío si todavía no se cargaron. */
function cead_division_links() {
	$out = [];
	$q = new WP_Query( [
		'post_type'      => 'cead_division',
		'posts_per_page' => 8,
		'orderby'        => 'menu_order',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	] );
	foreach ( $q->posts as $p ) {
		$out[] = [ 'label' => get_the_title( $p ), 'url' => get_permalink( $p ) ];
	}
	wp_reset_postdata();
	return $out;
}

/** Slug y plantilla de la página que explica la plataforma. */
const CEAD_PROYECTO_SLUG     = 'proyecto';
const CEAD_PROYECTO_TEMPLATE = 'template-proyecto.php';

/** Crea las páginas que falten. Idempotente: nunca pisa contenido existente. */
function cead_seed_pages() {
	foreach ( cead_institutional_pages() as $slug => $title ) {
		if ( get_page_by_path( $slug ) ) { continue; }
		wp_insert_post( [
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => CEAD_PAGE_PLACEHOLDER,
		] );
	}

	/*
	 * La página de la plataforma. Se crea en blanco y con la plantilla ya
	 * asignada: todo lo que se ve sale de `template-proyecto.php`, así que el
	 * contenido del post no se usa. Se siembra sola para que exista sin que
	 * nadie tenga que acordarse de crearla y elegir la plantilla a mano.
	 */
	$proyecto = get_page_by_path( CEAD_PROYECTO_SLUG );
	if ( ! $proyecto ) {
		$id = wp_insert_post( [
			'post_title'   => __( 'La plataforma', 'cead' ),
			'post_name'    => CEAD_PROYECTO_SLUG,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '',
		] );
		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_wp_page_template', CEAD_PROYECTO_TEMPLATE );
		}
	} elseif ( get_post_meta( $proyecto->ID, '_wp_page_template', true ) !== CEAD_PROYECTO_TEMPLATE ) {
		// La página existe pero perdió la plantilla (import, cambio de tema):
		// sin esto se vería en blanco, que es peor que no existir.
		update_post_meta( $proyecto->ID, '_wp_page_template', CEAD_PROYECTO_TEMPLATE );
	}
}

/**
 * Sembrado versionado: al cambiar el conjunto de páginas se sube el número y
 * se crean solo las nuevas. Sin esto, un sitio ya sembrado nunca recibiría las
 * páginas agregadas después.
 */
const CEAD_PAGES_SEED_VERSION = '3';

add_action( 'init', function () {
	if ( (string) get_option( 'cead_pages_seeded' ) === CEAD_PAGES_SEED_VERSION ) { return; }
	cead_seed_pages();
	update_option( 'cead_pages_seeded', CEAD_PAGES_SEED_VERSION );
}, 25 );
