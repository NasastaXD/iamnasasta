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
	$allowed = [ 'invitations', 'audit_log', 'roster', 'audiences', 'broadcast_reads', 'survey_questions', 'survey_responses', 'survey_answers', 'grades', 'import_jobs', 'wa_session', 'wa_registry', 'wa_state', 'wa_messages', 'wa_reports', 'wa_suggestions', 'wa_scheduled', 'wa_logs' ];
	$name = in_array( $name, $allowed, true ) ? $name : 'invitations';
	return $wpdb->prefix . 'cead_acad_' . $name;
}

/**
 * ¿El usuario es "staff" que puede gestionar el backend del plugin?
 * Chequea por ROL directo (no por cap, que puede no haberse asignado) más
 * el administrator de WordPress. Robusto ante caps custom que no se instalaron.
 */
function cead_acad_user_is_staff( $user = null ) {
	$user = $user ? get_user_by( 'id', (int) $user ) : wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return false;
	}
	if ( user_can( $user, 'manage_options' ) ) {
		return true;
	}
	$staff_roles = [ 'cead_acad_direction', 'cead_acad_secretary' ];
	return (bool) array_intersect( (array) $user->roles, $staff_roles );
}

/**
 * Rol principal del usuario relevante para el plugin. Memoizado por user_id en el request.
 */
function cead_acad_user_role( $user = null ) {
	static $cache = [];
	$uid = $user ? (int) $user : get_current_user_id();
	if ( array_key_exists( $uid, $cache ) ) {
		return $cache[ $uid ];
	}
	$u = $uid ? get_user_by( 'id', $uid ) : null;
	if ( ! $u || ! $u->exists() ) {
		return $cache[ $uid ] = '';
	}
	foreach ( (array) $u->roles as $role ) {
		if ( str_starts_with( $role, 'cead_acad_' ) ) {
			return $cache[ $uid ] = $role;
		}
	}
	return $cache[ $uid ] = (string) ( $u->roles[0] ?? '' );
}

/**
 * Genera un token alfanumérico corto y criptográficamente seguro para invitaciones.
 * Base62, 12 caracteres (~71 bits). Las invitaciones son de un solo uso y expiran,
 * así que el link puede ser corto sin comprometer la seguridad.
 */
function cead_acad_generate_token() {
	$alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	$max      = strlen( $alphabet ) - 1;
	$out      = '';
	for ( $i = 0; $i < 12; $i++ ) {
		$out .= $alphabet[ random_int( 0, $max ) ];
	}
	return $out;
}

/**
 * Token-hash para almacenar en BD (no guardamos el token plano).
 */
function cead_acad_hash_token( $token ) {
	return hash( 'sha256', $token );
}

/**
 * Cursos disponibles para selects (id => título), ordenados por título.
 */
function cead_acad_courses_for_select() {
	$posts = get_posts( [
		'post_type'        => 'cead_acad_course',
		'posts_per_page'   => -1,
		'orderby'          => 'title',
		'order'            => 'ASC',
		'post_status'      => [ 'publish', 'private', 'draft' ],
		'no_found_rows'    => true,
		'suppress_filters' => false,
	] );
	$out = [];
	foreach ( $posts as $p ) {
		$out[ (int) $p->ID ] = $p->post_title !== '' ? $p->post_title : ( '#' . $p->ID );
	}
	return $out;
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
