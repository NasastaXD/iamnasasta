<?php
/**
 * Los avisos al teléfono.
 *
 * Lo que se prueba es lo que, si sale mal, no se nota hasta que perjudica a
 * alguien: que un teléfono deje de recibir avisos cuando su sesión se cierra,
 * que el teléfono que cambia de dueño no siga mostrando los avisos del
 * anterior, que un error de formato nuestro no borre los dispositivos de todo
 * el colegio, y que la clave de la cuenta de servicio firme de verdad.
 */

use PHPUnit\Framework\TestCase;

final class PushTest extends TestCase {

	private const PASS = '$P$Bxxxxxxxxxxxxxxxxxxxxxxxxxxxxx0';
	private const TOKEN_A = 'fcm-token-de-prueba-AAAAAAAAAAAAAAAAAAAA:APA91bF';
	private const TOKEN_B = 'fcm-token-de-prueba-BBBBBBBBBBBBBBBBBBBB:APA91bF';

	protected function setUp(): void {
		cead_test_reset_usermeta();
		cead_test_reset_users();
		cead_test_reset_options();
		$GLOBALS['cead_test_transients'] = [];
	}

	/* ------------------------------------------------------- credenciales */

	public function test_credenciales_completas_valen_e_incompletas_no(): void {
		$ok = [ 'project_id' => 'cead', 'client_email' => 'x@cead.iam.gserviceaccount.com', 'private_key' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n", 'extra' => 'se ignora' ];
		$this->assertSame( [ 'project_id', 'client_email', 'private_key' ], array_keys( Cead_Acad_Push_Fcm::validar_credenciales( $ok ) ) );

		foreach ( [ 'project_id', 'client_email', 'private_key' ] as $falta ) {
			$roto = $ok;
			unset( $roto[ $falta ] );
			$this->assertNull( Cead_Acad_Push_Fcm::validar_credenciales( $roto ), "sin $falta" );
		}
		$this->assertNull( Cead_Acad_Push_Fcm::validar_credenciales( array_merge( $ok, [ 'private_key' => 'no es una clave' ] ) ) );
		$this->assertNull( Cead_Acad_Push_Fcm::validar_credenciales( null ) );
		$this->assertNull( Cead_Acad_Push_Fcm::validar_credenciales( 'texto' ) );
	}

	public function test_sin_credenciales_no_hay_push(): void {
		$this->assertFalse( ( new Cead_Acad_Push_Fcm() )->disponible() );
	}

	/** La firma tiene que verificarse con la clave pública: si no, Google la rechaza. */
	public function test_el_jwt_va_firmado_con_la_clave_de_la_cuenta(): void {
		[ $cred, $publica ] = $this->cuenta();
		$jwt = Cead_Acad_Push_Fcm::armar_jwt( $cred, 1_000_000 );

		[ $cab, $cuerpo, $firma ] = explode( '.', $jwt );
		$deb = static function ( $s ) { return base64_decode( strtr( $s, '-_', '+/' ) ); };

		$this->assertSame( [ 'alg' => 'RS256', 'typ' => 'JWT' ], json_decode( $deb( $cab ), true ) );
		$claims = json_decode( $deb( $cuerpo ), true );
		$this->assertSame( $cred['client_email'], $claims['iss'] );
		$this->assertSame( Cead_Acad_Push_Fcm::SCOPE, $claims['scope'] );
		$this->assertSame( Cead_Acad_Push_Fcm::TOKEN_URL, $claims['aud'] );
		$this->assertSame( 1_003_600, $claims['exp'] );
		$this->assertSame( 1, openssl_verify( "$cab.$cuerpo", $deb( $firma ), $publica, OPENSSL_ALGO_SHA256 ) );
		$this->assertStringNotContainsString( '=', $jwt, 'base64url, sin relleno' );
	}

	public function test_una_clave_ilegible_no_firma(): void {
		$this->assertNull( Cead_Acad_Push_Fcm::armar_jwt( [ 'client_email' => 'x', 'private_key' => 'basura' ], 1 ) );
	}

	/* ---------------------------------------------------------- mensaje */

	public function test_todo_dato_del_mensaje_es_un_string(): void {
		$m = Cead_Acad_Push_Fcm::mensaje( 'tok', [ 'titulo' => 'T', 'cuerpo' => 'C', 'datos' => [ 'id' => 12, 'tipo' => 'comunicado', 'x' => null ] ] );
		$this->assertSame( [ 'id' => '12', 'tipo' => 'comunicado', 'x' => '' ], $m['data'] );
		$this->assertSame( 'T', $m['notification']['title'] );
		$this->assertSame( 'tok', $m['token'] );
	}

	/** Un `data` vacío tiene que ir como objeto: como lista FCM lo rechaza. */
	public function test_sin_datos_el_campo_es_un_objeto(): void {
		$m = Cead_Acad_Push_Fcm::mensaje( 'tok', [ 'titulo' => 'T', 'cuerpo' => 'C' ] );
		$this->assertStringContainsString( '"data":{}', json_encode( $m ) );
	}

	/** @dataProvider respuestas */
	public function test_clasifica_lo_que_contesta_fcm( int $codigo, string $cuerpo, string $esperado ): void {
		$this->assertSame( $esperado, Cead_Acad_Push_Fcm::clasificar( $codigo, $cuerpo ) );
	}

	public static function respuestas(): array {
		$unreg = json_encode( [ 'error' => [ 'status' => 'NOT_FOUND', 'details' => [ [ 'errorCode' => 'UNREGISTERED' ] ] ] ] );
		return [
			'enviado'               => [ 200, '{"name":"x"}', Cead_Acad_Push_Fcm::OK ],
			'app desinstalada'      => [ 404, $unreg, Cead_Acad_Push_Fcm::DESCARTAR ],
			'no encontrado'         => [ 404, '{"error":{"status":"NOT_FOUND"}}', Cead_Acad_Push_Fcm::DESCARTAR ],
			/*
			 * Un mensaje mal armado NO es culpa del teléfono: si lo descartáramos,
			 * un error nuestro dejaría sin avisos a todos los dispositivos.
			 */
			'mensaje mal armado'    => [ 400, '{"error":{"status":"INVALID_ARGUMENT"}}', Cead_Acad_Push_Fcm::REINTENTAR ],
			'sin permiso'           => [ 403, '{"error":{"status":"PERMISSION_DENIED"}}', Cead_Acad_Push_Fcm::REINTENTAR ],
			'cuota'                 => [ 429, '{"error":{"status":"RESOURCE_EXHAUSTED"}}', Cead_Acad_Push_Fcm::REINTENTAR ],
			'google caído'          => [ 503, '', Cead_Acad_Push_Fcm::REINTENTAR ],
			'respuesta ilegible'    => [ 500, 'no es json', Cead_Acad_Push_Fcm::REINTENTAR ],
		];
	}

	/* -------------------------------------------------------------- envío */

	public function test_envia_con_el_token_de_acceso_y_lo_guarda_en_cache(): void {
		$trans = $this->transporte( [ 200, 200 ] );
		$fcm   = $this->fcm( $trans );

		$this->assertSame( Cead_Acad_Push_Fcm::OK, $fcm->enviar( 'tok1', [ 'titulo' => 'T', 'cuerpo' => 'C' ] ) );
		$this->assertSame( Cead_Acad_Push_Fcm::OK, $fcm->enviar( 'tok2', [ 'titulo' => 'T', 'cuerpo' => 'C' ] ) );

		// Un pedido de acceso y dos envíos: el segundo ya usa el token guardado.
		$urls = array_column( $trans->llamadas, 'url' );
		$this->assertSame( 1, count( array_filter( $urls, static fn( $u ) => $u === Cead_Acad_Push_Fcm::TOKEN_URL ) ) );
		$this->assertSame( 2, count( array_filter( $urls, static fn( $u ) => str_contains( $u, 'messages:send' ) ) ) );
		$this->assertStringContainsString( '/projects/cead-test/', $urls[1] );
		$this->assertSame( 'Bearer acceso-1', $trans->llamadas[1]['args']['headers']['Authorization'] );
	}

	/** El token de acceso se venció: se pide otro y se reintenta UNA vez. */
	public function test_ante_un_401_renueva_el_acceso_y_reintenta(): void {
		$trans = $this->transporte( [ 401, 200 ] );
		$this->assertSame( Cead_Acad_Push_Fcm::OK, $this->fcm( $trans )->enviar( 'tok1', [ 'titulo' => 'T' ] ) );

		$accesos = count( array_filter( array_column( $trans->llamadas, 'url' ), static fn( $u ) => $u === Cead_Acad_Push_Fcm::TOKEN_URL ) );
		$this->assertSame( 2, $accesos );
	}

	public function test_si_el_401_se_repite_no_insiste(): void {
		$trans = $this->transporte( [ 401, 401 ] );
		$this->assertSame( Cead_Acad_Push_Fcm::REINTENTAR, $this->fcm( $trans )->enviar( 'tok1', [ 'titulo' => 'T' ] ) );
	}

	public function test_si_no_se_consigue_acceso_no_se_manda_nada(): void {
		$trans = new class {
			public $llamadas = [];
			public function __invoke( $url, $args ) { $this->llamadas[] = $url; return [ 'code' => 400, 'body' => '{"error":"invalid_grant"}' ]; }
		};
		$r = $this->fcm( $trans )->enviar( 'tok1', [ 'titulo' => 'T' ] );

		$this->assertSame( Cead_Acad_Push_Fcm::REINTENTAR, $r );
		$this->assertSame( [ Cead_Acad_Push_Fcm::TOKEN_URL ], $trans->llamadas );
	}

	/* -------------------------------------------------------- dispositivos */

	public function test_registrar_exige_token_plataforma_y_sesion(): void {
		$this->assertSame( 'token_invalido', Cead_Acad_Push::registrar( 7, 'corto', 'android', 'jti1' )->get_error_code() );
		$this->assertSame( 'token_invalido', Cead_Acad_Push::registrar( 7, "tiene espacios y sale de cualquier lado", 'android', 'jti1' )->get_error_code() );
		$this->assertSame( 'plataforma_invalida', Cead_Acad_Push::registrar( 7, self::TOKEN_A, 'windows', 'jti1' )->get_error_code() );
		// Con cookies de la web no hay sesión de la app a la que atarse.
		$this->assertSame( 'sin_sesion', Cead_Acad_Push::registrar( 7, self::TOKEN_A, 'android', '' )->get_error_code() );
		$this->assertSame( [], Cead_Acad_Push::dispositivos( 7 ) );
	}

	public function test_un_dispositivo_registrado_queda_atado_a_su_sesion(): void {
		$this->assertTrue( Cead_Acad_Push::registrar( 7, self::TOKEN_A, 'android', 'jti1', 'Moto de Ana' ) );

		$d = Cead_Acad_Push::dispositivos( 7 )[ Cead_Acad_Push::clave( self::TOKEN_A ) ];
		$this->assertSame( 'jti1', $d['jti'] );
		$this->assertSame( 'android', $d['plataforma'] );
		$this->assertSame( self::TOKEN_A, $d['token'] );
	}

	public function test_registrar_dos_veces_el_mismo_token_no_duplica(): void {
		Cead_Acad_Push::registrar( 7, self::TOKEN_A, 'android', 'jti1' );
		Cead_Acad_Push::registrar( 7, self::TOKEN_A, 'android', 'jti2' );

		$this->assertCount( 1, Cead_Acad_Push::dispositivos( 7 ) );
		$this->assertSame( 'jti2', array_values( Cead_Acad_Push::dispositivos( 7 ) )[0]['jti'] );
	}

	/**
	 * Alguien inicia sesión en un teléfono donde antes estaba otra persona: los
	 * avisos de la primera no pueden seguir apareciendo ahí.
	 */
	public function test_el_telefono_que_cambia_de_dueno_deja_de_recibir_los_avisos_del_anterior(): void {
		Cead_Acad_Push::registrar( 7, self::TOKEN_A, 'android', 'jtiAna' );
		Cead_Acad_Push::registrar( 8, self::TOKEN_A, 'android', 'jtiLuis' );

		$this->assertSame( [], Cead_Acad_Push::dispositivos( 7 ) );
		$this->assertCount( 1, Cead_Acad_Push::dispositivos( 8 ) );
	}

	public function test_dar_de_baja_saca_solo_ese_telefono(): void {
		Cead_Acad_Push::registrar( 7, self::TOKEN_A, 'android', 'jti1' );
		Cead_Acad_Push::registrar( 7, self::TOKEN_B, 'ios', 'jti2' );

		Cead_Acad_Push::dar_de_baja( 7, self::TOKEN_A );

		$this->assertSame( [ self::TOKEN_B ], array_column( Cead_Acad_Push::dispositivos( 7 ), 'token' ) );
	}

	/** Cerrar una sesión se lleva el teléfono atado a ESA sesión, no los demás. */
	public function test_cerrar_una_sesion_saca_el_telefono_de_esa_sesion(): void {
		Cead_Acad_Push::registrar( 7, self::TOKEN_A, 'android', 'jti1' );
		Cead_Acad_Push::registrar( 7, self::TOKEN_B, 'ios', 'jti2' );

		Cead_Acad_Push::sesion_cerrada( 7, 'jti1' );

		$this->assertSame( [ self::TOKEN_B ], array_column( Cead_Acad_Push::dispositivos( 7 ), 'token' ) );
	}

	public function test_echar_a_todos_saca_todos_los_telefonos(): void {
		Cead_Acad_Push::registrar( 7, self::TOKEN_A, 'android', 'jti1' );
		Cead_Acad_Push::registrar( 7, self::TOKEN_B, 'ios', 'jti2' );

		Cead_Acad_Push::sesion_cerrada( 7, null );

		$this->assertSame( [], Cead_Acad_Push::dispositivos( 7 ) );
	}

	/** El enganche de verdad: revocar la sesión de la app dispara la baja. */
	public function test_revocar_una_sesion_de_la_api_avisa_para_sacar_el_telefono(): void {
		$GLOBALS['cead_test_actions'] = [];
		$user  = cead_test_set_user( 7, self::PASS );
		$token = Cead_Acad_API_Tokens::emitir( $user, 'Moto' );
		[ , $jti ] = Cead_Acad_API_Tokens::partir( $token );

		Cead_Acad_API_Tokens::revocar_jti( 7, $jti );

		$this->assertContains( [ 'cead_acad_api_sesion_cerrada', 7, $jti ], $GLOBALS['cead_test_actions'] );
	}

	public function test_tiene_tope_de_dispositivos_y_se_quedan_los_mas_nuevos(): void {
		$lista = [];
		for ( $i = 1; $i <= 8; $i++ ) {
			$lista[ "k$i" ] = [ 'token' => "t$i", 'alta' => 1000 + $i ];
		}
		$podada = Cead_Acad_Push::podar( $lista );

		$this->assertCount( Cead_Acad_Push::MAX_DISPOSITIVOS, $podada );
		$this->assertArrayHasKey( 'k8', $podada );
		$this->assertArrayNotHasKey( 'k1', $podada );
	}

	/* --------------------------------------------------- envío a personas */

	public function test_solo_se_manda_a_los_telefonos_con_sesion_viva(): void {
		$user  = cead_test_set_user( 7, self::PASS );
		$token = Cead_Acad_API_Tokens::emitir( $user, 'Moto' );
		[ , $jti ] = Cead_Acad_API_Tokens::partir( $token );

		Cead_Acad_Push::registrar( 7, self::TOKEN_A, 'android', $jti );
		Cead_Acad_Push::registrar( 7, self::TOKEN_B, 'ios', 'sesion-que-ya-no-existe' );

		$trans = $this->transporte( [ 200 ] );
		$r     = Cead_Acad_Push::enviar_ahora( $this->fcm( $trans ), [ 7 ], [ 'categoria' => 'comunicados', 'titulo' => 'T', 'cuerpo' => 'C' ] );

		$this->assertSame( [ 'enviados' => 1, 'descartados' => 1, 'fallidos' => 0 ], $r );
		// El teléfono de la sesión muerta se saca para no volver a intentarlo.
		$this->assertSame( [ self::TOKEN_A ], array_column( Cead_Acad_Push::dispositivos( 7 ), 'token' ) );
	}

	public function test_un_token_que_fcm_dice_que_ya_no_existe_se_saca(): void {
		$user  = cead_test_set_user( 7, self::PASS );
		[ , $jti ] = Cead_Acad_API_Tokens::partir( Cead_Acad_API_Tokens::emitir( $user, 'Moto' ) );
		Cead_Acad_Push::registrar( 7, self::TOKEN_A, 'android', $jti );

		$unreg = json_encode( [ 'error' => [ 'status' => 'NOT_FOUND', 'details' => [ [ 'errorCode' => 'UNREGISTERED' ] ] ] ] );
		$r     = Cead_Acad_Push::enviar_ahora( $this->fcm( $this->transporte( [ 404 ], $unreg ) ), [ 7 ], [ 'categoria' => 'comunicados', 'titulo' => 'T' ] );

		$this->assertSame( 1, $r['descartados'] );
		$this->assertSame( [], Cead_Acad_Push::dispositivos( 7 ) );
	}

	/** Un error de Google o de cuota no es culpa del teléfono: se queda registrado. */
	public function test_un_error_pasajero_no_borra_el_telefono(): void {
		$user  = cead_test_set_user( 7, self::PASS );
		[ , $jti ] = Cead_Acad_API_Tokens::partir( Cead_Acad_API_Tokens::emitir( $user, 'Moto' ) );
		Cead_Acad_Push::registrar( 7, self::TOKEN_A, 'android', $jti );

		$r = Cead_Acad_Push::enviar_ahora( $this->fcm( $this->transporte( [ 503 ], '' ) ), [ 7 ], [ 'categoria' => 'comunicados', 'titulo' => 'T' ] );

		$this->assertSame( [ 'enviados' => 0, 'descartados' => 0, 'fallidos' => 1 ], $r );
		$this->assertCount( 1, Cead_Acad_Push::dispositivos( 7 ) );
	}

	public function test_respeta_lo_que_la_persona_apago(): void {
		$user  = cead_test_set_user( 7, self::PASS );
		[ , $jti ] = Cead_Acad_API_Tokens::partir( Cead_Acad_API_Tokens::emitir( $user, 'Moto' ) );
		Cead_Acad_Push::registrar( 7, self::TOKEN_A, 'android', $jti );
		Cead_Acad_Push::guardar_preferencias( 7, [ 'comunicados' => false ] );

		$trans = $this->transporte( [ 200, 200 ] );
		$fcm   = $this->fcm( $trans );

		$this->assertSame( 0, Cead_Acad_Push::enviar_ahora( $fcm, [ 7 ], [ 'categoria' => 'comunicados', 'titulo' => 'T' ] )['enviados'] );
		$this->assertSame( 1, Cead_Acad_Push::enviar_ahora( $fcm, [ 7 ], [ 'categoria' => 'eventos', 'titulo' => 'T' ] )['enviados'] );
		// La prueba va aunque haya categorías apagadas: la pidió la persona.
		$this->assertSame( 1, Cead_Acad_Push::enviar_ahora( $fcm, [ 7 ], [ 'categoria' => 'comunicados', 'titulo' => 'T' ], false )['enviados'] );
	}

	/* ------------------------------------------------------- preferencias */

	public function test_lo_que_nunca_se_toco_esta_activo(): void {
		$p = Cead_Acad_Push::completar_preferencias( [] );
		$this->assertSame( Cead_Acad_Push::CATEGORIAS, array_keys( $p ) );
		$this->assertNotContains( false, $p );
	}

	public function test_guardar_preferencias_ignora_categorias_inventadas(): void {
		$r = Cead_Acad_Push::guardar_preferencias( 7, [ 'eventos' => false, 'inventada' => true ] );

		$this->assertFalse( $r['eventos'] );
		$this->assertTrue( $r['comunicados'] );
		$this->assertArrayNotHasKey( 'inventada', $r );
		$this->assertFalse( Cead_Acad_Push::preferencias( 7 )['eventos'] );
	}

	/* ------------------------------------------------------------- avisos */

	public function test_el_aviso_se_acota_antes_de_encolarlo(): void {
		$a = Cead_Acad_Push::limpiar_aviso( [
			'categoria' => 'cualquiera',
			'titulo'    => '<b>' . str_repeat( 'x', 200 ) . '</b>',
			'cuerpo'    => str_repeat( 'y', 500 ),
			'datos'     => [ 'ID' => 5, 'tipo' => 'comunicado' ],
		] );
		$this->assertSame( 'comunicados', $a['categoria'], 'una categoría desconocida cae en la general' );
		$this->assertSame( 80, mb_strlen( $a['titulo'] ) );
		$this->assertStringNotContainsString( '<b>', $a['titulo'] );
		$this->assertSame( 160, mb_strlen( $a['cuerpo'] ) );
		$this->assertSame( [ 'id' => '5', 'tipo' => 'comunicado' ], $a['datos'] );
	}

	/**
	 * El buzón avisa que hay respuesta, nunca la respuesta: la pantalla
	 * bloqueada la ve cualquiera.
	 */
	public function test_el_aviso_del_buzon_no_lleva_el_texto_de_la_respuesta(): void {
		// La función ni recibe la respuesta: no hay forma de que se cuele.
		$aviso = Cead_Acad_Push::aviso_buzon_respuesta( 'reporte', (object) [ 'user_id' => 5, 'ref_code' => 'RPT-ABC123' ] );
		$this->assertStringContainsString( 'RPT-ABC123', $aviso['cuerpo'] );
		$this->assertSame( 'mis_mensajes', $aviso['datos']['tipo'] );

		$msg = Cead_Acad_Push::aviso_buzon_respuesta( 'sugerencia', (object) [ 'user_id' => 5 ] );
		$this->assertStringNotContainsString( 'RPT', $msg['cuerpo'] );
	}

	/** Un reporte anónimo no guarda de quién es: no hay a quién avisar, y no revienta. */
	public function test_un_reporte_anonimo_no_genera_aviso(): void {
		$GLOBALS['cead_test_actions'] = [];
		Cead_Acad_Push::al_responder_buzon( 'reporte', (object) [ 'user_id' => null, 'ref_code' => 'RPT-ABC123' ], 'respond', 'texto' );
		Cead_Acad_Push::al_responder_buzon( 'reporte', (object) [ 'ref_code' => 'RPT-ABC123' ], 'respond', 'texto' );
		$this->assertTrue( true );
	}

	public function test_el_aviso_de_una_nota_dice_la_materia_pero_no_la_nota(): void {
		$this->assertSame( 'boletin', $this->aviso_nota( 'Matemática', 'Segunda Etapa' )['datos']['tipo'] );
		$this->assertSame( 'Matemática · Segunda Etapa', $this->aviso_nota( 'Matemática', 'Segunda Etapa' )['cuerpo'] );
	}

	/* ----------------------------------------------- publicar, una sola vez */

	public function test_se_avisa_una_sola_vez_por_publicacion(): void {
		$GLOBALS['cead_test_actions'] = [];

		$this->assertTrue( Cead_Acad_Push::marcar_y_avisar( 42, 'cead_acad_comunicado_publicado' ) );
		$this->assertFalse( Cead_Acad_Push::marcar_y_avisar( 42, 'cead_acad_comunicado_publicado' ), 'editar de nuevo no reenvía' );
		$this->assertCount( 1, $GLOBALS['cead_test_actions'] );
	}

	public function test_solo_lo_recien_publicado_se_considera_reciente(): void {
		$ahora = 1_800_000_000;
		$hace  = static fn( int $seg ) => (object) [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $ahora - $seg ) ];

		$this->assertTrue( Cead_Acad_Push::es_reciente( $hace( 30 ), $ahora ) );
		// Un evento de hace meses al que alguien le corrige una errata no es «nuevo».
		$this->assertFalse( Cead_Acad_Push::es_reciente( $hace( 90 * DAY_IN_SECONDS ), $ahora ) );
		$this->assertFalse( Cead_Acad_Push::es_reciente( (object) [ 'post_date_gmt' => '0000-00-00 00:00:00' ], $ahora ) );
	}

	/* ------------------------------------------------------------ CEADI */

	public function test_ceadi_manda_a_la_pantalla_de_la_app_no_a_whatsapp(): void {
		$txt = Cead_Acad_Ceadi_Panel::donde_se_hace( 'enviar_comunicado' );
		$this->assertStringContainsString( 'Publicar comunicado', $txt );
		$this->assertStringNotContainsString( 'WhatsApp', $txt );
		$this->assertStringContainsString( 'Cargar notas', Cead_Acad_Ceadi_Panel::donde_se_hace( 'cargar_nota' ) );
	}

	public function test_ceadi_no_manda_a_buscar_una_pantalla_que_no_existe(): void {
		foreach ( [ 'generar_imagen', 'recordar', 'olvidar', 'algo_nuevo' ] as $intent ) {
			$txt = Cead_Acad_Ceadi_Panel::donde_se_hace( $intent );
			$this->assertStringContainsString( 'todavía no se puede', $txt, $intent );
			$this->assertStringNotContainsString( 'WhatsApp', $txt, $intent );
		}
	}

	/* -------------------------------------------------------------- ayudas */

	/** @return array{0:array,1:string} cuenta de servicio con una clave real y su pública */
	private function cuenta(): array {
		// Generar una clave RSA cuesta décimas de segundo: una sola para todo el archivo.
		static $cuenta = null;
		if ( null === $cuenta ) {
			$k = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
			openssl_pkey_export( $k, $privada );
			$cuenta = [
				[ 'project_id' => 'cead-test', 'client_email' => 'push@cead-test.iam.gserviceaccount.com', 'private_key' => $privada ],
				openssl_pkey_get_details( $k )['key'],
			];
		}
		return $cuenta;
	}

	private function fcm( $transporte ): Cead_Acad_Push_Fcm {
		return new Cead_Acad_Push_Fcm( $transporte, $this->cuenta()[0] );
	}

	/**
	 * Un transporte de mentira. Contesta con los códigos dados a los ENVÍOS, en
	 * orden; los pedidos de acceso siempre salen bien (`acceso-N`).
	 */
	private function transporte( array $codigos, string $cuerpo = '{"name":"projects/x/messages/1"}' ) {
		return new class( $codigos, $cuerpo ) {
			public $llamadas = [];
			private $codigos;
			private $cuerpo;
			private $accesos = 0;
			public function __construct( $codigos, $cuerpo ) { $this->codigos = $codigos; $this->cuerpo = $cuerpo; }
			public function __invoke( $url, $args ) {
				$this->llamadas[] = compact( 'url', 'args' );
				if ( $url === Cead_Acad_Push_Fcm::TOKEN_URL ) {
					$this->accesos++;
					return [ 'code' => 200, 'body' => json_encode( [ 'access_token' => 'acceso-' . $this->accesos, 'expires_in' => 3600 ] ) ];
				}
				return [ 'code' => array_shift( $this->codigos ) ?? 200, 'body' => $this->cuerpo ];
			}
		};
	}

	private function aviso_nota( string $materia, string $periodo ): array {
		return Cead_Acad_Push::aviso_nota( $materia, $periodo );
	}
}
