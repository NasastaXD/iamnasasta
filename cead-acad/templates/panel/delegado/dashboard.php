<?php
/**
 * /panel/delegado: dashboard del delegado/a — tareas pendientes del curso.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user = wp_get_current_user();

if ( ! current_user_can( 'cead_acad_complete_delegate_task' ) && ! current_user_can( 'cead_acad_assign_tasks' ) ) {
	wp_die( esc_html__( 'Esta sección es solo para delegados/as y dirección.', 'cead-acad' ), 403 );
}

$pendientes = Cead_Acad_Tasks_CPT::for_user( $user->ID, [ 'pendiente', 'en_curso' ] );
$hechas     = Cead_Acad_Tasks_CPT::for_user( $user->ID, [ 'hecha' ] );
$courses    = Cead_Acad_Courses_Roster::courses_for_user( $user->ID );

$page_title = __( 'Tareas del curso', 'cead-acad' );

$body = function () use ( $pendientes, $hechas, $courses ) {
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Panel del delegado/a', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Tareas del curso', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub">
			<?php if ( $courses ) :
				$titles = array_filter( array_map( fn( $id ) => get_the_title( $id ), $courses ) );
				/* translators: %s: lista de cursos asignados al delegado */
				printf( esc_html__( 'Cursos a tu cargo: %s.', 'cead-acad' ), '<strong>' . esc_html( implode( ', ', $titles ) ) . '</strong>' );
			else :
				esc_html_e( 'Todavía no estás asignado/a a un curso.', 'cead-acad' );
			endif; ?>
		</p>

		<?php if ( ! $pendientes && ! $hechas ) : ?>
			<div class="cead-acad-card cead-acad-card--empty" style="margin-top:1.5rem">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Vacío', 'cead-acad' ); ?></span>
				<h3><?php esc_html_e( 'Sin tareas asignadas', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Cuando dirección o secretaría asigne tareas al curso, aparecerán acá.', 'cead-acad' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $pendientes ) : ?>
			<h3 class="cead-acad-section-h"><?php esc_html_e( 'Pendientes', 'cead-acad' ); ?></h3>
			<div class="cead-acad-tasks">
				<?php foreach ( $pendientes as $t ) :
					$status   = (string) get_post_meta( $t->ID, '_cead_acad_task_status',   true ) ?: 'pendiente';
					$priority = (string) get_post_meta( $t->ID, '_cead_acad_task_priority', true ) ?: 'normal';
					$due      = (string) get_post_meta( $t->ID, '_cead_acad_task_due_date', true );
				?>
					<article class="cead-acad-task cead-acad-task--priority-<?php echo esc_attr( $priority ); ?>">
						<div class="cead-acad-task-head">
							<span class="cead-acad-pill"><?php echo esc_html( Cead_Acad_Tasks_CPT::priority_label( $priority ) ); ?></span>
							<span class="cead-acad-eyebrow"><?php echo esc_html( Cead_Acad_Tasks_CPT::status_label( $status ) ); ?></span>
							<?php if ( $due ) : ?>
								<span class="cead-acad-task-due"><?php
									/* translators: %s: fecha de vencimiento de la tarea */
									printf( esc_html__( 'Vence: %s', 'cead-acad' ), esc_html( date_i18n( 'j M Y', strtotime( $due ) ) ) );
								?></span>
							<?php endif; ?>
						</div>
						<h4><?php echo esc_html( get_the_title( $t ) ); ?></h4>
						<?php if ( $t->post_content ) : ?>
							<div class="cead-acad-task-body"><?php echo wp_kses_post( wpautop( $t->post_content ) ); ?></div>
						<?php endif; ?>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cead-acad-task-actions">
							<?php wp_nonce_field( 'cead_acad_task_set_status' ); ?>
							<input type="hidden" name="action" value="cead_acad_task_set_status">
							<input type="hidden" name="task_id" value="<?php echo (int) $t->ID; ?>">
							<?php if ( $status === 'pendiente' ) : ?>
								<button name="status" value="en_curso" class="cead-acad-btn cead-acad-btn--ghost"><?php esc_html_e( 'Marcar en curso', 'cead-acad' ); ?></button>
							<?php endif; ?>
							<button name="status" value="hecha" class="cead-acad-btn cead-acad-btn--primary"><?php esc_html_e( 'Marcar como hecha', 'cead-acad' ); ?></button>
						</form>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $hechas ) : ?>
			<h3 class="cead-acad-section-h"><?php esc_html_e( 'Completadas', 'cead-acad' ); ?></h3>
			<div class="cead-acad-feed">
				<?php foreach ( array_slice( $hechas, 0, 20 ) as $t ) : ?>
					<div class="cead-acad-feed-item is-read">
						<div class="cead-acad-feed-item-meta">
							<span class="cead-acad-eyebrow"><?php esc_html_e( 'Hecha', 'cead-acad' ); ?> · <?php echo esc_html( get_the_date( '', $t ) ); ?></span>
						</div>
						<h3 class="cead-acad-feed-item-title"><?php echo esc_html( get_the_title( $t ) ); ?></h3>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
