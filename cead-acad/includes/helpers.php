<?php
/**
 * Funciones helper compartidas.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Nombres canónicos de las tablas custom del plugin.
 */
function cead_acad_table( $name ) {
	global $wpdb;
	$allowed = [ 'invitations', 'audit_log', 'roster', 'audiences', 'broadcast_reads' ];
	$name = in_array( $name, $allowed, true ) ? $name : 'invitations';
	return $wpdb->prefix . 'cead_acad_' . $name;
}

/**
 * Rol principal del usuario relevante para el plugin.
 */
function cead_acad_user_role( $user = null ) {
	$user = $user ? get_user_by( 'id', (int) $user ) : wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return '';
	}
	foreach ( (array) $user->roles as $role ) {
		if ( str_starts_with( $role, 'cead_acad_' ) ) {
			return $role;
		}
	}
	return (string) ( $user->roles[0] ?? '' );
}

/**
 * Genera un token alfanumérico criptográficamente seguro.
 */
function cead_acad_generate_token() {
	return bin2hex( random_bytes( 24 ) ); // 48 chars hex
}

/**
 * Token-hash para almacenar en BD (no guardamos el token plano).
 */
function cead_acad_hash_token( $token ) {
	return hash( 'sha256', $token );
}

/**
 * Carga un template del plugin permitiendo override desde el tema activo en
 * /<theme>/cead-acad/<ruta>.php
 */
function cead_acad_template( $relative, $args = [] ) {
	$loader = new Cead_Acad_Template_Loader();
	$loader->render( $relative, $args );
}

/**
 * URL absoluta a un asset del plugin.
 */
function cead_acad_asset( $path ) {
	return CEAD_ACAD_URL . ltrim( $path, '/' );
}

/**
 * Sanitiza color hex (#fff o #ffffff). Devuelve '' si inválido.
 */
function cead_acad_sanitize_hex( $color ) {
	$color = is_string( $color ) ? trim( $color ) : '';
	return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $color ) ? $color : '';
}

/**
 * Devuelve URL a una ruta del panel/auth.
 */
function cead_acad_url( $route ) {
	return home_url( '/' . ltrim( $route, '/' ) );
}

/**
 * Flash messages basados en transients namespaced por sesión/IP.
 */
function cead_acad_flash( $key, $value = null ) {
	$bucket = 'cead_acad_flash_' . md5( ( $_SERVER['REMOTE_ADDR'] ?? '' ) . ( wp_get_session_token() ?: 'anon' ) );
	$flashes = get_transient( $bucket );
	$flashes = is_array( $flashes ) ? $flashes : [];
	if ( null === $value ) {
		$out = $flashes[ $key ] ?? null;
		if ( null !== $out ) {
			unset( $flashes[ $key ] );
			set_transient( $bucket, $flashes, 60 );
		}
		return $out;
	}
	$flashes[ $key ] = $value;
	set_transient( $bucket, $flashes, 60 );
	return null;
}

/**
 * Verifica un nonce simple con action.
 */
function cead_acad_verify_nonce( $field, $action ) {
	$nonce = $_REQUEST[ $field ] ?? '';
	return is_string( $nonce ) && wp_verify_nonce( $nonce, $action );
}

/**
 * Rate-limit por IP. Devuelve true si está OK, false si excedió.
 */
function cead_acad_rate_limit( $action, $max = 5, $window = 60 ) {
	$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
	$key = 'cead_acad_rl_' . md5( $action . '|' . $ip );
	$count = (int) get_transient( $key );
	if ( $count >= $max ) {
		return false;
	}
	set_transient( $key, $count + 1, $window );
	return true;
}
