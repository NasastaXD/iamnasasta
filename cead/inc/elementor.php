<?php
/**
 * Compatibilidad con Elementor (y bloques anchos de Gutenberg).
 *
 * El tema ya es usable con Elementor porque page.php/single.php llaman a
 * the_content() y header/footer tienen wp_head()/wp_body_open()/wp_footer().
 * Acá sumamos lo que falta para una experiencia cómoda:
 *  - content width,
 *  - soporte de ancho completo,
 *  - registro de "locations" para el Theme Builder de Elementor Pro,
 *  - las fuentes del tema dentro del editor de Elementor.
 *
 * Plantillas de página disponibles (Atributos de página → Plantilla):
 *  - "Ancho completo (Elementor)"  → page-elementor.php  (con header/footer)
 *  - "Lienzo Elementor (en blanco)" → page-canvas.php     (sin header/footer)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Ancho de contenido (lo usan Elementor y los embeds responsivos).
if ( ! isset( $GLOBALS['content_width'] ) ) {
	$GLOBALS['content_width'] = 1200;
}

add_action( 'after_setup_theme', function () {
	add_theme_support( 'align-wide' );    // permite bloques/secciones a todo el ancho
	add_theme_support( 'editor-styles' );
}, 11 );

/**
 * Elementor Pro: habilita el Theme Builder (header, footer, single, archive…)
 * para que puedan rediseñarse con Elementor. Inofensivo si no está Pro.
 */
add_action( 'elementor/theme/register_locations', function ( $manager ) {
	if ( method_exists( $manager, 'register_all_core_location' ) ) {
		$manager->register_all_core_location();
	}
} );

/**
 * Cargar las fuentes del CEAD también dentro del editor de Elementor, para que
 * la vista previa mientras editás coincida con el front.
 */
add_action( 'elementor/editor/after_enqueue_styles', function () {
	wp_enqueue_style(
		'cead-fonts-elementor',
		'https://fonts.googleapis.com/css2?family=Anton&family=Cormorant+Garamond:ital,wght@0,500;0,600;1,400;1,500&family=Mulish:wght@400;500;600;700;800&display=swap',
		[],
		null
	);
} );
