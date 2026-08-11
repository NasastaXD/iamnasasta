<?php
/**
 * "Horarios": repasar y corregir el horario de cualquier curso desde un solo
 * lugar, sin entrar curso por curso.
 *
 * Variables desde Cead_Acad_Courses_Admin::render_review():
 *   $courses      array<int,string>  id => título
 *   $course_id    int  curso elegido (0 = ninguno)
 *   $slots_count  int  cuántas clases tiene cargadas ese curso
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$page_url = admin_url( 'admin.php?page=cead-acad-horarios' );
?>
<div class="wrap cead-acad-admin-wrap">
	<h1><?php esc_html_e( 'Horarios', 'cead-acad' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Elegí un curso para revisar y corregir su horario semanal de materias. Es lo mismo que el cuadro "Horario de materias" dentro de cada curso, pero desde un solo lugar para repasarlos todos.', 'cead-acad' ); ?></p>

	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Horario guardado.', 'cead-acad' ); ?></p></div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( $page_url ); ?>" style="margin:1rem 0;display:flex;gap:.5rem;align-items:center">
		<input type="hidden" name="page" value="cead-acad-horarios">
		<label for="cead_acad_horario_curso" class="screen-reader-text"><?php esc_html_e( 'Elegir curso', 'cead-acad' ); ?></label>
		<select id="cead_acad_horario_curso" name="curso">
			<option value="0"><?php esc_html_e( '— elegí un curso —', 'cead-acad' ); ?></option>
			<?php foreach ( $courses as $cid => $ctitle ) : ?>
				<option value="<?php echo esc_attr( $cid ); ?>" <?php selected( $course_id, $cid ); ?>><?php echo esc_html( $ctitle ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'Ver', 'cead-acad' ); ?></button>
	</form>

	<?php if ( $course_id ) : ?>
		<h2 class="title">
			<?php echo esc_html( $courses[ $course_id ] ); ?>
			<a href="<?php echo esc_url( get_edit_post_link( $course_id ) ); ?>" style="font-size:.6em;font-weight:normal;margin-left:.5rem"><?php esc_html_e( 'Abrir el curso completo →', 'cead-acad' ); ?></a>
		</h2>
		<p class="description">
			<?php
			echo 0 === $slots_count
				? esc_html__( 'Este curso todavía no tiene horario cargado.', 'cead-acad' )
				: esc_html( sprintf(
					/* translators: %d: cantidad de clases cargadas */
					_n( '%d clase cargada.', '%d clases cargadas.', $slots_count, 'cead-acad' ),
					$slots_count
				) );
			?>
			<?php esc_html_e( 'Dejá la materia vacía para borrar una fila.', 'cead-acad' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cead_acad_horario_review' ); ?>
			<input type="hidden" name="action" value="cead_acad_horario_review_save">
			<input type="hidden" name="course_id" value="<?php echo (int) $course_id; ?>">
			<?php $this->render_horario_fields( $course_id ); ?>
			<?php submit_button( __( 'Guardar horario', 'cead-acad' ) ); ?>
		</form>
	<?php endif; ?>
</div>
