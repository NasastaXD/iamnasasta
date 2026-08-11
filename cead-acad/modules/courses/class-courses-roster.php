<?php
/**
 * Roster: relación user ↔ course en tabla custom.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Courses_Roster {

	/**
	 * Agrega un usuario al curso. Idempotente por unique (user_id, course_id).
	 *
	 * Devuelve el id de la fila, o **0 si la escritura falló**.
	 *
	 * Antes se devolvía `$wpdb->insert_id` sin mirar si el insert había andado.
	 * Ante un fallo, ese valor conserva el id del insert ANTERIOR de la misma
	 * request, así que la función podía devolver un id ajeno y quien la llama lo
	 * leía como «se inscribió bien». La persona quedaba fuera del curso y todo lo
	 * que cuelga del roster —horario, boletín, tareas, comunicados del curso— le
	 * aparecía vacío, sin ningún error a la vista.
	 */
	public static function add( $user_id, $course_id, $role_in_course = 'student' ) {
		global $wpdb;
		$table = cead_acad_table( 'roster' );

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE user_id = %d AND course_id = %d",
			$user_id, $course_id
		), ARRAY_A );

		if ( $existing ) {
			$res = $wpdb->update(
				$table,
				[ 'status' => 'active', 'role_in_course' => $role_in_course, 'end_date' => null ],
				[ 'id' => (int) $existing['id'] ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);
			// Solo `false` es error: 0 filas afectadas significa que ya estaba
			// igual, que es exactamente lo que se busca al reinscribir.
			if ( false === $res ) {
				error_log( '[CeadAcad][Roster] no se pudo reactivar la inscripción: user ' . (int) $user_id . ' curso ' . (int) $course_id );
				return 0;
			}
			return (int) $existing['id'];
		}

		$res = $wpdb->insert(
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
		if ( false === $res ) {
			error_log( '[CeadAcad][Roster] no se pudo inscribir: user ' . (int) $user_id . ' curso ' . (int) $course_id );
			return 0;
		}
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

	/**
	 * ¿El usuario sigue activo con ese rol en algún otro curso? Útil para no
	 * sacarle el rol global (p. ej. cead_acad_delegate) si todavía lo ejerce
	 * en otro curso.
	 */
	public static function has_active_role_elsewhere( $user_id, $role_in_course, $exclude_course_id ) {
		global $wpdb;
		$table = cead_acad_table( 'roster' );
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND role_in_course = %s AND status = 'active' AND course_id != %d",
			(int) $user_id, $role_in_course, (int) $exclude_course_id
		) );
		return $count > 0;
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
