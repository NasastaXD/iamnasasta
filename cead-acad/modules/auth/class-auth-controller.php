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

		if ( ! $user_login || ! $password ) {
			$this->bail_to( 'login', 'missing_fields' );
		}

		$user = wp_signon( [
			'user_login'    => $user_login,
			'user_password' => $password,
			'remember'      => $remember,
		], is_ssl() );

		if ( is_wp_error( $user ) ) {
			$this->bail_to( 'login', 'bad_credentials' );
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

		$user_id = wp_insert_user( [
			'user_login'   => $user_login,
			'user_pass'    => $password,
			'user_email'   => $email,
			'display_name' => $full_name,
			'first_name'   => $full_name,
			'role'         => $invitation['role'],
		] );

		if ( is_wp_error( $user_id ) ) {
			$this->bail_to( 'registro', 'insert_failed', [ 't' => $token ] );
		}

		update_user_meta( $user_id, '_cead_acad_legal_name', $full_name );
		update_user_meta( $user_id, '_cead_acad_phone', $phone );
		update_user_meta( $user_id, '_cead_acad_invited_via', (int) $invitation['id'] );
		if ( ! empty( $invitation['course_id'] ) ) {
			update_user_meta( $user_id, '_cead_acad_current_course_id', (int) $invitation['course_id'] );
		}

		Cead_Acad_Invitations::mark_used( (int) $invitation['id'], (int) $user_id );

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
