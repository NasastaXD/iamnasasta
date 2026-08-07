<?php
/**
 * Lectura de imágenes (visión): armado del bloque que se manda al modelo y
 * resolución del modelo a usar. Lógica pura, sin llamadas de red.
 */

use PHPUnit\Framework\TestCase;

final class VisionTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
	}

	protected function tearDown(): void {
		cead_test_reset_options();
	}

	/** Una imagen normal se convierte en un data URI con su mime. */
	public function test_image_block_arma_data_uri() {
		$block = Cead_Acad_WA_AI::image_block( [
			'mime'        => 'image/jpeg',
			'data_base64' => 'QUJD', // "ABC"
		] );

		$this->assertIsArray( $block );
		$this->assertSame( 'image_url', $block['type'] );
		$this->assertSame( 'data:image/jpeg;base64,QUJD', $block['image_url']['url'] );
	}

	/** El mime se normaliza a minúsculas y no rompe el prefijo del data URI. */
	public function test_image_block_normaliza_mime() {
		$block = Cead_Acad_WA_AI::image_block( [
			'mime'        => 'IMAGE/PNG',
			'data_base64' => 'QUJD',
		] );

		$this->assertIsArray( $block );
		$this->assertStringStartsWith( 'data:image/png;base64,', $block['image_url']['url'] );
	}

	/** Un audio o un PDF no son imágenes: no se mandan a mirar. */
	public function test_image_block_rechaza_lo_que_no_es_imagen() {
		$this->assertNull( Cead_Acad_WA_AI::image_block( [
			'mime'        => 'audio/ogg',
			'data_base64' => 'QUJD',
		] ) );
		$this->assertNull( Cead_Acad_WA_AI::image_block( [
			'mime'        => 'application/pdf',
			'data_base64' => 'QUJD',
		] ) );
	}

	/** Sin datos, sin mime, o directamente sin media, no hay bloque. */
	public function test_image_block_rechaza_entradas_vacias() {
		$this->assertNull( Cead_Acad_WA_AI::image_block( null ) );
		$this->assertNull( Cead_Acad_WA_AI::image_block( [] ) );
		$this->assertNull( Cead_Acad_WA_AI::image_block( [ 'mime' => 'image/jpeg' ] ) );
		$this->assertNull( Cead_Acad_WA_AI::image_block( [ 'data_base64' => 'QUJD' ] ) );
	}

	/** Pasado el tope de tamaño se descarta, en vez de reventar el turno. */
	public function test_image_block_rechaza_imagenes_gigantes() {
		$limite_b64 = (int) ceil( Cead_Acad_WA_AI::VISION_MAX_BYTES * 4 / 3 );

		$justo = Cead_Acad_WA_AI::image_block( [
			'mime'        => 'image/jpeg',
			'data_base64' => str_repeat( 'A', $limite_b64 ),
		] );
		$this->assertIsArray( $justo, 'Una imagen justo en el tope todavía se manda.' );

		$pasada = Cead_Acad_WA_AI::image_block( [
			'mime'        => 'image/jpeg',
			'data_base64' => str_repeat( 'A', $limite_b64 + 1 ),
		] );
		$this->assertNull( $pasada, 'Pasado el tope no se manda.' );
	}

	/** Sin modelo propio de visión se usa el mismo de la IA. */
	public function test_vision_model_cae_al_modelo_de_la_ia() {
		cead_test_set_option( 'cead_acad_wa_ai_model', 'un-modelo-de-texto' );
		$this->assertSame( 'un-modelo-de-texto', Cead_Acad_WA_AI::vision_model() );
	}

	/** Con modelo propio configurado, ese gana. */
	public function test_vision_model_usa_el_configurado() {
		cead_test_set_option( 'cead_acad_wa_ai_model', 'un-modelo-de-texto' );
		cead_test_set_option( 'cead_acad_wa_vision_model', 'gpt-4o' );
		$this->assertSame( 'gpt-4o', Cead_Acad_WA_AI::vision_model() );
	}

	/** Un modelo cargado con espacios de más no debe pasar como "configurado". */
	public function test_vision_model_ignora_espacios() {
		cead_test_set_option( 'cead_acad_wa_ai_model', 'un-modelo-de-texto' );
		cead_test_set_option( 'cead_acad_wa_vision_model', '   ' );
		$this->assertSame( 'un-modelo-de-texto', Cead_Acad_WA_AI::vision_model() );
	}

	/** La visión no se activa sola: hace falta el check Y una API key. */
	public function test_vision_enabled_necesita_check_y_key() {
		$this->assertFalse( Cead_Acad_WA_AI::vision_enabled(), 'Apagada por defecto.' );

		cead_test_set_option( 'cead_acad_wa_vision_enabled', 1 );
		$this->assertFalse( Cead_Acad_WA_AI::vision_enabled(), 'Activada pero sin key: no alcanza.' );

		cead_test_set_option( 'cead_acad_wa_ai_key', 'sk-lo-que-sea' );
		$this->assertTrue( Cead_Acad_WA_AI::vision_enabled() );
	}
}
