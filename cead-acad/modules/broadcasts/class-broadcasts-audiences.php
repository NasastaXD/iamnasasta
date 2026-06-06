<?php
/**
 * Tabla genérica de audiencias polimórfica.
 * subject_type ∈ {broadcast, survey, event}
 * audience_type ∈ {all, role, course, cohort, user}
 *
 * Diseñada en F1 para reuso en F2 (encuestas) y F3 (eventos).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Audiences {

	const TYPE_ALL    = 'all';
	const TYPE_ROLE   = 'role';
	const TYPE_COURSE = 'course';
	const TYPE_COHORT = 'cohort';
	const TYPE_USER   = 'user';

	/**
	 * Reemplaza por completo las audiencias de un sujeto.
	 *
	 * @param string                                $subject_type
	 * @param int                                   $subject_id
	 * @param array<int,array{type:string,value:string}> $audiences
	 */
	public static function set( $subject_type, $subject_id, $audiences ) {
		global $wpdb;
		$table = cead_acad_table( 'audiences' );

		$wpdb->delete( $table, [ 'subject_type' => $subject_type, 'subject_id' => (int) $subject_id ], [ '%s', '%d' ] );

		if ( ! $audiences ) {
			return;
		}
		$now = current_time( 'mysql', 1 );
		foreach ( $audiences as $a ) {
			$type  = self::sanitize_type( $a['type'] ?? '' );
			$value = isset( $a['value'] ) ? sanitize_text_field( (string) $a['value'] ) : '';
			if ( ! $type ) {
				continue;
			}
			if ( self::TYPE_ALL === $type ) {
				$value = '*';
			}
			$wpdb->insert(
				$table,
				[
					'subject_type'   => $subject_type,
					'subject_id'     => (int) $subject_id,
					'audience_type'  => $type,
					'audience_value' => $value,
					'created_at'     => $now,
				],
				[ '%s', '%d', '%s', '%s', '%s' ]
			);
		}
	}

	public static function get( $subject_type, $subject_id ) {
		global $wpdb;
		$table = cead_acad_table( 'audiences' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT audience_type, audience_value FROM {$table} WHERE subject_type = %s AND subject_id = %d",
			$subject_type, (int) $subject_id
		), ARRAY_A ) ?: [];
	}

	/**
	 * Devuelve IDs de sujetos visibles para un usuario.
	 * Memoizado por (subject_type, user_id) en el lifecycle del request.
	 *
	 * @return int[]
	 */
	public static function subjects_for_user( $subject_type, $user_id ) {
		static $cache = [];
		$key = $subject_type . '|' . (int) $user_id;
		if ( array_key_exists( $key, $cache ) ) {
			return $cache[ $key ];
		}

		global $wpdb;
		$table = cead_acad_table( 'audiences' );

		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user ) {
			return $cache[ $key ] = [];
		}

		$role_slugs  = array_values( (array) $user->roles );
		$course_ids  = Cead_Acad_Courses_Roster::courses_for_user( $user->ID );
		$cohort_ids  = [];
		if ( $course_ids ) {
			$terms = wp_get_object_terms( $course_ids, Cead_Acad_Courses_CPT::TAX_COHORT, [ 'fields' => 'ids' ] );
			$cohort_ids = is_wp_error( $terms ) ? [] : array_map( 'intval', $terms );
		}

		$conditions = [];
		$args       = [ $subject_type ];

		$conditions[] = "(audience_type = 'all')";

		if ( $role_slugs ) {
			$ph = implode( ',', array_fill( 0, count( $role_slugs ), '%s' ) );
			$conditions[] = "(audience_type = 'role' AND audience_value IN ($ph))";
			array_push( $args, ...$role_slugs );
		}
		if ( $course_ids ) {
			$ph = implode( ',', array_fill( 0, count( $course_ids ), '%s' ) );
			$conditions[] = "(audience_type = 'course' AND audience_value IN ($ph))";
			array_push( $args, ...array_map( 'strval', $course_ids ) );
		}
		if ( $cohort_ids ) {
			$ph = implode( ',', array_fill( 0, count( $cohort_ids ), '%s' ) );
			$conditions[] = "(audience_type = 'cohort' AND audience_value IN ($ph))";
			array_push( $args, ...array_map( 'strval', $cohort_ids ) );
		}
		$conditions[] = "(audience_type = 'user' AND audience_value = %s)";
		$args[] = (string) $user->ID;

		$where = '(' . implode( ' OR ', $conditions ) . ')';
		$sql = "SELECT DISTINCT subject_id FROM {$table} WHERE subject_type = %s AND {$where}";

		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) );
		return $cache[ $key ] = array_map( 'intval', $ids ?: [] );
	}

	public static function sanitize_type( $type ) {
		$valid = [ self::TYPE_ALL, self::TYPE_ROLE, self::TYPE_COURSE, self::TYPE_COHORT, self::TYPE_USER ];
		return in_array( $type, $valid, true ) ? $type : '';
	}

	/**
	 * Texto legible de una audiencia (para resumen en cards).
	 */
	public static function describe( $rows ) {
		if ( ! $rows ) {
			return __( '— sin destinatarios —', 'cead-acad' );
		}
		$parts = [];
		foreach ( $rows as $r ) {
			switch ( $r['audience_type'] ) {
				case self::TYPE_ALL:
					return __( 'Todos los usuarios', 'cead-acad' );
				case self::TYPE_ROLE:
					$roles = Cead_Acad_Capabilities::roles();
					$parts[] = $roles[ $r['audience_value'] ]['display'] ?? $r['audience_value'];
					break;
				case self::TYPE_COURSE:
					$p = get_post( (int) $r['audience_value'] );
					$parts[] = $p ? $p->post_title : '#' . $r['audience_value'];
					break;
				case self::TYPE_COHORT:
					$t = get_term( (int) $r['audience_value'], Cead_Acad_Courses_CPT::TAX_COHORT );
					$parts[] = ( $t && ! is_wp_error( $t ) ) ? $t->name : '#' . $r['audience_value'];
					break;
				case self::TYPE_USER:
					$u = get_user_by( 'id', (int) $r['audience_value'] );
					$parts[] = $u ? $u->display_name : '#' . $r['audience_value'];
					break;
			}
		}
		return implode( ' · ', $parts );
	}
}
