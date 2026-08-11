<?php
/**
 * Maneja los submits de login y registro.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Auth_Controller {

	public function boot() {
		add_action( 'admin_post_nopriv_cead_acad_login',    [ $this, 'handle_login' ] );
		add_action( 'admin_post_cead_acad_login',           [ $this, 'handle_login' ] );

		add_action( 'admin_post_nopriv_cead_acad_register', [ $this, 'handle_register' ] );
		add_action( 'admin_post_cead_acad_register',        [ $this, 'handle_register' ] );
	}

	public function handle_login() {
		if ( ! cead_acad_verify_nonce( '_cead_nonce', 'cead_acad_login' ) ) {
			$this->bail_to( 'login', 'invalid_nonce' );
		}
		if ( ! cead_acad_rate_limit( 'login', 8, 60 ) ) {
			$this->bail_to( 'login', 'rate_limited' );
		}

		$user_login = sanitize_user( wp_unslash( $_POST['user_login'] ?? '' ), true );
		$password   = (string) ( $_POST['user_pass'] ?? '' );
		$remember   = ! empty( $_POST['remember'] );

		// Conservar el usuario tipeado para repoblar el formulario si algo falla.
		if ( $user_login ) {
			cead_acad_flash( 'login_user', $user_login );
		}

		if ( ! $user_login || ! $password ) {
			$this->bail_to( 'login', 'missing_fields' );
		}

		$user = wp_signon( [
			'user_login'    => $user_login,
			'user_password' => $password,
			'remember'      => $remember,
		], is_ssl() );

		if ( is_wp_error( $user ) ) {
			$code = 'cead_acad_suspended' === $user->get_error_code() ? 'suspended' : 'bad_credentials';
			$this->bail_to( 'login', $code );
		}

		$redirect = ! empty( $_POST['next'] ) ? esc_url_raw( wp_unslash( $_POST['next'] ) ) : cead_acad_url( 'panel' );
		// Solo permitir redirect interno.
		if ( wp_parse_url( $redirect, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$redirect = cead_acad_url( 'panel' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_register() {
		if ( ! cead_acad_verify_nonce( '_cead_nonce', 'cead_acad_register' ) ) {
			$this->bail_to( 'registro', 'invalid_nonce' );
		}
		if ( ! cead_acad_rate_limit( 'register', 5, 60 ) ) {
			$this->bail_to( 'registro', 'rate_limited' );
		}

		$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		if ( ! $token ) {
			$this->bail_to( 'registro', 'missing_token' );
		}
		$invitation = Cead_Acad_Invitations::find_by_token( $token );
		$status = Cead_Acad_Invitations::status( $invitation );
		if ( 'valid' !== $status ) {
			$this->bail_to( 'registro', 'invitation_' . $status, [ 't' => $token ] );
		}

		$full_name  = sanitize_text_field( wp_unslash( $_POST['full_name'] ?? '' ) );
		$user_login = sanitize_user( wp_unslash( $_POST['user_login'] ?? '' ), true );
		$email      = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone      = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$password   = (string) ( $_POST['user_pass'] ?? '' );
		$password2  = (string) ( $_POST['user_pass2'] ?? '' );

		// Conservar lo tipeado para repoblar el formulario si la validación falla.
		// (Nunca guardamos contraseñas.)
		cead_acad_flash( 'reg_user_login', $user_login );
		cead_acad_flash( 'reg_email', $email );
		cead_acad_flash( 'reg_full_name', $full_name );
		cead_acad_flash( 'reg_phone', $phone );

		if ( ! $full_name || ! $user_login || ! $email || ! $password ) {
			$this->bail_to( 'registro', 'missing_fields', [ 't' => $token ] );
		}
		if ( ! $phone ) {
			$this->bail_to( 'registro', 'missing_phone', [ 't' => $token ] );
		}
		if ( strlen( $password ) < 8 ) {
			$this->bail_to( 'registro', 'weak_password', [ 't' => $token ] );
		}
		if ( $password !== $password2 ) {
			$this->bail_to( 'registro', 'password_mismatch', [ 't' => $token ] );
		}
		if ( ! is_email( $email ) ) {
			$this->bail_to( 'registro', 'bad_email', [ 't' => $token ] );
		}
		if ( username_exists( $user_login ) ) {
			$this->bail_to( 'registro', 'username_taken', [ 't' => $token ] );
		}
		if ( email_exists( $email ) ) {
			$this->bail_to( 'registro', 'email_taken', [ 't' => $token ] );
		}

		// Curso: el del invite manda; si no trae y es Alumno/a o Delegado/a, el elegido en el form.
		$needs_course = in_array( $invitation['role'], [ 'cead_acad_student', 'cead_acad_delegate' ], true );
		$course_id    = ! empty( $invitation['course_id'] ) ? (int) $invitation['course_id'] : 0;
		if ( ! $course_id && $needs_course ) {
			$sel = isset( $_POST['course_id'] ) ? (int) $_POST['course_id'] : 0;
			if ( $sel && get_post_type( $sel ) === 'cead_acad_course' ) {
				$course_id = $sel;
			}
		}
		if ( $needs_course && ! $course_id ) {
			$this->bail_to( 'registro', 'missing_course', [ 't' => $token ] );
		}

		// Reservar un uso de la invitación de forma atómica ANTES de crear el usuario:
		// si el link ya llegó a su tope de usos (multiuso), no dejamos pasar.
		if ( ! Cead_Acad_Invitations::consume( (int) $invitation['id'] ) ) {
			$this->bail_to( 'registro', 'invitation_used', [ 't' => $token ] );
		}

		$user_id = wp_insert_user( [
			'user_login'   => $user_login,
			'user_pass'    => $password,
			'user_email'   => $email,
			'display_name' => $full_name,
			'first_name'   => $full_name,
			'role'         => $invitation['role'],
		] );

		if ( is_wp_error( $user_id ) ) {
			// Devolver el uso reservado: la creación no prosperó.
			Cead_Acad_Invitations::refund( (int) $invitation['id'] );
			$this->bail_to( 'registro', 'insert_failed', [ 't' => $token ] );
		}

		update_user_meta( $user_id, '_cead_acad_legal_name', $full_name );
		update_user_meta( $user_id, '_cead_acad_phone', $phone );
		update_user_meta( $user_id, '_cead_acad_invited_via', (int) $invitation['id'] );
		if ( $course_id ) {
			$role_in_course = ( $invitation['role'] === 'cead_acad_delegate' ) ? 'delegate' : 'student';
			$inscripto      = Cead_Acad_Courses_Roster::add( (int) $user_id, $course_id, $role_in_course );
			if ( $inscripto ) {
				update_user_meta( $user_id, '_cead_acad_current_course_id', $course_id );
			} else {
				/*
				 * Acá no se puede abortar: la cuenta ya está creada y el uso de la
				 * invitación ya se consumió, así que cancelar dejaría a la persona
				 * sin cuenta y sin invitación. Se la deja entrar y queda registrado
				 * para que secretaría la inscriba a mano.
				 *
				 * El meta del curso NO se escribe: apuntaría a un curso donde no
				 * está, y el panel mostraría un horario que no le corresponde.
				 */
				error_log( '[CeadAcad][Registro] usuario ' . (int) $user_id . ' creado pero NO inscripto en el curso ' . (int) $course_id );
				Cead_Acad_Audit::log( 'user_registered_without_course', [
					'user_id'     => $user_id,
					'entity_type' => 'user',
					'entity_id'   => $user_id,
					'payload'     => [ 'course_id' => (int) $course_id, 'role_in_course' => $role_in_course ],
				] );
			}
		}

		// El uso ya se reservó con consume() antes de crear el usuario; el vínculo
		// usuario → invitación queda en el meta _cead_acad_invited_via (arriba).

		Cead_Acad_Audit::log( 'user_self_registered', [
			'user_id'     => $user_id,
			'entity_type' => 'user',
			'entity_id'   => $user_id,
			'payload'     => [ 'login' => $user_login, 'role' => $invitation['role'], 'invitation_id' => (int) $invitation['id'] ],
		] );

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );

		wp_safe_redirect( cead_acad_url( 'panel' ) );
		exit;
	}

	protected function bail_to( $route, $code, $extra = [] ) {
		$args = array_merge( [ 'err' => $code ], $extra );
		wp_safe_redirect( add_query_arg( $args, cead_acad_url( $route ) ) );
		exit;
	}
}
