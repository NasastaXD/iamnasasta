<?php
/**
 * Importador del horario semanal de clases: qué materia, con qué docente, a
 * qué hora y en qué aula, día por día, para un curso que ya existe.
 *
 * No confundir con el importador "Horarios / eventos" (`events`), que carga
 * el calendario institucional (reuniones, exámenes, actos). Esto es la grilla
 * semanal fija de un curso — lo que hoy solo se podía cargar a mano, fila por
 * fila, en el cuadro "Horario de materias" del admin del curso. Una planilla
 * de una institución real tiene cientos de filas; tipearlas no es viable.
 *
 * El destino es el mismo meta que llena ese cuadro manual, `_cead_acad_horario`
 * en el post del curso — así lo que carga acá se ve igual en el panel y en
 * CEADI sin tocar nada más.
 *
 * Cada fila es una clase de un curso que YA EXISTE (se crea antes con el
 * importador de Cursos): si el título no matchea exacto, la fila es error, no
 * warning — a diferencia de otros importadores donde el curso es opcional,
 * acá ES el destino de la fila, no tiene sentido seguir sin él.
 *
 * Idempotente por (curso, día, hora de inicio): reimportar la misma planilla
 * actualiza la clase existente en vez de duplicarla. Corregir un aula o un
 * docente a mitad de año es, entonces, simplemente volver a subir el archivo.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Importer_Horarios extends Cead_Acad_Importer_Base {

	public function type()  { return 'horarios'; }
	public function label() { return __( 'Horario de clases (por curso)', 'cead-acad' ); }

	public function fields() {
		return [
			'curso'   => [ 'label' => __( 'Curso (título exacto)', 'cead-acad' ),      'required' => true ],
			'dia'     => [ 'label' => __( 'Día (lunes a domingo)', 'cead-acad' ),      'required' => true ],
			'inicio'  => [ 'label' => __( 'Hora de inicio (HH:MM)', 'cead-acad' ),     'required' => true ],
			'fin'     => [ 'label' => __( 'Hora de fin (HH:MM)', 'cead-acad' ),        'required' => true ],
			'materia' => [ 'label' => __( 'Materia', 'cead-acad' ),                    'required' => true ],
			'docente' => [ 'label' => __( 'Docente', 'cead-acad' ),                    'required' => false ],
			'aula'    => [ 'label' => __( 'Aula / lugar', 'cead-acad' ),               'required' => false ],
		];
	}

	/**
	 * Nombres de día → 1..7 (lunes..domingo), el mismo criterio numérico que
	 * usa el resto del sistema (metabox, panel, bot).
	 *
	 * @return array<string,int>
	 */
	public static function dias() {
		return [
			'lunes'     => 1,
			'martes'    => 2,
			'miercoles' => 3,
			'jueves'    => 4,
			'viernes'   => 5,
			'sabado'    => 6,
			'domingo'   => 7,
		];
	}

	/** 0 si no se reconoce. Tolera mayúsculas, tildes y espacios de más. */
	public static function normalizar_dia( $texto ) {
		$s = remove_accents( trim( (string) $texto ) );
		$s = strtolower( $s );
		return self::dias()[ $s ] ?? 0;
	}

	/** '' si no matchea HH:MM en 24 horas. Mismo criterio que usa el metabox. */
	public static function normalizar_hora( $texto ) {
		$t = trim( (string) $texto );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $t ) ? $t : '';
	}

	/*
	 * Orden a propósito: todo lo barato (día, horas, materia) se revisa ANTES
	 * de consultar si el curso existe. Es la única comprobación que toca la
	 * base, así que dejarla al final evita una consulta por cada fila que ya
	 * iba a fallar por otro motivo — y de paso deja el resto de las reglas
	 * verificables sin necesitar WordPress.
	 */
	public function validate_row( $row ) {
		$curso = trim( (string) ( $row['curso'] ?? '' ) );
		if ( '' === $curso ) {
			return [ 'level' => 'error', 'message' => __( 'Falta el curso.', 'cead-acad' ) ];
		}

		$dia_txt = (string) ( $row['dia'] ?? '' );
		if ( ! self::normalizar_dia( $dia_txt ) ) {
			/* translators: %s: día leído del archivo */
			return [ 'level' => 'error', 'message' => sprintf( __( 'Día "%s" no reconocido. Usá lunes, martes, miércoles, jueves, viernes, sábado o domingo.', 'cead-acad' ), $dia_txt ) ];
		}

		$inicio = self::normalizar_hora( $row['inicio'] ?? '' );
		$fin    = self::normalizar_hora( $row['fin'] ?? '' );
		if ( ! $inicio || ! $fin ) {
			return [ 'level' => 'error', 'message' => __( 'Hora de inicio o de fin inválida. Formato HH:MM, 24 horas (ej. 07:00, 14:20).', 'cead-acad' ) ];
		}
		if ( $fin <= $inicio ) {
			return [ 'level' => 'error', 'message' => __( 'La hora de fin tiene que ser posterior a la de inicio.', 'cead-acad' ) ];
		}

		$materia = trim( (string) ( $row['materia'] ?? '' ) );
		if ( '' === $materia ) {
			return [ 'level' => 'error', 'message' => __( 'Falta la materia.', 'cead-acad' ) ];
		}

		if ( ! $this->resolve_course_id( $curso ) ) {
			/* translators: %s: título del curso leído del archivo */
			return [ 'level' => 'error', 'message' => sprintf( __( 'Curso "%s" no existe. Importalo primero con el importador de Cursos (el título tiene que coincidir exacto).', 'cead-acad' ), $curso ) ];
		}

		return [ 'level' => 'ok' ];
	}

	public function commit_row( $row, $job_id ) {
		$course_id = $this->resolve_course_id( trim( (string) $row['curso'] ) );
		if ( ! $course_id ) {
			// validate_row ya lo filtra; esto cubre que el curso se haya
			// borrado entre la validación y el commit.
			return new WP_Error( 'cead_acad_curso_inexistente', __( 'El curso ya no existe.', 'cead-acad' ) );
		}

		$dia    = self::normalizar_dia( $row['dia'] ?? '' );
		$inicio = self::normalizar_hora( $row['inicio'] ?? '' );
		$fin    = self::normalizar_hora( $row['fin'] ?? '' );

		$slot = [
			'dia'     => $dia,
			'inicio'  => $inicio,
			'fin'     => $fin,
			'materia' => sanitize_text_field( (string) $row['materia'] ),
			'docente' => isset( $row['docente'] ) ? sanitize_text_field( (string) $row['docente'] ) : '',
		];
		$aula = isset( $row['aula'] ) ? sanitize_text_field( (string) $row['aula'] ) : '';
		if ( '' !== $aula ) {
			$slot['aula'] = $aula;
		}

		$slots = $this->read_horario( $course_id );

		// Upsert por (día, inicio): dos filas con el mismo curso+día+hora son
		// la misma clase, así que reimportar corrige en vez de duplicar.
		$encontrado = false;
		foreach ( $slots as $i => $s ) {
			if ( (int) ( $s['dia'] ?? 0 ) === $dia && (string) ( $s['inicio'] ?? '' ) === $inicio ) {
				$slots[ $i ] = $slot;
				$encontrado  = true;
				break;
			}
		}
		if ( ! $encontrado ) {
			$slots[] = $slot;
		}

		/*
		 * Se re-lee y re-escribe el meta completo en cada fila (no se acumula
		 * en memoria entre filas): una planilla real tiene unos cientos de
		 * filas repartidas en un puñado de cursos, así que el costo es
		 * insignificante y esto evita tener que sincronizar estado entre
		 * llamadas — cada `commit_row` es independiente, como en el resto de
		 * los importadores.
		 */
		update_post_meta( $course_id, '_cead_acad_horario', wp_json_encode( $slots ) );
		update_post_meta( $course_id, '_cead_acad_imported_via_job', (int) $job_id );

		return true;
	}

	/**
	 * Horario actual del curso, tolerando que el meta esté guardado como
	 * array PHP (lo escribe el sembrador de demo) o como JSON (lo escribe el
	 * metabox manual). Ambas formas conviven en el sistema.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function read_horario( $course_id ) {
		$raw = get_post_meta( (int) $course_id, '_cead_acad_horario', true );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$d = json_decode( $raw, true );
			if ( is_array( $d ) ) {
				return $d;
			}
		}
		return [];
	}

	protected function resolve_course_id( $title ) {
		if ( '' === $title ) {
			return 0;
		}
		$posts = get_posts( [
			'post_type'      => Cead_Acad_Courses_CPT::POST_TYPE,
			'title'          => $title,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post_status'    => 'any',
			'no_found_rows'  => true,
		] );
		return $posts ? (int) $posts[0] : 0;
	}
}
