<?php
/**
 * Template Name: La plataforma (explicación visual)
 * Template Post Type: page
 *
 * La página que explica QUÉ es CEAD Académico a alguien que no va a leer la
 * wiki. Pública, sin login, pensada para mandarle el link a alguien y que
 * entienda en dos minutos.
 *
 * Vive en el tema, no en el plugin, a propósito: así hereda el header, el mega
 * menú, el footer, las tres tipografías y los colores del Customizer. La wiki
 * se sirve como HTML suelto y por eso no se parece al sitio; esto sí.
 *
 * El contenido está en las parciales de `template-parts/proyecto/`, una por
 * sección. El contenido del post no se usa: la página se siembra en blanco.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

$secciones = [
	'hero',        // Qué es, en una frase
	'problema',    // Por qué hacía falta
	'caras',       // Panel · App · CEADI — la idea central
	'panel',       // Recorrido por las pantallas
	'ceadi',       // El bot, en acción
	'arquitectura',// Cómo está armado
	'numeros',     // El tamaño de la cosa
	'seguridad',   // Lo que pregunta una dirección
	'cierre',      // A dónde ir después
];
?>
<main id="contenido" class="proyecto">
	<?php foreach ( $secciones as $s ) { get_template_part( 'template-parts/proyecto/' . $s ); } ?>
</main>
<?php
get_footer();
