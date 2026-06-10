<?php
/**
 * Estado calculado de invitaciones (Cead_Acad_Invitations::status), lógica
 * pura sobre la fila: valid | used | expired | revoked | invalid.
 */

use PHPUnit\Framework\TestCase;

final class InvitationStatusTest extends TestCase {

	private function row( array $overrides = [] ): array {
		return array_merge( [
			'revoked_at' => null,
			'used_at'    => null,
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
		], $overrides );
	}

	public function test_fila_inexistente_es_invalid(): void {
		$this->assertSame( 'invalid', Cead_Acad_Invitations::status( null ) );
		$this->assertSame( 'invalid', Cead_Acad_Invitations::status( [] ) );
	}

	public function test_vigente_es_valid(): void {
		$this->assertSame( 'valid', Cead_Acad_Invitations::status( $this->row() ) );
	}

	public function test_usada_es_used(): void {
		$row = $this->row( [ 'used_at' => gmdate( 'Y-m-d H:i:s' ) ] );
		$this->assertSame( 'used', Cead_Acad_Invitations::status( $row ) );
	}

	public function test_vencida_es_expired(): void {
		$row = $this->row( [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
		$this->assertSame( 'expired', Cead_Acad_Invitations::status( $row ) );
	}

	public function test_revocada_gana_sobre_todo(): void {
		$row = $this->row( [
			'revoked_at' => gmdate( 'Y-m-d H:i:s' ),
			'used_at'    => gmdate( 'Y-m-d H:i:s' ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		] );
		$this->assertSame( 'revoked', Cead_Acad_Invitations::status( $row ) );
	}

	public function test_usada_gana_sobre_vencida(): void {
		$row = $this->row( [
			'used_at'    => gmdate( 'Y-m-d H:i:s' ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		] );
		$this->assertSame( 'used', Cead_Acad_Invitations::status( $row ) );
	}
}
