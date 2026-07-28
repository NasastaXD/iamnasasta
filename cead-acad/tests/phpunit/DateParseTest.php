<?php
/**
 * Interpretación de fechas escritas a mano (CEADI).
 *
 * Importa por dos motivos: el flujo manual de eventos depende del formato
 * estricto de siempre, y la IA promete entender lenguaje natural.
 */

use PHPUnit\Framework\TestCase;

class DateParseTest extends TestCase {

	/** Martes 28 de julio de 2026, 09:41. */
	private function now() {
		return mktime( 9, 41, 0, 7, 28, 2026 );
	}

	private function parse( $input ) {
		return Cead_Acad_WA_Engine::parse_human_datetime( $input, $this->now() );
	}

	/** El formato de los menús y del cron no se puede romper. */
	public function test_formato_estricto_sigue_funcionando() {
		$this->assertSame( '2026-08-05 14:30:00', $this->parse( '2026-08-05 14:30' ) );
		$this->assertSame( '2026-08-05 14:30:00', $this->parse( '2026-08-05 14:30:00' ) );
	}

	public function test_dias_relativos() {
		$this->assertSame( '2026-07-28 15:00:00', $this->parse( 'hoy 15:00' ) );
		$this->assertSame( '2026-07-29 10:00:00', $this->parse( 'mañana 10:00' ) );
		$this->assertSame( '2026-07-29 10:00:00', $this->parse( 'manana 10:00' ) );
		$this->assertSame( '2026-07-30 08:00:00', $this->parse( 'pasado mañana a las 8' ) );
	}

	/** Formato local: 5/8 es 5 de agosto, no 8 de mayo. */
	public function test_fecha_en_formato_dia_mes() {
		$this->assertSame( '2026-08-05 14:30:00', $this->parse( '5/8 14:30' ) );
		$this->assertSame( '2026-08-05 08:00:00', $this->parse( '05-08-2026' ) );
		$this->assertSame( '2026-09-12 09:00:00', $this->parse( '12/09/26 9hs' ) );
	}

	public function test_dia_de_la_semana() {
		$this->assertSame( '2026-07-31 09:00:00', $this->parse( 'el viernes a las 9' ) );
		$this->assertSame( '2026-07-30 16:00:00', $this->parse( 'reunión el jueves 16:00' ) );
		// Si nombran el día de hoy, se entiende la semana que viene.
		$this->assertSame( '2026-08-04 10:00:00', $this->parse( 'martes 10:00' ) );
	}

	public function test_horas_en_distintos_formatos() {
		$this->assertSame( '2026-07-29 14:30:00', $this->parse( 'mañana 2:30 pm' ) );
		$this->assertSame( '2026-07-28 19:00:00', $this->parse( 'hoy 7pm' ) );
	}

	/** Sólo hora: hoy si todavía no pasó, mañana si ya pasó. */
	public function test_solo_hora_elige_el_dia() {
		$this->assertSame( '2026-07-28 18:00:00', $this->parse( 'a las 18' ) );
		$this->assertSame( '2026-07-29 08:00:00', $this->parse( 'a las 8' ) );
	}

	/** Una fecha sin año que ya pasó se entiende como del año que viene. */
	public function test_fecha_pasada_sin_anio_rueda_al_siguiente() {
		$this->assertSame( '2027-07-05 08:00:00', $this->parse( '5/7' ) );
	}

	/** Ante la duda no inventa: mejor repreguntar que agendar cualquier cosa. */
	public function test_rechaza_lo_que_no_entiende() {
		$this->assertNull( $this->parse( '' ) );
		$this->assertNull( $this->parse( 'cuando puedas' ) );
		$this->assertNull( $this->parse( '31/02/2026' ) );
		$this->assertNull( $this->parse( 'hoy 99:99' ) );
		$this->assertNull( $this->parse( 'hoy 25:00' ) );
	}
}
