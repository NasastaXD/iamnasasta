<?php
/**
 * Helpers de lectura de la tabla cead_acad_grades.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Grades_Db {

	/**
	 * Boletín del alumno: filas agrupadas por materia con todos sus periodos.
	 *
	 * @return array<int,array{subject_name:string,subject_id:int,course_id:int,course_title:string,grades:array<string,array{score:?float,letter:string,comments:string,recorded_at:string}>}>
	 */
	public static function bulletin_for_student( $student_user_id ) {
		global $wpdb;
		$table = cead_acad_table( 'grades' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE student_user_id = %d ORDER BY course_id ASC, subject_term_id ASC, period ASC",
			(int) $student_user_id
		), ARRAY_A );
		if ( ! $rows ) { return []; }

		$by_key = [];
		foreach ( $rows as $r ) {
			$key = $r['course_id'] . '|' . $r['subject_term_id'];
			if ( ! isset( $by_key[ $key ] ) ) {
				$term = get_term( (int) $r['subject_term_id'], Cead_Acad_Resources_CPT::TAX_SUBJECT );
				$by_key[ $key ] = [
					'subject_name'  => ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $r['subject_term_id'],
					'subject_id'    => (int) $r['subject_term_id'],
					'course_id'     => (int) $r['course_id'],
					'course_title'  => get_the_title( (int) $r['course_id'] ) ?: '#' . $r['course_id'],
					'grades'        => [],
				];
			}
			$by_key[ $key ]['grades'][ $r['period'] ] = [
				'score'       => $r['score'] !== null ? (float) $r['score'] : null,
				'letter'      => (string) $r['letter'],
				'comments'    => (string) $r['comments'],
				'recorded_at' => (string) $r['recorded_at'],
			];
		}
		return array_values( $by_key );
	}

	public static function for_course( $course_id ) {
		global $wpdb;
		$table = cead_acad_table( 'grades' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE course_id = %d ORDER BY student_user_id ASC, subject_term_id ASC, period ASC",
			(int) $course_id
		), ARRAY_A );
	}
}
