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
		'buscar_persona',
		'agenda_institucional',
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
			'agenda_institucional' => [
				'cap'  => 'cead_acad_manage_schedule',
				'spec' => [
					'name'        => 'agenda_institucional',
					'description' => 'Los eventos del calendario que vienen, con su tipo y a quién están dirigidos. '
						. 'Sirve para revisar qué hay cargado antes de agregar algo y no duplicar.',
					'parameters'  => [
						'type'       => 'object',
						'properties' => [
							'dias' => [ 'type' => 'integer', 'description' => 'Cuántos días hacia adelante mirar (por defecto 30).' ],
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
			case 'buscar_persona':       return self::persona( (string) ( $args['texto'] ?? '' ) );
			case 'agenda_institucional': return self::agenda( (int) ( $args['dias'] ?? 30 ) );
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

	protected static function agenda( $dias ) {
		$dias  = max( 1, min( 365, (int) $dias ?: 30 ) );
		$ahora = current_time( 'Y-m-d H:i:s' );
		$hasta = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $dias . ' days', current_time( 'timestamp' ) ) );

		$posts = get_posts( [
			'post_type'   => Cead_Acad_Schedule_CPT::POST_TYPE,
			'post_status' => 'publish',
			'numberposts' => self::TOPE_FILAS,
			'orderby'     => 'meta_value',
			'meta_key'    => '_cead_acad_event_start',
			'order'       => 'ASC',
			'meta_query'  => [
				[ 'key' => '_cead_acad_event_start', 'value' => [ $ahora, $hasta ], 'compare' => 'BETWEEN', 'type' => 'DATETIME' ],
				/*
				 * Fuera las clases, igual que en el calendario del panel y en el
				 * público (`Cead_Acad_Schedule_Feed::query()`). El horario semanal
				 * también vive en este CPT: sin este filtro, las 25 filas del tope
				 * se llenaban con «07:30 · Matemática (Clase)» y los eventos de
				 * verdad —reuniones, actos, exámenes— no entraban nunca. Justo lo
				 * contrario de para lo que existe la herramienta, que es ver qué
				 * hay cargado antes de agregar algo.
				 */
				[
					'relation' => 'OR',
					[ 'key' => '_cead_acad_event_type', 'value' => 'clase', 'compare' => '!=' ],
					[ 'key' => '_cead_acad_event_type', 'compare' => 'NOT EXISTS' ],
				],
			],
		] );
		if ( ! $posts ) { return 'No hay eventos cargados en los próximos ' . $dias . ' días.'; }

		$out = [ 'Eventos en los próximos ' . $dias . ' días:' ];
		foreach ( $posts as $p ) {
			$ini  = (string) get_post_meta( $p->ID, '_cead_acad_event_start', true );
			$tipo = (string) get_post_meta( $p->ID, '_cead_acad_event_type', true ) ?: 'evento';
			$aud  = class_exists( 'Cead_Acad_Audiences' )
				? Cead_Acad_Audiences::describe( Cead_Acad_Audiences::get( 'event', $p->ID ) )
				: '';
			$out[] = '- ' . ( $ini ? gmdate( 'd/m H:i', strtotime( $ini ) ) : 's/f' )
				. ' · ' . $p->post_title
				. ' (' . Cead_Acad_Schedule_CPT::type_label( $tipo ) . ')'
				. ( $aud ? ' → ' . $aud : '' );
		}
		return implode( "\n", $out );
	}
}
