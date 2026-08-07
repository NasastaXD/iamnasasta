<?php
/**
 * Portada.
 *
 * El overlay de entrada queda FUERA del <main>: es decoración, y el enlace
 * «saltar al contenido» tiene que llevar al contenido de verdad.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); ?>

<?php get_template_part('template-parts/sections/intro'); ?>

<main id="contenido">
	<?php get_template_part('template-parts/sections/hero'); ?>
	<?php get_template_part('template-parts/sections/values'); ?>
	<?php get_template_part('template-parts/sections/quote'); ?>
	<?php get_template_part('template-parts/sections/divisions'); ?>
	<?php get_template_part('template-parts/sections/marquee'); ?>
	<?php get_template_part('template-parts/sections/galeria'); ?>
	<?php get_template_part('template-parts/sections/redes'); ?>
	<?php get_template_part('template-parts/sections/life'); ?>
	<?php get_template_part('template-parts/sections/eventos'); ?>
	<?php get_template_part('template-parts/sections/admission'); ?>
</main>

<?php get_footer();
