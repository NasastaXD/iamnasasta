<?php
/**
 * Detección de publicaciones nuevas de Instagram.
 *
 * La lectura en sí (API oficial o servicio externo) necesita red y queda fuera
 * de esta suite. Lo que se prueba acá es lo que decide QUÉ se avisa, que es
 * donde está el riesgo real: avisar de más le dispara mensajes al WhatsApp del
 * director, y avisar de menos hace que la función no sirva para nada.
 */

use PHPUnit\Framework\TestCase;

final class WaInstagramTest extends TestCase {

	private function post( $id ) {
		return [ 'id' => $id, 'caption' => 'texto ' . $id, 'url' => 'https://instagram.com/p/' . $id ];
	}

	/**
	 * El caso que importa: al activar la función por primera vez no se avisa
	 * NADA. Sin esto llegarían de golpe todas las publicaciones que la cuenta
	 * ya tenía, como si fueran nuevas.
	 */
	public function test_la_primera_corrida_no_avisa_nada(): void {
		$r = Cead_Acad_WA_Instagram::separar_nuevas(
			[ $this->post( 'c' ), $this->post( 'b' ), $this->post( 'a' ) ],
			[]
		);

		$this->assertTrue( $r['primera'] );
		$this->assertSame( [], $r['nuevas'], 'Estrenar la función no puede inundar al director.' );
		$this->assertCount( 3, $r['vistas'], 'Pero sí tiene que quedar anotado lo que ya existía.' );
	}

	public function test_una_publicacion_nueva_se_avisa_una_sola_vez(): void {
		$vistas = [ 'a', 'b' ];

		$r1 = Cead_Acad_WA_Instagram::separar_nuevas( [ $this->post( 'c' ), $this->post( 'b' ), $this->post( 'a' ) ], $vistas );
		$this->assertCount( 1, $r1['nuevas'] );
		$this->assertSame( 'c', $r1['nuevas'][0]['id'] );

		// Segunda pasada con lo mismo: ya no hay nada que avisar.
		$r2 = Cead_Acad_WA_Instagram::separar_nuevas( [ $this->post( 'c' ), $this->post( 'b' ), $this->post( 'a' ) ], $r1['vistas'] );
		$this->assertSame( [], $r2['nuevas'] );
	}

	/**
	 * Instagram devuelve de más nueva a más vieja. Si se avisara en ese orden,
	 * el director las leería al revés de como pasaron.
	 */
	public function test_las_nuevas_llegan_de_mas_vieja_a_mas_nueva(): void {
		$r = Cead_Acad_WA_Instagram::separar_nuevas(
			[ $this->post( 'nueva' ), $this->post( 'media' ), $this->post( 'vieja' ) ],
			[ 'ancla' ]
		);

		$this->assertSame( [ 'vieja', 'media', 'nueva' ], array_column( $r['nuevas'], 'id' ) );
	}

	public function test_una_publicacion_sin_id_se_ignora(): void {
		$r = Cead_Acad_WA_Instagram::separar_nuevas(
			[ [ 'caption' => 'sin id' ], $this->post( 'ok' ) ],
			[ 'ancla' ]
		);

		$this->assertSame( [ 'ok' ], array_column( $r['nuevas'], 'id' ) );
	}

	/** La lista de vistas no puede crecer para siempre dentro de una opción. */
	public function test_la_memoria_de_vistas_tiene_tope(): void {
		$muchos = [];
		for ( $i = 0; $i < 200; $i++ ) { $muchos[] = $this->post( 'p' . $i ); }

		$r = Cead_Acad_WA_Instagram::separar_nuevas( $muchos, [ 'ancla' ] );

		$this->assertLessThanOrEqual( 60, count( $r['vistas'] ) );
		// Y lo que se conserva es lo último, no lo primero.
		$this->assertContains( 'p199', $r['vistas'] );
	}

	public function test_sin_configurar_el_modo_esta_apagado(): void {
		cead_test_reset_options();
		$this->assertSame( 'off', Cead_Acad_WA_Instagram::modo() );
	}

	public function test_un_modo_invalido_cae_en_apagado(): void {
		cead_test_set_option( 'cead_acad_wa_ig_modo', 'raspar_a_lo_bruto' );
		$this->assertSame( 'off', Cead_Acad_WA_Instagram::modo() );
		cead_test_reset_options();
	}
}
