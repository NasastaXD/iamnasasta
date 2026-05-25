<?php
/**
 * Feed: /panel/comunicados
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user      = wp_get_current_user();
$paged     = max( 1, (int) ( $_GET['p'] ?? 1 ) );
$category  = isset( $_GET['cat'] ) ? sanitize_title( wp_unslash( $_GET['cat'] ) ) : '';
$per_page  = 10;

$posts     = Cead_Acad_Broadcasts_Feed::for_user( $user->ID, [
	'per_page' => $per_page,
	'paged'    => $paged,
	'category' => $category,
] );
$read_ids  = Cead_Acad_Broadcasts_Reads::read_ids_for_user( $user->ID );
$categories = get_terms( [ 'taxonomy' => Cead_Acad_Broadcasts_CPT::TAX_CAT, 'hide_empty' => false ] );

$page_title = __( 'Comunicados', 'cead-acad' );

$body = function () use ( $posts, $read_ids, $categories, $category, $paged, $per_page ) {
	?>
	<section class="cead-acad-panel-section">
		<div class="cead-acad-feed-head">
			<span class="cead-acad-eyebrow"><?php esc_html_e( 'Comunicados', 'cead-acad' ); ?></span>
			<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Tu feed', 'cead-acad' ); ?></h2>
			<p class="cead-acad-panel-sub"><?php esc_html_e( 'Mostramos los comunicados publicados que están dirigidos a tu rol, curso, cohorte o personalmente.', 'cead-acad' ); ?></p>

			<?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
				<nav class="cead-acad-cats" aria-label="<?php esc_attr_e( 'Filtrar por categoría', 'cead-acad' ); ?>">
					<a class="<?php echo $category === '' ? 'is-active' : ''; ?>" href="<?php echo esc_url( cead_acad_url( 'panel/comunicados' ) ); ?>"><?php esc_html_e( 'Todas', 'cead-acad' ); ?></a>
					<?php foreach ( $categories as $c ) : ?>
						<a class="<?php echo $category === $c->slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'cat', $c->slug, cead_acad_url( 'panel/comunicados' ) ) ); ?>"><?php echo esc_html( $c->name ); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>
		</div>

		<?php if ( ! $posts ) : ?>
			<div class="cead-acad-card cead-acad-card--empty">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Sin comunicados', 'cead-acad' ); ?></span>
				<h3><?php esc_html_e( 'Bandeja vacía', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Cuando dirección o secretaría publique un comunicado dirigido a vos, aparecerá acá.', 'cead-acad' ); ?></p>
			</div>
		<?php else : ?>
			<div class="cead-acad-feed">
				<?php foreach ( $posts as $p ) :
					$is_read = in_array( (int) $p->ID, $read_ids, true );
					$cats = get_the_terms( $p->ID, Cead_Acad_Broadcasts_CPT::TAX_CAT );
					$cat_label = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0]->name : '';
				?>
					<a class="cead-acad-feed-item <?php echo $is_read ? 'is-read' : 'is-unread'; ?>" href="<?php echo esc_url( cead_acad_url( 'panel/comunicados/' . $p->ID ) ); ?>">
						<div class="cead-acad-feed-item-meta">
							<?php if ( ! $is_read ) : ?><span class="cead-acad-feed-dot" aria-hidden="true"></span><?php endif; ?>
							<span class="cead-acad-eyebrow"><?php echo esc_html( get_the_date( '', $p ) ); ?><?php if ( $cat_label ) : ?> · <?php echo esc_html( $cat_label ); ?><?php endif; ?></span>
						</div>
						<h3 class="cead-acad-feed-item-title"><?php echo esc_html( get_the_title( $p ) ); ?></h3>
						<p class="cead-acad-feed-item-excerpt"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $p->post_content ), 32 ) ); ?></p>
					</a>
				<?php endforeach; ?>
			</div>

			<?php
			// Paginación simple.
			$has_more = count( $posts ) === $per_page;
			if ( $paged > 1 || $has_more ) : ?>
				<nav class="cead-acad-pager">
					<?php if ( $paged > 1 ) : ?>
						<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( add_query_arg( [ 'p' => $paged - 1, 'cat' => $category ?: null ], cead_acad_url( 'panel/comunicados' ) ) ); ?>">← <?php esc_html_e( 'Anterior', 'cead-acad' ); ?></a>
					<?php endif; ?>
					<?php if ( $has_more ) : ?>
						<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( add_query_arg( [ 'p' => $paged + 1, 'cat' => $category ?: null ], cead_acad_url( 'panel/comunicados' ) ) ); ?>"><?php esc_html_e( 'Siguiente', 'cead-acad' ); ?> →</a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
