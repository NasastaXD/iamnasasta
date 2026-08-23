<?php
/**
 * Proveedor de IA de respaldo y aviso de caída.
 *
 * Los servicios de IA se caen, se quedan sin crédito y retiran modelos sin
 * avisar. Cuando eso pasa, CEADI deja de entender lenguaje natural para todo el
 * colegio al mismo tiempo, y —esto es lo importante— sin que nadie se entere:
 * el alumno cree que el bot está tonto y dirección no tiene forma de saber que
 * hay que recargar saldo.
 *
 * Estos tests cubren las dos mitades:
 *   1. Que el respaldo esté bien definido (y que uno a medio cargar NO cuente).
 *   2. Que el aviso a dirección sea accionable y NO filtre la API key.
 */

use PHPUnit\Framework\TestCase;

final class AiRespaldoTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
	}

	private function cargarRespaldo( string $endpoint = 'https://respaldo.example/v1/chat/completions' ): void {
		cead_test_set_option( 'cead_acad_wa_ai2_endpoint', $endpoint );
		cead_test_set_option( 'cead_acad_wa_ai2_model', 'modelo-de-respaldo' );
		cead_test_set_option( 'cead_acad_wa_ai2_key', 'sk-respaldo-secreta' );
	}

	/* ----------------------- cuándo hay respaldo de verdad ------------------ */

	public function test_sin_configurar_no_hay_respaldo(): void {
		$this->assertFalse( Cead_Acad_WA_AI::respaldo_activo() );
	}

	public function test_con_las_tres_cosas_hay_respaldo(): void {
		$this->cargarRespaldo();

		$this->assertTrue( Cead_Acad_WA_AI::respaldo_activo() );
	}

	/**
	 * Un respaldo a medio cargar es PEOR que ninguno: hace creer que hay red
	 * debajo, y el día de la caída falla igual pero con el doble de demora,
	 * que es justo lo que hace que el bridge corte y el alumno no reciba nada.
	 *
	 * @dataProvider configuracionesIncompletas
	 */
	public function test_un_respaldo_a_medias_no_cuenta( string $faltante ): void {
		$this->cargarRespaldo();
		cead_test_set_option( $faltante, '' );

		$this->assertFalse( Cead_Acad_WA_AI::respaldo_activo(), "Con «{$faltante}» vacío no debería contar como respaldo." );
	}

	public static function configuracionesIncompletas(): array {
		return [
			'sin endpoint' => [ 'cead_acad_wa_ai2_endpoint' ],
			'sin modelo'   => [ 'cead_acad_wa_ai2_model' ],
			'sin key'      => [ 'cead_acad_wa_ai2_key' ],
		];
	}

	/** El interruptor de «es una base» también vale para el respaldo. */
	public function test_el_endpoint_base_del_respaldo_se_completa(): void {
		$this->cargarRespaldo( 'https://respaldo.example/v1' );
		cead_test_set_option( 'cead_acad_wa_ai2_endpoint_is_base', 1 );

		$this->assertSame( 'https://respaldo.example/v1/chat/completions', Cead_Acad_WA_AI::respaldo_endpoint() );
	}

	public function test_el_endpoint_completo_del_respaldo_se_respeta(): void {
		$this->cargarRespaldo( 'https://respaldo.example/v1/chat/completions' );

		$this->assertSame( 'https://respaldo.example/v1/chat/completions', Cead_Acad_WA_AI::respaldo_endpoint() );
	}

	/* ------------------- el respaldo también tiene niveles ------------------ */

	/**
	 * Sin niveles propios cargados, el respaldo usa su modelo general para todo.
	 * Es el caso de quien carga un respaldo y no quiere pensar más.
	 *
	 * @dataProvider losTresNiveles
	 */
	public function test_sin_niveles_propios_usa_el_modelo_general_del_respaldo( string $nivel ): void {
		$this->cargarRespaldo();

		$this->assertSame( 'modelo-de-respaldo', Cead_Acad_WA_AI::respaldo_model_nivel( $nivel ) );
	}

	public static function losTresNiveles(): array {
		return [
			'rápido' => [ Cead_Acad_WA_AI::NIVEL_RAPIDO ],
			'medio'  => [ Cead_Acad_WA_AI::NIVEL_MEDIO ],
			'máximo' => [ Cead_Acad_WA_AI::NIVEL_MAXIMO ],
		];
	}

	/**
	 * El caso que justifica todo esto.
	 *
	 * Si el proveedor principal se cae JUSTO mientras se carga una nota, esa
	 * nota no puede terminar cargada por el modelo chico del respaldo: un humano
	 * aprueba «un 4 a Ana en Mate» porque se lee bien, aunque sea la Ana
	 * equivocada. Una caída del proveedor no puede degradar en silencio la
	 * calidad de lo que no se puede errar.
	 */
	public function test_el_respaldo_respeta_el_nivel_de_dificultad(): void {
		$this->cargarRespaldo();
		cead_test_set_option( 'cead_acad_wa_ai2_model_n3', 'respaldo-grande' );

		$this->assertSame( 'respaldo-grande', Cead_Acad_WA_AI::respaldo_model_nivel( Cead_Acad_WA_AI::NIVEL_MAXIMO ) );
		// Los otros niveles no se contagian.
		$this->assertSame( 'modelo-de-respaldo', Cead_Acad_WA_AI::respaldo_model_nivel( Cead_Acad_WA_AI::NIVEL_RAPIDO ) );
	}

	/** Sin nivel (proveedor forzado, o una imagen) usa el general. */
	public function test_sin_nivel_usa_el_general(): void {
		$this->cargarRespaldo();
		cead_test_set_option( 'cead_acad_wa_ai2_model_n3', 'respaldo-grande' );

		$this->assertSame( 'modelo-de-respaldo', Cead_Acad_WA_AI::respaldo_model_nivel( null ) );
		$this->assertSame( 'modelo-de-respaldo', Cead_Acad_WA_AI::respaldo_model_nivel( 'inventado' ) );
	}

	/**
	 * Los niveles del respaldo son SUYOS: no heredan los del principal. Si los
	 * heredaran, CEADI le pediría al respaldo un modelo que ese proveedor no
	 * tiene y la caída del principal se convertiría en una caída total.
	 */
	public function test_no_hereda_los_modelos_del_principal(): void {
		$this->cargarRespaldo();
		cead_test_set_option( 'cead_acad_wa_ai_model_n3', 'modelo-solo-del-principal' );

		$this->assertSame( 'modelo-de-respaldo', Cead_Acad_WA_AI::respaldo_model_nivel( Cead_Acad_WA_AI::NIVEL_MAXIMO ) );
	}

	/* -------------------------- el diagnóstico ------------------------------ */

	/**
	 * Lo que dirección necesita no es el código HTTP sino qué hacer.
	 *
	 * El caso que justifica mirar el CUERPO y no solo el código: DeepSeek avisa
	 * que se acabó el crédito con un 402, y OpenAI con un 429 que por fuera es
	 * idéntico a «demasiados pedidos», que se arregla esperando. Si el aviso
	 * dijera «esperá unos minutos» con el saldo en cero, CEADI quedaría caído
	 * toda la noche esperando algo que no se va a arreglar solo.
	 *
	 * @dataProvider fallasConocidas
	 */
	public function test_traduce_la_falla_a_algo_accionable( int $code, string $body, string $esperadoEnCausa, string $esperadoEnArreglo ): void {
		$d = Cead_Acad_WA_AI::diagnostico( $code, $body );

		$this->assertStringContainsStringIgnoringCase( $esperadoEnCausa, $d['causa'] );
		$this->assertStringContainsStringIgnoringCase( $esperadoEnArreglo, $d['arreglo'] );
	}

	public static function fallasConocidas(): array {
		return [
			'saldo agotado (DeepSeek, 402)' => [ 402, 'Insufficient Balance', 'crédito', 'recargar' ],
			'saldo agotado (OpenAI, 429)'   => [ 429, '{"error":{"code":"insufficient_quota"}}', 'crédito', 'recargar' ],
			'key revocada'                  => [ 401, 'Invalid API key provided', 'key', 'nueva' ],
			'modelo retirado'               => [ 400, 'The model `viejo-3` does not exist', 'modelo', 'modelo' ],
			'límite de tasa'                => [ 429, 'Rate limit reached', 'limitando', 'minutos' ],
			'proveedor caído'               => [ 503, 'Service Unavailable', 'caído', 'levanten' ],
			'sin red'                       => [ 0, 'cURL error 28: Operation timed out', 'conectarse', 'internet' ],
		];
	}

	/**
	 * Un 429 «de verdad» (límite de tasa) NO puede confundirse con saldo: el
	 * arreglo es opuesto —esperar contra recargar— y confundirlos manda a
	 * dirección a hacer lo que no sirve.
	 */
	public function test_el_limite_de_tasa_no_se_confunde_con_saldo(): void {
		$d = Cead_Acad_WA_AI::diagnostico( 429, 'Rate limit reached for requests' );

		$this->assertStringNotContainsStringIgnoringCase( 'crédito', $d['causa'] );
	}

	/* ---------------------------- el aviso ---------------------------------- */

	/**
	 * La garantía que más importa de todo este archivo.
	 *
     * El aviso sale por WhatsApp: se reenvía, queda en el celular de varias
	 * personas y en las copias de seguridad de la nube. Una API key filtrada ahí
	 * es una cuenta que cualquiera puede vaciar, y nadie se enteraría hasta ver
	 * la factura.
	 */
	public function test_el_aviso_nunca_lleva_la_api_key(): void {
		$this->cargarRespaldo();

		$texto = Cead_Acad_WA_AI::mensaje_caida(
			'caido',
			[ 'code' => 401, 'bodyraw' => 'Invalid API key: sk-respaldo-secreta', 'error' => '' ],
			'respaldo.example'
		);

		$this->assertStringNotContainsString( 'sk-respaldo-secreta', $texto );
	}

	/**
	 * El caso real que hizo falta tapar: varios proveedores devuelven la key
	 * DENTRO del mensaje de error. Así, la falla más común de todas —una key
	 * mal cargada— publicaba la key por WhatsApp.
	 */
	public function test_tacha_credenciales_aunque_no_sean_las_configuradas(): void {
		$texto = Cead_Acad_WA_AI::mensaje_caida(
			'caido',
			[ 'code' => 401, 'bodyraw' => 'Invalid API key: sk-proj-AbCdEf0123456789xyz', 'error' => '' ],
			'api.openai.com'
		);

		$this->assertStringNotContainsString( 'sk-proj-AbCdEf0123456789xyz', $texto );
		$this->assertStringContainsString( '[key oculta]', $texto );
	}

	/** Tachar no puede comerse el mensaje: la causa tiene que seguir leyéndose. */
	public function test_tachar_no_borra_el_resto_del_mensaje(): void {
		$texto = Cead_Acad_WA_AI::mensaje_caida(
			'caido',
			[ 'code' => 401, 'bodyraw' => 'Invalid API key: sk-proj-AbCdEf0123456789xyz', 'error' => '' ],
			'api.openai.com'
		);

		$this->assertStringContainsString( 'api.openai.com', $texto );
		$this->assertStringContainsStringIgnoringCase( 'key nueva', $texto );
	}

	/** Y tampoco la del proveedor principal. */
	public function test_el_aviso_no_lleva_la_key_del_principal(): void {
		cead_test_set_option( 'cead_acad_wa_ai_key', 'sk-principal-secreta' );

		$texto = Cead_Acad_WA_AI::mensaje_caida(
			'respaldo',
			[ 'code' => 402, 'bodyraw' => 'Insufficient Balance', 'error' => '' ],
			'api.deepseek.com'
		);

		$this->assertStringNotContainsString( 'sk-principal-secreta', $texto );
	}

	/** El aviso tiene que decir qué pasó Y qué hacer, no solo un número. */
	public function test_el_aviso_dice_la_causa_y_el_arreglo(): void {
		$texto = Cead_Acad_WA_AI::mensaje_caida(
			'caido',
			[ 'code' => 402, 'bodyraw' => 'Insufficient Balance', 'error' => '' ],
			'api.deepseek.com'
		);

		$this->assertStringContainsString( 'api.deepseek.com', $texto );
		$this->assertStringContainsStringIgnoringCase( 'crédito', $texto );
		$this->assertStringContainsStringIgnoringCase( 'recargar', $texto );
	}

	/**
	 * Las dos situaciones tienen que distinguirse a simple vista: una es «andá
	 * viendo» y la otra es «CEADI está sin IA ahora mismo». Si se leyeran igual,
	 * dirección trataría la caída total como si fuera un aviso más.
	 */
	public function test_se_distingue_la_caida_total_del_paso_a_respaldo(): void {
		$falla = [ 'code' => 500, 'bodyraw' => 'Internal Server Error', 'error' => '' ];

		$conRespaldo = Cead_Acad_WA_AI::mensaje_caida( 'respaldo', $falla, 'api.deepseek.com' );
		$caido       = Cead_Acad_WA_AI::mensaje_caida( 'caido', $falla, 'api.deepseek.com' );

		$this->assertNotSame( $conRespaldo, $caido );
		// Con respaldo: el alumno no se entera, se avisa que sigue andando.
		$this->assertStringContainsStringIgnoringCase( 'respaldo', $conRespaldo );
		// Caído: hay que decir qué pasa mientras tanto, sin exagerar — el menú
		// numérico sigue atendiendo.
		$this->assertStringContainsStringIgnoringCase( 'menú', $caido );
	}

	/** Sin cuerpo ni mensaje de error, el aviso igual tiene que servir. */
	public function test_el_aviso_funciona_sin_detalle(): void {
		$texto = Cead_Acad_WA_AI::mensaje_caida( 'caido', [ 'code' => 0 ], 'api.deepseek.com' );

		$this->assertStringContainsStringIgnoringCase( 'conectarse', $texto );
		$this->assertStringNotContainsString( '—  ', $texto );
	}
}
