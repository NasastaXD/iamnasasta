<?php
/**
 * El modelo se elige por la DIFICULTAD de la tarea, no por quién la pide.
 *
 * Que escriba un alumno o la directora no cambia lo difícil que es el trabajo:
 * leer una planilla de notas torcida cuesta igual sin importar quién mandó el
 * archivo. Lo que se compra subiendo de nivel no es calidad a secas — es
 * TIEMPO: el modelo grande tarda bastante más, y nadie espera que «¿qué clases
 * tengo hoy?» tarde diez segundos.
 *
 * De ahí el diseño: se arranca siempre en el nivel rápido y se escala solo
 * cuando el modelo muestra que va a hacer algo caro. Estos tests fijan la tabla
 * de dificultades y la comparación de niveles, que es lo que decide la escalada.
 */

use PHPUnit\Framework\TestCase;

final class AiNivelDificultadTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
		cead_test_set_option( 'cead_acad_wa_ai_model', 'modelo-general' );
	}

	/* ----------------------------- el opt-in -------------------------------- */

	/**
	 * Sin configurar nada, los tres niveles usan el modelo general. Si esto
	 * fallara, actualizar el plugin cambiaría solo el modelo con el que CEADI
	 * le contesta a todo el colegio.
	 *
	 * @dataProvider losTresNiveles
	 */
	public function test_sin_configurar_todo_usa_el_modelo_general( string $nivel ): void {
		$this->assertSame( 'modelo-general', Cead_Acad_WA_AI::model_nivel( $nivel ) );
	}

	public static function losTresNiveles(): array {
		return [
			'rápido' => [ Cead_Acad_WA_AI::NIVEL_RAPIDO ],
			'medio'  => [ Cead_Acad_WA_AI::NIVEL_MEDIO ],
			'máximo' => [ Cead_Acad_WA_AI::NIVEL_MAXIMO ],
		];
	}

	public function test_cada_nivel_usa_su_modelo(): void {
		cead_test_set_option( 'cead_acad_wa_ai_model_n1', 'modelo-veloz' );
		cead_test_set_option( 'cead_acad_wa_ai_model_n2', 'modelo-medio' );
		cead_test_set_option( 'cead_acad_wa_ai_model_n3', 'modelo-grande' );

		$this->assertSame( 'modelo-veloz',  Cead_Acad_WA_AI::model_nivel( Cead_Acad_WA_AI::NIVEL_RAPIDO ) );
		$this->assertSame( 'modelo-medio',  Cead_Acad_WA_AI::model_nivel( Cead_Acad_WA_AI::NIVEL_MEDIO ) );
		$this->assertSame( 'modelo-grande', Cead_Acad_WA_AI::model_nivel( Cead_Acad_WA_AI::NIVEL_MAXIMO ) );
	}

	/** Un nivel inventado cae al modelo general en vez de romper. */
	public function test_un_nivel_desconocido_cae_al_general(): void {
		cead_test_set_option( 'cead_acad_wa_ai_model_n1', 'modelo-veloz' );

		$this->assertSame( 'modelo-general', Cead_Acad_WA_AI::model_nivel( 'inventado' ) );
		$this->assertSame( 'modelo-general', Cead_Acad_WA_AI::model_nivel( '' ) );
	}

	/**
	 * La configuración vieja (charla/gestion/redaccion) se sigue leyendo. Sin
	 * esto, renombrar los niveles habría dejado huérfano lo ya cargado: las
	 * casillas se verían vacías y CEADI volvería al modelo general sin avisar.
	 */
	public function test_respeta_la_configuracion_con_los_nombres_viejos(): void {
		cead_test_set_option( 'cead_acad_wa_ai_model_charla', 'viejo-rapido' );
		cead_test_set_option( 'cead_acad_wa_ai_model_redaccion', 'viejo-grande' );

		$this->assertSame( 'viejo-rapido', Cead_Acad_WA_AI::model_nivel( Cead_Acad_WA_AI::NIVEL_RAPIDO ) );
		$this->assertSame( 'viejo-grande', Cead_Acad_WA_AI::model_nivel( Cead_Acad_WA_AI::NIVEL_MAXIMO ) );
	}

	/** Lo nuevo le gana a lo viejo cuando están los dos. */
	public function test_el_nombre_nuevo_tiene_prioridad(): void {
		cead_test_set_option( 'cead_acad_wa_ai_model_charla', 'viejo' );
		cead_test_set_option( 'cead_acad_wa_ai_model_n1', 'nuevo' );

		$this->assertSame( 'nuevo', Cead_Acad_WA_AI::model_nivel( Cead_Acad_WA_AI::NIVEL_RAPIDO ) );
	}

	/* -------------------- la tabla de dificultades -------------------------- */

	/**
	 * Las cuatro que no se pueden errar.
	 *
	 * `cargar_nota` es la más traicionera de todas: la aprueba un humano, pero
	 * «un 4 a Ana en Mate» SE LEE BIEN aunque sea la Ana equivocada. La
	 * aprobación no atrapa ese error, así que tiene que no cometerse.
	 *
	 * @dataProvider tareasDeNivelMaximo
	 */
	public function test_lo_que_no_se_puede_errar_pide_el_maximo( string $funcion ): void {
		$this->assertSame( Cead_Acad_WA_AI::NIVEL_MAXIMO, Cead_Acad_WA_AI::nivel_de( $funcion ) );
	}

	public static function tareasDeNivelMaximo(): array {
		return [
			'cargar una nota'     => [ 'cargar_nota' ],
			'mandar comunicado'   => [ 'enviar_comunicado' ],
			'escribir un artículo'=> [ 'crear_articulo' ],
			'recordar un dato'    => [ 'recordar' ],
		];
	}

	/** @dataProvider tareasDeNivelMedio */
	public function test_el_trabajo_con_red_pide_el_medio( string $funcion ): void {
		$this->assertSame( Cead_Acad_WA_AI::NIVEL_MEDIO, Cead_Acad_WA_AI::nivel_de( $funcion ) );
	}

	public static function tareasDeNivelMedio(): array {
		return [
			'crear evento'      => [ 'crear_evento' ],
			'crear invitación'  => [ 'crear_invitacion' ],
			'olvidar'           => [ 'olvidar' ],
			'buscar persona'    => [ 'buscar_persona' ],
			'ver notas curso'   => [ 'ver_notas_curso' ],
		];
	}

	/**
	 * Todo lo que no está en la tabla es rápido. Es el default y tiene que
	 * serlo: son los cientos de mensajes por día que deciden si CEADI se siente
	 * ágil o pesado.
	 *
	 * @dataProvider tareasRapidas
	 */
	public function test_lo_cotidiano_se_queda_en_rapido( string $funcion ): void {
		$this->assertSame( Cead_Acad_WA_AI::NIVEL_RAPIDO, Cead_Acad_WA_AI::nivel_de( $funcion ) );
	}

	public static function tareasRapidas(): array {
		return [
			'horario'              => [ 'horario' ],
			'notas propias'        => [ 'notas' ],
			'tareas'               => [ 'tareas' ],
			'comunicados'          => [ 'comunicados' ],
			'listar cursos'        => [ 'listar_cursos' ],
			'consultar calendario' => [ 'consultar_calendario' ],
			'métricas'             => [ 'consultar_metricas' ],
			'ver curso'            => [ 'ver_curso' ],
			'generar imagen'       => [ 'generar_imagen' ],
			'desconocida'          => [ 'algo_que_no_existe' ],
			'vacía'                => [ '' ],
		];
	}

	/* ---------------------- la comparación de niveles ----------------------- */

	/**
	 * De esto depende la escalada: si `nivel_mayor()` se equivocara, o CEADI
	 * escalaría siempre —perdiendo toda la velocidad que se buscaba— o no
	 * escalaría nunca, y cargaría las notas con el modelo chico.
	 */
	public function test_los_niveles_se_ordenan_bien(): void {
		$r = Cead_Acad_WA_AI::NIVEL_RAPIDO;
		$m = Cead_Acad_WA_AI::NIVEL_MEDIO;
		$x = Cead_Acad_WA_AI::NIVEL_MAXIMO;

		$this->assertTrue( Cead_Acad_WA_AI::nivel_mayor( $m, $r ) );
		$this->assertTrue( Cead_Acad_WA_AI::nivel_mayor( $x, $r ) );
		$this->assertTrue( Cead_Acad_WA_AI::nivel_mayor( $x, $m ) );
	}

	public function test_un_nivel_no_es_mayor_que_si_mismo(): void {
		foreach ( array_keys( Cead_Acad_WA_AI::niveles() ) as $n ) {
			$this->assertFalse( Cead_Acad_WA_AI::nivel_mayor( $n, $n ), "«{$n}» no puede ser mayor que sí mismo." );
		}
	}

	public function test_no_se_escala_hacia_abajo(): void {
		$this->assertFalse( Cead_Acad_WA_AI::nivel_mayor( Cead_Acad_WA_AI::NIVEL_RAPIDO, Cead_Acad_WA_AI::NIVEL_MAXIMO ) );
		$this->assertFalse( Cead_Acad_WA_AI::nivel_mayor( Cead_Acad_WA_AI::NIVEL_MEDIO, Cead_Acad_WA_AI::NIVEL_MAXIMO ) );
	}

	/* ----------------------------- el catálogo ------------------------------ */

	/**
	 * La pantalla de ajustes recorre este catálogo para dibujar Y para guardar.
	 * Si un nivel se cayera de la lista, su casilla desaparecería del formulario
	 * y el valor guardado quedaría huérfano: activo, invisible e imposible de
	 * cambiar desde el panel.
	 */
	public function test_el_catalogo_tiene_los_tres_niveles(): void {
		$niveles = Cead_Acad_WA_AI::niveles();

		$this->assertArrayHasKey( Cead_Acad_WA_AI::NIVEL_RAPIDO, $niveles );
		$this->assertArrayHasKey( Cead_Acad_WA_AI::NIVEL_MEDIO, $niveles );
		$this->assertArrayHasKey( Cead_Acad_WA_AI::NIVEL_MAXIMO, $niveles );
		$this->assertCount( 3, $niveles );
	}

	/** Toda dificultad declarada tiene que apuntar a un nivel que exista. */
	public function test_la_tabla_solo_usa_niveles_validos(): void {
		$validos = array_keys( Cead_Acad_WA_AI::niveles() );

		foreach ( Cead_Acad_WA_AI::dificultades() as $funcion => $nivel ) {
			$this->assertContains( $nivel, $validos, "«{$funcion}» apunta a un nivel inexistente." );
		}
	}

	/**
	 * Toda función declarada difícil tiene que EXISTIR de verdad. Un nombre mal
	 * escrito en la tabla no rompe nada visible: simplemente esa tarea nunca
	 * escala y se sigue haciendo con el modelo rápido, callada.
	 */
	public function test_la_tabla_no_nombra_funciones_inexistentes(): void {
		/*
		 * Los nombres reales se juntan de las TRES fuentes que existen, sin
		 * copiar ninguna lista acá: una lista duplicada en el test es la que
		 * después se desincroniza y hace que el test deje de probar nada.
		 *
		 *   1. Las acciones de menú (WA_AI).
		 *   2. Las herramientas de gestión (WA_Tools::GESTION).
		 *   3. El catálogo de consultas (WA_Tools, protegido → reflexión).
		 *   4. Las herramientas de personal, que el MOTOR arma inline; se leen
		 *      de la fuente porque solo existen dentro de un método privado que
		 *      necesita un usuario real para correr.
		 */
		$m = new ReflectionMethod( 'Cead_Acad_WA_Tools', 'catalogo' );
		$m->setAccessible( true );

		$fuente = (string) file_get_contents( dirname( __DIR__, 2 ) . '/modules/whatsapp/class-wa-engine.php' );
		preg_match_all( "/'name'\s*=>\s*'([a-z_]+)'/", $fuente, $mm );

		$reales = array_merge(
			array_keys( Cead_Acad_WA_AI::actions() ),
			Cead_Acad_WA_Tools::GESTION,
			array_keys( (array) $m->invoke( null ) ),
			$mm[1] ?? []
		);

		foreach ( array_keys( Cead_Acad_WA_AI::dificultades() ) as $funcion ) {
			$this->assertContains( $funcion, $reales, "«{$funcion}» no es una función real de CEADI." );
		}
	}

	/* ------------------- la visión le gana a la dificultad ------------------ */

	/**
	 * Con una foto adjunta manda el modelo de visión, no el del nivel. Si el
	 * nivel le ganara, CEADI recibiría la foto con un modelo sin ojos: no
	 * fallaría con un error claro, contestaría cualquier cosa sobre una imagen
	 * que nunca vio.
	 */
	public function test_con_imagen_manda_el_modelo_de_vision(): void {
		cead_test_set_option( 'cead_acad_wa_ai_key', 'sk-lo-que-sea' );
		cead_test_set_option( 'cead_acad_wa_vision_enabled', 1 );
		cead_test_set_option( 'cead_acad_wa_ai_model_n1', 'modelo-sin-ojos' );

		$this->assertNull( Cead_Acad_WA_AI::modelo_para_turno( Cead_Acad_WA_AI::NIVEL_RAPIDO, true ) );
	}

	public function test_sin_imagen_manda_el_nivel(): void {
		cead_test_set_option( 'cead_acad_wa_ai_key', 'sk-lo-que-sea' );
		cead_test_set_option( 'cead_acad_wa_vision_enabled', 1 );
		cead_test_set_option( 'cead_acad_wa_ai_model_n1', 'modelo-veloz' );

		$this->assertSame( 'modelo-veloz', Cead_Acad_WA_AI::modelo_para_turno( Cead_Acad_WA_AI::NIVEL_RAPIDO, false ) );
	}

	/** Con la visión apagada, una foto no cambia nada: no hay a dónde ir. */
	public function test_con_vision_apagada_la_imagen_no_cambia_el_modelo(): void {
		cead_test_set_option( 'cead_acad_wa_ai_key', 'sk-lo-que-sea' );
		cead_test_set_option( 'cead_acad_wa_vision_enabled', 0 );
		cead_test_set_option( 'cead_acad_wa_ai_model_n1', 'modelo-veloz' );

		$this->assertSame( 'modelo-veloz', Cead_Acad_WA_AI::modelo_para_turno( Cead_Acad_WA_AI::NIVEL_RAPIDO, true ) );
	}
}
