<?php
/**
 * Bootstrap de PHPUnit SIN WordPress: define ABSPATH y stubs mínimos de las
 * funciones de WP que la lógica pura toca en runtime (get_option, i18n).
 *
 * Solo se testea lógica pura (normalización, tokens, parsers, estados); todo
 * lo que necesita BD/hooks reales queda fuera de esta suite.
 */

define( 'ABSPATH', sys_get_temp_dir() . '/' );

// Constantes de tiempo de WordPress: algunas clases las usan al definirse.
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' )   || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' )    || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WEEK_IN_SECONDS' )   || define( 'WEEK_IN_SECONDS', 604800 );

// ---- Store de opciones controlable desde los tests ----
$GLOBALS['cead_test_options'] = [];

function cead_test_set_option( $key, $value ) {
	$GLOBALS['cead_test_options'][ $key ] = $value;
}

function cead_test_reset_options() {
	$GLOBALS['cead_test_options']    = [];
	$GLOBALS['cead_test_transients'] = [];
}

// ---- Stubs de WordPress ----
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['cead_test_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['cead_test_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( $GLOBALS['cead_test_options'][ $key ] );
		return true;
	}
}
// Transients: mismo store que las opciones, sin vencimiento. Alcanza para la
// lógica pura; nada de lo que se testea depende de que expiren.
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['cead_test_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['cead_test_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['cead_test_transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( '_e' ) ) {
	function _e( $text, $domain = 'default' ) { echo $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { return htmlspecialchars( $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) { return trim( strip_tags( (string) $str ) ); }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$t = strtolower( trim( (string) $title ) );
		$t = strtr( $t, [ 'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u' ] );
		$t = preg_replace( '/[^a-z0-9]+/', '-', $t );
		return trim( $t, '-' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return filter_var( (string) $url, FILTER_SANITIZE_URL ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		$text = strip_tags( $text );
		if ( $remove_breaks ) { $text = preg_replace( '/[\r\n\t ]+/', ' ', $text ); }
		return trim( $text );
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals ); }
}
if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $prefix = '' ) { return tempnam( sys_get_temp_dir(), (string) $prefix ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		protected $code;
		protected $message;
		protected $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

// ---- Código bajo test ----
require_once dirname( __DIR__, 2 ) . '/includes/helpers.php';
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-wa-identity.php';
require_once dirname( __DIR__, 2 ) . '/modules/auth/class-invitations.php';
require_once dirname( __DIR__, 2 ) . '/modules/broadcasts/class-broadcasts-audiences.php';
require_once dirname( __DIR__, 2 ) . '/modules/importers/class-importer-csv-reader.php';
require_once dirname( __DIR__, 2 ) . '/modules/grades/class-grades-writer.php';
require_once dirname( __DIR__, 2 ) . '/modules/grades/class-grades-sheet.php';
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-wa-ai.php';
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-wa-docs.php';
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-wa-memory.php';
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-wa-news.php';
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-article-format.php';
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-wa-engine.php';
