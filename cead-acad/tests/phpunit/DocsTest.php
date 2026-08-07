<?php
/**
 * Lectura de documentos (PDF, Word, texto). Se arman archivos reales en
 * memoria y se comprueba que salga el texto esperado.
 */

use PHPUnit\Framework\TestCase;

final class DocsTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
	}

	protected function tearDown(): void {
		cead_test_reset_options();
	}

	/* ------------------------------- helpers ------------------------------ */

	/** Empaqueta un .docx mínimo (ZIP con word/document.xml). */
	private function make_docx( $document_xml ) {
		$tmp = tempnam( sys_get_temp_dir(), 'docxtest' );
		$zip = new ZipArchive();
		$zip->open( $tmp, ZipArchive::OVERWRITE );
		$zip->addFromString( 'word/document.xml', $document_xml );
		$zip->close();
		$bytes = file_get_contents( $tmp );
		unlink( $tmp );
		return $bytes;
	}

	/** Arma un PDF mínimo con un stream de texto comprimido en Flate. */
	private function make_pdf( array $lineas, $comprimir = true ) {
		$ops = '';
		foreach ( $lineas as $l ) {
			$ops .= 'BT /F1 12 Tf 72 700 Td (' . $l . ') Tj ET' . "\n";
		}
		$stream = $comprimir ? gzcompress( $ops ) : $ops;
		return "%PDF-1.4\n1 0 obj\n<< /Length " . strlen( $stream ) . " >>\nstream\n"
			. $stream . "\nendstream\nendobj\n%%EOF";
	}

	private function media( $mime, $bytes, $filename = '' ) {
		return [
			'mime'        => $mime,
			'filename'    => $filename,
			'data_base64' => base64_encode( $bytes ),
		];
	}

	/* ------------------------------- kind() ------------------------------- */

	public function test_reconoce_los_tipos_por_mime() {
		$this->assertSame( 'pdf',  Cead_Acad_WA_Docs::kind( [ 'mime' => 'application/pdf' ] ) );
		$this->assertSame( 'docx', Cead_Acad_WA_Docs::kind( [ 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ] ) );
		$this->assertSame( 'odt',  Cead_Acad_WA_Docs::kind( [ 'mime' => 'application/vnd.oasis.opendocument.text' ] ) );
		$this->assertSame( 'text', Cead_Acad_WA_Docs::kind( [ 'mime' => 'text/plain' ] ) );
	}

	/** WhatsApp manda muchos adjuntos sin mime: ahí vale la extensión. */
	public function test_reconoce_los_tipos_por_extension() {
		$this->assertSame( 'pdf',  Cead_Acad_WA_Docs::kind( [ 'filename' => 'Circular 03.PDF' ] ) );
		$this->assertSame( 'docx', Cead_Acad_WA_Docs::kind( [ 'filename' => 'nota.docx' ] ) );
		$this->assertSame( 'text', Cead_Acad_WA_Docs::kind( [ 'filename' => 'apuntes.txt' ] ) );
	}

	/** Una planilla NO es un documento de lectura: tiene su propio camino. */
	public function test_las_planillas_no_son_documentos() {
		$this->assertSame( '', Cead_Acad_WA_Docs::kind( [ 'filename' => 'notas.csv' ] ) );
		$this->assertSame( '', Cead_Acad_WA_Docs::kind( [ 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ] ) );
		$this->assertFalse( Cead_Acad_WA_Docs::is_document( [ 'filename' => 'notas.xlsx' ] ) );
	}

	/** Un audio o una imagen tampoco. */
	public function test_ignora_lo_que_no_es_documento() {
		$this->assertSame( '', Cead_Acad_WA_Docs::kind( [ 'mime' => 'image/jpeg' ] ) );
		$this->assertSame( '', Cead_Acad_WA_Docs::kind( [ 'mime' => 'audio/ogg' ] ) );
		$this->assertSame( '', Cead_Acad_WA_Docs::kind( null ) );
	}

	/* ------------------------------ extract() ----------------------------- */

	public function test_lee_un_txt() {
		$r = Cead_Acad_WA_Docs::extract( $this->media( 'text/plain', "Reunión el lunes.\nTraer carpeta.", 'aviso.txt' ) );

		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( 'Reunión el lunes.', $r['text'] );
		$this->assertStringContainsString( 'Traer carpeta.', $r['text'] );
	}

	public function test_lee_un_docx_y_respeta_los_parrafos() {
		$xml = '<?xml version="1.0"?><w:document><w:body>'
			. '<w:p><w:r><w:t>Circular 03/2026</w:t></w:r></w:p>'
			. '<w:p><w:r><w:t>Se suspenden las clases el viernes.</w:t></w:r></w:p>'
			. '</w:body></w:document>';

		$r = Cead_Acad_WA_Docs::extract( $this->media(
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			$this->make_docx( $xml ),
			'circular.docx'
		) );

		$this->assertTrue( $r['ok'], 'El .docx tiene que poder leerse.' );
		$this->assertStringContainsString( 'Circular 03/2026', $r['text'] );
		$this->assertStringContainsString( 'Se suspenden las clases el viernes.', $r['text'] );
		// Sin el manejo de </w:p> los dos párrafos quedarían pegados.
		$this->assertStringNotContainsString( '2026Se suspenden', $r['text'] );
	}

	/** Word parte una frase en varios <w:t>; al unirlos no deben quedar huecos. */
	public function test_docx_une_los_fragmentos_de_una_misma_linea() {
		$xml = '<w:document><w:body><w:p>'
			. '<w:r><w:t>Reunión de </w:t></w:r><w:r><w:t>padres</w:t></w:r>'
			. '</w:p></w:body></w:document>';

		$r = Cead_Acad_WA_Docs::extract( $this->media(
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			$this->make_docx( $xml ),
			'nota.docx'
		) );

		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( 'Reunión de padres', $r['text'] );
	}

	public function test_lee_un_pdf_de_texto() {
		$r = Cead_Acad_WA_Docs::extract( $this->media(
			'application/pdf',
			$this->make_pdf( [ 'Resolucion 12', 'Acto del 14 de mayo' ] ),
			'resolucion.pdf'
		) );

		$this->assertTrue( $r['ok'], 'Un PDF con texto tiene que poder leerse.' );
		$this->assertStringContainsString( 'Resolucion 12', $r['text'] );
		$this->assertStringContainsString( 'Acto del 14 de mayo', $r['text'] );
	}

	/** También los PDF cuyo stream no viene comprimido. */
	public function test_lee_un_pdf_sin_comprimir() {
		$r = Cead_Acad_WA_Docs::extract( $this->media(
			'application/pdf',
			$this->make_pdf( [ 'Sin comprimir' ], false ),
			'plano.pdf'
		) );

		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( 'Sin comprimir', $r['text'] );
	}

	/** Los paréntesis escapados no deben cortar el texto antes de tiempo. */
	public function test_pdf_respeta_parentesis_escapados() {
		$r = Cead_Acad_WA_Docs::extract( $this->media(
			'application/pdf',
			$this->make_pdf( [ 'Turno manana \\(TM\\) y tarde' ] ),
			'turnos.pdf'
		) );

		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( '(TM)', $r['text'] );
		$this->assertStringContainsString( 'y tarde', $r['text'] );
	}

	/**
	 * Word escribe el texto en arrays TJ con ajustes de espaciado entre medio.
	 * Hay que juntar todos los pedazos, no solo el último.
	 */
	public function test_pdf_junta_los_pedazos_de_un_array_tj() {
		$ops    = "BT /F1 12 Tf [(Reu) -250 (nion de pa) 15 (dres)] TJ ET\n";
		$stream = gzcompress( $ops );
		$pdf    = "%PDF-1.4\n1 0 obj\n<< /Length " . strlen( $stream ) . " >>\nstream\n"
			. $stream . "\nendstream\nendobj\n%%EOF";

		$r = Cead_Acad_WA_Docs::extract( $this->media( 'application/pdf', $pdf, 'word.pdf' ) );

		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( 'Reunion de padres', $r['text'] );
	}

	/**
	 * Un PDF escaneado es una imagen adentro de un PDF: no hay texto. Tiene que
	 * fallar con un motivo claro, porque es EL caso que hay que saber explicar.
	 */
	public function test_pdf_escaneado_avisa_que_no_tiene_texto() {
		$pdf = "%PDF-1.4\n1 0 obj\n<< /Subtype /Image >>\nstream\n"
			. "\x89PNG\x0d\x0a\x1a\x0a datos binarios de la imagen \nendstream\nendobj\n%%EOF";

		$r = Cead_Acad_WA_Docs::extract( $this->media( 'application/pdf', $pdf, 'escaneo.pdf' ) );

		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'escaneo', strtolower( $r['reason'] ) );
	}

	public function test_rechaza_archivos_vacios_o_danados() {
		$vacio = Cead_Acad_WA_Docs::extract( [ 'mime' => 'application/pdf', 'data_base64' => '' ] );
		$this->assertFalse( $vacio['ok'] );

		$noEsDoc = Cead_Acad_WA_Docs::extract( $this->media( 'image/jpeg', 'x', 'foto.jpg' ) );
		$this->assertFalse( $noEsDoc['ok'] );
	}

	/** Un documento larguísimo se corta, avisando, en vez de reventar el prompt. */
	public function test_corta_los_documentos_muy_largos() {
		$largo = str_repeat( 'palabra ', 40000 );
		$r = Cead_Acad_WA_Docs::extract( $this->media( 'text/plain', $largo, 'largo.txt' ) );

		$this->assertTrue( $r['ok'] );
		$this->assertLessThan( mb_strlen( $largo ), mb_strlen( $r['text'] ) );
		$this->assertStringContainsString( 'se leyó solo el principio', $r['text'] );
	}

	/** La lectura de documentos no se enciende sola. */
	public function test_enabled_apagado_por_defecto() {
		$this->assertFalse( Cead_Acad_WA_Docs::enabled() );
		cead_test_set_option( 'cead_acad_wa_docs_enabled', 1 );
		$this->assertTrue( Cead_Acad_WA_Docs::enabled() );
	}
}
