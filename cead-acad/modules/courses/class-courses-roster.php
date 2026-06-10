<?php
/**
 * Roster: relación user ↔ course en tabla custom.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Courses_Roster {

	/**
	 * Agrega un usuario al curso. Idempotente por unique (user_id, course_id).
	 */
	public static function add( $user_id, $course_id, $role_in_course = 'student' ) {
		global $wpdb;
		$table = cead_acad_table( 'roster' );

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE user_id = %d AND course_id = %d",
			$user_id, $course_id
		), ARRAY_A );

		if ( $existing ) {
			$wpdb->update(
				$table,
				[ 'status' => 'active', 'role_in_course' => $role_in_course, 'end_date' => null ],
				[ 'id' => (int) $existing['id'] ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);
			return (int) $existing['id'];
		}

		$wpdb->insert(
			$table,
			[
				'user_id'        => (int) $user_id,
				'course_id'      => (int) $course_id,
				'role_in_course' => $role_in_course,
				'start_date'     => current_time( 'Y-m-d' ),
				'status'         => 'active',
				'created_at'     => current_time( 'mysql', 1 ),
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	public static function remove( $user_id, $course_id ) {
		global $wpdb;
		$table = cead_acad_table( 'roster' );
		$wpdb->update(
			$table,
			[ 'status' => 'inactive', 'end_date' => current_time( 'Y-m-d' ) ],
			[ 'user_id' => (int) $user_id, 'course_id' => (int) $course_id ],
			[ '%s', '%s' ],
			[ '%d', '%d' ]
		);
	}

	/**
	 * IDs de cursos activos de un usuario.
	 *
	 * @return int[]
	 */
	public static function courses_for_user( $user_id ) {
		global $wpdb;
		$table = cead_acad_table( 'roster' );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT course_id FROM {$table} WHERE user_id = %d AND status = 'active'",
			(int) $user_id
		) );
		return array_map( 'intval', $ids ?: [] );
	}

	/**
	 * IDs de usuarios activos en un curso.
	 *
	 * @return int[]
	 */
	public static function users_in_course( $course_id, $role_in_course = null ) {
		global $wpdb;
		$table = cead_acad_table( 'roster' );
		if ( $role_in_course ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT user_id FROM {$table} WHERE course_id = %d AND status = 'active' AND role_in_course = %s",
				(int) $course_id, $role_in_course
			) );
		} else {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT user_id FROM {$table} WHERE course_id = %d AND status = 'active'",
				(int) $course_id
			) );
		}
		return array_map( 'intval', $ids ?: [] );
	}

	public static function count_active_in_course( $course_id ) {
		global $wpdb;
		$table = cead_acad_table( 'roster' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE course_id = %d AND status = 'active'",
			(int) $course_id
		) );
	}
}
