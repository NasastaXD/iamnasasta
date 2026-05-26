<?php
/**
 * Menú "CEAD Académico" en wp-admin + pantalla de Invitaciones.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Admin_Menu {

	public function boot() {
		add_action( 'admin_menu',           [ $this, 'register_menu' ] );
		add_action( 'admin_post_cead_acad_admin_invite_create', [ $this, 'handle_invite_create' ] );
		add_action( 'admin_post_cead_acad_admin_invite_revoke', [ $this, 'handle_invite_revoke' ] );
		add_action( 'admin_notices',        [ $this, 'maybe_flash_notice' ] );
	}

	public function register_menu() {
		add_menu_page(
			__( 'CEAD Académico', 'cead-acad' ),
			__( 'CEAD Académico', 'cead-acad' ),
			'cead_acad_view_panel',
			'cead-acad',
			[ $this, 'render_dashboard' ],
			'dashicons-welcome-learn-more',
			58
		);

		add_submenu_page(
			'cead-acad',
			__( 'Panel', 'cead-acad' ),
			__( 'Panel', 'cead-acad' ),
			'cead_acad_view_panel',
			'cead-acad',
			[ $this, 'render_dashboard' ]
		);

		add_submenu_page(
			'cead-acad',
			__( 'Invitaciones', 'cead-acad' ),
			__( 'Invitaciones', 'cead-acad' ),
			'cead_acad_manage_invitations',
			'cead-acad-invitations',
			[ $this, 'render_invitations' ]
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

		echo '<p>' . esc_html__( 'Estado: todos los módulos planificados (F0–F5) están instalados.', 'cead-acad' ) . '</p>';

		echo '<h2>' . esc_html__( 'Accesos rápidos', 'cead-acad' ) . '</h2>';
		echo '<ul style="list-style:disc;margin-left:20px;line-height:1.8">';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=cead-acad-invitations' ) ) . '">' . esc_html__( 'Invitaciones', 'cead-acad' ) . '</a> — ' . esc_html__( 'generá links para sumar usuarios al sistema.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Cead_Acad_Courses_CPT::POST_TYPE ) ) . '">' . esc_html__( 'Cursos', 'cead-acad' ) . '</a> — ' . esc_html__( 'creá cursos y asigná delegado/a, tutor/a y alumnado.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Cead_Acad_Broadcasts_CPT::POST_TYPE ) ) . '">' . esc_html__( 'Comunicados', 'cead-acad' ) . '</a> — ' . esc_html__( 'publicá comunicados dirigidos a rol, curso o personalmente.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Cead_Acad_Surveys_CPT::POST_TYPE ) ) . '">' . esc_html__( 'Encuestas', 'cead-acad' ) . '</a> — ' . esc_html__( 'creá encuestas con varias preguntas y exportá resultados.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Cead_Acad_Schedule_CPT::POST_TYPE ) ) . '">' . esc_html__( 'Horarios', 'cead-acad' ) . '</a> — ' . esc_html__( 'clases, reuniones, exámenes y eventos.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Cead_Acad_Resources_CPT::POST_TYPE ) ) . '">' . esc_html__( 'Recursos', 'cead-acad' ) . '</a> — ' . esc_html__( 'mapas conceptuales, PDFs y enlaces pedagógicos.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=cead-acad-importers' ) ) . '">' . esc_html__( 'Importadores CSV', 'cead-acad' ) . '</a> — ' . esc_html__( 'subí alumnado y calificaciones desde archivo.', 'cead-acad' ) . '</li>';
		echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Cead_Acad_Tasks_CPT::POST_TYPE ) ) . '">' . esc_html__( 'Tareas (delegado)', 'cead-acad' ) . '</a> — ' . esc_html__( 'asigná tareas a cursos para que las gestionen los delegados.', 'cead-acad' ) . '</li>';
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
		if ( ! current_user_can( 'cead_acad_manage_invitations' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		// Carga de la vista (que internamente hace lista + form).
		include CEAD_ACAD_DIR . 'admin/views/invitations-list.php';
	}

	public function handle_invite_create() {
		if ( ! current_user_can( 'cead_acad_manage_invitations' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		check_admin_referer( 'cead_acad_admin_invite_create' );

		$tokens = Cead_Acad_Invitations::create( [
			'role'         => sanitize_text_field( wp_unslash( $_POST['role'] ?? 'cead_acad_student' ) ),
			'course_id'    => ! empty( $_POST['course_id'] ) ? (int) $_POST['course_id'] : null,
			'email'        => ! empty( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : null,
			'expires_days' => max( 1, min( 90, (int) ( $_POST['expires_days'] ?? 14 ) ) ),
			'count'        => max( 1, min( 100, (int) ( $_POST['count'] ?? 1 ) ) ),
		] );

		// Guardamos los tokens recién creados en transient corto para mostrarlos UNA VEZ en la lista.
		set_transient( 'cead_acad_recent_tokens_' . get_current_user_id(), $tokens, 5 * MINUTE_IN_SECONDS );
		set_transient( 'cead_acad_flash_' . get_current_user_id(), [ 'type' => 'success', 'msg' => sprintf( _n( '%d invitación creada.', '%d invitaciones creadas.', count( $tokens ), 'cead-acad' ), count( $tokens ) ) ], 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=cead-acad-invitations&created=1' ) );
		exit;
	}

	public function handle_invite_revoke() {
		if ( ! current_user_can( 'cead_acad_manage_invitations' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		check_admin_referer( 'cead_acad_admin_invite_revoke' );
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id > 0 ) {
			Cead_Acad_Invitations::revoke( $id );
			set_transient( 'cead_acad_flash_' . get_current_user_id(), [ 'type' => 'success', 'msg' => __( 'Invitación revocada.', 'cead-acad' ) ], 30 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=cead-acad-invitations' ) );
		exit;
	}

	public function maybe_flash_notice() {
		$flash = get_transient( 'cead_acad_flash_' . get_current_user_id() );
		if ( ! $flash ) {
			return;
		}
		delete_transient( 'cead_acad_flash_' . get_current_user_id() );
		$type = ( $flash['type'] ?? 'info' ) === 'success' ? 'notice-success' : 'notice-info';
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $flash['msg'] ?? '' ) );
	}
}
