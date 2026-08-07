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
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/news-card' );
			endwhile;
			?>
		</div>
		<?php the_posts_pagination( [ 'mid_size' => 1 ] ); ?>
	<?php else : ?>
		<p class="cead-archive-empty"><?php esc_html_e( 'Todavía no hay noticias.', 'cead' ); ?></p>
	<?php endif; ?>
</main>

<?php get_footer();
