<?php
/**
 * Página: /recuperar
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$err = isset( $_GET['err'] ) ? sanitize_key( $_GET['err'] ) : '';
$ok  = isset( $_GET['ok'] );

$title = __( 'Recuperar contraseña', 'cead-acad' );
$body  = function () use ( $err, $ok ) {
	?>
	<h1 class="cead-acad-auth-h"><?php esc_html_e( 'Recuperar contraseña', 'cead-acad' ); ?></h1>
	<p class="cead-acad-auth-sub"><?php esc_html_e( 'Te enviamos un link a tu email para restablecerla.', 'cead-acad' ); ?></p>

	<?php if ( $ok ) : ?>
		<div class="cead-acad-msg cead-acad-msg--ok"><?php esc_html_e( 'Si la cuenta existe, te enviamos un email con instrucciones. Revisá tu bandeja de entrada.', 'cead-acad' ); ?></div>
	<?php endif; ?>

	<?php if ( $err ) :
		$labels = [
			'missing_fields'    => __( 'Ingresá tu usuario o email.', 'cead-acad' ),
			'rate_limited'      => __( 'Demasiados intentos. Esperá un minuto.', 'cead-acad' ),
			'invalid_or_expired'=> __( 'El link ya no es válido. Pedí uno nuevo.', 'cead-acad' ),
			'invalid_nonce'     => __( 'Sesión expirada. Recargá la página.', 'cead-acad' ),
		];
		?>
		<div class="cead-acad-msg cead-acad-msg--err"><?php echo esc_html( $labels[ $err ] ?? __( 'Error.', 'cead-acad' ) ); ?></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cead-acad-form">
		<?php wp_nonce_field( 'cead_acad_recover_request', '_cead_nonce' ); ?>
		<input type="hidden" name="action" value="cead_acad_recover_request">

		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Usuario o email', 'cead-acad' ); ?></span>
			<input type="text" name="user_login" required autofocus>
		</label>

		<button type="submit" class="cead-acad-btn cead-acad-btn--primary"><?php esc_html_e( 'Enviar link', 'cead-acad' ); ?></button>
	</form>

	<p class="cead-acad-auth-links">
		<a href="<?php echo esc_url( cead_acad_url( 'login' ) ); ?>">← <?php esc_html_e( 'Volver a iniciar sesión', 'cead-acad' ); ?></a>
	</p>
	<?php
};

include CEAD_ACAD_DIR . 'templates/auth/partials/auth-shell.php';
