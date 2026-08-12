<?php
/**
 * `normalizar_fecha()`: el importador de Horarios/eventos tiene que tolerar
 * el formato día/mes/año que usa una planilla real de secretaría (feriados,
 * fechas célebres), no solo el ISO que pide la etiqueta del campo.
 *
 * `strtotime()` a secas interpreta las barras como MM/DD/AAAA (formato
 * estadounidense): con el día > 12 la fecha no parsea (rechaza el 15/08,
 * Fundación de Asunción), y con día ≤ 12 la acepta pero cambia mes y día EN
 * SILENCIO (01/05 —1º de mayo— se vuelve 5 de enero, sin ningún error a la
 * vista). Eso es justo lo que motivó este arreglo.
 */

use PHPUnit\Framework\TestCase;

final class ImporterEventsFechaTest extends TestCase {

	public function test_iso_no_se_toca(): void {
		$this->assertSame( '2026-05-01', Cead_Acad_Importer_Events::normalizar_fecha( '2026-05-01' ) );
		$this->assertSame( '2026-05-01 10:00', Cead_Acad_Importer_Events::normalizar_fecha( '2026-05-01 10:00' ) );
	}

	/** El caso que rechazaba antes: día > 12, formato paraguayo con barra. */
	public function test_dia_mayor_a_doce_se_reescribe_a_iso(): void {
		$this->assertSame( '2026-08-15', Cead_Acad_Importer_Events::normalizar_fecha( '15/08/2026' ) );
	}

	/**
	 * El caso peor: día ≤ 12, strtotime() ANTES lo aceptaba pero le cambiaba
	 * la fecha en silencio (01/05 → 5 de enero en vez de 1º de mayo).
	 */
	public function test_dia_ambiguo_se_interpreta_dia_primero_no_mes_primero(): void {
		$this->assertSame( '2026-05-01', Cead_Acad_Importer_Events::normalizar_fecha( '01/05/2026' ) );
	}

	public function test_tolera_guion_ademas_de_barra(): void {
		$this->assertSame( '2026-08-15', Cead_Acad_Importer_Events::normalizar_fecha( '15-08-2026' ) );
	}

	public function test_conserva_la_hora_si_vino(): void {
		$this->assertSame( '2026-08-15 07:30', Cead_Acad_Importer_Events::normalizar_fecha( '15/08/2026 07:30' ) );
	}

	public function test_fecha_invalida_no_se_reescribe_y_strtotime_la_rechaza(): void {
		// 32/13/2026 no es una fecha real: se devuelve tal cual, y por eso
		// strtotime() la sigue rechazando en validate_row().
		$this->assertSame( '32/13/2026', Cead_Acad_Importer_Events::normalizar_fecha( '32/13/2026' ) );
		$this->assertFalse( strtotime( Cead_Acad_Importer_Events::normalizar_fecha( '32/13/2026' ) ) );
	}

	public function test_vacio_devuelve_vacio(): void {
		$this->assertSame( '', Cead_Acad_Importer_Events::normalizar_fecha( '' ) );
	}

	/* ------------------------------------------- validate_row end-to-end --- */

	public function test_validate_row_acepta_feriado_en_formato_paraguayo(): void {
		$imp = new Cead_Acad_Importer_Events();
		$res = $imp->validate_row( [
			'titulo' => 'Fundación de Asunción', 'inicio' => '15/08/2026', 'tipo' => 'evento',
		] );
		$this->assertSame( 'ok', $res['level'] );
	}

	public function test_validate_row_acepta_1_de_mayo_sin_confundirlo_con_5_de_enero(): void {
		$imp = new Cead_Acad_Importer_Events();
		$res = $imp->validate_row( [
			'titulo' => 'Día del Trabajador', 'inicio' => '01/05/2026', 'tipo' => 'evento',
		] );
		$this->assertSame( 'ok', $res['level'] );
	}
}
