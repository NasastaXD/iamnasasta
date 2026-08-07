<?php
/**
 * Listado genérico: índice del blog, archivos por categoría y fecha, búsqueda.
 * (La portada usa front-page.php.)
 *
 * Muestra las notas como tarjetas, igual que /noticias. Antes imprimía el
 * extracto suelto: las tablas salían aplanadas en un párrafo corrido y la foto
 * no aparecía por ningún lado.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); ?>

<main id="contenido" class="container cead-archive">
	<header class="section-head">
		<div class="eyebrow">— <?php echo esc_html( cead_listing_eyebrow() ); ?></div>
		<h1 class="display-h2 display-h2--narrow"><?php echo esc_html( cead_listing_title() ); ?></h1>
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
		<p class="cead-archive-empty">
			<?php
			echo is_search()
				? esc_html__( 'No encontramos nada con esa búsqueda.', 'cead' )
				: esc_html__( 'Todavía no hay publicaciones.', 'cead' );
			?>
		</p>
	<?php endif; ?>
</main>

<?php get_footer();
