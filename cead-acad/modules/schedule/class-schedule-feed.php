<?php
/**
 * Query helpers + iCal export para horarios.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Schedule_Feed {

	/**
	 * Tope de días que puede ocupar un solo evento en la grilla.
	 *
	 * Una fecha de fin mal tipeada —el año equivocado, que en un importador de
	 * planilla pasa— no puede colgar la página rellenando celdas para siempre.
	 */
	const TOPE_DIAS = 366;

	public function boot() {
		add_action( 'admin_post_cead_acad_event_ical',        [ $this, 'handle_ical_export' ] );
		add_action( 'admin_post_nopriv_cead_acad_event_ical', [ $this, 'handle_ical_export' ] );
	}

	/**
	 * Eventos visibles para el usuario en un rango.
	 *
	 * @return WP_Post[]
	 */
	public static function for_user( $user_id, $from = null, $to = null, $limit = 200, $exclude_classes = true, $overlap = false ) {
		$ids = Cead_Acad_Audiences::subjects_for_user( 'event', $user_id );
		if ( ! $ids ) {
			return [];
		}
		return self::query( $ids, $from, $to, $limit, $exclude_classes, $overlap );
	}

	/**
	 * Eventos institucionales: audiencia "todos" únicamente (feriados, actos,
	 * períodos de cierre) — nunca uno dirigido a un curso, rol o persona en
	 * particular. Es la lista que se puede mostrar en una página PÚBLICA, sin
	 * login: no depende de quién esté mirando.
	 *
	 * @return WP_Post[]
	 */
	public static function public_events( $from = null, $to = null, $limit = 200, $exclude_classes = true, $overlap = false ) {
		global $wpdb;
		$table = cead_acad_table( 'audiences' );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT subject_id FROM {$table} WHERE subject_type = %s AND audience_type = 'all'",
			'event'
		) );
		$ids = array_map( 'intval', $ids ?: [] );
		if ( ! $ids ) {
			return [];
		}
		return self::query( $ids, $from, $to, $limit, $exclude_classes, $overlap );
	}

	/** Consulta compartida por for_user() y public_events(): mismo filtro de fecha/tipo, distinta lista de IDs. */
	protected static function query( $ids, $from, $to, $limit, $exclude_classes, $overlap = false ) {
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
		/*
		 * Dos formas de entender "los eventos de este mes".
		 *
		 * La de siempre mira la fecha de INICIO, y para una lista de próximas
		 * fechas es la correcta. Pero para dibujar una grilla deja afuera lo que
		 * más importa ver: las vacaciones que empezaron el 6 de julio y terminan
		 * el 24 no aparecían en el mes de julio si la grilla arrancaba después,
		 * ni asomaban en agosto aunque siguieran corriendo. Un período largo se
		 * volvía invisible justo en los días en que está pasando.
		 *
		 * Con `$overlap` la condición pasa a ser "se cruza con el rango": empieza
		 * antes de que termine el rango, y termina después de que el rango empieza.
		 * La segunda rama del OR cubre a los eventos sin fecha de fin —o con una
		 * vacía, que al comparar como DATETIME da 0000-00-00—, que se filtran por
		 * su inicio como antes.
		 */
		if ( $overlap && $from && $to ) {
			$meta_query[] = [
				'key'     => '_cead_acad_event_start',
				'value'   => $to,
				'compare' => '<=',
				'type'    => 'DATETIME',
			];
			$meta_query[] = [
				'relation' => 'OR',
				[ 'key' => '_cead_acad_event_end',   'value' => $from, 'compare' => '>=', 'type' => 'DATETIME' ],
				[ 'key' => '_cead_acad_event_start', 'value' => $from, 'compare' => '>=', 'type' => 'DATETIME' ],
			];
		} else {
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

	/**
	 * Reparte los eventos por día, marcando dónde EMPIEZA y dónde TERMINA cada uno.
	 *
	 * Un evento aparece en todos los días que abarca, no solo en el de inicio.
	 * Pero saber además si ese día es el primero, el último o uno del medio es
	 * lo que permite dibujar un período como una BANDA continua —una barra que
	 * cruza la semana— en vez de repetir la misma etiqueta siete veces. Es la
	 * diferencia entre ver "del 3 al 18 hay exámenes" y ver dieciséis rectángulos
	 * sueltos que dicen lo mismo.
	 *
	 * Vive acá y no en cada plantilla porque el calendario del panel y el público
	 * tienen que repartir los días igual: si divergen, un mismo evento se ve de
	 * dos formas distintas según dónde lo mires.
	 *
	 * @param WP_Post[] $events
	 * @param int       $desde_ts Recorte opcional: no emitir días anteriores a este.
	 * @param int       $hasta_ts Recorte opcional: no emitir días posteriores a este.
	 * @return array<string,array<int,array<string,mixed>>> 'Y-m-d' => filas
	 */
	public static function expand_by_day( $events, $desde_ts = 0, $hasta_ts = 0 ) {
		$out = [];

		foreach ( $events as $e ) {
			$st = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
			if ( ! $st ) { continue; }
			$start_ts = strtotime( $st );
			if ( ! $start_ts ) { continue; }

			$en     = (string) get_post_meta( $e->ID, '_cead_acad_event_end', true );
			$end_ts = $en ? strtotime( $en ) : $start_ts;
			if ( ! $end_ts || $end_ts < $start_ts ) { $end_ts = $start_ts; }

			$primero = strtotime( date( 'Y-m-d', $start_ts ) );
			$ultimo  = min( strtotime( date( 'Y-m-d', $end_ts ) ), $primero + self::TOPE_DIAS * DAY_IN_SECONDS );
			$span    = $ultimo > $primero;

			// Recorte a la ventana visible: un período de tres meses no tiene por
			// qué generar noventa filas para dibujar cinco semanas.
			$day_ts = $desde_ts ? max( $primero, strtotime( date( 'Y-m-d', $desde_ts ) ) ) : $primero;
			$fin_ts = $hasta_ts ? min( $ultimo,  strtotime( date( 'Y-m-d', $hasta_ts ) ) ) : $ultimo;

			while ( $day_ts <= $fin_ts ) {
				$out[ date( 'Y-m-d', $day_ts ) ][] = [
					'post' => $e,
					'ini'  => $day_ts === $primero,
					'fin'  => $day_ts === $ultimo,
					'span' => $span,
					// Solo para ordenar: un período no tiene hora que valga.
					'hora' => $span ? '' : substr( $st, 11, 5 ),
				];
				$day_ts += DAY_IN_SECONDS;
			}
		}

		/*
		 * Los períodos arriba y el resto por hora.
		 *
		 * No es cosmético: si un período de una semana quedara debajo de una
		 * charla de las 9 solo los días que hay charla, la banda cambiaría de
		 * altura de una celda a la otra y dejaría de leerse como una sola cosa.
		 * Arriba de todo, se mantiene alineada de lunes a domingo.
		 */
		foreach ( $out as $day => $filas ) {
			usort( $filas, static function ( $a, $b ) {
				if ( $a['span'] !== $b['span'] ) { return $a['span'] ? -1 : 1; }
				return strcmp( (string) $a['hora'], (string) $b['hora'] );
			} );
			$out[ $day ] = $filas;
		}

		return $out;
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
