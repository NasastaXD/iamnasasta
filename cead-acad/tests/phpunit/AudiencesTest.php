<?php
/**
 * Validación de tipos de audiencia polimórfica (Cead_Acad_Audiences).
 */

use PHPUnit\Framework\TestCase;

final class AudiencesTest extends TestCase {

	public function test_tipos_validos_pasan(): void {
		foreach ( [ 'all', 'role', 'course', 'cohort', 'user' ] as $type ) {
			$this->assertSame( $type, Cead_Acad_Audiences::sanitize_type( $type ) );
		}
	}

	public function test_tipos_invalidos_devuelven_vacio(): void {
		$this->assertSame( '', Cead_Acad_Audiences::sanitize_type( 'ALL' ) );
		$this->assertSame( '', Cead_Acad_Audiences::sanitize_type( 'group' ) );
		$this->assertSame( '', Cead_Acad_Audiences::sanitize_type( '' ) );
		$this->assertSame( '', Cead_Acad_Audiences::sanitize_type( 'role; DROP TABLE x' ) );
	}

	public function test_constantes_coinciden_con_los_slugs(): void {
		$this->assertSame( 'all', Cead_Acad_Audiences::TYPE_ALL );
		$this->assertSame( 'role', Cead_Acad_Audiences::TYPE_ROLE );
		$this->assertSame( 'course', Cead_Acad_Audiences::TYPE_COURSE );
		$this->assertSame( 'cohort', Cead_Acad_Audiences::TYPE_COHORT );
		$this->assertSame( 'user', Cead_Acad_Audiences::TYPE_USER );
	}
}
