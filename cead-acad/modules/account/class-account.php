<?php
/**
 * Cuenta del usuario: perfil editable, foto de perfil y token del carné digital.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Account {

	const AVATAR_META = '_cead_acad_avatar_id';
	const PHONE_META  = '_cead_acad_phone';

	public function boot() {
		add_action( 'admin_post_cead_acad_save_profile', [ $this, 'handle_save_profile' ] );
		add_filter( 'get_avatar_data', [ $this, 'filter_avatar_data' ], 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Foto de perfil                                                      */
	/* ------------------------------------------------------------------ */

	public static function avatar_id( $user_id ) {
		return (int) get_user_meta( (int) $user_id, self::AVATAR_META, true );
	}

	public static function avatar_url( $user_id, $size = 'thumbnail' ) {
		$id = self::avatar_id( $user_id );
		if ( $id ) {
			$url = wp_get_attachment_image_url( $id, $size );
			if ( $url ) { return $url; }
		}
		return '';
	}

	/** Iniciales para el avatar de respaldo (sin foto). */
	public static function initials( $name ) {
		$name  = trim( wp_strip_all_tags( (string) $name ) );
		$parts = preg_split( '/\s+/', $name );
		$a = isset( $parts[0][0] ) ? mb_substr( $parts[0], 0, 1 ) : '';
		$b = isset( $parts[1][0] ) ? mb_substr( $parts[1], 0, 1 ) : '';
		return mb_strtoupper( $a . $b );
	}

	/** Que get_avatar() use la foto subida si existe. */
	public function filter_avatar_data( $args, $id_or_email ) {
		$user_id = 0;
		if ( is_numeric( $id_or_email ) ) {
			$user_id = (int) $id_or_email;
		} elseif ( $id_or_email instanceof WP_User ) {
			$user_id = (int) $id_or_email->ID;
		} elseif ( $id_or_email instanceof WP_Comment ) {
			$user_id = (int) $id_or_email->user_id;
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$u = get_user_by( 'email', $id_or_email );
			$user_id = $u ? (int) $u->ID : 0;
		}
		if ( $user_id ) {
			$url = self::avatar_url( $user_id, 'thumbnail' );
			if ( $url ) {
				$args['url']          = $url;
				$args['found_avatar'] = true;
			}
		}
		return $args;
	}

	/* ------------------------------------------------------------------ */
	/* Carné: token firmado (stateless, sin datos sensibles)               */
	/* ------------------------------------------------------------------ */

	public static function carne_token( $user_id ) {
		$user_id = (int) $user_id;
		return $user_id . '-' . self::carne_sig( $user_id );
	}

	protected static function carne_sig( $user_id ) {
		return substr( hash_hmac( 'sha256', 'cead-carne|' . (int) $user_id, wp_salt( 'auth' ) ), 0, 16 );
	}

	/** Devuelve el user_id si el token es válido, o 0. */
	public static function verify_token( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^(\d+)-([a-f0-9]{16})$/', $token, $m ) ) {
			return 0;
		}
		$uid = (int) $m[1];
		return hash_equals( self::carne_sig( $uid ), $m[2] ) ? $uid : 0;
	}

	/* ------------------------------------------------------------------ */
	/* Guardar perfil                                                      */
	/* ------------------------------------------------------------------ */

	public function handle_save_profile() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( cead_acad_url( 'login' ) );
			exit;
		}
		check_admin_referer( 'cead_acad_save_profile' );

		$user_id = get_current_user_id();
		$dest    = cead_acad_url( 'panel/perfil' );

		$display = sanitize_text_field( (string) ( $_POST['display_name'] ?? '' ) );
		if ( $display !== '' ) {
			wp_update_user( [ 'ID' => $user_id, 'display_name' => $display ] );
		}

		$phone = sanitize_text_field( (string) ( $_POST['phone'] ?? '' ) );
		update_user_meta( $user_id, self::PHONE_META, $phone );

		// Foto (opcional).
		if ( ! empty( $_FILES['avatar']['name'] ) && empty( $_FILES['avatar']['error'] ) ) {
			$type = (string) ( $_FILES['avatar']['type'] ?? '' );
			if ( strpos( $type, 'image/' ) !== 0 ) {
				wp_safe_redirect( add_query_arg( 'err', 'tipo', $dest ) );
				exit;
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$attach_id = media_handle_upload( 'avatar', 0, [], [ 'test_form' => false ] );
			if ( is_wp_error( $attach_id ) ) {
				wp_safe_redirect( add_query_arg( 'err', 'subida', $dest ) );
				exit;
			}
			$old = self::avatar_id( $user_id );
			update_user_meta( $user_id, self::AVATAR_META, (int) $attach_id );
			if ( $old && $old !== (int) $attach_id ) {
				wp_delete_attachment( $old, true );
			}
		}

		wp_safe_redirect( add_query_arg( 'done', 1, $dest ) );
		exit;
	}
}
