<?php
/**
 * Colores de evento: propio si cargó uno válido, si no el del tipo.
 * Centralizado en `Cead_Acad_Schedule_CPT::event_color()` para que el
 * calendario y la agenda se vean siempre consistentes entre sí.
 */

use PHPUnit\Framework\TestCase;

final class ScheduleCptColorTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_postmeta();
	}

	public function test_cada_tipo_conocido_tiene_un_color_por_defecto_distinto(): void {
		$colores = array_map( [ 'Cead_Acad_Schedule_CPT', 'default_color' ], Cead_Acad_Schedule_CPT::TYPES );
		$this->assertCount( count( Cead_Acad_Schedule_CPT::TYPES ), array_unique( $colores ), 'Dos tipos con el mismo color por defecto se confunden en el calendario.' );
		foreach ( $colores as $c ) {
			$this->assertMatchesRegularExpression( '/^#[0-9A-Fa-f]{6}$/', $c );
		}
	}

	public function test_tipo_desconocido_cae_en_el_color_de_evento(): void {
		$this->assertSame( Cead_Acad_Schedule_CPT::default_color( 'evento' ), Cead_Acad_Schedule_CPT::default_color( 'algo-que-no-existe' ) );
	}

	public function test_sin_color_propio_usa_el_del_tipo(): void {
		update_post_meta( 42, '_cead_acad_event_type', 'feriado' );
		$this->assertSame( Cead_Acad_Schedule_CPT::default_color( 'feriado' ), Cead_Acad_Schedule_CPT::event_color( 42 ) );
	}

	public function test_con_color_propio_ese_gana(): void {
		update_post_meta( 42, '_cead_acad_event_type', 'feriado' );
		update_post_meta( 42, '_cead_acad_event_color', '#123456' );
		$this->assertSame( '#123456', Cead_Acad_Schedule_CPT::event_color( 42 ) );
	}

	/**
	 * #abc corto es válido en CSS, pero quien lo pinta a veces le suma un
	 * sufijo de transparencia (#rrggbbAA) — sobre 3 dígitos eso da una
	 * cadena inválida. Por eso event_color() siempre devuelve 6 dígitos.
	 */
	public function test_color_corto_se_expande_a_seis_digitos(): void {
		update_post_meta( 42, '_cead_acad_event_color', '#abc' );
		$this->assertSame( '#aabbcc', Cead_Acad_Schedule_CPT::event_color( 42 ) );
	}

	public function test_sin_tipo_ni_color_cae_en_evento(): void {
		$this->assertSame( Cead_Acad_Schedule_CPT::default_color( 'evento' ), Cead_Acad_Schedule_CPT::event_color( 999 ) );
	}
}
