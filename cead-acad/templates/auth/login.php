<?php
/**
 * Página: /login
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Si ya está logueado, mandarlo al panel.
if ( is_user_logged_in() ) {
	wp_safe_redirect( cead_acad_url( 'panel' ) );
	exit;
}

$err  = isset( $_GET['err'] ) ? sanitize_key( $_GET['err'] ) : '';
$ok   = isset( $_GET['ok'] )  ? sanitize_key( $_GET['ok'] )  : '';
$next = isset( $_GET['next'] ) ? esc_url_raw( wp_unslash( $_GET['next'] ) ) : '';

$title = __( 'Ingresar al panel', 'cead-acad' );
$prefill_user = function_exists( 'cead_acad_flash' ) ? (string) cead_acad_flash( 'login_user' ) : '';
$body  = function () use ( $err, $ok, $next, $prefill_user ) {
	?>
	<h1 class="cead-acad-auth-h"><?php esc_html_e( 'Ingresar', 'cead-acad' ); ?></h1>
	<p class="cead-acad-auth-sub"><?php esc_html_e( 'Accedé con tu usuario y contraseña.', 'cead-acad' ); ?></p>

	<?php if ( 'reset' === $ok ) : ?>
		<div class="cead-acad-msg cead-acad-msg--ok"><?php esc_html_e( 'Contraseña actualizada. Ya podés ingresar.', 'cead-acad' ); ?></div>
	<?php endif; ?>

	<?php if ( $err ) :
		$labels = [
			'bad_credentials' => __( 'Usuario o contraseña incorrectos.', 'cead-acad' ),
			'missing_fields'  => __( 'Completá usuario y contraseña.', 'cead-acad' ),
			'rate_limited'    => __( 'Demasiados intentos. Esperá un minuto y volvé a probar.', 'cead-acad' ),
			'invalid_nonce'   => __( 'Sesión expirada. Recargá la página.', 'cead-acad' ),
			'suspended'       => __( 'Tu cuenta está suspendida. Contactá a la secretaría del CEAD.', 'cead-acad' ),
		];
		?>
		<div class="cead-acad-msg cead-acad-msg--err"><?php echo esc_html( $labels[ $err ] ?? __( 'Error.', 'cead-acad' ) ); ?></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cead-acad-form">
		<?php wp_nonce_field( 'cead_acad_login', '_cead_nonce' ); ?>
		<input type="hidden" name="action" value="cead_acad_login">
		<?php if ( $next ) : ?>
			<input type="hidden" name="next" value="<?php echo esc_attr( $next ); ?>">
		<?php endif; ?>

		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Usuario o email', 'cead-acad' ); ?></span>
			<input type="text" name="user_login" value="<?php echo esc_attr( $prefill_user ); ?>" required autocomplete="username" <?php echo $prefill_user ? '' : 'autofocus'; ?>>
		</label>

		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Contraseña', 'cead-acad' ); ?></span>
			<span class="cead-acad-pass-wrap">
				<input type="password" name="user_pass" id="cead-acad-pass" required autocomplete="current-password" <?php echo $prefill_user ? 'autofocus' : ''; ?>>
				<button type="button" class="cead-acad-pass-toggle" aria-label="<?php esc_attr_e( 'Mostrar u ocultar contraseña', 'cead-acad' ); ?>" onclick="var i=document.getElementById('cead-acad-pass');var s=i.type==='password';i.type=s?'text':'password';this.textContent=s?'🙈':'👁';"><?php echo '👁'; ?></button>
			</span>
		</label>

		<label class="cead-acad-checkbox">
			<input type="checkbox" name="remember" value="1" checked>
			<span><?php esc_html_e( 'Mantener sesión iniciada', 'cead-acad' ); ?></span>
		</label>

		<button type="submit" class="cead-acad-btn cead-acad-btn--primary"><?php esc_html_e( 'Ingresar', 'cead-acad' ); ?></button>
	</form>

	<p class="cead-acad-auth-links">
		<a href="<?php echo esc_url( cead_acad_url( 'recuperar' ) ); ?>"><?php esc_html_e( '¿Olvidaste tu contraseña?', 'cead-acad' ); ?></a>
		<span aria-hidden="true">·</span>
		<span><?php esc_html_e( '¿Sos nuevo? Necesitás un link de invitación de la dirección.', 'cead-acad' ); ?></span>
	</p>
	<?php
};

include CEAD_ACAD_DIR . 'templates/auth/partials/auth-shell.php';
