<?php
/**
 * La figura de cada tipo de evento.
 *
 * En una celda de calendario un evento ocupa unos pocos píxeles, y a ese tamaño
 * el color solo no dice nada: hay que ir a la referencia y contar cuál de los
 * nueve tonos es. Con una figura encima se lee de un vistazo, y para quien no
 * distingue bien los colores —uno de cada doce varones— pasa de adivinanza a
 * información.
 *
 * Por eso el test que importa no es que el SVG esté bien formado, sino que NO
 * haya dos tipos con el mismo dibujo: dos figuras iguales devuelven el problema
 * al punto de partida sin que se note en ninguna pantalla.
 */

use PHPUnit\Framework\TestCase;

final class ScheduleIconTest extends TestCase {

	public function test_todos_los_tipos_tienen_figura(): void {
		foreach ( Cead_Acad_Schedule_CPT::TYPES as $tipo ) {
			$svg = Cead_Acad_Schedule_CPT::type_icon( $tipo );
			$this->assertStringContainsString( '<svg', $svg, "El tipo {$tipo} se quedó sin figura." );
			$this->assertStringContainsString( '</svg>', $svg );
		}
	}

	/** Dos tipos con el mismo dibujo es lo mismo que no tener dibujo. */
	public function test_ningun_tipo_repite_la_figura_de_otro(): void {
		$vistos = [];
		foreach ( Cead_Acad_Schedule_CPT::TYPES as $tipo ) {
			$svg = Cead_Acad_Schedule_CPT::type_icon( $tipo );
			$this->assertArrayNotHasKey( $svg, $vistos, "«{$tipo}» dibuja lo mismo que «" . ( $vistos[ $svg ] ?? '' ) . '».' );
			$vistos[ $svg ] = $tipo;
		}
	}

	/** Un tipo desconocido no rompe la celda: cae en el genérico. */
	public function test_un_tipo_inventado_cae_en_el_generico(): void {
		$this->assertSame(
			Cead_Acad_Schedule_CPT::type_icon( 'evento' ),
			Cead_Acad_Schedule_CPT::type_icon( 'kermesse' )
		);
	}

	/**
	 * Hereda el color por `currentColor` en vez de traerlo pintado: es lo que
	 * permite que la misma figura sirva sobre claro y sobre oscuro, y que tome
	 * el color del evento sin generar un juego de íconos por cada tono.
	 */
	public function test_la_figura_hereda_el_color_en_vez_de_traerlo_fijo(): void {
		$svg = Cead_Acad_Schedule_CPT::type_icon( 'examen' );

		$this->assertStringContainsString( 'stroke="currentColor"', $svg );
		$this->assertStringNotContainsString( '#', $svg, 'Un color fijo adentro del SVG ignora el color del evento.' );
	}

	/** El tamaño se pide, y llega como número: es un atributo, no texto libre. */
	public function test_el_tamano_se_respeta_y_se_sanea(): void {
		$this->assertStringContainsString( 'width="18"', Cead_Acad_Schedule_CPT::type_icon( 'acto', 18 ) );
		$this->assertStringContainsString( 'width="0"',  Cead_Acad_Schedule_CPT::type_icon( 'acto', '<script>' ) );
	}

	/** No se anuncia a los lectores de pantalla: al lado va el nombre del evento. */
	public function test_la_figura_es_decorativa(): void {
		$this->assertStringContainsString( 'aria-hidden="true"', Cead_Acad_Schedule_CPT::type_icon( 'feriado' ) );
	}
}
