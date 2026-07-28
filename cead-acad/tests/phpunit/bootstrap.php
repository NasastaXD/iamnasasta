<?php
/**
 * Bootstrap de PHPUnit SIN WordPress: define ABSPATH y stubs mínimos de las
 * funciones de WP que la lógica pura toca en runtime (get_option, i18n).
 *
 * Solo se testea lógica pura (normalización, tokens, parsers, estados); todo
 * lo que necesita BD/hooks reales queda fuera de esta suite.
 */

define( 'ABSPATH', sys_get_temp_dir() . '/' );

// ---- Store de opciones controlable desde los tests ----
$GLOBALS['cead_test_options'] = [];

function cead_test_set_option( $key, $value ) {
	$GLOBALS['cead_test_options'][ $key ] = $value;
}

function cead_test_reset_options() {
	$GLOBALS['cead_test_options'] = [];
}

// ---- Stubs de WordPress ----
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['cead_test_options'][ $key ] ?? $default;
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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		protected $code;
		protected $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
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
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-wa-engine.php';
