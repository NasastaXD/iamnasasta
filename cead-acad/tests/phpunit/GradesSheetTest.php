<?php
/**
 * Lectura de planillas de notas.
 *
 * Cada celda mal interpretada es una nota mal cargada en un boletín, así que
 * lo que no se entiende tiene que quedar afuera y avisarse, no adivinarse.
 */

use PHPUnit\Framework\TestCase;

class GradesSheetTest extends TestCase {

	public function test_reconoce_planillas_por_extension_o_mime() {
		$this->assertTrue( Cead_Acad_Grades_Sheet::looks_like_sheet( [ 'filename' => 'notas.xlsx', 'mime' => '' ] ) );
		$this->assertTrue( Cead_Acad_Grades_Sheet::looks_like_sheet( [ 'filename' => 'notas.csv', 'mime' => '' ] ) );
		$this->assertTrue( Cead_Acad_Grades_Sheet::looks_like_sheet( [ 'filename' => '', 'mime' => 'text/csv' ] ) );
		$this->assertFalse( Cead_Acad_Grades_Sheet::looks_like_sheet( [ 'filename' => 'foto.jpg', 'mime' => 'image/jpeg' ] ) );
		$this->assertFalse( Cead_Acad_Grades_Sheet::looks_like_sheet( null ) );
	}

	/** Notas ya en la escala del colegio (1 a 5). */
	public function test_celda_con_nota_directa() {
		$this->assertSame( 4.0, Cead_Acad_Grades_Sheet::value_to_score( '4' ) );
		$this->assertSame( 3.5, Cead_Acad_Grades_Sheet::value_to_score( '3,5' ) );
		$this->assertSame( 3.5, Cead_Acad_Grades_Sheet::value_to_score( '3.5' ) );
	}

	/** Una celda con «8» en escala de 5 es un error, no una nota. */
	public function test_celda_fuera_de_escala_se_descarta() {
		$this->assertNull( Cead_Acad_Grades_Sheet::value_to_score( '8' ) );
		$this->assertNull( Cead_Acad_Grades_Sheet::value_to_score( '-1' ) );
	}

	public function test_celda_con_puntaje_o_porcentaje() {
		$this->assertSame( 3.0, Cead_Acad_Grades_Sheet::value_to_score( '45/60' ) );
		$this->assertSame( 3.0, Cead_Acad_Grades_Sheet::value_to_score( '75%' ) );
		$this->assertSame( 5.0, Cead_Acad_Grades_Sheet::value_to_score( '90', 'porcentaje' ) );
		$this->assertSame( 3.0, Cead_Acad_Grades_Sheet::value_to_score( '45', 'puntaje', 60 ) );
	}

	public function test_celdas_que_no_son_notas() {
		$this->assertNull( Cead_Acad_Grades_Sheet::value_to_score( '' ) );
		$this->assertNull( Cead_Acad_Grades_Sheet::value_to_score( 'ausente' ) );
		$this->assertNull( Cead_Acad_Grades_Sheet::value_to_score( '-' ) );
		// Más puntos que el total: error de carga.
		$this->assertNull( Cead_Acad_Grades_Sheet::value_to_score( '70', 'puntaje', 60 ) );
	}

	public function test_texto_para_el_modelo_numera_filas_y_recorta() {
		$rows = [ [ 'Alumno', 'Nota' ], [ 'Juan Pérez', '4' ], [ 'Ana Benítez', '5' ] ];
		$txt  = Cead_Acad_Grades_Sheet::to_text( $rows );
		$this->assertStringContainsString( '1: Alumno | Nota', $txt );
		$this->assertStringContainsString( '2: Juan Pérez | 4', $txt );

		$muchas = array_fill( 0, 40, [ 'X', '3' ] );
		$this->assertStringContainsString( 'filas más', Cead_Acad_Grades_Sheet::to_text( $muchas, 5 ) );
	}
}
