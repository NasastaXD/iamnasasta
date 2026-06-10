<?php
/**
 * Lista de encuestas: /panel/encuestas
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user = wp_get_current_user();
$ids  = Cead_Acad_Audiences::subjects_for_user( 'survey', $user->ID );
$now  = current_time( 'timestamp' );

$open_surveys    = [];
$pending_user    = [];
$closed_surveys  = [];

if ( $ids ) {
	$posts = get_posts( [
		'post_type'      => Cead_Acad_Surveys_CPT::POST_TYPE,
		'post_status'    => 'publish',
		'post__in'       => $ids,
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	] );

	foreach ( $posts as $p ) {
		$is_open = Cead_Acad_Surveys_CPT::is_open( $p->ID );
		$answered = ! Cead_Acad_Surveys_CPT::is_anonymous( $p->ID ) && Cead_Acad_Surveys_Responses::user_already_responded( $p->ID, $user->ID );
		if ( ! $is_open ) {
			$closed_surveys[] = $p;
		} elseif ( $answered ) {
			$pending_user[] = $p; // ya respondió → mostramos en "completadas"
		} else {
			$open_surveys[] = $p;
		}
	}
}

$page_title = __( 'Encuestas', 'cead-acad' );

$body = function () use ( $open_surveys, $pending_user, $closed_surveys, $user ) {
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Encuestas', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Tu actividad', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Estas son las encuestas dirigidas a vos.', 'cead-acad' ); ?></p>

		<?php if ( ! $open_surveys && ! $pending_user && ! $closed_surveys ) : ?>
			<div class="cead-acad-card cead-acad-card--empty" style="margin-top:1.5rem">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Vacío', 'cead-acad' ); ?></span>
				<h3><?php esc_html_e( 'Sin encuestas', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'No tenés encuestas dirigidas en este momento.', 'cead-acad' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $open_surveys ) : ?>
			<h3 class="cead-acad-section-h"><?php esc_html_e( 'Pendientes de responder', 'cead-acad' ); ?></h3>
			<div class="cead-acad-feed">
				<?php foreach ( $open_surveys as $p ) :
					$closes = (string) get_post_meta( $p->ID, '_cead_acad_survey_closes_at', true );
				?>
					<a class="cead-acad-feed-item is-unread" href="<?php echo esc_url( cead_acad_url( 'panel/encuestas/' . $p->ID ) ); ?>">
						<div class="cead-acad-feed-item-meta">
							<span class="cead-acad-feed-dot" aria-hidden="true"></span>
							<span class="cead-acad-eyebrow">
								<?php if ( $closes ) : ?><?php /* translators: %s: fecha y hora de cierre de la encuesta */ printf( esc_html__( 'Cierra el %s', 'cead-acad' ), esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $closes ) ) ); ?>
								<?php else : ?><?php esc_html_e( 'Abierta', 'cead-acad' ); ?><?php endif; ?>
							</span>
						</div>
						<h3 class="cead-acad-feed-item-title"><?php echo esc_html( get_the_title( $p ) ); ?></h3>
						<?php if ( $p->post_content ) : ?>
							<p class="cead-acad-feed-item-excerpt"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $p->post_content ), 24 ) ); ?></p>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $pending_user ) : ?>
			<h3 class="cead-acad-section-h"><?php esc_html_e( 'Ya respondidas', 'cead-acad' ); ?></h3>
			<div class="cead-acad-feed">
				<?php foreach ( $pending_user as $p ) : ?>
					<div class="cead-acad-feed-item is-read">
						<div class="cead-acad-feed-item-meta">
							<span class="cead-acad-eyebrow"><?php esc_html_e( 'Respondida', 'cead-acad' ); ?></span>
						</div>
						<h3 class="cead-acad-feed-item-title"><?php echo esc_html( get_the_title( $p ) ); ?></h3>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $closed_surveys ) : ?>
			<h3 class="cead-acad-section-h"><?php esc_html_e( 'Cerradas', 'cead-acad' ); ?></h3>
			<div class="cead-acad-feed">
				<?php foreach ( $closed_surveys as $p ) : ?>
					<div class="cead-acad-feed-item is-read">
						<div class="cead-acad-feed-item-meta">
							<span class="cead-acad-eyebrow"><?php esc_html_e( 'Cerrada', 'cead-acad' ); ?></span>
						</div>
						<h3 class="cead-acad-feed-item-title"><?php echo esc_html( get_the_title( $p ) ); ?></h3>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
