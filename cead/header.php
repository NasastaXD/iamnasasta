<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="profile" href="https://gmpg.org/xfn/11">
<?php
/**
 * Marca que el JavaScript está vivo, antes del primer pintado.
 *
 * Las animaciones de entrada parten de `opacity: 0`, así que sin esta clase el
 * CSS las deja visibles: si el JS no llega a correr (falla la red, un bloqueador,
 * un navegador viejo), el contenido se ve igual en vez de quedar invisible.
 */
?>
<script>document.documentElement.className += ' js';</script>
<?php wp_head(); ?>
</head>
<body <?php body_class('cead-body'); ?>>
<?php wp_body_open(); ?>

<a class="skip-link" href="#contenido"><?php esc_html_e( 'Saltar al contenido', 'cead' ); ?></a>

<?php get_template_part('template-parts/nav'); ?>
