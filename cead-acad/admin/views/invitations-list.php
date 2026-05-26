<?php
/**
 * Pantalla: CEAD Académico → Invitaciones.
 * Lista + formulario inline para crear nuevas invitaciones.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$roles = Cead_Acad_Capabilities::roles();
$rows  = Cead_Acad_Invitations::list_recent( 50 );
?>
<div class="wrap cead-acad-admin-wrap">
	<h1><?php esc_html_e( 'Invitaciones', 'cead-acad' ); ?></h1>
	<p><?php esc_html_e( 'Generá un link de invitación. La persona abre el link, completa el registro y se crea su cuenta con el rol asignado. Si ponés un email, se lo enviamos automáticamente.', 'cead-acad' ); ?></p>

	<h2 class="title"><?php esc_html_e( 'Nueva invitación', 'cead-acad' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'cead_acad_admin_invite_create' ); ?>
		<input type="hidden" name="action" value="cead_acad_admin_invite_create" />
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="role"><?php esc_html_e( 'Rol', 'cead-acad' ); ?></label></th>
				<td>
					<select name="role" id="role">
						<?php foreach ( $roles as $slug => $cfg ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $cfg['display'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="email"><?php esc_html_e( 'Email (opcional)', 'cead-acad' ); ?></label></th>
				<td>
					<input type="email" name="email" id="email" class="regular-text" placeholder="<?php esc_attr_e( 'destinatario@ejemplo.com', 'cead-acad' ); ?>">
					<p class="description"><?php esc_html_e( 'Si lo completás, enviamos el link por correo. Igual vas a ver el link copiable en la tabla de abajo.', 'cead-acad' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="expires_days"><?php esc_html_e( 'Expira en (días)', 'cead-acad' ); ?></label></th>
				<td><input type="number" name="expires_days" id="expires_days" value="14" min="1" max="90" class="small-text"></td>
			</tr>
			<tr>
				<th><label for="count"><?php esc_html_e( 'Cantidad', 'cead-acad' ); ?></label></th>
				<td>
					<input type="number" name="count" id="count" value="1" min="1" max="100" class="small-text">
					<p class="description"><?php esc_html_e( 'Útil para crear muchos links de una vez (p. ej. para un curso entero).', 'cead-acad' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Generar invitaciones', 'cead-acad' ) ); ?>
	</form>

	<h2 class="title"><?php esc_html_e( 'Invitaciones', 'cead-acad' ); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:40px"><?php esc_html_e( 'ID', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Rol', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Link de registro', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Expira', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Acciones', 'cead-acad' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $rows ) : ?>
			<tr><td colspan="6"><em><?php esc_html_e( 'Todavía no hay invitaciones.', 'cead-acad' ); ?></em></td></tr>
		<?php else : foreach ( $rows as $row ) :
			$status = Cead_Acad_Invitations::status( $row );
			$label = [
				'valid'   => __( 'Válida', 'cead-acad' ),
				'used'    => __( 'Usada', 'cead-acad' ),
				'expired' => __( 'Expirada', 'cead-acad' ),
				'revoked' => __( 'Revocada', 'cead-acad' ),
				'invalid' => __( 'Inválida', 'cead-acad' ),
			][ $status ] ?? $status;
			$token = Cead_Acad_Invitations::plain_token( $row );
			$url   = $token ? Cead_Acad_Invitations::registration_url( $token ) : '';
		?>
			<tr>
				<td><?php echo (int) $row['id']; ?></td>
				<td><?php echo esc_html( $roles[ $row['role'] ]['display'] ?? $row['role'] ); ?></td>
				<td><?php echo esc_html( $label ); ?><?php if ( $row['email'] ) : ?><br><small><?php echo esc_html( $row['email'] ); ?></small><?php endif; ?></td>
				<td>
					<?php if ( 'valid' === $status && $url ) : ?>
						<input type="text" readonly value="<?php echo esc_attr( $url ); ?>" onfocus="this.select()" style="width:100%;font-family:monospace;font-size:11px;padding:4px 6px" />
					<?php elseif ( $url && 'used' !== $status ) : ?>
						<span style="color:#999">—</span>
					<?php else : ?>
						<span style="color:#999">—</span>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $row['expires_at'] ) ); ?></td>
				<td>
					<?php if ( 'valid' === $status ) : ?>
						<?php if ( $row['email'] ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( 'cead_acad_admin_invite_resend' ); ?>
								<input type="hidden" name="action" value="cead_acad_admin_invite_resend" />
								<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>" />
								<button class="button button-small"><?php esc_html_e( 'Reenviar email', 'cead-acad' ); ?></button>
							</form>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( '¿Revocar esta invitación?', 'cead-acad' ) ); ?>');">
							<?php wp_nonce_field( 'cead_acad_admin_invite_revoke' ); ?>
							<input type="hidden" name="action" value="cead_acad_admin_invite_revoke" />
							<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>" />
							<button class="button-link-delete"><?php esc_html_e( 'Revocar', 'cead-acad' ); ?></button>
						</form>
					<?php else : ?>—<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>
</div>
