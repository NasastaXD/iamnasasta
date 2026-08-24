<?php
/**
 * Consultar el horario por docente, aula o momento — no solo por curso.
 *
 * Nace de un caso real. Alguien preguntó «¿la profe Sanny tiene alguna clase
 * ahora?» y CEADI contestó «¿de qué curso querés consultar el horario?». No fue
 * torpeza del modelo: la única herramienta de horarios estaba indexada por
 * CURSO, así que a una pregunta sobre una PERSONA solo podía responder con el
 * molde que tenía. Para contestarla de verdad habría tenido que recorrer los
 * cursos uno por uno, y el bucle no da para eso.
 *
 * La lección general, que vale para toda herramienta futura: si la gente
 * pregunta por situaciones («¿está?», «¿dónde?», «¿llego?») y las herramientas
 * están indexadas por entidades, el modelo va a pedir la entidad. La forma de
 * la herramienta decide qué preguntas se pueden contestar.
 */

use PHPUnit\Framework\TestCase;

final class HorarioConsultaTest extends TestCase {

	/** Un horario chico y realista: lunes, dos cursos, tres docentes. */
	private function franjas(): array {
		return [
			[ 'dia' => 1, 'inicio' => '13:30', 'fin' => '14:50', 'materia' => 'Matemática', 'docente' => 'Sanny Giménez', 'aula' => 'Aula 3', 'curso' => '3.º Ciencias Básicas' ],
			[ 'dia' => 1, 'inicio' => '14:50', 'fin' => '16:10', 'materia' => 'Física',     'docente' => 'Ramón Ayala',   'aula' => 'Lab 1',  'curso' => '3.º Ciencias Básicas' ],
			[ 'dia' => 1, 'inicio' => '07:10', 'fin' => '08:30', 'materia' => 'Historia',   'docente' => 'Sanny Giménez', 'aula' => 'Aula 5', 'curso' => '2.º Sociales' ],
			[ 'dia' => 5, 'inicio' => '13:30', 'fin' => '14:50', 'materia' => 'Literatura', 'docente' => 'Ana Penayo',    'aula' => 'Aula 3', 'curso' => '2.º Sociales' ],
		];
	}

	/** Lunes 14:20, exactamente el momento del caso real. */
	private function consultar( array $args, int $dia = 1, string $hora = '14:20' ): string {
		return Cead_Acad_WA_Tools::horario_texto( $this->franjas(), $args, $dia, $hora );
	}

	/* ------------------------- el caso del screenshot ----------------------- */

	/**
	 * «¿La profe Sanny tiene alguna clase ahora?» — lunes 14:20.
	 *
	 * Tiene Matemática de 13:30 a 14:50, así que la respuesta es que SÍ, y tiene
	 * que decir dónde: quien pregunta lo hace para ir a buscarla.
	 */
	public function test_contesta_si_una_docente_tiene_clase_ahora(): void {
		$r = $this->consultar( [ 'docente' => 'Sanny', 'momento' => 'ahora' ] );

		$this->assertStringContainsString( 'Matemática', $r );
		$this->assertStringContainsString( 'Aula 3', $r );
		$this->assertStringContainsString( '14:50', $r, 'Tiene que decir hasta qué hora, que es lo que decide si llego.' );
		// Y NO puede colarse la otra clase de Sanny, que fue a la mañana.
		$this->assertStringNotContainsString( 'Historia', $r );
	}

	/**
	 * A las 16:30 ya no tiene clase. Decir «no tiene clase» no es lo mismo que
	 * decir «no sé»: una manda a buscarla y la otra no.
	 */
	public function test_dice_que_no_tiene_clase_cuando_no_la_tiene(): void {
		$r = $this->consultar( [ 'docente' => 'Sanny', 'momento' => 'ahora' ], 1, '16:30' );

		$this->assertStringContainsString( 'Sanny', $r );
		$this->assertStringContainsStringIgnoringCase( 'no tiene clase', $r );
	}

	/** El borde exacto: a las 14:50 la clase YA terminó, no está en curso. */
	public function test_el_fin_de_la_clase_no_cuenta_como_ahora(): void {
		$en_clase = $this->consultar( [ 'docente' => 'Sanny', 'momento' => 'ahora' ], 1, '14:49' );
		$fuera    = $this->consultar( [ 'docente' => 'Sanny', 'momento' => 'ahora' ], 1, '14:50' );

		$this->assertStringContainsString( 'Matemática', $en_clase );
		$this->assertStringContainsStringIgnoringCase( 'no tiene clase', $fuera );
	}

	/** Y el de arranque: a las 13:30 en punto ya empezó. */
	public function test_el_inicio_de_la_clase_si_cuenta(): void {
		$r = $this->consultar( [ 'docente' => 'Sanny', 'momento' => 'ahora' ], 1, '13:30' );

		$this->assertStringContainsString( 'Matemática', $r );
	}

	/* ---------------------------- los otros ejes ---------------------------- */

	/** Por aula: «¿qué hay en el Aula 3?» */
	public function test_busca_por_aula(): void {
		$r = $this->consultar( [ 'aula' => 'Aula 3' ] );

		$this->assertStringContainsString( 'Matemática', $r );
		$this->assertStringContainsString( 'Literatura', $r );
		$this->assertStringNotContainsString( 'Física', $r );
	}

	/** Por materia, sin importar el curso. */
	public function test_busca_por_materia_en_todos_los_cursos(): void {
		$r = $this->consultar( [ 'materia' => 'física' ] );

		$this->assertStringContainsString( 'Física', $r );
		$this->assertStringContainsString( 'Lab 1', $r );
	}

	/** Por día, escrito como lo escribe una persona. */
	public function test_busca_por_dia_en_palabras(): void {
		$r = $this->consultar( [ 'dia' => 'viernes' ] );

		$this->assertStringContainsString( 'Literatura', $r );
		$this->assertStringNotContainsString( 'Matemática', $r );
	}

	/** «miércoles» y «miercoles» tienen que ser lo mismo. */
	public function test_el_dia_tolera_tildes_y_abreviaturas(): void {
		$franjas = [ [ 'dia' => 3, 'inicio' => '08:00', 'fin' => '09:00', 'materia' => 'Química', 'docente' => 'X', 'aula' => 'A', 'curso' => 'C' ] ];

		foreach ( [ 'miércoles', 'miercoles', 'Miércoles', 'mié', 'mie', '3' ] as $escrito ) {
			$r = Cead_Acad_WA_Tools::horario_texto( $franjas, [ 'dia' => $escrito ], 1, '10:00' );
			$this->assertStringContainsString( 'Química', $r, "«{$escrito}» tendría que entenderse como miércoles." );
		}
	}

	/** Buscar un nombre sin tildes tiene que encontrar al docente con tildes. */
	public function test_el_docente_se_encuentra_sin_tildes(): void {
		$r = $this->consultar( [ 'docente' => 'gimenez' ] );

		$this->assertStringContainsString( 'Sanny Giménez', $r );
	}

	/** Combinar filtros los suma, no los reemplaza. */
	public function test_los_filtros_se_combinan(): void {
		$r = $this->consultar( [ 'docente' => 'Sanny', 'dia' => 'lunes', 'materia' => 'historia' ] );

		$this->assertStringContainsString( 'Historia', $r );
		$this->assertStringNotContainsString( 'Matemática', $r );
	}

	/* ------------------------------- momentos -------------------------------- */

	public function test_hoy_trae_todo_el_dia_y_no_solo_lo_que_esta_pasando(): void {
		$r = $this->consultar( [ 'docente' => 'Sanny', 'momento' => 'hoy' ] );

		// Las dos clases de Sanny del lunes, incluida la de la mañana.
		$this->assertStringContainsString( 'Historia', $r );
		$this->assertStringContainsString( 'Matemática', $r );
	}

	public function test_manana_avanza_un_dia(): void {
		$r = Cead_Acad_WA_Tools::horario_texto(
			[ [ 'dia' => 2, 'inicio' => '08:00', 'fin' => '09:00', 'materia' => 'Biología', 'docente' => 'Z', 'aula' => 'A', 'curso' => 'C' ] ],
			[ 'momento' => 'manana' ],
			1,
			'14:20'
		);

		$this->assertStringContainsString( 'Biología', $r );
	}

	/** Y el domingo, «mañana» vuelve al lunes en vez de irse a un día 8. */
	public function test_manana_pasa_de_domingo_a_lunes(): void {
		$r = Cead_Acad_WA_Tools::horario_texto(
			[ [ 'dia' => 1, 'inicio' => '08:00', 'fin' => '09:00', 'materia' => 'Cívica', 'docente' => 'Z', 'aula' => 'A', 'curso' => 'C' ] ],
			[ 'momento' => 'manana' ],
			7,
			'10:00'
		);

		$this->assertStringContainsString( 'Cívica', $r );
	}

	/* -------------------------------- bordes --------------------------------- */

	/**
	 * Una franja sin hora de fin no puede contar como «ahora»: afirmar que
	 * alguien está en clase manda a una persona a golpear una puerta, y hacerlo
	 * sin saberlo es peor que decir que no hay dato.
	 */
	public function test_sin_hora_de_fin_no_se_afirma_que_esta_en_clase(): void {
		$r = Cead_Acad_WA_Tools::horario_texto(
			[ [ 'dia' => 1, 'inicio' => '13:30', 'fin' => '', 'materia' => 'Taller', 'docente' => 'Sanny', 'aula' => 'A', 'curso' => 'C' ] ],
			[ 'docente' => 'Sanny', 'momento' => 'ahora' ],
			1,
			'14:20'
		);

		$this->assertStringNotContainsString( 'Taller', $r );
	}

	/** Sin filtros devuelve todo, ordenado por día y hora. */
	public function test_sin_filtros_devuelve_todo_ordenado(): void {
		$r = $this->consultar( [] );

		$this->assertStringContainsString( 'Historia', $r );
		// La de las 07:10 va antes que la de las 13:30 del mismo día.
		$this->assertLessThan( strpos( $r, 'Matemática' ), strpos( $r, 'Historia' ) );
	}

	/** Franjas con día inválido se ignoran en vez de romper la respuesta. */
	public function test_ignora_franjas_con_dia_invalido(): void {
		$r = Cead_Acad_WA_Tools::horario_texto(
			[
				[ 'dia' => 0, 'inicio' => '08:00', 'fin' => '09:00', 'materia' => 'Rota', 'docente' => 'X', 'aula' => 'A', 'curso' => 'C' ],
				[ 'dia' => 1, 'inicio' => '08:00', 'fin' => '09:00', 'materia' => 'Buena', 'docente' => 'X', 'aula' => 'A', 'curso' => 'C' ],
			],
			[],
			1,
			'10:00'
		);

		$this->assertStringContainsString( 'Buena', $r );
		$this->assertStringNotContainsString( 'Rota', $r );
	}

	/** Un horario vacío no devuelve una respuesta vacía. */
	public function test_sin_horario_cargado_lo_dice(): void {
		$r = Cead_Acad_WA_Tools::horario_texto( [], [ 'docente' => 'Sanny' ], 1, '14:20' );

		$this->assertNotSame( '', trim( $r ) );
		$this->assertStringContainsStringIgnoringCase( 'no', $r );
	}

	/* ------------------------ «¿qué materias da X?» -------------------------- */

	/**
	 * La pregunta tiene que poder contestarse SIN filtrar por curso ni por día:
	 * se le pasa solo el docente y vuelven todas sus franjas, de donde salen las
	 * materias. Si esto fallara, CEADI volvería a pedir un curso que nadie
	 * mencionó.
	 */
	public function test_se_puede_saber_que_materias_da_un_docente(): void {
		$r = $this->consultar( [ 'docente' => 'Sanny' ] );

		$this->assertStringContainsString( 'Matemática', $r );
		$this->assertStringContainsString( 'Historia', $r );
		// Y solo las suyas.
		$this->assertStringNotContainsString( 'Literatura', $r );
	}

	/** Con los cursos, que es lo primero que se pregunta después. */
	public function test_dice_en_que_cursos_las_da(): void {
		$r = $this->consultar( [ 'docente' => 'Sanny' ] );

		$this->assertStringContainsString( '3.º Ciencias Básicas', $r );
		$this->assertStringContainsString( '2.º Sociales', $r );
	}

	/**
	 * El caso peligroso: alcance acotado a los cursos propios.
	 *
	 * Un alumno de 2.º pregunta «¿qué materias da Sanny?». Si ella le da Historia
	 * a su curso y Matemática a otros dos, sin avisar que solo se miró su curso
	 * la respuesta sería «Historia»: completa, segura y equivocada. Una respuesta
	 * parcial presentada como total es peor que no contestar, porque nadie la
	 * sale a verificar.
	 */
	public function test_avisa_cuando_solo_miro_el_curso_propio(): void {
		$soloSuCurso = [ $this->franjas()[2] ]; // Historia, 2.º Sociales

		$r = Cead_Acad_WA_Tools::horario_texto( $soloSuCurso, [ 'docente' => 'Sanny' ], 1, '14:20', true );

		$this->assertStringContainsString( 'Historia', $r );
		$this->assertStringContainsStringIgnoringCase( 'Alcance:', $r );
	}

    /** Y también cuando no encontró nada: ahí el aviso importa MÁS. */
	public function test_avisa_del_alcance_aunque_no_encuentre_nada(): void {
		$r = Cead_Acad_WA_Tools::horario_texto( [], [ 'docente' => 'Sanny' ], 1, '14:20', true );

		$this->assertStringContainsStringIgnoringCase( 'Alcance:', $r );
	}

	/** Con permiso para ver todo, el aviso no aparece: sería ruido y confundiría. */
	public function test_sin_acotar_no_avisa_nada(): void {
		$r = $this->consultar( [ 'docente' => 'Sanny' ] );

		$this->assertStringNotContainsStringIgnoringCase( 'Alcance:', $r );
	}

	/**
	 * La nota del alcance va entre corchetes y dirigida al modelo, no redactada
	 * como una frase lista para mostrar. Escrita como frase, terminaba pegada al
	 * final de cada respuesta: alguien pregunta a qué hora tiene Matemática y se
	 * lleva una advertencia sobre otros cursos que nunca preguntó. Una
	 * aclaración que no cambia lo que la persona haría es ruido, y el ruido
	 * enseña a no leer.
	 */
	public function test_la_nota_del_alcance_es_para_el_modelo_no_para_copiar(): void {
		$r = Cead_Acad_WA_Tools::horario_texto( $this->franjas(), [ 'docente' => 'Sanny' ], 1, '14:20', true );

		$this->assertStringContainsString( '[Alcance:', $r );
		$this->assertStringContainsStringIgnoringCase( 'SOLO si', $r, 'Tiene que decirle CUÁNDO mencionarlo, no solo el hecho.' );
	}

	/* --------------------------- la herramienta ------------------------------ */

	/** Tiene que estar registrada como CONSULTA: se ejecuta sola, no escribe nada. */
	public function test_es_una_consulta_y_no_una_escritura(): void {
		$this->assertTrue( Cead_Acad_WA_Tools::es_consulta( 'consultar_horario' ) );
		$this->assertFalse( Cead_Acad_WA_Tools::es_gestion( 'consultar_horario' ) );
	}
}
