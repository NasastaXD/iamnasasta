<?php
/**
 * Página: /registro?t=<token>
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( is_user_logged_in() ) {
	wp_safe_redirect( cead_acad_url( 'panel' ) );
	exit;
}

$token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
$err   = isset( $_GET['err'] ) ? sanitize_key( $_GET['err'] ) : '';

$invitation = $token ? Cead_Acad_Invitations::find_by_token( $token ) : null;
$status     = Cead_Acad_Invitations::status( $invitation );
$roles      = Cead_Acad_Capabilities::roles();
$role_display = $invitation && isset( $roles[ $invitation['role'] ] ) ? $roles[ $invitation['role'] ]['display'] : '';

$title = __( 'Crear cuenta', 'cead-acad' );
$body  = function () use ( $token, $invitation, $status, $err, $role_display ) {
	?>
	<h1 class="cead-acad-auth-h"><?php esc_html_e( 'Crear cuenta', 'cead-acad' ); ?></h1>

	<?php if ( 'valid' !== $status ) :
		$labels = [
			'invalid' => __( 'El link de invitación no es válido.', 'cead-acad' ),
			'used'    => __( 'Esta invitación ya fue usada.', 'cead-acad' ),
			'expired' => __( 'Esta invitación está expirada.', 'cead-acad' ),
			'revoked' => __( 'Esta invitación fue revocada.', 'cead-acad' ),
		];
		?>
		<div class="cead-acad-msg cead-acad-msg--err"><?php echo esc_html( $labels[ $status ] ?? __( 'Invitación inválida.', 'cead-acad' ) ); ?></div>
		<p class="cead-acad-auth-sub"><?php esc_html_e( 'Pedile a la dirección o secretaría un nuevo link de invitación.', 'cead-acad' ); ?></p>
		<p class="cead-acad-auth-links">
			<a href="<?php echo esc_url( cead_acad_url( 'login' ) ); ?>"><?php esc_html_e( 'Ir a iniciar sesión', 'cead-acad' ); ?> →</a>
		</p>
		<?php return;
	endif; ?>

	<p class="cead-acad-auth-sub">
		<?php
		printf(
			/* translators: %s: role display name. */
			esc_html__( 'Te invitaron como %s. Completá tus datos para activar la cuenta.', 'cead-acad' ),
			'<strong>' . esc_html( $role_display ) . '</strong>'
		);
		?>
	</p>

	<?php if ( $err ) :
		$labels = [
			'missing_fields'    => __( 'Completá todos los campos.', 'cead-acad' ),
			'bad_email'         => __( 'Email inválido.', 'cead-acad' ),
			'weak_password'     => __( 'La contraseña debe tener al menos 8 caracteres.', 'cead-acad' ),
			'password_mismatch' => __( 'Las contraseñas no coinciden.', 'cead-acad' ),
			'username_taken'    => __( 'Ese nombre de usuario ya está en uso.', 'cead-acad' ),
			'email_taken'       => __( 'Ya hay una cuenta con ese email.', 'cead-acad' ),
			'rate_limited'      => __( 'Demasiados intentos. Esperá un minuto.', 'cead-acad' ),
			'missing_phone'     => __( 'El número de teléfono es obligatorio.', 'cead-acad' ),
			'invitation_used'   => __( 'Este link de invitación ya se utilizó.', 'cead-acad' ),
			'invitation_expired'=> __( 'Este link de invitación expiró.', 'cead-acad' ),
			'invitation_revoked'=> __( 'Esta invitación fue revocada.', 'cead-acad' ),
		];
		?>
		<div class="cead-acad-msg cead-acad-msg--err"><?php echo esc_html( $labels[ $err ] ?? __( 'Error.', 'cead-acad' ) ); ?></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cead-acad-form">
		<?php wp_nonce_field( 'cead_acad_register', '_cead_nonce' ); ?>
		<input type="hidden" name="action" value="cead_acad_register">
		<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">

		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Nombre y apellido', 'cead-acad' ); ?></span>
			<input type="text" name="full_name" required autocomplete="name" value="<?php echo isset( $_GET['full_name'] ) ? esc_attr( wp_unslash( $_GET['full_name'] ) ) : ''; ?>">
		</label>

		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Usuario', 'cead-acad' ); ?></span>
			<input type="text" name="user_login" required pattern="[A-Za-z0-9._\-]+" autocomplete="username" placeholder="<?php esc_attr_e( 'sin espacios', 'cead-acad' ); ?>">
		</label>

		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Email', 'cead-acad' ); ?></span>
			<input type="email" name="email" required autocomplete="email" value="<?php echo esc_attr( $invitation['email'] ?? '' ); ?>">
		</label>

		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Teléfono (WhatsApp)', 'cead-acad' ); ?></span>
			<input type="tel" name="phone" required autocomplete="tel" placeholder="<?php esc_attr_e( 'Ej: 0981123456', 'cead-acad' ); ?>" value="<?php echo isset( $_GET['phone'] ) ? esc_attr( wp_unslash( $_GET['phone'] ) ) : ''; ?>">
			<small class="cead-acad-hint"><?php esc_html_e( 'Tu número de WhatsApp. Ejemplo: 0981123456', 'cead-acad' ); ?></small>
		</label>

		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Contraseña', 'cead-acad' ); ?></span>
			<input type="password" name="user_pass" required minlength="8" autocomplete="new-password">
			<small class="cead-acad-hint"><?php esc_html_e( 'Mínimo 8 caracteres.', 'cead-acad' ); ?></small>
		</label>

		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Repetir contraseña', 'cead-acad' ); ?></span>
			<input type="password" name="user_pass2" required minlength="8" autocomplete="new-password">
		</label>

		<button type="submit" class="cead-acad-btn cead-acad-btn--primary"><?php esc_html_e( 'Crear cuenta', 'cead-acad' ); ?></button>
	</form>

	<p class="cead-acad-auth-links">
		<a href="<?php echo esc_url( cead_acad_url( 'login' ) ); ?>"><?php esc_html_e( '¿Ya tenés cuenta? Iniciá sesión', 'cead-acad' ); ?></a>
	</p>
	<?php
};

include CEAD_ACAD_DIR . 'templates/auth/partials/auth-shell.php';
