<?php
/**
 * /panel/direccion: métricas de dirección.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! current_user_can( 'cead_acad_view_metrics' ) ) {
	wp_die( esc_html__( 'Esta sección es solo para dirección.', 'cead-acad' ), 403 );
}

global $wpdb;

$roster_table     = cead_acad_table( 'roster' );
$audiences_table  = cead_acad_table( 'audiences' );
$reads_table      = cead_acad_table( 'broadcast_reads' );
$responses_table  = cead_acad_table( 'survey_responses' );
$grades_table     = cead_acad_table( 'grades' );

$users_total      = count_users();
$students_count   = (int) ( $users_total['avail_roles']['cead_acad_student'] ?? 0 );
$delegates_count  = (int) ( $users_total['avail_roles']['cead_acad_delegate'] ?? 0 );
$teachers_count   = (int) ( $users_total['avail_roles']['cead_acad_teacher'] ?? 0 );

$active_roster    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$roster_table} WHERE status = 'active'" );
$courses          = wp_count_posts( Cead_Acad_Courses_CPT::POST_TYPE );
$courses_pub      = (int) ( $courses->publish ?? 0 );

$broadcasts_pub   = (int) ( wp_count_posts( Cead_Acad_Broadcasts_CPT::POST_TYPE )->publish ?? 0 );
$surveys_pub      = (int) ( wp_count_posts( Cead_Acad_Surveys_CPT::POST_TYPE )->publish ?? 0 );
$events_pub       = (int) ( wp_count_posts( Cead_Acad_Schedule_CPT::POST_TYPE )->publish ?? 0 );
$resources_pub    = (int) ( wp_count_posts( Cead_Acad_Resources_CPT::POST_TYPE )->publish ?? 0 );

// Próximos eventos.
$next_events = get_posts( [
	'post_type'      => Cead_Acad_Schedule_CPT::POST_TYPE,
	'post_status'    => 'publish',
	'posts_per_page' => 5,
	'meta_key'       => '_cead_acad_event_start',
	'orderby'        => 'meta_value',
	'order'          => 'ASC',
	'meta_query'     => [
		[ 'key' => '_cead_acad_event_start', 'value' => current_time( 'mysql', 1 ), 'compare' => '>=' ],
	],
] );

// Tasa de lectura del último comunicado publicado.
$latest_broadcast = get_posts( [
	'post_type'      => Cead_Acad_Broadcasts_CPT::POST_TYPE,
	'post_status'    => 'publish',
	'posts_per_page' => 1,
] );
$latest_read_rate = null;
if ( $latest_broadcast ) {
	$b   = $latest_broadcast[0];
	$recipients = Cead_Acad_Broadcasts_Feed::resolve_recipient_user_ids( $b->ID );
	$reads_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reads_table} WHERE broadcast_id = %d", $b->ID ) );
	$latest_read_rate = [
		'title'    => get_the_title( $b ),
		'recipients' => count( $recipients ),
		'reads'    => $reads_count,
		'rate'     => count( $recipients ) > 0 ? round( $reads_count / max( 1, count( $recipients ) ) * 100 ) : 0,
	];
}

// Tasa de respuesta de la última encuesta nominal.
$latest_survey = get_posts( [
	'post_type'      => Cead_Acad_Surveys_CPT::POST_TYPE,
	'post_status'    => 'publish',
	'posts_per_page' => 1,
] );
$latest_survey_stats = null;
if ( $latest_survey ) {
	$s = $latest_survey[0];
	// Resolver audiencias del subject_type='survey' (resolve_recipient_user_ids vive en Broadcasts y asume 'broadcast').
	$rows = Cead_Acad_Audiences::get( 'survey', $s->ID );
	$audience_users = [];
	foreach ( $rows as $r ) {
		switch ( $r['audience_type'] ) {
			case 'all':
				foreach ( get_users( [ 'fields' => [ 'ID' ] ] ) as $u ) { $audience_users[] = (int) $u->ID; }
				break;
			case 'role':
				foreach ( get_users( [ 'role' => $r['audience_value'], 'fields' => [ 'ID' ] ] ) as $u ) { $audience_users[] = (int) $u->ID; }
				break;
			case 'course':
				foreach ( Cead_Acad_Courses_Roster::users_in_course( (int) $r['audience_value'] ) as $uid ) { $audience_users[] = (int) $uid; }
				break;
			case 'user':
				$audience_users[] = (int) $r['audience_value'];
				break;
		}
	}
	$audience_users = array_unique( $audience_users );

	$responses_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$responses_table} WHERE survey_id = %d",
		$s->ID
	) );

	$latest_survey_stats = [
		'title'      => get_the_title( $s ),
		'recipients' => count( $audience_users ),
		'responses'  => $responses_count,
		'rate'       => count( $audience_users ) > 0 ? round( $responses_count / max( 1, count( $audience_users ) ) * 100 ) : 0,
	];
}

$page_title = __( 'Dirección', 'cead-acad' );

$body = function () use ( $students_count, $delegates_count, $teachers_count, $active_roster, $courses_pub, $broadcasts_pub, $surveys_pub, $events_pub, $resources_pub, $latest_read_rate, $latest_survey_stats, $next_events ) {
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Métricas generales', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Dirección', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Estado actual del CEAD digital: comunidad, actividad y compromiso.', 'cead-acad' ); ?></p>

		<div class="cead-acad-metrics">
			<div class="cead-acad-metric"><span class="cead-acad-metric-num"><?php echo (int) $students_count; ?></span><span class="cead-acad-metric-label"><?php esc_html_e( 'Alumnos/as', 'cead-acad' ); ?></span></div>
			<div class="cead-acad-metric"><span class="cead-acad-metric-num"><?php echo (int) $delegates_count; ?></span><span class="cead-acad-metric-label"><?php esc_html_e( 'Delegados/as', 'cead-acad' ); ?></span></div>
			<div class="cead-acad-metric"><span class="cead-acad-metric-num"><?php echo (int) $teachers_count; ?></span><span class="cead-acad-metric-label"><?php esc_html_e( 'Docentes', 'cead-acad' ); ?></span></div>
			<div class="cead-acad-metric"><span class="cead-acad-metric-num"><?php echo (int) $courses_pub; ?></span><span class="cead-acad-metric-label"><?php esc_html_e( 'Cursos', 'cead-acad' ); ?></span></div>
			<div class="cead-acad-metric"><span class="cead-acad-metric-num"><?php echo (int) $active_roster; ?></span><span class="cead-acad-metric-label"><?php esc_html_e( 'Inscripciones activas', 'cead-acad' ); ?></span></div>
		</div>

		<h3 class="cead-acad-section-h"><?php esc_html_e( 'Contenido publicado', 'cead-acad' ); ?></h3>
		<div class="cead-acad-metrics">
			<div class="cead-acad-metric cead-acad-metric--blue"><span class="cead-acad-metric-num"><?php echo (int) $broadcasts_pub; ?></span><span class="cead-acad-metric-label"><?php esc_html_e( 'Comunicados', 'cead-acad' ); ?></span></div>
			<div class="cead-acad-metric cead-acad-metric--yellow"><span class="cead-acad-metric-num"><?php echo (int) $surveys_pub; ?></span><span class="cead-acad-metric-label"><?php esc_html_e( 'Encuestas', 'cead-acad' ); ?></span></div>
			<div class="cead-acad-metric cead-acad-metric--orange"><span class="cead-acad-metric-num"><?php echo (int) $events_pub; ?></span><span class="cead-acad-metric-label"><?php esc_html_e( 'Eventos', 'cead-acad' ); ?></span></div>
			<div class="cead-acad-metric"><span class="cead-acad-metric-num"><?php echo (int) $resources_pub; ?></span><span class="cead-acad-metric-label"><?php esc_html_e( 'Recursos', 'cead-acad' ); ?></span></div>
		</div>

		<h3 class="cead-acad-section-h"><?php esc_html_e( 'Compromiso (engagement)', 'cead-acad' ); ?></h3>
		<div class="cead-acad-grid cead-acad-grid--2">
			<?php if ( $latest_read_rate ) : ?>
				<div class="cead-acad-card">
					<span class="cead-acad-eyebrow"><?php esc_html_e( 'Último comunicado · Tasa de lectura', 'cead-acad' ); ?></span>
					<h3><?php echo (int) $latest_read_rate['rate']; ?>%</h3>
					<p>
						<strong><?php echo esc_html( $latest_read_rate['title'] ); ?></strong><br>
						<?php
						printf(
							/* translators: 1: cantidad de lecturas, 2: cantidad de destinatarios */
							esc_html__( '%1$d de %2$d destinatarios leyeron el comunicado.', 'cead-acad' ),
							(int) $latest_read_rate['reads'],
							(int) $latest_read_rate['recipients']
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $latest_survey_stats ) : ?>
				<div class="cead-acad-card">
					<span class="cead-acad-eyebrow"><?php esc_html_e( 'Última encuesta · Tasa de respuesta', 'cead-acad' ); ?></span>
					<h3><?php echo (int) $latest_survey_stats['rate']; ?>%</h3>
					<p>
						<strong><?php echo esc_html( $latest_survey_stats['title'] ); ?></strong><br>
						<?php
						printf(
							/* translators: 1: cantidad de respuestas, 2: cantidad de destinatarios */
							esc_html__( '%1$d respuestas de %2$d destinatarios.', 'cead-acad' ),
							(int) $latest_survey_stats['responses'],
							(int) $latest_survey_stats['recipients']
						);
						?>
					</p>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $next_events ) : ?>
			<h3 class="cead-acad-section-h"><?php esc_html_e( 'Próximos eventos', 'cead-acad' ); ?></h3>
			<div class="cead-acad-feed">
				<?php foreach ( $next_events as $e ) :
					$start = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
					$type  = (string) get_post_meta( $e->ID, '_cead_acad_event_type',  true ) ?: 'evento';
				?>
					<a class="cead-acad-feed-item is-read" href="<?php echo esc_url( cead_acad_url( 'panel/horarios/' . $e->ID ) ); ?>">
						<div class="cead-acad-feed-item-meta">
							<span class="cead-acad-eyebrow"><?php echo esc_html( Cead_Acad_Schedule_CPT::type_label( $type ) ); ?> · <?php echo $start ? esc_html( date_i18n( 'j M Y · H:i', strtotime( $start ) ) ) : '—'; ?></span>
						</div>
						<h3 class="cead-acad-feed-item-title"><?php echo esc_html( get_the_title( $e ) ); ?></h3>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
