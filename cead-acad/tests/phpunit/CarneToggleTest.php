<?php
/**
 * El carné digital se prende y se apaga desde wp-admin, y arranca apagado.
 *
 * Lo que estos tests cuidan no es el interruptor, que es trivial, sino que sea
 * UNO. El carné se muestra en seis lugares —menú lateral, barra superior,
 * atajos del inicio, perfil, la ruta del panel y la verificación pública— y una
 * función a medio apagar es peor que una encendida: el alumno ve «Mi carné» en
 * el menú, entra, y el panel lo rebota al inicio sin decirle nada.
 *
 * El caso más silencioso es la verificación pública: esa página muestra nombre,
 * foto y curso a cualquiera con el token. Si se apagaran solo los enlaces del
 * panel, esa página seguiría viva sin que nadie la vea en la interfaz.
 */

use PHPUnit\Framework\TestCase;

final class CarneToggleTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
	}

	/**
	 * De fábrica apagado. Si alguien cambia este default, la función aparece
	 * sola en el panel de todos los alumnos en la próxima actualización.
	 */
	public function test_de_fabrica_esta_apagado(): void {
		$this->assertFalse( cead_acad_carne_activo() );
	}

	public function test_se_prende_desde_la_opcion(): void {
		cead_test_set_option( 'cead_acad_carne_enabled', 1 );

		$this->assertTrue( cead_acad_carne_activo() );
	}

	/** Guardar el formulario con la casilla vacía tiene que apagarlo de verdad. */
	public function test_el_cero_lo_apaga(): void {
		cead_test_set_option( 'cead_acad_carne_enabled', 0 );

		$this->assertFalse( cead_acad_carne_activo() );
	}

	/**
	 * CEADI no puede ofrecer el carné con la función apagada.
	 *
	 * Esta lista viaja DENTRO del prompt: dejar la acción ahí es enseñarle al
	 * bot a mandar alumnos a una página que redirige sola. Y como el bot decide
	 * por su cuenta cuándo usar cada acción, no hay forma de que se «acuerde»
	 * de no ofrecerla si se la seguimos mostrando.
	 */
	public function test_ceadi_no_ofrece_el_carne_apagado(): void {
		$this->assertArrayNotHasKey( 'carne', Cead_Acad_WA_AI::actions() );
	}

	public function test_ceadi_ofrece_el_carne_prendido(): void {
		cead_test_set_option( 'cead_acad_carne_enabled', 1 );

		$acciones = Cead_Acad_WA_AI::actions();
		$this->assertArrayHasKey( 'carne', $acciones );
		$this->assertNotSame( '', (string) $acciones['carne'] );
	}

	/**
	 * Prender o apagar el carné no puede llevarse puesto el resto del catálogo:
	 * el bot perdería el horario o las notas por un interruptor que no tiene
	 * nada que ver.
	 */
	public function test_el_resto_de_las_acciones_no_depende_del_carne(): void {
		$apagado = Cead_Acad_WA_AI::actions();
		cead_test_set_option( 'cead_acad_carne_enabled', 1 );
		$prendido = Cead_Acad_WA_AI::actions();

		foreach ( [ 'horario', 'notas', 'tareas', 'eventos', 'comunicados', 'panel', 'faq' ] as $clave ) {
			$this->assertArrayHasKey( $clave, $apagado );
			$this->assertArrayHasKey( $clave, $prendido );
		}

		$this->assertSame( count( $apagado ) + 1, count( $prendido ) );
	}
}
