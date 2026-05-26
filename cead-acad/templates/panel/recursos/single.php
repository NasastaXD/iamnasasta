<?php
/**
 * /panel/recursos/{id}: detalle de recurso.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$resource_id = isset( $resource_id ) ? (int) $resource_id : 0;
$user        = wp_get_current_user();
$post        = $resource_id ? get_post( $resource_id ) : null;

$can_view = $post
	&& Cead_Acad_Resources_CPT::POST_TYPE === $post->post_type
	&& 'publish' === $post->post_status
	&& Cead_Acad_Resources_Acl::user_can_view( $resource_id, $user->ID );

if ( ! $can_view ) {
	status_header( 404 );
	$page_title = __( 'No encontrado', 'cead-acad' );
	$body = function () {
		?>
		<section class="cead-acad-panel-section">
			<span class="cead-acad-eyebrow"><?php esc_html_e( 'Error', 'cead-acad' ); ?></span>
			<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Recurso no disponible', 'cead-acad' ); ?></h2>
			<p><a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( cead_acad_url( 'panel/recursos' ) ); ?>">← <?php esc_html_e( 'Volver a la biblioteca', 'cead-acad' ); ?></a></p>
		</section>
		<?php
	};
	include CEAD_ACAD_DIR . 'templates/panel/shell.php';
	return;
}

$resource_url = Cead_Acad_Resources_CPT::resource_url( $post->ID );
$subjects     = get_the_terms( $post->ID, Cead_Acad_Resources_CPT::TAX_SUBJECT );
$types        = get_the_terms( $post->ID, Cead_Acad_Resources_CPT::TAX_TYPE );

$page_title = get_the_title( $post );

$body = function () use ( $post, $resource_url, $subjects, $types ) {
	?>
	<article class="cead-acad-panel-section cead-acad-bcast">
		<p class="cead-acad-bcast-back">
			<a href="<?php echo esc_url( cead_acad_url( 'panel/recursos' ) ); ?>">← <?php esc_html_e( 'Biblioteca', 'cead-acad' ); ?></a>
		</p>
		<header class="cead-acad-bcast-head">
			<span class="cead-acad-eyebrow">
				<?php
				$parts = [];
				if ( $types && ! is_wp_error( $types ) ) {
					$parts[] = $types[0]->name;
				}
				if ( $subjects && ! is_wp_error( $subjects ) ) {
					foreach ( $subjects as $s ) { $parts[] = $s->name; }
				}
				echo esc_html( implode( ' · ', $parts ) );
				?>
			</span>
			<h1 class="cead-acad-bcast-title"><?php echo esc_html( get_the_title( $post ) ); ?></h1>
		</header>

		<?php if ( has_post_thumbnail( $post ) ) : ?>
			<figure class="cead-acad-bcast-figure"><?php echo get_the_post_thumbnail( $post, 'large' ); ?></figure>
		<?php endif; ?>

		<?php if ( $post->post_content ) : ?>
			<div class="cead-acad-bcast-body">
				<?php echo apply_filters( 'the_content', $post->post_content ); ?>
			</div>
		<?php endif; ?>

		<?php if ( $resource_url ) : ?>
			<p style="margin-top:2rem">
				<a class="cead-acad-btn cead-acad-btn--primary" href="<?php echo esc_url( $resource_url ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Abrir recurso', 'cead-acad' ); ?> ↗
				</a>
			</p>
		<?php endif; ?>
	</article>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
