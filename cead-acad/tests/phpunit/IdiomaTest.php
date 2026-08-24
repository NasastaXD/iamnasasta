<?php
/**
 * CEADI entiende guaraní pero contesta siempre en castellano.
 *
 * No es una decisión estética. En el colegio nadie puede revisar si lo que el
 * modelo escribe en guaraní está bien: si contestara en guaraní, estaría
 * publicando en nombre del CEAD un texto que nadie del colegio puede corregir.
 * Entender es gratis y no tiene ese riesgo; escribir, sí.
 *
 * Este test es un guardián contra la deriva. La personalidad es un texto largo
 * que se reescribe cada tanto, y una regla que se cae de ahí no rompe nada
 * visible: CEADI simplemente empieza, un día, a contestarle en guaraní a quien
 * le escriba en guaraní, y nadie se entera hasta que sale mal.
 */

use PHPUnit\Framework\TestCase;

final class IdiomaTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
	}

	/** La regla tiene que estar, y tiene que decir las dos mitades. */
	public function test_la_personalidad_fija_el_idioma(): void {
		$p = Cead_Acad_WA_AI::default_persona();

		$this->assertStringContainsString( 'guaraní', $p, 'La personalidad ni menciona el guaraní.' );
		$this->assertMatchesRegularExpression(
			'/respondés siempre en castellano/i',
			$p,
			'Falta la mitad que importa: que la respuesta va en castellano.'
		);
	}

	/**
	 * Entender NO puede quedar prohibido junto con responder. Si la regla se
	 * escribiera como «no uses guaraní» a secas, el modelo podría deducir que
	 * tampoco tiene que entenderlo, y un alumno que escribe mezclando quedaría
	 * sin respuesta útil — que es lo contrario de lo que se buscaba.
	 */
	public function test_entender_guarani_sigue_permitido(): void {
		$p = Cead_Acad_WA_AI::default_persona();

		$this->assertMatchesRegularExpression( '/entendés guaraní/i', $p );
	}

	/**
	 * La regla también vale para lo que se PUBLICA. El material de origen de una
	 * nota (un pie de foto de Instagram, una nota de voz) puede venir en guaraní
	 * o mezclado, y ahí el texto queda en el sitio del colegio para siempre.
	 */
	public function test_el_estilo_de_redaccion_tambien_fija_el_castellano(): void {
		$e = Cead_Acad_WA_AI::default_estilo();

		$this->assertStringContainsString( 'castellano', $e );
		$this->assertStringContainsString( 'guaraní', $e );
	}

	/* --------------------- el contrapeso de la cautela ---------------------- */

	/**
	 * La personalidad tiene varias reglas para no afirmar de más. Necesita la
	 * contraria, y por un motivo que se olvida: los dos errores no se ven igual.
	 * Cuando el bot inventa algo, alguien lo nota y lo corrige; cuando se calla,
	 * no pasa nada visible y parece que funcionó. Sin un contrapeso explícito,
	 * callarse termina siendo siempre la opción cómoda y el asistente deja de
	 * servir para lo que lo pusieron.
	 *
	 * Es justo el tipo de sección que desaparece en una reescritura «para
	 * acortar», porque suena a permiso y no a regla.
	 */
	public function test_la_personalidad_compensa_el_exceso_de_cautela(): void {
		$p = Cead_Acad_WA_AI::default_persona();

		$this->assertMatchesRegularExpression( '/callarse también cuesta/iu', $p );
		$this->assertStringContainsString( 'sin rodeos', $p );
	}

	/**
	 * Y el contrapeso NO puede arrastrarse las reglas duras. Aflojar la cautela
	 * es decir lo que SÍ se sabe, no empezar a inventar ni a mostrar datos
	 * ajenos: si esa distinción se pierde, la sección se vuelve peligrosa.
	 */
	public function test_el_contrapeso_no_toca_las_reglas_duras(): void {
		$p = Cead_Acad_WA_AI::default_persona();

		$this->assertMatchesRegularExpression( '/no inventar datos/iu', $p );
		$this->assertMatchesRegularExpression( '/no saltear permisos/iu', $p );
	}

	/**
	 * La personalidad de fábrica solo se usa si nadie cargó una propia. Si
	 * alguien escribe la suya en wp-admin, la regla del idioma se va con la
	 * vieja — así que esto documenta el punto donde se puede perder.
	 */
	public function test_una_personalidad_propia_reemplaza_a_la_de_fabrica(): void {
		cead_test_set_option( 'cead_acad_wa_ai_prompt', 'Sos otro asistente.' );

		$this->assertSame( 'Sos otro asistente.', Cead_Acad_WA_AI::persona() );
	}

	public function test_sin_personalidad_propia_se_usa_la_de_fabrica(): void {
		$this->assertSame( Cead_Acad_WA_AI::default_persona(), Cead_Acad_WA_AI::persona() );
	}
}
