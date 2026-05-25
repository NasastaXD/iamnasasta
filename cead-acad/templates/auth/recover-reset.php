<?php
/**
 * Página: /recuperar/restablecer?k=&u=
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$k   = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';
$u   = isset( $_GET['u'] ) ? sanitize_user( wp_unslash( $_GET['u'] ), true ) : '';
$err = isset( $_GET['err'] ) ? sanitize_key( $_GET['err'] ) : '';

$valid = ( $k && $u && ! is_wp_error( check_password_reset_key( $k, $u ) ) );

$title = __( 'Nueva contraseña', 'cead-acad' );
$body  = function () use ( $k, $u, $err, $valid ) {
	?>
	<h1 class="cead-acad-auth-h"><?php esc_html_e( 'Definir nueva contraseña', 'cead-acad' ); ?></h1>

	<?php if ( ! $valid ) : ?>
		<div class="cead-acad-msg cead-acad-msg--err"><?php esc_html_e( 'El link ya no es válido. Pedí uno nuevo.', 'cead-acad' ); ?></div>
		<p class="cead-acad-auth-links">
			<a href="<?php echo esc_url( cead_acad_url( 'recuperar' ) ); ?>"><?php esc_html_e( 'Solicitar otro link', 'cead-acad' ); ?></a>
		</p>
		<?php return;
	endif; ?>

	<?php if ( $err ) :
		$labels = [
			'missing_fields'    => __( 'Completá ambos campos.', 'cead-acad' ),
			'weak_password'     => __( 'La contraseña debe tener al menos 8 caracteres.', 'cead-acad' ),
			'password_mismatch' => __( 'Las contraseñas no coinciden.', 'cead-acad' ),
			'rate_limited'      => __( 'Demasiados intentos. Esperá un minuto.', 'cead-acad' ),
		];
		?>
		<div class="cead-acad-msg cead-acad-msg--err"><?php echo esc_html( $labels[ $err ] ?? __( 'Error.', 'cead-acad' ) ); ?></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cead-acad-form">
		<?php wp_nonce_field( 'cead_acad_recover_confirm', '_cead_nonce' ); ?>
		<input type="hidden" name="action" value="cead_acad_recover_confirm">
		<input type="hidden" name="k" value="<?php echo esc_attr( $k ); ?>">
		<input type="hidden" name="u" value="<?php echo esc_attr( $u ); ?>">

		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Nueva contraseña', 'cead-acad' ); ?></span>
			<input type="password" name="user_pass" required minlength="8" autocomplete="new-password" autofocus>
		</label>

		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Repetir contraseña', 'cead-acad' ); ?></span>
			<input type="password" name="user_pass2" required minlength="8" autocomplete="new-password">
		</label>

		<button type="submit" class="cead-acad-btn cead-acad-btn--primary"><?php esc_html_e( 'Actualizar contraseña', 'cead-acad' ); ?></button>
	</form>
	<?php
};

include CEAD_ACAD_DIR . 'templates/auth/partials/auth-shell.php';
