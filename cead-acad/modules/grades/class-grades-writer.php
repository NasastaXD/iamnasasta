<?php
/**
 * Alta y actualización de calificaciones fuera del importador.
 *
 * `Cead_Acad_Grades_Db` solo lee, y la única escritura que existía vivía dentro
 * del importador CSV (atada a un `import_job_id`). Esta clase expone esa
 * operación de forma limpia para el resto del plugin — hoy, para que la IA de
 * CEADI pueda cargar una nota por chat con aprobación humana.
 *
 * Dos capas:
 *  - Puras (norm, pick_by_name, norm_period, validate): sin WordPress, testeables.
 *  - Con WordPress (record, match_*): tocan BD y taxonomías.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Grades_Writer {

	/* ---------------------------------------------------------- configuración */

	/** Periodos aceptados por la institución. */
	public static function periods() {
		$p = get_option( 'cead_acad_grades_periods', '' );
		if ( is_array( $p ) && $p ) { return array_map( 'strval', $p ); }
		return [ '1', '2', '3', '4', 'Final' ];
	}

	/**
	 * Nota máxima de la escala. Default 5: es la escala de la educación media
	 * paraguaya (1 a 5). Configurable por si la institución usa otra.
	 */
	public static function score_max() {
		$m = (float) get_option( 'cead_acad_grades_score_max', 5 );
		return ( $m > 0 && $m <= 999.99 ) ? $m : 5.0;
	}

	/** Nota mínima que se considera aprobada (default 2 en la escala de 5). */
	public static function score_pass() {
		$p = (float) get_option( 'cead_acad_grades_score_pass', 2 );
		return ( $p > 0 && $p <= self::score_max() ) ? $p : 2.0;
	}

	/**
	 * Bandas de porcentaje de logro → nota, de mayor a menor.
	 * El default sigue la práctica habitual en Paraguay (60 % para aprobar);
	 * cada institución puede ajustarlo con la opción `cead_acad_grades_scale_bands`.
	 *
	 * @return array<int,array{0:float,1:float}> [ [mínimo %, nota], ... ]
	 */
	public static function scale_bands() {
		$raw = get_option( 'cead_acad_grades_scale_bands', '' );
		if ( is_array( $raw ) && $raw ) {
			$bands = [];
			foreach ( $raw as $b ) {
				if ( ! is_array( $b ) || count( $b ) < 2 ) { continue; }
				$bands[] = [ (float) $b[0], (float) $b[1] ];
			}
			if ( $bands ) {
				usort( $bands, static fn( $a, $b ) => $b[0] <=> $a[0] );
				return $bands;
			}
		}
		return [ [ 90.0, 5.0 ], [ 80.0, 4.0 ], [ 70.0, 3.0 ], [ 60.0, 2.0 ], [ 0.0, 1.0 ] ];
	}

	/** Porcentaje de logro → nota de la escala. null si el dato no sirve. */
	public static function percent_to_score( $percent ) {
		if ( ! is_numeric( $percent ) ) { return null; }
		$p = (float) $percent;
		if ( $p < 0 || $p > 100 ) { return null; }
		foreach ( self::scale_bands() as $band ) {
			if ( $p >= $band[0] ) { return (float) $band[1]; }
		}
		return null;
	}

	/**
	 * Puntaje obtenido sobre el total → porcentaje.
	 * Rechaza sacar más puntos que el total (el error de tipeo más común).
	 */
	public static function points_to_percent( $obtained, $total ) {
		if ( ! is_numeric( $obtained ) || ! is_numeric( $total ) ) { return null; }
		$o = (float) $obtained;
		$t = (float) $total;
		if ( $t <= 0 || $o < 0 || $o > $t ) { return null; }
		return round( $o / $t * 100, 2 );
	}

	/** Cuánto falta para aprobar, en porcentaje. Para responderle al alumnado. */
	public static function percent_needed_to_pass() {
		$pass = self::score_pass();
		$min  = null;
		foreach ( self::scale_bands() as $band ) {
			if ( $band[1] >= $pass ) { $min = $band[0]; }
		}
		return $min;
	}

	/* ----------------------------------------------------------------- puras */

	/** Número legible: sin decimales inútiles y con coma decimal. */
	public static function fmt( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 2, ',', '' ), '0' ), ',' );
	}

	/** Minúsculas, sin acentos y con espacios colapsados, para comparar nombres. */
	public static function norm( $s ) {
		$s = (string) $s;
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$s = strtr( $s, [ 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' ] );
		return trim( preg_replace( '/\s+/', ' ', $s ) );
	}

	/**
	 * Elige a una persona de una lista a partir de un nombre escrito a mano.
	 * Va de lo más estricto a lo más laxo y se queda con el primer nivel que da
	 * resultados, para no confundir a dos personas con apellido parecido.
	 *
	 * @param array  $people [['id'=>int,'name'=>string,'doc'=>string], ...]
	 * @param string $query  Lo que escribió la persona.
	 * @return array{status:string,matches:array} status: exact|ambiguous|none
	 */
	public static function pick_by_name( array $people, $query ) {
		$q = self::norm( $query );
		if ( '' === $q || ! $people ) {
			return [ 'status' => 'none', 'matches' => [] ];
		}

		$levels = [ [], [], [], [] ];
		$q_tokens = array_values( array_filter( explode( ' ', $q ) ) );
		$digits   = preg_replace( '/\D/', '', $q );

		foreach ( $people as $p ) {
			$name = self::norm( $p['name'] ?? '' );
			$doc  = preg_replace( '/\D/', '', (string) ( $p['doc'] ?? '' ) );

			// 1) Documento exacto (si escribieron solo números).
			if ( '' !== $digits && $doc !== '' && $doc === $digits ) {
				$levels[0][] = $p;
				continue;
			}
			// 2) Nombre idéntico.
			if ( $name === $q ) {
				$levels[1][] = $p;
				continue;
			}
			// 3) Cada palabra buscada es prefijo de alguna del nombre
			//    («perez juan» y «j perez» encuentran a «Juan Pérez»).
			$name_tokens = array_values( array_filter( explode( ' ', $name ) ) );
			$all_hit = (bool) $q_tokens;
			foreach ( $q_tokens as $qt ) {
				$hit = false;
				foreach ( $name_tokens as $nt ) {
					if ( 0 === strpos( $nt, $qt ) ) { $hit = true; break; }
				}
				if ( ! $hit ) { $all_hit = false; break; }
			}
			if ( $all_hit ) {
				$levels[2][] = $p;
				continue;
			}
			// 4) Coincidencia suelta dentro del nombre.
			if ( false !== strpos( $name, $q ) ) {
				$levels[3][] = $p;
			}
		}

		foreach ( $levels as $found ) {
			if ( ! $found ) { continue; }
			if ( 1 === count( $found ) ) {
				return [ 'status' => 'exact', 'matches' => $found ];
			}
			return [ 'status' => 'ambiguous', 'matches' => array_slice( $found, 0, 5 ) ];
		}
		return [ 'status' => 'none', 'matches' => [] ];
	}

	/** Normaliza «segundo trimestre», «2do», «p2», «final» → '2' / 'Final'. */
	public static function norm_period( $raw ) {
		$s = self::norm( $raw );
		if ( '' === $s ) { return ''; }
		if ( preg_match( '/\bfinal\b/', $s ) ) { return 'Final'; }

		$words = [
			'primer' => '1', 'primero' => '1', 'primera' => '1', 'uno' => '1',
			'segundo' => '2', 'segunda' => '2', 'dos' => '2',
			'tercer' => '3', 'tercero' => '3', 'tercera' => '3', 'tres' => '3',
			'cuarto' => '4', 'cuarta' => '4', 'cuatro' => '4',
		];
		foreach ( $words as $w => $n ) {
			if ( preg_match( '/\b' . $w . '/', $s ) ) { return $n; }
		}
		if ( preg_match( '/(\d{1,2})/', $s, $m ) ) {
			$n = (int) $m[1];
			if ( $n >= 1 && $n <= 12 ) { return (string) $n; }
		}
		return '';
	}

	/**
	 * Valida y sanea los datos de una nota sin tocar la base.
	 *
	 * @return array|WP_Error Datos listos para guardar, o el error concreto.
	 */
	public static function validate( array $args ) {
		$student = (int) ( $args['student_user_id'] ?? 0 );
		$course  = (int) ( $args['course_id'] ?? 0 );
		$subject = (int) ( $args['subject_term_id'] ?? 0 );
		$by      = (int) ( $args['recorded_by'] ?? 0 );

		if ( $student <= 0 ) { return new WP_Error( 'cead_grade_student', __( 'Falta el alumno.', 'cead-acad' ) ); }
		if ( $course <= 0 )  { return new WP_Error( 'cead_grade_course', __( 'Falta el curso.', 'cead-acad' ) ); }
		if ( $subject <= 0 ) { return new WP_Error( 'cead_grade_subject', __( 'Falta la materia.', 'cead-acad' ) ); }
		if ( $by <= 0 )      { return new WP_Error( 'cead_grade_author', __( 'Falta quién carga la nota.', 'cead-acad' ) ); }

		$period = self::norm_period( $args['period'] ?? '' );
		if ( '' === $period ) {
			return new WP_Error( 'cead_grade_period', __( 'No entendí el periodo (1, 2, 3, 4 o Final).', 'cead-acad' ) );
		}
		// La columna es VARCHAR(20): más largo lo truncaría MySQL en silencio.
		if ( strlen( $period ) > 20 ) {
			return new WP_Error( 'cead_grade_period', __( 'El periodo es demasiado largo.', 'cead-acad' ) );
		}
		// Tiene que ser uno de los periodos que la institución configuró: evita
		// que un typo (p. ej. "11" en vez de "1") quede cargado como si fuera
		// un periodo legítimo.
		if ( ! in_array( $period, self::periods(), true ) ) {
			/* translators: %s: lista de periodos configurados, separados por coma */
			return new WP_Error( 'cead_grade_period', sprintf( __( 'Ese periodo no existe en el colegio. Periodos válidos: %s.', 'cead-acad' ), implode( ', ', self::periods() ) ) );
		}

		$score = $args['score'] ?? null;
		if ( '' === $score ) { $score = null; }
		if ( null !== $score ) {
			if ( ! is_numeric( $score ) ) {
				return new WP_Error( 'cead_grade_score', __( 'La nota tiene que ser un número.', 'cead-acad' ) );
			}
			$score = round( (float) $score, 2 );
			$max   = self::score_max();
			if ( $score < 0 ) {
				return new WP_Error(
					'cead_grade_score',
					sprintf(
						/* translators: %s: nota máxima de la escala */
						__( 'Esa nota está fuera de la escala del colegio (0 a %s). Si estás usando porcentaje, decímelo así lo convierto.', 'cead-acad' ),
						self::fmt( $max )
					)
				);
			}
			// Failsafe: en una escala chica (1 a 5), un número como 45 u 85 casi
			// siempre es un porcentaje o un puntaje escrito por error como nota.
			if ( $max <= 10 && $score > $max ) {
				return new WP_Error( 'cead_grade_score', __( 'Esa nota parece un porcentaje, no una nota.', 'cead-acad' ) );
			}
			if ( $score > $max ) {
				return new WP_Error(
					'cead_grade_score',
					sprintf(
						/* translators: %s: nota máxima de la escala */
						__( 'Esa nota está fuera de la escala del colegio (0 a %s). Si estás usando porcentaje, decímelo así lo convierto.', 'cead-acad' ),
						self::fmt( $max )
					)
				);
			}
		}

		$letter = sanitize_text_field( (string) ( $args['letter'] ?? '' ) );
		if ( strlen( $letter ) > 8 ) { $letter = substr( $letter, 0, 8 ); } // columna VARCHAR(8)

		if ( null === $score && '' === $letter ) {
			return new WP_Error( 'cead_grade_empty', __( 'Necesito una nota o una letra.', 'cead-acad' ) );
		}

		$comments = sanitize_textarea_field( (string) ( $args['comments'] ?? '' ) );
		if ( function_exists( 'mb_substr' ) ) { $comments = mb_substr( $comments, 0, 500 ); }

		return [
			'student_user_id' => $student,
			'course_id'       => $course,
			'subject_term_id' => $subject,
			'period'          => $period,
			'score'           => $score,
			'letter'          => $letter,
			'comments'        => $comments,
			'recorded_by'     => $by,
		];
	}

	/* --------------------------------------------------------- con WordPress */

	/** Cohorte del curso (para poder filtrar por año lectivo). */
	protected static function cohort_of_course( $course_id ) {
		$terms = get_the_terms( (int) $course_id, 'cead_acad_cohort' );
		if ( is_array( $terms ) && $terms ) { return (int) $terms[0]->term_id; }
		return 0;
	}

	/** ¿La persona puede poner notas en este curso? */
	public static function user_can_grade_course( $uid, $course_id ) {
		$uid = (int) $uid;
		if ( ! $uid || ! user_can( $uid, 'cead_acad_record_grade' ) ) { return false; }
		if ( user_can( $uid, 'cead_acad_manage_courses' ) ) { return true; } // dirección/secretaría
		$mine = class_exists( 'Cead_Acad_Courses_Roster' )
			? Cead_Acad_Courses_Roster::courses_for_user( $uid )
			: [];
		if ( in_array( (int) $course_id, array_map( 'intval', (array) $mine ), true ) ) { return true; }
		return (int) get_post_meta( (int) $course_id, '_cead_acad_tutor', true ) === $uid;
	}

	/** Busca un alumno por nombre dentro de un curso. */
	public static function match_student_in_course( $course_id, $query ) {
		if ( ! class_exists( 'Cead_Acad_Courses_Roster' ) ) {
			return [ 'status' => 'none', 'matches' => [] ];
		}
		$ids = array_merge(
			(array) Cead_Acad_Courses_Roster::users_in_course( (int) $course_id, 'student' ),
			(array) Cead_Acad_Courses_Roster::users_in_course( (int) $course_id, 'delegate' )
		);
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) { return [ 'status' => 'none', 'matches' => [] ]; }

		$people = [];
		foreach ( get_users( [ 'include' => $ids, 'orderby' => 'display_name' ] ) as $u ) {
			$people[] = [
				'id'   => (int) $u->ID,
				'name' => (string) $u->display_name,
				'doc'  => (string) get_user_meta( $u->ID, '_cead_acad_document_id', true ),
			];
		}
		return self::pick_by_name( $people, $query );
	}

	/**
	 * Busca una materia por nombre, dando prioridad a las que ya se dictan en el
	 * curso. Con `$create = false` nunca inventa un término: así un typo no crea
	 * una materia nueva sin que un humano lo apruebe.
	 *
	 * @return array{status:string,matches:array,term_id:int}
	 */
	public static function match_subject( $name, $course_id = 0, $create = false ) {
		$name = trim( (string) $name );
		if ( '' === $name ) { return [ 'status' => 'none', 'matches' => [], 'term_id' => 0 ]; }

		$terms = get_terms( [ 'taxonomy' => 'cead_acad_subject', 'hide_empty' => false ] );
		$people = [];
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$people[] = [ 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'doc' => '' ];
			}
		}

		$hit = self::pick_by_name( $people, $name );
		if ( 'exact' === $hit['status'] ) {
			$hit['term_id'] = (int) $hit['matches'][0]['id'];
			return $hit;
		}
		if ( 'ambiguous' === $hit['status'] ) {
			$hit['term_id'] = 0;
			return $hit;
		}

		if ( $create ) {
			$new = wp_insert_term( $name, 'cead_acad_subject' );
			if ( ! is_wp_error( $new ) ) {
				return [ 'status' => 'created', 'matches' => [], 'term_id' => (int) $new['term_id'] ];
			}
		}
		return [ 'status' => 'none', 'matches' => [], 'term_id' => 0 ];
	}

	/** Nota ya cargada para esa combinación exacta, o null. */
	public static function find( $student_user_id, $course_id, $subject_term_id, $period ) {
		global $wpdb;
		$table = cead_acad_table( 'grades' );
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE student_user_id = %d AND course_id = %d AND subject_term_id = %d AND period = %s",
			(int) $student_user_id,
			(int) $course_id,
			(int) $subject_term_id,
			(string) $period
		), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Guarda una nota. Idempotente por la clave única
	 * (alumno, curso, materia, periodo): si ya existía la actualiza.
	 *
	 * A diferencia del importador, no depende de un `import_job_id` (queda NULL,
	 * lo que distingue la carga manual de la importada) y el autor viene
	 * explícito en vez de `get_current_user_id()`, porque el bot corre en una
	 * petición REST sin sesión.
	 *
	 * @return array{id:int,created:bool,previous:?array}|WP_Error
	 */
	public static function record( array $args ) {
		global $wpdb;

		$v = self::validate( $args );
		if ( is_wp_error( $v ) ) { return $v; }

		$table = cead_acad_table( 'grades' );

		$prev = self::find( $v['student_user_id'], $v['course_id'], $v['subject_term_id'], $v['period'] );

		$cohort = self::cohort_of_course( $v['course_id'] );
		$data   = [
			'student_user_id' => $v['student_user_id'],
			'course_id'       => $v['course_id'],
			'subject_term_id' => $v['subject_term_id'],
			'cohort_term_id'  => $cohort ?: null,
			'period'          => $v['period'],
			'score'           => $v['score'],
			'letter'          => $v['letter'],
			'comments'        => $v['comments'],
			'recorded_by'     => $v['recorded_by'],
			'recorded_at'     => current_time( 'mysql', 1 ),
		];
		$formats = [ '%d', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%d', '%s' ];

		if ( $prev ) {
			$ok = $wpdb->update( $table, $data, [ 'id' => (int) $prev['id'] ], $formats, [ '%d' ] );
			$id = (int) $prev['id'];
		} else {
			$ok = $wpdb->insert( $table, $data, $formats );
			$id = (int) $wpdb->insert_id;
		}
		// $wpdb no lanza excepciones: sin este chequeo diríamos «listo» sin guardar.
		if ( false === $ok ) {
			return new WP_Error( 'cead_grade_db', __( 'No pude guardar la nota.', 'cead-acad' ) );
		}

		Cead_Acad_Audit::log( 'grade_recorded', [
			'user_id'     => $v['recorded_by'],
			'entity_type' => 'grade',
			'entity_id'   => $id,
			'payload'     => [
				'source'  => 'whatsapp',
				'course'  => $v['course_id'],
				'student' => $v['student_user_id'],
				'subject' => $v['subject_term_id'],
				'period'  => $v['period'],
				'from'    => $prev['score'] ?? null,
				'to'      => $v['score'],
			],
		] );

		return [ 'id' => $id, 'created' => ! $prev, 'previous' => $prev ?: null ];
	}
}
