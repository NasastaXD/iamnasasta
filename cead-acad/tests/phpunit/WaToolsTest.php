<?php
/**
 * Herramientas de consulta de CEADI: qué ve cada persona y qué se le permite
 * ejecutar.
 *
 * El filtro por permiso es lo importante de esta clase. No es cosmético: lo que
 * alguien no puede ver no entra en la lista que se le manda al modelo, así que
 * el modelo ni siquiera sabe que la consulta existe y no puede ofrecerla.
 *
 * Las consultas en sí (las que van a la base) quedan fuera de esta suite, que
 * corre sin WordPress; lo que se prueba acá es el catálogo y el permiso.
 */

use PHPUnit\Framework\TestCase;

final class WaToolsTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_caps();
	}

	/** @return string[] nombres de las herramientas que recibe ese usuario */
	private function nombres( $uid ) {
		return array_map( static function ( $t ) {
			return $t['function']['name'];
		}, Cead_Acad_WA_Tools::specs( $uid ) );
	}

	public function test_sin_usuario_identificado_no_hay_ninguna_consulta(): void {
		$this->assertSame( [], Cead_Acad_WA_Tools::specs( 0 ) );
	}

	/**
	 * Alguien identificado pero sin permisos de gestión solo ve lo que no pide
	 * permiso. Listar los cursos es público adentro del colegio; ver quién está
	 * inscripto en uno, no.
	 */
	public function test_usuario_sin_permisos_solo_ve_las_consultas_abiertas(): void {
		cead_test_set_caps( 7, [] );
		$n = $this->nombres( 7 );

		$this->assertContains( 'listar_cursos', $n );
		$this->assertNotContains( 'buscar_persona', $n );
		$this->assertNotContains( 'consultar_metricas', $n );
		$this->assertNotContains( 'ver_curso', $n );
	}

	/**
	 * El calendario lo consulta CUALQUIERA.
	 *
	 * Estaba detrás del permiso de gestionar el calendario, así que para casi
	 * todo el mundo la herramienta no existía: al preguntar por la fecha de un
	 * acto, el modelo se quedaba sin ninguna forma de mirar el calendario, caía
	 * en buscar entre las notas del sitio y contestaba que no había nada
	 * publicado sobre eso —con el evento cargado—. Las fechas del colegio son
	 * justo lo que todo el mundo pregunta.
	 */
	public function test_el_calendario_lo_puede_consultar_cualquiera(): void {
		cead_test_set_caps( 7, [] );
		$this->assertContains( 'consultar_calendario', $this->nombres( 7 ) );
	}

	public function test_direccion_ve_todo_el_catalogo(): void {
		cead_test_set_caps( 9, [
			'cead_acad_view_metrics'         => true,
			'cead_acad_manage_courses'       => true,
			'cead_acad_view_other_schedules' => true,
			'cead_acad_manage_roles'         => true,
			'cead_acad_manage_schedule'      => true,
		] );
		$n = $this->nombres( 9 );

		foreach ( [ 'consultar_metricas', 'listar_cursos', 'ver_curso', 'ver_horario_curso', 'buscar_persona', 'consultar_calendario' ] as $esperada ) {
			$this->assertContains( $esperada, $n );
		}
	}

	/** El teléfono y el estado de la cuenta piden el permiso más alto. */
	public function test_buscar_persona_pide_gestion_de_roles(): void {
		cead_test_set_caps( 5, [ 'cead_acad_manage_courses' => true ] );
		$this->assertNotContains( 'buscar_persona', $this->nombres( 5 ) );

		cead_test_set_caps( 5, [ 'cead_acad_manage_roles' => true ] );
		$this->assertContains( 'buscar_persona', $this->nombres( 5 ) );
	}

	/**
	 * El permiso se vuelve a mirar al EJECUTAR, no solo al armar la lista: entre
	 * que se mandó el prompt y que volvió la respuesta del modelo pudo haber
	 * cambiado el rol de esa persona.
	 */
	public function test_ejecutar_sin_permiso_no_devuelve_datos(): void {
		cead_test_set_caps( 4, [] );
		$this->assertSame( 'Sin permiso para esa consulta.', Cead_Acad_WA_Tools::run( 'buscar_persona', [ 'texto' => 'x' ], 4 ) );
	}

	public function test_una_consulta_inventada_no_ejecuta_nada(): void {
		cead_test_set_caps( 4, [ 'cead_acad_manage_roles' => true ] );
		$this->assertSame( 'Esa consulta no existe.', Cead_Acad_WA_Tools::run( 'borrar_todo', [], 4 ) );
	}

	/* ------------------------------------------- separación de los dos tipos */

	/**
	 * La distinción que sostiene todo el diseño: las consultas las resuelve el
	 * bucle y su resultado vuelve al modelo; las de gestión cortan el bucle y
	 * vuelven al motor, que pide aprobación humana antes de escribir nada.
	 * Si una de gestión se colara como consulta, se ejecutaría sola.
	 */
	public function test_las_herramientas_de_gestion_no_son_consultas(): void {
		foreach ( [ 'enviar_comunicado', 'crear_articulo', 'crear_evento', 'crear_invitacion' ] as $accion ) {
			$this->assertFalse(
				Cead_Acad_WA_Tools::es_consulta( $accion ),
				"{$accion} escribe: si contara como consulta, el bucle la ejecutaría sin que nadie la apruebe."
			);
		}
	}

	public function test_las_consultas_se_reconocen_como_tales(): void {
		foreach ( [ 'consultar_metricas', 'listar_cursos', 'ver_curso', 'buscar_persona', 'consultar_calendario' ] as $c ) {
			$this->assertTrue( Cead_Acad_WA_Tools::es_consulta( $c ) );
		}
	}

	/* ------------------------------------------ cómo se lee una fecha ------ */

	/** @return mixed */
	private function priv( $metodo, ...$args ) {
		$m = new ReflectionMethod( 'Cead_Acad_WA_Tools', $metodo );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	/**
	 * La fecha se corta de la cadena, no se pasa por strtotime()/date(): el dato
	 * ya está guardado en hora local del colegio y convertirlo lo único que
	 * puede hacer es correrlo unas horas.
	 */
	public function test_la_fecha_se_lee_como_la_diria_una_persona(): void {
		$this->assertSame( '12/08/2026 08:30', $this->priv( 'fecha_legible', '2026-08-12 08:30:00' ) );
	}

	/** Medianoche es «sin hora»: un feriado no empieza a las 00:00, dura todo el día. */
	public function test_la_medianoche_no_se_imprime_como_hora(): void {
		$this->assertSame( '12/08/2026', $this->priv( 'fecha_legible', '2026-08-12 00:00:00' ) );
	}

	public function test_una_fecha_vacia_no_inventa_nada(): void {
		$this->assertSame( '', $this->priv( 'fecha_legible', '' ) );
	}

	/**
	 * Que el «a cuánto está» venga masticado es la diferencia entre contestar
	 * bien y contestar convencido: si el modelo tiene que hacer la aritmética de
	 * fechas solo, dice «el viernes» mirando un año que no es.
	 */
	public function test_a_cuanto_esta_cada_evento(): void {
		$hoy = '2026-08-12';

		$this->assertSame( 'HOY',      $this->priv( 'cuando', '2026-08-12 09:00:00', '', $hoy ) );
		$this->assertSame( 'mañana',   $this->priv( 'cuando', '2026-08-13 09:00:00', '', $hoy ) );
		$this->assertSame( 'faltan 9 días', $this->priv( 'cuando', '2026-08-21 09:00:00', '', $hoy ) );
		$this->assertSame( 'ya pasó',  $this->priv( 'cuando', '2026-08-01 09:00:00', '', $hoy ) );
	}

	/**
	 * El caso de las vacaciones: un período que ya arrancó y todavía no terminó.
	 * Decir «ya pasó» porque empezó antes de hoy sería exactamente al revés.
	 */
	public function test_un_periodo_en_marcha_esta_en_curso_no_pasado(): void {
		$this->assertSame(
			'EN CURSO ahora',
			$this->priv( 'cuando', '2026-07-06 00:00:00', '2026-07-24 00:00:00', '2026-07-15' )
		);
	}

	public function test_un_periodo_terminado_si_es_pasado(): void {
		$this->assertSame( 'ya pasó', $this->priv( 'cuando', '2026-07-06 00:00:00', '2026-07-24 00:00:00', '2026-08-12' ) );
	}

	/** Una fecha de fin corrupta (anterior al inicio) no puede volver pasado un evento futuro. */
	public function test_un_fin_anterior_al_inicio_se_ignora(): void {
		$this->assertSame( 'faltan 9 días', $this->priv( 'cuando', '2026-08-21 09:00:00', '2020-01-01 00:00:00', '2026-08-12' ) );
	}

	/**
	 * `es_consulta()` mira una lista de nombres suelta, no el catálogo entero
	 * —armarlo para chequear una clave costaba seis specs traducidas por vuelta
	 * del bucle—. El precio es que ahora hay dos lugares que pueden divergir: si
	 * se agrega una consulta al catálogo y no a la lista, el bucle no la
	 * ejecutaría y la devolvería al motor como si fuera una pantalla del menú.
	 */
	public function test_la_lista_de_consultas_no_se_desincroniza_del_catalogo(): void {
		cead_test_set_caps( 9, [
			'cead_acad_view_metrics'         => true,
			'cead_acad_manage_courses'       => true,
			'cead_acad_view_other_schedules' => true,
			'cead_acad_manage_roles'         => true,
			'cead_acad_manage_schedule'      => true,
		] );

		$delCatalogo = $this->nombres( 9 );
		sort( $delCatalogo );
		$deLaLista = Cead_Acad_WA_Tools::CONSULTAS;
		sort( $deLaLista );

		$this->assertSame( $deLaLista, $delCatalogo );
	}

	/** Ninguna acción que escribe puede figurar además como consulta. */
	public function test_gestion_y_consulta_no_se_pisan(): void {
		$this->assertSame(
			[],
			array_intersect( Cead_Acad_WA_Tools::GESTION, Cead_Acad_WA_Tools::CONSULTAS )
		);
	}

	/* ------------------------------------- el bucle no filtra al motor ---- */

	/**
	 * Si el bucle corta por tiempo con una consulta a medio pedir, esa consulta
	 * NO puede volver al motor como si fuera una intención: el motor buscaría
	 * una pantalla llamada «listar_cursos», que no existe, y el turno moriría
	 * en un error en vez de contestar con lo que ya se sabía.
	 */
	public function test_una_consulta_nunca_vuelve_al_motor_como_intencion(): void {
		$m = new ReflectionMethod( 'Cead_Acad_WA_AI', 'parse_tools_mode' );
		$m->setAccessible( true );

		$respuesta = [ 'data' => [ 'choices' => [ [ 'message' => [
			'content'    => 'Dejame ver los cursos.',
			'tool_calls' => [ [ 'id' => 'x', 'function' => [ 'name' => 'listar_cursos', 'arguments' => '{}' ] ] ],
		] ] ] ] ];
		$base = [ 'ok' => false, 'code' => 200, 'error' => '', 'intent' => '', 'reply' => '', 'content' => '', 'args' => [] ];

		$out = $m->invoke( null, $respuesta, $base, [ 'listar_cursos', 'horario' ] );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( '', $out['intent'], 'Una consulta no es una pantalla del menú.' );
		$this->assertNotSame( '', $out['reply'], 'Y lo que alcanzó a decir tiene que llegar igual.' );
	}

	/** Una acción de gestión sí tiene que llegar al motor, que pide aprobación. */
	public function test_una_gestion_si_vuelve_al_motor(): void {
		$m = new ReflectionMethod( 'Cead_Acad_WA_AI', 'parse_tools_mode' );
		$m->setAccessible( true );

		$respuesta = [ 'data' => [ 'choices' => [ [ 'message' => [
			'content'    => 'Va.',
			'tool_calls' => [ [ 'id' => 'y', 'function' => [ 'name' => 'crear_evento', 'arguments' => '{"titulo":"Acto","fecha":"10/05/2026"}' ] ] ],
		] ] ] ] ];
		$base = [ 'ok' => false, 'code' => 200, 'error' => '', 'intent' => '', 'reply' => '', 'content' => '', 'args' => [] ];

		$out = $m->invoke( null, $respuesta, $base, [ 'crear_evento' ] );

		$this->assertSame( 'crear_evento', $out['intent'] );
		$this->assertSame( 'Acto', $out['args']['titulo'] );
	}
}
