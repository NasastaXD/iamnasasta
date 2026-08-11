<?php
/**
 * Lógica pura del importador de horario de clases: nombres de día → número, y
 * validación de horas HH:MM.
 *
 * Es el primer importador con tests en el repo. Los otros (cursos, alumnado,
 * notas, eventos) resuelven todo contra `get_posts`/`wp_insert_user`/etc., que
 * necesitan WordPress y quedan fuera de esta suite — pero acá casi toda la
 * lógica que puede fallar (un nombre de día mal tipeado, una hora con formato
 * raro) es pura, así que vale la pena fijarla.
 */

use PHPUnit\Framework\TestCase;

final class ImporterHorariosTest extends TestCase {

	/* ------------------------------------------------------------- días */

	public function test_reconoce_los_siete_dias_en_minuscula(): void {
		$this->assertSame( 1, Cead_Acad_Importer_Horarios::normalizar_dia( 'lunes' ) );
		$this->assertSame( 2, Cead_Acad_Importer_Horarios::normalizar_dia( 'martes' ) );
		$this->assertSame( 3, Cead_Acad_Importer_Horarios::normalizar_dia( 'miercoles' ) );
		$this->assertSame( 4, Cead_Acad_Importer_Horarios::normalizar_dia( 'jueves' ) );
		$this->assertSame( 5, Cead_Acad_Importer_Horarios::normalizar_dia( 'viernes' ) );
		$this->assertSame( 6, Cead_Acad_Importer_Horarios::normalizar_dia( 'sabado' ) );
		$this->assertSame( 7, Cead_Acad_Importer_Horarios::normalizar_dia( 'domingo' ) );
	}

	/** La planilla real del colegio viene con mayúsculas y tildes ("Miércoles"). */
	public function test_tolera_mayusculas_tildes_y_espacios(): void {
		$this->assertSame( 3, Cead_Acad_Importer_Horarios::normalizar_dia( 'Miércoles' ) );
		$this->assertSame( 3, Cead_Acad_Importer_Horarios::normalizar_dia( 'MIÉRCOLES' ) );
		$this->assertSame( 3, Cead_Acad_Importer_Horarios::normalizar_dia( '  Miércoles  ' ) );
		$this->assertSame( 6, Cead_Acad_Importer_Horarios::normalizar_dia( 'Sábado' ) );
	}

	public function test_dia_no_reconocido_devuelve_cero(): void {
		$this->assertSame( 0, Cead_Acad_Importer_Horarios::normalizar_dia( 'lun' ) );
		$this->assertSame( 0, Cead_Acad_Importer_Horarios::normalizar_dia( '' ) );
		$this->assertSame( 0, Cead_Acad_Importer_Horarios::normalizar_dia( 'Monday' ) );
	}

	/* ------------------------------------------------------------- horas */

	public function test_acepta_horas_validas_24h(): void {
		$this->assertSame( '07:00', Cead_Acad_Importer_Horarios::normalizar_hora( '07:00' ) );
		$this->assertSame( '23:59', Cead_Acad_Importer_Horarios::normalizar_hora( '23:59' ) );
		$this->assertSame( '00:00', Cead_Acad_Importer_Horarios::normalizar_hora( '00:00' ) );
	}

	public function test_rechaza_horas_invalidas(): void {
		$this->assertSame( '', Cead_Acad_Importer_Horarios::normalizar_hora( '24:00' ) );
		$this->assertSame( '', Cead_Acad_Importer_Horarios::normalizar_hora( '7:00' ) );   // sin cero a la izquierda
		$this->assertSame( '', Cead_Acad_Importer_Horarios::normalizar_hora( '07:60' ) );
		$this->assertSame( '', Cead_Acad_Importer_Horarios::normalizar_hora( '' ) );
		$this->assertSame( '', Cead_Acad_Importer_Horarios::normalizar_hora( '7 AM' ) );
	}

	/* --------------------------------------------------- validate_row --- */

	/**
	 * `validate_row` resuelve el curso contra la base (necesita WordPress) como
	 * ÚLTIMO paso, a propósito: es la única comprobación cara, así que se deja
	 * para el final y las demás se prueban acá sin necesitar WordPress. Si el
	 * orden se rompiera y el curso se resolviera antes, estos tests fallarían
	 * con un error fatal de `get_posts()` indefinida, no con un assert — y eso
	 * también es una señal válida de que el orden se rompió.
	 */
	public function test_validate_row_rechaza_dia_no_reconocido_antes_de_tocar_la_base(): void {
		$imp = new Cead_Acad_Importer_Horarios();
		$res = $imp->validate_row( [
			'curso' => 'Cualquiera', 'dia' => 'lun', 'inicio' => '07:00', 'fin' => '08:20', 'materia' => 'Matemática',
		] );
		$this->assertSame( 'error', $res['level'] );
		$this->assertStringContainsString( 'no reconocido', $res['message'] );
	}

	public function test_validate_row_rechaza_hora_invalida_antes_de_tocar_la_base(): void {
		$imp = new Cead_Acad_Importer_Horarios();
		$res = $imp->validate_row( [
			'curso' => 'Cualquiera', 'dia' => 'lunes', 'inicio' => '7:00', 'fin' => '08:20', 'materia' => 'Matemática',
		] );
		$this->assertSame( 'error', $res['level'] );
	}

	public function test_validate_row_rechaza_fin_anterior_o_igual_a_inicio_antes_de_tocar_la_base(): void {
		$imp = new Cead_Acad_Importer_Horarios();
		$res = $imp->validate_row( [
			'curso' => 'Cualquiera', 'dia' => 'lunes', 'inicio' => '10:00', 'fin' => '10:00', 'materia' => 'Matemática',
		] );
		$this->assertSame( 'error', $res['level'] );
	}

	public function test_validate_row_rechaza_materia_vacia_antes_de_tocar_la_base(): void {
		$imp = new Cead_Acad_Importer_Horarios();
		$res = $imp->validate_row( [
			'curso' => 'Cualquiera', 'dia' => 'lunes', 'inicio' => '07:00', 'fin' => '08:20', 'materia' => '  ',
		] );
		$this->assertSame( 'error', $res['level'] );
	}

	public function test_validate_row_rechaza_curso_vacio(): void {
		$imp = new Cead_Acad_Importer_Horarios();
		$res = $imp->validate_row( [
			'curso' => '', 'dia' => 'lunes', 'inicio' => '07:00', 'fin' => '08:20', 'materia' => 'Matemática',
		] );
		$this->assertSame( 'error', $res['level'] );
	}
}
