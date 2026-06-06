<?php
/**
 * Query del feed de comunicados por usuario y resolución de destinatarios.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Broadcasts_Feed {

	public function boot() {
		// Wiring intencionalmente vacío; los templates usan los métodos estáticos.
	}

	/**
	 * IDs de comunicados visibles para un usuario, ordenados por fecha desc.
	 *
	 * @return WP_Post[]
	 */
	public static function for_user( $user_id, $args = [] ) {
		$args = wp_parse_args( $args, [
			'per_page' => 20,
			'paged'    => 1,
			'category' => '',
		] );

		$ids = Cead_Acad_Audiences::subjects_for_user( 'broadcast', $user_id );
		if ( ! $ids ) {
			return [];
		}

		$query_args = [
			'post_type'      => Cead_Acad_Broadcasts_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $args['per_page'],
			'paged'          => (int) $args['paged'],
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post__in'       => $ids,
			'ignore_sticky_posts' => true,
			'no_found_rows'  => true,
		];
		if ( $args['category'] ) {
			$query_args['tax_query'] = [ [
				'taxonomy' => Cead_Acad_Broadcasts_CPT::TAX_CAT,
				'field'    => 'slug',
				'terms'    => sanitize_title( $args['category'] ),
			] ];
		}
		return get_posts( $query_args );
	}

	/**
	 * Resuelve los user_ids que pueden ver un comunicado (para notificaciones).
	 *
	 * @return int[]
	 */
	public static function resolve_recipient_user_ids( $broadcast_id ) {
		$rows = Cead_Acad_Audiences::get( 'broadcast', $broadcast_id );
		if ( ! $rows ) {
			return [];
		}
		$ids = [];
		// Si hay 'all' en cualquier row, eso ya domina al resto: una sola query.
		foreach ( $rows as $r ) {
			if ( 'all' === $r['audience_type'] ) {
				return array_map( 'intval', get_users( [ 'fields' => 'ID' ] ) );
			}
		}

		// Para cohortes y cursos: usar 1 query directa al roster con IN (...)
		// en vez de N llamadas a users_in_course().
		$cohort_terms = [];
		$course_ids   = [];
		$role_slugs   = [];
		foreach ( $rows as $r ) {
			switch ( $r['audience_type'] ) {
				case 'role':
					$role_slugs[] = $r['audience_value'];
					break;
				case 'course':
					$course_ids[] = (int) $r['audience_value'];
					break;
				case 'cohort':
					$cohort_terms[] = (int) $r['audience_value'];
					break;
				case 'user':
					$ids[] = (int) $r['audience_value'];
					break;
			}
		}

		if ( $role_slugs ) {
			$user_ids = get_users( [ 'role__in' => array_values( array_unique( $role_slugs ) ), 'fields' => 'ID' ] );
			foreach ( $user_ids as $uid ) { $ids[] = (int) $uid; }
		}

		// Cohort → expandir a course_ids con un solo WP_Query.
		if ( $cohort_terms ) {
			$cohort_terms = array_values( array_unique( $cohort_terms ) );
			$expanded = get_posts( [
				'post_type'      => Cead_Acad_Courses_CPT::POST_TYPE,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => [ [
					'taxonomy' => Cead_Acad_Courses_CPT::TAX_COHORT,
					'field'    => 'term_id',
					'terms'    => $cohort_terms,
				] ],
			] );
			foreach ( $expanded as $cid ) { $course_ids[] = (int) $cid; }
		}

		// Cursos + cohortes → 1 query al roster.
		if ( $course_ids ) {
			global $wpdb;
			$roster = cead_acad_table( 'roster' );
			$course_ids = array_values( array_unique( $course_ids ) );
			$ph = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
			$roster_users = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$roster} WHERE status = 'active' AND course_id IN ({$ph})",
				...$course_ids
			) );
			foreach ( $roster_users as $uid ) { $ids[] = (int) $uid; }
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Verifica si un user puede ver un broadcast específico.
	 */
	public static function user_can_view( $broadcast_id, $user_id ) {
		$ids = Cead_Acad_Audiences::subjects_for_user( 'broadcast', $user_id );
		return in_array( (int) $broadcast_id, $ids, true );
	}
}
