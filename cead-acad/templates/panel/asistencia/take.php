<?php
/**
 * /panel/asistencia: toma de lista (docentes) y consolidado del día (dirección/secretaría).
 *
 * Todos arrancan en «presente» (lo más común): un toque cambia el estado.
 * En la web no hay modo sin conexión; para el aula está la app.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$uid     = get_current_user_id();
$cursos  = Cead_Acad_Asistencias::cursos_de( $uid );
$curso   = isset( $_GET['curso'] ) ? (int) $_GET['curso'] : ( $cursos[0] ?? 0 );
$fecha   = isset( $_GET['fecha'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['fecha'] ) ? (string) $_GET['fecha'] : current_time( 'Y-m-d' );
$gestion = Cead_Acad_Asistencias::puede_gestionar( $uid );
$inicio  = time();

if ( $gestion && isset( $_GET['consolidado'] ) ) {
	Cead_Acad_Estudio::registrar( 'asistencia_consolidada', [ 'user_id' => $uid, 'canal' => 'web', 'entidad_tipo' => 'asistencia', 'meta' => [ 'fecha' => $fecha ] ] );
}

$page_title = __( 'Asistencia', 'cead-acad' );

$body = function () use ( $cursos, $curso, $fecha, $gestion, $inicio, $uid ) {
	$def = (string) get_option( 'cead_acad_asistencia_estado_defecto', 'presente' );
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Clases', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Asistencia', 'cead-acad' ); ?></h2>
		<?php if ( isset( $_GET['ok'] ) ) : ?><p class="cead-acad-notice"><?php esc_html_e( 'Asistencia guardada.', 'cead-acad' ); ?></p><?php endif; ?>
		<?php if ( isset( $_GET['err'] ) ) : ?><p class="cead-acad-notice cead-acad-notice--error"><?php esc_html_e( 'No se pudo guardar.', 'cead-acad' ); ?></p><?php endif; ?>

		<form method="get" class="cead-acad-card" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
			<label class="cead-acad-field"><span><?php esc_html_e( 'Curso', 'cead-acad' ); ?></span>
				<select name="curso"><?php foreach ( $cursos as $c ) : ?><option value="<?php echo (int) $c; ?>" <?php selected( $c, $curso ); ?>><?php echo esc_html( get_the_title( $c ) ); ?></option><?php endforeach; ?></select>
			</label>
			<label class="cead-acad-field"><span><?php esc_html_e( 'Fecha', 'cead-acad' ); ?></span><input type="date" name="fecha" value="<?php echo esc_attr( $fecha ); ?>"></label>
			<button class="cead-acad-btn" type="submit"><?php esc_html_e( 'Ver lista', 'cead-acad' ); ?></button>
			<?php if ( $gestion ) : ?><button class="cead-acad-btn cead-acad-btn--ghost" name="consolidado" value="1"><?php esc_html_e( 'Consolidado del día', 'cead-acad' ); ?></button><?php endif; ?>
		</form>

		<?php if ( $gestion && isset( $_GET['consolidado'] ) ) : ?>
			<table class="cead-acad-table" style="margin-top:1rem"><thead><tr>
				<th><?php esc_html_e( 'Curso', 'cead-acad' ); ?></th><th><?php esc_html_e( 'Presentes', 'cead-acad' ); ?></th><th><?php esc_html_e( 'Ausentes', 'cead-acad' ); ?></th><th><?php esc_html_e( 'Tarde', 'cead-acad' ); ?></th><th><?php esc_html_e( 'Justificados', 'cead-acad' ); ?></th>
			</tr></thead><tbody>
			<?php foreach ( Cead_Acad_Asistencias::consolidado( $fecha ) as $f ) : ?>
				<tr><td><?php echo esc_html( $f['curso'] . ( $f['bloque'] ? ' · ' . $f['bloque'] : '' ) ); ?></td><td><?php echo (int) $f['presente']; ?></td><td><?php echo (int) $f['ausente']; ?></td><td><?php echo (int) $f['tarde']; ?></td><td><?php echo (int) $f['justificado']; ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php elseif ( $curso && Cead_Acad_Asistencias::puede_tomar( $uid, $curso ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cead-acad-card" style="margin-top:1rem">
				<input type="hidden" name="action" value="cead_acad_asistencia">
				<?php wp_nonce_field( 'cead_acad_asistencia' ); ?>
				<input type="hidden" name="curso_id" value="<?php echo (int) $curso; ?>">
				<input type="hidden" name="fecha" value="<?php echo esc_attr( $fecha ); ?>">
				<input type="hidden" name="clave" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
				<input type="hidden" name="inicio" value="<?php echo (int) $inicio; ?>">
				<input type="hidden" name="inicio_firma" value="<?php echo esc_attr( wp_hash( 'asistencia|' . $inicio . '|' . $uid ) ); ?>">
				<?php foreach ( Cead_Acad_Asistencias::alumnos( $curso ) as $a ) : $u = get_userdata( $a ); ?>
					<fieldset style="display:flex;gap:.75rem;align-items:center;border:0;padding:.35rem 0;border-bottom:1px solid var(--cead-acad-border)">
						<legend style="flex:1;float:left"><?php echo esc_html( $u ? $u->display_name : '#' . $a ); ?></legend>
						<?php foreach ( Cead_Acad_Asistencias::ESTADOS as $e ) : ?>
							<label><input type="radio" name="estado[<?php echo (int) $a; ?>]" value="<?php echo esc_attr( $e ); ?>" <?php checked( $e, $def ); ?>> <?php echo esc_html( ucfirst( $e ) ); ?></label>
						<?php endforeach; ?>
					</fieldset>
				<?php endforeach; ?>
				<button class="cead-acad-btn" type="submit" style="margin-top:1rem"><?php esc_html_e( 'Guardar asistencia', 'cead-acad' ); ?></button>
			</form>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
