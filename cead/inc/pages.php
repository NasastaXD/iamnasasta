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
 * URL de una página por slug, o '' si no existe.
 *
 * Devuelve vacío a propósito: quien arma un menú puede saltear el ítem en vez
 * de imprimir un enlace a un 404. Es el mismo criterio que usan las redes
 * sociales del footer — mejor nada que un enlace que no lleva a ningún lado.
 */
function cead_page_url( $slug ) {
	$p = get_page_by_path( $slug );
	return $p ? get_permalink( $p ) : '';
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

/** Crea las páginas que falten. Idempotente: nunca pisa contenido existente. */
function cead_seed_pages() {
	foreach ( cead_institutional_pages() as $slug => $title ) {
		if ( get_page_by_path( $slug ) ) { continue; }
		wp_insert_post( [
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<p>Contenido en preparación. Editá esta página desde <strong>Páginas</strong> en el panel.</p>',
		] );
	}
}

/**
 * Sembrado versionado: al cambiar el conjunto de páginas se sube el número y
 * se crean solo las nuevas. Sin esto, un sitio ya sembrado nunca recibiría las
 * páginas agregadas después.
 */
const CEAD_PAGES_SEED_VERSION = '2';

add_action( 'init', function () {
	if ( (string) get_option( 'cead_pages_seeded' ) === CEAD_PAGES_SEED_VERSION ) { return; }
	cead_seed_pages();
	update_option( 'cead_pages_seeded', CEAD_PAGES_SEED_VERSION );
}, 25 );
