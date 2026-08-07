<?php
/**
 * Archivo de Galería (/galeria): grilla de álbumes.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); ?>

<main id="contenido" class="container cead-archive">
	<header class="section-head">
		<div class="eyebrow">— Galería</div>
		<h1 class="display-h2 display-h2--narrow"><?php echo esc_html( get_theme_mod( 'cead_galeria_title', 'La vida en el CEAD, en imágenes' ) ); ?></h1>
	</header>

	<?php if ( have_posts() ) : ?>
		<div class="cead-gallery-grid">
			<?php while ( have_posts() ) : the_post();
				$img = cead_post_image_url( get_the_ID(), '_cead_vida_image_url', 'large' );
			?>
				<a class="reveal cead-album" href="<?php the_permalink(); ?>">
					<div class="cead-album-image">
						<?php if ( $img ) : ?><img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" loading="lazy"><?php endif; ?>
						<div class="cead-album-overlay"></div>
						<h3 class="cead-album-title"><?php the_title(); ?></h3>
					</div>
				</a>
			<?php endwhile; ?>
		</div>
		<?php the_posts_pagination( [ 'mid_size' => 1 ] ); ?>
	<?php else : ?>
		<p class="cead-archive-empty"><?php esc_html_e( 'Todavía no hay álbumes.', 'cead' ); ?></p>
	<?php endif; ?>
</main>

<?php get_footer();
