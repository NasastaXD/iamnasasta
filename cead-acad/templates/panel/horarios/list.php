<?php
/**
 * /panel/horarios: agenda + lista agrupada por día.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user = wp_get_current_user();

$from = current_time( 'Y-m-d 00:00:00' );
$to   = date( 'Y-m-d 23:59:59', strtotime( '+60 days', current_time( 'timestamp' ) ) );

$events  = Cead_Acad_Schedule_Feed::for_user( $user->ID, $from, $to, 200 );
$by_day  = Cead_Acad_Schedule_Feed::group_by_day( $events );

$page_title = __( 'Horarios', 'cead-acad' );

$body = function () use ( $by_day, $events ) {
	?>
	<section class="cead-acad-panel-section">
		<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;flex-wrap:wrap">
			<div>
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Próximos 60 días', 'cead-acad' ); ?></span>
				<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Tu agenda', 'cead-acad' ); ?></h2>
				<p class="cead-acad-panel-sub"><?php esc_html_e( 'Clases, reuniones, exámenes y eventos dirigidos a vos.', 'cead-acad' ); ?></p>
			</div>
			<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cead_acad_event_ical' ), 'cead_acad_event_ical' ) ); ?>">
				<?php esc_html_e( 'Exportar iCal', 'cead-acad' ); ?>
			</a>
		</div>

		<?php if ( ! $events ) : ?>
			<div class="cead-acad-card cead-acad-card--empty" style="margin-top:1.5rem">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Sin eventos', 'cead-acad' ); ?></span>
				<h3><?php esc_html_e( 'Tu agenda está despejada', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Cuando dirección o tus docentes publiquen eventos, aparecerán acá.', 'cead-acad' ); ?></p>
			</div>
		<?php else : ?>
			<div class="cead-acad-agenda">
				<?php foreach ( $by_day as $day => $items ) :
					$ts = strtotime( $day );
					$label = $ts ? date_i18n( 'l j \d\e F', $ts ) : __( 'Sin fecha', 'cead-acad' );
				?>
					<div class="cead-acad-agenda-day">
						<div class="cead-acad-agenda-daykey">
							<span class="cead-acad-agenda-num"><?php echo $ts ? esc_html( date_i18n( 'd', $ts ) ) : '—'; ?></span>
							<span class="cead-acad-agenda-month"><?php echo $ts ? esc_html( date_i18n( 'M', $ts ) ) : ''; ?></span>
							<span class="cead-acad-agenda-weekday"><?php echo $ts ? esc_html( date_i18n( 'l', $ts ) ) : ''; ?></span>
						</div>
						<div class="cead-acad-agenda-items">
							<?php foreach ( $items as $e ) :
								$start = (string) get_post_meta( $e->ID, '_cead_acad_event_start',    true );
								$end   = (string) get_post_meta( $e->ID, '_cead_acad_event_end',      true );
								$loc   = (string) get_post_meta( $e->ID, '_cead_acad_event_location', true );
								$type  = (string) get_post_meta( $e->ID, '_cead_acad_event_type',     true );
								$all   = (bool)   get_post_meta( $e->ID, '_cead_acad_event_all_day',  true );
							?>
								<a class="cead-acad-agenda-item cead-acad-agenda-item--<?php echo esc_attr( $type ?: 'evento' ); ?>" href="<?php echo esc_url( cead_acad_url( 'panel/horarios/' . $e->ID ) ); ?>">
									<div class="cead-acad-agenda-time">
										<?php if ( $all ) : ?>
											<span><?php esc_html_e( 'Todo el día', 'cead-acad' ); ?></span>
										<?php else : ?>
											<span><?php echo $start ? esc_html( date_i18n( 'H:i', strtotime( $start ) ) ) : '—'; ?></span>
											<?php if ( $end ) : ?><span class="cead-acad-agenda-time-end">→ <?php echo esc_html( date_i18n( 'H:i', strtotime( $end ) ) ); ?></span><?php endif; ?>
										<?php endif; ?>
									</div>
									<div class="cead-acad-agenda-body">
										<span class="cead-acad-eyebrow"><?php echo esc_html( Cead_Acad_Schedule_CPT::type_label( $type ?: 'evento' ) ); ?></span>
										<h3 class="cead-acad-agenda-title"><?php echo esc_html( get_the_title( $e ) ); ?></h3>
										<?php if ( $loc ) : ?><p class="cead-acad-agenda-loc">📍 <?php echo esc_html( $loc ); ?></p><?php endif; ?>
									</div>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
