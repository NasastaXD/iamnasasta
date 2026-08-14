<?php
/**
 * Cuenta del usuario: perfil editable, foto de perfil y token del carné digital.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Account {

	const AVATAR_META = '_cead_acad_avatar_id';
	const PHONE_META  = '_cead_acad_phone';
	const FAV_META    = '_cead_acad_fav_resources';

	const VCODE_META    = '_cead_acad_wa_code';
	const VEXP_META     = '_cead_acad_wa_code_exp';
	const VERIFIED_META = '_cead_acad_phone_verified';

	public function boot() {
		add_action( 'admin_post_cead_acad_save_profile', [ $this, 'handle_save_profile' ] );
		add_action( 'admin_post_cead_acad_toggle_fav',   [ $this, 'handle_toggle_fav' ] );
		add_action( 'admin_post_cead_acad_send_message',  [ $this, 'handle_send_message' ] );
		add_action( 'admin_post_cead_acad_send_wa_code',   [ $this, 'handle_send_wa_code' ] );
		add_action( 'admin_post_cead_acad_verify_wa_code', [ $this, 'handle_verify_wa_code' ] );
		add_filter( 'get_avatar_data', [ $this, 'filter_avatar_data' ], 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Verificación del teléfono por WhatsApp (CEADI)                      */
	/* ------------------------------------------------------------------ */

	public static function is_phone_verified( $user_id ) {
		return (bool) get_user_meta( (int) $user_id, self::VERIFIED_META, true );
	}

	/** Envía un código de 6 dígitos por CEADI al teléfono del perfil. */
	public function handle_send_wa_code() {
		if ( ! is_user_logged_in() ) { wp_safe_redirect( cead_acad_url( 'login' ) ); exit; }
		check_admin_referer( 'cead_acad_send_wa_code' );

		$uid   = get_current_user_id();
		$dest  = cead_acad_url( 'panel/perfil' );
		$phone = preg_replace( '/[^0-9]/', '', (string) get_user_meta( $uid, self::PHONE_META, true ) );

		if ( strlen( $phone ) < 7 ) {
			wp_safe_redirect( add_query_arg( 'verr', 'nophone', $dest ) );
			exit;
		}
		// Anti-spam: un envío por minuto.
		if ( get_transient( 'cead_acad_wa_code_throttle_' . $uid ) ) {
			wp_safe_redirect( add_query_arg( 'verr', 'throttle', $dest ) );
			exit;
		}

		$code = (string) wp_rand( 100000, 999999 );
		update_user_meta( $uid, self::VCODE_META, wp_hash( $code ) );
		update_user_meta( $uid, self::VEXP_META, time() + 600 );
		set_transient( 'cead_acad_wa_code_throttle_' . $uid, 1, 60 );

		$sent = false;
		if ( class_exists( 'Cead_Acad_WA_Module' ) ) {
			$sent = (bool) Cead_Acad_WA_Module::notify( $phone, "🔐 Tu código de verificación CEADI es: *{$code}*\n\nVence en 10 minutos. Si no lo pediste, ignorá este mensaje." );
		}
		wp_safe_redirect( add_query_arg( $sent ? [ 'vsent' => 1 ] : [ 'verr' => 'send' ], $dest ) );
		exit;
	}

	/** Verifica el código ingresado en el panel. */
	public function handle_verify_wa_code() {
		if ( ! is_user_logged_in() ) { wp_safe_redirect( cead_acad_url( 'login' ) ); exit; }
		check_admin_referer( 'cead_acad_verify_wa_code' );

		$uid  = get_current_user_id();
		$dest = cead_acad_url( 'panel/perfil' );
		$code = preg_replace( '/[^0-9]/', '', (string) ( $_POST['code'] ?? '' ) );

		$stored = (string) get_user_meta( $uid, self::VCODE_META, true );
		$exp    = (int) get_user_meta( $uid, self::VEXP_META, true );

		if ( ! $stored || ! $exp || time() > $exp ) {
			wp_safe_redirect( add_query_arg( 'verr', 'expired', $dest ) );
			exit;
		}
		if ( ! hash_equals( $stored, wp_hash( $code ) ) ) {
			wp_safe_redirect( add_query_arg( 'verr', 'bad', $dest ) );
			exit;
		}

		update_user_meta( $uid, self::VERIFIED_META, 1 );
		delete_user_meta( $uid, self::VCODE_META );
		delete_user_meta( $uid, self::VEXP_META );
		wp_safe_redirect( add_query_arg( 'vok', 1, $dest ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Recursos favoritos                                                  */
	/* ------------------------------------------------------------------ */

	public static function fav_ids( $user_id ) {
		$ids = get_user_meta( (int) $user_id, self::FAV_META, true );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	public static function is_fav( $user_id, $resource_id ) {
		return in_array( (int) $resource_id, self::fav_ids( $user_id ), true );
	}

	/** Mensaje directo a un rol (Dirección / Consejo / Administración) → buzón. */
	public function handle_send_message() {
		if ( ! is_user_logged_in() ) { wp_safe_redirect( cead_acad_url( 'login' ) ); exit; }
		check_admin_referer( 'cead_acad_send_message' );

		$uid  = get_current_user_id();
		$user = wp_get_current_user();
		$dest = cead_acad_url( 'panel/contacto' );

		$to  = sanitize_key( (string) ( $_POST['recipient'] ?? '' ) );
		if ( ! in_array( $to, [ 'direccion', 'consejo', 'administracion' ], true ) ) {
			$to = 'direccion';
		}
		$msg = trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ) );
		if ( $msg === '' ) {
			cead_acad_flash( 'contacto_recipient', $to );
			wp_safe_redirect( add_query_arg( 'err', 'vacio', $dest ) );
			exit;
		}
		if ( function_exists( 'cead_acad_has_banned_words' ) && cead_acad_has_banned_words( $msg ) ) {
			cead_acad_flash( 'contacto_message', $msg );
			cead_acad_flash( 'contacto_recipient', $to );
			wp_safe_redirect( add_query_arg( 'err', 'vulgar', $dest ) );
			exit;
		}

		$roles = Cead_Acad_Capabilities::roles();
		$role  = cead_acad_user_role( $uid );
		$rdisp = $roles[ $role ]['display'] ?? $role;
		$phone = (string) get_user_meta( $uid, self::PHONE_META, true );

		$body = sprintf( "✉️ De %s (%s)\n\n%s", $user->display_name, $rdisp, $msg );

		( new Cead_Acad_WA_Store() )->create_suggestion( $phone !== '' ? $phone : null, $body, $to );

		wp_safe_redirect( add_query_arg( 'done', 1, $dest ) );
		exit;
	}

	public function handle_toggle_fav() {
		if ( ! is_user_logged_in() ) { wp_safe_redirect( cead_acad_url( 'login' ) ); exit; }
		check_admin_referer( 'cead_acad_toggle_fav' );

		$uid = get_current_user_id();
		$rid = (int) ( $_POST['resource_id'] ?? 0 );
		if ( $rid ) {
			$ids = self::fav_ids( $uid );
			if ( in_array( $rid, $ids, true ) ) {
				$ids = array_values( array_diff( $ids, [ $rid ] ) );
			} else {
				$ids[] = $rid;
			}
			update_user_meta( $uid, self::FAV_META, $ids );
		}
		$ref = wp_get_referer();
		wp_safe_redirect( $ref ? $ref : cead_acad_url( 'panel/recursos' ) );
		exit;
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
	/* Token de suscripción de calendario (iCal)                           */
	/* ------------------------------------------------------------------ */

	public static function feed_token( $user_id ) {
		$user_id = (int) $user_id;
		return $user_id . '-' . substr( hash_hmac( 'sha256', 'cead-feed|' . $user_id, wp_salt( 'auth' ) ), 0, 16 );
	}

	public static function verify_feed_token( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^(\d+)-([a-f0-9]{16})$/', $token, $m ) ) {
			return 0;
		}
		$uid = (int) $m[1];
		$sig = substr( hash_hmac( 'sha256', 'cead-feed|' . $uid, wp_salt( 'auth' ) ), 0, 16 );
		return hash_equals( $sig, $m[2] ) ? $uid : 0;
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

		$phone   = sanitize_text_field( (string) ( $_POST['phone'] ?? '' ) );
		$anterior = (string) get_user_meta( $user_id, self::PHONE_META, true );

		/*
		 * El teléfono no puede estar en dos fichas.
		 *
		 * Es la llave con la que CEADI reconoce a quien le escribe, así que
		 * repetido no produce un error: produce que el bot le conteste a alguien
		 * con los datos de otro. Y esta es la puerta más expuesta de las tres que
		 * escriben el número —la abre cualquier alumno desde su propio perfil—,
		 * así que también es por donde alguien podría anotarse el número de un
		 * compañero y dejarlo sin bot.
		 */
		if ( $phone !== '' && class_exists( 'Cead_Acad_WA_Identity' ) ) {
			if ( Cead_Acad_WA_Identity::phone_taken_by( $phone, $user_id ) ) {
				cead_acad_flash( 'perfil_phone', $anterior );
				wp_safe_redirect( add_query_arg( 'err', 'tel_ocupado', $dest ) );
				exit;
			}
		}

		Cead_Acad_WA_Identity::store_phone( $user_id, $phone );

		/*
		 * Cambió el número → la verificación anterior deja de valer.
		 *
		 * La marca se ponía al confirmar el código y no la borraba nadie: quien
		 * verificaba un número y después lo cambiaba se quedaba con el ✅ puesto
		 * sobre un número que nunca confirmó. Se compara normalizado para que
		 * reescribir el mismo número con otro formato («0981…» por «+595 981…»)
		 * no cuente como cambio y obligue a verificar de nuevo sin motivo.
		 */
		if ( class_exists( 'Cead_Acad_WA_Identity' ) ) {
			$cambio = Cead_Acad_WA_Identity::normalize_phone( $anterior ) !== Cead_Acad_WA_Identity::normalize_phone( $phone );
			if ( $cambio ) {
				delete_user_meta( $user_id, self::VERIFIED_META );
			}
		}

		// Foto (opcional).
		if ( ! empty( $_FILES['avatar']['name'] ) && empty( $_FILES['avatar']['error'] ) ) {
			$type = (string) ( $_FILES['avatar']['type'] ?? '' );
			if ( strpos( $type, 'image/' ) !== 0 ) {
				cead_acad_flash( 'perfil_phone', $phone );
				wp_safe_redirect( add_query_arg( 'err', 'tipo', $dest ) );
				exit;
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$attach_id = media_handle_upload( 'avatar', 0, [], [ 'test_form' => false ] );
			if ( is_wp_error( $attach_id ) ) {
				cead_acad_flash( 'perfil_phone', $phone );
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
