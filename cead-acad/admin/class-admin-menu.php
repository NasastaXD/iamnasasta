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
		echo '<div class="wrap"><h1>' . esc_html__( 'CEAD Académico', 'cead-acad' ) . '</h1>';
		echo '<p>' . esc_html__( 'Bienvenido al panel administrativo del plugin. Esta es la Fase 0 — invitaciones, login y registro están operativos.', 'cead-acad' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Próximos pasos:', 'cead-acad' ) . '</strong></p><ul style="list-style:disc;margin-left:20px">';
		echo '<li>' . esc_html__( 'Ir a Invitaciones y generar un link para sumar usuarios.', 'cead-acad' ) . '</li>';
		echo '<li>' . esc_html__( 'Probar el flujo: copiar el link en una ventana de incógnito y completar el registro.', 'cead-acad' ) . '</li>';
		echo '<li>' . esc_html__( 'En las próximas fases: cursos, comunicados, encuestas, horarios, recursos e importadores.', 'cead-acad' ) . '</li>';
		echo '</ul></div>';
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
