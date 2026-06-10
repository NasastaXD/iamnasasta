<?php
/**
 * Álbum individual: el contenido (bloque de galería de WordPress) se muestra tal cual.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); ?>

<main class="container cead-single" style="padding-top:8rem;padding-bottom:6rem">
	<?php while ( have_posts() ) : the_post(); ?>
		<article <?php post_class( 'cead-article' ); ?>>
			<div class="eyebrow">— <?php esc_html_e( 'Galería', 'cead' ); ?></div>
			<h1 class="cead-article-title"><?php the_title(); ?></h1>
			<div class="cead-article-content cead-album-content"><?php the_content(); ?></div>
			<p style="margin-top:2rem"><a class="underline-brand" href="<?php echo esc_url( get_post_type_archive_link( 'cead_galeria' ) ); ?>">← <?php esc_html_e( 'Toda la galería', 'cead' ); ?></a></p>
		</article>
	<?php endwhile; ?>
</main>

<?php get_footer();
