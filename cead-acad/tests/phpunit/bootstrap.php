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

// Formatos de salida de $wpdb.
defined( 'OBJECT' )   || define( 'OBJECT', 'OBJECT' );
defined( 'ARRAY_A' )  || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' )  || define( 'ARRAY_N', 'ARRAY_N' );

// ---- Store de opciones controlable desde los tests ----
$GLOBALS['cead_test_options'] = [];

function cead_test_set_option( $key, $value ) {
	$GLOBALS['cead_test_options'][ $key ] = $value;
}

function cead_test_reset_options() {
	$GLOBALS['cead_test_options']    = [];
	$GLOBALS['cead_test_transients'] = [];
}

// ---- Store de user meta controlable desde los tests ----
$GLOBALS['cead_test_usermeta'] = []; // user_id => [ key => value ]

function cead_test_reset_usermeta() {
	$GLOBALS['cead_test_usermeta'] = [];
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key = '', $single = false ) {
		if ( '' === $key ) {
			return $GLOBALS['cead_test_usermeta'][ $user_id ] ?? [];
		}
		$value = $GLOBALS['cead_test_usermeta'][ $user_id ][ $key ] ?? '';
		return $single ? $value : [ $value ];
	}
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['cead_test_usermeta'][ $user_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( $user_id, $key ) {
		unset( $GLOBALS['cead_test_usermeta'][ $user_id ][ $key ] );
		return true;
	}
}

// ---- Store de post meta controlable desde los tests ----
$GLOBALS['cead_test_postmeta'] = []; // post_id => [ key => value ]

function cead_test_reset_postmeta() {
	$GLOBALS['cead_test_postmeta'] = [];
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		if ( '' === $key ) {
			return $GLOBALS['cead_test_postmeta'][ $post_id ] ?? [];
		}
		$value = $GLOBALS['cead_test_postmeta'][ $post_id ][ $key ] ?? '';
		return $single ? $value : [ $value ];
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['cead_test_postmeta'][ $post_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key ) {
		unset( $GLOBALS['cead_test_postmeta'][ $post_id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'sanitize_hex_color' ) ) {
	function sanitize_hex_color( $color ) {
		$color = trim( (string) $color );
		if ( '' === $color ) { return ''; }
		if ( preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $color ) ) { return $color; }
		return null;
	}
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
if ( ! function_exists( 'remove_accents' ) ) {
	function remove_accents( $string ) {
		$mapa = [
			'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
			'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
		];
		return strtr( (string) $string, $mapa );
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
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

/*
 * Huso horario del "sitio", controlable desde los tests. WordPress no expone la
 * hora local con date(): la expone desplazando el timestamp, y esa distinción
 * es justo lo que hay que poder probar.
 */
$GLOBALS['cead_test_gmt_offset'] = 0;

function cead_test_set_gmt_offset( $horas ) {
	$GLOBALS['cead_test_gmt_offset'] = (float) $horas;
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Réplica del contrato de current_time(): con $gmt devuelve UTC; sin él,
	 * devuelve el timestamp YA desplazado al huso del sitio (no una conversión
	 * de zona horaria real, que es exactamente cómo se comporta WordPress).
	 */
	function current_time( $type, $gmt = 0 ) {
		$ts = time() + ( $gmt ? 0 : (int) round( $GLOBALS['cead_test_gmt_offset'] * HOUR_IN_SECONDS ) );
		if ( 'timestamp' === $type || 'U' === $type ) { return $ts; }
		if ( 'mysql' === $type ) { return gmdate( 'Y-m-d H:i:s', $ts ); }
		return gmdate( $type, $ts );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { return 'https://cead.test' . $path; }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) { return substr( md5( 'nonce|' . $action ), 0, 10 ); }
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( $actionurl, $action = -1, $name = '_wpnonce' ) {
		$sep = false === strpos( $actionurl, '?' ) ? '?' : '&';
		return $actionurl . $sep . rawurlencode( $name ) . '=' . rawurlencode( wp_create_nonce( $action ) );
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return hash_equals( wp_create_nonce( $action ), (string) $nonce ) ? 1 : false;
	}
}

/**
 * $wpdb de mentira, lo mínimo para probar escrituras que pueden fallar.
 *
 * Reproduce el detalle que importa: `insert_id` NO se limpia cuando el insert
 * falla, así que conserva el id del insert anterior de la misma request. Ese es
 * el valor que se colaba como "salió bien".
 */
class Cead_Test_WPDB {
	public $prefix    = 'wptest_';
	public $insert_id = 0;
	public $last_error = '';

	/** Qué devolver en el próximo insert/update: false simula error de BD. */
	public $insert_result = 1;
	public $update_result = 1;

	/** Fila que devuelve get_row (null = no existe). */
	public $row = null;

	/** Registro de lo que se intentó escribir. */
	public $writes = [];

	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$query = str_replace( [ '%d', '%s', '%f' ], '%s', $query );
		return vsprintf( $query, array_map( static function ( $a ) {
			return is_numeric( $a ) ? $a : "'" . $a . "'";
		}, $args ) );
	}

	public function get_row( $query, $output = null ) { return $this->row; }
	public function get_col( $query ) { return []; }
	public function get_var( $query ) { return 0; }
	public function query( $query ) { return 1; }

	public function insert( $table, $data, $format = null ) {
		$this->writes[] = [ 'insert', $table, $data ];
		if ( false === $this->insert_result ) {
			$this->last_error = 'simulado: el insert falló';
			return false; // insert_id queda como estaba: ese es el punto.
		}
		$this->insert_id = 4242;
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->writes[] = [ 'update', $table, $data, $where ];
		if ( false === $this->update_result ) {
			$this->last_error = 'simulado: el update falló';
			return false;
		}
		return $this->update_result;
	}

	public function replace( $table, $data, $format = null ) {
		$this->writes[] = [ 'replace', $table, $data ];
		return 1;
	}
}

/*
 * Store de términos de taxonomía (`cead_acad_subject`), en memoria: crear,
 * buscar por nombre, marcar con meta, contar y borrar — sin necesitar
 * WordPress real.
 */
$GLOBALS['cead_test_terms']        = []; // term_id => [ 'name', 'taxonomy', 'count' ]
$GLOBALS['cead_test_term_meta']    = []; // term_id => [ key => value ]
$GLOBALS['cead_test_next_term_id'] = 1;

function cead_test_reset_terms() {
	$GLOBALS['cead_test_terms']        = [];
	$GLOBALS['cead_test_term_meta']    = [];
	$GLOBALS['cead_test_next_term_id'] = 1;
}

if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy = '' ) {
		foreach ( $GLOBALS['cead_test_terms'] as $term_id => $term ) {
			if ( $taxonomy && $term['taxonomy'] !== $taxonomy ) { continue; }
			if ( 'name' === $field && $term['name'] === $value ) {
				return (object) [ 'term_id' => $term_id, 'name' => $term['name'], 'taxonomy' => $term['taxonomy'], 'count' => $term['count'] ];
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( $name, $taxonomy, $args = [] ) {
		foreach ( $GLOBALS['cead_test_terms'] as $term ) {
			if ( $term['taxonomy'] === $taxonomy && $term['name'] === $name ) {
				return new WP_Error( 'term_exists', 'El término ya existe' );
			}
		}
		$term_id = $GLOBALS['cead_test_next_term_id']++;
		$GLOBALS['cead_test_terms'][ $term_id ] = [
			'name'     => $name,
			'taxonomy' => $taxonomy,
			'count'    => 0,
		];
		return [ 'term_id' => $term_id, 'term_taxonomy_id' => $term_id ];
	}
}

if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $key, $value ) {
		$GLOBALS['cead_test_term_meta'][ $term_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key = '', $single = false ) {
		if ( '' === $key ) {
			return $GLOBALS['cead_test_term_meta'][ $term_id ] ?? [];
		}
		$value = $GLOBALS['cead_test_term_meta'][ $term_id ][ $key ] ?? '';
		return $single ? $value : [ $value ];
	}
}

if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		if ( ! isset( $GLOBALS['cead_test_terms'][ $term_id ] ) ) { return null; }
		$term = $GLOBALS['cead_test_terms'][ $term_id ];
		return (object) [ 'term_id' => $term_id, 'name' => $term['name'], 'taxonomy' => $term['taxonomy'], 'count' => $term['count'] ];
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args = [] ) {
		$taxonomy = $args['taxonomy'] ?? '';
		$meta_key = $args['meta_key'] ?? '';
		$fields   = $args['fields'] ?? 'all';
		$out      = [];
		foreach ( $GLOBALS['cead_test_terms'] as $term_id => $term ) {
			if ( $taxonomy && $term['taxonomy'] !== $taxonomy ) { continue; }
			if ( $meta_key && empty( $GLOBALS['cead_test_term_meta'][ $term_id ][ $meta_key ] ) ) { continue; }
			$out[] = 'ids' === $fields ? $term_id : (object) [ 'term_id' => $term_id, 'name' => $term['name'], 'taxonomy' => $term['taxonomy'], 'count' => $term['count'] ];
		}
		return $out;
	}
}

if ( ! function_exists( 'wp_delete_term' ) ) {
	function wp_delete_term( $term_id, $taxonomy = '' ) {
		unset( $GLOBALS['cead_test_terms'][ $term_id ] );
		unset( $GLOBALS['cead_test_term_meta'][ $term_id ] );
		return true;
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
		foreach ( (array) $terms as $t ) {
			if ( isset( $GLOBALS['cead_test_terms'][ $t ] ) ) {
				$GLOBALS['cead_test_terms'][ $t ]['count']++;
			}
		}
		return (array) $terms;
	}
}

// ---- Código bajo test ----
require_once dirname( __DIR__, 2 ) . '/includes/helpers.php';
require_once dirname( __DIR__, 2 ) . '/modules/courses/class-courses-roster.php';
require_once dirname( __DIR__, 2 ) . '/modules/courses/class-courses-admin.php';
require_once dirname( __DIR__, 2 ) . '/admin/class-admin-menu.php';
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-wa-identity.php';
require_once dirname( __DIR__, 2 ) . '/modules/auth/class-invitations.php';
require_once dirname( __DIR__, 2 ) . '/modules/broadcasts/class-broadcasts-audiences.php';
require_once dirname( __DIR__, 2 ) . '/modules/importers/class-importer-csv-reader.php';
require_once dirname( __DIR__, 2 ) . '/modules/importers/class-importer-base.php';
require_once dirname( __DIR__, 2 ) . '/modules/schedule/class-schedule-cpt.php';
require_once dirname( __DIR__, 2 ) . '/modules/importers/class-importer-events.php';
require_once dirname( __DIR__, 2 ) . '/modules/importers/class-importer-horarios.php';
require_once dirname( __DIR__, 2 ) . '/modules/importers/class-importer-admin.php';
require_once dirname( __DIR__, 2 ) . '/modules/grades/class-grades-writer.php';
require_once dirname( __DIR__, 2 ) . '/modules/grades/class-grades-sheet.php';
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-wa-ai.php';
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-wa-docs.php';
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-wa-memory.php';
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-wa-news.php';
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-article-format.php';
require_once dirname( __DIR__, 2 ) . '/modules/whatsapp/class-wa-engine.php';
