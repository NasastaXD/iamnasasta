<?php
/**
 * Submit + read de respuestas.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Surveys_Responses {

	/**
	 * Guarda una respuesta completa (response + answers). Devuelve response_id o WP_Error.
	 *
	 * @param int   $survey_id
	 * @param int   $user_id (0 si anónima)
	 * @param array $answers [ question_id => value (string|array) ]
	 */
	public static function submit( $survey_id, $user_id, $answers ) {
		global $wpdb;
		$resp_table = cead_acad_table( 'survey_responses' );
		$ans_table  = cead_acad_table( 'survey_answers' );

		$is_anon = Cead_Acad_Surveys_CPT::is_anonymous( $survey_id );

		// Idempotencia: si no es anónima, un user solo puede responder una vez.
		if ( ! $is_anon && $user_id ) {
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$resp_table} WHERE survey_id = %d AND user_id = %d",
				(int) $survey_id, (int) $user_id
			) );
			if ( $existing ) {
				return new WP_Error( 'already_responded', __( 'Ya respondiste esta encuesta.', 'cead-acad' ) );
			}
		}

		$questions = Cead_Acad_Surveys_Questions::for_survey( $survey_id );
		if ( ! $questions ) {
			return new WP_Error( 'no_questions', __( 'La encuesta no tiene preguntas.', 'cead-acad' ) );
		}

		// Validar requeridas.
		foreach ( $questions as $q ) {
			if ( $q['required'] ) {
				$val = $answers[ $q['id'] ] ?? null;
				if ( self::is_empty_answer( $val ) ) {
					return new WP_Error( 'required_missing', sprintf(
						/* translators: %s pregunta. */
						__( 'La pregunta "%s" es obligatoria.', 'cead-acad' ),
						$q['text']
					) );
				}
			}
		}

		$wpdb->insert(
			$resp_table,
			[
				'survey_id'    => (int) $survey_id,
				'user_id'      => ( $is_anon || ! $user_id ) ? null : (int) $user_id,
				'submitted_at' => current_time( 'mysql', 1 ),
				'ip_hash'      => hash( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? '' ) . NONCE_SALT ),
				'user_agent'   => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);
		$response_id = (int) $wpdb->insert_id;

		foreach ( $questions as $q ) {
			$qid = (int) $q['id'];
			$val = $answers[ $qid ] ?? null;
			if ( self::is_empty_answer( $val ) ) {
				continue;
			}
			[ $val_text, $val_json ] = self::format_answer_value( $q, $val );
			$wpdb->insert(
				$ans_table,
				[
					'response_id' => $response_id,
					'question_id' => $qid,
					'value_text'  => $val_text,
					'value_json'  => $val_json,
				],
				[ '%d', '%d', '%s', '%s' ]
			);
		}

		return $response_id;
	}

	public static function user_already_responded( $survey_id, $user_id ) {
		global $wpdb;
		$table = cead_acad_table( 'survey_responses' );
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE survey_id = %d AND user_id = %d LIMIT 1",
			(int) $survey_id, (int) $user_id
		) );
	}

	public static function count_for_survey( $survey_id ) {
		global $wpdb;
		$table = cead_acad_table( 'survey_responses' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE survey_id = %d",
			(int) $survey_id
		) );
	}

	public static function answers_for_survey( $survey_id ) {
		global $wpdb;
		$ans_table  = cead_acad_table( 'survey_answers' );
		$resp_table = cead_acad_table( 'survey_responses' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT a.question_id, a.value_text, a.value_json
			 FROM {$ans_table} a
			 INNER JOIN {$resp_table} r ON r.id = a.response_id
			 WHERE r.survey_id = %d",
			(int) $survey_id
		), ARRAY_A ) ?: [];
	}

	protected static function is_empty_answer( $val ) {
		if ( $val === null ) { return true; }
		if ( is_array( $val ) ) { return count( array_filter( $val, fn( $v ) => $v !== '' && $v !== null ) ) === 0; }
		return trim( (string) $val ) === '';
	}

	protected static function format_answer_value( $q, $val ) {
		switch ( $q['type'] ) {
			case 'checkbox':
				$arr = array_map( 'sanitize_text_field', (array) $val );
				$arr = array_values( array_filter( $arr, fn( $v ) => $v !== '' ) );
				return [ implode( ', ', $arr ), wp_json_encode( $arr ) ];

			case 'radio':
			case 'date':
				return [ sanitize_text_field( (string) $val ), null ];

			case 'long_text':
				return [ sanitize_textarea_field( (string) $val ), null ];

			case 'scale':
				return [ (string) (int) $val, null ];

			case 'text':
			default:
				return [ sanitize_text_field( (string) $val ), null ];
		}
	}

	/**
	 * CSV export para los resultados de una encuesta. Devuelve string CSV.
	 */
	public static function export_csv( $survey_id ) {
		global $wpdb;
		$resp_table = cead_acad_table( 'survey_responses' );
		$ans_table  = cead_acad_table( 'survey_answers' );
		$questions  = Cead_Acad_Surveys_Questions::for_survey( $survey_id );

		$responses = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$resp_table} WHERE survey_id = %d ORDER BY submitted_at ASC",
			(int) $survey_id
		), ARRAY_A );

		$rid_to_idx = [];
		$rows = [];
		foreach ( $responses as $i => $r ) {
			$rid_to_idx[ (int) $r['id'] ] = $i;
			$user = $r['user_id'] ? get_user_by( 'id', (int) $r['user_id'] ) : null;
			$rows[ $i ] = [
				$r['submitted_at'],
				$user ? $user->display_name : '(anónimo)',
				$user ? $user->user_email   : '',
			];
			foreach ( $questions as $q ) {
				$rows[ $i ][] = '';
			}
		}
		$answers = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.* FROM {$ans_table} a INNER JOIN {$resp_table} r ON r.id = a.response_id WHERE r.survey_id = %d",
			(int) $survey_id
		), ARRAY_A );
		$q_idx_by_id = [];
		foreach ( $questions as $idx => $q ) {
			$q_idx_by_id[ (int) $q['id'] ] = $idx + 3; // offset por columnas fijas.
		}
		foreach ( $answers as $a ) {
			$row_i = $rid_to_idx[ (int) $a['response_id'] ] ?? null;
			$col_i = $q_idx_by_id[ (int) $a['question_id'] ] ?? null;
			if ( null === $row_i || null === $col_i ) { continue; }
			$rows[ $row_i ][ $col_i ] = (string) $a['value_text'];
		}

		// Construir CSV.
		$fh = fopen( 'php://memory', 'r+' );
		$header = [ __( 'Fecha', 'cead-acad' ), __( 'Usuario', 'cead-acad' ), __( 'Email', 'cead-acad' ) ];
		foreach ( $questions as $q ) {
			$header[] = $q['text'];
		}
		// CSV injection guard.
		$header = array_map( [ __CLASS__, 'sanitize_cell' ], $header );
		fputcsv( $fh, $header );
		foreach ( $rows as $row ) {
			fputcsv( $fh, array_map( [ __CLASS__, 'sanitize_cell' ], $row ) );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}

	protected static function sanitize_cell( $cell ) {
		$cell = (string) $cell;
		if ( $cell !== '' && in_array( $cell[0], [ '=', '+', '-', '@' ], true ) ) {
			$cell = "'" . $cell;
		}
		return $cell;
	}
}
