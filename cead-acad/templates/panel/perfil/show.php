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
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
