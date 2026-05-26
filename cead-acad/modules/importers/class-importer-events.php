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
			'titulo'      => [ 'label' => __( 'Título', 'cead-acad' ),                              'required' => true ],
			'inicio'      => [ 'label' => __( 'Inicio (YYYY-MM-DD HH:MM)', 'cead-acad' ),           'required' => true ],
			'fin'         => [ 'label' => __( 'Fin (YYYY-MM-DD HH:MM)', 'cead-acad' ),              'required' => false ],
			'tipo'        => [ 'label' => __( 'Tipo (clase/reunion/examen/evento)', 'cead-acad' ),  'required' => false ],
			'lugar'       => [ 'label' => __( 'Lugar', 'cead-acad' ),                               'required' => false ],
			'todo_el_dia' => [ 'label' => __( 'Todo el día (1/0)', 'cead-acad' ),                   'required' => false ],
			'curso'       => [ 'label' => __( 'Curso destinatario (título)', 'cead-acad' ),         'required' => false ],
			'descripcion' => [ 'label' => __( 'Descripción', 'cead-acad' ),                         'required' => false ],
		];
	}

	public function validate_row( $row ) {
		$titulo = trim( (string) ( $row['titulo'] ?? '' ) );
		$inicio = trim( (string) ( $row['inicio'] ?? '' ) );
		if ( $titulo === '' || $inicio === '' ) {
			return [ 'level' => 'error', 'message' => __( 'Falta título o fecha de inicio.', 'cead-acad' ) ];
		}
		if ( ! strtotime( $inicio ) ) {
			return [ 'level' => 'error', 'message' => sprintf( __( 'Fecha de inicio inválida: %s', 'cead-acad' ), $inicio ) ];
		}
		$fin = trim( (string) ( $row['fin'] ?? '' ) );
		if ( $fin !== '' && ! strtotime( $fin ) ) {
			return [ 'level' => 'error', 'message' => sprintf( __( 'Fecha de fin inválida: %s', 'cead-acad' ), $fin ) ];
		}
		$tipo = strtolower( trim( (string) ( $row['tipo'] ?? '' ) ) );
		if ( $tipo !== '' && ! in_array( $tipo, Cead_Acad_Schedule_CPT::TYPES, true ) ) {
			return [ 'level' => 'warn', 'message' => sprintf( __( 'Tipo desconocido "%s" — se usa "evento".', 'cead-acad' ), $tipo ) ];
		}
		$curso = trim( (string) ( $row['curso'] ?? '' ) );
		if ( $curso !== '' && ! $this->resolve_course_id( $curso ) ) {
			return [ 'level' => 'warn', 'message' => sprintf( __( 'Curso "%s" no encontrado — el evento se crea sin audiencia.', 'cead-acad' ), $curso ) ];
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
		$desc   = isset( $row['descripcion'] ) ? sanitize_textarea_field( $row['descripcion'] ) : '';

		if ( ! in_array( $tipo, Cead_Acad_Schedule_CPT::TYPES, true ) ) {
			$tipo = 'evento';
		}

		// Normalizar formato de fechas a Y-m-d\TH:i (lo que espera el metabox).
		$ts_start = strtotime( $inicio );
		$inicio_n = $ts_start ? gmdate( 'Y-m-d\TH:i', $ts_start ) : '';
		$ts_end   = $fin ? strtotime( $fin ) : 0;
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
		update_post_meta( $post_id, '_cead_acad_imported_via_job', (int) $job_id );

		// Audiencia: si vino curso, lo agregamos al event audiences (no reemplaza).
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
