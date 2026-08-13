<?php
/**
 * El estilo de redacción y la generación de imágenes.
 *
 * Las dos cosas comparten un mismo riesgo: son opciones de wp-admin que, mal
 * resueltas, fallan hacia el lado silencioso. Un estilo que se cae deja las
 * notas escritas como mensajes de chat sin que nadie vea un error; una
 * generación de imágenes que se cree activa sin clave gasta un turno entero
 * para terminar en un 401 del proveedor.
 */

use PHPUnit\Framework\TestCase;

/** Abre el armado del prompt, que es lo que decide cómo sale la imagen. */
final class ImagenesEspia extends Cead_Acad_WA_Images {
	public static function prompt( $desc, $texto = '' ) { return self::componer_prompt( $desc, $texto ); }
}

final class EstiloEImagenesTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
	}

	/* ------------------------- estilo de redacción ------------------------- */

	/**
	 * Vacío significa «usá el del plugin», no «sin estilo».
	 *
	 * Es el mismo criterio que la personalidad, y nace del mismo error: si vacío
	 * quisiera decir «ninguno», cualquier instalación que no hubiera tocado el
	 * campo publicaría notas sin ninguna guía de redacción.
	 */
	public function test_sin_estilo_guardado_se_usa_el_del_plugin(): void {
		$this->assertSame( Cead_Acad_WA_AI::default_estilo(), Cead_Acad_WA_AI::estilo() );
		$this->assertNotSame( '', trim( Cead_Acad_WA_AI::default_estilo() ) );
	}

	public function test_el_estilo_propio_pisa_al_del_plugin(): void {
		cead_test_set_option( 'cead_acad_wa_ai_estilo', 'Escribí todo en verso.' );

		$this->assertSame( 'Escribí todo en verso.', Cead_Acad_WA_AI::estilo() );
	}

	/**
	 * El estilo es una voz DISTINTA de la del chat, no la misma con otras
	 * palabras. Si terminaran diciendo lo mismo, tener dos campos sería puro
	 * ruido: la razón de separarlos es que se contradicen a propósito.
	 */
	public function test_el_estilo_y_la_personalidad_no_son_la_misma_voz(): void {
		$estilo   = Cead_Acad_WA_AI::default_estilo();
		$persona  = Cead_Acad_WA_AI::default_persona();

		$this->assertStringContainsString( 'Tercera persona', $estilo );
		$this->assertStringContainsString( 'voseo', $persona, 'La personalidad del chat sí usa voseo.' );
		$this->assertStringContainsString( 'sin voseo', $estilo, 'Lo que se publica NO.' );
		$this->assertNotSame( $persona, $estilo );
	}

	/**
	 * El bloque para el prompt trae encabezado, pero SOLO si hay algo debajo.
	 * Un «# CÓMO ESCRIBE EL COLEGIO» seguido de nada le anuncia al modelo que
	 * hay reglas y no se las muestra, que es peor que no decir nada.
	 */
	public function test_el_bloque_trae_encabezado_cuando_hay_estilo(): void {
		$this->assertStringContainsString( 'CÓMO ESCRIBE EL COLEGIO', Cead_Acad_WA_AI::estilo_bloque() );
	}

	public function test_un_estilo_en_blanco_no_deja_un_encabezado_huerfano(): void {
		cead_test_set_option( 'cead_acad_wa_ai_estilo', '   ' );

		// Con espacios, `estilo()` cae al del plugin (no está vacío de verdad),
		// así que el encabezado sigue teniendo contenido debajo.
		$this->assertStringContainsString( 'CÓMO ESCRIBE EL COLEGIO', Cead_Acad_WA_AI::estilo_bloque() );
		$this->assertGreaterThan( 100, strlen( Cead_Acad_WA_AI::estilo_bloque() ) );
	}

	/* --------------------------- generación de imágenes -------------------- */

	/** Apagada por defecto: el proveedor de chat del colegio no genera imágenes. */
	public function test_viene_apagada(): void {
		$this->assertFalse( Cead_Acad_WA_Images::enabled() );
	}

	/**
	 * Prendida pero sin ninguna clave NO cuenta como activa.
	 *
	 * Si contara, el modelo vería la herramienta, alguien pediría un flyer,
	 * confirmaría, esperaría el minuto de generación y recibiría un 401. La
	 * herramienta ni siquiera tiene que aparecer.
	 */
	public function test_prendida_sin_clave_sigue_sin_estar_activa(): void {
		cead_test_set_option( 'cead_acad_wa_img_enabled', 1 );

		$this->assertFalse( Cead_Acad_WA_Images::enabled() );
	}

	/** La clave del chat sirve: muchos proveedores dan texto e imagen con la misma. */
	public function test_hereda_la_clave_de_la_ia_de_texto(): void {
		cead_test_set_option( 'cead_acad_wa_img_enabled', 1 );
		cead_test_set_option( 'cead_acad_wa_ai_key', 'sk-compartida' );

		$this->assertSame( 'sk-compartida', Cead_Acad_WA_Images::key() );
		$this->assertTrue( Cead_Acad_WA_Images::enabled() );
	}

	/** Y la propia manda sobre la heredada. */
	public function test_la_clave_propia_gana(): void {
		cead_test_set_option( 'cead_acad_wa_ai_key', 'sk-texto' );
		cead_test_set_option( 'cead_acad_wa_img_key', 'sk-imagen' );

		$this->assertSame( 'sk-imagen', Cead_Acad_WA_Images::key() );
	}

	/**
	 * Un tamaño que el proveedor no acepta devuelve 400 y quema el pedido. Si
	 * la opción quedó con basura (una migración, un valor viejo), se cae al
	 * cuadrado en vez de mandarlo tal cual.
	 */
	public function test_un_tamano_invalido_cae_al_por_defecto(): void {
		cead_test_set_option( 'cead_acad_wa_img_size', '800x600' );

		$this->assertSame( Cead_Acad_WA_Images::SIZE_DEFAULT, Cead_Acad_WA_Images::size() );
	}

	public function test_un_tamano_valido_se_respeta(): void {
		cead_test_set_option( 'cead_acad_wa_img_size', '1024x1536' );

		$this->assertSame( '1024x1536', Cead_Acad_WA_Images::size() );
	}

	/* ----------------------------- el prompt ------------------------------- */

	/** La guía visual va siempre: es lo que hace que las piezas se parezcan. */
	public function test_el_estilo_grafico_se_pega_al_pedido(): void {
		$p = ImagenesEspia::prompt( 'Un patio de colegio con banderines' );

		$this->assertStringContainsString( 'Un patio de colegio con banderines', $p );
		$this->assertStringContainsString( '#E93B3C', $p );
	}

	/**
	 * El texto del flyer va aparte y entrecomillado.
	 *
	 * Mezclado con la descripción de la escena, los modelos de imagen escriben
	 * mal: letras de más, palabras inventadas. Un flyer del colegio con una
	 * falta de ortografía es peor que no tener flyer.
	 */
	public function test_el_texto_del_flyer_va_aparte_y_literal(): void {
		$p = ImagenesEspia::prompt( 'Fondo rojo con formas geométricas', 'ACTO DEL 14 DE MAYO' );

		$this->assertStringContainsString( '«ACTO DEL 14 DE MAYO»', $p );
		$this->assertStringContainsString( 'sin faltas de ortografía', $p );
	}

	/** Sin texto se lo dice explícitamente, para que no invente un titular. */
	public function test_sin_texto_se_pide_una_imagen_sin_texto(): void {
		$p = ImagenesEspia::prompt( 'Una biblioteca escolar' );

		$this->assertStringContainsString( 'Sin texto dentro de la imagen', $p );
	}

	/** Con la generación apagada, pedirla falla antes de gastar un pedido. */
	public function test_apagada_no_llega_a_llamar_al_proveedor(): void {
		$r = Cead_Acad_WA_Images::generar( 'lo que sea' );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'cead_img_off', $r->get_error_code() );
	}

	/** Y una descripción vacía tampoco: no hay nada que dibujar. */
	public function test_sin_descripcion_no_se_genera_nada(): void {
		cead_test_set_option( 'cead_acad_wa_img_enabled', 1 );
		cead_test_set_option( 'cead_acad_wa_img_key', 'sk-x' );

		$r = Cead_Acad_WA_Images::generar( '   ' );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'cead_img_vacio', $r->get_error_code() );
	}

	/**
	 * La acción está en la lista de las que ESCRIBEN.
	 *
	 * Esa lista es lo que decide qué exige aprobación humana. Si `generar_imagen`
	 * no estuviera, el panel web la trataría como una consulta y la ejecutaría
	 * sin preguntar — gastando plata sin que nadie confirme.
	 */
	public function test_generar_imagen_exige_aprobacion_humana(): void {
		$this->assertContains( 'generar_imagen', Cead_Acad_WA_Tools::GESTION );
	}
}
