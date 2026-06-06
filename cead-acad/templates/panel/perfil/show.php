<?php
/**
 * /panel/perfil: datos de la cuenta + foto de perfil.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user  = wp_get_current_user();
$role  = cead_acad_user_role( $user->ID );
$roles = Cead_Acad_Capabilities::roles();
$rdisp = $roles[ $role ]['display'] ?? $role;

$avatar = Cead_Acad_Account::avatar_url( $user->ID, 'medium' );
$phone  = (string) get_user_meta( $user->ID, Cead_Acad_Account::PHONE_META, true );
$doc    = (string) get_user_meta( $user->ID, '_cead_acad_document_id', true );

$page_title = __( 'Mi perfil', 'cead-acad' );

$body = function () use ( $user, $rdisp, $avatar, $phone, $doc ) {
	$done = isset( $_GET['done'] );
	$err  = isset( $_GET['err'] ) ? sanitize_key( (string) $_GET['err'] ) : '';
	$errs = [
		'tipo'   => __( 'El archivo debe ser una imagen.', 'cead-acad' ),
		'subida' => __( 'No se pudo subir la imagen. Probá con otra.', 'cead-acad' ),
	];
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Cuenta', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Mi perfil', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Actualizá tu foto y tus datos de contacto.', 'cead-acad' ); ?></p>

		<?php if ( $done ) : ?>
			<div class="cead-acad-msg cead-acad-msg--ok"><?php esc_html_e( 'Perfil actualizado.', 'cead-acad' ); ?></div>
		<?php elseif ( $err && isset( $errs[ $err ] ) ) : ?>
			<div class="cead-acad-msg cead-acad-msg--err"><?php echo esc_html( $errs[ $err ] ); ?></div>
		<?php endif; ?>

		<form class="cead-acad-card cead-acad-profile" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1.5rem">
			<input type="hidden" name="action" value="cead_acad_save_profile">
			<?php wp_nonce_field( 'cead_acad_save_profile' ); ?>

			<div class="cead-acad-profile-avatar">
				<?php if ( $avatar ) : ?>
					<img src="<?php echo esc_url( $avatar ); ?>" alt="" class="cead-acad-avatar cead-acad-avatar--lg">
				<?php else : ?>
					<span class="cead-acad-avatar cead-acad-avatar--lg cead-acad-avatar--initials"><?php echo esc_html( Cead_Acad_Account::initials( $user->display_name ) ); ?></span>
				<?php endif; ?>
				<label class="cead-acad-field">
					<span><?php esc_html_e( 'Foto de perfil', 'cead-acad' ); ?></span>
					<input type="file" name="avatar" accept="image/*">
				</label>
			</div>

			<label class="cead-acad-field">
				<span><?php esc_html_e( 'Nombre a mostrar', 'cead-acad' ); ?></span>
				<input type="text" name="display_name" value="<?php echo esc_attr( $user->display_name ); ?>" required>
			</label>

			<label class="cead-acad-field">
				<span><?php esc_html_e( 'Teléfono', 'cead-acad' ); ?></span>
				<input type="text" name="phone" value="<?php echo esc_attr( $phone ); ?>" inputmode="tel">
			</label>

			<div class="cead-acad-grid cead-acad-grid--2">
				<label class="cead-acad-field">
					<span><?php esc_html_e( 'Email', 'cead-acad' ); ?></span>
					<input type="text" value="<?php echo esc_attr( $user->user_email ); ?>" disabled>
				</label>
				<label class="cead-acad-field">
					<span><?php esc_html_e( 'Rol', 'cead-acad' ); ?></span>
					<input type="text" value="<?php echo esc_attr( $rdisp ); ?>" disabled>
				</label>
			</div>
			<?php if ( $doc ) : ?>
				<label class="cead-acad-field">
					<span><?php esc_html_e( 'Documento', 'cead-acad' ); ?></span>
					<input type="text" value="<?php echo esc_attr( $doc ); ?>" disabled>
				</label>
			<?php endif; ?>

			<div class="cead-acad-profile-actions">
				<button type="submit" class="cead-acad-btn"><?php esc_html_e( 'Guardar cambios', 'cead-acad' ); ?></button>
				<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( cead_acad_url( 'panel/carne' ) ); ?>"><?php esc_html_e( 'Ver mi carné', 'cead-acad' ); ?></a>
			</div>
		</form>

		<?php
		// Verificación del teléfono por WhatsApp (CEADI).
		$verified = Cead_Acad_Account::is_phone_verified( $user->ID );
		$vok      = isset( $_GET['vok'] );
		$vsent    = isset( $_GET['vsent'] );
		$verr     = isset( $_GET['verr'] ) ? sanitize_key( (string) $_GET['verr'] ) : '';
		$verrs    = [
			'nophone'  => __( 'Primero cargá y guardá tu teléfono arriba.', 'cead-acad' ),
			'throttle' => __( 'Esperá un minuto antes de pedir otro código.', 'cead-acad' ),
			'send'     => __( 'No se pudo enviar el código (¿CEADI está conectado?).', 'cead-acad' ),
			'expired'  => __( 'El código venció. Pedí uno nuevo.', 'cead-acad' ),
			'bad'      => __( 'Código incorrecto. Revisá e intentá de nuevo.', 'cead-acad' ),
		];
		?>
		<div class="cead-acad-card" style="margin-top:1.5rem">
			<span class="cead-acad-eyebrow"><?php esc_html_e( 'WhatsApp', 'cead-acad' ); ?></span>
			<h3>
				<?php esc_html_e( 'Verificación de tu número', 'cead-acad' ); ?>
				<?php if ( $verified ) : ?><span class="cead-acad-pill">✅ <?php esc_html_e( 'Verificado', 'cead-acad' ); ?></span><?php endif; ?>
			</h3>
			<p><?php esc_html_e( 'Verificá tu teléfono para confirmar que es tuyo y que CEADI pueda atenderte por WhatsApp.', 'cead-acad' ); ?></p>

			<?php if ( $vok ) : ?>
				<div class="cead-acad-msg cead-acad-msg--ok"><?php esc_html_e( '¡Número verificado! 🎉', 'cead-acad' ); ?></div>
			<?php elseif ( $vsent ) : ?>
				<div class="cead-acad-msg cead-acad-msg--ok"><?php esc_html_e( 'Te enviamos un código por WhatsApp. Ingresalo abajo.', 'cead-acad' ); ?></div>
			<?php elseif ( $verr && isset( $verrs[ $verr ] ) ) : ?>
				<div class="cead-acad-msg cead-acad-msg--err"><?php echo esc_html( $verrs[ $verr ] ); ?></div>
			<?php endif; ?>

			<?php if ( ! $verified ) : ?>
				<div class="cead-acad-profile-actions" style="align-items:flex-end;gap:1rem">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="cead_acad_send_wa_code">
						<?php wp_nonce_field( 'cead_acad_send_wa_code' ); ?>
						<button type="submit" class="cead-acad-btn cead-acad-btn--ghost"><?php esc_html_e( 'Enviarme un código por WhatsApp', 'cead-acad' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:.5rem;align-items:flex-end">
						<input type="hidden" name="action" value="cead_acad_verify_wa_code">
						<?php wp_nonce_field( 'cead_acad_verify_wa_code' ); ?>
						<label class="cead-acad-field" style="margin:0">
							<span><?php esc_html_e( 'Código', 'cead-acad' ); ?></span>
							<input type="text" name="code" inputmode="numeric" maxlength="6" pattern="[0-9]*" placeholder="123456" required>
						</label>
						<button type="submit" class="cead-acad-btn"><?php esc_html_e( 'Verificar', 'cead-acad' ); ?></button>
					</form>
				</div>
			<?php endif; ?>
		</div>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
