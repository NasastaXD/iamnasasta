<?php
/**
 * Single: /panel/comunicados/{id}
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$broadcast_id = isset( $broadcast_id ) ? (int) $broadcast_id : 0;
$user         = wp_get_current_user();

$post = $broadcast_id ? get_post( $broadcast_id ) : null;

if ( ! $post || $post->post_type !== Cead_Acad_Broadcasts_CPT::POST_TYPE || $post->post_status !== 'publish' ) {
	status_header( 404 );
	$page_title = __( 'No encontrado', 'cead-acad' );
	$body = function () {
		?>
		<section class="cead-acad-panel-section">
			<span class="cead-acad-eyebrow"><?php esc_html_e( 'Error 404', 'cead-acad' ); ?></span>
			<h2 class="cead-acad-panel-h"><?php esc_html_e( 'No encontrado', 'cead-acad' ); ?></h2>
			<p class="cead-acad-panel-sub"><?php esc_html_e( 'El comunicado no existe o no está publicado.', 'cead-acad' ); ?></p>
			<p><a href="<?php echo esc_url( cead_acad_url( 'panel/comunicados' ) ); ?>" class="cead-acad-btn cead-acad-btn--ghost">← <?php esc_html_e( 'Volver al feed', 'cead-acad' ); ?></a></p>
		</section>
		<?php
	};
	include CEAD_ACAD_DIR . 'templates/panel/shell.php';
	return;
}

if ( ! Cead_Acad_Broadcasts_Feed::user_can_view( $post->ID, $user->ID ) ) {
	status_header( 403 );
	$page_title = __( 'Sin acceso', 'cead-acad' );
	$body = function () {
		?>
		<section class="cead-acad-panel-section">
			<span class="cead-acad-eyebrow"><?php esc_html_e( 'Acceso denegado', 'cead-acad' ); ?></span>
			<h2 class="cead-acad-panel-h"><?php esc_html_e( 'No tenés permiso para ver este comunicado', 'cead-acad' ); ?></h2>
			<p><a href="<?php echo esc_url( cead_acad_url( 'panel/comunicados' ) ); ?>" class="cead-acad-btn cead-acad-btn--ghost">← <?php esc_html_e( 'Volver al feed', 'cead-acad' ); ?></a></p>
		</section>
		<?php
	};
	include CEAD_ACAD_DIR . 'templates/panel/shell.php';
	return;
}

// Marcar como leído.
Cead_Acad_Broadcasts_Reads::mark_read( $post->ID, $user->ID );

$author     = get_user_by( 'id', (int) $post->post_author );
$cats       = get_the_terms( $post->ID, Cead_Acad_Broadcasts_CPT::TAX_CAT );
$cat_label  = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0]->name : '';
$page_title = get_the_title( $post );

$body = function () use ( $post, $author, $cat_label ) {
	?>
	<article class="cead-acad-panel-section cead-acad-bcast">
		<p class="cead-acad-bcast-back">
			<a href="<?php echo esc_url( cead_acad_url( 'panel/comunicados' ) ); ?>">← <?php esc_html_e( 'Comunicados', 'cead-acad' ); ?></a>
		</p>
		<header class="cead-acad-bcast-head">
			<span class="cead-acad-eyebrow">
				<?php echo esc_html( get_the_date( '', $post ) ); ?>
				<?php if ( $cat_label ) : ?> · <?php echo esc_html( $cat_label ); ?><?php endif; ?>
				<?php if ( $author ) : ?> · <?php echo esc_html( $author->display_name ); ?><?php endif; ?>
			</span>
			<h1 class="cead-acad-bcast-title"><?php echo esc_html( get_the_title( $post ) ); ?></h1>
		</header>

		<?php if ( has_post_thumbnail( $post ) ) : ?>
			<figure class="cead-acad-bcast-figure">
				<?php echo get_the_post_thumbnail( $post, 'large' ); ?>
			</figure>
		<?php endif; ?>

		<div class="cead-acad-bcast-body">
			<?php echo apply_filters( 'the_content', $post->post_content ); ?>
		</div>
	</article>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
