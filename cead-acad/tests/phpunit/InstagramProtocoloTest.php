<?php
/**
 * El protocolo Instagram → borrador.
 *
 * Las dos piezas frágiles son las de acá: lo que devuelve el modelo (que es
 * texto y puede venir de cualquier forma) y lo que devuelve el proveedor de
 * Instagram (que es JSON ajeno y cada servicio lo arma distinto). El resto
 *  —crear el post, bajar las fotos— toca WordPress y queda para la otra suite.
 *
 * Lo que se prueba es sobre todo qué pasa cuando NO sale bien: un borrador que
 * sale mal armado se publica igual y queda en el sitio del colegio.
 */

use PHPUnit\Framework\TestCase;

/** Abre los métodos protegidos, que es donde vive la lógica que importa. */
final class IgEspia extends Cead_Acad_WA_Instagram {
	public static function leer( $texto, $pie ) { return self::interpretar( $texto, $pie ); }
	public static function fotos( array $m ) { return self::imagenes_del_proveedor( $m ); }
}

final class InstagramProtocoloTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
	}

	/* ------------------------- lo que devuelve el modelo ------------------- */

	public function test_lee_el_json_con_todos_los_campos(): void {
		$f = IgEspia::leer(
			'{"titulo":"Jornada deportiva","contenido":"Cuerpo de la nota.","categoria":"Deportes","formato":"logro","epigrafe":"El equipo"}',
			'pie original'
		);

		$this->assertSame( 'Jornada deportiva', $f['titulo'] );
		$this->assertSame( 'Cuerpo de la nota.', $f['contenido'] );
		$this->assertSame( 'El equipo', $f['epigrafe'] );
	}

	/**
	 * La mitad de los modelos devuelve el JSON envuelto en un bloque de código
	 * por más que se les pida lo contrario. Sin desenvolverlo, cada borrador
	 * caería al modo viejo y perdería categoría, maqueta y epígrafe — en
	 * silencio, porque el título y el cuerpo igual saldrían parecidos.
	 */
	public function test_desenvuelve_el_json_de_un_bloque_de_codigo(): void {
		$f = IgEspia::leer(
			"```json\n{\"titulo\":\"Con cerca\",\"contenido\":\"Cuerpo.\"}\n```",
			'pie'
		);

		$this->assertSame( 'Con cerca', $f['titulo'] );
		$this->assertSame( 'Cuerpo.', $f['contenido'] );
	}

	/** Y el que antepone una frase de cortesía antes del objeto. */
	public function test_ignora_la_charla_antes_del_json(): void {
		$f = IgEspia::leer(
			'¡Claro! Acá va: {"titulo":"Charlatan","contenido":"Cuerpo."} Espero que sirva.',
			'pie'
		);

		$this->assertSame( 'Charlatan', $f['titulo'] );
		$this->assertSame( 'Cuerpo.', $f['contenido'] );
	}

	/**
	 * Sin JSON usable se vuelve a la forma vieja: primera línea el título, el
	 * resto el cuerpo. Un borrador sin categoría sirve; ningún borrador, no.
	 */
	public function test_sin_json_cae_a_titulo_y_cuerpo(): void {
		$f = IgEspia::leer( "Título en prosa\n\nPrimer párrafo.\nSegundo párrafo.", 'pie' );

		$this->assertSame( 'Título en prosa', $f['titulo'] );
		$this->assertStringContainsString( 'Primer párrafo.', $f['contenido'] );
		$this->assertSame( 0, $f['categoria'] );
		$this->assertSame( '', $f['formato'] );
	}

	/** Un JSON al que le falta el cuerpo no es un JSON válido para esto. */
	public function test_un_json_incompleto_no_cuenta_como_json(): void {
		$f = IgEspia::leer( '{"titulo":"Solo título"}', 'el pie de foto' );

		$this->assertNotSame( 'Solo título', $f['contenido'] );
	}

	/**
	 * Una categoría inventada no se guarda.
	 *
	 * Sin categorías cargadas en el sitio, `resolver()` devuelve 0 — nunca crea
	 * una nueva. Que el modelo se invente «Actualidad» no puede ensuciar la
	 * taxonomía del colegio.
	 */
	public function test_una_categoria_que_no_existe_queda_en_cero(): void {
		$f = IgEspia::leer( '{"titulo":"T","contenido":"C","categoria":"Actualidad Internacional"}', 'pie' );

		$this->assertSame( 0, $f['categoria'] );
	}

	/**
	 * Un «evento» propuesto desde Instagram se cae a noticia.
	 *
	 * De un pie de foto no sale una fecha confiable, y la maqueta de evento sin
	 * fecha dibuja el bloque de fecha vacío. El veto ya vive en Article_Kind;
	 * este test comprueba que este camino pase por ahí y no escriba directo.
	 */
	public function test_un_evento_sin_fecha_no_llega_como_evento(): void {
		$f = IgEspia::leer( '{"titulo":"T","contenido":"C","formato":"evento"}', 'pie' );

		$this->assertSame( 'noticia', $f['formato'] );
	}

	public function test_una_maqueta_valida_si_pasa(): void {
		$f = IgEspia::leer( '{"titulo":"T","contenido":"C","formato":"logro"}', 'pie' );

		$this->assertSame( 'logro', $f['formato'] );
	}

	/**
	 * La corrección tiene una vara más alta que la redacción inicial.
	 *
	 * Si el modelo no devuelve JSON al pedirle un cambio, el modo viejo
	 * devolvería el pie crudo como cuerpo — o sea, pisaría el borrador con algo
	 * peor y contestaría «corregido». Ahí es preferible decir que no salió.
	 */
	public function test_una_correccion_sin_json_no_se_da_por_buena(): void {
		$this->assertNull( Cead_Acad_WA_Instagram::interpretar_publico( 'Ahí va', 'Ahí va' ) );
	}

	public function test_una_correccion_con_json_si_vale(): void {
		$f = Cead_Acad_WA_Instagram::interpretar_publico( '{"titulo":"Nuevo","contenido":"Cuerpo corregido."}', 'viejo' );

		$this->assertSame( 'Nuevo', $f['titulo'] );
	}

	/* ---------------------- lo que devuelve el proveedor ------------------- */

	/** Un carrusel: se toman las dos primeras, no las diez. */
	public function test_de_un_carrusel_toma_dos_imagenes(): void {
		$urls = IgEspia::fotos( [ 'images' => [
			[ 'url' => 'https://x/1.jpg' ], [ 'url' => 'https://x/2.jpg' ],
			[ 'url' => 'https://x/3.jpg' ], [ 'url' => 'https://x/4.jpg' ],
		] ] );

		$this->assertSame( [ 'https://x/1.jpg', 'https://x/2.jpg' ], $urls );
		$this->assertLessThanOrEqual( Cead_Acad_WA_Instagram::IMAGENES, count( $urls ) );
	}

	/** Una publicación de una sola foto también tiene que traer esa foto. */
	public function test_una_sola_imagen_suelta_tambien_entra(): void {
		$this->assertSame( [ 'https://x/uno.jpg' ], IgEspia::fotos( [ 'media_url' => 'https://x/uno.jpg' ] ) );
	}

	/** Cada proveedor las llama distinto; se aceptan los nombres comunes. */
	public function test_acepta_los_nombres_alternativos(): void {
		$this->assertSame( [ 'https://x/d.jpg' ], IgEspia::fotos( [ 'display_url' => 'https://x/d.jpg' ] ) );
		$this->assertSame( [ 'https://x/t.jpg' ], IgEspia::fotos( [ 'thumbnail' => 'https://x/t.jpg' ] ) );
	}

	/**
	 * La miniatura que repite la primera del carrusel no cuenta como segunda.
	 * Si no, la nota saldría con la misma foto arriba y en el cuerpo.
	 */
	public function test_no_repite_la_misma_url_dos_veces(): void {
		$urls = IgEspia::fotos( [
			'images'    => [ [ 'url' => 'https://x/1.jpg' ] ],
			'media_url' => 'https://x/1.jpg',
		] );

		$this->assertSame( [ 'https://x/1.jpg' ], $urls );
	}

	/** Sin ninguna clave conocida, sin fotos — y no una URL inventada. */
	public function test_sin_claves_conocidas_no_hay_imagenes(): void {
		$this->assertSame( [], IgEspia::fotos( [ 'foo' => 'bar' ] ) );
		$this->assertSame( [], IgEspia::fotos( [] ) );
	}
}
