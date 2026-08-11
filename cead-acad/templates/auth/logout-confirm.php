<?php
/**
 * Página: /salir sin nonce válido.
 *
 * No se cierra la sesión de una porque un GET pelado lo dispara cualquier cosa
 * que precargue enlaces —prefetch del navegador, escáneres de antivirus, la
 * previsualización de WhatsApp al compartir una URL del panel—. Acá se pide
 * confirmar, que es lo que hace WordPress en el mismo caso.
 *
 * También cae acá un enlace viejo cuyo nonce venció, que es el caso más común
 * y no tiene nada de sospechoso: por eso el tono es normal, sin alarma.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Si ya no hay sesión, no hay nada que cerrar.
if ( ! is_user_logged_in() ) {
	wp_safe_redirect( cead_acad_url( 'login' ) );
	exit;
}

$title = __( 'Cerrar sesión', 'cead-acad' );
$body  = function () {
	?>
	<h1 class="cead-acad-auth-h"><?php esc_html_e( 'Cerrar sesión', 'cead-acad' ); ?></h1>
	<p class="cead-acad-auth-sub"><?php esc_html_e( '¿Desea salir de su cuenta?', 'cead-acad' ); ?></p>

	<p>
		<a class="cead-acad-btn cead-acad-btn--primary" href="<?php echo esc_url( cead_acad_logout_url() ); ?>">
			<?php esc_html_e( 'Sí, cerrar sesión', 'cead-acad' ); ?>
		</a>
	</p>

	<p class="cead-acad-auth-links">
		<a href="<?php echo esc_url( cead_acad_url( 'panel' ) ); ?>">← <?php esc_html_e( 'Volver al panel', 'cead-acad' ); ?></a>
	</p>
	<?php
};

include CEAD_ACAD_DIR . 'templates/auth/partials/auth-shell.php';
