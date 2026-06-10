<?php
/**
 * Normalización de teléfonos a E.164 (Cead_Acad_WA_Identity::normalize_phone).
 * Cubre el fix de seguridad: el match debe ser por número normalizado exacto,
 * no por subcadena (evita identidad/permisos equivocados).
 */

use PHPUnit\Framework\TestCase;

final class PhoneNormalizationTest extends TestCase {

	private function n( string $raw, string $cc = '595' ): string {
		return Cead_Acad_WA_Identity::normalize_phone( $raw, $cc );
	}

	public function test_formatos_equivalentes_del_mismo_numero(): void {
		$this->assertSame( '595981123456', $this->n( '595981123456' ), 'con código de país' );
		$this->assertSame( '595981123456', $this->n( '0981123456' ), 'local con 0' );
		$this->assertSame( '595981123456', $this->n( '981123456' ), 'local sin 0' );
		$this->assertSame( '595981123456', $this->n( '+595 981 123-456' ), 'con +, espacios y guiones' );
		$this->assertSame( '595981123456', $this->n( '00595981123456' ), 'prefijo internacional 00' );
	}

	public function test_entradas_invalidas_devuelven_vacio(): void {
		$this->assertSame( '', $this->n( '' ) );
		$this->assertSame( '', $this->n( '+-  ' ) );
	}

	public function test_numeros_distintos_no_colisionan(): void {
		$this->assertNotSame( $this->n( '0981123456' ), $this->n( '0981123457' ) );
	}

	public function test_codigo_de_pais_configurable(): void {
		$this->assertSame( '54111234567', $this->n( '0111234567', '54' ) );
	}

	public function test_codigo_de_pais_por_opcion_de_wp(): void {
		cead_test_set_option( 'cead_acad_wa_country_code', '54' );
		$this->assertSame( '54981123456', Cead_Acad_WA_Identity::normalize_phone( '0981123456' ) );
		cead_test_reset_options();
	}

	public function test_cc_invalido_cae_al_default(): void {
		$this->assertSame( '595981123456', Cead_Acad_WA_Identity::normalize_phone( '0981123456', 'abc' ) );
	}
}
