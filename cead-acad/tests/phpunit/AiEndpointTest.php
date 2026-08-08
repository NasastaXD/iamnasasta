<?php
/**
 * Endpoint de la IA: por defecto se manda tal cual se cargó, pero algunos
 * proveedores no aceptan la ruta completa (`/chat/completions`) y devuelven
 * 405 si se les pega directo ahí — quieren solo la base. El interruptor
 * `endpoint_is_base` decide si hay que completar la ruta antes de usarla.
 */

use PHPUnit\Framework\TestCase;

class AiEndpointTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
	}

	public function test_sin_nada_cargado_usa_el_default_de_deepseek() {
		$this->assertSame( Cead_Acad_WA_AI::ENDPOINT_DEFAULT, Cead_Acad_WA_AI::endpoint() );
	}

	/** Comportamiento de siempre: lo cargado se usa tal cual, sin tocarlo. */
	public function test_endpoint_completo_se_manda_sin_modificar() {
		cead_test_set_option( 'cead_acad_wa_ai_endpoint', 'https://openrouter.ai/api/v1/chat/completions' );
		$this->assertSame( 'https://openrouter.ai/api/v1/chat/completions', Cead_Acad_WA_AI::endpoint() );
	}

	/** Con el interruptor activo, se le agrega /chat/completions a la base. */
	public function test_con_el_interruptor_completa_la_ruta() {
		cead_test_set_option( 'cead_acad_wa_ai_endpoint', 'https://mi-servicio.com' );
		cead_test_set_option( 'cead_acad_wa_ai_endpoint_is_base', 1 );
		$this->assertTrue( Cead_Acad_WA_AI::endpoint_is_base() );
		$this->assertSame( 'https://mi-servicio.com/chat/completions', Cead_Acad_WA_AI::endpoint() );
	}

	/** Una barra final en la base no debe duplicarse contra la ruta agregada. */
	public function test_con_barra_final_no_duplica_la_barra() {
		cead_test_set_option( 'cead_acad_wa_ai_endpoint', 'https://mi-servicio.com/' );
		cead_test_set_option( 'cead_acad_wa_ai_endpoint_is_base', 1 );
		$this->assertSame( 'https://mi-servicio.com/chat/completions', Cead_Acad_WA_AI::endpoint() );
	}

	/** Si el proveedor necesita /v1, va en la base que carga la persona. */
	public function test_base_con_prefijo_v1_lo_conserva() {
		cead_test_set_option( 'cead_acad_wa_ai_endpoint', 'https://mi-servicio.com/v1' );
		cead_test_set_option( 'cead_acad_wa_ai_endpoint_is_base', 1 );
		$this->assertSame( 'https://mi-servicio.com/v1/chat/completions', Cead_Acad_WA_AI::endpoint() );
	}

	/**
	 * Caso borde: activar el interruptor sin cargar un endpoint propio no
	 * puede duplicar la ruta sobre el default de DeepSeek, que ya la trae.
	 */
	public function test_el_default_no_se_toca_si_se_activa_el_interruptor_sin_endpoint_propio() {
		cead_test_set_option( 'cead_acad_wa_ai_endpoint_is_base', 1 );
		$this->assertSame( Cead_Acad_WA_AI::ENDPOINT_DEFAULT, Cead_Acad_WA_AI::endpoint() );
	}
}
