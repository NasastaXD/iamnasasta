<?php
/**
 * Cuándo corresponde repetir la explicación de cómo hablarle al bot.
 *
 * A quien lo usa todos los días no hay que recordarle en cada saludo que
 * escriba lo que necesita; a quien recién arranca, o volvió después de varios
 * días, sí.
 */

use PHPUnit\Framework\TestCase;

final class IntroThrottleTest extends TestCase {

	/** Hora "actual" fija, para que los tests no dependan del reloj. */
	private const AHORA = '2026-08-07 15:00:00';

	private function now() {
		return strtotime( self::AHORA );
	}

	/** Hace $dias que no escribe. */
	private function hace_dias( $dias ) {
		return date( 'Y-m-d H:i:s', $this->now() - (int) ( $dias * DAY_IN_SECONDS ) );
	}

	/** Número nuevo: nunca escribió. */
	public function test_numero_nuevo_recibe_la_explicacion() {
		$this->assertTrue( Cead_Acad_WA_Engine::needs_intro( 0, '', $this->now() ) );
	}

	/** Las primeras veces sí, para agarrarle la mano. */
	public function test_las_primeras_veces_si() {
		$ayer = $this->hace_dias( 1 );
		$this->assertTrue( Cead_Acad_WA_Engine::needs_intro( 1, $ayer, $this->now() ) );
		$this->assertTrue( Cead_Acad_WA_Engine::needs_intro( 2, $ayer, $this->now() ) );
	}

	/** A partir del cuarto mensaje ya no, si viene usándolo seguido. */
	public function test_despues_de_las_primeras_veces_no() {
		$hoy = date( 'Y-m-d H:i:s', $this->now() - 600 );
		$this->assertFalse( Cead_Acad_WA_Engine::needs_intro( 3, $hoy, $this->now() ) );
		$this->assertFalse( Cead_Acad_WA_Engine::needs_intro( 50, $hoy, $this->now() ) );
	}

	/** Un día sin escribir no alcanza para repetirla. */
	public function test_un_dia_de_silencio_no_alcanza() {
		$this->assertFalse( Cead_Acad_WA_Engine::needs_intro( 20, $this->hace_dias( 1 ), $this->now() ) );
	}

	/** Dos días o más, sí: ahí ya no se acuerda. */
	public function test_dos_dias_de_silencio_la_repite() {
		$this->assertTrue( Cead_Acad_WA_Engine::needs_intro( 20, $this->hace_dias( 2 ), $this->now() ) );
		$this->assertTrue( Cead_Acad_WA_Engine::needs_intro( 20, $this->hace_dias( 9 ), $this->now() ) );
	}

	/** Justo en el límite de los dos días cuenta como "hace mucho". */
	public function test_el_limite_exacto_cuenta() {
		$justo = date( 'Y-m-d H:i:s', $this->now() - DAY_IN_SECONDS * 2 );
		$this->assertTrue( Cead_Acad_WA_Engine::needs_intro( 20, $justo, $this->now() ) );

		$un_segundo_antes = date( 'Y-m-d H:i:s', $this->now() - ( DAY_IN_SECONDS * 2 ) + 1 );
		$this->assertFalse( Cead_Acad_WA_Engine::needs_intro( 20, $un_segundo_antes, $this->now() ) );
	}

	/** Una fecha rota no puede dejar a alguien sin la explicación. */
	public function test_fecha_invalida_muestra_la_explicacion() {
		$this->assertTrue( Cead_Acad_WA_Engine::needs_intro( 20, '0000-00-00 00:00:00', $this->now() ) );
		$this->assertTrue( Cead_Acad_WA_Engine::needs_intro( 20, 'cualquier cosa', $this->now() ) );
		$this->assertTrue( Cead_Acad_WA_Engine::needs_intro( 20, '', $this->now() ) );
	}
}
