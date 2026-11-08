<?php
use PHPUnit\Framework\TestCase;

/** Spec 10: los secretos se leen primero de wp-config.php. */
class SecretoTest extends TestCase {
	public function test_constante_manda_sobre_la_opcion() {
		cead_test_reset_options();
		cead_test_set_option( 'cead_acad_prueba_key', 'de-la-base' );
		$this->assertSame( 'de-la-base', cead_acad_secreto( 'CEAD_ACAD_PRUEBA_KEY_NO_DEFINIDA', 'cead_acad_prueba_key' ) );
		define( 'CEAD_ACAD_PRUEBA_KEY', 'de-wp-config' );
		$this->assertSame( 'de-wp-config', cead_acad_secreto( 'CEAD_ACAD_PRUEBA_KEY', 'cead_acad_prueba_key' ) );
		$this->assertSame( '', cead_acad_secreto( 'NADA', 'cead_acad_no_existe' ) );
	}
}
