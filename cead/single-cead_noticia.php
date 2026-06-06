<?php
/**
 * Noticia individual.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); ?>

<main class="container cead-single" style="padding-top:8rem;padding-bottom:6rem">
	<?php while ( have_posts() ) : the_post(); ?>
		<article <?php post_class( 'cead-article cead-article--news' ); ?>>
			<div class="eyebrow"><?php echo esc_html( get_the_date() ); ?></div>
			<h1 class="cead-article-title"><?php the_title(); ?></h1>
			<?php if ( has_post_thumbnail() ) : ?>
				<div class="cead-article-thumb"><?php the_post_thumbnail( 'large' ); ?></div>
			<?php endif; ?>
			<div class="cead-article-content"><?php the_content(); ?></div>
			<p style="margin-top:2rem"><a class="underline-brand" href="<?php echo esc_url( get_post_type_archive_link( 'cead_noticia' ) ); ?>">← <?php esc_html_e( 'Todas las noticias', 'cead' ); ?></a></p>
		</article>
	<?php endwhile; ?>
</main>

<?php get_footer();
