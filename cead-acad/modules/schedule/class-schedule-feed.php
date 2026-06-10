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
	public static function for_user( $user_id, $from = null, $to = null, $limit = 200, $exclude_classes = true ) {
		$ids = Cead_Acad_Audiences::subjects_for_user( 'event', $user_id );
		if ( ! $ids ) {
			return [];
		}

		$meta_query = [];

		// El calendario muestra solo eventos; las clases (horario semanal) viven en
		// el curso y se ven en "Horarios".
		if ( $exclude_classes ) {
			$meta_query[] = [
				'relation' => 'OR',
				[ 'key' => '_cead_acad_event_type', 'value' => 'clase', 'compare' => '!=' ],
				[ 'key' => '_cead_acad_event_type', 'compare' => 'NOT EXISTS' ],
			];
		}
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
			'no_found_rows'  => true,
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
		$user   = wp_get_current_user();
		$events = self::for_user( $user->ID, null, null, 500 );

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="cead-' . sanitize_title( $user->user_login ) . '.ics"' );

		echo self::build_ical( $events ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/** Feed iCal suscribible (sin login) validado por token firmado. */
	public static function output_subscription( $token ) {
		$uid = class_exists( 'Cead_Acad_Account' ) ? Cead_Acad_Account::verify_feed_token( $token ) : 0;
		if ( ! $uid ) {
			status_header( 403 );
			exit;
		}
		$events = self::for_user( $uid, null, null, 500 );

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=UTF-8' );

		echo self::build_ical( $events ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/** Construye el cuerpo iCal a partir de una lista de eventos. */
	public static function build_ical( $events ) {
		$lines   = [];
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//CEAD Academico//ES';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'METHOD:PUBLISH';
		$lines[] = 'X-WR-CALNAME:' . self::ical_escape( get_bloginfo( 'name' ) . ' · CEAD' );
		$lines[] = 'X-WR-TIMEZONE:' . self::ical_escape( wp_timezone_string() );
		// Hints de refresco para clientes que suscriben (Google/Apple/Outlook).
		$lines[] = 'X-PUBLISHED-TTL:PT1H';
		$lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT1H';
		foreach ( $events as $e ) {
			$start = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
			$end   = (string) get_post_meta( $e->ID, '_cead_acad_event_end',   true );
			$loc   = (string) get_post_meta( $e->ID, '_cead_acad_event_location', true );
			if ( ! $start ) { continue; }
			$dt_start = self::ical_date( $start );
			$dt_end   = $end ? self::ical_date( $end ) : self::ical_date( $start, '+1 hour' );

			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:cead-event-' . $e->ID . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
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

		// Folding RFC 5545: líneas de máximo 75 octetos, continuación con espacio.
		$folded = array_map( [ __CLASS__, 'ical_fold' ], $lines );

		return implode( "\r\n", $folded );
	}

	/**
	 * Convierte el datetime local del sitio a UTC para iCal. Los eventos se
	 * guardan en hora local de WordPress; interpretar con strtotime() usaría
	 * la TZ de PHP (normalmente UTC en el server) y correría los horarios.
	 */
	protected static function ical_date( $datetime, $modifier = null ) {
		try {
			$dt = new DateTimeImmutable( $datetime, wp_timezone() );
		} catch ( Exception $e ) {
			return gmdate( 'Ymd\THis\Z' );
		}
		if ( $modifier ) {
			$dt = $dt->modify( $modifier );
		}
		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	}

	/** Pliega una línea iCal a 75 octetos (RFC 5545 §3.1). */
	protected static function ical_fold( $line ) {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out   = '';
		$first = true;
		while ( $line !== '' ) {
			$max = $first ? 75 : 74;
			// No cortar en medio de un carácter UTF-8 multibyte.
			$chunk = substr( $line, 0, $max );
			while ( strlen( $chunk ) > 1 && ( ord( substr( $line, strlen( $chunk ), 1 ) ?: ' ' ) & 0xC0 ) === 0x80 ) {
				$chunk = substr( $chunk, 0, -1 );
			}
			// Guard anti-loop: ante bytes inválidos (no UTF-8), nunca dejar $chunk
			// vacío o $line no avanzaría. Forzamos al menos 1 byte.
			if ( $chunk === '' ) {
				$chunk = substr( $line, 0, 1 );
			}
			$out  .= ( $first ? '' : "\r\n " ) . $chunk;
			$line  = substr( $line, strlen( $chunk ) );
			$first = false;
		}
		return $out;
	}

	protected static function ical_escape( $s ) {
		$s = (string) $s;
		$s = str_replace( [ '\\', "\n", ',', ';' ], [ '\\\\', '\\n', '\\,', '\\;' ], $s );
		return $s;
	}
}
