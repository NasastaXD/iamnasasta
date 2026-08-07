<?php
/**
 * Markdown de la IA → HTML publicable.
 *
 * Los casos salen de artículos reales: CEADI escribe con negritas, listas
 * numeradas y tablas de partidos, y todo eso se publicaba con los asteriscos
 * y las barritas a la vista.
 */

use PHPUnit\Framework\TestCase;

final class ArticleFormatTest extends TestCase {

	private function fmt( $t ) {
		return Cead_Acad_Article_Format::to_html( $t );
	}

	/** El primer párrafo lleva la clase de bajada; el resto no. */
	public function test_primer_parrafo_es_la_bajada() {
		$html = $this->fmt( "Primero.\n\nSegundo." );

		$this->assertStringContainsString( '<p class="cead-lead">Primero.</p>', $html );
		$this->assertStringContainsString( '<p>Segundo.</p>', $html );
	}

	public function test_negrita_y_cursiva() {
		$html = $this->fmt( 'Va el **fixture** y algo *importante* más.' );

		$this->assertStringContainsString( '<strong>fixture</strong>', $html );
		$this->assertStringContainsString( '<em>importante</em>', $html );
		$this->assertStringNotContainsString( '**', $html );
	}

	/** «**TURNO MAÑANA**» en su propia línea es un subtítulo, no un párrafo. */
	public function test_una_linea_toda_en_negrita_es_subtitulo() {
		$html = $this->fmt( "Intro.\n\n**TURNO MAÑANA**\n\nJuegan todos." );

		$this->assertStringContainsString( '<h2>TURNO MAÑANA</h2>', $html );
	}

	/** El h1 de la página ya es el título: los del cuerpo arrancan en h2. */
	public function test_titulos_con_almohadilla_arrancan_en_h2() {
		$this->assertStringContainsString( '<h2>Encabezado</h2>', $this->fmt( "# Encabezado\n\nTexto." ) );
		$this->assertStringContainsString( '<h2>Turno tarde</h2>', $this->fmt( "## Turno tarde\n\nTexto." ) );
		$this->assertStringContainsString( '<h3>Detalle</h3>', $this->fmt( "### Detalle\n\nTexto." ) );
	}

	/**
	 * Dos secciones equivalentes tienen que verse igual, aunque la IA marque
	 * una con ## y la otra con negrita.
	 */
	public function test_seccion_con_almohadilla_y_con_negrita_dan_el_mismo_nivel() {
		$con_almohadilla = $this->fmt( "## Turno mañana\n\nTexto." );
		$con_negrita     = $this->fmt( "**Turno mañana**\n\nTexto." );

		$this->assertStringContainsString( '<h2>Turno mañana</h2>', $con_almohadilla );
		$this->assertStringContainsString( '<h2>Turno mañana</h2>', $con_negrita );
	}

	public function test_lista_numerada() {
		$html = $this->fmt( "Partidos:\n\n1. Juventus vs BVB 05\n2. Arsenal vs Bayern" );

		$this->assertStringContainsString( '<ol>', $html );
		$this->assertStringContainsString( '<li>Juventus vs BVB 05</li>', $html );
		$this->assertStringContainsString( '</ol>', $html );
	}

	public function test_lista_con_vinetas() {
		$html = $this->fmt( "Modalidades:\n\n- Futsal\n- Vóley" );

		$this->assertStringContainsString( '<ul>', $html );
		$this->assertStringContainsString( '<li>Futsal</li>', $html );
	}

	/** Una lista pegada a otra no debe mezclarse. */
	public function test_lista_numerada_y_vinetas_no_se_mezclan() {
		$html = $this->fmt( "1. Uno\n2. Dos\n\n- Otra cosa" );

		$this->assertStringContainsString( '</ol>', $html );
		$this->assertStringContainsString( '<ul>', $html );
	}

	public function test_separador() {
		$html = $this->fmt( "Arriba.\n\n---\n\nAbajo." );
		$this->assertStringContainsString( '<hr class="cead-sep">', $html );
	}

	public function test_cita() {
		$html = $this->fmt( '> Comenzamos con las clases normalmente.' );
		$this->assertStringContainsString( '<blockquote><p>Comenzamos con las clases normalmente.</p></blockquote>', $html );
	}

	/* ------------------------------ tablas -------------------------------- */

	/** La tabla del fixture: encabezado propio y filas de datos. */
	public function test_tabla_con_encabezado() {
		$md = "| # | Equipo | vs | Equipo | Modalidad |\n"
			. "|---|--------|----|----|-----------|\n"
			. "| 1 | Juventus | vs | BVB 05 | Voley masc. |\n"
			. "| 2 | Arsenal | vs | B. Leverkusen | Voley masc. |";

		$html = $this->fmt( $md );

		$this->assertStringContainsString( '<table>', $html );
		$this->assertStringContainsString( '<thead>', $html );
		$this->assertStringContainsString( '<th>Modalidad</th>', $html );
		$this->assertStringContainsString( '<td>Juventus</td>', $html );
		$this->assertStringContainsString( '<td>B. Leverkusen</td>', $html );
		// La fila de guiones es sintaxis, no contenido.
		$this->assertStringNotContainsString( '---', $html );
		$this->assertStringNotContainsString( '|', $html );
	}

	/** Una tabla sin fila de guiones sigue siendo tabla, sin encabezado. */
	public function test_tabla_sin_encabezado() {
		$html = $this->fmt( "| Juventus | BVB 05 |\n| Arsenal | Bayern |" );

		$this->assertStringContainsString( '<table>', $html );
		$this->assertStringNotContainsString( '<thead>', $html );
		$this->assertStringContainsString( '<td>Juventus</td>', $html );
	}

	/** Texto antes y después de la tabla no se pierde. */
	public function test_tabla_entre_parrafos() {
		$md = "Antes.\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\nDespués.";
		$html = $this->fmt( $md );

		$this->assertStringContainsString( 'Antes.', $html );
		$this->assertStringContainsString( '<table>', $html );
		$this->assertStringContainsString( '<p>Después.</p>', $html );
	}

	/* ------------------------------ seguridad ----------------------------- */

	/** Nada de HTML crudo colado desde el texto de la IA. */
	public function test_escapa_el_html_del_texto() {
		$html = $this->fmt( 'Cuidado con <script>alert(1)</script> acá.' );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/** Un contenido que YA es HTML se deja como está. */
	public function test_no_reprocesa_html() {
		$original = '<p>Esto ya vino maquetado.</p>';
		$this->assertSame( $original, $this->fmt( $original ) );
	}

	public function test_texto_vacio() {
		$this->assertSame( '', $this->fmt( '' ) );
		$this->assertSame( '', $this->fmt( "  \n  " ) );
	}

	/** Un enlace en markdown se convierte; una URL suelta también. */
	public function test_enlaces() {
		$html = $this->fmt( 'Mirá [el sitio](https://cead.caaguazu.net) ahora.' );
		$this->assertStringContainsString( '<a href="https://cead.caaguazu.net">el sitio</a>', $html );

		$html = $this->fmt( 'Entrá a https://cead.caaguazu.net ya.' );
		$this->assertStringContainsString( '<a href="https://cead.caaguazu.net"', $html );
	}

	/** Las líneas sueltas de un mismo párrafo se juntan. */
	public function test_junta_las_lineas_de_un_parrafo() {
		$html = $this->fmt( "Una frase\ncortada en dos líneas." );
		$this->assertStringContainsString( '<p class="cead-lead">Una frase cortada en dos líneas.</p>', $html );
	}

	/* --------------------------- plantillas por tipo ---------------------- */

	/** Cada categoría con molde propio aporta su línea. */
	public function test_las_plantillas_salen_por_categoria() {
		$hint = Cead_Acad_Article_Format::templates_hint( [ 'Deportes', 'Avisos' ] );

		$this->assertStringContainsString( 'Deportes:', $hint );
		$this->assertStringContainsString( 'Avisos:', $hint );
		// A deportes le corresponde la tabla de partidos.
		$this->assertStringContainsString( 'tabla', $hint );
	}

	/** Una categoría inventada por el colegio no rompe nada: se omite. */
	public function test_categoria_sin_molde_se_omite() {
		$hint = Cead_Acad_Article_Format::templates_hint( [ 'Deportes', 'Kermesse' ] );

		$this->assertStringContainsString( 'Deportes:', $hint );
		$this->assertStringNotContainsString( 'Kermesse', $hint );
	}

	/** Sin ninguna categoría conocida no se le manda ruido al modelo. */
	public function test_sin_categorias_conocidas_no_hay_plantilla() {
		$this->assertSame( '', Cead_Acad_Article_Format::templates_hint( [ 'Kermesse' ] ) );
		$this->assertSame( '', Cead_Acad_Article_Format::templates_hint( [] ) );
	}

	/** El nombre con acentos tiene que matchear igual con su slug. */
	public function test_matchea_por_slug_con_acentos() {
		$hint = Cead_Acad_Article_Format::templates_hint( [ 'Académico' ] );
		$this->assertStringContainsString( 'Académico:', $hint );
	}
}
