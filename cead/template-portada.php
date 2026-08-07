<?php
/**
 * Template Name: Nota con portada
 * Template Post Type: post, page
 *
 * Para actos, eventos y todo lo que entra por la foto: la imagen destacada va
 * a sangre y el título encima. Sin imagen destacada no tiene sentido, así que
 * en ese caso cae a la maqueta de nota normal en vez de mostrar una franja
 * negra vacía.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); ?>

<?php
while ( have_posts() ) :
	the_post();

	if ( ! has_post_thumbnail() ) {
		echo '<main id="contenido" class="container cead-single cead-page">';
		get_template_part( 'template-parts/article' );
		echo '</main>';
		continue;
	}

	$cead_cats = get_the_category();
	$cead_cat  = ( $cead_cats && ! is_wp_error( $cead_cats ) ) ? $cead_cats[0]->name : '';
	?>
	<main id="contenido">
		<article <?php post_class( 'cead-cover' ); ?>>

			<header class="cead-cover-hero">
				<div class="cead-cover-img"><?php the_post_thumbnail( 'full' ); ?></div>
				<div class="cead-cover-veil" aria-hidden="true"></div>
				<div class="container cead-cover-head">
					<div class="cead-cover-meta">
						<?php if ( $cead_cat ) : ?>
							<span class="cead-article-cat"><?php echo esc_html( $cead_cat ); ?></span>
						<?php endif; ?>
						<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
					</div>
					<h1 class="cead-cover-title"><?php the_title(); ?></h1>
				</div>
			</header>

			<div class="container cead-cover-body">
				<div class="cead-article cead-article--news">
					<div class="cead-article-content"><?php the_content(); ?></div>
				</div>
			</div>

		</article>
	</main>
	<?php
endwhile;
?>

<?php get_footer();
