<?php
/**
 * Helpers puros de includes/helpers.php: tokens, hash, hex, palabras prohibidas.
 */

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase {

	protected function tearDown(): void {
		cead_test_reset_options();
	}

	// ---- Tokens ----

	public function test_token_tiene_12_caracteres_base62(): void {
		$token = cead_acad_generate_token();
		$this->assertSame( 12, strlen( $token ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-zA-Z]{12}$/', $token );
	}

	public function test_tokens_no_se_repiten(): void {
		$seen = [];
		for ( $i = 0; $i < 200; $i++ ) {
			$seen[ cead_acad_generate_token() ] = true;
		}
		$this->assertCount( 200, $seen );
	}

	public function test_hash_token_es_sha256_estable(): void {
		$this->assertSame( hash( 'sha256', 'abc123' ), cead_acad_hash_token( 'abc123' ) );
		$this->assertSame( cead_acad_hash_token( 'x' ), cead_acad_hash_token( 'x' ) );
	}

	// ---- Hex ----

	public function test_sanitize_hex_acepta_3_y_6_digitos(): void {
		$this->assertSame( '#fff', cead_acad_sanitize_hex( '#fff' ) );
		$this->assertSame( '#A1B2C3', cead_acad_sanitize_hex( '#A1B2C3' ) );
		$this->assertSame( '#fff', cead_acad_sanitize_hex( '  #fff  ' ) );
	}

	public function test_sanitize_hex_rechaza_invalidos(): void {
		$this->assertSame( '', cead_acad_sanitize_hex( 'fff' ) );
		$this->assertSame( '', cead_acad_sanitize_hex( '#ggg' ) );
		$this->assertSame( '', cead_acad_sanitize_hex( '#ffff' ) );
		$this->assertSame( '', cead_acad_sanitize_hex( null ) );
		$this->assertSame( '', cead_acad_sanitize_hex( [ '#fff' ] ) );
	}

	// ---- Palabras prohibidas ----

	public function test_detecta_palabra_prohibida_por_palabra_completa(): void {
		$this->assertTrue( cead_acad_has_banned_words( 'sos un idiota' ) );
		// "idiotamente" no matchea porque el match es por palabra completa.
		$this->assertFalse( cead_acad_has_banned_words( 'idiotamente bueno' ) );
	}

	public function test_detecta_con_tildes_y_mayusculas(): void {
		$this->assertTrue( cead_acad_has_banned_words( 'IMBÉCIL' ) );
		$this->assertTrue( cead_acad_has_banned_words( 'qué estúpido' ) );
	}

	public function test_texto_limpio_pasa(): void {
		$this->assertFalse( cead_acad_has_banned_words( 'Juan Pérez, delegado de 3°B' ) );
		$this->assertFalse( cead_acad_has_banned_words( '' ) );
	}

	public function test_lista_configurable_por_opcion(): void {
		cead_test_set_option( 'cead_acad_banned_words', "spam\nestafa, timo" );
		$this->assertTrue( cead_acad_has_banned_words( 'esto es spam' ) );
		$this->assertTrue( cead_acad_has_banned_words( 'vaya TIMO' ) );
		// Con lista custom, la base por defecto deja de aplicar.
		$this->assertFalse( cead_acad_has_banned_words( 'sos un idiota' ) );
	}
}
