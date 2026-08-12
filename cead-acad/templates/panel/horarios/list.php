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

/*
 * Delegado/a o superior: puede repasar el horario de OTROS cursos, en una
 * sección aparte de la suya. No reemplaza "Tu horario" de arriba —una
 * persona con este permiso sigue teniendo su propio curso, si le
 * corresponde— es una consulta adicional, de solo lectura.
 */
$can_view_others  = current_user_can( 'cead_acad_view_other_schedules' );
$other_courses    = $can_view_others ? cead_acad_courses_for_select() : [];
$other_course_id  = 0;
$other_slots_days = [];
if ( $can_view_others && ! empty( $_GET['curso_id'] ) ) {
	$other_course_id = (int) $_GET['curso_id'];
	if ( ! isset( $other_courses[ $other_course_id ] ) ) {
		$other_course_id = 0;
	}
}
if ( $other_course_id ) {
	$raw   = get_post_meta( $other_course_id, '_cead_acad_horario', true );
	$oslots = is_array( $raw ) ? $raw : ( is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : [] );
	$oslots = is_array( $oslots ) ? $oslots : [];
	foreach ( $oslots as $s ) {
		$d = (int) ( $s['dia'] ?? 0 );
		if ( $d < 1 || $d > 7 ) { continue; }
		$other_slots_days[ $d ][] = $s;
	}
	ksort( $other_slots_days );
	foreach ( $other_slots_days as $d => &$items ) {
		usort( $items, static function ( $a, $b ) { return strcmp( (string) ( $a['inicio'] ?? '' ), (string) ( $b['inicio'] ?? '' ) ); } );
	}
	unset( $items );
}

/** Misma grilla día-por-día que "Tu horario", reusada para el curso ajeno. */
$render_agenda = function ( $by_day, $days ) {
	foreach ( $by_day as $d => $items ) : ?>
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
	<?php endforeach;
};

$body = function () use ( $by_day, $days, $course_title, $course_id, $can_view_others, $other_courses, $other_course_id, $other_slots_days, $render_agenda ) {
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
				<?php $render_agenda( $by_day, $days ); ?>
			</div>
		<?php endif; ?>
	</section>

	<?php if ( $can_view_others && $other_courses ) : ?>
	<section id="otros-cursos" class="cead-acad-panel-section" style="margin-top:2rem">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Consulta', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Horario de otros cursos', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Para repasar el horario de un curso que no es el tuyo.', 'cead-acad' ); ?></p>

		<?php
		/*
		 * El `#otros-cursos` del action es el respaldo para cuando no hay
		 * JavaScript: al enviarse, el navegador recarga y aterriza en esta
		 * sección en vez de al tope de la página, que era justo lo molesto
		 * (elegías un curso y tenías que volver a bajar para ver el resultado).
		 * Con JS, el router ni siquiera recarga: cambia el contenido sin moverte.
		 */
		?>
		<form method="get" action="<?php echo esc_url( cead_acad_url( 'panel/horarios' ) ); ?>#otros-cursos" style="margin-top:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
			<label for="cead_acad_otro_curso" class="screen-reader-text"><?php esc_html_e( 'Elegir curso', 'cead-acad' ); ?></label>
			<select id="cead_acad_otro_curso" name="curso_id">
				<option value="0"><?php esc_html_e( '— elegí un curso —', 'cead-acad' ); ?></option>
				<?php foreach ( $other_courses as $cid => $ctitle ) : ?>
					<option value="<?php echo esc_attr( $cid ); ?>" <?php selected( $other_course_id, $cid ); ?>><?php echo esc_html( $ctitle ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="cead-acad-btn"><?php esc_html_e( 'Ver', 'cead-acad' ); ?></button>
		</form>

		<?php if ( $other_course_id && ! $other_slots_days ) : ?>
			<div class="cead-acad-card cead-acad-card--empty" style="margin-top:1.5rem">
				<h3><?php esc_html_e( 'Horario sin cargar', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Ese curso todavía no tiene horario de materias cargado.', 'cead-acad' ); ?></p>
			</div>
		<?php elseif ( $other_slots_days ) : ?>
			<div class="cead-acad-agenda" style="margin-top:1.5rem">
				<?php $render_agenda( $other_slots_days, $days ); ?>
			</div>
		<?php endif; ?>
	</section>
	<?php endif; ?>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
