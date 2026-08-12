<?php
/**
 * Importador de eventos / horarios. Crea posts cead_acad_event con start/end,
 * tipo, lugar y audiencia opcional por curso. Idempotente por (título, fecha
 * de inicio).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Importer_Events extends Cead_Acad_Importer_Base {

	public function type()  { return 'events'; }
	public function label() { return __( 'Horarios / eventos', 'cead-acad' ); }

	public function fields() {
		return [
			'titulo'      => [ 'label' => __( 'Título', 'cead-acad' ),                                          'required' => true ],
			'inicio'      => [ 'label' => __( 'Inicio (AAAA-MM-DD o DD/MM/AAAA, con hora opcional)', 'cead-acad' ), 'required' => true ],
			'fin'         => [ 'label' => __( 'Fin (mismo formato)', 'cead-acad' ),                              'required' => false ],
			'tipo'        => [ 'label' => __( 'Tipo (clase/reunion/examen/entrega/feriado/acto/excursion/cierre/evento)', 'cead-acad' ), 'required' => false ],
			'lugar'       => [ 'label' => __( 'Lugar', 'cead-acad' ),                                            'required' => false ],
			'todo_el_dia' => [ 'label' => __( 'Todo el día (1/0)', 'cead-acad' ),                                'required' => false ],
			'curso'       => [ 'label' => __( 'Curso destinatario (título, vacío = para todos)', 'cead-acad' ),  'required' => false ],
			'color'       => [ 'label' => __( 'Color (#RRGGBB, opcional, vacío = color del tipo)', 'cead-acad' ), 'required' => false ],
			'descripcion' => [ 'label' => __( 'Descripción', 'cead-acad' ),                                      'required' => false ],
		];
	}

	/**
	 * Reescribe una fecha en formato paraguayo (DD/MM/AAAA, con / o -) a ISO
	 * antes de pasarla a strtotime(). Sin esto, strtotime() interpreta las
	 * barras como formato estadounidense MM/DD/AAAA: con el día > 12 la
	 * fecha directamente no parsea —rechaza feriados como el 15/08,
	 * Fundación de Asunción— y con día ≤ 12 la acepta pero cambia mes y día
	 * EN SILENCIO (un 01/05 —1º de mayo— se convierte en 5 de enero, sin
	 * ningún error a la vista). Una planilla real de secretaría con
	 * feriados y fechas célebres casi siempre viene en formato día/mes/año,
	 * no el que pide la etiqueta del campo.
	 *
	 * ISO (AAAA-MM-DD, con guión y el año primero) no se toca: no tiene
	 * esta ambigüedad y ya funciona.
	 */
	public static function normalizar_fecha( $texto ) {
		$t = trim( (string) $texto );
		if ( preg_match( '#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})(.*)$#', $t, $m ) ) {
			[ , $d, $mes, $y, $resto ] = $m;
			if ( checkdate( (int) $mes, (int) $d, (int) $y ) ) {
				return sprintf( '%04d-%02d-%02d%s', $y, $mes, $d, $resto );
			}
		}
		return $t;
	}

	public function validate_row( $row ) {
		$titulo = trim( (string) ( $row['titulo'] ?? '' ) );
		$inicio = self::normalizar_fecha( $row['inicio'] ?? '' );
		if ( $titulo === '' || $inicio === '' ) {
			return [ 'level' => 'error', 'message' => __( 'Falta título o fecha de inicio.', 'cead-acad' ) ];
		}
		if ( ! strtotime( $inicio ) ) {
			/* translators: %s: valor de fecha leído del archivo */
			return [ 'level' => 'error', 'message' => sprintf( __( 'Fecha de inicio inválida: %s', 'cead-acad' ), trim( (string) ( $row['inicio'] ?? '' ) ) ) ];
		}
		$fin = self::normalizar_fecha( $row['fin'] ?? '' );
		if ( $fin !== '' && ! strtotime( $fin ) ) {
			/* translators: %s: valor de fecha leído del archivo */
			return [ 'level' => 'error', 'message' => sprintf( __( 'Fecha de fin inválida: %s', 'cead-acad' ), trim( (string) ( $row['fin'] ?? '' ) ) ) ];
		}
		$tipo = strtolower( trim( (string) ( $row['tipo'] ?? '' ) ) );
		if ( $tipo !== '' && ! in_array( $tipo, Cead_Acad_Schedule_CPT::TYPES, true ) ) {
			/* translators: %s: tipo de evento leído del archivo */
			return [ 'level' => 'warn', 'message' => sprintf( __( 'Tipo desconocido "%s" — se usa "evento".', 'cead-acad' ), $tipo ) ];
		}
		$curso = trim( (string) ( $row['curso'] ?? '' ) );
		if ( $curso !== '' && ! $this->resolve_course_id( $curso ) ) {
			/* translators: %s: nombre del curso leído del archivo */
			return [ 'level' => 'warn', 'message' => sprintf( __( 'Curso "%s" no encontrado — el evento se crea para todos, sin quedar limitado a ese curso.', 'cead-acad' ), $curso ) ];
		}
		$color = trim( (string) ( $row['color'] ?? '' ) );
		if ( $color !== '' && ! sanitize_hex_color( $color ) ) {
			/* translators: %s: color leído del archivo */
			return [ 'level' => 'warn', 'message' => sprintf( __( 'Color "%s" inválido (esperado #RRGGBB) — se usa el color por defecto del tipo.', 'cead-acad' ), $color ) ];
		}
		return [ 'level' => 'ok' ];
	}

	public function commit_row( $row, $job_id ) {
		$titulo = sanitize_text_field( (string) $row['titulo'] );
		$inicio = sanitize_text_field( (string) $row['inicio'] );
		$fin    = isset( $row['fin'] )         ? sanitize_text_field( $row['fin'] )         : '';
		$tipo   = isset( $row['tipo'] )        ? strtolower( sanitize_text_field( $row['tipo'] ) ) : '';
		$lugar  = isset( $row['lugar'] )       ? sanitize_text_field( $row['lugar'] )       : '';
		$all    = isset( $row['todo_el_dia'] ) ? ( in_array( strtolower( trim( $row['todo_el_dia'] ) ), [ '1', 'si', 'sí', 'true', 'yes' ], true ) ? 1 : 0 ) : 0;
		$curso  = isset( $row['curso'] )       ? trim( (string) $row['curso'] )             : '';
		// sanitize_hex_color() devuelve null si no matchea #RRGGBB/#RGB: inválido = '' = color del tipo.
		$color  = isset( $row['color'] )       ? (string) ( sanitize_hex_color( trim( (string) $row['color'] ) ) ?? '' ) : '';
		$desc   = isset( $row['descripcion'] ) ? sanitize_textarea_field( $row['descripcion'] ) : '';

		if ( ! in_array( $tipo, Cead_Acad_Schedule_CPT::TYPES, true ) ) {
			$tipo = 'evento';
		}

		// Normalizar formato de fechas a Y-m-d\TH:i (lo que espera el metabox).
		$ts_start = strtotime( self::normalizar_fecha( $inicio ) );
		$inicio_n = $ts_start ? gmdate( 'Y-m-d\TH:i', $ts_start ) : '';
		$ts_end   = $fin ? strtotime( self::normalizar_fecha( $fin ) ) : 0;
		$fin_n    = $ts_end ? gmdate( 'Y-m-d\TH:i', $ts_end ) : '';

		// Idempotencia por (título + start).
		global $wpdb;
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_cead_acad_event_start'
			 WHERE p.post_type = %s AND p.post_title = %s AND pm.meta_value = %s
			 LIMIT 1",
			Cead_Acad_Schedule_CPT::POST_TYPE, $titulo, $inicio_n
		) );

		$post_data = [
			'post_type'    => Cead_Acad_Schedule_CPT::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $titulo,
			'post_content' => $desc,
		];
		if ( $existing_id ) {
			$post_data['ID'] = $existing_id;
			$post_id = wp_update_post( $post_data, true );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_cead_acad_event_start',    $inicio_n );
		update_post_meta( $post_id, '_cead_acad_event_end',      $fin_n );
		update_post_meta( $post_id, '_cead_acad_event_all_day',  $all );
		update_post_meta( $post_id, '_cead_acad_event_location', $lugar );
		update_post_meta( $post_id, '_cead_acad_event_type',     $tipo );
		update_post_meta( $post_id, '_cead_acad_event_color',    $color );
		update_post_meta( $post_id, '_cead_acad_imported_via_job', (int) $job_id );

		// Audiencia: si vino curso y matchea, lo agregamos (no reemplaza el resto).
		if ( $curso !== '' ) {
			$course_id = $this->resolve_course_id( $curso );
			if ( $course_id ) {
				$existing = Cead_Acad_Audiences::get( 'event', $post_id );
				$audiences = [];
				foreach ( $existing as $a ) {
					$audiences[] = [ 'type' => $a['audience_type'], 'value' => $a['audience_value'] ];
				}
				$candidate = [ 'type' => 'course', 'value' => (string) $course_id ];
				$already = false;
				foreach ( $audiences as $a ) {
					if ( $a['type'] === 'course' && (string) $a['value'] === (string) $course_id ) {
						$already = true;
						break;
					}
				}
				if ( ! $already ) {
					$audiences[] = $candidate;
				}
				Cead_Acad_Audiences::set( 'event', $post_id, $audiences );
			}
		}

		/*
		 * Sin ninguna audiencia a esta altura —sin curso, o con un curso que no
		 * matcheó ningún título real— el evento queda institucional, para
		 * todos: un feriado, un acto, un período de exámenes general. Sin esto
		 * el evento se creaba sin audiencia y `Schedule_Feed::for_user()` no lo
		 * mostraba a NADIE (`subjects_for_user()` no encuentra a quién
		 * mostrárselo si no hay ninguna fila de audiencia) — quedaba invisible
		 * en el calendario de todo el mundo, que es justo el bug reportado.
		 * Solo se aplica si de verdad no tiene audiencia todavía, para no
		 * pisar una que se haya cargado a mano después de importar.
		 */
		if ( ! Cead_Acad_Audiences::get( 'event', $post_id ) ) {
			Cead_Acad_Audiences::set( 'event', $post_id, [ [ 'type' => 'all', 'value' => '*' ] ] );
		}

		return true;
	}

	protected function resolve_course_id( $title ) {
		$posts = get_posts( [
			'post_type'      => Cead_Acad_Courses_CPT::POST_TYPE,
			'title'          => $title,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );
		return $posts ? (int) $posts[0] : 0;
	}
}
