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
 * Sección de la portada a la que apunta una página que todavía está vacía.
 *
 * La portada YA tiene contenido real sobre casi todos estos temas. Sacar del
 * menú a las páginas sin escribir dejaba grupos enteros sin dibujar —el bloque
 * Institucional desaparecía completo—, y eso es peor que el problema que venía
 * a resolver: el sitio pierde secciones que sí existen.
 *
 * Así que en vez de desaparecer, mientras la página no tenga contenido el enlace
 * lleva al punto de la portada que cubre ese tema. El visitante llega a algo
 * real, y en cuanto se escriba la página el enlace pasa a apuntarle a ella sola,
 * sin tocar nada.
 *
 * Dos avisos honestos sobre este mapa:
 *
 *  - **Historia** y **Autoridades** no tienen equivalente en la portada. Van al
 *    bloque de valores, que es lo más cercano a «quiénes somos», pero es un
 *    parche: son las dos que más piden que les escribas contenido propio.
 *  - Cuando una página se llena, su entrada acá deja de usarse sola. No hay que
 *    acordarse de sacarla.
 */
function cead_page_fallback_anchor( $slug ) {
	$map = [
		'sobre-cead'    => '#creemos',    // «Cuatro valores. Una sola institución.»
		'historia'      => '#creemos',    // sin sección propia todavía
		'honor-code'    => '#honor-code', // la cita de la portada ES el Código de Honor
		'autoridades'   => '#creemos',    // sin sección propia todavía
		'admision'      => '#admision',
		'bachilleratos' => '#divisiones',
	];
	return isset( $map[ $slug ] ) ? home_url( '/' . $map[ $slug ] ) : '';
}

/**
 * URL de una página por slug: la página misma, el punto de la portada que la
 * cubre mientras esté vacía, o '' si no hay nada de eso.
 *
 * Devolver vacío es a propósito: quien arma un menú puede saltear el ítem en vez
 * de imprimir un enlace a un 404. Es el mismo criterio que usan las redes
 * sociales del footer — mejor nada que un enlace que no lleva a ningún lado.
 *
 * Pero saltear el ítem es el ÚLTIMO recurso, no el primero. Una página sembrada
 * que todavía dice «Contenido en preparación» no ayuda a nadie, y borrarla del
 * menú tampoco: se lleva puesto el grupo entero. Mientras haya un punto de la
 * portada que cubra el tema, el enlace va ahí.
 *
 * En cuanto se escribe el contenido, el enlace apunta a la página sola: no hay
 * que acordarse de nada.
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
