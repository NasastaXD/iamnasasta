<?php
/**
 * Flujo de recuperación de password con UI propia.
 * Usa get_password_reset_key() / check_password_reset_key() de core.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Password_Reset {

	public function boot() {
		add_action( 'admin_post_nopriv_cead_acad_recover_request', [ $this, 'handle_request' ] );
		add_action( 'admin_post_cead_acad_recover_request',        [ $this, 'handle_request' ] );

		add_action( 'admin_post_nopriv_cead_acad_recover_confirm', [ $this, 'handle_confirm' ] );
		add_action( 'admin_post_cead_acad_recover_confirm',        [ $this, 'handle_confirm' ] );
	}

	public function handle_request() {
		if ( ! cead_acad_verify_nonce( '_cead_nonce', 'cead_acad_recover_request' ) ) {
			$this->bail( 'recover', 'invalid_nonce' );
		}
		if ( ! cead_acad_rate_limit( 'recover', 3, 60 ) ) {
			$this->bail( 'recover', 'rate_limited' );
		}

		$user_login = sanitize_text_field( wp_unslash( $_POST['user_login'] ?? '' ) );
		if ( ! $user_login ) {
			$this->bail( 'recover', 'missing_fields' );
		}

		// Acepta email o usuario.
		$user = is_email( $user_login ) ? get_user_by( 'email', $user_login ) : get_user_by( 'login', $user_login );

		// No filtramos si existe o no por seguridad: siempre mostramos "te enviamos un email si la cuenta existe".
		if ( $user ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				$reset_url = add_query_arg( [
					'k' => $key,
					'u' => $user->user_login,
				], cead_acad_url( 'recuperar/restablecer' ) );

				/* translators: %s: nombre del sitio */
				$subject = sprintf( __( '[%s] Restablecer contraseña', 'cead-acad' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
				/* translators: %s: nombre del usuario */
				$message  = sprintf( __( "Hola %s,\n\n", 'cead-acad' ), $user->display_name );
				$message .= __( "Recibimos un pedido para restablecer tu contraseña. Si fuiste vos, hacé clic en el siguiente link:\n\n", 'cead-acad' );
				$message .= $reset_url . "\n\n";
				$message .= __( "Si no fuiste vos, ignorá este mensaje.\n\n", 'cead-acad' );
				$message .= sprintf( __( '— %s', 'cead-acad' ), get_bloginfo( 'name' ) );

				wp_mail( $user->user_email, $subject, $message );
			}
		}

		wp_safe_redirect( add_query_arg( 'ok', 1, cead_acad_url( 'recuperar' ) ) );
		exit;
	}

	public function handle_confirm() {
		if ( ! cead_acad_verify_nonce( '_cead_nonce', 'cead_acad_recover_confirm' ) ) {
			$this->bail( 'recover', 'invalid_nonce' );
		}
		if ( ! cead_acad_rate_limit( 'recover_confirm', 5, 60 ) ) {
			$this->bail( 'recover', 'rate_limited' );
		}

		$key       = sanitize_text_field( wp_unslash( $_POST['k'] ?? '' ) );
		$login     = sanitize_user( wp_unslash( $_POST['u'] ?? '' ), true );
		$password  = (string) ( $_POST['user_pass'] ?? '' );
		$password2 = (string) ( $_POST['user_pass2'] ?? '' );

		if ( ! $key || ! $login || ! $password ) {
			$this->bail_reset( $key, $login, 'missing_fields' );
		}
		if ( strlen( $password ) < 8 ) {
			$this->bail_reset( $key, $login, 'weak_password' );
		}
		if ( $password !== $password2 ) {
			$this->bail_reset( $key, $login, 'password_mismatch' );
		}

		$user = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) {
			$this->bail( 'recover', 'invalid_or_expired' );
		}

		reset_password( $user, $password );

		wp_safe_redirect( add_query_arg( 'ok', 'reset', cead_acad_url( 'login' ) ) );
		exit;
	}

	protected function bail( $route, $code ) {
		wp_safe_redirect( add_query_arg( 'err', $code, cead_acad_url( $route ) ) );
		exit;
	}
	protected function bail_reset( $k, $u, $code ) {
		wp_safe_redirect( add_query_arg( [ 'err' => $code, 'k' => $k, 'u' => $u ], cead_acad_url( 'recuperar/restablecer' ) ) );
		exit;
	}
}
