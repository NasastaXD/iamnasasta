<?php get_header(); ?>

<main id="contenido" class="container cead-page">
    <h1 class="cead-article-title">404</h1>
    <p>La página que buscás no existe.</p>
    <p><a href="<?php echo esc_url(home_url('/')); ?>" class="underline-brand">Volver al inicio</a></p>
</main>

<?php get_footer();
