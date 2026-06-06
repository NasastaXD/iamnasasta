<?php
/**
 * Fallback. WordPress requiere index.php.
 * En este tema, front-page.php se usa para la portada;
 * este archivo cubre cualquier otra vista (archive, search, etc.)
 * con un layout mínimo.
 */
get_header(); ?>

<main class="container" style="padding-top: 8rem; padding-bottom: 6rem;">
    <?php if (have_posts()): ?>
        <?php while (have_posts()): the_post(); ?>
            <article <?php post_class('cead-article'); ?>>
                <h1 class="cead-article-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h1>
                <div class="cead-article-content">
                    <?php the_excerpt(); ?>
                </div>
            </article>
        <?php endwhile; ?>

        <?php the_posts_pagination(); ?>
    <?php else: ?>
        <h1 class="cead-article-title">No hay contenido</h1>
    <?php endif; ?>
</main>

<?php get_footer();
