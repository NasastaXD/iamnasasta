<?php
/**
 * Template Name: Calendario institucional (público)
 * Template Post Type: page
 *
 * El calendario institucional, público y sin login: feriados, actos y
 * períodos de cierre (vacaciones, exámenes) — nunca algo dirigido a un curso,
 * rol o persona en particular. Lo que sí es específico de un curso (horario
 * de clases, exámenes puntuales) vive en el panel, donde tiene sentido: ese
 * horario no le sirve a alguien de afuera.
 *
 * Vive en el tema, mismo criterio que template-proyecto.php: hereda header,
 * mega menú, footer y colores del Customizer en vez de ser HTML suelto.
 *
 * Los eventos salen de Cead_Acad_Schedule_Feed::public_events() (plugin) —
 * si el plugin no está activo, la página lo dice y no rompe.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

$disponible = class_exists( 'Cead_Acad_Schedule_Feed' ) && class_exists( 'Cead_Acad_Schedule_CPT' );

if ( $disponible ) {
	// Mismo criterio de query var que el calendario del panel: NUNCA `m`
	// (WordPress lo reserva para archivos de fecha) ni `p` (post ID).
	$month_param = isset( $_GET['periodo'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $_GET['periodo'] ) : '';
	$cur_ts = current_time( 'timestamp' );
	$first  = false;
	if ( $month_param && preg_match( '/^(\d{4})-(\d{1,2})$/', $month_param, $mm ) ) {
		$first = mktime( 0, 0, 0, (int) $mm[2], 1, (int) $mm[1] );
	}
	if ( ! $first ) { $first = mktime( 0, 0, 0, (int) date( 'n', $cur_ts ), 1, (int) date( 'Y', $cur_ts ) ); }

	$y = (int) date( 'Y', $first );
	$n = (int) date( 'n', $first );
	$lead    = ( (int) date( 'N', $first ) ) - 1; // 0=Lun … 6=Dom
	$days_in = (int) date( 't', $first );
	$weeks   = (int) ceil( ( $lead + $days_in ) / 7 );
	$grid_start = $first - $lead * DAY_IN_SECONDS;
	$grid_end   = $grid_start + ( $weeks * 7 - 1 ) * DAY_IN_SECONDS;

	$prev = date( 'Y-m', mktime( 0, 0, 0, $n - 1, 1, $y ) );
	$next = date( 'Y-m', mktime( 0, 0, 0, $n + 1, 1, $y ) );

	// Mismo criterio que el panel: se piden los eventos que CRUZAN el mes, no
	// solo los que empiezan en él, para que unas vacaciones a mitad de camino se
	// vean igual; y el reparto por día lo hace el plugin, así el calendario
	// público y el del panel no pueden divergir.
	$from   = date( 'Y-m-d 00:00:00', $grid_start );
	$to     = date( 'Y-m-d 23:59:59', $grid_end );
	$events = Cead_Acad_Schedule_Feed::public_events( $from, $to, 300, true, true );

	$by_date = Cead_Acad_Schedule_Feed::expand_by_day( $events, $grid_start, $grid_end );

	// Próximos eventos (agenda), para quien prefiere una lista a una grilla.
	$ag_from   = current_time( 'Y-m-d 00:00:00' );
	$ag_to     = date( 'Y-m-d 23:59:59', strtotime( '+60 days', $cur_ts ) );
	$ag_events = Cead_Acad_Schedule_Feed::public_events( $ag_from, $ag_to, 20 );

	$tipos_presentes = [];
	foreach ( array_merge( $events, $ag_events ) as $e ) {
		$t = (string) get_post_meta( $e->ID, '_cead_acad_event_type', true ) ?: 'evento';
		$tipos_presentes[ $t ] = true;
	}
	ksort( $tipos_presentes );
}
?>
<main id="contenido" class="cal-pub">
	<section class="cal-hero">
		<div class="container">
			<p class="reveal eyebrow"><?php esc_html_e( 'Calendario', 'cead' ); ?></p>
			<h1 class="reveal cal-hero-title"><?php esc_html_e( 'Calendario institucional', 'cead' ); ?></h1>
			<p class="reveal cal-hero-body"><?php esc_html_e( 'Feriados, actos y períodos de cierre (vacaciones, exámenes) del CEAD. El horario de clases de cada curso y las fechas puntuales de cada materia se consultan desde el panel.', 'cead' ); ?></p>
		</div>
	</section>

	<?php if ( ! $disponible ) : ?>
		<section class="section container--narrow">
			<p><?php esc_html_e( 'El calendario todavía no está disponible.', 'cead' ); ?></p>
		</section>
	<?php else : ?>
		<section class="cal-body">
			<div class="container">
				<?php if ( $tipos_presentes ) : ?>
					<ul class="cal-legend" aria-label="<?php esc_attr_e( 'Referencias', 'cead' ); ?>">
						<?php foreach ( array_keys( $tipos_presentes ) as $t ) : ?>
							<li><span class="cal-legend-dot" style="--ev:<?php echo esc_attr( Cead_Acad_Schedule_CPT::default_color( $t ) ); ?>"></span><?php echo esc_html( Cead_Acad_Schedule_CPT::type_label( $t ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php
				/*
				 * La navegación vive dentro de la tarjeta —el mes con sus flechas
				 * arriba, la fila de meses abajo—, igual que en el calendario del
				 * panel: si los dos se dibujaran distinto, el mismo feriado se
				 * vería de dos formas según dónde lo mires.
				 */
				?>
				<div class="cal-card">
					<div class="cal-topo">
						<a class="cal-arrow" href="<?php echo esc_url( add_query_arg( 'periodo', $prev ) ); ?>" aria-label="<?php esc_attr_e( 'Mes anterior', 'cead' ); ?>"><span aria-hidden="true">‹</span></a>
						<h2 class="cal-topo-mes"><?php echo esc_html( ucfirst( date_i18n( 'F Y', $first ) ) ); ?></h2>
						<a class="cal-arrow" href="<?php echo esc_url( add_query_arg( 'periodo', $next ) ); ?>" aria-label="<?php esc_attr_e( 'Mes siguiente', 'cead' ); ?>"><span aria-hidden="true">›</span></a>
					</div>

				<div class="cal-grid" role="grid">
					<div class="cal-row cal-row--head" role="row">
						<?php foreach ( [ 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom' ] as $i => $wd ) : ?>
							<div class="cal-wd<?php echo $i >= 5 ? ' is-finde' : ''; ?>" role="columnheader"><?php echo esc_html( $wd ); ?></div>
						<?php endforeach; ?>
					</div>
					<?php $today_key = date( 'Y-m-d', $cur_ts ); ?>
					<?php for ( $w = 0; $w < $weeks; $w++ ) : ?>
						<div class="cal-row" role="row">
							<?php for ( $d = 0; $d < 7; $d++ ) :
								$ts  = $grid_start + ( $w * 7 + $d ) * DAY_IN_SECONDS;
								$key = date( 'Y-m-d', $ts );
								$out = ( (int) date( 'n', $ts ) !== $n );
								$evs = $by_date[ $key ] ?? [];
								$cls = 'cal-cell';
								if ( $out )                { $cls .= ' is-out'; }
								if ( $d >= 5 )             { $cls .= ' is-finde'; }
								if ( $key === $today_key ) { $cls .= ' is-today'; }
							?>
								<div class="<?php echo esc_attr( $cls ); ?>" role="gridcell">
									<span class="cal-day"><?php echo esc_html( (int) date( 'j', $ts ) ); ?></span>
									<?php foreach ( array_slice( $evs, 0, 3 ) as $f ) :
										$e   = $f['post'];
										$col = Cead_Acad_Schedule_CPT::event_color( $e->ID );
										// Un período se rotula donde empieza y al reentrar por
										// el lunes; en el medio es una banda sin texto, que es
										// como se lee «del 3 al 18» de un vistazo.
										$rotula = ! $f['span'] || $f['ini'] || 0 === $d;
										$cls_ev = 'cal-ev';
										if ( $f['span'] ) {
											$cls_ev .= ' cal-ev--span';
											if ( $f['ini'] ) { $cls_ev .= ' is-ini'; }
											if ( $f['fin'] ) { $cls_ev .= ' is-fin'; }
										}
									?>
										<span class="<?php echo esc_attr( $cls_ev ); ?>" style="--ev:<?php echo esc_attr( $col ); ?>" title="<?php echo esc_attr( get_the_title( $e ) ); ?>">
											<?php if ( ! $f['span'] ) : ?><span class="cal-ev-dot" aria-hidden="true"></span><?php endif; ?>
											<span class="cal-ev-t<?php echo $rotula ? '' : ' sr-only'; ?>"><?php echo esc_html( get_the_title( $e ) ); ?></span>
										</span>
									<?php endforeach; ?>
									<?php if ( count( $evs ) > 3 ) : ?>
										<span class="cal-more"><?php /* translators: %d: cantidad de eventos adicionales del día */ printf( esc_html__( '+%d más', 'cead' ), count( $evs ) - 3 ); ?></span>
									<?php endif; ?>
								</div>
							<?php endfor; ?>
						</div>
					<?php endfor; ?>
				</div>

					<div class="cal-pie">
						<a href="<?php echo esc_url( add_query_arg( 'periodo', $prev ) ); ?>"><?php esc_html_e( 'Mes anterior', 'cead' ); ?></a>
						<a class="is-hoy" href="<?php echo esc_url( remove_query_arg( 'periodo' ) ); ?>"><?php esc_html_e( 'Hoy', 'cead' ); ?></a>
						<a href="<?php echo esc_url( add_query_arg( 'periodo', $next ) ); ?>"><?php esc_html_e( 'Mes siguiente', 'cead' ); ?></a>
					</div>
				</div>
			</div>
		</section>

		<?php if ( $ag_events ) : ?>
			<section class="cal-agenda-section">
				<div class="container container--narrow">
					<h2 class="cal-agenda-h"><?php esc_html_e( 'Próximas fechas', 'cead' ); ?></h2>
					<ul class="cal-agenda-list">
						<?php foreach ( $ag_events as $e ) :
							$start = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
							$all   = (bool) get_post_meta( $e->ID, '_cead_acad_event_all_day', true );
							$type  = (string) get_post_meta( $e->ID, '_cead_acad_event_type', true ) ?: 'evento';
							$col   = Cead_Acad_Schedule_CPT::event_color( $e->ID );
						?>
							<li class="cal-agenda-item" style="--ev:<?php echo esc_attr( $col ); ?>">
								<span class="cal-agenda-date"><?php echo $start ? esc_html( date_i18n( 'j \d\e F', strtotime( $start ) ) ) : ''; ?></span>
								<span class="cal-agenda-title"><?php echo esc_html( get_the_title( $e ) ); ?></span>
								<span class="cal-agenda-type"><?php echo esc_html( Cead_Acad_Schedule_CPT::type_label( $type ) ); ?><?php echo $all ? ' · ' . esc_html__( 'todo el día', 'cead' ) : ''; ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			</section>
		<?php endif; ?>
	<?php endif; ?>
</main>
<?php
get_footer();
