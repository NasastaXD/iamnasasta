<?php
/**
 * Menú "CEAD Académico" en wp-admin + pantalla de Invitaciones.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Admin_Menu {

	public function boot() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		// Las invitaciones se procesan inline en render_invitations() (post a la
		// misma página), no vía admin-post.php.
	}

	public function register_menu() {
		// Gating por rol staff (robusto ante caps custom no instaladas).
		// Si no es staff, no registramos nada del backend del plugin.
		if ( ! cead_acad_user_is_staff() ) {
			return;
		}

		add_menu_page(
			__( 'CEAD Académico', 'cead-acad' ),
			__( 'CEAD Académico', 'cead-acad' ),
			'read',
			'cead-acad',
			[ $this, 'render_dashboard' ],
			'dashicons-welcome-learn-more',
			58
		);

		add_submenu_page(
			'cead-acad',
			__( 'Panel', 'cead-acad' ),
			__( 'Panel', 'cead-acad' ),
			'read',
			'cead-acad',
			[ $this, 'render_dashboard' ]
		);

		add_submenu_page(
			'cead-acad',
			__( 'Invitaciones', 'cead-acad' ),
			__( 'Invitaciones', 'cead-acad' ),
			'read',
			'cead-acad-invitations',
			[ $this, 'render_invitations' ]
		);

		add_submenu_page(
			'cead-acad',
			__( 'Usuarios', 'cead-acad' ),
			__( 'Usuarios', 'cead-acad' ),
			'read',
			'cead-acad-users',
			[ $this, 'render_users' ]
		);
	}

	public function render_dashboard() {
		$user = wp_get_current_user();
		echo '<div class="wrap"><h1>' . esc_html__( 'CEAD Académico', 'cead-acad' ) . '</h1>';

		echo '<p>' . sprintf(
			/* translators: %s nombre del usuario */
			esc_html__( 'Hola, %s. Este es el panel administrativo del plugin.', 'cead-acad' ),
			'<strong>' . esc_html( $user->display_name ) . '</strong>'
		) . '</p>';

		echo '<p>' . sprintf( esc_html__( 'Estado: todos los módulos planificados (F0–F5) están instalados. Versión %s.', 'cead-acad' ), esc_html( CEAD_ACAD_VERSION ) ) . '</p>';

		// Diagnóstico de acceso (útil para depurar permisos).
		$roles = implode( ', ', (array) $user->roles ) ?: '(ninguno)';
		echo '<div class="notice notice-info inline" style="padding:8px 12px"><p style="margin:0"><strong>' . esc_html__( 'Diagnóstico de acceso', 'cead-acad' ) . ':</strong> ';
		echo esc_html__( 'Roles', 'cead-acad' ) . ': <code>' . esc_html( $roles ) . '</code> · ';
		echo 'manage_options: ' . ( current_user_can( 'manage_options' ) ? '✅' : '❌' ) . ' · ';
		echo 'cead_acad_import_data: ' . ( current_user_can( 'cead_acad_import_data' ) ? '✅' : '❌' ) . ' · ';
		echo 'cead_acad_manage_invitations: ' . ( current_user_can( 'cead_acad_manage_invitations' ) ? '✅' : '❌' ) . ' · ';
		echo 'staff: ' . ( cead_acad_user_is_staff() ? '✅' : '❌' );
		echo '</p></div>';

		echo '<h2>' . esc_html__( 'Accesos rápidos', 'cead-acad' ) . '</h2>';
		echo '<ul style="list-style:disc;margin-left:20px;line-height:1.8">';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=cead-acad-users' ) ) . '">' . esc_html__( 'Usuarios', 'cead-acad' ) . '</a> — ' . esc_html__( 'creá usuarios manualmente y asigná roles y teléfono.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=cead-acad-invitations' ) ) . '">' . esc_html__( 'Invitaciones', 'cead-acad' ) . '</a> — ' . esc_html__( 'generá links para sumar usuarios al sistema.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Cead_Acad_Courses_CPT::POST_TYPE ) ) . '">' . esc_html__( 'Cursos', 'cead-acad' ) . '</a> — ' . esc_html__( 'creá cursos y asigná delegado/a, tutor/a y alumnado.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Cead_Acad_Broadcasts_CPT::POST_TYPE ) ) . '">' . esc_html__( 'Comunicados', 'cead-acad' ) . '</a> — ' . esc_html__( 'publicá comunicados dirigidos a rol, curso o personalmente.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Cead_Acad_Surveys_CPT::POST_TYPE ) ) . '">' . esc_html__( 'Encuestas', 'cead-acad' ) . '</a> — ' . esc_html__( 'creá encuestas con varias preguntas y exportá resultados.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Cead_Acad_Schedule_CPT::POST_TYPE ) ) . '">' . esc_html__( 'Horarios', 'cead-acad' ) . '</a> — ' . esc_html__( 'clases, reuniones, exámenes y eventos.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Cead_Acad_Resources_CPT::POST_TYPE ) ) . '">' . esc_html__( 'Recursos', 'cead-acad' ) . '</a> — ' . esc_html__( 'mapas conceptuales, PDFs y enlaces pedagógicos.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=cead-acad-importers' ) ) . '">' . esc_html__( 'Importadores CSV', 'cead-acad' ) . '</a> — ' . esc_html__( 'subí alumnado y calificaciones desde archivo.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Cead_Acad_Tasks_CPT::POST_TYPE ) ) . '">' . esc_html__( 'Tareas (delegado)', 'cead-acad' ) . '</a> — ' . esc_html__( 'asigná tareas a cursos para que las gestionen los delegados.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=cead-acad-whatsapp' ) ) . '">' . esc_html__( 'Bot de WhatsApp', 'cead-acad' ) . '</a> — ' . esc_html__( 'estado del bridge, comunicados, reportes y mensajes del bot.', 'cead-acad' ) . '</li>';
		echo '</ul>';

		echo '<h2>' . esc_html__( 'Panel frontend', 'cead-acad' ) . '</h2>';
		echo '<p>' . sprintf(
			/* translators: 1: URL panel, 2: URL login */
			esc_html__( 'Los usuarios acceden al panel estilizado en %1$s y al login custom en %2$s.', 'cead-acad' ),
			'<a href="' . esc_url( cead_acad_url( 'panel' ) ) . '">' . esc_html( cead_acad_url( 'panel' ) ) . '</a>',
			'<a href="' . esc_url( cead_acad_url( 'login' ) ) . '">' . esc_html( cead_acad_url( 'login' ) ) . '</a>'
		) . '</p>';
		echo '<p><em>' . esc_html__( 'Tip: si recién activaste el plugin y las rutas frontend devuelven 404, andá a Ajustes → Enlaces permanentes y guardá una vez para refrescar las rewrite rules.', 'cead-acad' ) . '</em></p>';

		echo '</div>';
	}

	public function render_invitations() {
		if ( ! cead_acad_user_is_staff() ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		// Procesamos el POST INLINE (en la misma página) en vez de rebotar por
		// admin-post.php, que en entornos embebidos/proxy a veces no completa el
		// redirect. $notice se pasa a la vista.
		$notice = $this->process_invitation_post();
		$created_links = $this->last_created_links;
		include CEAD_ACAD_DIR . 'admin/views/invitations-list.php';
	}

	/** @var array<int,array{url:string,email:string}> Links recién creados para mostrar. */
	protected $last_created_links = [];

	/**
	 * Procesa los formularios de invitaciones posteados a esta misma página.
	 *
	 * @return array{type:string,msg:string}|null
	 */
	protected function process_invitation_post() {
		if ( empty( $_POST['cead_acad_inv_action'] ) ) {
			return null;
		}
		$action = sanitize_key( wp_unslash( $_POST['cead_acad_inv_action'] ) );

		if ( ! isset( $_POST['_cead_inv_nonce'] ) || ! wp_verify_nonce( $_POST['_cead_inv_nonce'], 'cead_acad_inv_' . $action ) ) {
			return [ 'type' => 'error', 'msg' => __( 'Sesión expirada. Probá de nuevo.', 'cead-acad' ) ];
		}

		switch ( $action ) {
			case 'create':
				return $this->do_create();
			case 'invite_users':
				return $this->do_invite_registered();
			case 'resend':
				return $this->do_resend();
			case 'revoke':
				return $this->do_revoke();
		}
		return null;
	}

	protected function do_create() {
		$email = ! empty( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : null;
		$role  = sanitize_text_field( wp_unslash( $_POST['role'] ?? 'cead_acad_student' ) );
		$tokens = Cead_Acad_Invitations::create( [
			'role'         => $role,
			'course_id'    => ! empty( $_POST['course_id'] ) ? (int) $_POST['course_id'] : null,
			'email'        => $email,
			'expires_days' => max( 1, min( 90, (int) ( $_POST['expires_days'] ?? 14 ) ) ),
			'count'        => max( 1, min( 100, (int) ( $_POST['count'] ?? 1 ) ) ),
		] );

		foreach ( $tokens as $t ) {
			$this->last_created_links[] = [ 'url' => Cead_Acad_Invitations::registration_url( $t ), 'email' => $email ?: '' ];
		}

		$msg = sprintf( _n( '%d invitación creada.', '%d invitaciones creadas.', count( $tokens ), 'cead-acad' ), count( $tokens ) );
		if ( $email ) {
			$msg .= ' ' . sprintf( __( 'Email enviado a %s (si el correo del sitio está configurado).', 'cead-acad' ), $email );
		}
		return [ 'type' => 'success', 'msg' => $msg ];
	}

	protected function do_invite_registered() {
		$role     = sanitize_text_field( wp_unslash( $_POST['role'] ?? 'cead_acad_student' ) );
		$user_ids = array_map( 'intval', (array) ( $_POST['user_ids'] ?? [] ) );
		$user_ids = array_filter( array_unique( $user_ids ) );
		if ( ! $user_ids ) {
			return [ 'type' => 'error', 'msg' => __( 'Elegí al menos un usuario.', 'cead-acad' ) ];
		}
		$sent = 0;
		foreach ( $user_ids as $uid ) {
			$u = get_user_by( 'id', $uid );
			if ( ! $u || ! $u->user_email ) {
				continue;
			}
			$tokens = Cead_Acad_Invitations::create( [
				'role'         => $role,
				'email'        => $u->user_email,
				'expires_days' => 14,
				'count'        => 1,
			] );
			if ( $tokens ) {
				$this->last_created_links[] = [ 'url' => Cead_Acad_Invitations::registration_url( $tokens[0] ), 'email' => $u->user_email ];
				$sent++;
			}
		}
		return [ 'type' => 'success', 'msg' => sprintf( _n( 'Invitación enviada a %d usuario.', 'Invitaciones enviadas a %d usuarios.', $sent, 'cead-acad' ), $sent ) ];
	}

	protected function do_resend() {
		$id  = (int) ( $_POST['id'] ?? 0 );
		$row = $id ? Cead_Acad_Invitations::find_by_id( $id ) : null;
		if ( $row && $row['email'] ) {
			$token = Cead_Acad_Invitations::plain_token( $row );
			if ( $token ) {
				Cead_Acad_Invitations::send_email( $row['email'], $token, $row['role'] );
				return [ 'type' => 'success', 'msg' => sprintf( __( 'Email reenviado a %s.', 'cead-acad' ), $row['email'] ) ];
			}
		}
		return [ 'type' => 'error', 'msg' => __( 'No se pudo reenviar (sin email o token).', 'cead-acad' ) ];
	}

	protected function do_revoke() {
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id > 0 ) {
			Cead_Acad_Invitations::revoke( $id );
			return [ 'type' => 'success', 'msg' => __( 'Invitación revocada.', 'cead-acad' ) ];
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Usuarios
	// -------------------------------------------------------------------------

	/** @var string Contraseña generada al crear un usuario, para mostrar una vez. */
	protected $last_created_password = '';

	public function render_users() {
		if ( ! cead_acad_user_is_staff() ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		$notice            = $this->process_users_post();
		$last_password     = $this->last_created_password;
		include CEAD_ACAD_DIR . 'admin/views/users-list.php';
	}

	protected function process_users_post() {
		if ( empty( $_POST['cead_acad_users_action'] ) ) {
			return null;
		}
		$action = sanitize_key( wp_unslash( $_POST['cead_acad_users_action'] ) );
		if ( ! isset( $_POST['_cead_users_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cead_users_nonce'] ) ), 'cead_acad_users_' . $action ) ) {
			return [ 'type' => 'error', 'msg' => __( 'Sesión expirada. Probá de nuevo.', 'cead-acad' ) ];
		}
		switch ( $action ) {
			case 'create': return $this->do_create_user();
			case 'edit':   return $this->do_edit_user();
		}
		return null;
	}

	protected function do_create_user() {
		$full_name  = sanitize_text_field( wp_unslash( $_POST['full_name'] ?? '' ) );
		$user_login = sanitize_user( wp_unslash( $_POST['user_login'] ?? '' ), true );
		$email      = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone      = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$role       = sanitize_key( wp_unslash( $_POST['role'] ?? 'cead_acad_student' ) );

		if ( ! $full_name || ! $user_login ) {
			return [ 'type' => 'error', 'msg' => __( 'Nombre y usuario son obligatorios.', 'cead-acad' ) ];
		}
		if ( username_exists( $user_login ) ) {
			return [ 'type' => 'error', 'msg' => __( 'Ese nombre de usuario ya está en uso.', 'cead-acad' ) ];
		}
		if ( $email && email_exists( $email ) ) {
			return [ 'type' => 'error', 'msg' => __( 'Ya hay una cuenta con ese email.', 'cead-acad' ) ];
		}

		$valid_roles = array_keys( Cead_Acad_Capabilities::roles() );
		if ( ! in_array( $role, $valid_roles, true ) ) {
			$role = 'cead_acad_student';
		}

		$password = wp_generate_password( 12, false );
		$args     = [
			'user_login'   => $user_login,
			'user_pass'    => $password,
			'display_name' => $full_name,
			'first_name'   => $full_name,
			'role'         => $role,
		];
		if ( $email ) {
			$args['user_email'] = $email;
		}

		$user_id = wp_insert_user( $args );
		if ( is_wp_error( $user_id ) ) {
			return [ 'type' => 'error', 'msg' => $user_id->get_error_message() ];
		}

		update_user_meta( $user_id, '_cead_acad_legal_name', $full_name );
		if ( $phone !== '' ) {
			update_user_meta( $user_id, '_cead_acad_phone', $phone );
		}

		$this->last_created_password = $password;
		$roles        = Cead_Acad_Capabilities::roles();
		$role_display = $roles[ $role ]['display'] ?? $role;
		return [ 'type' => 'success', 'msg' => sprintf( __( 'Usuario "%s" creado como %s.', 'cead-acad' ), esc_html( $user_login ), esc_html( $role_display ) ) ];
	}

	protected function do_edit_user() {
		$user_id = (int) ( $_POST['user_id'] ?? 0 );
		if ( $user_id < 1 || ! get_user_by( 'id', $user_id ) ) {
			return [ 'type' => 'error', 'msg' => __( 'Usuario inválido.', 'cead-acad' ) ];
		}

		$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$role  = sanitize_key( wp_unslash( $_POST['role'] ?? '' ) );

		update_user_meta( $user_id, '_cead_acad_phone', $phone );

		if ( $role !== '' ) {
			$valid_roles = array_keys( Cead_Acad_Capabilities::roles() );
			if ( in_array( $role, $valid_roles, true ) ) {
				$user = new WP_User( $user_id );
				// Eliminar roles cead_acad_* previos y asignar el nuevo.
				foreach ( $valid_roles as $vr ) {
					if ( in_array( $vr, (array) $user->roles, true ) ) {
						$user->remove_role( $vr );
					}
				}
				$user->add_role( $role );
			}
		}

		return [ 'type' => 'success', 'msg' => __( 'Usuario actualizado.', 'cead-acad' ) ];
	}
}
