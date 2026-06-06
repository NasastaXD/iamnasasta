<?php
/**
 * CRUD de preguntas (tabla cead_acad_survey_questions).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Surveys_Questions {

	/**
	 * @return array Lista ordenada de preguntas de una encuesta.
	 */
	public static function for_survey( $survey_id ) {
		global $wpdb;
		$table = cead_acad_table( 'survey_questions' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE survey_id = %d ORDER BY position ASC, id ASC",
			(int) $survey_id
		), ARRAY_A );
		return array_map( [ __CLASS__, 'hydrate' ], $rows ?: [] );
	}

	public static function find( $id ) {
		global $wpdb;
		$table = cead_acad_table( 'survey_questions' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Reemplaza todas las preguntas de una encuesta con la lista provista.
	 * Hace delete + insert dentro del mismo flujo (sin transacción explícita).
	 *
	 * @param int   $survey_id
	 * @param array $questions Array de questions normalizadas: type, text, required, options[], config{}
	 */
	public static function replace_all( $survey_id, $questions ) {
		global $wpdb;
		$table = cead_acad_table( 'survey_questions' );
		$wpdb->delete( $table, [ 'survey_id' => (int) $survey_id ], [ '%d' ] );

		$now = current_time( 'mysql', 1 );
		$pos = 0;
		foreach ( $questions as $q ) {
			$type = self::sanitize_type( $q['type'] ?? '' );
			$text = sanitize_text_field( (string) ( $q['text'] ?? '' ) );
			if ( ! $type || ! $text ) {
				continue;
			}
			$config = self::sanitize_config( $type, $q );
			$wpdb->insert(
				$table,
				[
					'survey_id'  => (int) $survey_id,
					'position'   => $pos++,
					'type'       => $type,
					'text'       => $text,
					'required'   => ! empty( $q['required'] ) ? 1 : 0,
					'config'     => $config ? wp_json_encode( $config ) : null,
					'created_at' => $now,
				],
				[ '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
			);
		}
	}

	protected static function hydrate( $row ) {
		$row['required'] = (int) $row['required'];
		$row['config']   = $row['config'] ? json_decode( $row['config'], true ) : [];
		if ( ! is_array( $row['config'] ) ) {
			$row['config'] = [];
		}
		return $row;
	}

	public static function sanitize_type( $type ) {
		return in_array( $type, Cead_Acad_Surveys_CPT::TYPES, true ) ? $type : '';
	}

	protected static function sanitize_config( $type, $q ) {
		$config = [];
		if ( in_array( $type, [ 'radio', 'checkbox' ], true ) ) {
			$raw = $q['options'] ?? [];
			if ( is_string( $raw ) ) {
				$raw = preg_split( '/\r\n|\r|\n/', $raw );
			}
			$opts = [];
			foreach ( (array) $raw as $o ) {
				$o = trim( sanitize_text_field( (string) $o ) );
				if ( $o !== '' ) { $opts[] = $o; }
			}
			$config['options'] = array_values( array_unique( $opts ) );
		}
		if ( 'scale' === $type ) {
			$min = max( 1, min( 10, (int) ( $q['scale_min'] ?? 1 ) ) );
			$max = max( $min + 1, min( 10, (int) ( $q['scale_max'] ?? 5 ) ) );
			$config['min'] = $min;
			$config['max'] = $max;
		}
		return $config;
	}

	public static function type_label( $type ) {
		$labels = [
			'radio'     => __( 'Opción única', 'cead-acad' ),
			'checkbox'  => __( 'Opción múltiple', 'cead-acad' ),
			'text'      => __( 'Texto corto', 'cead-acad' ),
			'long_text' => __( 'Texto largo', 'cead-acad' ),
			'scale'     => __( 'Escala', 'cead-acad' ),
			'date'      => __( 'Fecha', 'cead-acad' ),
		];
		return $labels[ $type ] ?? $type;
	}
}
