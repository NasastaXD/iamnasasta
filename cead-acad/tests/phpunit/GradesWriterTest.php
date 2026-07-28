<?php
/**
 * Carga de calificaciones: resolución de nombres y validación.
 *
 * Es la lógica que decide A QUIÉN se le pone una nota, así que un error acá
 * escribe en el boletín equivocado.
 */

use PHPUnit\Framework\TestCase;

class GradesWriterTest extends TestCase {

	private function curso() {
		return [
			[ 'id' => 1, 'name' => 'Juan Pérez',     'doc' => '4123456' ],
			[ 'id' => 2, 'name' => 'Juana Peralta',  'doc' => '5222333' ],
			[ 'id' => 3, 'name' => 'María González', 'doc' => '3111222' ],
			[ 'id' => 4, 'name' => 'Ana Benítez',    'doc' => '6333444' ],
		];
	}

	private function base() {
		return [
			'student_user_id' => 1,
			'course_id'       => 2,
			'subject_term_id' => 3,
			'recorded_by'     => 9,
			'period'          => '2',
			'score'           => 8,
		];
	}

	public function test_encuentra_por_nombre_exacto_y_sin_acentos() {
		foreach ( [ 'Juan Pérez', 'juan perez', 'perez juan' ] as $q ) {
			$r = Cead_Acad_Grades_Writer::pick_by_name( $this->curso(), $q );
			$this->assertSame( 'exact', $r['status'], "falló con: {$q}" );
			$this->assertSame( 1, $r['matches'][0]['id'], "falló con: {$q}" );
		}
	}

	public function test_encuentra_por_apellido_y_por_documento() {
		$r = Cead_Acad_Grades_Writer::pick_by_name( $this->curso(), 'gonzalez' );
		$this->assertSame( 'exact', $r['status'] );
		$this->assertSame( 3, $r['matches'][0]['id'] );

		$r = Cead_Acad_Grades_Writer::pick_by_name( $this->curso(), '4123456' );
		$this->assertSame( 'exact', $r['status'] );
		$this->assertSame( 1, $r['matches'][0]['id'] );
	}

	/** Ante dos personas parecidas pregunta, no elige por su cuenta. */
	public function test_nombre_ambiguo_no_elige_solo() {
		$r = Cead_Acad_Grades_Writer::pick_by_name( $this->curso(), 'jua' );
		$this->assertSame( 'ambiguous', $r['status'] );
		$this->assertCount( 2, $r['matches'] );
	}

	public function test_nombre_inexistente() {
		$this->assertSame( 'none', Cead_Acad_Grades_Writer::pick_by_name( $this->curso(), 'rodriguez' )['status'] );
		$this->assertSame( 'none', Cead_Acad_Grades_Writer::pick_by_name( $this->curso(), '' )['status'] );
		$this->assertSame( 'none', Cead_Acad_Grades_Writer::pick_by_name( [], 'juan' )['status'] );
	}

	public function test_normaliza_el_periodo() {
		$casos = [
			'2' => '2', 'segundo trimestre' => '2', '2do' => '2', 'p3' => '3',
			'primer periodo' => '1', 'cuarto' => '4', 'Final' => 'Final', 'el final' => 'Final',
			'' => '', 'asdf' => '',
		];
		foreach ( $casos as $entrada => $esperado ) {
			$this->assertSame( $esperado, Cead_Acad_Grades_Writer::norm_period( $entrada ), "falló con: {$entrada}" );
		}
	}

	public function test_validacion_caso_feliz() {
		$v = Cead_Acad_Grades_Writer::validate( $this->base() );
		$this->assertIsArray( $v );
		$this->assertSame( 8.0, $v['score'] );
		$this->assertSame( '2', $v['period'] );
	}

	/**
	 * @dataProvider datos_invalidos
	 */
	public function test_validacion_rechaza_datos_invalidos( array $override ) {
		$v = Cead_Acad_Grades_Writer::validate( array_merge( $this->base(), $override ) );
		$this->assertTrue( is_wp_error( $v ) );
	}

	public static function datos_invalidos() {
		return [
			'sin alumno'          => [ [ 'student_user_id' => 0 ] ],
			'sin curso'           => [ [ 'course_id' => 0 ] ],
			'sin materia'         => [ [ 'subject_term_id' => 0 ] ],
			'sin autor'           => [ [ 'recorded_by' => 0 ] ],
			'periodo ilegible'    => [ [ 'period' => 'asdf' ] ],
			'nota negativa'       => [ [ 'score' => -1 ] ],
			'nota fuera de escala'=> [ [ 'score' => 101 ] ],
			'nota no numérica'    => [ [ 'score' => 'ocho' ] ],
			'sin nota ni letra'   => [ [ 'score' => null, 'letter' => '' ] ],
		];
	}

	public function test_acepta_solo_letra_y_recorta_al_largo_de_la_columna() {
		$v = Cead_Acad_Grades_Writer::validate( array_merge( $this->base(), [ 'score' => null, 'letter' => 'AA' ] ) );
		$this->assertIsArray( $v );

		$v = Cead_Acad_Grades_Writer::validate( array_merge( $this->base(), [ 'letter' => 'ABCDEFGHIJKL' ] ) );
		$this->assertIsArray( $v );
		$this->assertSame( 8, strlen( $v['letter'] ) );
	}

	/** La escala es configurable: con 1-10, un 85 tiene que rebotar. */
	public function test_respeta_la_escala_configurada() {
		cead_test_set_option( 'cead_acad_grades_score_max', 10 );
		$this->assertTrue( is_wp_error( Cead_Acad_Grades_Writer::validate( array_merge( $this->base(), [ 'score' => 85 ] ) ) ) );
		$this->assertIsArray( Cead_Acad_Grades_Writer::validate( array_merge( $this->base(), [ 'score' => 9 ] ) ) );
		cead_test_reset_options();
	}
}
