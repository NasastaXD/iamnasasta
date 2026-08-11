<?php
/**
 * Qué informa Cead_Acad_Courses_Roster::add() cuando la escritura falla.
 *
 * Antes se devolvía `$wpdb->insert_id` sin mirar el resultado del insert. Ante
 * un fallo ese valor conserva el id del insert ANTERIOR de la misma request,
 * así que la función devolvía un id ajeno y quien la llamaba lo leía como «se
 * inscribió bien». La persona quedaba fuera del curso y todo lo que cuelga del
 * roster —horario, boletín, tareas, comunicados del curso— le aparecía vacío,
 * sin ningún error a la vista.
 *
 * Es el mismo fallo silencioso que ya nos costó tiempo en otros lugares, así
 * que se fija acá.
 */

use PHPUnit\Framework\TestCase;

final class RosterAddTest extends TestCase {

	/** @var Cead_Test_WPDB */
	private $wpdb;

	/** Archivo al que se desvía error_log() para no ensuciar la salida. */
	private $log;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new Cead_Test_WPDB();
		$wpdb       = $this->wpdb;

		$this->log = tempnam( sys_get_temp_dir(), 'ceadlog' );
		ini_set( 'error_log', $this->log );
	}

	protected function tearDown(): void {
		ini_restore( 'error_log' );
		if ( $this->log && file_exists( $this->log ) ) {
			unlink( $this->log );
		}
	}

	private function logged() {
		return $this->log && file_exists( $this->log ) ? (string) file_get_contents( $this->log ) : '';
	}

	/* ----------------------------- alta nueva ----------------------------- */

	public function test_insert_ok_devuelve_el_id_nuevo(): void {
		$this->wpdb->row = null; // no estaba inscripto

		$this->assertSame( 4242, Cead_Acad_Courses_Roster::add( 7, 33, 'student' ) );
	}

	public function test_insert_fallido_devuelve_cero_y_no_el_id_anterior(): void {
		// Un insert previo exitoso deja insert_id cargado: es el valor que antes
		// se colaba como si fuera la inscripción nueva.
		$this->wpdb->insert( 'otra_tabla', [ 'x' => 1 ] );
		$this->assertSame( 4242, $this->wpdb->insert_id );

		$this->wpdb->row           = null;
		$this->wpdb->insert_result = false;

		$this->assertSame( 0, Cead_Acad_Courses_Roster::add( 7, 33, 'student' ) );
	}

	public function test_insert_fallido_deja_rastro_en_el_log(): void {
		$this->wpdb->row           = null;
		$this->wpdb->insert_result = false;

		Cead_Acad_Courses_Roster::add( 7, 33, 'student' );

		$this->assertStringContainsString( 'no se pudo inscribir', $this->logged() );
	}

	/* --------------------------- reinscripción ---------------------------- */

	public function test_reinscripcion_devuelve_el_id_existente(): void {
		$this->wpdb->row = [ 'id' => 91 ];

		$this->assertSame( 91, Cead_Acad_Courses_Roster::add( 7, 33, 'student' ) );
	}

	/**
	 * `$wpdb->update` devuelve 0 cuando ninguna fila cambió, y eso NO es un
	 * error: es lo que pasa al reinscribir a alguien que ya estaba activo con el
	 * mismo rol. Tratarlo como fallo rompería el caso más común.
	 */
	public function test_update_sin_cambios_no_es_error(): void {
		$this->wpdb->row           = [ 'id' => 91 ];
		$this->wpdb->update_result = 0;

		$this->assertSame( 91, Cead_Acad_Courses_Roster::add( 7, 33, 'student' ) );
		$this->assertStringNotContainsString( 'no se pudo', $this->logged() );
	}

	public function test_update_fallido_devuelve_cero(): void {
		$this->wpdb->row           = [ 'id' => 91 ];
		$this->wpdb->update_result = false;

		$this->assertSame( 0, Cead_Acad_Courses_Roster::add( 7, 33, 'student' ) );
		$this->assertStringContainsString( 'no se pudo reactivar', $this->logged() );
	}

	/* ------------------------- contrato con el resto ----------------------- */

	/**
	 * Los llamadores hacen `if ( ! Roster::add(...) )`, así que el valor de
	 * fallo tiene que ser falsy y el de éxito truthy. Si algún día se devolviera
	 * un WP_Error, esos `if` lo leerían como éxito.
	 */
	public function test_el_valor_de_fallo_es_falsy_y_el_de_exito_truthy(): void {
		$this->wpdb->row           = null;
		$this->wpdb->insert_result = false;
		$this->assertFalse( (bool) Cead_Acad_Courses_Roster::add( 7, 33, 'student' ) );

		$this->wpdb->insert_result = 1;
		$this->assertTrue( (bool) Cead_Acad_Courses_Roster::add( 7, 33, 'student' ) );
	}
}
