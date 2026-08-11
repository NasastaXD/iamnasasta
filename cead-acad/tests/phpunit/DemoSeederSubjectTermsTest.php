<?php
/**
 * Términos de materia (`cead_acad_subject`) que crea el seeder de demo.
 *
 * `ensure_subject_term()` es lo que faltaba: antes, `seed_resources()` y
 * `seed_grades()` creaban términos con `wp_set_object_terms()` /
 * `wp_insert_term()` sueltos, sin marcarlos con `self::FLAG`. `purge()`
 * borraba posts y usuarios, pero nunca esos términos — «Institucional»
 * (que ni siquiera es una materia real) se quedaba para siempre en el
 * filtro de Materias de Recursos, y lo mismo cualquier otra materia que
 * la demo hubiera inventado.
 */

use PHPUnit\Framework\TestCase;

final class DemoSeederSubjectTermsTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_terms();
	}

	/** Los helpers son protected: se los alcanza por reflexión. */
	private function call( $metodo, ...$args ) {
		$m = new ReflectionMethod( 'Cead_Acad_Demo_Seeder', $metodo );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	public function test_un_termino_nuevo_se_crea_y_se_marca_como_demo(): void {
		$term_id = $this->call( 'ensure_subject_term', 'Institucional' );

		$this->assertGreaterThan( 0, $term_id );
		$this->assertSame( 1, get_term_meta( $term_id, Cead_Acad_Demo_Seeder::FLAG, true ) );
	}

	public function test_un_termino_existente_se_reusa_y_no_se_marca(): void {
		// Una materia real, cargada por dirección antes de que corra la demo.
		$real = wp_insert_term( 'Matemática', 'cead_acad_subject' );
		$real_id = $real['term_id'];

		$term_id = $this->call( 'ensure_subject_term', 'Matemática' );

		$this->assertSame( $real_id, $term_id );
		$this->assertSame( '', get_term_meta( $term_id, Cead_Acad_Demo_Seeder::FLAG, true ) );
	}

	public function test_llamarlo_dos_veces_con_el_mismo_nombre_no_duplica(): void {
		$primero  = $this->call( 'ensure_subject_term', 'Guaraní Ñe\'ẽ' );
		$segundo  = $this->call( 'ensure_subject_term', 'Guaraní Ñe\'ẽ' );

		$this->assertSame( $primero, $segundo );
		$this->assertCount( 1, get_terms( [ 'taxonomy' => 'cead_acad_subject', 'hide_empty' => false ] ) );
	}

	/**
	 * Reproduce el bloque de limpieza de purge(): un término marcado como demo
	 * y sin uso se borra; uno que sigue en uso (por contenido real, no de
	 * demo) se respeta.
	 */
	public function test_purge_borra_el_termino_de_demo_sin_uso_pero_respeta_el_que_sigue_en_uso(): void {
		$huerfano = $this->call( 'ensure_subject_term', 'Institucional' );
		$en_uso   = $this->call( 'ensure_subject_term', 'Matemática' );

		// Simula que un recurso real, no de demo, sigue usando "Matemática".
		cead_test_set_term_count( $en_uso, 1 );

		$term_ids = get_terms( [
			'taxonomy'   => 'cead_acad_subject',
			'hide_empty' => false,
			'fields'     => 'ids',
			'meta_key'   => Cead_Acad_Demo_Seeder::FLAG,
		] );
		foreach ( $term_ids as $tid ) {
			$term = get_term( $tid, 'cead_acad_subject' );
			if ( $term && ! is_wp_error( $term ) && 0 === (int) $term->count ) {
				wp_delete_term( $tid, 'cead_acad_subject' );
			}
		}

		$this->assertNull( get_term( $huerfano, 'cead_acad_subject' ), 'El término sin uso tenía que borrarse.' );
		$this->assertNotNull( get_term( $en_uso, 'cead_acad_subject' ), 'El término en uso no tenía que tocarse.' );
	}
}
