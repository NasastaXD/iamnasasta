<?php
get_header(); ?>

<main id="contenido" class="container cead-page">
    <?php while (have_posts()): the_post(); ?>
        <article <?php post_class('cead-article'); ?>>
            <h1 class="cead-article-title"><?php the_title(); ?></h1>
            <div class="cead-article-content">
                <?php the_content(); ?>
            </div>
        </article>
    <?php endwhile; ?>
</main>

<?php get_footer();
