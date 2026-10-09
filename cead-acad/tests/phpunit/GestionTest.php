<?php
/**
 * La gestión que antes se hacía por WhatsApp y ahora se hace desde la app.
 *
 * Lo que se prueba es lo que, si sale mal, no se ve hasta que hace daño:
 * que un docente no pueda publicarle a todo el colegio armando el pedido a
 * mano; que el Consejo no lea reportes; que un reporte anónimo no guarde nada
 * de quien lo mandó; y que el aviso por WhatsApp diga lo mismo que decía.
 */

use PHPUnit\Framework\TestCase;

/** Un store de mentira: guarda filas en memoria y anota qué se le pidió. */
final class Cead_Test_Buzon_Store {
	public $reportes    = [];
	public $sugerencias = [];
	public $llamadas    = [];
	public $creados     = [];

	public function get_report( $id ) { return $this->reportes[ $id ] ?? null; }
	public function get_suggestion( $id ) { return $this->sugerencias[ $id ] ?? null; }
	public function get_report_by_ref( $ref ) {
		foreach ( $this->reportes as $r ) {
			if ( $r->ref_code === $ref ) { return $r; }
		}
		return null;
	}
	public function create_report( $type, $phone, $category, $body, $user_id = 0 ) {
		$this->creados[] = compact( 'type', 'phone', 'category', 'body', 'user_id' );
		return 'RPT-ABC123';
	}
	public function __call( $metodo, $args ) {
		$this->llamadas[] = [ $metodo, $args ];
		return true;
	}
}

/** El buzón, con quien reporta inventado en vez de leído de WordPress. */
final class Cead_Test_Buzon extends Cead_Acad_Buzon {
	protected function identidad( $user_id ) {
		return [ 'nombre' => 'Ana Pérez', 'rol' => 'Alumno/a', 'curso' => '3° B', 'telefono' => '' ];
	}
}

final class GestionTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_caps();
		cead_test_reset_options();
		$GLOBALS['cead_test_actions']    = [];
		$GLOBALS['cead_test_transients'] = [];
	}

	/* ---------------------------------------------- quién publica a quién */

	private function roles(): array {
		return array_keys( Cead_Acad_Capabilities::roles() );
	}

	public function test_quien_puede_todo_publica_a_todo_el_colegio(): void {
		$r = Cead_Acad_Gestion_Audiencias::filtrar( [ [ 'type' => 'all', 'value' => '*' ] ], true, $this->roles() );
		$this->assertSame( [ [ 'type' => 'all', 'value' => '*' ] ], $r );
	}

	/** El límite que ponía el bot: un docente le habla al alumnado, no a todos. */
	public function test_sin_permiso_total_no_publica_a_todo_el_colegio(): void {
		$r = Cead_Acad_Gestion_Audiencias::filtrar( [ [ 'type' => 'all', 'value' => '*' ] ], false, $this->roles() );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'audiencia_no_permitida', $r->get_error_code() );
		$this->assertSame( 403, $r->get_error_data()['status'] );
	}

	public function test_sin_permiso_total_no_publica_al_staff_ni_a_una_persona(): void {
		foreach ( [ [ 'type' => 'role', 'value' => 'cead_acad_teacher' ], [ 'type' => 'user', 'value' => '7' ] ] as $a ) {
			$r = Cead_Acad_Gestion_Audiencias::filtrar( [ $a ], false, $this->roles() );
			$this->assertInstanceOf( WP_Error::class, $r, $a['type'] );
			$this->assertSame( 'audiencia_no_permitida', $r->get_error_code() );
		}
	}

	public function test_sin_permiso_total_publica_dentro_del_alumnado(): void {
		$pedidas = [
			[ 'type' => 'role', 'value' => 'cead_acad_student' ],
			[ 'type' => 'role', 'value' => 'cead_acad_delegate' ],
			[ 'type' => 'course', 'value' => '12' ],
			[ 'type' => 'cohort', 'value' => '3' ],
		];
		$this->assertSame( $pedidas, Cead_Acad_Gestion_Audiencias::filtrar( $pedidas, false, $this->roles() ) );
	}

	/** Si una no se puede, no sale a la mitad: no sale, y dice por qué. */
	public function test_una_sola_audiencia_prohibida_rechaza_el_pedido_entero(): void {
		$r = Cead_Acad_Gestion_Audiencias::filtrar( [
			[ 'type' => 'course', 'value' => '12' ],
			[ 'type' => 'all', 'value' => '*' ],
		], false, $this->roles() );
		$this->assertInstanceOf( WP_Error::class, $r );
	}

	public function test_acepta_las_claves_en_castellano_y_quita_repetidos(): void {
		$r = Cead_Acad_Gestion_Audiencias::filtrar( [
			[ 'tipo' => 'course', 'valor' => '12' ],
			[ 'type' => 'course', 'value' => '12' ],
		], true, $this->roles() );
		$this->assertSame( [ [ 'type' => 'course', 'value' => '12' ] ], $r );
	}

	/** @dataProvider audiencias_invalidas */
	public function test_rechaza_lo_que_no_es_una_audiencia( $pedidas, string $codigo ): void {
		$r = Cead_Acad_Gestion_Audiencias::filtrar( $pedidas, true, $this->roles() );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( $codigo, $r->get_error_code() );
	}

	public static function audiencias_invalidas(): array {
		return [
			'nada'           => [ [], 'sin_audiencia' ],
			'no es lista'    => [ 'all', 'sin_audiencia' ],
			'tipo raro'      => [ [ [ 'type' => 'planeta', 'value' => '1' ] ], 'audiencia_invalida' ],
			'rol inventado'  => [ [ [ 'type' => 'role', 'value' => 'administrator' ] ], 'audiencia_invalida' ],
			'curso con letras' => [ [ [ 'type' => 'course', 'value' => '12 OR 1=1' ] ], 'audiencia_invalida' ],
			'curso cero'     => [ [ [ 'type' => 'course', 'value' => '0' ] ], 'audiencia_invalida' ],
		];
	}

	public function test_sin_permiso_de_publicar_no_hay_audiencia_que_valga(): void {
		$r = Cead_Acad_Gestion_Audiencias::para_comunicado( 5, [ [ 'type' => 'course', 'value' => '1' ] ] );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 403, $r->get_error_data()['status'] );
	}

	public function test_el_permiso_total_sale_de_la_capacidad_de_quien_publica(): void {
		$todo = [ [ 'type' => 'all', 'value' => '*' ] ];

		cead_test_set_caps( 5, [ 'cead_acad_publish_broadcast' => true ] );
		$this->assertInstanceOf( WP_Error::class, Cead_Acad_Gestion_Audiencias::para_comunicado( 5, $todo ) );

		cead_test_set_caps( 5, [ 'cead_acad_publish_broadcast' => true, 'cead_acad_publish_broadcast_all' => true ] );
		$this->assertSame( $todo, Cead_Acad_Gestion_Audiencias::para_comunicado( 5, $todo ) );
	}

	public function test_los_eventos_los_carga_quien_maneja_el_calendario(): void {
		$todo = [ [ 'type' => 'all', 'value' => '*' ] ];
		$this->assertInstanceOf( WP_Error::class, Cead_Acad_Gestion_Audiencias::para_evento( 5, $todo ) );

		cead_test_set_caps( 5, [ 'cead_acad_manage_schedule' => true ] );
		$this->assertSame( $todo, Cead_Acad_Gestion_Audiencias::para_evento( 5, $todo ) );
	}

	/** En un multipart todo llega como texto: la lista viene en JSON. */
	public function test_las_audiencias_llegan_como_lista_o_como_json(): void {
		$lista = [ [ 'type' => 'course', 'value' => '3' ] ];
		$this->assertSame( $lista, Cead_Acad_API_Gestion::lista_audiencias( $lista ) );
		$this->assertSame( $lista, Cead_Acad_API_Gestion::lista_audiencias( '[{"type":"course","value":"3"}]' ) );
		$this->assertNull( Cead_Acad_API_Gestion::lista_audiencias( 'no es json' ) );
		$this->assertNull( Cead_Acad_API_Gestion::lista_audiencias( null ) );
	}

	/* --------------------------------------------------- fechas de eventos */

	/** @dataProvider fechas */
	public function test_la_fecha_de_un_evento_queda_en_un_solo_formato( $entrada, string $esperada ): void {
		$this->assertSame( $esperada, Cead_Acad_Schedule_CPT::fecha_canonica( $entrada ) );
	}

	public static function fechas(): array {
		return [
			'del formulario'       => [ '2026-10-09T14:30', '2026-10-09T14:30' ],
			'del bot, con segundos' => [ '2026-10-09 14:30:00', '2026-10-09T14:30' ],
			'con espacio'          => [ '2026-10-09 08:05', '2026-10-09T08:05' ],
			'solo el día'          => [ '2026-10-09', '2026-10-09T00:00' ],
			'con espacios afuera'  => [ "  2026-10-09T14:30\n", '2026-10-09T14:30' ],
			'hora imposible'       => [ '2026-10-09T25:00', '' ],
			'31 de febrero'        => [ '2026-02-31T10:00', '' ],
			'mes 13'               => [ '2026-13-01', '' ],
			'al revés'             => [ '09/10/2026', '' ],
			'vacía'                => [ '', '' ],
			'nula'                 => [ null, '' ],
		];
	}

	/* ------------------------------------------------------------- buzón */

	public function test_el_alcance_sale_de_las_capacidades(): void {
		$this->assertSame( '', Cead_Acad_Buzon::alcance( 9 ) );

		cead_test_set_caps( 9, [ 'cead_acad_manage_suggestions' => true ] );
		$this->assertSame( 'consejo', Cead_Acad_Buzon::alcance( 9 ) );

		cead_test_set_caps( 9, [ 'cead_acad_manage_suggestions' => true, 'cead_acad_manage_reports' => true ] );
		$this->assertSame( 'todo', Cead_Acad_Buzon::alcance( 9 ) );
	}

	public function test_el_consejo_no_toca_reportes(): void {
		foreach ( Cead_Acad_Buzon::ACCIONES_REPORTE as $accion ) {
			$this->assertFalse( Cead_Acad_Buzon::puede( 'consejo', 'reporte', $accion ), $accion );
			$this->assertTrue( Cead_Acad_Buzon::puede( 'todo', 'reporte', $accion ), $accion );
		}
	}

	public function test_el_consejo_solo_contesta_lo_que_va_al_consejo(): void {
		$this->assertTrue( Cead_Acad_Buzon::puede( 'consejo', 'sugerencia', 'respond', 'consejo' ) );
		$this->assertTrue( Cead_Acad_Buzon::puede( 'consejo', 'sugerencia', 'trash', 'consejo' ) );
		$this->assertFalse( Cead_Acad_Buzon::puede( 'consejo', 'sugerencia', 'respond', 'direccion' ) );
		$this->assertFalse( Cead_Acad_Buzon::puede( 'consejo', 'sugerencia', 'respond', 'administracion' ) );
	}

	/** No ve la papelera: ni restaura ni borra para siempre. */
	public function test_el_consejo_no_borra_para_siempre(): void {
		$this->assertFalse( Cead_Acad_Buzon::puede( 'consejo', 'sugerencia', 'restore', 'consejo' ) );
		$this->assertFalse( Cead_Acad_Buzon::puede( 'consejo', 'sugerencia', 'purge', 'consejo' ) );
	}

	public function test_acciones_que_no_existen_no_se_pueden(): void {
		$this->assertFalse( Cead_Acad_Buzon::puede( 'todo', 'reporte', 'deny' ) );
		$this->assertFalse( Cead_Acad_Buzon::puede( 'todo', 'sugerencia', 'not_report' ) );
		$this->assertFalse( Cead_Acad_Buzon::puede( 'todo', 'otra_cosa', 'respond' ) );
		$this->assertFalse( Cead_Acad_Buzon::puede( '', 'sugerencia', 'respond', 'consejo' ) );
	}

	public function test_contestar_guarda_la_respuesta_y_avisa(): void {
		cead_test_set_caps( 1, [ 'cead_acad_manage_reports' => true ] );
		$store = new Cead_Test_Buzon_Store();
		$store->sugerencias[4] = (object) [ 'id' => 4, 'category' => 'direccion', 'phone' => '595981000000' ];

		$r = ( new Cead_Acad_Buzon( $store ) )->actuar( 1, 'sugerencia', 4, 'accept', 'Lo hacemos el lunes.' );

		$this->assertTrue( $r );
		$this->assertSame( [ [ 'respond_suggestion', [ 4, 'Lo hacemos el lunes.', 'accepted' ] ] ], $store->llamadas );
		$aviso = end( $GLOBALS['cead_test_actions'] );
		$this->assertSame( 'cead_acad_buzon_respuesta', $aviso[0] );
		$this->assertSame( [ 'sugerencia', 'accept', 'Lo hacemos el lunes.' ], [ $aviso[1], $aviso[3], $aviso[4] ] );
	}

	/** Archivar no le cambia nada a quien escribió: no se le avisa. */
	public function test_mandar_a_la_papelera_no_avisa(): void {
		cead_test_set_caps( 1, [ 'cead_acad_manage_reports' => true ] );
		$store = new Cead_Test_Buzon_Store();
		$store->reportes[2] = (object) [ 'id' => 2, 'ref_code' => 'RPT-000002', 'type' => 'anonymous', 'phone' => null ];

		( new Cead_Acad_Buzon( $store ) )->actuar( 1, 'reporte', 2, 'trash' );

		$this->assertSame( [ [ 'soft_delete_report', [ 2 ] ] ], $store->llamadas );
		$this->assertSame( [], $GLOBALS['cead_test_actions'] );
	}

	public function test_el_consejo_no_actua_sobre_lo_ajeno_aunque_arme_el_pedido(): void {
		cead_test_set_caps( 3, [ 'cead_acad_manage_suggestions' => true ] );
		$store = new Cead_Test_Buzon_Store();
		$store->sugerencias[4] = (object) [ 'id' => 4, 'category' => 'direccion', 'phone' => '' ];
		$store->reportes[2]    = (object) [ 'id' => 2, 'ref_code' => 'RPT-000002', 'type' => 'confidential', 'phone' => '595' ];
		$buzon = new Cead_Acad_Buzon( $store );

		$this->assertSame( 403, $buzon->actuar( 3, 'sugerencia', 4, 'respond', 'x' )->get_error_data()['status'] );
		$this->assertSame( 403, $buzon->actuar( 3, 'reporte', 2, 'respond', 'x' )->get_error_data()['status'] );
		$this->assertSame( [], $store->llamadas );
	}

	public function test_un_item_que_no_existe_da_404(): void {
		cead_test_set_caps( 1, [ 'cead_acad_manage_reports' => true ] );
		$r = ( new Cead_Acad_Buzon( new Cead_Test_Buzon_Store() ) )->actuar( 1, 'reporte', 99, 'respond' );
		$this->assertSame( 404, $r->get_error_data()['status'] );
	}

	/* ----------------------------------------- reportes desde la app */

	/** Que no quede guardado nada de quien lo mandó es lo que lo hace anónimo. */
	public function test_un_reporte_anonimo_no_guarda_nada_de_quien_lo_manda(): void {
		$store = new Cead_Test_Buzon_Store();
		$cod   = ( new Cead_Test_Buzon( $store ) )->crear_reporte( 42, 'anonimo', 'Seguridad', 'Hay un vidrio roto.' );

		$this->assertSame( 'RPT-ABC123', $cod );
		$this->assertSame( [
			'type'     => 'anonymous',
			'phone'    => null,
			'category' => 'Seguridad',
			'body'     => 'Hay un vidrio roto.',
			'user_id'  => 0,
		], $store->creados[0] );
		$this->assertStringNotContainsString( 'Ana', $store->creados[0]['body'] );
		// El aviso de «entró un reporte» tampoco lleva a nadie.
		$this->assertSame( [ 'cead_acad_buzon_reporte_nuevo', 'RPT-ABC123' ], end( $GLOBALS['cead_test_actions'] ) );
	}

	/**
	 * Desde la app no hay número que identifique a quien reporta: sin el
	 * nombre adentro, un confidencial se volvería anónimo sin que nadie lo
	 * decida.
	 */
	public function test_un_reporte_confidencial_lleva_quien_lo_manda(): void {
		$store = new Cead_Test_Buzon_Store();
		( new Cead_Test_Buzon( $store ) )->crear_reporte( 42, 'confidencial', 'Seguridad', 'Hay un vidrio roto.' );

		$c = $store->creados[0];
		$this->assertSame( 'confidential', $c['type'] );
		$this->assertSame( 42, $c['user_id'] );
		$this->assertNull( $c['phone'] );
		$this->assertSame( "🔒 De: Ana Pérez (Alumno/a, 3° B)\n\nHay un vidrio roto.", $c['body'] );
	}

	public function test_un_reporte_vacio_no_se_guarda(): void {
		$store = new Cead_Test_Buzon_Store();
		$r     = ( new Cead_Test_Buzon( $store ) )->crear_reporte( 42, 'anonimo', 'Otro', "  \n " );
		$this->assertSame( 'vacio', $r->get_error_code() );
		$this->assertSame( [], $store->creados );
	}

	public function test_una_categoria_que_no_existe_cae_en_otro(): void {
		$store = new Cead_Test_Buzon_Store();
		( new Cead_Test_Buzon( $store ) )->crear_reporte( 42, 'anonimo', 'Inventada', 'Algo pasó.' );
		$this->assertSame( 'Otro', $store->creados[0]['category'] );
	}

	public function test_las_categorias_son_las_de_los_ajustes(): void {
		$this->assertContains( 'Otro', Cead_Acad_Buzon::categorias() );
		cead_test_set_option( 'cead_acad_wa_report_categories', [ 'Bullying', 'Otro' ] );
		$this->assertSame( [ 'Bullying', 'Otro' ], Cead_Acad_Buzon::categorias() );
	}

	public function test_cuerpo_confidencial_sin_datos_no_queda_con_parentesis_vacios(): void {
		$this->assertSame( "🔒 De: Ana\n\nx", Cead_Acad_Buzon::cuerpo_confidencial( 'Ana', '', '', 'x' ) );
		$this->assertSame( "🔒 De: —\n\nx", Cead_Acad_Buzon::cuerpo_confidencial( '', '', '', 'x' ) );
	}

	public function test_a_quien_escribio_no_se_le_muestra_su_propia_firma(): void {
		$this->assertSame( "Hola\n\nchau", Cead_Acad_Buzon::sin_firma( "✉️ De Ana (Alumno/a)\n\nHola\n\nchau" ) );
		$this->assertSame( 'Sin firma', Cead_Acad_Buzon::sin_firma( 'Sin firma' ) );
	}

	/* ----------------------------------------- seguimiento por código */

	public function test_el_seguimiento_no_devuelve_el_texto_del_reporte(): void {
		$store = new Cead_Test_Buzon_Store();
		$store->reportes[2] = (object) [
			'id' => 2, 'ref_code' => 'RPT-00AB12', 'status' => 'in_review', 'response' => 'Lo estamos viendo.',
			'updated_at' => '2026-10-09 10:00:00', 'deleted_at' => null, 'body_enc' => 'secreto',
		];
		$r = ( new Cead_Acad_Buzon( $store ) )->estado_reporte( 5, 'rpt-00ab12' );

		$this->assertSame( [
			'codigo'      => 'RPT-00AB12',
			'estado'      => 'in_review',
			'respuesta'   => 'Lo estamos viendo.',
			'actualizado' => '2026-10-09 10:00:00',
		], $r );
	}

	public function test_un_codigo_mal_escrito_o_borrado_no_existe(): void {
		$store = new Cead_Test_Buzon_Store();
		$store->reportes[2] = (object) [ 'id' => 2, 'ref_code' => 'RPT-00AB12', 'deleted_at' => '2026-10-01 00:00:00' ];
		$buzon = new Cead_Acad_Buzon( $store );

		$this->assertNull( $buzon->estado_reporte( 5, 'RPT-00AB12' ) );
		$this->assertNull( $buzon->estado_reporte( 5, "RPT-00AB12' OR 1=1" ) );
	}

	/** El código es la única llave: probar códigos a ciegas tiene un tope. */
	public function test_las_consultas_por_codigo_tienen_tope(): void {
		$buzon = new Cead_Acad_Buzon( new Cead_Test_Buzon_Store() );
		for ( $i = 0; $i < Cead_Acad_Buzon::CONSULTAS_MINUTO; $i++ ) {
			$this->assertNull( $buzon->estado_reporte( 5, 'RPT-000000' ) );
		}
		$r = $buzon->estado_reporte( 5, 'RPT-000000' );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 429, $r->get_error_data()['status'] );
		// El tope es por persona.
		$this->assertNull( $buzon->estado_reporte( 6, 'RPT-000000' ) );
	}

	/* ------------------------------------------ el aviso por WhatsApp */

	/** Las mismas palabras que mandaba la plantilla del panel. */
	public function test_el_aviso_por_whatsapp_dice_lo_de_siempre(): void {
		$conf = (object) [ 'type' => 'confidential', 'phone' => '595981', 'ref_code' => 'RPT-ABC123' ];
		$sug  = (object) [ 'phone' => '595981' ];

		$this->assertSame( "💬 Respuesta a tu reporte RPT-ABC123:\n\nYa lo vimos.", Cead_Acad_WA_Module::mensaje_buzon( 'reporte', $conf, 'respond', 'Ya lo vimos.' ) );
		$this->assertSame( '✅ Tu reporte RPT-ABC123 fue recibido y aceptado.', Cead_Acad_WA_Module::mensaje_buzon( 'reporte', $conf, 'accept', '' ) );
		$this->assertSame( "✅ Tu reporte RPT-ABC123 fue recibido y aceptado.\n\nGracias.", Cead_Acad_WA_Module::mensaje_buzon( 'reporte', $conf, 'accept', 'Gracias.' ) );
		$this->assertSame( "💬 Respuesta a tu sugerencia:\n\nDale.", Cead_Acad_WA_Module::mensaje_buzon( 'sugerencia', $sug, 'respond', 'Dale.' ) );
		$this->assertSame( '✅ Tu sugerencia fue aceptada.', Cead_Acad_WA_Module::mensaje_buzon( 'sugerencia', $sug, 'accept', '' ) );
		$this->assertSame( "❌ Tu sugerencia fue rechazada.\n\nNo hay presupuesto.", Cead_Acad_WA_Module::mensaje_buzon( 'sugerencia', $sug, 'deny', 'No hay presupuesto.' ) );
	}

	public function test_no_se_avisa_sin_numero_ni_a_un_anonimo_ni_con_respuesta_vacia(): void {
		$this->assertNull( Cead_Acad_WA_Module::mensaje_buzon( 'sugerencia', (object) [ 'phone' => '' ], 'accept', '' ) );
		$this->assertNull( Cead_Acad_WA_Module::mensaje_buzon( 'reporte', (object) [ 'type' => 'anonymous', 'phone' => '595', 'ref_code' => 'X' ], 'accept', '' ) );
		$this->assertNull( Cead_Acad_WA_Module::mensaje_buzon( 'sugerencia', (object) [ 'phone' => '595' ], 'respond', '' ) );
		$this->assertNull( Cead_Acad_WA_Module::mensaje_buzon( 'reporte', (object) [ 'type' => 'confidential', 'phone' => '595', 'ref_code' => 'X' ], 'deny', 'x' ) );
	}

	/* --------------------------------------------------------- métricas */

	public function test_la_tasa_es_un_porcentaje_entero_y_sin_division_por_cero(): void {
		$this->assertSame( 0, Cead_Acad_Metricas::tasa( 0, 0 ) );
		$this->assertSame( 33, Cead_Acad_Metricas::tasa( 1, 3 ) );
		$this->assertSame( 67, Cead_Acad_Metricas::tasa( 2, 3 ) );
		$this->assertSame( 100, Cead_Acad_Metricas::tasa( 5, 5 ) );
	}
}
