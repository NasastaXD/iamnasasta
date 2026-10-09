<?php
/**
 * /panel/direccion: métricas de dirección.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! current_user_can( 'cead_acad_view_metrics' ) ) {
	wp_die( esc_html__( 'Esta sección es solo para dirección.', 'cead-acad' ), 403 );
}

// El cálculo vive en Cead_Acad_Metricas: la app muestra los mismos números.
$m = Cead_Acad_Metricas::datos();

$students_count   = $m['personas']['alumnos'];
$delegates_count  = $m['personas']['delegados'];
$teachers_count   = $m['personas']['docentes'];
$active_roster    = $m['personas']['inscripciones_activas'];
$courses_pub      = $m['contenido']['cursos'];
$broadcasts_pub   = $m['contenido']['comunicados'];
$surveys_pub      = $m['contenido']['encuestas'];
$events_pub       = $m['contenido']['eventos'];
$resources_pub    = $m['contenido']['recursos'];
$next_events      = $m['proximos_eventos'];

$latest_read_rate = $m['ultimo_comunicado'] ? [
	'title'      => $m['ultimo_comunicado']['titulo'],
	'recipients' => $m['ultimo_comunicado']['destinatarios'],
	'reads'      => $m['ultimo_comunicado']['lecturas'],
	'rate'       => $m['ultimo_comunicado']['tasa'],
] : null;
$latest_survey_stats = $m['ultima_encuesta'] ? [
	'title'      => $m['ultima_encuesta']['titulo'],
	'recipients' => $m['ultima_encuesta']['destinatarios'],
	'responses'  => $m['ultima_encuesta']['respuestas'],
	'rate'       => $m['ultima_encuesta']['tasa'],
] : null;

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
					$start = $e['inicio'];
					$type  = $e['tipo'];
				?>
					<a class="cead-acad-feed-item is-read" href="<?php echo esc_url( cead_acad_url( 'panel/horarios/' . $e['id'] ) ); ?>">
						<div class="cead-acad-feed-item-meta">
							<span class="cead-acad-eyebrow"><?php echo esc_html( Cead_Acad_Schedule_CPT::type_label( $type ) ); ?> · <?php echo $start ? esc_html( date_i18n( 'j M Y · H:i', strtotime( $start ) ) ) : '—'; ?></span>
						</div>
						<h3 class="cead-acad-feed-item-title"><?php echo esc_html( $e['titulo'] ); ?></h3>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
