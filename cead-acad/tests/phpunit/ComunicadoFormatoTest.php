<?php
/**
 * El comunicado tiene dos vidas con un solo texto de origen: lo que llega al
 * chat de cada destinatario, tal cual, y lo que queda como post en el panel
 * web. Lo que se prueba acá es esa segunda vida — que no invente ni pierda
 * nada del mensaje, y que entienda la sintaxis de WhatsApp (`*negrita*`, un
 * asterisco) y no la de una nota del sitio (`**negrita**`, dos), que es
 * exactamente al revés y es donde estaba el bug: un comunicado con
 * `**CEAD Digital**` salía en el panel con los asteriscos a la vista, sin
 * convertir a nada, porque `the_content` no interpreta Markdown.
 */

use PHPUnit\Framework\TestCase;

final class ComunicadoFormatoTest extends TestCase {

	/* ------------------------- to_html_whatsapp() -------------------------- */

	public function test_negrita_de_whatsapp_con_un_asterisco(): void {
		$html = Cead_Acad_Article_Format::to_html_whatsapp( 'El CEAD pone a disposición *CEAD Digital*.' );

		$this->assertStringContainsString( '<strong>CEAD Digital</strong>', $html );
	}

	/**
	 * El caso que rompía antes: la IA escribía con la sintaxis de artículo
	 * (dos asteriscos) porque la herramienta le pasaba la misma guía de
	 * estilo que un artículo. Acá se prueba el otro lado del arreglo: el
	 * conversor de comunicados usa la sintaxis de WhatsApp, así que un doble
	 * asterisco NO es negrita para él — es justo la marca que ya no debería
	 * llegar nunca, pero si llegara, no tiene que desaparecer en silencio.
	 */
	public function test_doble_asterisco_no_es_la_sintaxis_de_whatsapp(): void {
		$html = Cead_Acad_Article_Format::to_html_whatsapp( 'Esto es **CEAD Digital** con dos asteriscos.' );

		$this->assertStringNotContainsString( '<strong>CEAD Digital</strong>', $html );
	}

	public function test_cursiva_y_tachado_de_whatsapp(): void {
		$html = Cead_Acad_Article_Format::to_html_whatsapp( 'Es _urgente_ y ya no es ~válido~.' );

		$this->assertStringContainsString( '<em>urgente</em>', $html );
		$this->assertStringContainsString( '<del>válido</del>', $html );
	}

	/**
	 * Un salto de línea simple es un renglón nuevo del MISMO mensaje —así se
	 * ve en WhatsApp—, no un párrafo aparte. Partirlo en dos `<p>` le cambia
	 * el ritmo al texto: dos líneas cortas pegadas se leen distinto que dos
	 * párrafos separados.
	 */
	public function test_un_salto_simple_es_br_no_parrafo_nuevo(): void {
		$html = Cead_Acad_Article_Format::to_html_whatsapp( "Primera línea.\nSegunda línea." );

		$this->assertSame( 1, substr_count( $html, '<p>' ) );
		$this->assertStringContainsString( 'Primera línea.<br>Segunda línea.', $html );
	}

	/** Una línea en blanco de por medio sí separa párrafos de verdad. */
	public function test_una_linea_en_blanco_si_separa_parrafos(): void {
		$html = Cead_Acad_Article_Format::to_html_whatsapp( "Primer párrafo.\n\nSegundo párrafo." );

		$this->assertSame( 2, substr_count( $html, '<p>' ) );
	}

	public function test_linkifica_urls_sueltas(): void {
		$html = Cead_Acad_Article_Format::to_html_whatsapp( 'Más info en https://cead.caaguazu.net/matriculas' );

		$this->assertStringContainsString( '<a href="https://cead.caaguazu.net/matriculas">', $html );
	}

	/**
	 * Sin encabezados ni tablas: un comunicado no es una nota. Un `##` que
	 * llegara igual (por error de la IA, o porque alguien lo tipeó a mano) se
	 * imprime como texto plano — WhatsApp tampoco lo interpreta, así que
	 * mostrarlo tal cual es justo lo consistente con lo que la gente recibió.
	 */
	public function test_no_interpreta_encabezados_de_markdown(): void {
		$html = Cead_Acad_Article_Format::to_html_whatsapp( "## No es un título\nEs solo texto." );

		$this->assertStringNotContainsString( '<h2', $html );
		$this->assertStringContainsString( '## No es un título', $html );
	}

	public function test_vacio_da_vacio(): void {
		$this->assertSame( '', Cead_Acad_Article_Format::to_html_whatsapp( '' ) );
		$this->assertSame( '', Cead_Acad_Article_Format::to_html_whatsapp( '   ' ) );
	}

	/** HTML ya maquetado (pegado a mano) no se reprocesa. */
	public function test_html_ya_armado_no_se_toca(): void {
		$html = '<p>Ya está en <strong>HTML</strong>.</p>';

		$this->assertSame( $html, Cead_Acad_Article_Format::to_html_whatsapp( $html ) );
	}

	/** Los símbolos del texto se escapan: nadie puede inyectar HTML por WhatsApp. */
	public function test_escapa_html_del_texto_plano(): void {
		$html = Cead_Acad_Article_Format::to_html_whatsapp( 'Ojo con <script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/* --------------------------- estilo_bloque() ---------------------------- */

	/**
	 * Las dos voces del estilo tienen reglas de sintaxis DISTINTAS y tienen
	 * que decirlo: una nota se lee en el sitio (`##`, `**negrita**`) y un
	 * comunicado se lee en WhatsApp (`*negrita*`, sin encabezados). Que las
	 * dos digan lo mismo sería el mismo bug que se está arreglando acá, solo
	 * que en el prompt en vez de en el HTML.
	 */
	public function test_el_bloque_de_comunicado_pide_sintaxis_de_whatsapp(): void {
		$bloque = Cead_Acad_WA_AI::estilo_bloque( 'comunicado' );

		$this->assertStringContainsString( 'un solo asterisco', $bloque );
		$this->assertStringContainsString( 'WhatsApp', $bloque );
	}

	public function test_el_bloque_de_articulo_no_menciona_whatsapp(): void {
		$bloque = Cead_Acad_WA_AI::estilo_bloque( 'articulo' );

		$this->assertStringNotContainsString( 'WhatsApp', $bloque );
	}

	/** El default (sin argumento) sigue siendo el de artículo — no cambia el resto de las llamadas ya existentes. */
	public function test_sin_argumento_es_el_de_articulo(): void {
		$this->assertSame( Cead_Acad_WA_AI::estilo_bloque(), Cead_Acad_WA_AI::estilo_bloque( 'articulo' ) );
	}
}
