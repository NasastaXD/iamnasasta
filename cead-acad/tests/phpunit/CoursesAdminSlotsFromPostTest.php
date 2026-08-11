<?php
/**
 * `slots_from_post()`: lo comparten el metabox del curso y la pantalla de
 * revisión centralizada de horarios (`admin/views/horarios-review.php`) para
 * parsear las mismas filas `cead_acad_horario[i][...]` del POST.
 */

use PHPUnit\Framework\TestCase;

final class CoursesAdminSlotsFromPostTest extends TestCase {

	private function call() {
		$m = new ReflectionMethod( 'Cead_Acad_Courses_Admin', 'slots_from_post' );
		$m->setAccessible( true );
		return $m->invokeArgs( new Cead_Acad_Courses_Admin(), [] );
	}

	protected function tearDown(): void {
		unset( $_POST['cead_acad_horario'] );
	}

	public function test_sin_post_devuelve_vacio(): void {
		unset( $_POST['cead_acad_horario'] );
		$this->assertSame( [], $this->call() );
	}

	public function test_descarta_filas_sin_dia_o_sin_materia(): void {
		$_POST['cead_acad_horario'] = [
			[ 'dia' => '0', 'inicio' => '07:00', 'fin' => '08:20', 'materia' => 'Matemática' ], // sin día
			[ 'dia' => '1', 'inicio' => '07:00', 'fin' => '08:20', 'materia' => '' ],            // sin materia
		];
		$this->assertSame( [], $this->call() );
	}

	public function test_conserva_una_fila_valida_con_sus_campos(): void {
		$_POST['cead_acad_horario'] = [
			[ 'dia' => '3', 'inicio' => '10:00', 'fin' => '11:20', 'materia' => 'Física', 'docente' => 'Ana Gómez', 'aula' => 'Aula 5' ],
		];
		$slots = $this->call();
		$this->assertCount( 1, $slots );
		$this->assertSame( 3, $slots[0]['dia'] );
		$this->assertSame( '10:00', $slots[0]['inicio'] );
		$this->assertSame( '11:20', $slots[0]['fin'] );
		$this->assertSame( 'Física', $slots[0]['materia'] );
		$this->assertSame( 'Ana Gómez', $slots[0]['docente'] );
		$this->assertSame( 'Aula 5', $slots[0]['aula'] );
	}

	/** El aula es opcional: si viene vacía, ni se guarda la clave. */
	public function test_aula_vacia_no_deja_la_clave(): void {
		$_POST['cead_acad_horario'] = [
			[ 'dia' => '1', 'inicio' => '07:00', 'fin' => '08:20', 'materia' => 'Guaraní', 'aula' => '' ],
		];
		$slots = $this->call();
		$this->assertArrayNotHasKey( 'aula', $slots[0] );
	}

	/** Una hora con formato inválido se limpia a '', no se cuela cualquier texto. */
	public function test_hora_invalida_se_limpia_a_vacio(): void {
		$_POST['cead_acad_horario'] = [
			[ 'dia' => '1', 'inicio' => '7am', 'fin' => '25:99', 'materia' => 'Historia' ],
		];
		$slots = $this->call();
		$this->assertSame( '', $slots[0]['inicio'] );
		$this->assertSame( '', $slots[0]['fin'] );
	}
}
