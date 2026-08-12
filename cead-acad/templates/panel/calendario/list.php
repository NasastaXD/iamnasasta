<?php
/**
 * /panel/calendario: vista mensual (grilla) o agenda. ?vista=mes|agenda, ?periodo=YYYY-MM
 *
 * El parámetro de mes NO se llama `m`: es el query var que WordPress usa para
 * archivos por fecha (`?m=YYYYMM`). Con `m` en la URL, el `WP_Query` principal
 * detectaba un archivo de fecha antes de que este router propio llegara a
 * correr, y `redirect_canonical()` mandaba al visitante a la URL "canónica"
 * de ese archivo — la página de posts normales de ese mes, que es lo que se
 * veía como "te redirige a los artículos". Aparte de este: cualquier query
 * var propio en el panel tiene que evitar los reservados de WordPress (m, p,
 * cat, s, page, ...), no solo este.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user = wp_get_current_user();

$view = isset( $_GET['vista'] ) && 'agenda' === $_GET['vista'] ? 'agenda' : 'mes';

// Mes solicitado (YYYY-MM), con fallback al actual.
$month_param = isset( $_GET['periodo'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $_GET['periodo'] ) : '';
$cur_ts = current_time( 'timestamp' );
$first  = false;
if ( $month_param && preg_match( '/^(\d{4})-(\d{1,2})$/', $month_param, $mm ) ) {
	$first = mktime( 0, 0, 0, (int) $mm[2], 1, (int) $mm[1] );
}
if ( ! $first ) { $first = mktime( 0, 0, 0, (int) date( 'n', $cur_ts ), 1, (int) date( 'Y', $cur_ts ) ); }

$y = (int) date( 'Y', $first );
$n = (int) date( 'n', $first );
$days_in = (int) date( 't', $first );
$lead    = ( (int) date( 'N', $first ) ) - 1;           // 0=Lun … 6=Dom
$cells   = $lead + $days_in;
$weeks   = (int) ceil( $cells / 7 );
$grid_start = $first - $lead * DAY_IN_SECONDS;
$grid_end   = $grid_start + ( $weeks * 7 - 1 ) * DAY_IN_SECONDS;

$prev = date( 'Y-m', mktime( 0, 0, 0, $n - 1, 1, $y ) );
$next = date( 'Y-m', mktime( 0, 0, 0, $n + 1, 1, $y ) );

// Eventos del rango visible.
$from   = date( 'Y-m-d 00:00:00', $grid_start );
$to     = date( 'Y-m-d 23:59:59', $grid_end );
$events = Cead_Acad_Schedule_Feed::for_user( $user->ID, $from, $to, 300 );

/*
 * Un evento se agrega a TODOS los días que abarca, no solo al de inicio.
 * Antes solo se agregaba al día de `_cead_acad_event_start`, así que un
 * período de varios días ("cierre": vacaciones, exámenes) solo se veía en
 * la grilla el primer día y desaparecía el resto — quedaba invisible
 * justo donde más hacía falta verlo.
 */
$by_date = [];
foreach ( $events as $e ) {
	$st = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
	if ( ! $st ) { continue; }
	$start_ts = strtotime( $st );
	if ( ! $start_ts ) { continue; }
	$en = (string) get_post_meta( $e->ID, '_cead_acad_event_end', true );
	$end_ts = $en ? strtotime( $en ) : $start_ts;
	if ( ! $end_ts || $end_ts < $start_ts ) { $end_ts = $start_ts; }

	$day_ts = strtotime( date( 'Y-m-d', $start_ts ) );
	$last_day_ts = strtotime( date( 'Y-m-d', $end_ts ) );
	// Tope de 366 días: una fecha de fin mal tipeada (año equivocado) no
	// tiene por qué colgar la página rellenando la grilla entera.
	$last_day_ts = min( $last_day_ts, $day_ts + 366 * DAY_IN_SECONDS );
	while ( $day_ts <= $last_day_ts ) {
		$by_date[ date( 'Y-m-d', $day_ts ) ][] = $e;
		$day_ts += DAY_IN_SECONDS;
	}
}

// Para la vista agenda (próximos 60 días).
$ag_from = current_time( 'Y-m-d 00:00:00' );
$ag_to   = date( 'Y-m-d 23:59:59', strtotime( '+60 days', $cur_ts ) );
$ag_events = 'agenda' === $view ? Cead_Acad_Schedule_Feed::for_user( $user->ID, $ag_from, $ag_to, 200 ) : [];
$by_day    = 'agenda' === $view ? Cead_Acad_Schedule_Feed::group_by_day( $ag_events ) : [];

// Feed iCal suscribible.
$feed_token  = Cead_Acad_Account::feed_token( $user->ID );
$feed_https  = home_url( '/cal/' . $feed_token . '.ics' );
$feed_webcal = preg_replace( '#^https?://#', 'webcal://', $feed_https );
$gcal_url    = 'https://calendar.google.com/calendar/r?cid=' . rawurlencode( $feed_https );

$page_title = __( 'Calendario', 'cead-acad' );

$body = function () use ( $view, $y, $n, $weeks, $grid_start, $first, $days_in, $by_date, $prev, $next, $cur_ts, $by_day, $ag_events, $feed_https, $feed_webcal, $gcal_url ) {
	$today_key = date( 'Y-m-d', $cur_ts );
	$wd = [ __( 'Lun', 'cead-acad' ), __( 'Mar', 'cead-acad' ), __( 'Mié', 'cead-acad' ), __( 'Jue', 'cead-acad' ), __( 'Vie', 'cead-acad' ), __( 'Sáb', 'cead-acad' ), __( 'Dom', 'cead-acad' ) ];
	$base = cead_acad_url( 'panel/calendario' );
	?>
	<section class="cead-acad-panel-section">
		<div class="cead-acad-cal-head">
			<div>
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Calendario', 'cead-acad' ); ?></span>
				<h2 class="cead-acad-panel-h"><?php echo esc_html( ucfirst( date_i18n( 'F Y', $first ) ) ); ?></h2>
			</div>
			<div class="cead-acad-cal-tools">
				<div class="cead-acad-cal-toggle">
					<a class="<?php echo 'mes' === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'vista' => 'mes', 'periodo' => $y . '-' . str_pad( $n, 2, '0', STR_PAD_LEFT ) ], $base ) ); ?>"><?php esc_html_e( 'Mes', 'cead-acad' ); ?></a>
					<a class="<?php echo 'agenda' === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'vista', 'agenda', $base ) ); ?>"><?php esc_html_e( 'Agenda', 'cead-acad' ); ?></a>
				</div>
				<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cead_acad_event_ical' ), 'cead_acad_event_ical' ) ); ?>"><?php esc_html_e( 'iCal', 'cead-acad' ); ?></a>
			</div>
		</div>

		<details class="cead-acad-subscribe">
			<summary>📲 <?php esc_html_e( 'Sincronizar con el calendario de mi celular', 'cead-acad' ); ?></summary>
			<div class="cead-acad-subscribe-body">
				<p><?php esc_html_e( 'Agregalo una vez y los eventos del CEAD aparecen (y se actualizan) solos en tu calendario.', 'cead-acad' ); ?></p>
				<div class="cead-acad-subscribe-actions">
					<a class="cead-acad-btn" href="<?php echo esc_url( $feed_webcal ); ?>"><?php esc_html_e( 'Agregar (iPhone / Apple)', 'cead-acad' ); ?></a>
					<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( $gcal_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Agregar a Google Calendar', 'cead-acad' ); ?></a>
				</div>
				<label class="cead-acad-field" style="margin-top:.75rem">
					<span><?php esc_html_e( 'O copiá este enlace y agregalo en tu app de calendario:', 'cead-acad' ); ?></span>
					<input type="text" readonly value="<?php echo esc_attr( $feed_https ); ?>" title="Click para copiar" onclick="this.select(); if (navigator.clipboard) { navigator.clipboard.writeText(this.value); this.setAttribute('title', 'Copiado'); }">
				</label>
				<p class="cead-acad-subscribe-hint"><?php esc_html_e( 'Android: Google Calendar → Configuración → Agregar calendario → Desde URL. iPhone: el botón de Apple lo agrega directo.', 'cead-acad' ); ?></p>
			</div>
		</details>

		<?php if ( 'mes' === $view ) : ?>
			<div class="cead-acad-cal-nav">
				<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( add_query_arg( [ 'vista' => 'mes', 'periodo' => $prev ], $base ) ); ?>">← <?php esc_html_e( 'Anterior', 'cead-acad' ); ?></a>
				<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( add_query_arg( 'vista', 'mes', $base ) ); ?>"><?php esc_html_e( 'Hoy', 'cead-acad' ); ?></a>
				<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( add_query_arg( [ 'vista' => 'mes', 'periodo' => $next ], $base ) ); ?>"><?php esc_html_e( 'Siguiente', 'cead-acad' ); ?> →</a>
			</div>

			<div class="cead-acad-cal" role="grid">
				<div class="cead-acad-cal-row cead-acad-cal-row--head" role="row">
					<?php foreach ( $wd as $d ) : ?><div class="cead-acad-cal-wd" role="columnheader"><?php echo esc_html( $d ); ?></div><?php endforeach; ?>
				</div>
				<?php for ( $w = 0; $w < $weeks; $w++ ) : ?>
					<div class="cead-acad-cal-row" role="row">
						<?php for ( $d = 0; $d < 7; $d++ ) :
							$ts  = $grid_start + ( $w * 7 + $d ) * DAY_IN_SECONDS;
							$key = date( 'Y-m-d', $ts );
							$out = ( (int) date( 'n', $ts ) !== $n );
							$evs = $by_date[ $key ] ?? [];
						?>
							<div class="cead-acad-cal-cell<?php echo $out ? ' is-out' : ''; ?><?php echo $key === $today_key ? ' is-today' : ''; ?>" role="gridcell">
								<span class="cead-acad-cal-day"><?php echo esc_html( (int) date( 'j', $ts ) ); ?></span>
								<?php
								$shown = array_slice( $evs, 0, 3 );
								foreach ( $shown as $e ) :
									$type = (string) get_post_meta( $e->ID, '_cead_acad_event_type', true ) ?: 'evento';
									$st   = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
									$col  = Cead_Acad_Schedule_CPT::event_color( $e->ID );
									$is_span = 'cierre' === $type;
								?>
									<a class="cead-acad-cal-ev<?php echo $is_span ? ' cead-acad-cal-ev--span' : ''; ?>" href="<?php echo esc_url( cead_acad_url( 'panel/calendario/' . $e->ID ) ); ?>" title="<?php echo esc_attr( get_the_title( $e ) ); ?>" style="<?php echo $is_span ? 'background:' . esc_attr( $col ) . '22;border-left:3px solid ' . esc_attr( $col ) : ''; ?>">
										<?php if ( ! $is_span ) : ?><span class="cead-acad-cal-dot" style="background:<?php echo esc_attr( $col ); ?>"></span><?php endif; ?>
										<span class="cead-acad-cal-ev-t"><?php echo ( $st && ! $is_span ) ? esc_html( date_i18n( 'H:i', strtotime( $st ) ) . ' ' ) : ''; ?><?php echo esc_html( get_the_title( $e ) ); ?></span>
									</a>
								<?php endforeach; ?>
								<?php if ( count( $evs ) > 3 ) : ?>
									<span class="cead-acad-cal-more"><?php /* translators: %d: cantidad de eventos adicionales del día */ printf( esc_html__( '+%d más', 'cead-acad' ), count( $evs ) - 3 ); ?></span>
								<?php endif; ?>
							</div>
						<?php endfor; ?>
					</div>
				<?php endfor; ?>
			</div>

		<?php else : /* ===== Agenda ===== */ ?>
			<?php if ( ! $ag_events ) : ?>
				<div class="cead-acad-card cead-acad-card--empty" style="margin-top:1.5rem">
					<span class="cead-acad-eyebrow"><?php esc_html_e( 'Sin eventos', 'cead-acad' ); ?></span>
					<h3><?php esc_html_e( 'No hay eventos próximos', 'cead-acad' ); ?></h3>
					<p><?php esc_html_e( 'Cuando dirección o tus docentes publiquen eventos, aparecerán acá.', 'cead-acad' ); ?></p>
				</div>
			<?php else : ?>
				<div class="cead-acad-agenda">
					<?php foreach ( $by_day as $day => $items ) :
						$ts = strtotime( $day );
					?>
						<div class="cead-acad-agenda-day">
							<div class="cead-acad-agenda-daykey">
								<span class="cead-acad-agenda-num"><?php echo $ts ? esc_html( date_i18n( 'd', $ts ) ) : '—'; ?></span>
								<span class="cead-acad-agenda-month"><?php echo $ts ? esc_html( date_i18n( 'M', $ts ) ) : ''; ?></span>
								<span class="cead-acad-agenda-weekday"><?php echo $ts ? esc_html( date_i18n( 'l', $ts ) ) : ''; ?></span>
							</div>
							<div class="cead-acad-agenda-items">
								<?php foreach ( $items as $e ) :
									$start = (string) get_post_meta( $e->ID, '_cead_acad_event_start',    true );
									$end   = (string) get_post_meta( $e->ID, '_cead_acad_event_end',      true );
									$loc   = (string) get_post_meta( $e->ID, '_cead_acad_event_location', true );
									$type  = (string) get_post_meta( $e->ID, '_cead_acad_event_type',     true );
									$all   = (bool)   get_post_meta( $e->ID, '_cead_acad_event_all_day',  true );
									$col   = Cead_Acad_Schedule_CPT::event_color( $e->ID );
								?>
									<a class="cead-acad-agenda-item" style="border-left-color:<?php echo esc_attr( $col ); ?>" href="<?php echo esc_url( cead_acad_url( 'panel/calendario/' . $e->ID ) ); ?>">
										<div class="cead-acad-agenda-time">
											<?php if ( $all ) : ?>
												<span><?php esc_html_e( 'Todo el día', 'cead-acad' ); ?></span>
											<?php else : ?>
												<span><?php echo $start ? esc_html( date_i18n( 'H:i', strtotime( $start ) ) ) : '—'; ?></span>
												<?php if ( $end ) : ?><span class="cead-acad-agenda-time-end">→ <?php echo esc_html( date_i18n( 'H:i', strtotime( $end ) ) ); ?></span><?php endif; ?>
											<?php endif; ?>
										</div>
										<div class="cead-acad-agenda-body">
											<span class="cead-acad-eyebrow"><?php echo esc_html( Cead_Acad_Schedule_CPT::type_label( $type ?: 'evento' ) ); ?></span>
											<h3 class="cead-acad-agenda-title"><?php echo esc_html( get_the_title( $e ) ); ?></h3>
											<?php if ( $loc ) : ?><p class="cead-acad-agenda-loc">📍 <?php echo esc_html( $loc ); ?></p><?php endif; ?>
										</div>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
