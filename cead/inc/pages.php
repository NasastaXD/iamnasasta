<?php
/**
 * Páginas del menú y su creación automática.
 *
 * Los ítems del mega-menú (Creemos / Admisión / Comunidad / Vida) se respaldan
 * con Páginas reales (vacías, listas para que el staff las edite). Divisiones
 * se enlaza al CPT cead_division. Así los botones del menú llevan a algún lado
 * y el contenido se edita desde wp-admin.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Secciones del menú respaldadas por Páginas: grupo => [ [label, slug], ... ]. */
function cead_nav_page_sections() {
	return [
		'Creemos'   => [
			[ 'Sobre CEAD', 'sobre-cead' ],
			[ 'Misión y Visión', 'mision-y-vision' ],
			[ 'Honor Code', 'honor-code' ],
			[ 'Historia', 'historia' ],
		],
		'Admisión'  => [
			[ 'Proceso', 'admision-proceso' ],
			[ 'Calendario', 'admision-calendario' ],
			[ 'Requisitos', 'admision-requisitos' ],
			[ 'Visitas', 'visitas' ],
		],
		'Comunidad' => [
			[ 'Estudiantes', 'comunidad-estudiantes' ],
			[ 'Docentes', 'comunidad-docentes' ],
			[ 'Familias', 'comunidad-familias' ],
			[ 'Egresados', 'egresados' ],
		],
		'Vida'      => [
			[ 'Cultural', 'vida-cultural' ],
			[ 'Deporte', 'vida-deporte' ],
			[ 'Académico', 'vida-academico' ],
			[ 'Clubes', 'vida-clubes' ],
		],
	];
}

/** URL de una página por slug (con fallback). */
function cead_page_url( $slug ) {
	$p = get_page_by_path( $slug );
	return $p ? get_permalink( $p ) : home_url( '/' . $slug . '/' );
}

/** Crea las páginas del menú si faltan (vacías, para editar). Idempotente. */
function cead_seed_pages() {
	foreach ( cead_nav_page_sections() as $items ) {
		foreach ( $items as $it ) {
			list( $title, $slug ) = $it;
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
}

// Sembrar una sola vez (también en instalaciones del tema ya activadas).
add_action( 'init', function () {
	if ( get_option( 'cead_pages_seeded' ) ) { return; }
	cead_seed_pages();
	update_option( 'cead_pages_seeded', 1 );
}, 25 );
