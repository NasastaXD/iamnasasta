<?php
/**
 * Archivo de Noticias (/noticias).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); ?>

<main class="container cead-archive">
	<header class="section-head">
		<div class="eyebrow">— Novedades</div>
		<h1 class="display-h2 display-h2--narrow"><?php echo esc_html( get_theme_mod( 'cead_noticias_title', 'Noticias del CEAD' ) ); ?></h1>
	</header>

	<?php if ( have_posts() ) : ?>
		<div class="cead-news-grid">
			<?php while ( have_posts() ) : the_post(); ?>
				<a class="reveal cead-news-card" href="<?php the_permalink(); ?>">
					<div class="cead-news-thumb">
						<?php if ( has_post_thumbnail() ) : the_post_thumbnail( 'large' ); endif; ?>
					</div>
					<div class="cead-news-body">
						<div class="eyebrow"><?php echo esc_html( get_the_date() ); ?></div>
						<h3 class="cead-news-title"><?php the_title(); ?></h3>
						<p class="cead-news-text"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt() ?: get_the_content() ), 24 ) ); ?></p>
					</div>
				</a>
			<?php endwhile; ?>
		</div>
		<?php the_posts_pagination( [ 'mid_size' => 1 ] ); ?>
	<?php else : ?>
		<p class="cead-archive-empty"><?php esc_html_e( 'Todavía no hay noticias.', 'cead' ); ?></p>
	<?php endif; ?>
</main>

<?php get_footer();
