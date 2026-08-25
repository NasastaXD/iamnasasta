<?php
/**
 * Herramientas de CONSULTA de CEADI.
 *
 * La diferencia con las herramientas que ya existían en el motor es cuándo se
 * ejecutan. Las de gestión —mandar un comunicado, publicar un artículo, crear
 * un evento— no se ejecutan solas nunca: vuelven al motor, que le muestra a la
 * persona un resumen con Aceptar / Editar / Cancelar. Eso está bien y no se
 * toca: son escrituras, y una escritura sin aprobación humana es justo lo que
 * no queremos.
 *
 * Las de acá son de LECTURA. No cambian nada, así que se pueden ejecutar en el
 * momento y devolverle el resultado al modelo para que siga razonando. Es lo
 * que le faltaba a CEADI para dejar de ser un menú con lenguaje natural: antes
 * podía nombrar UNA función y ahí terminaba su turno, sin llegar a ver nunca
 * el resultado. No podía encadenar («buscá el curso y después decime quiénes
 * están») ni verificar antes de afirmar algo.
 *
 * Cada herramienta declara qué permiso pide. El filtro es real, no cosmético:
 * lo que la persona no puede ver, el modelo ni siquiera sabe que existe,
 * porque la herramienta no entra en su lista.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Tools {

	/** Cuántas filas se listan como mucho en una respuesta. Un WhatsApp no es una planilla. */
	const TOPE_FILAS = 25;

	/**
	 * Los nombres de las consultas, sueltos.
	 *
	 * `es_consulta()` se llama una vez por vuelta del bucle de la IA, otra en
	 * `parse_tools_mode()` y otra en el panel: armar el catálogo entero —seis
	 * specs anidadas con sus descripciones traducidas— para mirar una clave era
	 * pagar todo eso cada vez.
	 */
	const CONSULTAS = [
		'consultar_metricas',
		'listar_cursos',
		'ver_curso',
		'ver_horario_curso',
		'consultar_horario',
		'buscar_persona',
		'consultar_calendario',
	];

	/**
	 * Las acciones que ESCRIBEN en el sistema.
	 *
	 * Vive acá, y no repetida en cada quien la necesita, porque el motor y el
	 * panel web tienen que estar de acuerdo sobre qué exige aprobación humana:
	 * si una lista se desactualiza respecto de la otra, o se ejecuta algo sin
	 * confirmar o se manda a WhatsApp una consulta que no lo necesita.
	 */
	const GESTION = [
		'enviar_comunicado',
		'crear_evento',
		'crear_invitacion',
		'cargar_nota',
		'crear_articulo',
		'generar_imagen',
		'recordar',
		'olvidar',
	];

	/**
	 * Catálogo: nombre => [ cap, spec para el modelo ].
	 *
	 * `cap` null = alcanza con estar identificado. El resto se chequea contra
	 * el usuario real de WordPress, así que un cambio de rol se refleja solo.
	 */
	protected static function catalogo() {
		return [
			'consultar_metricas' => [
				'cap'  => 'cead_acad_view_metrics',
				'spec' => [
					'name'        => 'consultar_metricas',
					'description' => 'Números del colegio ahora mismo: cuánta gente hay por rol, cuántos cursos, '
						. 'cuántos eventos vienen y cuánto se publicó último. Usalo cuando te pregunten "cómo venimos", '
						. '"cuántos alumnos hay" o cualquier panorama general.',
					'parameters'  => [ 'type' => 'object', 'properties' => (object) [] ],
				],
			],
			'listar_cursos' => [
				'cap'  => null,
				'spec' => [
					'name'        => 'listar_cursos',
					'description' => 'Lista los cursos del colegio con su turno y cuánta gente tiene inscripta. '
						. 'Útil como paso previo cuando te nombran un curso y necesitás el título exacto.',
					'parameters'  => [ 'type' => 'object', 'properties' => (object) [] ],
				],
			],
			'ver_curso' => [
				'cap'  => 'cead_acad_manage_courses',
				'spec' => [
					'name'        => 'ver_curso',
					'description' => 'Ficha de un curso: delegado/a, tutor/a y quiénes están inscriptos.',
					'parameters'  => [
						'type'       => 'object',
						'properties' => [
							'curso' => [ 'type' => 'string', 'description' => 'Título del curso. No hace falta que sea exacto: se busca por aproximación.' ],
						],
						'required'   => [ 'curso' ],
					],
				],
			],
			'ver_horario_curso' => [
				'cap'  => 'cead_acad_view_other_schedules',
				'spec' => [
					'name'        => 'ver_horario_curso',
					'description' => 'El horario semanal de clases de un curso: materia, hora, docente y aula.',
					'parameters'  => [
						'type'       => 'object',
						'properties' => [
							'curso' => [ 'type' => 'string', 'description' => 'Título del curso, aproximado.' ],
						],
						'required'   => [ 'curso' ],
					],
				],
			],
			'buscar_persona' => [
				// Devuelve teléfono y estado de la cuenta: se pide el permiso más
				// alto, el mismo que hace falta para gestionar roles.
				'cap'  => 'cead_acad_manage_roles',
				'spec' => [
					'name'        => 'buscar_persona',
					'description' => 'Busca a alguien del colegio por nombre, usuario o documento y devuelve su rol, '
						. 'curso, teléfono y si la cuenta está activa o suspendida.',
					'parameters'  => [
						'type'       => 'object',
						'properties' => [
							'texto' => [ 'type' => 'string', 'description' => 'Nombre, apellido, usuario o documento. Alcanza con una parte.' ],
						],
						'required'   => [ 'texto' ],
					],
				],
			],
			/*
			 * El horario mirado por CUALQUIER lado, no solo por curso.
			 *
			 * Existe por un caso real: «¿la profe Sanny tiene clase ahora?», que
			 * CEADI contestaba con «¿de qué curso querés el horario?». No era
			 * torpeza del modelo: la única herramienta de horarios estaba
			 * indexada por curso, así que para responder habría tenido que
			 * recorrer los cursos uno por uno, y el bucle no da para eso. Le
			 * pidieron un dato sobre una PERSONA y solo tenía un molde con forma
			 * de CURSO, así que preguntó por el curso.
			 *
			 * La gente no pregunta por entidades, pregunta por situaciones:
			 * quién está, dónde, a qué hora, si puedo ir a buscarlo. Una
			 * herramienta con filtros contesta todas esas de una sola llamada.
			 */
			'consultar_horario' => [
				/*
				 * Sin permiso NO se bloquea la consulta: se acota a los cursos
				 * propios (ver `horario_buscar()`). Bloquearla entera dejaría a
				 * un alumno sin poder preguntar por su propia clase; abrirla
				 * entera sería una puerta lateral para leer el horario de otro
				 * curso sin el permiso que hoy exige `ver_horario_curso`. El
				 * permiso define el ALCANCE, no el acceso — mismo criterio que
				 * `consultar_calendario`.
				 */
				'cap'  => null,
				'spec' => [
					'name'        => 'consultar_horario',
					'description' => 'Busca en el horario de clases por cualquier combinación de docente, curso, materia, aula, '
						. 'día y momento. Usalo para preguntas sobre PERSONAS y LUGARES, no solo sobre cursos: «¿la profe Sanny '
						. 'tiene clase ahora?», «¿dónde está el profe de Física?», «¿qué hay en el aula 3 a la tarde?», «¿quién da '
						. 'clase el viernes a primera hora?». Sin ningún filtro devuelve todo el horario, así que poné al menos uno. '
						. 'Con momento="ahora" te dice qué está ocurriendo en este momento, ya calculado por el sistema: no deduzcas '
						. 'vos la hora ni el día.',
					'parameters'  => [
						'type'       => 'object',
						'properties' => [
							'docente' => [
								'type'        => 'string',
								'description' => 'Nombre o parte del nombre del docente («Sanny», «Gimenez»).',
							],
							'curso'   => [
								'type'        => 'string',
								'description' => 'Título del curso, aproximado.',
							],
							'materia' => [
								'type'        => 'string',
								'description' => 'Nombre o parte de la materia («mate», «física»).',
							],
							'aula'    => [
								'type'        => 'string',
								'description' => 'Aula o sala.',
							],
							'dia'     => [
								'type'        => 'string',
								'description' => 'Día de la semana («lunes», «viernes»). Vacío = cualquier día.',
							],
							'momento' => [
								'type'        => 'string',
								'description' => '"ahora" (lo que está ocurriendo en este momento), "hoy" o "manana". Vacío = toda la semana.',
							],
						],
					],
				],
			],

			/*
			 * El calendario NO pide permiso, y ese es el punto.
			 *
			 * Antes esta consulta se llamaba `agenda_institucional` y estaba detrás
			 * de `cead_acad_manage_schedule`, así que para el 99% de la gente
			 * simplemente no existía: al preguntar «¿cuándo es la Feria de las
			 * Naciones?» el modelo no tenía ninguna herramienta de fechas, caía en
			 * buscar_noticias y contestaba «no encontré ninguna nota publicada sobre
			 * eso» — con el evento cargado en el calendario.
			 *
			 * Las fechas del colegio son justo lo que todo el mundo pregunta. Lo que
			 * sigue dependiendo del permiso es el ALCANCE, no el acceso: ver
			 * `calendario()`.
			 */
			'consultar_calendario' => [
				'cap'  => null,
				'spec' => [
					'name'        => 'consultar_calendario',
					'description' => 'El calendario del colegio: feriados, actos, exámenes, entregas, excursiones, reuniones '
						. 'y períodos largos como vacaciones o cierre de etapa. Usalo SIEMPRE que la pregunta tenga que ver '
						. 'con una fecha —«cuándo es tal cosa», «qué hay esta semana», «hay clases el lunes», «cuándo empiezan '
						. 'las vacaciones»—, incluso si el evento no te suena: que vos no lo sepas no quiere decir que no esté '
						. 'cargado. Para buscar algo puntual pasá su nombre en «texto»; sin texto te devuelve lo que viene por '
						. 'orden de fecha. No confundas esto con buscar_noticias, que busca artículos publicados, no fechas.',
					'parameters'  => [
						'type'       => 'object',
						'properties' => [
							'texto' => [
								'type'        => 'string',
								'description' => 'Nombre del evento o una parte («feria de las naciones», «vacaciones», «examen»). '
									. 'Vacío = lista lo que viene.',
							],
							'dias'  => [
								'type'        => 'integer',
								'description' => 'Cuántos días hacia adelante mirar cuando no buscás por nombre (por defecto 60).',
							],
						],
					],
				],
			],
		];
	}

	/** ¿Esta herramienta la resuelve el sistema en el momento (lectura)? */
	public static function es_consulta( $nombre ) {
		return in_array( (string) $nombre, self::CONSULTAS, true );
	}

	/** ¿Esta acción escribe y por lo tanto necesita que una persona la apruebe? */
	public static function es_gestion( $nombre ) {
		return in_array( (string) $nombre, self::GESTION, true );
	}

	/**
	 * Specs de las herramientas que ESTA persona puede usar, en el formato que
	 * espera el endpoint de chat.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function specs( $user_id ) {
		$out = [];
		if ( ! $user_id ) { return $out; }
		foreach ( self::catalogo() as $def ) {
			if ( $def['cap'] && ! user_can( (int) $user_id, $def['cap'] ) ) { continue; }
			$out[] = [ 'type' => 'function', 'function' => $def['spec'] ];
		}
		return $out;
	}

	/**
	 * Ejecuta una consulta y devuelve TEXTO para que el modelo lo lea.
	 *
	 * Se devuelve texto y no JSON a propósito: lo que sigue es que el modelo lo
	 * redacte para un WhatsApp, y un texto ya legible se parafrasea mucho mejor
	 * que un objeto. Cuando algo no se encuentra se dice explícitamente, para
	 * que no lo interprete como "no hay datos" y se ponga a inventar.
	 */
	public static function run( $nombre, $args, $user_id ) {
		$nombre = (string) $nombre;
		$cat    = self::catalogo();
		if ( ! isset( $cat[ $nombre ] ) ) {
			return 'Esa consulta no existe.';
		}
		$cap = $cat[ $nombre ]['cap'];
		// Se vuelve a chequear el permiso acá, y no solo al armar la lista: entre
		// que se armó el prompt y que llegó la respuesta del modelo pudo haber
		// cambiado el rol de la persona.
		if ( $cap && ! user_can( (int) $user_id, $cap ) ) {
			return 'Sin permiso para esa consulta.';
		}
		$args = is_array( $args ) ? $args : [];

		switch ( $nombre ) {
			case 'consultar_metricas':   return self::metricas();
			case 'listar_cursos':        return self::cursos();
			case 'ver_curso':            return self::curso( (string) ( $args['curso'] ?? '' ) );
			case 'ver_horario_curso':    return self::horario( (string) ( $args['curso'] ?? '' ) );
			case 'consultar_horario':    return self::horario_buscar( $args, (int) $user_id );
			case 'buscar_persona':       return self::persona( (string) ( $args['texto'] ?? '' ) );
			case 'consultar_calendario': return self::calendario( (string) ( $args['texto'] ?? '' ), (int) ( $args['dias'] ?? 60 ), (int) $user_id );
		}
		return 'Esa consulta no existe.';
	}

	/* ------------------------------------------------------- consultas --- */

	protected static function metricas() {
		$roles = [
			'cead_acad_student'   => 'Alumnado',
			'cead_acad_teacher'   => 'Docentes',
			'cead_acad_delegate'  => 'Delegados',
			'cead_acad_guardian'  => 'Familias',
			'cead_acad_secretary' => 'Secretaría',
			'cead_acad_direction' => 'Dirección',
		];
		/*
		 * `count_users()` devuelve la cuenta de TODOS los roles en una sola
		 * consulta agrupada (y cacheada). Antes esto eran seis `get_users()` sin
		 * límite, cada una recorriendo la tabla entera y trayéndose los IDs a PHP
		 * nada más que para contarlos.
		 */
		$conteo = count_users();
		$porrol = isset( $conteo['avail_roles'] ) && is_array( $conteo['avail_roles'] ) ? $conteo['avail_roles'] : [];

		$lineas = [];
		foreach ( $roles as $slug => $label ) {
			$n = (int) ( $porrol[ $slug ] ?? 0 );
			$lineas[] = "- {$label}: {$n}";
		}

		$cursos = count( get_posts( [
			'post_type'   => Cead_Acad_Courses_CPT::POST_TYPE,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		] ) );

		$ahora   = current_time( 'Y-m-d H:i:s' );
		$en30    = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days', current_time( 'timestamp' ) ) );
		$eventos = count( get_posts( [
			'post_type'   => Cead_Acad_Schedule_CPT::POST_TYPE,
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_query'  => [
				[ 'key' => '_cead_acad_event_start', 'value' => [ $ahora, $en30 ], 'compare' => 'BETWEEN', 'type' => 'DATETIME' ],
			],
		] ) );

		$hace30 = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days', current_time( 'timestamp' ) ) );
		$comuni = count( get_posts( [
			'post_type'     => Cead_Acad_Broadcasts_CPT::POST_TYPE,
			'post_status'   => 'publish',
			'numberposts'   => -1,
			'fields'        => 'ids',
			'date_query'    => [ [ 'after' => $hace30 ] ],
		] ) );

		return "Números del colegio (ahora):\n" . implode( "\n", $lineas )
			. "\n- Cursos: {$cursos}"
			. "\n- Eventos en los próximos 30 días: {$eventos}"
			. "\n- Comunicados publicados en los últimos 30 días: {$comuni}";
	}

	protected static function cursos() {
		$posts = get_posts( [
			'post_type'   => Cead_Acad_Courses_CPT::POST_TYPE,
			'post_status' => 'any',
			'numberposts' => self::TOPE_FILAS,
			'orderby'     => 'title',
			'order'       => 'ASC',
		] );
		if ( ! $posts ) { return 'No hay cursos cargados todavía.'; }

		$turnos = [ 'manana' => 'mañana', 'tarde' => 'tarde', 'noche' => 'noche' ];
		$out    = [];
		foreach ( $posts as $p ) {
			$turno = (string) get_post_meta( $p->ID, '_cead_acad_turno', true );
			$n     = class_exists( 'Cead_Acad_Courses_Roster' ) ? Cead_Acad_Courses_Roster::count_active_in_course( $p->ID ) : 0;
			$out[] = '- ' . $p->post_title
				. ( isset( $turnos[ $turno ] ) ? ' (turno ' . $turnos[ $turno ] . ')' : '' )
				. ' — ' . $n . ' inscriptos';
		}
		return "Cursos:\n" . implode( "\n", $out );
	}

	/** Busca un curso por título aproximado. Devuelve el post o null. */

	/** Los días de la semana, para leerlos y para escribirlos. */
	protected static function dias_semana() {
		return [ 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo' ];
	}

	/** «viernes», «Vie», «5» → 5. Devuelve 0 si no se entiende. */
	protected static function dia_numero( $texto ) {
		$t = self::sin_tildes( mb_strtolower( trim( (string) $texto ) ) );
		if ( '' === $t ) { return 0; }
		if ( ctype_digit( $t ) ) {
			$n = (int) $t;
			return ( $n >= 1 && $n <= 7 ) ? $n : 0;
		}
		foreach ( self::dias_semana() as $n => $nombre ) {
			// Con los tres primeros caracteres alcanza y tolera «mier», «miercoles».
			if ( str_starts_with( self::sin_tildes( mb_strtolower( $nombre ) ), mb_substr( $t, 0, 3 ) ) ) {
				return $n;
			}
		}
		return 0;
	}

	protected static function sin_tildes( $s ) {
		return strtr( (string) $s, [ 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' ] );
	}

	/**
	 * Deja un texto en su forma comparable: minúsculas, sin tildes, sin
	 * puntuación, y con los ordinales llevados a su número.
	 *
	 * Lo de los ordinales no es un adorno. El colegio escribe «2.º Servicios
	 * Turísticos», la gente escribe «2do», «2º» o «segundo», y sin normalizar
	 * ninguna de esas tres encuentra el curso.
	 */
	protected static function normalizar( $s ) {
		$s = self::sin_tildes( mb_strtolower( trim( (string) $s ) ) );

		// «segundo» → «2», antes de romper las palabras.
		$s = strtr( $s, [
			'primero' => '1', 'primer' => '1', 'segundo' => '2', 'tercero' => '3', 'tercer' => '3',
			'cuarto'  => '4', 'quinto' => '5', 'sexto'  => '6', 'septimo' => '7',
		] );

		// «2do», «1ro», «3er» → «2», «1», «3».
		$s = preg_replace( '/(\d+)(ro|do|er|to|mo|vo|no)\b/u', '$1', $s );

		// Todo lo que no sea letra o número separa palabras: así «2.º» queda «2».
		$s = preg_replace( '/[^a-z0-9]+/u', ' ', $s );

		return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
	}

	/**
	 * ¿El pajar contiene lo que se busca?
	 *
	 * Compara PALABRA POR PALABRA, no la frase entera seguida, y esa diferencia
	 * es la que hacía fallar la búsqueda de cursos. Con `strpos` sobre la frase
	 * completa, «2do servicios turisticos» no encontraba «2.º Servicios
	 * Turísticos» —los caracteres no están en ese orden— pero «servicios
	 * turisticos» sí. Según cómo el modelo redactara el filtro en cada turno,
	 * encontraba todo o nada, y contestaba cosas opuestas en la misma
	 * conversación.
	 */
	protected static function contiene( $pajar, $aguja ) {
		$aguja = self::normalizar( $aguja );
		if ( '' === $aguja ) { return true; }

		$pajar = self::normalizar( $pajar );
		foreach ( explode( ' ', $aguja ) as $palabra ) {
			if ( '' === $palabra ) { continue; }
			if ( false === strpos( $pajar, $palabra ) ) { return false; }
		}
		return true;
	}

	/**
	 * Busca franjas del horario por docente, curso, materia, aula, día y momento.
	 *
	 * El alcance depende del permiso y no el acceso: quien no puede ver horarios
	 * ajenos igual puede preguntar, pero solo se le mira su propio curso. Así un
	 * alumno pregunta por su clase sin que la herramienta se convierta en una
	 * puerta lateral para leer el horario de otro curso.
	 */
	protected static function horario_buscar( $args, $user_id ) {
		$acotado = ! user_can( (int) $user_id, 'cead_acad_view_other_schedules' );
		$cursos  = self::cursos_visibles( $user_id );
		if ( ! $cursos ) {
			return 'No tengo horarios que puedas consultar. Si sos alumno/a, puede que todavía no estés asignado/a a un curso.';
		}

		// Se juntan las franjas de todos los cursos visibles y recién después se
		// filtra: la parte de WordPress termina acá.
		$franjas = [];
		foreach ( $cursos as $curso ) {
			$raw   = get_post_meta( $curso->ID, '_cead_acad_horario', true );
			$slots = is_array( $raw ) ? $raw : ( is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : [] );
			if ( ! is_array( $slots ) ) { continue; }
			foreach ( $slots as $s ) {
				if ( ! is_array( $s ) ) { continue; }
				$s['curso'] = $curso->post_title;
				$franjas[]  = $s;
			}
		}

		/*
		 * El «ahora» lo calcula el sistema y no el modelo: es el dato que más se
		 * deduce mal y del que depende toda la respuesta.
		 */
		$ts = current_time( 'timestamp' );

		return self::horario_texto( $franjas, $args, (int) gmdate( 'N', $ts ), gmdate( 'H:i', $ts ), $acotado );
	}

	/**
	 * Filtra y redacta el resultado. Función pura: recibe las franjas y la hora,
	 * así que se puede testear con un «ahora» fijo sin depender del reloj.
	 *
	 * @param array  $franjas Cada una con dia, inicio, fin, materia, docente, aula, curso.
	 * @param array  $args    Filtros de la herramienta.
	 * @param int    $hoy     Día de la semana actual (1 = lunes).
	 * @param string $hora    Hora actual «HH:MM».
	 */
	public static function horario_texto( array $franjas, array $args, $hoy, $hora, $acotado = false ) {
		$docente = (string) ( $args['docente'] ?? '' );
		$curso_f = (string) ( $args['curso'] ?? '' );
		$materia = (string) ( $args['materia'] ?? '' );
		$aula    = (string) ( $args['aula'] ?? '' );
		$momento = self::sin_tildes( mb_strtolower( trim( (string) ( $args['momento'] ?? '' ) ) ) );
		$dia     = self::dia_numero( $args['dia'] ?? '' );
		$hoy     = (int) $hoy;

		$solo_ahora = ( 'ahora' === $momento );
		if ( $solo_ahora || 'hoy' === $momento ) {
			$dia = $hoy;
		} elseif ( 'manana' === $momento ) {
			$dia = ( 7 === $hoy ) ? 1 : $hoy + 1;
		}

		/*
		 * Cuando solo se miraron los cursos propios hay que DECIRLO, tanto si
		 * hubo resultados como si no.
		 *
		 * El caso que lo hace necesario: un alumno de 2.º pregunta «¿qué materias
		 * da la profe Sanny?». Si Sanny le da Historia a su curso y Matemática a
		 * otros dos, sin este aviso la respuesta sería «Historia» — completa,
		 * segura y equivocada. Una respuesta parcial presentada como total es
		 * peor que no contestar, porque nadie la sale a verificar.
		 */
		/*
		 * La nota es para el MODELO, no para copiarla y pegarla.
		 *
		 * Redactada como una frase lista para mostrar, terminaba pegada al final
		 * de cada respuesta: alguien pregunta a qué hora tiene Matemática, se lo
		 * decís bien, y le agregás una advertencia sobre otros cursos que no
		 * preguntó. Una aclaración que no cambia lo que la persona haría es
		 * ruido, y el ruido enseña a no leer.
		 */
		$nota = $acotado
			? "\n[Alcance: solo se miraron los cursos de esta persona. Aclaráselo SOLO si lo que preguntó abarcaba más que su propio curso —por ejemplo todo lo que da un docente—; si preguntó por lo suyo, no aclares nada.]"
			: '';

		/*
		 * Si se filtró por curso y NINGUNA franja es de un curso que coincida, el
		 * problema es el nombre, no el horario.
		 *
		 * Sin esta distinción, un curso mal escrito devolvía «no tienen clases
		 * cargadas»: una afirmación sobre el horario, dicha con total seguridad,
		 * cuando en realidad no se había encontrado el curso. Es peor que un
		 * error, porque el modelo la repite como si fuera un dato.
		 */
		if ( '' !== $curso_f ) {
			$hay_curso = false;
			foreach ( $franjas as $f ) {
				if ( self::contiene( $f['curso'] ?? '', $curso_f ) ) { $hay_curso = true; break; }
			}
			if ( ! $hay_curso ) {
				$nombres = array_values( array_unique( array_filter( array_map(
					static function ( $f ) { return (string) ( $f['curso'] ?? '' ); },
					$franjas
				) ) ) );
				return 'No encontré ningún curso que se llame así. NO es que no tengan clases: es que ese nombre no coincide con ninguno.'
					. ( $nombres ? "\nLos que hay son: " . implode( ', ', array_slice( $nombres, 0, 12 ) ) . '.' : '' )
					. $nota;
			}
		}

		$filas = [];
		foreach ( $franjas as $s ) {
			$d = (int) ( $s['dia'] ?? 0 );
			if ( $d < 1 || $d > 7 ) { continue; }
			if ( $dia && $d !== $dia ) { continue; }
			if ( ! self::contiene( $s['curso'] ?? '', $curso_f ) ) { continue; }
			if ( ! self::contiene( $s['docente'] ?? '', $docente ) ) { continue; }
			if ( ! self::contiene( $s['materia'] ?? '', $materia ) ) { continue; }
			if ( ! self::contiene( $s['aula'] ?? '', $aula ) ) { continue; }

			$inicio = (string) ( $s['inicio'] ?? '' );
			$fin    = (string) ( $s['fin'] ?? '' );
			if ( $solo_ahora ) {
				// Sin hora de fin no se puede AFIRMAR que esté ocurriendo, y una
				// afirmación de más manda a alguien a golpear una puerta vacía.
				if ( '' === $inicio || '' === $fin ) { continue; }
				if ( $hora < $inicio || $hora >= $fin ) { continue; }
			}

			$filas[] = [
				'dia'     => $d,
				'inicio'  => $inicio,
				'fin'     => $fin,
				'curso'   => (string) ( $s['curso'] ?? '' ),
				'materia' => (string) ( $s['materia'] ?? '' ),
				'docente' => (string) ( $s['docente'] ?? '' ),
				'aula'    => (string) ( $s['aula'] ?? '' ),
			];
		}

		if ( ! $filas ) {
			return self::horario_sin_resultados( $solo_ahora, $dia, $docente, $hora ) . $nota;
		}

		usort( $filas, static function ( $a, $b ) {
			return [ $a['dia'], $a['inicio'] ] <=> [ $b['dia'], $b['inicio'] ];
		} );

		$dias  = self::dias_semana();
		$total = count( $filas );
		$filas = array_slice( $filas, 0, self::TOPE_FILAS );

		$out = [];
		if ( $solo_ahora ) {
			$out[] = 'Está ocurriendo ahora (' . $dias[ $hoy ] . ' ' . $hora . '):';
		} elseif ( $dia ) {
			$out[] = 'Horario del ' . $dias[ $dia ] . ':';
		} else {
			$out[] = 'Franjas que coinciden:';
		}
		foreach ( $filas as $f ) {
			$linea  = '- ' . $dias[ $f['dia'] ] . ' ' . $f['inicio'];
			$linea .= '' !== $f['fin'] ? '-' . $f['fin'] : '';
			$linea .= ' · ' . $f['materia'];
			$linea .= ' · ' . $f['curso'];
			if ( '' !== $f['docente'] ) { $linea .= ' · ' . $f['docente']; }
			if ( '' !== $f['aula'] ) { $linea .= ' · ' . $f['aula']; }
			$out[] = $linea;
		}
		if ( $total > count( $filas ) ) {
			$out[] = '(' . ( $total - count( $filas ) ) . ' franjas más; afiná la búsqueda.)';
		}
		return implode( "\n", $out ) . $nota;
	}

	/**
	 * El «no hay nada» tiene que decir QUÉ no hay.
	 *
	 * Un «no encontré» pelado deja al modelo eligiendo entre «no da clase» y «no
	 * tengo el dato», que para quien pregunta son cosas muy distintas: una lo
	 * manda a buscar a la profesora y la otra no.
	 */
	protected static function horario_sin_resultados( $solo_ahora, $dia, $docente, $hora_hhmm ) {
		$dias = self::dias_semana();
		if ( $solo_ahora ) {
			$quien = '' !== trim( $docente ) ? trim( $docente ) . ' no tiene clase' : 'No hay ninguna clase';
			return $quien . ' en este momento (' . $hora_hhmm . ') según el horario cargado.';
		}
		if ( $dia ) {
			return 'No hay nada que coincida el ' . $dias[ $dia ] . ' en el horario cargado.';
		}
		return 'No encontré ninguna franja que coincida en el horario cargado.';
	}

	/**
	 * Los cursos cuyo horario puede mirar esta persona.
	 *
	 * Con el permiso, todos. Sin él, los suyos — que es lo que ya podía ver en
	 * su panel, así que la herramienta no agrega acceso nuevo.
	 */
	protected static function cursos_visibles( $user_id ) {
		$todos = user_can( (int) $user_id, 'cead_acad_view_other_schedules' );
		if ( $todos ) {
			return get_posts( [
				'post_type'   => Cead_Acad_Courses_CPT::POST_TYPE,
				'numberposts' => -1,
				'post_status' => 'any',
			] );
		}

		$ids = class_exists( 'Cead_Acad_Courses_Roster' )
			? (array) Cead_Acad_Courses_Roster::courses_for_user( (int) $user_id )
			: [];
		$propio = (int) get_user_meta( (int) $user_id, '_cead_acad_current_course_id', true );
		if ( $propio ) { $ids[] = $propio; }
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) { return []; }

		return get_posts( [
			'post_type'   => Cead_Acad_Courses_CPT::POST_TYPE,
			'post__in'    => $ids,
			'numberposts' => -1,
			'post_status' => 'any',
		] );
	}

	protected static function resolver_curso( $texto ) {
		$texto = trim( $texto );
		if ( '' === $texto ) { return null; }
		$exacto = get_posts( [
			'post_type'   => Cead_Acad_Courses_CPT::POST_TYPE,
			'title'       => $texto,
			'numberposts' => 1,
			'post_status' => 'any',
		] );
		if ( $exacto ) { return $exacto[0]; }
		$aprox = get_posts( [
			'post_type'   => Cead_Acad_Courses_CPT::POST_TYPE,
			's'           => $texto,
			'numberposts' => 1,
			'post_status' => 'any',
		] );
		return $aprox ? $aprox[0] : null;
	}

	protected static function curso( $texto ) {
		$curso = self::resolver_curso( $texto );
		if ( ! $curso ) { return 'No encontré ningún curso que se parezca a «' . $texto . '». Probá con listar_cursos para ver los títulos reales.'; }

		$delegado = (int) get_post_meta( $curso->ID, '_cead_acad_delegate', true );
		$tutor    = (int) get_post_meta( $curso->ID, '_cead_acad_tutor', true );
		$nombre   = static function ( $uid ) {
			$u = $uid ? get_userdata( $uid ) : null;
			return $u ? $u->display_name : 'sin asignar';
		};

		$out = [ 'Curso: ' . $curso->post_title ];
		$out[] = 'Delegado/a: ' . $nombre( $delegado );
		$out[] = 'Tutor/a: ' . $nombre( $tutor );

		if ( class_exists( 'Cead_Acad_Courses_Roster' ) ) {
			$ids = Cead_Acad_Courses_Roster::users_in_course( $curso->ID );
			$out[] = 'Inscriptos: ' . count( $ids );
			$muestra = array_slice( $ids, 0, self::TOPE_FILAS );
			foreach ( $muestra as $uid ) {
				$u = get_userdata( $uid );
				if ( $u ) { $out[] = '- ' . $u->display_name; }
			}
			if ( count( $ids ) > count( $muestra ) ) {
				$out[] = '(y ' . ( count( $ids ) - count( $muestra ) ) . ' más)';
			}
		}
		return implode( "\n", $out );
	}

	protected static function horario( $texto ) {
		$curso = self::resolver_curso( $texto );
		if ( ! $curso ) { return 'No encontré ese curso. Probá con listar_cursos.'; }

		$raw   = get_post_meta( $curso->ID, '_cead_acad_horario', true );
		$slots = is_array( $raw ) ? $raw : ( is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : [] );
		if ( ! is_array( $slots ) || ! $slots ) {
			return 'El curso ' . $curso->post_title . ' no tiene horario cargado.';
		}

		$dias = [ 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo' ];
		$por  = [];
		foreach ( $slots as $s ) {
			$d = (int) ( $s['dia'] ?? 0 );
			if ( $d < 1 || $d > 7 ) { continue; }
			$por[ $d ][] = $s;
		}
		ksort( $por );

		$out = [ 'Horario de ' . $curso->post_title . ':' ];
		foreach ( $por as $d => $items ) {
			usort( $items, static function ( $a, $b ) {
				return strcmp( (string) ( $a['inicio'] ?? '' ), (string) ( $b['inicio'] ?? '' ) );
			} );
			$out[] = $dias[ $d ] . ':';
			foreach ( $items as $it ) {
				$linea = '  ' . (string) ( $it['inicio'] ?? '' );
				if ( ! empty( $it['fin'] ) ) { $linea .= '-' . $it['fin']; }
				$linea .= ' ' . (string) ( $it['materia'] ?? '' );
				if ( ! empty( $it['docente'] ) ) { $linea .= ' · ' . $it['docente']; }
				if ( ! empty( $it['aula'] ) ) { $linea .= ' · ' . $it['aula']; }
				$out[] = $linea;
			}
		}
		return implode( "\n", $out );
	}

	protected static function persona( $texto ) {
		$texto = trim( $texto );
		if ( '' === $texto ) { return 'Decime a quién buscar.'; }

		$vistos = [];
		$users  = get_users( [ 'search' => '*' . $texto . '*', 'number' => self::TOPE_FILAS ] );
		foreach ( $users as $u ) { $vistos[ $u->ID ] = $u; }

		// Por documento, que no entra en la búsqueda de usuarios de WordPress.
		foreach ( get_users( [
			'meta_key'     => '_cead_acad_document_id',
			'meta_value'   => $texto,
			'meta_compare' => 'LIKE',
			'number'       => self::TOPE_FILAS,
		] ) as $u ) {
			$vistos[ $u->ID ] = $u;
		}

		if ( ! $vistos ) { return 'No encontré a nadie que coincida con «' . $texto . '».'; }

		$roles = class_exists( 'Cead_Acad_Capabilities' ) ? Cead_Acad_Capabilities::roles() : [];
		$out   = [];
		foreach ( array_slice( $vistos, 0, self::TOPE_FILAS, true ) as $u ) {
			$rol = '';
			foreach ( (array) $u->roles as $r ) {
				if ( isset( $roles[ $r ] ) ) { $rol = $roles[ $r ]['display']; break; }
			}
			if ( '' === $rol && in_array( 'administrator', (array) $u->roles, true ) ) { $rol = 'Admin WP'; }

			$curso_id = (int) get_user_meta( $u->ID, '_cead_acad_current_course_id', true );
			$curso    = $curso_id ? get_the_title( $curso_id ) : '';
			$tel      = (string) get_user_meta( $u->ID, '_cead_acad_phone', true );
			$susp     = class_exists( 'Cead_Acad_User_Suspension' ) && Cead_Acad_User_Suspension::is_suspended( $u->ID );

			$out[] = '- ' . $u->display_name
				. ( $rol ? ' · ' . $rol : '' )
				. ( $curso ? ' · ' . $curso : '' )
				. ( $tel ? ' · tel ' . $tel : '' )
				. ( $susp ? ' · SUSPENDIDO' : '' );
		}
		return "Coincidencias:\n" . implode( "\n", $out );
	}

	/**
	 * El calendario, contestado en texto.
	 *
	 * Dos modos en una sola herramienta, porque para el modelo son la misma
	 * pregunta con distinto grado de precisión: con `texto` busca un evento por
	 * nombre en TODO el calendario (incluido lo que ya pasó, que también es una
	 * respuesta válida: «la feria fue el 12 de agosto»), y sin `texto` lista lo
	 * que viene.
	 */
	protected static function calendario( $texto, $dias, $user_id ) {
		$texto = trim( (string) $texto );
		$dias  = max( 1, min( 365, (int) $dias ?: 60 ) );
		$hoy   = substr( (string) current_time( 'Y-m-d H:i:s' ), 0, 10 );

		/*
		 * Acceso para todos, alcance según el permiso.
		 *
		 * Quien administra el calendario ve TODO lo cargado, incluido lo dirigido
		 * a un curso al que no pertenece: lo necesita para no duplicar un evento
		 * antes de crear otro. El resto ve lo que le fue dirigido —lo mismo que
		 * ya ve en su calendario del panel—, ni un evento más.
		 */
		$manda = user_can( (int) $user_id, 'cead_acad_manage_schedule' );
		$ids   = $manda ? null : Cead_Acad_Audiences::subjects_for_user( 'event', (int) $user_id );

		if ( '' !== $texto ) {
			$found = self::eventos( $ids, null, null, self::TOPE_FILAS, 'ASC', $texto );
			if ( ! $found ) {
				return 'No hay ningún evento en el calendario que se llame «' . $texto . '» ni se le parezca. '
					. 'Puede que todavía no esté cargado: decilo así, no deduzcas una fecha.';
			}
			return "Calendario — lo que coincide con «{$texto}»:\n"
				. implode( "\n", array_map( static function ( $p ) use ( $hoy, $manda ) {
					return self::linea_evento( $p, $hoy, $manda );
				}, $found ) );
		}

		/*
		 * Un período largo que ya arrancó pero no terminó —las vacaciones de
		 * julio, mientras transcurren— empieza ANTES de hoy, así que un filtro
		 * por fecha de inicio lo dejaba afuera justo los días en que preguntar
		 * por él tiene más sentido. Por eso se busca hacia atrás también y se
		 * filtra por fecha de FIN.
		 */
		$ahora    = (string) current_time( 'Y-m-d H:i:s' );
		$hasta    = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $dias . ' days', current_time( 'timestamp' ) ) );
		$desde    = gmdate( 'Y-m-d H:i:s', strtotime( '-1 year', current_time( 'timestamp' ) ) );
		$en_curso = [];
		foreach ( self::eventos( $ids, $desde, $ahora, self::TOPE_FILAS, 'DESC' ) as $p ) {
			$fin = (string) get_post_meta( $p->ID, '_cead_acad_event_end', true );
			if ( substr( $fin, 0, 10 ) >= $hoy ) { $en_curso[] = $p; }
		}
		$en_curso  = array_reverse( $en_curso );
		$proximos  = self::eventos( $ids, $ahora, $hasta, self::TOPE_FILAS, 'ASC' );

		if ( ! $en_curso && ! $proximos ) {
			return 'No hay nada cargado en el calendario para los próximos ' . $dias . ' días.';
		}

		$out = [];
		foreach ( array_merge( $en_curso, $proximos ) as $p ) {
			$out[] = self::linea_evento( $p, $hoy, $manda );
		}
		return 'Calendario, próximos ' . $dias . " días:\n" . implode( "\n", array_slice( $out, 0, self::TOPE_FILAS ) );
	}

	/**
	 * Consulta cruda de eventos del calendario, sin las clases.
	 *
	 * El horario semanal vive en el mismo CPT que los eventos. Sin este filtro
	 * las filas se llenan con «07:30 · Matemática (Clase)» y lo que de verdad se
	 * preguntó —el acto, el feriado, el examen— no entra nunca. Es el mismo
	 * criterio que aplica el calendario del panel en `Schedule_Feed::query()`.
	 *
	 * `$ids === null` significa sin restricción de audiencia. Un array VACÍO es
	 * lo contrario —esta persona no tiene ningún evento dirigido— y se corta
	 * acá: `post__in` vacío en WordPress no filtra nada y devolvería el
	 * calendario entero, que es exactamente el bug que no queremos.
	 *
	 * @return WP_Post[]
	 */
	protected static function eventos( $ids, $from, $to, $limit, $order = 'ASC', $texto = '' ) {
		if ( is_array( $ids ) && ! $ids ) { return []; }

		$meta = [
			[
				'relation' => 'OR',
				[ 'key' => '_cead_acad_event_type', 'value' => 'clase', 'compare' => '!=' ],
				[ 'key' => '_cead_acad_event_type', 'compare' => 'NOT EXISTS' ],
			],
		];
		if ( $from ) { $meta[] = [ 'key' => '_cead_acad_event_start', 'value' => $from, 'compare' => '>=', 'type' => 'DATETIME' ]; }
		if ( $to )   { $meta[] = [ 'key' => '_cead_acad_event_start', 'value' => $to,   'compare' => '<=', 'type' => 'DATETIME' ]; }

		$args = [
			'post_type'      => Cead_Acad_Schedule_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'orderby'        => 'meta_value',
			'meta_key'       => '_cead_acad_event_start',
			'order'          => $order,
			'no_found_rows'  => true,
			'meta_query'     => $meta,
		];
		if ( is_array( $ids ) ) { $args['post__in'] = $ids; }
		if ( '' !== $texto )    { $args['s'] = $texto; }

		return get_posts( $args );
	}

	/** Una línea de calendario, tal como la va a leer el modelo. */
	protected static function linea_evento( $p, $hoy, $con_audiencia ) {
		$ini   = (string) get_post_meta( $p->ID, '_cead_acad_event_start', true );
		$fin   = (string) get_post_meta( $p->ID, '_cead_acad_event_end', true );
		$tipo  = (string) get_post_meta( $p->ID, '_cead_acad_event_type', true ) ?: 'evento';
		$lugar = (string) get_post_meta( $p->ID, '_cead_acad_event_location', true );

		$fecha = self::fecha_legible( $ini );
		// Un período de varios días se dice como período; repetir el día de fin
		// cuando empieza y termina el mismo día solo agrega ruido.
		if ( $fin && substr( $fin, 0, 10 ) !== substr( $ini, 0, 10 ) ) {
			$fecha = 'del ' . self::fecha_legible( $ini, false ) . ' al ' . self::fecha_legible( $fin, false );
		}

		$cuando = self::cuando( $ini, $fin, $hoy );
		$linea  = '- ' . ( $fecha ?: 'sin fecha' )
			. ( $cuando ? ' (' . $cuando . ')' : '' )
			. ' · ' . $p->post_title
			. ' [' . Cead_Acad_Schedule_CPT::type_label( $tipo ) . ']';
		if ( $lugar ) { $linea .= ' · ' . $lugar; }

		if ( $con_audiencia && class_exists( 'Cead_Acad_Audiences' ) ) {
			$aud = Cead_Acad_Audiences::describe( Cead_Acad_Audiences::get( 'event', $p->ID ) );
			if ( $aud ) { $linea .= ' → ' . $aud; }
		}
		return $linea;
	}

	/**
	 * «2026-08-12 08:30:00» → «12/08/2026 08:30».
	 *
	 * Se corta la cadena en vez de pasar por strtotime()/date(): el dato ya está
	 * guardado en hora local del colegio, así que convertirlo lo único que puede
	 * hacer es correrlo unas horas. La medianoche se toma como «sin hora», que
	 * es lo que significa en la práctica para un feriado o un período.
	 */
	protected static function fecha_legible( $sql, $con_hora = true ) {
		$sql = (string) $sql;
		if ( strlen( $sql ) < 10 ) { return ''; }
		$fecha = substr( $sql, 8, 2 ) . '/' . substr( $sql, 5, 2 ) . '/' . substr( $sql, 0, 4 );
		$hora  = substr( $sql, 11, 5 );
		if ( $con_hora && $hora && '00:00' !== $hora ) { $fecha .= ' ' . $hora; }
		return $fecha;
	}

	/**
	 * A cuánto está: «hoy», «mañana», «faltan 9 días», «EN CURSO ahora», «ya pasó».
	 *
	 * Sin esto el modelo tiene que hacer la aritmética de fechas él, y ahí es
	 * donde se equivoca: dice «el viernes» mirando un 2026 con la cabeza puesta
	 * en otro año. Que el dato venga masticado es la diferencia entre contestar
	 * bien y contestar convencido.
	 */
	protected static function cuando( $ini_sql, $fin_sql, $hoy ) {
		$ini = substr( (string) $ini_sql, 0, 10 );
		if ( '' === $ini ) { return ''; }
		$fin = substr( (string) $fin_sql, 0, 10 );
		if ( '' === $fin || $fin < $ini ) { $fin = $ini; }

		if ( $fin < $hoy )                  { return 'ya pasó'; }
		if ( $ini <= $hoy && $fin >= $hoy ) { return $ini === $fin ? 'HOY' : 'EN CURSO ahora'; }

		$dias = (int) round( ( strtotime( $ini ) - strtotime( $hoy ) ) / DAY_IN_SECONDS );
		if ( $dias <= 1 ) { return 'mañana'; }
		return 'faltan ' . $dias . ' días';
	}
}
