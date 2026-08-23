<?php
/**
 * Un modelo distinto según lo que se le pide a CEADI.
 *
 * Decirle a alguien a qué hora tiene Matemática es buscar un dato y ordenarlo
 * en una frase. Redactar la nota de un acto escolar que se publica en el sitio,
 * con la voz institucional y eligiendo categoría, es otro trabajo. Usar el
 * mismo modelo para las dos cosas es pagar el caro para contestar horarios, o
 * escribir las notas del colegio con el barato.
 *
 * Lo que estos tests protegen no es el ruteo en sí (es un `get_option`) sino
 * las dos formas en que esto se rompe callado:
 *   1. Que dejar las casillas vacías cambie algo. Es opt-in: quien no toca
 *      nada tiene que seguir exactamente como estaba.
 *   2. Que el nivel de dificultad le gane al modelo de visión. Un modelo sin
 *      ojos no puede mirar una foto por más potente que sea.
 */

use PHPUnit\Framework\TestCase;

final class AiModeloPorTareaTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
		cead_test_set_option( 'cead_acad_wa_ai_model', 'modelo-general' );
	}

	/* --------------------------- el opt-in ---------------------------------- */

	/**
	 * Sin configurar nada, las tres tareas usan el modelo general. Si esto
	 * fallara, actualizar el plugin cambiaría solo el modelo con el que CEADI
	 * le contesta a todo el colegio.
	 *
	 * @dataProvider lasTresTareas
	 */
	public function test_sin_configurar_todo_usa_el_modelo_general( string $tarea ): void {
		$this->assertSame( 'modelo-general', Cead_Acad_WA_AI::model_para( $tarea ) );
	}

	public static function lasTresTareas(): array {
		return [
			'día a día' => [ Cead_Acad_WA_AI::TAREA_CHARLA ],
			'gestión'   => [ Cead_Acad_WA_AI::TAREA_GESTION ],
			'redacción' => [ Cead_Acad_WA_AI::TAREA_REDACCION ],
		];
	}

	/** Una casilla vacía no apaga el modelo: cae al general. */
	public function test_una_casilla_vacia_cae_al_general(): void {
		cead_test_set_option( 'cead_acad_wa_ai_model_redaccion', '' );

		$this->assertSame( 'modelo-general', Cead_Acad_WA_AI::model_para( Cead_Acad_WA_AI::TAREA_REDACCION ) );
	}

	/* --------------------------- el ruteo ----------------------------------- */

	public function test_cada_tarea_usa_su_modelo(): void {
		cead_test_set_option( 'cead_acad_wa_ai_model_charla', 'modelo-chico' );
		cead_test_set_option( 'cead_acad_wa_ai_model_gestion', 'modelo-medio' );
		cead_test_set_option( 'cead_acad_wa_ai_model_redaccion', 'modelo-grande' );

		$this->assertSame( 'modelo-chico',  Cead_Acad_WA_AI::model_para( Cead_Acad_WA_AI::TAREA_CHARLA ) );
		$this->assertSame( 'modelo-medio',  Cead_Acad_WA_AI::model_para( Cead_Acad_WA_AI::TAREA_GESTION ) );
		$this->assertSame( 'modelo-grande', Cead_Acad_WA_AI::model_para( Cead_Acad_WA_AI::TAREA_REDACCION ) );
	}

	/** Configurar una sola no arrastra a las otras. */
	public function test_configurar_una_no_toca_las_demas(): void {
		cead_test_set_option( 'cead_acad_wa_ai_model_redaccion', 'modelo-grande' );

		$this->assertSame( 'modelo-grande', Cead_Acad_WA_AI::model_para( Cead_Acad_WA_AI::TAREA_REDACCION ) );
		$this->assertSame( 'modelo-general', Cead_Acad_WA_AI::model_para( Cead_Acad_WA_AI::TAREA_CHARLA ) );
		$this->assertSame( 'modelo-general', Cead_Acad_WA_AI::model_para( Cead_Acad_WA_AI::TAREA_GESTION ) );
	}

	/**
	 * Una tarea que no existe cae al modelo general en vez de romper. Importa
	 * porque el nombre de la tarea lo escribe quien llama: un typo tiene que
	 * degradar a «el de siempre», no dejar a CEADI sin modelo.
	 */
	public function test_una_tarea_desconocida_cae_al_general(): void {
		cead_test_set_option( 'cead_acad_wa_ai_model_charla', 'modelo-chico' );

		$this->assertSame( 'modelo-general', Cead_Acad_WA_AI::model_para( 'inventada' ) );
		$this->assertSame( 'modelo-general', Cead_Acad_WA_AI::model_para( '' ) );
	}

	/* ------------------- la visión le gana a la dificultad ------------------ */

	/**
	 * Con una foto adjunta manda el modelo de visión, no el de la tarea.
	 *
	 * Este es el que se rompe callado. Si el nivel de dificultad le ganara a la
	 * visión, CEADI recibiría la foto con un modelo sin ojos: no fallaría con un
	 * error claro, contestaría cualquier cosa sobre una imagen que nunca vio. El
	 * null significa «que lo elija quien sabe de visión».
	 */
	public function test_con_imagen_manda_el_modelo_de_vision(): void {
		cead_test_set_option( 'cead_acad_wa_ai_key', 'sk-lo-que-sea' );
		cead_test_set_option( 'cead_acad_wa_vision_enabled', 1 );
		cead_test_set_option( 'cead_acad_wa_ai_model_charla', 'modelo-chico-sin-ojos' );

		$this->assertNull( Cead_Acad_WA_AI::modelo_para_turno( Cead_Acad_WA_AI::TAREA_CHARLA, true ) );
	}

	/** Sin imagen, la tarea manda como siempre. */
	public function test_sin_imagen_manda_la_tarea(): void {
		cead_test_set_option( 'cead_acad_wa_ai_key', 'sk-lo-que-sea' );
		cead_test_set_option( 'cead_acad_wa_vision_enabled', 1 );
		cead_test_set_option( 'cead_acad_wa_ai_model_charla', 'modelo-chico' );

		$this->assertSame( 'modelo-chico', Cead_Acad_WA_AI::modelo_para_turno( Cead_Acad_WA_AI::TAREA_CHARLA, false ) );
	}

	/**
	 * Con la lectura de imágenes APAGADA, una foto no cambia nada: no hay modelo
	 * de visión al que ir, así que sigue mandando el de la tarea.
	 */
	public function test_con_vision_apagada_la_imagen_no_cambia_el_modelo(): void {
		cead_test_set_option( 'cead_acad_wa_ai_key', 'sk-lo-que-sea' );
		cead_test_set_option( 'cead_acad_wa_vision_enabled', 0 );
		cead_test_set_option( 'cead_acad_wa_ai_model_charla', 'modelo-chico' );

		$this->assertSame( 'modelo-chico', Cead_Acad_WA_AI::modelo_para_turno( Cead_Acad_WA_AI::TAREA_CHARLA, true ) );
	}

	/* ------------------------- el catálogo ---------------------------------- */

	/**
	 * La pantalla de ajustes recorre este catálogo para dibujar las casillas y
	 * para guardarlas. Si una constante dejara de estar en la lista, su casilla
	 * desaparecería del formulario y el valor guardado quedaría huérfano:
	 * activo, invisible e imposible de cambiar desde el panel.
	 */
	public function test_el_catalogo_tiene_las_tres_constantes(): void {
		$tareas = Cead_Acad_WA_AI::tareas();

		$this->assertArrayHasKey( Cead_Acad_WA_AI::TAREA_CHARLA, $tareas );
		$this->assertArrayHasKey( Cead_Acad_WA_AI::TAREA_GESTION, $tareas );
		$this->assertArrayHasKey( Cead_Acad_WA_AI::TAREA_REDACCION, $tareas );
		$this->assertCount( 3, $tareas );

		foreach ( $tareas as $clave => $etiqueta ) {
			$this->assertNotSame( '', trim( (string) $etiqueta ), "La tarea «{$clave}» no tiene etiqueta." );
		}
	}

	/** Las claves tienen que sobrevivir a sanitize_key: son parte del nombre de la opción. */
	public function test_las_claves_sirven_como_nombre_de_opcion(): void {
		foreach ( array_keys( Cead_Acad_WA_AI::tareas() ) as $clave ) {
			$this->assertSame( $clave, sanitize_key( $clave ), "La clave «{$clave}» cambia al sanitizarse." );
		}
	}
}
