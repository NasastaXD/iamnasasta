<?php
/**
 * /panel/contacto: enviar un mensaje directo a Dirección, Consejo o Administración.
 * Se guarda en el buzón, donde el rol destinatario lo lee y responde.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$page_title = __( 'Escribir al CEAD', 'cead-acad' );

$body = function () {
	$done = isset( $_GET['done'] );
	$err  = isset( $_GET['err'] ) ? sanitize_key( (string) $_GET['err'] ) : '';
	$recipients = [
		'direccion'      => __( 'Dirección', 'cead-acad' ),
		'consejo'        => __( 'Consejo Estudiantil', 'cead-acad' ),
		'administracion' => __( 'Administración / Secretaría', 'cead-acad' ),
	];
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Mensajes', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Escribir al CEAD', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Mandá un mensaje directo a Dirección, al Consejo o a Administración. Lo reciben en su buzón y te responden.', 'cead-acad' ); ?></p>

		<?php if ( $done ) : ?>
			<div class="cead-acad-msg cead-acad-msg--ok"><?php esc_html_e( 'Mensaje enviado. Te van a responder pronto.', 'cead-acad' ); ?></div>
		<?php elseif ( 'vacio' === $err ) : ?>
			<div class="cead-acad-msg cead-acad-msg--err"><?php esc_html_e( 'Escribí un mensaje antes de enviar.', 'cead-acad' ); ?></div>
		<?php elseif ( 'vulgar' === $err ) : ?>
			<div class="cead-acad-msg cead-acad-msg--err"><?php esc_html_e( 'Tu mensaje contiene lenguaje no permitido. Reformulalo, por favor.', 'cead-acad' ); ?></div>
		<?php endif; ?>

		<form class="cead-acad-card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1.5rem;gap:1.1rem">
			<input type="hidden" name="action" value="cead_acad_send_message">
			<?php wp_nonce_field( 'cead_acad_send_message' ); ?>

			<label class="cead-acad-field">
				<span><?php esc_html_e( 'Para', 'cead-acad' ); ?></span>
				<select name="recipient">
					<?php foreach ( $recipients as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="cead-acad-field">
				<span><?php esc_html_e( 'Tu mensaje', 'cead-acad' ); ?></span>
				<textarea name="message" rows="6" required placeholder="<?php esc_attr_e( 'Escribí tu consulta o mensaje…', 'cead-acad' ); ?>"></textarea>
			</label>

			<div>
				<button type="submit" class="cead-acad-btn"><?php esc_html_e( 'Enviar mensaje', 'cead-acad' ); ?></button>
			</div>
		</form>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
