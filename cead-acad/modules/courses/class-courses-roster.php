<?php
/**
 * Roster: relación user ↔ course en tabla custom.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Courses_Roster {

	/**
	 * El rol con el que se busca a los delegados.
	 *
	 * Va en una constante para poder verificarlo contra los roles que el plugin
	 * registra de verdad. Una falta de tipeo acá no rompe nada visible: la
	 * consulta devuelve cero usuarios y la pantalla dice «todavía no hay
	 * delegados asignados», que es exactamente lo que se ve cuando SÍ los hay
	 * pero se los busca mal. Un test lo cruza contra el catálogo de roles.
	 */
	const DELEGATE_ROLE = 'cead_acad_delegate';

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

	/**
	 * La lista de delegados del colegio, con su curso y su contacto.
	 *
	 * La fuente es el ROL de la persona, no el cargo cargado en el curso.
	 *
	 * La primera versión hacía al revés: recorría los cursos leyendo
	 * `_cead_acad_delegate`. En el papel era más correcto —el delegado es un
	 * cargo sobre un curso— pero en el colegio real los delegados están dados de
	 * alta como usuarios con su rol y NADIE completó ese campo en las fichas de
	 * curso. Resultado: la pantalla salía vacía teniendo delegados cargados. El
	 * dato que existe manda sobre el dato que debería existir.
	 *
	 * El curso se agrega si se lo puede averiguar, por dos caminos y en este
	 * orden: el cargo en la ficha del curso (si alguien sí lo cargó, es el más
	 * específico) y, si no, el curso en el que la persona está inscripta. Sin
	 * ninguno de los dos, la ficha sale igual pero sin curso — que es mucho
	 * mejor que no salir.
	 *
	 * @return array<int,array<string,mixed>> Ordenada por nombre.
	 */
	public static function delegates() {
		$usuarios = get_users( [
			'role'    => self::DELEGATE_ROLE,
			'orderby' => 'display_name',
			'order'   => 'ASC',
		] );
		if ( ! $usuarios ) { return []; }

		// Cargo asignado en la ficha del curso: user_id => curso. Se arma de una
		// sola pasada en vez de consultar por cada delegado.
		$por_cargo = [];
		foreach ( get_posts( [
			'post_type'   => Cead_Acad_Courses_CPT::POST_TYPE,
			'post_status' => 'publish',
			'numberposts' => -1,
		] ) as $curso ) {
			$uid = (int) get_post_meta( $curso->ID, '_cead_acad_delegate', true );
			if ( $uid && ! isset( $por_cargo[ $uid ] ) ) { $por_cargo[ $uid ] = $curso; }
		}

		$turnos = [ 'manana' => __( 'Mañana', 'cead-acad' ), 'tarde' => __( 'Tarde', 'cead-acad' ), 'noche' => __( 'Noche', 'cead-acad' ) ];
		$out    = [];

		foreach ( $usuarios as $u ) {
			$curso = $por_cargo[ $u->ID ] ?? null;

			if ( ! $curso ) {
				// Sin cargo cargado, el curso en el que está inscripto alcanza para
				// saber a quién representa.
				$ids = self::courses_for_user( $u->ID );
				if ( $ids ) { $curso = get_post( $ids[0] ); }
			}

			$turno = $curso ? (string) get_post_meta( $curso->ID, '_cead_acad_turno', true ) : '';

			$out[] = [
				'user_id'    => (int) $u->ID,
				'nombre'     => $u->display_name,
				'curso_id'   => $curso ? (int) $curso->ID : 0,
				'curso'      => $curso ? $curso->post_title : '',
				'turno'      => $turnos[ $turno ] ?? '',
				'telefono'   => (string) get_user_meta( $u->ID, '_cead_acad_phone', true ),
				'suspendido' => class_exists( 'Cead_Acad_User_Suspension' ) && Cead_Acad_User_Suspension::is_suspended( $u->ID ),
			];
		}

		return $out;
	}
}
