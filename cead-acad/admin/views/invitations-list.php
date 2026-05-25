<?php
/**
 * Pantalla: CEAD Académico → Invitaciones.
 * Lista + formulario inline para crear nuevas invitaciones.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$roles  = Cead_Acad_Capabilities::roles();
$recent = (array) get_transient( 'cead_acad_recent_tokens_' . get_current_user_id() );
delete_transient( 'cead_acad_recent_tokens_' . get_current_user_id() );

$rows = Cead_Acad_Invitations::list_recent( 50 );
?>
<div class="wrap cead-acad-admin-wrap">
	<h1><?php esc_html_e( 'Invitaciones', 'cead-acad' ); ?></h1>
	<p><?php esc_html_e( 'Generá links de invitación. El usuario abre el link, completa el registro y se crea su cuenta con el rol asignado.', 'cead-acad' ); ?></p>

	<?php if ( ! empty( $recent ) ) : ?>
		<div class="notice notice-success">
			<p><strong><?php esc_html_e( 'Links recién creados (visibles SOLO ahora):', 'cead-acad' ); ?></strong></p>
			<ol style="margin-left:20px">
				<?php foreach ( $recent as $t ) : $url = Cead_Acad_Invitations::registration_url( $t ); ?>
					<li style="margin-bottom:6px">
						<code style="user-select:all;background:#f6f7f7;padding:4px 8px;border:1px solid #dcdcde;border-radius:3px"><?php echo esc_html( $url ); ?></code>
					</li>
				<?php endforeach; ?>
			</ol>
			<p><em><?php esc_html_e( 'Copiá estos links ahora — por seguridad no se vuelven a mostrar.', 'cead-acad' ); ?></em></p>
		</div>
	<?php endif; ?>

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
				<td><input type="email" name="email" id="email" class="regular-text" placeholder="<?php esc_attr_e( 'destinatario@ejemplo.com', 'cead-acad' ); ?>"></td>
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

	<h2 class="title"><?php esc_html_e( 'Últimas invitaciones', 'cead-acad' ); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Rol', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Email', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Expira', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Creada', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Acciones', 'cead-acad' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $rows ) : ?>
			<tr><td colspan="7"><em><?php esc_html_e( 'Todavía no hay invitaciones.', 'cead-acad' ); ?></em></td></tr>
		<?php else : foreach ( $rows as $row ) :
			$status = Cead_Acad_Invitations::status( $row );
			$label = [
				'valid'   => __( 'Válida', 'cead-acad' ),
				'used'    => __( 'Usada', 'cead-acad' ),
				'expired' => __( 'Expirada', 'cead-acad' ),
				'revoked' => __( 'Revocada', 'cead-acad' ),
				'invalid' => __( 'Inválida', 'cead-acad' ),
			][ $status ] ?? $status;
		?>
			<tr>
				<td><?php echo (int) $row['id']; ?></td>
				<td><?php echo esc_html( $roles[ $row['role'] ]['display'] ?? $row['role'] ); ?></td>
				<td><?php echo esc_html( $row['email'] ?: '—' ); ?></td>
				<td><?php echo esc_html( $label ); ?></td>
				<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $row['expires_at'] ) ); ?></td>
				<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $row['created_at'] ) ); ?></td>
				<td>
					<?php if ( 'valid' === $status ) : ?>
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
