<?php
/**
 * Cuerpo de una nota — despachador.
 *
 * Se comparte entre single.php y single-cead_noticia.php: las noticias que
 * publica CEADI desde WhatsApp entran como entradas normales, y las cargadas a
 * mano pueden estar en cualquiera de los dos lados. Que se vean igual no
 * debería depender de por dónde entraron.
 *
 * Qué maqueta le toca a cada una está en `inc/notas.php`. Acá solo se elige el
 * archivo y se comprueba que exista: `get_template_part()` con un nombre que no
 * existe no avisa nada, imprime la nada y deja la página en blanco de la mitad
 * para abajo. Un tipo guardado que se quedó sin plantilla —renombrada, borrada,
 * un tema hijo a medio copiar— tiene que caer en la maqueta de siempre, no en
 * una página vacía.
 *
 * `cead_nota_tipo()` decide sola si la maqueta sale del selector nativo de
 * «Plantilla» (`template-nota-*.php`) o del recuadro del costado del editor:
 * no hace falta pasarle nada acá, y por eso este archivo se llama igual desde
 * `single.php`, desde los cinco `template-nota-*.php` y desde `news-card.php`.
 *
 * Espera estar dentro del loop.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$cead_tipo = function_exists( 'cead_nota_tipo' ) ? cead_nota_tipo() : 'noticia';

if ( ! locate_template( 'template-parts/nota/' . $cead_tipo . '.php' ) ) {
	$cead_tipo = 'noticia';
}

get_template_part( 'template-parts/nota/' . $cead_tipo );
