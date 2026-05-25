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
		foreach ( $rows as $r ) {
			switch ( $r['audience_type'] ) {
				case 'all':
					$users = get_users( [ 'fields' => [ 'ID' ] ] );
					foreach ( $users as $u ) { $ids[] = (int) $u->ID; }
					break;
				case 'role':
					$users = get_users( [ 'role' => $r['audience_value'], 'fields' => [ 'ID' ] ] );
					foreach ( $users as $u ) { $ids[] = (int) $u->ID; }
					break;
				case 'course':
					foreach ( Cead_Acad_Courses_Roster::users_in_course( (int) $r['audience_value'] ) as $uid ) {
						$ids[] = (int) $uid;
					}
					break;
				case 'cohort':
					$course_ids = get_posts( [
						'post_type'      => Cead_Acad_Courses_CPT::POST_TYPE,
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'tax_query'      => [ [
							'taxonomy' => Cead_Acad_Courses_CPT::TAX_COHORT,
							'field'    => 'term_id',
							'terms'    => (int) $r['audience_value'],
						] ],
					] );
					foreach ( $course_ids as $cid ) {
						foreach ( Cead_Acad_Courses_Roster::users_in_course( (int) $cid ) as $uid ) {
							$ids[] = (int) $uid;
						}
					}
					break;
				case 'user':
					$ids[] = (int) $r['audience_value'];
					break;
			}
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
