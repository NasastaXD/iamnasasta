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
	if ( ! in_array( $name, $allowed, true ) ) {
		// No devolver silenciosamente otra tabla real (antes: 'invitations'):
		// un nombre mal tipeado tiene que romper fuerte y visible, no leer o
		// escribir en la tabla equivocada sin que nadie se entere.
		trigger_error( 'cead_acad_table(): nombre de tabla desconocido: ' . (string) $name, E_USER_WARNING );
		return $wpdb->prefix . 'cead_acad_invalid_table_name';
	}
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

/** Acción del nonce que protege el cierre de sesión. */
const CEAD_ACAD_LOGOUT_ACTION = 'cead_acad_logout';

/**
 * URL para cerrar sesión, con su nonce.
 *
 * Cerrar sesión con un GET pelado significa que CUALQUIER cosa que precargue
 * enlaces te desloguea: el prefetch del navegador, un escáner de links de
 * antivirus, o la previsualización de WhatsApp cuando alguien comparte una URL
 * del panel. Con el nonce, esas visitas automáticas no cierran nada.
 *
 * Se centraliza acá para que ninguna plantilla arme la URL a mano y se olvide
 * del nonce.
 */
function cead_acad_logout_url() {
	return wp_nonce_url( cead_acad_url( 'salir' ), CEAD_ACAD_LOGOUT_ACTION, 'cead_nonce' );
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
 * Lista de palabras prohibidas (configurable en CEAD Académico → WhatsApp).
 * Si está vacía, usa una base por defecto.
 *
 * @return string[]
 */
function cead_acad_banned_words_list() {
	$raw = (string) get_option( 'cead_acad_banned_words', '' );
	$list = preg_split( '/[\r\n,]+/', $raw );
	$list = array_values( array_filter( array_map( 'trim', (array) $list ), static function ( $w ) { return $w !== ''; } ) );
	if ( ! $list ) {
		// Base mínima (editable desde el panel del bot). Sin tildes para el match.
		$list = [ 'puto', 'puta', 'pija', 'concha', 'mierda', 'verga', 'carajo', 'idiota', 'estupido', 'imbecil', 'pelotudo', 'forro', 'cagar', 'culo', 'tarado' ];
	}
	return $list;
}

/**
 * ¿El texto contiene alguna palabra prohibida? (match por palabra, sin tildes,
 * sin distinción de mayúsculas). Reutilizable en panel, formularios y usuarios.
 */
function cead_acad_has_banned_words( $text ) {
	$text = (string) $text;
	if ( $text === '' ) { return false; }
	// Quitar tildes (ambas cajas: strtolower() no baja multibyte como "É")
	// para que "imbécil"/"IMBÉCIL" matcheen "imbecil".
	$accents = [ 'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ü'=>'u' ];
	$norm = strtolower( strtr( $text, $accents ) );
	foreach ( cead_acad_banned_words_list() as $w ) {
		$wn = strtolower( strtr( $w, $accents ) );
		if ( $wn === '' ) { continue; }
		if ( preg_match( '/\b' . preg_quote( $wn, '/' ) . '\b/u', $norm ) ) {
			return true;
		}
	}
	return false;
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

/**
 * Un enlace de WhatsApp, o cadena vacía si no hay número que valga.
 *
 * Sin argumentos devuelve el de CEADI: el número lo carga la dirección en
 * wp-admin → WhatsApp (`cead_acad_wa_bot_number`) y se guarda como dígitos,
 * pero nadie garantiza que quede limpio — alguien lo pega como
 * «+595 991 123-456» y `wa.me` con espacios no abre nada.
 *
 * Con un número, sirve para escribirle a cualquiera (la sección de delegados).
 * Ahí la limpieza no alcanza: los teléfonos de la gente se cargan en formato
 * local («0991 123 456»), y `wa.me/0991123456` no existe — hace falta el
 * código de país. Eso ya lo resuelve `Cead_Acad_WA_Identity::normalize_phone()`,
 * que es la misma pieza con la que el bot reconoce a quien le escribe: usar
 * otra sería tener dos ideas distintas de cuál es el número de una persona.
 *
 * Devolver cadena vacía es parte del contrato: quien llama decide qué hacer sin
 * número —esconder el botón, abrir el chat directo— en vez de imprimir un
 * enlace a `https://wa.me/` que no lleva a ningún lado.
 *
 * @param string|null $numero Teléfono a contactar. Null = el de CEADI.
 */
function cead_acad_wa_link( $numero = null ) {
	if ( null === $numero ) {
		$numero = (string) get_option( 'cead_acad_wa_bot_number', '' );
		// El del bot se carga ya en formato internacional, así que solo se limpia.
		$digitos = preg_replace( '/[^0-9]/', '', $numero );
		return '' === $digitos ? '' : 'https://wa.me/' . $digitos;
	}

	$digitos = class_exists( 'Cead_Acad_WA_Identity' )
		? Cead_Acad_WA_Identity::normalize_phone( $numero )
		: preg_replace( '/[^0-9]/', '', (string) $numero );

	return '' === $digitos ? '' : 'https://wa.me/' . $digitos;
}

/**
 * ¿Está activo el carné digital?
 *
 * Apagado de fábrica. El carné funciona, pero hoy no lo usa nadie: nadie escanea
 * el QR en la puerta y no hay acuerdo de que ese sea el documento del colegio.
 * Una entrada de menú que no lleva a nada que sirva le cuesta atención a cada
 * alumno que la prueba, y encima invita a confundirlo con un carné oficial.
 * Cuando el colegio decida usarlo, se prende acá y aparece completo.
 *
 * Es una función y no un `get_option()` suelto porque son SEIS los lugares que
 * muestran el carné —menú lateral, barra superior, atajos del inicio, perfil,
 * la ruta del panel y la verificación pública— y con la pregunta escrita seis
 * veces alcanza con que alguien agregue un séptimo lugar para que la función
 * quede medio apagada: visible por un lado, 404 por el otro.
 */
function cead_acad_carne_activo() {
	return (bool) get_option( 'cead_acad_carne_enabled', false );
}
