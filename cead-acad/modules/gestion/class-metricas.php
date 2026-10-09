<?php
/**
 * Los números de Dirección.
 *
 * Se calculaban dentro de la plantilla de /panel/direccion, y el bot tenía su
 * propia versión, más corta. La app necesita los mismos que el panel, así que
 * el cálculo vive acá y la plantilla y la API leen de lo mismo.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Metricas {

	/** @return array */
	public static function datos() {
		global $wpdb;

		$usuarios = count_users();
		$por_rol  = isset( $usuarios['avail_roles'] ) && is_array( $usuarios['avail_roles'] ) ? $usuarios['avail_roles'] : [];
		$roster   = cead_acad_table( 'roster' );

		$publicados = static function ( $tipo ) {
			return (int) ( wp_count_posts( $tipo )->publish ?? 0 );
		};

		return [
			'personas'          => [
				'alumnos'               => (int) ( $por_rol['cead_acad_student'] ?? 0 ),
				'delegados'             => (int) ( $por_rol['cead_acad_delegate'] ?? 0 ),
				'docentes'              => (int) ( $por_rol['cead_acad_teacher'] ?? 0 ),
				'inscripciones_activas' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$roster} WHERE status = 'active'" ), // phpcs:ignore WordPress.DB
			],
			'contenido'         => [
				'cursos'      => $publicados( Cead_Acad_Courses_CPT::POST_TYPE ),
				'comunicados' => $publicados( Cead_Acad_Broadcasts_CPT::POST_TYPE ),
				'encuestas'   => $publicados( Cead_Acad_Surveys_CPT::POST_TYPE ),
				'eventos'     => $publicados( Cead_Acad_Schedule_CPT::POST_TYPE ),
				'recursos'    => $publicados( Cead_Acad_Resources_CPT::POST_TYPE ),
			],
			'ultimo_comunicado' => self::ultimo_comunicado(),
			'ultima_encuesta'   => self::ultima_encuesta(),
			'proximos_eventos'  => self::proximos_eventos(),
		];
	}

	/** Cuántos de los destinatarios del último comunicado lo leyeron. */
	protected static function ultimo_comunicado() {
		global $wpdb;
		$ultimo = get_posts( [
			'post_type'      => Cead_Acad_Broadcasts_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
		] );
		if ( ! $ultimo ) {
			return null;
		}
		$b        = $ultimo[0];
		$reads    = cead_acad_table( 'broadcast_reads' );
		$total    = count( Cead_Acad_Broadcasts_Feed::resolve_recipient_user_ids( $b->ID ) );
		$lecturas = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reads} WHERE broadcast_id = %d", $b->ID ) ); // phpcs:ignore WordPress.DB
		return [
			'id'            => (int) $b->ID,
			'titulo'        => get_the_title( $b ),
			'destinatarios' => $total,
			'lecturas'      => $lecturas,
			'tasa'          => self::tasa( $lecturas, $total ),
		];
	}

	/** Cuántos de los destinatarios de la última encuesta la respondieron. */
	protected static function ultima_encuesta() {
		global $wpdb;
		$ultima = get_posts( [
			'post_type'      => Cead_Acad_Surveys_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
		] );
		if ( ! $ultima ) {
			return null;
		}
		$s          = $ultima[0];
		$tabla      = cead_acad_table( 'survey_responses' );
		$total      = count( Cead_Acad_Broadcasts_Feed::resolve_recipient_user_ids( $s->ID, 'survey' ) );
		$respuestas = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tabla} WHERE survey_id = %d", $s->ID ) ); // phpcs:ignore WordPress.DB
		return [
			'id'            => (int) $s->ID,
			'titulo'        => get_the_title( $s ),
			'destinatarios' => $total,
			'respuestas'    => $respuestas,
			'tasa'          => self::tasa( $respuestas, $total ),
		];
	}

	protected static function proximos_eventos() {
		$eventos = get_posts( [
			'post_type'      => Cead_Acad_Schedule_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'meta_key'       => '_cead_acad_event_start',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [
				// `_cead_acad_event_start` se guarda en hora LOCAL (viene de un
				// <input type="datetime-local">), así que el corte también va en local.
				// Con `current_time( 'mysql', 1 )` —que es GMT— en Paraguay (UTC-3) se
				// comparaba contra tres horas en el futuro: los eventos de esta mañana
				// desaparecían de «Próximos eventos» estando todavía por empezar.
				[ 'key' => '_cead_acad_event_start', 'value' => current_time( 'mysql' ), 'compare' => '>=', 'type' => 'DATETIME' ],
			],
		] );
		return array_map( static function ( $e ) {
			return [
				'id'     => (int) $e->ID,
				'titulo' => get_the_title( $e ),
				'inicio' => Cead_Acad_Schedule_CPT::fecha_canonica( get_post_meta( $e->ID, '_cead_acad_event_start', true ) ),
				'tipo'   => (string) get_post_meta( $e->ID, '_cead_acad_event_type', true ) ?: 'evento',
			];
		}, $eventos );
	}

	/** Porcentaje entero; 0 si no había a quién. */
	public static function tasa( $hechos, $total ) {
		return $total > 0 ? (int) round( $hechos / $total * 100 ) : 0;
	}
}
