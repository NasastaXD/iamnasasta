<?php
use PHPUnit\Framework\TestCase;

/** Tags del panel maestro (roles y features) en el plugin. */
class EstudioTagsTest extends TestCase {

	private function cfg() {
		return json_decode( file_get_contents( CEAD_ACAD_DIR . 'config/estudio-encuestas.json' ), true );
	}

	public function test_sin_la_feature_de_asistencia_esas_preguntas_no_se_mandan() {
		$con = Cead_Acad_Estudio_Logica::instrumento( $this->cfg(), 1, 'docente', [ 'asistencias', 'comunicados' ] );
		$sin = Cead_Acad_Estudio_Logica::instrumento( $this->cfg(), 1, 'docente', [ 'comunicados' ] );
		$this->assertCount( 7, $con['preguntas'] );
		$this->assertCount( 5, $sin['preguntas'] );
		$this->assertSame( [ 'mensual.asistencia_rapida', 'mensual.dias_asistencia' ], array_column( $sin['omitidas'], 'id' ) );
		$this->assertArrayNotHasKey( 'features', $sin['preguntas'][0], 'los tags no van a la pantalla' );
		$this->assertCount( 7, Cead_Acad_Estudio_Logica::instrumento( $this->cfg(), 1, 'docente' )['preguntas'], 'null = sin filtro' );
	}

	public function test_directores_y_bloques_sin_preguntas_para_un_rol() {
		$cfg = $this->cfg();
		$cfg['roles'] = [ 'alumno', 'docente', 'director' ];
		$cfg['instrumentos']['mensual']['director'] = [ 'versiones' => [ [ 'version' => 2, 'preguntas' => [
			[ 'id' => 'clima', 'tipo' => 'multiple', 'texto' => '¿Qué mejoró?', 'opciones' => [ 'Comunicación', 'Asistencia' ] ],
		] ] ] ];
		// Mes 1 (solo «mensual»): el director tiene su pregunta.
		$d = Cead_Acad_Estudio_Logica::instrumento( $cfg, 1, 'director' );
		$this->assertSame( 'mensual@2', $d['version'] );
		// Mes 0 (solo «inicial»): no hay nada para director → no se crea.
		$this->expectException( InvalidArgumentException::class );
		Cead_Acad_Estudio_Logica::instrumento( $cfg, 0, 'director' );
	}

	public function test_respuesta_multiple() {
		$p = [ [ 'id' => 'x.m', 'tipo' => 'multiple', 'texto' => '?', 'opciones' => [ 'A', 'B' ] ] ];
		$this->assertSame( [ 'A', 'B' ], Cead_Acad_Estudio_Logica::validar_respuestas( $p, [ 'x.m' => [ 'A', 'B', 'A' ] ] )['cerradas']['x.m'] );
		$this->assertNotEmpty( Cead_Acad_Estudio_Logica::validar_respuestas( $p, [ 'x.m' => [ 'C' ] ] )['errores'] );
		$this->assertSame( 'checkbox', Cead_Acad_Estudio_Logica::tipo_survey( 'multiple' ) );
	}

	public function test_validar_config_importada_del_panel() {
		$this->assertSame( [], Cead_Acad_Estudio_Logica::validar_config( $this->cfg() ) );
		$this->assertNotEmpty( Cead_Acad_Estudio_Logica::validar_config( [ 'meses' => [] ] ) );
		$mal = $this->cfg();
		$mal['instrumentos']['mensual']['alumno']['versiones'][0]['preguntas'][0]['tipo'] = 'inventado';
		$this->assertNotEmpty( Cead_Acad_Estudio_Logica::validar_config( $mal ) );
	}

	public function test_bitacora_sin_datos_personales() {
		$this->assertSame(
			[ 'version' => 'mensual@1', 'omitidas' => [ [ 'id' => 'mensual.asistencia_rapida' ] ] ],
			Cead_Acad_Estudio_Logica::meta_segura_profunda( [ 'version' => 'mensual@1', 'email' => 'a@b.c', 'omitidas' => [ [ 'id' => 'mensual.asistencia_rapida', 'nombre' => 'Ana' ] ] ] )
		);
	}
}
