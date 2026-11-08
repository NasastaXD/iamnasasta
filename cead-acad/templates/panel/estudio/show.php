<?php
/**
 * /panel/estudio: transparencia del estudio piloto (spec 2.7).
 * Qué se recolecta, qué no, por cuánto tiempo, mi estado y cómo retirarme.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$uid    = get_current_user_id();
$cfg    = Cead_Acad_Estudio::config();
$estado = Cead_Acad_Estudio::estado_consentimiento( $uid );
$t      = Cead_Acad_Estudio_Consentimientos::transparencia();

$page_title = __( 'Estudio sobre la app', 'cead-acad' );

$body = function () use ( $cfg, $estado, $t, $uid ) {
	$estados = [
		'activo'   => __( 'Participás del estudio.', 'cead-acad' ),
		'ninguno'  => __( 'Todavía no hay consentimiento registrado: tus datos no entran al estudio.', 'cead-acad' ),
		'retirado' => __( 'Te retiraste: tus datos ya no entran al estudio.', 'cead-acad' ),
	];
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Privacidad', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Estudio sobre la app', 'cead-acad' ); ?></h2>
		<?php if ( ! $cfg['activo'] ) : ?>
			<p class="cead-acad-panel-sub"><?php esc_html_e( 'Este colegio no está participando de ningún estudio.', 'cead-acad' ); ?></p>
		<?php else : ?>
			<p class="cead-acad-panel-sub"><?php esc_html_e( 'Estamos estudiando si la app mejora la comunicación del colegio y la toma de asistencia. Esto es lo que se recolecta y lo que no.', 'cead-acad' ); ?></p>
			<div class="cead-acad-card">
				<p><strong><?php echo esc_html( $estados[ $estado ] ?? '' ); ?></strong></p>
				<h3><?php esc_html_e( 'Qué se recolecta', 'cead-acad' ); ?></h3>
				<ul><?php foreach ( $t['que_se_recolecta'] as $x ) : ?><li><?php echo esc_html( $x ); ?></li><?php endforeach; ?></ul>
				<p><?php echo esc_html( $t['como'] ); ?></p>
				<h3><?php esc_html_e( 'Qué NO se recolecta', 'cead-acad' ); ?></h3>
				<ul><?php foreach ( $t['que_no'] as $x ) : ?><li><?php echo esc_html( $x ); ?></li><?php endforeach; ?></ul>
				<h3><?php esc_html_e( 'Por cuánto tiempo', 'cead-acad' ); ?></h3>
				<p><?php echo esc_html( $t['cuanto_tiempo'] ); ?></p>
				<p><?php esc_html_e( 'La encuesta mensual es parte del acuerdo de uso de la app con el colegio: se pide a todos, aunque te retires (en ese caso, lo que respondas no se guarda en el estudio).', 'cead-acad' ); ?></p>

				<?php if ( 'individual' === $cfg['consentimiento_modo'] && 'ninguno' === $estado ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="cead_acad_estudio_dar"><?php wp_nonce_field( 'cead_acad_estudio_dar' ); ?>
						<?php if ( 'alumno' === Cead_Acad_Estudio::rol_de( $uid ) ) : ?>
							<label><input type="checkbox" name="mayor" value="1"> <?php esc_html_e( 'Tengo 18 años o más', 'cead-acad' ); ?></label>
						<?php endif; ?>
						<button class="cead-acad-btn" type="submit"><?php esc_html_e( 'Acepto participar', 'cead-acad' ); ?></button>
					</form>
				<?php endif; ?>

				<?php if ( $cfg['permitir_retiro'] && 'retirado' !== $estado ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( '¿Seguro? Retirarse es definitivo para este estudio.', 'cead-acad' ) ); ?>')">
						<input type="hidden" name="action" value="cead_acad_estudio_retirar"><?php wp_nonce_field( 'cead_acad_estudio_retirar' ); ?>
						<button class="cead-acad-btn cead-acad-btn--ghost" type="submit"><?php esc_html_e( 'Retirarme del estudio', 'cead-acad' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
