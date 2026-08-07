<?php
/**
 * Página. Usa la misma maqueta que las notas, sin la fecha ni la categoría:
 * una página institucional (Historia, Admisión) no es una novedad con fecha.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); ?>

<main id="contenido" class="container cead-single cead-page">
	<?php while ( have_posts() ) : the_post(); ?>
		<article <?php post_class( 'cead-article' ); ?>>
			<header class="cead-article-head">
				<h1 class="cead-article-title"><?php the_title(); ?></h1>
				<div class="cead-article-rule" aria-hidden="true"></div>
			</header>

			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="cead-article-thumb"><?php the_post_thumbnail( 'large' ); ?></figure>
			<?php endif; ?>

			<div class="cead-article-content"><?php the_content(); ?></div>
		</article>
	<?php endwhile; ?>
</main>

<?php get_footer();
