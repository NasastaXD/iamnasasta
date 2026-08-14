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
if ( $token === '' ) {
	// Link corto /i/<token>.
	$qv    = get_query_var( 'cead_acad_t' );
	$token = $qv ? sanitize_text_field( wp_unslash( $qv ) ) : '';
}
$err   = isset( $_GET['err'] ) ? sanitize_key( $_GET['err'] ) : '';

$invitation = $token ? Cead_Acad_Invitations::find_by_token( $token ) : null;
$status     = Cead_Acad_Invitations::status( $invitation );
$roles      = Cead_Acad_Capabilities::roles();
$role_display = $invitation && isset( $roles[ $invitation['role'] ] ) ? $roles[ $invitation['role'] ]['display'] : '';

// Textos editables (wp-admin → WhatsApp) que explican el panel y el bot.
$panel_intro  = (string) get_option( 'cead_acad_panel_intro', __( 'El panel del CEAD es el espacio web donde alumnado y personal gestionan las cosas del colegio: comunicados, horarios, eventos, materiales y más, todo en un solo lugar.', 'cead-acad' ) );
$ceadi_intro  = (string) get_option( 'cead_acad_ceadi_intro', __( 'CEADI es el asistente del colegio en WhatsApp. Te acerca comunicados, horarios y novedades, y te deja hacer consultas rápidas cuando las necesites.', 'cead-acad' ) );
$ceadi_wa     = cead_acad_wa_link();

// Para links de Alumno/a o Delegado/a SIN curso fijo, el usuario elige su curso.
$needs_course = ( 'valid' === $status )
	&& in_array( $invitation['role'] ?? '', [ 'cead_acad_student', 'cead_acad_delegate' ], true )
	&& empty( $invitation['course_id'] );
$course_list  = $needs_course ? cead_acad_courses_for_select() : [];

// Repoblación tras error de validación (one-shot). Leer una sola vez por clave.
$old_user_login = cead_acad_flash( 'reg_user_login' );
$old_email      = cead_acad_flash( 'reg_email' );
$old_full_name  = cead_acad_flash( 'reg_full_name' );
$old_phone      = cead_acad_flash( 'reg_phone' );

$title = __( 'Crear cuenta', 'cead-acad' );
$body  = function () use ( $token, $invitation, $status, $err, $role_display, $panel_intro, $ceadi_intro, $ceadi_number, $needs_course, $course_list, $old_user_login, $old_email, $old_full_name, $old_phone ) {
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

	<div class="cead-acad-auth-intro">
		<?php if ( trim( $panel_intro ) !== '' ) : ?>
			<h2 class="cead-acad-auth-h2"><?php esc_html_e( '¿Qué es el panel del CEAD?', 'cead-acad' ); ?></h2>
			<p><?php echo esc_html( $panel_intro ); ?></p>
		<?php endif; ?>
		<?php if ( trim( $ceadi_intro ) !== '' ) : ?>
			<h2 class="cead-acad-auth-h2"><?php esc_html_e( 'CEADI · el bot del colegio', 'cead-acad' ); ?></h2>
			<p><?php echo esc_html( $ceadi_intro ); ?></p>
		<?php endif; ?>
		<?php if ( $ceadi_wa !== '' ) : ?>
			<p>
				<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( $ceadi_wa ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Agregar a CEADI', 'cead-acad' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</div>

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
			// Se explica el porqué: sin eso, alguien que comparte el celular en
			// casa cree que el sistema está roto y vuelve a intentar con el mismo
			// número. Cada cuenta necesita el suyo porque es con lo que CEADI
			// distingue a una persona de otra.
			'phone_taken'       => __( 'Ese número ya está registrado en otra cuenta. Cada persona necesita su propio número, porque es con eso que CEADI la reconoce por WhatsApp. Si el número es tuyo y no sabés por qué figura ocupado, avisá a secretaría.', 'cead-acad' ),
			'invitation_used'   => __( 'Este link de invitación ya se utilizó.', 'cead-acad' ),
			'invitation_expired'=> __( 'Este link de invitación expiró.', 'cead-acad' ),
			'invitation_revoked'=> __( 'Esta invitación fue revocada.', 'cead-acad' ),
			'insert_failed'     => __( 'No se pudo crear la cuenta. Intentá de nuevo o pedí ayuda a la dirección.', 'cead-acad' ),
			'missing_token'     => __( 'Falta el código de invitación. Volvé a abrir el link que te enviaron.', 'cead-acad' ),
			'missing_course'    => __( 'Elegí tu curso para continuar.', 'cead-acad' ),
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
			<input type="text" name="full_name" required autocomplete="name" value="<?php echo esc_attr( $old_full_name !== null ? $old_full_name : ( isset( $_GET['full_name'] ) ? wp_unslash( $_GET['full_name'] ) : '' ) ); ?>">
		</label>

		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Usuario', 'cead-acad' ); ?></span>
			<input type="text" name="user_login" required pattern="[A-Za-z0-9._\-]+" autocomplete="username" placeholder="<?php esc_attr_e( 'sin espacios', 'cead-acad' ); ?>" value="<?php echo esc_attr( $old_user_login !== null ? $old_user_login : '' ); ?>">
		</label>

		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Email', 'cead-acad' ); ?></span>
			<input type="email" name="email" required autocomplete="email" value="<?php echo esc_attr( $old_email !== null ? $old_email : ( $invitation['email'] ?? '' ) ); ?>">
		</label>

		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Teléfono (WhatsApp)', 'cead-acad' ); ?></span>
			<input type="tel" name="phone" required autocomplete="tel" placeholder="<?php esc_attr_e( 'Ej: 0981123456', 'cead-acad' ); ?>" value="<?php echo esc_attr( $old_phone !== null ? $old_phone : ( isset( $_GET['phone'] ) ? wp_unslash( $_GET['phone'] ) : '' ) ); ?>">
			<small class="cead-acad-hint"><?php esc_html_e( 'Tu número de WhatsApp. Ejemplo: 0981123456', 'cead-acad' ); ?></small>
		</label>

		<?php if ( $needs_course ) : ?>
		<label class="cead-acad-field">
			<span class="cead-acad-label"><?php esc_html_e( 'Curso', 'cead-acad' ); ?></span>
			<select name="course_id" required>
				<option value=""><?php esc_html_e( 'Elegí tu curso…', 'cead-acad' ); ?></option>
				<?php foreach ( $course_list as $cid => $ctitle ) : ?>
					<option value="<?php echo (int) $cid; ?>"><?php echo esc_html( $ctitle ); ?></option>
				<?php endforeach; ?>
			</select>
			<small class="cead-acad-hint"><?php esc_html_e( 'Elegí el curso al que pertenecés.', 'cead-acad' ); ?></small>
		</label>
		<?php endif; ?>

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
