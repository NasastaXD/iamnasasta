<?php
use PHPUnit\Framework\TestCase;

/** Exportación de investigación (spec 7): consentimiento, campos prohibidos, k ≥ 5. */
class EstudioExportTest extends TestCase {

	private function ev( $s, $con = 1 ) {
		return [ 'seudonimo' => $s, 'institucion' => 'I1', 'rol' => 'alumno', 'curso_grupo' => '1° año', 'evento' => 'comunicado_leido',
			'entidad_tipo' => 'comunicado', 'entidad_id' => 7, 'canal' => 'app', 'ts_cliente' => '2027-04-01 13:00:00',
			'ts_servidor' => '2027-04-01 14:00:00', 'meta' => '{"ts_cliente_dudoso":false}', 'con_consentimiento' => $con ];
	}

	private function resp( $s, $abiertas_revisadas = null ) {
		return [ 'seudonimo' => $s, 'institucion' => 'I1', 'rol' => 'alumno', 'curso_grupo' => '1° año', 'mes' => 1,
			'instrumento_clave' => 'estudio-m01-alumno', 'instrumento_version' => 'mensual@1', 'cerradas' => '{"mensual.a_tiempo":4}',
			'abiertas' => '{"mensual.mejorar":"soy Ana Benítez de 1° A"}', 'abiertas_revisadas' => $abiertas_revisadas,
			'sus_puntaje' => null, 'con_consentimiento' => 1, 'ts_cliente' => null, 'ts_servidor' => '2027-04-02 00:00:00' ];
	}

	private function personas() {
		return [ 'p1', 'p2', 'p3', 'p4', 'p5', 'p6' ];
	}

	public function test_solo_consentimiento_vigente_al_exportar() {
		$con = array_fill_keys( array_merge( $this->personas(), [ 'sin' ] ), true );
		$eventos = array_merge( array_map( [ $this, 'ev' ], $this->personas() ), [ $this->ev( 'sin', 0 ), $this->ev( 'retirado' ) ] );
		$x = Cead_Acad_Estudio_Admin::construir_exportacion( $eventos, [], [], [], $con );
		$this->assertSame( 6, $x['resumen']['eventos'] );
		$this->assertStringNotContainsString( 'retirado', $x['archivos']['eventos.csv'] );
		$this->assertStringNotContainsString( "\nsin,", $x['archivos']['eventos.csv'] );
	}

	public function test_ninguna_columna_prohibida_y_texto_libre_solo_revisado() {
		$con = array_fill_keys( $this->personas(), true );
		$x = Cead_Acad_Estudio_Admin::construir_exportacion( array_map( [ $this, 'ev' ], $this->personas() ),
			array_map( function ( $p ) { return $this->resp( $p ); }, $this->personas() ), [], [], $con );
		foreach ( $x['archivos'] as $nombre => $csv ) {
			if ( 'diccionario.csv' === $nombre ) {
				continue;
			}
			$cols = str_getcsv( strtok( $csv, "\n" ), ',', '"', '\\' );
			foreach ( Cead_Acad_Estudio_Logica::PROHIBIDAS as $p ) {
				$this->assertNotContains( $p, $cols, "$nombre tiene $p" );
			}
		}
		$this->assertStringNotContainsString( 'Ana Benítez', $x['archivos']['respuestas.csv'], 'el texto sin revisar no sale' );
		$this->assertStringContainsString( 'mensual.a_tiempo', $x['archivos']['respuestas.csv'] );
	}

	public function test_k_anonimato_y_asistencia_agregada() {
		$con = array_fill_keys( $this->personas(), true );
		$x = Cead_Acad_Estudio_Admin::construir_exportacion( array_map( [ $this, 'ev' ], array_slice( $this->personas(), 0, 4 ) ), [], [
			[ 'institucion' => 'I1', 'curso_grupo' => '1° año', 'fecha' => '2027-04-01', 'tomas' => 1, 'alumnos' => 4, 'presente' => 3, 'ausente' => 1, 'tarde' => 0, 'justificado' => 0 ],
			[ 'institucion' => 'I1', 'curso_grupo' => '1° año', 'fecha' => '2027-04-02', 'tomas' => 1, 'alumnos' => 30, 'presente' => 28, 'ausente' => 2, 'tarde' => 0, 'justificado' => 0 ],
		], [], $con );
		$this->assertSame( 0, $x['resumen']['eventos'], 'un grupo de 4 no sale' );
		$this->assertSame( 1, $x['resumen']['asistencias'], 'la celda de 4 alumnos se suprime' );
		$this->assertStringContainsString( 'seudonimo', $x['archivos']['diccionario.csv'] );
	}

	public function test_csv_neutraliza_formulas() {
		$csv = Cead_Acad_Admin_Exports::to_csv( [ 'a' ], [ [ '=HYPERLINK("x")' ] ] );
		$this->assertStringContainsString( "'=HYPERLINK", $csv );
	}
}
