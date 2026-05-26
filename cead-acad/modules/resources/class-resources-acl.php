<?php
/**
 * Helpers de query y verificación de acceso para recursos.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Resources_Acl {

	public static function for_user( $user_id, $args = [] ) {
		$args = wp_parse_args( $args, [
			'per_page' => 20,
			'paged'    => 1,
			'subject'  => '',
			'type'     => '',
			'search'   => '',
		] );

		$ids = Cead_Acad_Audiences::subjects_for_user( 'resource', $user_id );
		if ( ! $ids ) {
			return [];
		}

		$query_args = [
			'post_type'      => Cead_Acad_Resources_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $args['per_page'],
			'paged'          => (int) $args['paged'],
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post__in'       => $ids,
		];
		$tax = [];
		if ( $args['subject'] ) {
			$tax[] = [ 'taxonomy' => Cead_Acad_Resources_CPT::TAX_SUBJECT, 'field' => 'slug', 'terms' => sanitize_title( $args['subject'] ) ];
		}
		if ( $args['type'] ) {
			$tax[] = [ 'taxonomy' => Cead_Acad_Resources_CPT::TAX_TYPE, 'field' => 'slug', 'terms' => sanitize_title( $args['type'] ) ];
		}
		if ( $tax ) {
			$query_args['tax_query'] = $tax;
		}
		if ( $args['search'] ) {
			$query_args['s'] = sanitize_text_field( $args['search'] );
		}

		return get_posts( $query_args );
	}

	public static function user_can_view( $resource_id, $user_id ) {
		return in_array( (int) $resource_id, Cead_Acad_Audiences::subjects_for_user( 'resource', $user_id ), true );
	}
}
