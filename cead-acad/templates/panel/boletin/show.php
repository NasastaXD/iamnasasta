<?php
/**
 * /panel/boletin: boletín del alumno actual (su propia vista).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user = wp_get_current_user();

if ( ! current_user_can( 'cead_acad_view_own_grades' ) ) {
	wp_die( esc_html__( 'No tenés permiso para ver boletines.', 'cead-acad' ) );
}

$bulletin = Cead_Acad_Grades_Db::bulletin_for_student( $user->ID );

$page_title = __( 'Boletín', 'cead-acad' );

$body = function () use ( $bulletin, $user ) {
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Tus calificaciones', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Boletín', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php echo esc_html( $user->display_name ); ?></p>

		<?php if ( ! $bulletin ) : ?>
			<div class="cead-acad-card cead-acad-card--empty" style="margin-top:1.5rem">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Vacío', 'cead-acad' ); ?></span>
				<h3><?php esc_html_e( 'Sin calificaciones cargadas', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Cuando dirección o tus docentes carguen notas, vas a verlas acá.', 'cead-acad' ); ?></p>
			</div>
		<?php else :
			// Recolectar todos los periodos vistos para el header.
			$all_periods = [];
			foreach ( $bulletin as $subj ) {
				foreach ( array_keys( $subj['grades'] ) as $p ) { $all_periods[ $p ] = true; }
			}
			ksort( $all_periods );
			$periods = array_keys( $all_periods );

			// Agrupar por curso para mostrar tabla por curso.
			$by_course = [];
			foreach ( $bulletin as $row ) {
				$by_course[ $row['course_id'] ][] = $row;
			}
			foreach ( $by_course as $course_id => $subjects ) :
				$course_title = $subjects[0]['course_title'];
		?>
			<h3 class="cead-acad-section-h"><?php echo esc_html( $course_title ); ?></h3>
			<table class="cead-acad-bulletin">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Materia', 'cead-acad' ); ?></th>
						<?php foreach ( $periods as $p ) : ?>
							<th><?php
								/* translators: %s nombre del periodo */
								printf( esc_html__( 'Periodo %s', 'cead-acad' ), esc_html( $p ) );
							?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $subjects as $subj ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $subj['subject_name'] ); ?></th>
						<?php foreach ( $periods as $p ) :
							$g = $subj['grades'][ $p ] ?? null;
						?>
							<td>
								<?php if ( $g ) : ?>
									<?php if ( null !== $g['score'] ) : ?>
										<strong><?php echo esc_html( number_format( $g['score'], 2 ) ); ?></strong>
									<?php endif; ?>
									<?php if ( $g['letter'] !== '' ) : ?>
										<span class="cead-acad-bulletin-letter"><?php echo esc_html( $g['letter'] ); ?></span>
									<?php endif; ?>
								<?php else : ?>
									<span class="cead-acad-bulletin-empty">—</span>
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach;
		endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
