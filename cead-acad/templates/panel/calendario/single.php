<?php
/**
 * /panel/calendario/{id}: detalle de evento.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$event_id = isset( $event_id ) ? (int) $event_id : 0;
$user     = wp_get_current_user();
$post     = $event_id ? get_post( $event_id ) : null;

$can_view = $post
	&& Cead_Acad_Schedule_CPT::POST_TYPE === $post->post_type
	&& 'publish' === $post->post_status
	&& in_array( (int) $event_id, Cead_Acad_Audiences::subjects_for_user( 'event', $user->ID ), true );

if ( ! $can_view ) {
	status_header( 404 );
	$page_title = __( 'No encontrado', 'cead-acad' );
	$body = function () {
		?>
		<section class="cead-acad-panel-section">
			<span class="cead-acad-eyebrow"><?php esc_html_e( 'Error', 'cead-acad' ); ?></span>
			<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Evento no disponible', 'cead-acad' ); ?></h2>
			<p><a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( cead_acad_url( 'panel/calendario' ) ); ?>">← <?php esc_html_e( 'Volver al calendario', 'cead-acad' ); ?></a></p>
		</section>
		<?php
	};
	include CEAD_ACAD_DIR . 'templates/panel/shell.php';
	return;
}

$start = (string) get_post_meta( $post->ID, '_cead_acad_event_start',    true );
$end   = (string) get_post_meta( $post->ID, '_cead_acad_event_end',      true );
$all   = (bool)   get_post_meta( $post->ID, '_cead_acad_event_all_day',  true );
$loc   = (string) get_post_meta( $post->ID, '_cead_acad_event_location', true );
$type  = (string) get_post_meta( $post->ID, '_cead_acad_event_type',     true ) ?: 'evento';

$page_title = get_the_title( $post );

$body = function () use ( $post, $start, $end, $all, $loc, $type ) {
	?>
	<article class="cead-acad-panel-section cead-acad-bcast">
		<p class="cead-acad-bcast-back">
			<a href="<?php echo esc_url( cead_acad_url( 'panel/calendario' ) ); ?>">← <?php esc_html_e( 'Calendario', 'cead-acad' ); ?></a>
		</p>
		<header class="cead-acad-bcast-head">
			<span class="cead-acad-eyebrow">
				<?php echo esc_html( Cead_Acad_Schedule_CPT::type_label( $type ) ); ?>
				<?php if ( $start ) : ?> · <?php echo esc_html( date_i18n( 'l j \d\e F · H:i', strtotime( $start ) ) ); ?><?php endif; ?>
				<?php if ( $end ) : ?> → <?php echo esc_html( date_i18n( 'H:i', strtotime( $end ) ) ); ?><?php endif; ?>
				<?php if ( $all ) : ?> · <?php esc_html_e( 'Todo el día', 'cead-acad' ); ?><?php endif; ?>
			</span>
			<h1 class="cead-acad-bcast-title"><?php echo esc_html( get_the_title( $post ) ); ?></h1>
			<?php if ( $loc ) : ?>
				<p class="cead-acad-bcast-loc">📍 <?php echo esc_html( $loc ); ?></p>
			<?php endif; ?>
		</header>

		<?php if ( $post->post_content ) : ?>
			<div class="cead-acad-bcast-body">
				<?php echo apply_filters( 'the_content', $post->post_content ); ?>
			</div>
		<?php endif; ?>
	</article>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
