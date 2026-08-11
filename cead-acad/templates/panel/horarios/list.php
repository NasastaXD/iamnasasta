<?php
/**
 * /panel/horarios: horario semanal de materias del curso del alumno.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user = wp_get_current_user();

// Curso del usuario (meta o primer curso del roster).
$course_id = (int) get_user_meta( $user->ID, '_cead_acad_current_course_id', true );
if ( ! $course_id && class_exists( 'Cead_Acad_Courses_Roster' ) ) {
	$courses = Cead_Acad_Courses_Roster::courses_for_user( $user->ID );
	if ( $courses ) { $course_id = (int) $courses[0]; }
}

$slots = [];
if ( $course_id ) {
	$raw   = get_post_meta( $course_id, '_cead_acad_horario', true );
	$slots = is_array( $raw ) ? $raw : ( is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : [] );
	$slots = is_array( $slots ) ? $slots : [];
}

$days   = [ 1 => __( 'Lunes', 'cead-acad' ), 2 => __( 'Martes', 'cead-acad' ), 3 => __( 'Miércoles', 'cead-acad' ), 4 => __( 'Jueves', 'cead-acad' ), 5 => __( 'Viernes', 'cead-acad' ), 6 => __( 'Sábado', 'cead-acad' ), 7 => __( 'Domingo', 'cead-acad' ) ];
$by_day = [];
foreach ( $slots as $s ) {
	$d = (int) ( $s['dia'] ?? 0 );
	if ( $d < 1 || $d > 7 ) { continue; }
	$by_day[ $d ][] = $s;
}
ksort( $by_day );
foreach ( $by_day as $d => &$items ) {
	usort( $items, static function ( $a, $b ) { return strcmp( (string) ( $a['inicio'] ?? '' ), (string) ( $b['inicio'] ?? '' ) ); } );
}
unset( $items );

$course_title = $course_id ? get_the_title( $course_id ) : '';
$page_title   = __( 'Horarios', 'cead-acad' );

$body = function () use ( $by_day, $days, $course_title, $course_id ) {
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Horario de materias', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php echo $course_title ? esc_html( $course_title ) : esc_html__( 'Tu horario', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Tu horario semanal de clases. Para eventos y reuniones mirá el Calendario.', 'cead-acad' ); ?></p>

		<?php if ( ! $course_id ) : ?>
			<div class="cead-acad-card cead-acad-card--empty" style="margin-top:1.5rem">
				<h3><?php esc_html_e( 'No estás asignado/a a un curso', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Pedile a secretaría que te asigne a tu curso para ver el horario.', 'cead-acad' ); ?></p>
			</div>
		<?php elseif ( ! $by_day ) : ?>
			<div class="cead-acad-card cead-acad-card--empty" style="margin-top:1.5rem">
				<h3><?php esc_html_e( 'Horario sin cargar', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'El horario de materias de tu curso todavía no fue cargado.', 'cead-acad' ); ?></p>
			</div>
		<?php else : ?>
			<div class="cead-acad-agenda" style="margin-top:1.5rem">
				<?php foreach ( $by_day as $d => $items ) : ?>
					<div class="cead-acad-agenda-day">
						<div class="cead-acad-agenda-daykey">
							<span class="cead-acad-agenda-weekday"><?php echo esc_html( $days[ $d ] ); ?></span>
						</div>
						<div class="cead-acad-agenda-items">
							<?php foreach ( $items as $it ) :
								$hi   = (string) ( $it['inicio'] ?? '' );
								$hf   = (string) ( $it['fin'] ?? '' );
								$doc  = (string) ( $it['docente'] ?? '' );
								$aula = (string) ( $it['aula'] ?? '' );
							?>
								<div class="cead-acad-agenda-item cead-acad-agenda-item--clase">
									<div class="cead-acad-agenda-time">
										<span><?php echo $hi !== '' ? esc_html( $hi ) : '—'; ?></span>
										<?php if ( $hf !== '' ) : ?><span class="cead-acad-agenda-time-end">→ <?php echo esc_html( $hf ); ?></span><?php endif; ?>
									</div>
									<div class="cead-acad-agenda-body">
										<h3 class="cead-acad-agenda-title"><?php echo esc_html( (string) ( $it['materia'] ?? '' ) ); ?></h3>
										<?php if ( $doc !== '' ) : ?><p class="cead-acad-agenda-loc">👤 <?php echo esc_html( $doc ); ?></p><?php endif; ?>
										<?php if ( $aula !== '' ) : ?><p class="cead-acad-agenda-loc">📍 <?php echo esc_html( $aula ); ?></p><?php endif; ?>
									</div>
								</div>
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
