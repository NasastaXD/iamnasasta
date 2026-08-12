<?php
/**
 * Cómo se reparte un evento por los días de la grilla del calendario.
 *
 * Es la pieza de la que depende que un período largo —vacaciones, dos semanas
 * de exámenes— se vea como UNA banda que cruza la semana y no como catorce
 * rectángulos repetidos. Que sepa dónde empieza y dónde termina es lo que
 * permite dibujar los topes; que se ordene igual todos los días es lo que
 * mantiene la banda a la misma altura de lunes a domingo.
 *
 * Vive en el plugin y no en cada plantilla justamente para poder probarse una
 * vez: el calendario del panel y el público lo comparten.
 */

use PHPUnit\Framework\TestCase;

final class ScheduleFeedExpandTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_postmeta();
	}

	/** Crea un evento de mentira y devuelve su "post". */
	private function evento( $id, $inicio, $fin = '' ) {
		update_post_meta( $id, '_cead_acad_event_start', $inicio );
		if ( '' !== $fin ) {
			update_post_meta( $id, '_cead_acad_event_end', $fin );
		}
		return (object) [ 'ID' => $id, 'post_title' => 'Evento ' . $id ];
	}

	public function test_un_evento_de_un_dia_ocupa_un_solo_dia(): void {
		$out = Cead_Acad_Schedule_Feed::expand_by_day( [ $this->evento( 1, '2026-08-12 09:00:00' ) ] );

		$this->assertSame( [ '2026-08-12' ], array_keys( $out ) );
		$this->assertFalse( $out['2026-08-12'][0]['span'] );
		$this->assertTrue( $out['2026-08-12'][0]['ini'] );
		$this->assertTrue( $out['2026-08-12'][0]['fin'] );
	}

	/**
	 * El caso que motiva todo esto: un período se dibuja en todos sus días, con
	 * los extremos marcados. Sin `ini`/`fin` no hay forma de saber dónde poner
	 * los topes de color, y la banda queda sin principio ni final.
	 */
	public function test_un_periodo_marca_donde_empieza_y_donde_termina(): void {
		$out = Cead_Acad_Schedule_Feed::expand_by_day( [ $this->evento( 2, '2026-05-03 00:00:00', '2026-05-06 00:00:00' ) ] );

		$this->assertSame( [ '2026-05-03', '2026-05-04', '2026-05-05', '2026-05-06' ], array_keys( $out ) );

		$this->assertTrue(  $out['2026-05-03'][0]['ini'] );
		$this->assertFalse( $out['2026-05-03'][0]['fin'] );
		$this->assertFalse( $out['2026-05-04'][0]['ini'] );
		$this->assertFalse( $out['2026-05-04'][0]['fin'] );
		$this->assertTrue(  $out['2026-05-06'][0]['fin'] );

		foreach ( $out as $filas ) {
			$this->assertTrue( $filas[0]['span'], 'Todos los días del período tienen que saber que son parte de uno.' );
		}
	}

	/**
	 * Los períodos van arriba y el resto por hora. Si un período quedara debajo
	 * de una charla de las 9 solo los días que hay charla, la banda cambiaría de
	 * altura de una celda a la otra y dejaría de leerse como una sola cosa.
	 */
	public function test_los_periodos_quedan_arriba_y_el_resto_ordenado_por_hora(): void {
		$out = Cead_Acad_Schedule_Feed::expand_by_day( [
			$this->evento( 10, '2026-05-04 15:00:00' ),
			$this->evento( 11, '2026-05-04 09:00:00' ),
			$this->evento( 12, '2026-05-03 00:00:00', '2026-05-06 00:00:00' ),
		] );

		$this->assertSame(
			[ 12, 11, 10 ],
			array_map( static function ( $f ) { return $f['post']->ID; }, $out['2026-05-04'] )
		);
	}

	/**
	 * Un período de tres meses no tiene por qué generar noventa filas para
	 * dibujar cinco semanas: se recorta a la ventana visible.
	 */
	public function test_se_recorta_a_la_ventana_pedida(): void {
		$out = Cead_Acad_Schedule_Feed::expand_by_day(
			[ $this->evento( 3, '2026-01-01 00:00:00', '2026-12-31 00:00:00' ) ],
			strtotime( '2026-05-04' ),
			strtotime( '2026-05-06' )
		);

		$this->assertSame( [ '2026-05-04', '2026-05-05', '2026-05-06' ], array_keys( $out ) );
		// Recortar no puede hacerle creer a la celda que el período empieza acá.
		$this->assertFalse( $out['2026-05-04'][0]['ini'] );
		$this->assertFalse( $out['2026-05-06'][0]['fin'] );
	}

	/**
	 * Una fecha de fin con el año mal tipeado —cosa que en un importador de
	 * planilla pasa— no puede colgar la página rellenando celdas para siempre.
	 */
	public function test_un_fin_absurdo_se_topea(): void {
		$out = Cead_Acad_Schedule_Feed::expand_by_day( [ $this->evento( 4, '2026-05-03 00:00:00', '2126-05-03 00:00:00' ) ] );

		$this->assertLessThanOrEqual( Cead_Acad_Schedule_Feed::TOPE_DIAS + 1, count( $out ) );
	}

	/** Un fin anterior al inicio es un dato roto: se trata como evento de un día. */
	public function test_un_fin_anterior_al_inicio_no_invierte_nada(): void {
		$out = Cead_Acad_Schedule_Feed::expand_by_day( [ $this->evento( 5, '2026-05-03 08:00:00', '2020-01-01 00:00:00' ) ] );

		$this->assertSame( [ '2026-05-03' ], array_keys( $out ) );
		$this->assertFalse( $out['2026-05-03'][0]['span'] );
	}

	/** Sin fecha de inicio no hay dónde dibujarlo: se ignora en vez de romper. */
	public function test_un_evento_sin_fecha_se_ignora(): void {
		$sin = (object) [ 'ID' => 6, 'post_title' => 'Sin fecha' ];

		$this->assertSame( [], Cead_Acad_Schedule_Feed::expand_by_day( [ $sin ] ) );
	}
}
