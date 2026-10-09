<?php
/**
 * /panel/contacto: enviar un mensaje directo a Dirección, Consejo o Administración.
 * Se guarda en el buzón, donde el rol destinatario lo lee y responde.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$page_title = __( 'Escribir al CEAD', 'cead-acad' );

$old_message   = (string) cead_acad_flash( 'contacto_message' );
$old_recipient = cead_acad_flash( 'contacto_recipient' );

// Lo que ya mandó y si le contestaron. Antes la respuesta solo llegaba por
// WhatsApp, y quien no tenía número cargado nunca se enteraba.
$mios = ( new Cead_Acad_Buzon() )->mios( get_current_user_id() );

$body = function () use ( $old_message, $old_recipient, $mios ) {
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
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $old_recipient, $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="cead-acad-field">
				<span><?php esc_html_e( 'Tu mensaje', 'cead-acad' ); ?></span>
				<textarea name="message" rows="6" required placeholder="<?php esc_attr_e( 'Escribí tu consulta o mensaje…', 'cead-acad' ); ?>"><?php echo esc_textarea( $old_message ); ?></textarea>
			</label>

			<div>
				<button type="submit" class="cead-acad-btn"><?php esc_html_e( 'Enviar mensaje', 'cead-acad' ); ?></button>
			</div>
		</form>

		<?php if ( $mios['mensajes'] ) :
			$estados = [
				'new'       => __( 'Enviado', 'cead-acad' ),
				'in_review' => __( 'Respondido', 'cead-acad' ),
				'accepted'  => __( 'Aceptado', 'cead-acad' ),
				'denied'    => __( 'Rechazado', 'cead-acad' ),
			];
			?>
			<h3 class="cead-acad-section-h" style="margin-top:2rem"><?php esc_html_e( 'Mis mensajes', 'cead-acad' ); ?></h3>
			<?php foreach ( $mios['mensajes'] as $m ) : ?>
				<div class="cead-acad-card" style="margin-bottom:.8rem">
					<p class="cead-acad-buzon-meta">
						<strong><?php echo esc_html( $recipients[ $m['para'] ] ?? $m['para'] ); ?></strong>
						· <?php echo esc_html( $estados[ $m['estado'] ] ?? $m['estado'] ); ?>
						· <?php echo esc_html( $m['creado'] ); ?>
					</p>
					<blockquote class="cead-acad-buzon-quote"><?php echo nl2br( esc_html( $m['texto'] ) ); ?></blockquote>
					<?php if ( '' !== trim( $m['respuesta'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Respuesta:', 'cead-acad' ); ?></strong><br><?php echo nl2br( esc_html( $m['respuesta'] ) ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
