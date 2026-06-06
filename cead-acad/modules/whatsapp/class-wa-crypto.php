<?php
/**
 * Cifrado de contenido sensible (reportes). libsodium con fallback AES-256-GCM.
 * Clave: constante CEAD_ACAD_WA_KEY (base64 de 32 bytes) u opción autogenerada.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Crypto {

	private static function key() {
		if ( defined( 'CEAD_ACAD_WA_KEY' ) && CEAD_ACAD_WA_KEY ) {
			$k = base64_decode( (string) CEAD_ACAD_WA_KEY, true );
			if ( $k !== false && strlen( $k ) === 32 ) {
				return $k;
			}
		}
		$stored = get_option( 'cead_acad_wa_crypto_key', '' );
		$k = $stored ? base64_decode( (string) $stored, true ) : false;
		if ( $k === false || strlen( $k ) !== 32 ) {
			$k = self::random32();
			update_option( 'cead_acad_wa_crypto_key', base64_encode( $k ), false );
		}
		return $k;
	}

	public static function random32() {
		try {
			return random_bytes( 32 );
		} catch ( \Throwable $e ) {
			return hash( 'sha256', wp_generate_password( 64, true, true ) . microtime(), true );
		}
	}

	public static function encrypt( $plaintext ) {
		$key = self::key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( (string) $plaintext, $nonce, $key );
			return 'v1:' . base64_encode( $nonce . $cipher );
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$cipher = openssl_encrypt( (string) $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return 'v2:' . base64_encode( $iv . $tag . $cipher );
	}

	public static function decrypt( $blob ) {
		$key  = self::key();
		$blob = (string) $blob;
		if ( str_starts_with( $blob, 'v1:' ) ) {
			$raw = base64_decode( substr( $blob, 3 ), true );
			if ( $raw === false || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return null;
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			return $plain === false ? null : $plain;
		}
		if ( str_starts_with( $blob, 'v2:' ) ) {
			$raw = base64_decode( substr( $blob, 3 ), true );
			if ( $raw === false ) {
				return null;
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return $plain === false ? null : $plain;
		}
		return null;
	}
}
