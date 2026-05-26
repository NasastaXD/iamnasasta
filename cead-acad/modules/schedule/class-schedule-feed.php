<?php
/**
 * Query helpers + iCal export para horarios.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Schedule_Feed {

	public function boot() {
		add_action( 'admin_post_cead_acad_event_ical',        [ $this, 'handle_ical_export' ] );
		add_action( 'admin_post_nopriv_cead_acad_event_ical', [ $this, 'handle_ical_export' ] );
	}

	/**
	 * Eventos visibles para el usuario en un rango.
	 *
	 * @return WP_Post[]
	 */
	public static function for_user( $user_id, $from = null, $to = null, $limit = 200 ) {
		$ids = Cead_Acad_Audiences::subjects_for_user( 'event', $user_id );
		if ( ! $ids ) {
			return [];
		}

		$meta_query = [];
		if ( $from ) {
			$meta_query[] = [
				'key'     => '_cead_acad_event_start',
				'value'   => $from,
				'compare' => '>=',
				'type'    => 'DATETIME',
			];
		}
		if ( $to ) {
			$meta_query[] = [
				'key'     => '_cead_acad_event_start',
				'value'   => $to,
				'compare' => '<=',
				'type'    => 'DATETIME',
			];
		}

		$args = [
			'post_type'      => Cead_Acad_Schedule_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'post__in'       => $ids,
			'posts_per_page' => (int) $limit,
			'orderby'        => 'meta_value',
			'meta_key'       => '_cead_acad_event_start',
			'order'          => 'ASC',
		];
		if ( $meta_query ) {
			$args['meta_query'] = $meta_query;
		}
		return get_posts( $args );
	}

	public static function group_by_day( $events ) {
		$out = [];
		foreach ( $events as $e ) {
			$start = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
			$day   = $start ? substr( $start, 0, 10 ) : 'sin-fecha';
			$out[ $day ][] = $e;
		}
		return $out;
	}

	public function handle_ical_export() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Sin acceso.', 'cead-acad' ) );
		}
		$user = wp_get_current_user();
		$events = self::for_user( $user->ID, null, null, 500 );

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="cead-' . sanitize_title( $user->user_login ) . '.ics"' );

		$lines = [];
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//CEAD Academico//ES';
		$lines[] = 'CALSCALE:GREGORIAN';
		foreach ( $events as $e ) {
			$start = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
			$end   = (string) get_post_meta( $e->ID, '_cead_acad_event_end',   true );
			$loc   = (string) get_post_meta( $e->ID, '_cead_acad_event_location', true );
			if ( ! $start ) { continue; }
			$dt_start = self::ical_date( $start );
			$dt_end   = $end ? self::ical_date( $end ) : self::ical_date( $start, '+1 hour' );

			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:cead-event-' . $e->ID . '@' . parse_url( home_url(), PHP_URL_HOST );
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
			$lines[] = 'DTSTART:' . $dt_start;
			$lines[] = 'DTEND:'   . $dt_end;
			$lines[] = 'SUMMARY:' . self::ical_escape( get_the_title( $e ) );
			if ( $loc ) {
				$lines[] = 'LOCATION:' . self::ical_escape( $loc );
			}
			$desc = wp_strip_all_tags( $e->post_content );
			if ( $desc ) {
				$lines[] = 'DESCRIPTION:' . self::ical_escape( $desc );
			}
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';

		echo implode( "\r\n", $lines );
		exit;
	}

	protected static function ical_date( $datetime, $modifier = null ) {
		$ts = strtotime( $datetime );
		if ( $modifier ) {
			$ts = strtotime( $modifier, $ts );
		}
		return gmdate( 'Ymd\THis\Z', $ts );
	}

	protected static function ical_escape( $s ) {
		$s = (string) $s;
		$s = str_replace( [ '\\', "\n", ',', ';' ], [ '\\\\', '\\n', '\\,', '\\;' ], $s );
		return $s;
	}
}
