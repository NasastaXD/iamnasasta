<?php
/**
 * Vista: Usuarios (gestión manual de usuarios del plugin).
 *
 * Variables disponibles desde render_users():
 *   $notice         array{type,msg}|null
 *   $last_password  string  — contraseña generada al crear, mostrar una sola vez
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$editing_id   = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
$editing_user = $editing_id ? get_user_by( 'id', $editing_id ) : null;

$all_cead_roles = array_keys( Cead_Acad_Capabilities::roles() );
$users          = get_users( [
	'role__in'  => array_merge( $all_cead_roles, [ 'administrator' ] ),
	'number'    => 500,
	'orderby'   => 'display_name',
	'order'     => 'ASC',
] );

$roles_cfg  = Cead_Acad_Capabilities::roles();
$page_url   = admin_url( 'admin.php?page=cead-acad-users' );

// Solo quien gestiona roles puede asignar/ver el rol Dirección en el selector.
$can_assign_direction = current_user_can( 'cead_acad_manage_roles' ) || current_user_can( 'manage_options' );
$roles_cfg_selectable = $can_assign_direction ? $roles_cfg : array_diff_key( $roles_cfg, [ 'cead_acad_direction' => true ] );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Usuarios', 'cead-acad' ); ?></h1>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] === 'success' ? 'success' : 'error' ); ?> is-dismissible">
			<p><?php echo wp_kses_post( $notice['msg'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $last_password ) : ?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Contraseña generada (anotala ahora, no se vuelve a mostrar):', 'cead-acad' ); ?></strong>
				<code style="font-size:1.1em;padding:2px 6px;background:#fff3cd;border-radius:3px"><?php echo esc_html( $last_password ); ?></code>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $editing_user ) : ?>
		<!-- ── Formulario editar usuario ── -->
		<h2><?php /* translators: %s: nombre del usuario en edición */ printf( esc_html__( 'Editar: %s', 'cead-acad' ), esc_html( $editing_user->display_name ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( $page_url ); ?>">
			<?php wp_nonce_field( 'cead_acad_users_edit', '_cead_users_nonce' ); ?>
			<input type="hidden" name="cead_acad_users_action" value="edit">
			<input type="hidden" name="user_id" value="<?php echo esc_attr( $editing_user->ID ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="edit_name"><?php esc_html_e( 'Nombre a mostrar', 'cead-acad' ); ?></label></th>
					<td><input type="text" id="edit_name" name="display_name" class="regular-text" value="<?php echo esc_attr( $editing_user->display_name ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="edit_email"><?php esc_html_e( 'Email', 'cead-acad' ); ?></label></th>
					<td><input type="email" id="edit_email" name="email" class="regular-text" value="<?php echo esc_attr( $editing_user->user_email ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="edit_phone"><?php esc_html_e( 'Teléfono (WhatsApp)', 'cead-acad' ); ?></label></th>
					<td>
						<input type="text" id="edit_phone" name="phone" class="regular-text"
							value="<?php echo esc_attr( get_user_meta( $editing_user->ID, '_cead_acad_phone', true ) ); ?>"
							placeholder="Ej: 595981123456">
						<p class="description"><?php esc_html_e( 'Número completo con código de país, sin +. Ej: 595981123456', 'cead-acad' ); ?></p>
					</td>
				</tr>
				<?php
				// Solo permitir cambio de rol si el usuario NO es administrador de WordPress.
				$is_wp_admin = in_array( 'administrator', (array) $editing_user->roles, true );
				$current_cead_role = '';
				foreach ( $all_cead_roles as $r ) {
					if ( in_array( $r, (array) $editing_user->roles, true ) ) {
						$current_cead_role = $r;
						break;
					}
				}
				// Si ya es Dirección y quien edita no gestiona roles, no se le muestra
				// el selector: no debe poder tocar (ni degradar) ese rol.
				$can_edit_role = ! $is_wp_admin && ( $can_assign_direction || 'cead_acad_direction' !== $current_cead_role );
				if ( $can_edit_role ) :
				?>
				<tr>
					<th scope="row"><label for="edit_role"><?php esc_html_e( 'Rol', 'cead-acad' ); ?></label></th>
					<td>
						<select id="edit_role" name="role">
							<?php foreach ( $roles_cfg_selectable as $slug => $cfg ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_cead_role, $slug ); ?>>
									<?php echo esc_html( $cfg['display'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Contraseña', 'cead-acad' ); ?></th>
					<td><label><input type="checkbox" name="reset_password" value="1"> <?php esc_html_e( 'Generar una nueva contraseña', 'cead-acad' ); ?></label></td>
				</tr>
			</table>
			<p class="submit">
				<?php submit_button( __( 'Guardar cambios', 'cead-acad' ), 'primary', 'submit', false ); ?>
				&nbsp; <a href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'Cancelar', 'cead-acad' ); ?></a>
			</p>
		</form>
		<hr>
	<?php else : ?>
		<!-- ── Formulario crear usuario ── -->
		<h2><?php esc_html_e( 'Agregar usuario', 'cead-acad' ); ?></h2>
		<form method="post" action="<?php echo esc_url( $page_url ); ?>">
			<?php wp_nonce_field( 'cead_acad_users_create', '_cead_users_nonce' ); ?>
			<?php Cead_Acad_Admin_Menu::submit_id_field(); ?>
			<input type="hidden" name="cead_acad_users_action" value="create">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="new_full_name"><?php esc_html_e( 'Nombre completo', 'cead-acad' ); ?> <span style="color:red">*</span></label></th>
					<td><input type="text" id="new_full_name" name="full_name" class="regular-text" required placeholder="<?php esc_attr_e( 'Nombre y apellido', 'cead-acad' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="new_user_login"><?php esc_html_e( 'Usuario', 'cead-acad' ); ?> <span style="color:red">*</span></label></th>
					<td>
						<input type="text" id="new_user_login" name="user_login" class="regular-text" required pattern="[A-Za-z0-9._\-]+" placeholder="<?php esc_attr_e( 'sin espacios', 'cead-acad' ); ?>">
						<p class="description"><?php esc_html_e( 'Solo letras, números, puntos y guiones.', 'cead-acad' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="new_email"><?php esc_html_e( 'Email', 'cead-acad' ); ?></label></th>
					<td>
						<input type="email" id="new_email" name="email" class="regular-text" placeholder="opcional">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="new_phone"><?php esc_html_e( 'Teléfono (WhatsApp)', 'cead-acad' ); ?></label></th>
					<td>
						<input type="text" id="new_phone" name="phone" class="regular-text" placeholder="Ej: 595981123456">
						<p class="description"><?php esc_html_e( 'Número completo con código de país, sin +. Ej: 595981123456', 'cead-acad' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="new_role"><?php esc_html_e( 'Rol', 'cead-acad' ); ?> <span style="color:red">*</span></label></th>
					<td>
						<select id="new_role" name="role">
							<?php foreach ( $roles_cfg_selectable as $slug => $cfg ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, 'cead_acad_student' ); ?>>
									<?php echo esc_html( $cfg['display'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<p class="submit">
				<?php submit_button( __( 'Crear usuario', 'cead-acad' ), 'primary', 'submit', false ); ?>
			</p>
			<p class="description" style="margin-top:-8px">
				<?php esc_html_e( 'La contraseña se genera automáticamente y se muestra una sola vez tras crear el usuario.', 'cead-acad' ); ?>
			</p>
		</form>
		<hr>
	<?php endif; ?>

	<!-- ── Tabla de usuarios ── -->
	<h2 style="margin-top:20px"><?php esc_html_e( 'Usuarios actuales', 'cead-acad' ); ?></h2>
	<?php if ( $users ) : ?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Nombre', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Usuario', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Email', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Rol', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Teléfono', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Acciones', 'cead-acad' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $users as $u ) :
			$phone = get_user_meta( $u->ID, '_cead_acad_phone', true );

			// Determinar rol para mostrar.
			$role_label = '';
			if ( in_array( 'administrator', (array) $u->roles, true ) ) {
				$role_label = __( 'Admin WP', 'cead-acad' );
			} else {
				foreach ( $all_cead_roles as $r ) {
					if ( in_array( $r, (array) $u->roles, true ) ) {
						$role_label = $roles_cfg[ $r ]['display'] ?? $r;
						break;
					}
				}
			}
			$edit_url = add_query_arg( 'edit', $u->ID, $page_url );
		?>
			<?php $is_suspended = Cead_Acad_User_Suspension::is_suspended( $u->ID ); ?>
			<tr<?php echo $is_suspended ? ' style="opacity:.6"' : ''; ?>>
				<td>
					<strong><?php echo esc_html( $u->display_name ); ?></strong>
					<?php if ( $is_suspended ) : ?>
						<span style="background:#d63638;color:#fff;border-radius:3px;padding:1px 6px;font-size:11px;margin-left:6px"><?php esc_html_e( 'Suspendido', 'cead-acad' ); ?></span>
					<?php endif; ?>
				</td>
				<td><code><?php echo esc_html( $u->user_login ); ?></code></td>
				<td><?php echo esc_html( $u->user_email ); ?></td>
				<td><?php echo esc_html( $role_label ); ?></td>
				<td>
					<?php if ( $phone ) : ?>
						<code><?php echo esc_html( $phone ); ?></code>
					<?php else : ?>
						<span style="color:#aaa"><?php esc_html_e( '—', 'cead-acad' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
						<?php esc_html_e( 'Editar', 'cead-acad' ); ?>
					</a>
				<?php
					$is_admin_row = in_array( 'administrator', (array) $u->roles, true );
					$can_delete   = ( current_user_can( 'cead_acad_manage_roles' ) || current_user_can( 'manage_options' ) ) && $u->ID !== get_current_user_id() && ! $is_admin_row;
					if ( $can_delete ) : // mismo gating para suspender/reactivar ?>
						<form method="post" action="<?php echo esc_url( $page_url ); ?>" style="display:inline">
							<?php if ( $is_suspended ) : ?>
								<?php wp_nonce_field( 'cead_acad_users_reactivate', '_cead_users_nonce' ); ?>
								<input type="hidden" name="cead_acad_users_action" value="reactivate">
								<input type="hidden" name="user_id" value="<?php echo esc_attr( $u->ID ); ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Reactivar', 'cead-acad' ); ?></button>
							<?php else : ?>
								<?php wp_nonce_field( 'cead_acad_users_suspend', '_cead_users_nonce' ); ?>
								<input type="hidden" name="cead_acad_users_action" value="suspend">
								<input type="hidden" name="user_id" value="<?php echo esc_attr( $u->ID ); ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Suspender', 'cead-acad' ); ?></button>
							<?php endif; ?>
						</form>
					<?php endif; ?>
				<?php if ( $can_delete ) : ?>
						<form method="post" action="<?php echo esc_url( $page_url ); ?>" style="display:inline" onsubmit="return confirm('<?php /* translators: %s: nombre del usuario a eliminar */ echo esc_js( sprintf( __( 'Eliminar a %s? Esta accion no se puede deshacer.', 'cead-acad' ), $u->display_name ) ); ?>');">
							<?php wp_nonce_field( 'cead_acad_users_delete', '_cead_users_nonce' ); ?>
							<input type="hidden" name="cead_acad_users_action" value="delete">
							<input type="hidden" name="user_id" value="<?php echo esc_attr( $u->ID ); ?>">
							<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Eliminar', 'cead-acad' ); ?></button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php else : ?>
		<p><?php esc_html_e( 'No hay usuarios con roles del plugin todavía.', 'cead-acad' ); ?></p>
	<?php endif; ?>

</div>
