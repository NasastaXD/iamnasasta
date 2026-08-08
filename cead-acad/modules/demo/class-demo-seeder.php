<?php
/**
 * Datos de demostración para grabar o presentar el sistema.
 *
 * El panel está completo, pero con la base vacía todas sus tarjetas dicen lo
 * mismo: «Nada por ahora», «Sin clases cargadas», «No hay comunicados». Un
 * sistema terminado puede verse muerto por falta de contenido, y eso arruina
 * una demo. Esto lo llena con un curso realista y su gente.
 *
 * Todo lo que crea queda marcado con `_cead_acad_demo`, y `purge()` borra
 * exactamente eso y nada más. Es la única forma segura de ofrecer un botón que
 * borra: sin la marca habría que adivinar qué era de demo y qué era real.
 *
 * No se activa solo ni corre en ningún hook: se dispara a mano desde la
 * pantalla de admin, por alguien con manage_options.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Demo_Seeder {

	/** Marca que llevan todos los objetos creados acá. */
	const FLAG = '_cead_acad_demo';

	/** Contraseña de las cuentas de demo. Se muestra en pantalla para poder entrar. */
	const PASS = 'DemoCEAD2026';

	/**
	 * Crea el juego de datos. Devuelve un resumen de qué se creó.
	 * Es idempotente por la vía fácil: si ya hay datos de demo, purga primero.
	 */
	public static function seed() {
		if ( self::has_data() ) { self::purge(); }

		$out = [ 'curso' => 0, 'usuarios' => 0, 'comunicados' => 0, 'eventos' => 0, 'tareas' => 0, 'recursos' => 0, 'encuestas' => 0, 'notas' => 0 ];

		$course_id = self::seed_course();
		$out['curso'] = $course_id;

		$people = self::seed_people( $course_id );
		$out['usuarios'] = count( $people['todos'] );

		$out['comunicados'] = self::seed_broadcasts( $people['alumnos'] );
		$out['eventos']     = self::seed_events();
		$out['tareas']      = self::seed_tasks( $course_id );
		$out['recursos']    = self::seed_resources();
		$out['encuestas']   = self::seed_survey( $people['alumnos'] );
		$out['notas']       = self::seed_grades( $course_id, $people['alumnos'] );

		return $out;
	}

	/** ¿Hay datos de demo cargados? */
	public static function has_data() {
		$posts = get_posts( [
			'post_type'   => 'any',
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_key'    => self::FLAG,
		] );
		if ( $posts ) { return true; }
		$users = get_users( [ 'meta_key' => self::FLAG, 'number' => 1, 'fields' => 'ID' ] );
		return ! empty( $users );
	}

	/**
	 * Borra todo lo marcado como demo: posts, usuarios y las filas de las tablas
	 * propias que cuelgan de ellos.
	 */
	public static function purge() {
		global $wpdb;

		$user_ids = get_users( [ 'meta_key' => self::FLAG, 'fields' => 'ID' ] );
		$post_ids = get_posts( [
			'post_type'   => 'any',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_key'    => self::FLAG,
		] );

		// Filas de las tablas propias. Se borran antes que los posts/usuarios
		// para no dejar huérfanos si algo falla en el medio.
		if ( $post_ids ) {
			$ph = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			foreach ( [ 'audiences' => 'subject_id', 'broadcast_reads' => 'broadcast_id' ] as $t => $col ) {
				$table = cead_acad_table( $t );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$col} IN ($ph)", $post_ids ) ); // phpcs:ignore
			}
			// Encuestas: respuestas y answers cuelgan de la encuesta.
			$sr = cead_acad_table( 'survey_responses' );
			$sa = cead_acad_table( 'survey_answers' );
			$sq = cead_acad_table( 'survey_questions' );
			$rids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$sr} WHERE survey_id IN ($ph)", $post_ids ) ); // phpcs:ignore
			if ( $rids ) {
				$rph = implode( ',', array_fill( 0, count( $rids ), '%d' ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$sa} WHERE response_id IN ($rph)", $rids ) ); // phpcs:ignore
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$sr} WHERE survey_id IN ($ph)", $post_ids ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$sq} WHERE survey_id IN ($ph)", $post_ids ) ); // phpcs:ignore
		}

		if ( $user_ids ) {
			$ph = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
			foreach ( [ 'roster' => 'user_id', 'grades' => 'student_user_id', 'broadcast_reads' => 'user_id' ] as $t => $col ) {
				$table = cead_acad_table( $t );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$col} IN ($ph)", $user_ids ) ); // phpcs:ignore
			}
		}

		foreach ( $post_ids as $pid ) { wp_delete_post( $pid, true ); }

		if ( $user_ids ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( $user_ids as $uid ) { wp_delete_user( $uid ); }
		}

		if ( class_exists( 'Cead_Acad_WA_News' ) ) { Cead_Acad_WA_News::flush(); }

		return [ 'posts' => count( $post_ids ), 'usuarios' => count( $user_ids ) ];
	}

	/* ---------------- Piezas ---------------- */

	/** Curso con horario semanal real, que es lo que llena «Clases de hoy». */
	protected static function seed_course() {
		$id = self::insert( [
			'post_type'    => 'cead_acad_course',
			'post_title'   => '3.º Curso — Bachillerato Técnico en Informática',
			'post_content' => 'Turno mañana. Sala 12, pabellón nuevo.',
			'post_status'  => 'publish',
		] );

		$materias = [
			'Programación', 'Base de Datos', 'Matemática', 'Lengua Castellana',
			'Historia y Geografía', 'Redes', 'Física', 'Guaraní',
		];
		$docentes = [
			'Prof. Rodríguez', 'Prof. Benítez', 'Prof. Cáceres', 'Prof. Villalba',
			'Prof. Duarte', 'Prof. Ojeda', 'Prof. Martínez', 'Prof. Giménez',
		];

		// Lunes a viernes, cuatro horas cátedra por día desde las 7:10.
		$horas = [ [ '07:10', '08:00' ], [ '08:05', '08:55' ], [ '09:15', '10:05' ], [ '10:10', '11:00' ] ];
		$slots = [];
		$i = 0;
		for ( $dia = 1; $dia <= 5; $dia++ ) {
			foreach ( $horas as $h ) {
				$slots[] = [
					'dia'     => $dia,
					'inicio'  => $h[0],
					'fin'     => $h[1],
					'materia' => $materias[ $i % count( $materias ) ],
					'docente' => $docentes[ $i % count( $docentes ) ],
				];
				$i++;
			}
		}
		update_post_meta( $id, '_cead_acad_horario', $slots );
		return $id;
	}

	/** Alumnado, delegada y docente. La contraseña es la misma para todos. */
	protected static function seed_people( $course_id ) {
		$alumnos = [
			[ 'Sofía Ramírez', 'sofia.ramirez' ],
			[ 'Mateo Ayala', 'mateo.ayala' ],
			[ 'Camila Fernández', 'camila.fernandez' ],
			[ 'Joaquín Benítez', 'joaquin.benitez' ],
			[ 'Valentina Ortiz', 'valentina.ortiz' ],
			[ 'Thiago Insfrán', 'thiago.insfran' ],
			[ 'Lucía Vera', 'lucia.vera' ],
			[ 'Diego Aquino', 'diego.aquino' ],
		];

		$ids = [];
		foreach ( $alumnos as $n => $a ) {
			$uid = self::make_user( $a[1], $a[0], 'cead_acad_student' );
			if ( ! $uid ) { continue; }
			$ids[] = $uid;
			update_user_meta( $uid, '_cead_acad_current_course_id', $course_id );
			if ( class_exists( 'Cead_Acad_Courses_Roster' ) ) {
				// Todas van como `student` en el roster, incluida la delegada: ser
				// delegada es un rol extra encima de ser alumna, no en lugar de.
				// Si se la cargara como `delegate`, quedaría fuera de
				// users_in_course(..., 'student') y no contaría como alumnado ni
				// recibiría los comunicados dirigidos al curso.
				Cead_Acad_Courses_Roster::add( $uid, $course_id, 'student' );
			}
		}

		$todos = $ids;

		$deleg = $ids ? $ids[0] : 0;
		if ( $deleg ) {
			$u = new WP_User( $deleg );
			$u->add_role( 'cead_acad_delegate' );
		}

		$doc = self::make_user( 'prof.rodriguez', 'Prof. Rodríguez', 'cead_acad_teacher' );
		if ( $doc ) {
			$todos[] = $doc;
			if ( class_exists( 'Cead_Acad_Courses_Roster' ) ) {
				Cead_Acad_Courses_Roster::add( $doc, $course_id, 'teacher' );
			}
		}

		return [ 'alumnos' => $ids, 'todos' => $todos ];
	}

	/** Comunicados: algunos leídos y otros no, para que se vea el badge. */
	protected static function seed_broadcasts( $alumnos ) {
		$items = [
			[ 'Inicio del segundo periodo', 'El segundo periodo arranca el lunes. Las clases mantienen el horario habitual de 7:10 a 11:00. Recuerden traer el material de Programación.', 'academico', 2 ],
			[ 'Reunión de padres — 3.º BTI', 'Convocamos a madres, padres y encargados a la reunión del jueves a las 18:00 en el salón de actos. Se entregarán los informes del primer periodo.', 'administrativo', 5 ],
			[ 'Inter CEAD 2026 — inscripciones abiertas', 'Ya se pueden anotar los equipos para el torneo interno. Fútbol, vóley y ajedrez. Hablen con su delegado/a de curso antes del viernes.', 'eventos', 9 ],
			[ 'Cambio de aula: Redes pasa al laboratorio', 'A partir de esta semana, las clases de Redes se dictan en el laboratorio de informática y no en el aula 12.', 'academico', 14 ],
			[ 'Suspensión de clases por asamblea docente', 'El viernes no habrá actividad académica por asamblea. Las clases se retoman el lunes con normalidad.', 'urgente', 20 ],
		];

		$n = 0;
		foreach ( $items as $i => $it ) {
			$id = self::insert( [
				'post_type'     => 'cead_acad_broadcast',
				'post_title'    => $it[0],
				'post_content'  => $it[1],
				'post_status'   => 'publish',
				'post_date'     => gmdate( 'Y-m-d H:i:s', strtotime( "-{$it[3]} days" ) ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( "-{$it[3]} days" ) ),
			] );
			wp_set_object_terms( $id, $it[2], 'cead_acad_broadcast_category' );

			if ( class_exists( 'Cead_Acad_Broadcasts_Audiences' ) ) {
				Cead_Acad_Broadcasts_Audiences::set( 'broadcast', $id, [ [ 'type' => 'all', 'value' => '' ] ] );
			}

			// Los dos más nuevos quedan sin leer para todos: así el panel muestra
			// «2 sin leer» y las tarjetas con el pill de «Nuevo».
			if ( $i >= 2 ) {
				self::mark_read( $id, $alumnos );
			}
			$n++;
		}
		return $n;
	}

	/** Eventos próximos, que es lo que llena el calendario y la home. */
	protected static function seed_events() {
		$items = [
			[ 'Entrega de informes del primer periodo', 2, '18:00', 'Salón de actos' ],
			[ 'Examen de Programación — unidad 3', 4, '07:10', 'Aula 12' ],
			[ 'Inter CEAD 2026 — fecha 1', 7, '08:00', 'Cancha del colegio' ],
			[ 'Charla: universidades y becas', 12, '10:00', 'Salón de actos' ],
			[ 'Feria de ciencias — montaje', 18, '14:00', 'Patio central' ],
			[ 'Feria de ciencias — apertura', 19, '09:00', 'Patio central' ],
		];
		$n = 0;
		foreach ( $items as $it ) {
			$start = gmdate( 'Y-m-d', strtotime( "+{$it[1]} days" ) ) . ' ' . $it[2] . ':00';
			$id    = self::insert( [
				'post_type'   => 'cead_acad_event',
				'post_title'  => $it[0],
				'post_status' => 'publish',
			] );
			update_post_meta( $id, '_cead_acad_event_start', $start );
			update_post_meta( $id, '_cead_acad_event_end', gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +2 hours' ) ) );
			update_post_meta( $id, '_cead_acad_event_location', $it[3] );
			$n++;
		}
		return $n;
	}

	/** Tareas del curso en distintos estados, para el panel del delegado. */
	protected static function seed_tasks( $course_id ) {
		$items = [
			[ 'Reunir las autorizaciones para la salida', 'pending', 3, 'high' ],
			[ 'Pasar la lista de equipos del Inter CEAD', 'in_progress', 5, 'high' ],
			[ 'Actualizar la cartelera del curso', 'in_progress', 8, 'normal' ],
			[ 'Entregar el acta de la reunión de delegados', 'done', -2, 'normal' ],
			[ 'Confirmar asistencia a la charla de becas', 'pending', 11, 'low' ],
			[ 'Recolectar los trabajos de Base de Datos', 'done', -5, 'normal' ],
		];
		$n = 0;
		foreach ( $items as $it ) {
			$id = self::insert( [
				'post_type'   => 'cead_acad_task',
				'post_title'  => $it[0],
				'post_status' => 'publish',
			] );
			update_post_meta( $id, '_cead_acad_task_course', $course_id );
			update_post_meta( $id, '_cead_acad_task_status', $it[1] );
			update_post_meta( $id, '_cead_acad_task_done', 'done' === $it[1] ? 1 : 0 );
			update_post_meta( $id, '_cead_acad_task_due_date', gmdate( 'Y-m-d', strtotime( "{$it[2]} days" ) ) );
			update_post_meta( $id, '_cead_acad_task_priority', $it[3] );
			$n++;
		}
		return $n;
	}

	protected static function seed_resources() {
		$items = [
			[ 'Guía de ejercicios — Programación unidad 3', 'Programación', 'Ejercicios resueltos de estructuras de control y funciones.' ],
			[ 'Modelo de examen — Base de Datos', 'Base de Datos', 'Examen del periodo anterior, para practicar.' ],
			[ 'Reglamento interno del CEAD', 'Institucional', 'Normas de convivencia, asistencia y evaluación.' ],
			[ 'Calendario académico 2026', 'Institucional', 'Fechas de periodos, exámenes y recesos.' ],
			[ 'Apunte de Redes — direccionamiento IP', 'Redes', 'Teoría y ejercicios de subneteo.' ],
		];
		$n = 0;
		foreach ( $items as $it ) {
			$id = self::insert( [
				'post_type'    => 'cead_acad_resource',
				'post_title'   => $it[0],
				'post_content' => $it[2],
				'post_status'  => 'publish',
			] );
			wp_set_object_terms( $id, $it[1], 'cead_acad_subject' );
			$n++;
		}
		return $n;
	}

	/**
	 * Encuesta con respuestas cargadas. Sin esto el tablero de dirección muestra
	 * 0% de participación, que es justo lo que no se quiere mostrar en una demo.
	 */
	protected static function seed_survey( $alumnos ) {
		global $wpdb;

		$id = self::insert( [
			'post_type'    => 'cead_acad_survey',
			'post_title'   => '¿Cómo venimos con el segundo periodo?',
			'post_content' => 'Queremos saber cómo están llevando las materias para ajustar el ritmo.',
			'post_status'  => 'publish',
		] );
		update_post_meta( $id, '_cead_acad_survey_opens_at', gmdate( 'Y-m-d H:i:s', strtotime( '-6 days' ) ) );
		update_post_meta( $id, '_cead_acad_survey_closes_at', gmdate( 'Y-m-d H:i:s', strtotime( '+8 days' ) ) );
		update_post_meta( $id, '_cead_acad_survey_anonymous', 0 );

		$tq = cead_acad_table( 'survey_questions' );
		$preguntas = [
			[ 'single', '¿Cómo sentís el ritmo de las clases?', '{"options":["Muy lento","Bien","Muy rápido"]}' ],
			[ 'single', '¿Qué materia te está costando más?', '{"options":["Programación","Base de Datos","Matemática","Física"]}' ],
			[ 'text', '¿Algo que quieras contarle a dirección?', null ],
		];
		$qids = [];
		foreach ( $preguntas as $pos => $q ) {
			$wpdb->insert( $tq, [
				'survey_id'  => $id,
				'position'   => $pos,
				'type'       => $q[0],
				'text'       => $q[1],
				'required'   => 'text' === $q[0] ? 0 : 1,
				'config'     => $q[2],
				'created_at' => current_time( 'mysql' ),
			] );
			$qids[] = (int) $wpdb->insert_id;
		}

		// Responden 6 de 8: da 75%, un número creíble y que se ve bien.
		$tr  = cead_acad_table( 'survey_responses' );
		$ta  = cead_acad_table( 'survey_answers' );
		$r1  = [ 'Bien', 'Bien', 'Muy rápido', 'Bien', 'Muy rápido', 'Muy lento' ];
		$r2  = [ 'Programación', 'Matemática', 'Programación', 'Física', 'Base de Datos', 'Matemática' ];
		$r3  = [
			'Estaría bueno tener más ejercicios resueltos.',
			'Todo bien por ahora.',
			'Las clases de la última hora se hacen largas.',
			'', '', 'Me gustaría que suban los apuntes antes de la clase.',
		];
		foreach ( array_slice( $alumnos, 0, 6 ) as $i => $uid ) {
			$wpdb->insert( $tr, [
				'survey_id'    => $id,
				'user_id'      => $uid,
				'submitted_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( 5 - $i ) . ' days' ) ),
			] );
			$rid = (int) $wpdb->insert_id;
			foreach ( [ 0 => $r1[ $i ], 1 => $r2[ $i ], 2 => $r3[ $i ] ] as $qi => $val ) {
				if ( '' === $val ) { continue; }
				$wpdb->insert( $ta, [
					'response_id' => $rid,
					'question_id' => $qids[ $qi ],
					'value_text'  => $val,
				] );
			}
		}
		return 1;
	}

	/** Boletín con notas en la escala paraguaya (1 a 5). */
	protected static function seed_grades( $course_id, $alumnos ) {
		global $wpdb;
		$tabla = cead_acad_table( 'grades' );

		$materias = [ 'Programación', 'Base de Datos', 'Matemática', 'Lengua Castellana', 'Redes' ];
		$terms    = [];
		foreach ( $materias as $m ) {
			$t = get_term_by( 'name', $m, 'cead_acad_subject' );
			if ( ! $t ) {
				$r = wp_insert_term( $m, 'cead_acad_subject' );
				if ( is_wp_error( $r ) ) { continue; }
				$terms[ $m ] = (int) $r['term_id'];
			} else {
				$terms[ $m ] = (int) $t->term_id;
			}
		}

		$n = 0;
		foreach ( $alumnos as $i => $uid ) {
			foreach ( $terms as $tid ) {
				foreach ( [ '1', '2' ] as $periodo ) {
					// Notas variadas pero mayormente aprobadas: un boletín real,
					// no todo cinco ni todo aplazo.
					$score = [ 5, 4, 4, 3, 5, 4, 3, 4 ][ ( $i + $tid + (int) $periodo ) % 8 ];
					$wpdb->replace( $tabla, [
						'student_user_id' => $uid,
						'course_id'       => $course_id,
						'subject_term_id' => $tid,
						'period'          => $periodo,
						'score'           => $score,
						'recorded_at'     => current_time( 'mysql' ),
					] );
					$n++;
				}
			}
		}
		return $n;
	}

	/* ---------------- Utilidades ---------------- */

	protected static function insert( array $args ) {
		$id = wp_insert_post( $args );
		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta( $id, self::FLAG, 1 );
			return (int) $id;
		}
		return 0;
	}

	protected static function make_user( $login, $name, $role ) {
		if ( username_exists( $login ) ) { return 0; }
		$uid = wp_insert_user( [
			'user_login'   => $login,
			'user_pass'    => self::PASS,
			'user_email'   => $login . '@demo.cead.local',
			'display_name' => $name,
			'first_name'   => explode( ' ', $name )[0],
			'role'         => $role,
		] );
		if ( is_wp_error( $uid ) ) { return 0; }
		update_user_meta( $uid, self::FLAG, 1 );
		return (int) $uid;
	}

	protected static function mark_read( $broadcast_id, $user_ids ) {
		global $wpdb;
		$tabla = cead_acad_table( 'broadcast_reads' );
		foreach ( $user_ids as $uid ) {
			$wpdb->replace( $tabla, [
				'broadcast_id' => $broadcast_id,
				'user_id'      => $uid,
				'read_at'      => current_time( 'mysql' ),
			] );
		}
	}
}
