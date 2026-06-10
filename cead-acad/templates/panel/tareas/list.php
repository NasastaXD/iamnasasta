<?php
/**
 * /panel/tareas: tareas del curso del alumno, con estado personal y entrega.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user  = wp_get_current_user();
$tasks = Cead_Acad_Tasks_Frontend::tasks_for_user( $user->ID );

// Separar canceladas y partir por estado personal (hecha por mí o no).
$pend = [];
$done = [];
foreach ( $tasks as $t ) {
	$status = (string) get_post_meta( $t->ID, '_cead_acad_task_status', true );
	if ( 'cancelada' === $status ) { continue; }
	if ( Cead_Acad_Tasks_Frontend::is_done( $user->ID, $t->ID ) ) {
		$done[] = $t;
	} else {
		$pend[] = $t;
	}
}

$page_title = __( 'Mis tareas', 'cead-acad' );

$body = function () use ( $user, $pend, $done ) {
	$err = isset( $_GET['err'] ) ? sanitize_key( (string) $_GET['err'] ) : '';
	$ok  = isset( $_GET['done'] );

	$prio_label = [ 'baja' => __( 'Baja', 'cead-acad' ), 'normal' => __( 'Normal', 'cead-acad' ), 'alta' => __( 'Alta', 'cead-acad' ) ];

	$render = function ( $t, $is_done ) use ( $user, $prio_label ) {
		$course = (int) get_post_meta( $t->ID, '_cead_acad_task_course', true );
		$due    = (string) get_post_meta( $t->ID, '_cead_acad_task_due_date', true );
		$prio   = (string) get_post_meta( $t->ID, '_cead_acad_task_priority', true ) ?: 'normal';
		$sub_id = Cead_Acad_Tasks_Frontend::submission_id( $user->ID, $t->ID );

		$due_txt = '';
		$due_cls = '';
		if ( $due ) {
			$dts  = strtotime( $due . ' 23:59:59' );
			$days = (int) floor( ( $dts - current_time( 'timestamp' ) ) / DAY_IN_SECONDS );
			$due_txt = date_i18n( 'd/m/Y', strtotime( $due ) );
			if ( ! $is_done ) {
				if ( $days < 0 )      { $due_cls = 'is-late';  $due_txt .= ' · ' . __( 'vencida', 'cead-acad' ); }
				elseif ( 0 === $days ) { $due_cls = 'is-soon'; $due_txt .= ' · ' . __( 'vence hoy', 'cead-acad' ); }
				/* translators: %d: días restantes hasta el vencimiento */
				elseif ( $days <= 3 )  { $due_cls = 'is-soon'; $due_txt .= ' · ' . sprintf( _n( 'en %d día', 'en %d días', $days, 'cead-acad' ), $days ); }
			}
		}
		?>
		<div class="cead-acad-card cead-acad-task<?php echo $is_done ? ' is-done' : ''; ?>">
			<div class="cead-acad-task-head">
				<div>
					<span class="cead-acad-eyebrow"><?php echo $course ? esc_html( get_the_title( $course ) ) : esc_html__( 'Tarea', 'cead-acad' ); ?></span>
					<h3><?php echo esc_html( get_the_title( $t ) ); ?></h3>
				</div>
				<span class="cead-acad-prio cead-acad-prio--<?php echo esc_attr( $prio ); ?>"><?php echo esc_html( $prio_label[ $prio ] ?? $prio ); ?></span>
			</div>

			<?php if ( $due_txt ) : ?>
				<p class="cead-acad-task-due <?php echo esc_attr( $due_cls ); ?>">📅 <?php echo esc_html( $due_txt ); ?></p>
			<?php endif; ?>

			<?php $content = wp_strip_all_tags( $t->post_content ); if ( $content ) : ?>
				<p><?php echo esc_html( wp_trim_words( $content, 40 ) ); ?></p>
			<?php endif; ?>

			<div class="cead-acad-task-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cead_acad_task_done">
					<input type="hidden" name="task_id" value="<?php echo (int) $t->ID; ?>">
					<?php wp_nonce_field( 'cead_acad_task_done' ); ?>
					<button type="submit" class="cead-acad-btn <?php echo $is_done ? 'cead-acad-btn--ghost' : ''; ?>">
						<?php echo $is_done ? esc_html__( '↩ Marcar como pendiente', 'cead-acad' ) : esc_html__( '✓ Marcar como hecha', 'cead-acad' ); ?>
					</button>
				</form>

				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cead-acad-task-upload">
					<input type="hidden" name="action" value="cead_acad_task_submit">
					<input type="hidden" name="task_id" value="<?php echo (int) $t->ID; ?>">
					<?php wp_nonce_field( 'cead_acad_task_submit' ); ?>
					<label class="cead-acad-file-btn">
						<input type="file" name="entrega">
						<span><?php echo $sub_id ? esc_html__( 'Reemplazar entrega', 'cead-acad' ) : esc_html__( 'Subir entrega', 'cead-acad' ); ?></span>
					</label>
					<button type="submit" class="cead-acad-btn cead-acad-btn--ghost"><?php esc_html_e( 'Enviar', 'cead-acad' ); ?></button>
				</form>
			</div>

			<?php if ( $sub_id ) : $url = wp_get_attachment_url( $sub_id ); if ( $url ) : ?>
				<p class="cead-acad-task-sub">📎 <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ver mi entrega', 'cead-acad' ); ?></a></p>
			<?php endif; endif; ?>
		</div>
		<?php
	};
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Agenda', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Mis tareas', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Tareas y entregas de tu curso. Marcá lo que vas completando.', 'cead-acad' ); ?></p>

		<?php if ( $ok ) : ?>
			<div class="cead-acad-msg cead-acad-msg--ok"><?php esc_html_e( 'Entrega subida.', 'cead-acad' ); ?></div>
		<?php elseif ( $err ) : ?>
			<div class="cead-acad-msg cead-acad-msg--err"><?php esc_html_e( 'No se pudo subir la entrega. Probá de nuevo.', 'cead-acad' ); ?></div>
		<?php endif; ?>

		<?php if ( ! $pend && ! $done ) : ?>
			<div class="cead-acad-card cead-acad-card--empty" style="margin-top:1.5rem">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Todo tranquilo', 'cead-acad' ); ?></span>
				<h3><?php esc_html_e( 'No tenés tareas', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Cuando el delegado o un docente cargue tareas para tu curso, aparecerán acá.', 'cead-acad' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $pend ) : ?>
			<h3 class="cead-acad-section-h" style="margin-top:1.5rem"><?php esc_html_e( 'Pendientes', 'cead-acad' ); ?> <span class="cead-acad-pill"><?php echo (int) count( $pend ); ?></span></h3>
			<div class="cead-acad-grid cead-acad-grid--2">
				<?php foreach ( $pend as $t ) { $render( $t, false ); } ?>
			</div>
		<?php endif; ?>

		<?php if ( $done ) : ?>
			<h3 class="cead-acad-section-h" style="margin-top:2rem"><?php esc_html_e( 'Hechas', 'cead-acad' ); ?></h3>
			<div class="cead-acad-grid cead-acad-grid--2">
				<?php foreach ( $done as $t ) { $render( $t, true ); } ?>
			</div>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
