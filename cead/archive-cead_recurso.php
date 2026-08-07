<?php
/**
 * Archivo de Recursos públicos (/recursos). Listado con categorías.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); ?>

<main id="contenido" class="container cead-archive">
	<header class="section-head">
		<div class="eyebrow">— Recursos</div>
		<h1 class="display-h2 display-h2--narrow"><?php echo esc_html( get_theme_mod( 'cead_recursos_title', 'Recursos para la comunidad' ) ); ?></h1>
		<p class="cead-archive-sub"><?php echo esc_html( get_theme_mod( 'cead_recursos_sub', 'Documentos, guías, calendarios y enlaces útiles del CEAD.' ) ); ?></p>
	</header>

	<?php
	$cats   = get_terms( [ 'taxonomy' => 'cead_recurso_cat', 'hide_empty' => true ] );
	$active = isset( $_GET['rc'] ) ? sanitize_title( wp_unslash( $_GET['rc'] ) ) : '';
	$base   = get_post_type_archive_link( 'cead_recurso' );
	if ( $cats && ! is_wp_error( $cats ) ) : ?>
		<nav class="cead-chips">
			<a class="cead-chip <?php echo $active === '' ? 'is-active' : ''; ?>" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Todos', 'cead' ); ?></a>
			<?php foreach ( $cats as $c ) : ?>
				<a class="cead-chip <?php echo $active === $c->slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'rc', $c->slug, $base ) ); ?>"><?php echo esc_html( $c->name ); ?></a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<?php if ( have_posts() ) : ?>
		<div class="cead-res-grid">
			<?php while ( have_posts() ) : the_post();
				$url   = (string) get_post_meta( get_the_ID(), '_cead_recurso_url', true );
				$terms = get_the_terms( get_the_ID(), 'cead_recurso_cat' );
				$tname = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
				$is_file = $url && preg_match( '/\.(pdf|docx?|xlsx?|pptx?|zip|jpg|png)$/i', $url );
			?>
				<article class="reveal cead-res-card">
					<?php if ( $tname ) : ?><div class="eyebrow"><?php echo esc_html( $tname ); ?></div><?php endif; ?>
					<h3 class="cead-res-title"><?php the_title(); ?></h3>
					<?php if ( has_excerpt() || get_the_content() ) : ?>
						<p class="cead-res-text"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt() ?: get_the_content() ), 28 ) ); ?></p>
					<?php endif; ?>
					<?php if ( $url ) : ?>
						<a class="cead-res-link underline-brand" href="<?php echo esc_url( $url ); ?>"<?php echo $is_file ? '' : ' target="_blank" rel="noopener"'; ?>>
							<?php echo $is_file ? esc_html__( 'Descargar →', 'cead' ) : esc_html__( 'Abrir recurso →', 'cead' ); ?>
						</a>
					<?php else : ?>
						<a class="cead-res-link underline-brand" href="<?php the_permalink(); ?>"><?php esc_html_e( 'Ver más →', 'cead' ); ?></a>
					<?php endif; ?>
				</article>
			<?php endwhile; ?>
		</div>
		<?php the_posts_pagination( [ 'mid_size' => 1 ] ); ?>
	<?php else : ?>
		<p class="cead-archive-empty"><?php esc_html_e( 'Todavía no hay recursos publicados.', 'cead' ); ?></p>
	<?php endif; ?>
</main>

<?php get_footer();
