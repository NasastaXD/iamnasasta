<?php
/**
 * Armado del horario para la app.
 *
 * El dato guardado es una lista plana de franjas; lo que se mira es una semana.
 * Esa conversión la hace el servidor y no cada cliente, justamente para que el
 * panel web y la app no puedan discrepar — así que si se rompe acá, se rompe
 * igual en los dos lados, que es lo que se quiere.
 */

use PHPUnit\Framework\TestCase;

final class ApiPanelTest extends TestCase {

	private const CURSO = 20;

	protected function setUp(): void {
		cead_test_reset_postmeta();
	}

	private function cargar( array $franjas ): void {
		update_post_meta( self::CURSO, '_cead_acad_horario', $franjas );
	}

	/* -------------------------------- agrupado ------------------------------ */

	public function test_agrupa_por_dia_y_ordena_por_hora(): void {
		$this->cargar( [
			[ 'dia' => 2, 'inicio' => '09:00', 'fin' => '10:00', 'materia' => 'Historia' ],
			[ 'dia' => 1, 'inicio' => '10:00', 'fin' => '11:00', 'materia' => 'Lengua' ],
			[ 'dia' => 1, 'inicio' => '07:00', 'fin' => '08:00', 'materia' => 'Matemática' ],
		] );

		$dias = Cead_Acad_API_Panel::franjas_por_dia( self::CURSO );

		$this->assertSame( [ 1, 2 ], array_column( $dias, 'dia' ) );
		$this->assertSame(
			[ 'Matemática', 'Lengua' ],
			array_column( $dias[0]['franjas'], 'materia' ),
			'dentro del día, la primera hora va primero'
		);
	}

	/**
	 * El meta puede venir como JSON: así lo dejan algunos importadores, y la
	 * plantilla web ya lo contempla. Si la app no lo entendiera, los mismos
	 * cursos se verían con horario en la web y vacíos en el teléfono.
	 */
	public function test_entiende_el_horario_guardado_como_json(): void {
		update_post_meta( self::CURSO, '_cead_acad_horario', wp_json_encode( [
			[ 'dia' => 3, 'inicio' => '08:00', 'materia' => 'Biología' ],
		] ) );

		$dias = Cead_Acad_API_Panel::franjas_por_dia( self::CURSO );

		$this->assertSame( 3, $dias[0]['dia'] );
		$this->assertSame( 'Biología', $dias[0]['franjas'][0]['materia'] );
	}

	public function test_descarta_las_franjas_con_dia_imposible(): void {
		$this->cargar( [
			[ 'dia' => 0, 'inicio' => '08:00', 'materia' => 'Fantasma' ],
			[ 'dia' => 8, 'inicio' => '08:00', 'materia' => 'Otro fantasma' ],
			[ 'dia' => 5, 'inicio' => '08:00', 'materia' => 'Real' ],
		] );

		$dias = Cead_Acad_API_Panel::franjas_por_dia( self::CURSO );

		$this->assertCount( 1, $dias );
		$this->assertSame( 'Real', $dias[0]['franjas'][0]['materia'] );
	}

	/**
	 * Un curso sin horario cargado devuelve una lista vacía, no un error: no
	 * tener horario todavía es un estado normal a principio de año, y la app
	 * tiene que poder decir «todavía no lo cargaron» en vez de «falló algo».
	 */
	public function test_sin_horario_cargado_devuelve_vacio(): void {
		$this->assertSame( [], Cead_Acad_API_Panel::franjas_por_dia( self::CURSO ) );
	}

	public function test_un_meta_con_basura_no_rompe(): void {
		update_post_meta( self::CURSO, '_cead_acad_horario', 'esto no es un horario' );

		$this->assertSame( [], Cead_Acad_API_Panel::franjas_por_dia( self::CURSO ) );
	}

	/** Las franjas incompletas no se caen: se emiten con los campos en blanco. */
	public function test_una_franja_incompleta_sale_con_campos_vacios(): void {
		$this->cargar( [ [ 'dia' => 4, 'materia' => 'Suelta' ] ] );

		$franja = Cead_Acad_API_Panel::franjas_por_dia( self::CURSO )[0]['franjas'][0];

		$this->assertSame( 'Suelta', $franja['materia'] );
		$this->assertSame( '', $franja['inicio'] );
		$this->assertSame( '', $franja['aula'] );
		$this->assertSame( '', $franja['docente'] );
	}
}
