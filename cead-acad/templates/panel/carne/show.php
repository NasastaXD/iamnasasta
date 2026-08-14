<?php
/**
 * /panel/carne: carné digital del usuario con QR de verificación.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user  = wp_get_current_user();
$role  = cead_acad_user_role( $user->ID );
$roles = Cead_Acad_Capabilities::roles();
$rdisp = $roles[ $role ]['display'] ?? $role;

$avatar = Cead_Acad_Account::avatar_url( $user->ID, 'medium' );
$doc    = (string) get_user_meta( $user->ID, '_cead_acad_document_id', true );

$course_id = (int) get_user_meta( $user->ID, '_cead_acad_current_course_id', true );
if ( ! $course_id && class_exists( 'Cead_Acad_Courses_Roster' ) ) {
	$cs = Cead_Acad_Courses_Roster::courses_for_user( $user->ID );
	if ( $cs ) { $course_id = (int) $cs[0]; }
}
$course = $course_id ? get_the_title( $course_id ) : '';

$token      = Cead_Acad_Account::carne_token( $user->ID );
$verify_url = cead_acad_url( 'carne/' . $token );

/*
 * El QR se dibuja acá, en el servidor del colegio.
 *
 * Antes lo generaba `api.qrserver.com` con la URL en el query string. Esa URL
 * lleva el token del carné, que es la única prueba de identidad de la tarjeta y
 * no vence: mandárselo a un tercero es entregarle una credencial permanente de
 * cada alumno que abre su carné, y además dejaba la tarjeta con la imagen rota
 * cuando ese servicio no estaba disponible — justo en la puerta del colegio,
 * que es donde se muestra.
 */
$qr_svg = Cead_Acad_QR::svg( $verify_url, [
	'class' => 'cead-acad-carne-qr-img',
	'title' => __( 'Código QR de verificación', 'cead-acad' ),
] );

$page_title = __( 'Mi carné', 'cead-acad' );

$body = function () use ( $user, $rdisp, $avatar, $doc, $course, $qr_svg, $verify_url ) {
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Identificación', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Mi carné digital', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Mostralo en la entrada o escaneá el QR para verificarlo.', 'cead-acad' ); ?></p>

		<div class="cead-acad-carne" id="cead-acad-carne">
			<div class="cead-acad-carne-top">
				<span class="cead-acad-carne-marks" aria-hidden="true"><span class="m m--brand"></span><span class="m m--blue"></span><span class="m m--yellow"></span><span class="m m--orange"></span></span>
				<span class="cead-acad-carne-org">CEAD</span>
			</div>
			<div class="cead-acad-carne-body">
				<div class="cead-acad-carne-photo">
					<?php if ( $avatar ) : ?>
						<img src="<?php echo esc_url( $avatar ); ?>" alt="">
					<?php else : ?>
						<span class="cead-acad-avatar--initials"><?php echo esc_html( Cead_Acad_Account::initials( $user->display_name ) ); ?></span>
					<?php endif; ?>
				</div>
				<div class="cead-acad-carne-data">
					<span class="cead-acad-carne-role"><?php echo esc_html( $rdisp ); ?></span>
					<h3 class="cead-acad-carne-name"><?php echo esc_html( $user->display_name ); ?></h3>
					<?php if ( $course ) : ?><p class="cead-acad-carne-line"><strong><?php esc_html_e( 'Curso:', 'cead-acad' ); ?></strong> <?php echo esc_html( $course ); ?></p><?php endif; ?>
					<?php if ( $doc ) : ?><p class="cead-acad-carne-line"><strong><?php esc_html_e( 'Doc:', 'cead-acad' ); ?></strong> <?php echo esc_html( $doc ); ?></p><?php endif; ?>
					<p class="cead-acad-carne-line"><strong><?php esc_html_e( 'ID:', 'cead-acad' ); ?></strong> <?php echo esc_html( str_pad( (string) $user->ID, 6, '0', STR_PAD_LEFT ) ); ?></p>
				</div>
				<div class="cead-acad-carne-qr">
					<?php
					// SVG armado por Cead_Acad_QR (no entra nada del usuario: solo
					// dígitos y el token firmado), por eso va tal cual.
					echo $qr_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					<span><?php esc_html_e( 'Escaneá para verificar', 'cead-acad' ); ?></span>
				</div>
			</div>
		</div>

		<div class="cead-acad-profile-actions" style="margin-top:1.25rem">
			<button type="button" class="cead-acad-btn" onclick="window.print()"><?php esc_html_e( 'Imprimir / Guardar PDF', 'cead-acad' ); ?></button>
			<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( $verify_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Abrir verificación', 'cead-acad' ); ?></a>
			<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( cead_acad_url( 'panel/perfil' ) ); ?>"><?php esc_html_e( 'Cambiar foto', 'cead-acad' ); ?></a>
		</div>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
