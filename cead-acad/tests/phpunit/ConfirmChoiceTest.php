<?php
/**
 * Respuesta a una propuesta de CEADI (1 publicar / 2 editar / 3 cancelar) y
 * vista previa del contenido propuesto.
 *
 * Casos sacados de conversaciones reales: la gente contesta «2.» con punto, o
 * pega el número y el cambio en el mismo mensaje.
 */

use PHPUnit\Framework\TestCase;

final class ConfirmChoiceTest extends TestCase {

	/* --------------------------- parse_confirm_choice --------------------- */

	public function test_numero_solo() {
		$this->assertSame( [ 1, '' ], Cead_Acad_WA_Engine::parse_confirm_choice( '1' ) );
		$this->assertSame( [ 2, '' ], Cead_Acad_WA_Engine::parse_confirm_choice( '2' ) );
		$this->assertSame( [ 3, '' ], Cead_Acad_WA_Engine::parse_confirm_choice( '3' ) );
	}

	/** Con espacios o saltos alrededor sigue siendo el número solo. */
	public function test_numero_con_espacios() {
		$this->assertSame( [ 1, '' ], Cead_Acad_WA_Engine::parse_confirm_choice( "  1 \n" ) );
	}

	/** «2.» con punto: antes caía en «Elegí 1, 2 o 3». */
	public function test_numero_con_puntuacion() {
		$this->assertSame( [ 2, '' ], Cead_Acad_WA_Engine::parse_confirm_choice( '2.' ) );
		$this->assertSame( [ 2, '' ], Cead_Acad_WA_Engine::parse_confirm_choice( '2)' ) );
		$this->assertSame( [ 1, '' ], Cead_Acad_WA_Engine::parse_confirm_choice( '1,' ) );
	}

	/** «2» y el cambio en el mismo mensaje: el resto es la instrucción. */
	public function test_editar_con_instruccion_pegada() {
		$this->assertSame(
			[ 2, 'agregale la fecha' ],
			Cead_Acad_WA_Engine::parse_confirm_choice( '2 agregale la fecha' )
		);
		$this->assertSame(
			[ 2, 'Pudiste leer el word que te pase?' ],
			Cead_Acad_WA_Engine::parse_confirm_choice( "2.\n\nPudiste leer el word que te pase?" )
		);
	}

	/** También en palabras. */
	public function test_editar_en_palabras() {
		$this->assertSame( [ 2, '' ], Cead_Acad_WA_Engine::parse_confirm_choice( 'editar' ) );
		$this->assertSame( [ 2, 'el título' ], Cead_Acad_WA_Engine::parse_confirm_choice( 'cambiar el título' ) );
	}

	/** Publicar y cancelar sí se aceptan por palabra, pero solo solas. */
	public function test_publicar_y_cancelar_en_palabras() {
		$this->assertSame( [ 1, '' ], Cead_Acad_WA_Engine::parse_confirm_choice( 'dale' ) );
		$this->assertSame( [ 1, '' ], Cead_Acad_WA_Engine::parse_confirm_choice( 'SÍ' ) );
		$this->assertSame( [ 3, '' ], Cead_Acad_WA_Engine::parse_confirm_choice( 'cancelar' ) );
	}

	/**
	 * «1, pero cambiale el título» NO es un sí: se devuelve el resto para que
	 * el motor pregunte, en vez de publicar algo que no era lo pedido.
	 */
	public function test_publicar_con_texto_pegado_no_se_asume() {
		$this->assertSame(
			[ 1, 'pero cambiale el título' ],
			Cead_Acad_WA_Engine::parse_confirm_choice( '1, pero cambiale el título' )
		);
	}

	public function test_texto_suelto_no_es_una_opcion() {
		$this->assertSame( [ 0, 'hola' ], Cead_Acad_WA_Engine::parse_confirm_choice( 'hola' ) );
		$this->assertSame( [ 0, '' ], Cead_Acad_WA_Engine::parse_confirm_choice( '' ) );
	}

	/** Un número que no es opción no se toma como tal. */
	public function test_otros_numeros_no_son_opcion() {
		$this->assertSame( [ 0, '7' ], Cead_Acad_WA_Engine::parse_confirm_choice( '7' ) );
	}

	/* ------------------------------ preview_text -------------------------- */

	/** Un texto corto se muestra tal cual, sin agregarle nada. */
	public function test_preview_no_toca_los_textos_cortos() {
		$corto = 'Un artículo breve.';
		$this->assertSame( $corto, Cead_Acad_WA_Engine::preview_text( $corto ) );
	}

	/**
	 * Uno largo se corta, pero avisando: antes terminaba a mitad de palabra y
	 * parecía que el artículo había salido cortado.
	 */
	public function test_preview_avisa_cuando_corta() {
		$largo = str_repeat( 'palabra ', 400 );
		$vista = Cead_Acad_WA_Engine::preview_text( $largo );

		$this->assertLessThan( mb_strlen( $largo ), mb_strlen( $vista ) );
		$this->assertStringContainsString( 'Vista previa cortada', $vista );
		$this->assertStringContainsString( 'se publica entero', $vista );
	}

	/** El corte cae en un espacio, no en el medio de una palabra. */
	public function test_preview_corta_en_una_palabra_entera() {
		$largo = str_repeat( 'incomprensibilidades ', 100 );
		$vista = Cead_Acad_WA_Engine::preview_text( $largo );
		$cuerpo = explode( "\n\n", $vista )[0];

		$this->assertStringEndsWith( 'incomprensibilidades', rtrim( $cuerpo ) );
	}
}
