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

/*
 * Eventos que CRUZAN el mes visible, no solo los que empiezan en él (de ahí el
 * último `true`): unas vacaciones que arrancaron el 6 de julio y terminan el 24
 * tienen que verse también al abrir julio a mitad de mes, y asomar en agosto si
 * llegan hasta ahí. Filtrando por fecha de inicio, un período largo se volvía
 * invisible justo en los días en que está pasando.
 */
$from   = date( 'Y-m-d 00:00:00', $grid_start );
$to     = date( 'Y-m-d 23:59:59', $grid_end );
$events = Cead_Acad_Schedule_Feed::for_user( $user->ID, $from, $to, 300, true, true );

// Un evento se dibuja en todos los días que abarca, sabiendo en cuál empieza y
// en cuál termina: eso es lo que permite pintarlo como una banda continua.
$by_date = Cead_Acad_Schedule_Feed::expand_by_day( $events, $grid_start, $grid_end );

// Tipos presentes en el mes, para la leyenda: sin ella los colores son
// decoración; con ella son información.
$tipos_mes = [];
foreach ( $by_date as $filas ) {
	foreach ( $filas as $f ) {
		$t = (string) get_post_meta( $f['post']->ID, '_cead_acad_event_type', true ) ?: 'evento';
		$tipos_mes[ $t ] = true;
	}
}
ksort( $tipos_mes );

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

$body = function () use ( $view, $y, $n, $weeks, $grid_start, $first, $days_in, $by_date, $tipos_mes, $prev, $next, $cur_ts, $by_day, $ag_events, $feed_https, $feed_webcal, $gcal_url ) {
	$today_key = date( 'Y-m-d', $cur_ts );
	$wd = [ __( 'Lun', 'cead-acad' ), __( 'Mar', 'cead-acad' ), __( 'Mié', 'cead-acad' ), __( 'Jue', 'cead-acad' ), __( 'Vie', 'cead-acad' ), __( 'Sáb', 'cead-acad' ), __( 'Dom', 'cead-acad' ) ];
	$base = cead_acad_url( 'panel/calendario' );
	?>
	<section class="cead-acad-panel-section">
		<div class="cead-acad-cal-head">
			<div>
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'CEAD', 'cead-acad' ); ?></span>
				<?php // El mes lo dice la tarjeta, junto a las flechas que lo cambian. ?>
				<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Calendario', 'cead-acad' ); ?></h2>
			</div>
			<div class="cead-acad-cal-tools">
				<div class="cead-acad-cal-toggle" role="group" aria-label="<?php esc_attr_e( 'Forma de ver el calendario', 'cead-acad' ); ?>">
					<a class="<?php echo 'mes' === $view ? 'is-active' : ''; ?>" <?php echo 'mes' === $view ? 'aria-current="true"' : ''; ?> href="<?php echo esc_url( add_query_arg( [ 'vista' => 'mes', 'periodo' => $y . '-' . str_pad( $n, 2, '0', STR_PAD_LEFT ) ], $base ) ); ?>"><?php esc_html_e( 'Mes', 'cead-acad' ); ?></a>
					<a class="<?php echo 'agenda' === $view ? 'is-active' : ''; ?>" <?php echo 'agenda' === $view ? 'aria-current="true"' : ''; ?> href="<?php echo esc_url( add_query_arg( 'vista', 'agenda', $base ) ); ?>"><?php esc_html_e( 'Agenda', 'cead-acad' ); ?></a>
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
			<?php if ( $tipos_mes ) : ?>
				<ul class="cead-acad-cal-legend" aria-label="<?php esc_attr_e( 'Referencias de color', 'cead-acad' ); ?>">
					<?php foreach ( array_keys( $tipos_mes ) as $t ) : ?>
						<li style="--ev:<?php echo esc_attr( Cead_Acad_Schedule_CPT::default_color( $t ) ); ?>">
							<span class="cead-acad-cal-legend-dot"><?php echo Cead_Acad_Schedule_CPT::type_icon( $t, 12 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<?php echo esc_html( Cead_Acad_Schedule_CPT::type_label( $t ) ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php
			/*
			 * La navegación vive DENTRO de la tarjeta: el mes con sus flechas
			 * arriba, y abajo la fila de Anterior · Hoy · Siguiente.
			 *
			 * Antes eran tres barras apiladas —la cabecera de la página, una fila
			 * suelta de botones y recién ahí la grilla—, y ninguna de las tres
			 * decía con fuerza qué mes se estaba mirando.
			 */
			?>
			<div class="cead-acad-cal">
				<div class="cead-acad-cal-topo">
					<a class="cead-acad-calnav-arrow" href="<?php echo esc_url( add_query_arg( [ 'vista' => 'mes', 'periodo' => $prev ], $base ) ); ?>" aria-label="<?php esc_attr_e( 'Mes anterior', 'cead-acad' ); ?>"><span aria-hidden="true">‹</span></a>
					<h3 class="cead-acad-cal-topo-mes"><?php echo esc_html( ucfirst( date_i18n( 'F Y', $first ) ) ); ?></h3>
					<a class="cead-acad-calnav-arrow" href="<?php echo esc_url( add_query_arg( [ 'vista' => 'mes', 'periodo' => $next ], $base ) ); ?>" aria-label="<?php esc_attr_e( 'Mes siguiente', 'cead-acad' ); ?>"><span aria-hidden="true">›</span></a>
				</div>

				<div class="cead-acad-cal-grid" role="grid">
				<div class="cead-acad-cal-row cead-acad-cal-row--head" role="row">
					<?php foreach ( $wd as $i => $d ) : ?><div class="cead-acad-cal-wd<?php echo $i >= 5 ? ' is-finde' : ''; ?>" role="columnheader"><?php echo esc_html( $d ); ?></div><?php endforeach; ?>
				</div>
				<?php for ( $w = 0; $w < $weeks; $w++ ) : ?>
					<div class="cead-acad-cal-row" role="row">
						<?php for ( $d = 0; $d < 7; $d++ ) :
							$ts  = $grid_start + ( $w * 7 + $d ) * DAY_IN_SECONDS;
							$key = date( 'Y-m-d', $ts );
							$out = ( (int) date( 'n', $ts ) !== $n );
							$evs = $by_date[ $key ] ?? [];
							$cls = 'cead-acad-cal-cell';
							if ( $out )              { $cls .= ' is-out'; }
							if ( $d >= 5 )           { $cls .= ' is-finde'; }
							if ( $key === $today_key ) { $cls .= ' is-today'; }
						?>
							<div class="<?php echo esc_attr( $cls ); ?>" role="gridcell">
								<span class="cead-acad-cal-day"><?php echo esc_html( (int) date( 'j', $ts ) ); ?></span>
								<div class="cead-acad-cal-evs">
								<?php
								foreach ( array_slice( $evs, 0, 3 ) as $f ) :
									$e    = $f['post'];
									$type = (string) get_post_meta( $e->ID, '_cead_acad_event_type', true ) ?: 'evento';
									$st   = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
									$col  = Cead_Acad_Schedule_CPT::event_color( $e->ID );

									/*
									 * Una banda solo lleva etiqueta donde empieza —o al
									 * reentrar por el lunes, si viene de la semana pasada—.
									 * Repetirla en cada celda es lo que hacía que un
									 * período de dos semanas se leyera como catorce
									 * eventos distintos en vez de uno solo largo.
									 */
									$rotula = ! $f['span'] || $f['ini'] || 0 === $d;

									$cls_ev = 'cead-acad-cal-ev';
									if ( $f['span'] ) {
										$cls_ev .= ' cead-acad-cal-ev--span';
										if ( $f['ini'] ) { $cls_ev .= ' is-ini'; }
										if ( $f['fin'] ) { $cls_ev .= ' is-fin'; }
									}
								?>
									<a class="<?php echo esc_attr( $cls_ev ); ?>" style="--ev:<?php echo esc_attr( $col ); ?>"
									   href="<?php echo esc_url( cead_acad_url( 'panel/calendario/' . $e->ID ) ); ?>"
									   title="<?php echo esc_attr( Cead_Acad_Schedule_CPT::type_label( $type ) . ' · ' . get_the_title( $e ) ); ?>">
										<?php
										/*
										 * La figura del tipo va SIEMPRE, también en la banda de
										 * un período: es lo que dice de qué se trata cuando la
										 * celda es tan angosta que no entra ni una palabra. El
										 * color solo obligaba a ir a buscar la referencia y
										 * contar cuál de los nueve tonos era.
										 */
										echo Cead_Acad_Schedule_CPT::type_icon( $type, 12 ); // phpcs:ignore WordPress.Security.EscapeOutput
										?>
										<span class="cead-acad-cal-ev-t<?php echo $rotula ? '' : ' cead-acad-sr-only'; ?>">
											<?php if ( ! $f['span'] && $st && '00:00' !== substr( $st, 11, 5 ) ) : ?><b><?php echo esc_html( date_i18n( 'H:i', strtotime( $st ) ) ); ?></b> <?php endif; ?>
											<?php echo esc_html( get_the_title( $e ) ); ?>
										</span>
									</a>
								<?php endforeach; ?>
								<?php if ( count( $evs ) > 3 ) : ?>
									<span class="cead-acad-cal-more"><?php /* translators: %d: cantidad de eventos adicionales del día */ printf( esc_html__( '+%d más', 'cead-acad' ), count( $evs ) - 3 ); ?></span>
								<?php endif; ?>
								</div>
							</div>
						<?php endfor; ?>
					</div>
				<?php endfor; ?>
				</div>

				<div class="cead-acad-cal-pie">
					<a href="<?php echo esc_url( add_query_arg( [ 'vista' => 'mes', 'periodo' => $prev ], $base ) ); ?>"><?php esc_html_e( 'Mes anterior', 'cead-acad' ); ?></a>
					<a class="is-hoy" href="<?php echo esc_url( add_query_arg( 'vista', 'mes', $base ) ); ?>"><?php esc_html_e( 'Hoy', 'cead-acad' ); ?></a>
					<a href="<?php echo esc_url( add_query_arg( [ 'vista' => 'mes', 'periodo' => $next ], $base ) ); ?>"><?php esc_html_e( 'Mes siguiente', 'cead-acad' ); ?></a>
				</div>
			</div>

			<?php
			/*
			 * Solo se ve en celular (lo esconde el CSS de 640px para arriba). Ahí
			 * la grilla muestra barras de color sin nombre, porque a ~50px de
			 * ancho por celda no entra texto legible; decirlo es mejor que dejar
			 * a alguien tocando cuadraditos a ver qué pasa.
			 */
			?>
			<p class="cead-acad-cal-hint"><?php esc_html_e( 'Tocá una barra para abrir el evento. Para verlos con nombre y hora, usá la vista Agenda.', 'cead-acad' ); ?></p>

		<?php else : /* ===== Agenda ===== */ ?>
			<?php if ( ! $ag_events ) : ?>
				<div class="cead-acad-card cead-acad-card--empty" style="margin-top:1.5rem">
					<span class="cead-acad-eyebrow"><?php esc_html_e( 'Sin eventos', 'cead-acad' ); ?></span>
					<h3><?php esc_html_e( 'No hay eventos próximos', 'cead-acad' ); ?></h3>
					<p><?php esc_html_e( 'Cuando dirección o tus docentes publiquen eventos, aparecerán acá.', 'cead-acad' ); ?></p>
				</div>
			<?php else : ?>
				<div class="cead-acad-agenda">
					<?php
					$manana_key = date( 'Y-m-d', $cur_ts + DAY_IN_SECONDS );
					foreach ( $by_day as $day => $items ) :
						$ts = strtotime( $day );
						// «Hoy» y «Mañana» en vez de una fecha: es lo que la persona
						// está buscando de verdad al abrir la agenda.
						$rel = '';
						if ( $day === $today_key )       { $rel = __( 'Hoy', 'cead-acad' ); }
						elseif ( $day === $manana_key )  { $rel = __( 'Mañana', 'cead-acad' ); }
					?>
						<div class="cead-acad-agenda-day<?php echo $day === $today_key ? ' is-today' : ''; ?>">
							<div class="cead-acad-agenda-daykey">
								<span class="cead-acad-agenda-num"><?php echo $ts ? esc_html( date_i18n( 'd', $ts ) ) : '—'; ?></span>
								<span class="cead-acad-agenda-month"><?php echo $ts ? esc_html( date_i18n( 'M', $ts ) ) : ''; ?></span>
								<span class="cead-acad-agenda-weekday"><?php echo $rel ? esc_html( $rel ) : ( $ts ? esc_html( date_i18n( 'l', $ts ) ) : '' ); ?></span>
							</div>
							<div class="cead-acad-agenda-items">
								<?php foreach ( $items as $e ) :
									$start = (string) get_post_meta( $e->ID, '_cead_acad_event_start',    true );
									$end   = (string) get_post_meta( $e->ID, '_cead_acad_event_end',      true );
									$loc   = (string) get_post_meta( $e->ID, '_cead_acad_event_location', true );
									$type  = (string) get_post_meta( $e->ID, '_cead_acad_event_type',     true );
									$all   = (bool)   get_post_meta( $e->ID, '_cead_acad_event_all_day',  true );
									$col   = Cead_Acad_Schedule_CPT::event_color( $e->ID );
									// Un período se dice como período: la hora de fin de
									// unas vacaciones de dos semanas no le importa a nadie,
									// pero el día en que terminan sí.
									$largo = $end && substr( $end, 0, 10 ) !== substr( $start, 0, 10 );
								?>
									<a class="cead-acad-agenda-item" style="--ev:<?php echo esc_attr( $col ); ?>" href="<?php echo esc_url( cead_acad_url( 'panel/calendario/' . $e->ID ) ); ?>">
										<div class="cead-acad-agenda-time">
											<?php if ( $largo ) : ?>
												<span><?php esc_html_e( 'Hasta el', 'cead-acad' ); ?></span>
												<span class="cead-acad-agenda-time-end"><?php echo esc_html( date_i18n( 'j M', strtotime( $end ) ) ); ?></span>
											<?php elseif ( $all ) : ?>
												<span><?php esc_html_e( 'Todo el día', 'cead-acad' ); ?></span>
											<?php else : ?>
												<span><?php echo $start ? esc_html( date_i18n( 'H:i', strtotime( $start ) ) ) : '—'; ?></span>
												<?php if ( $end ) : ?><span class="cead-acad-agenda-time-end">→ <?php echo esc_html( date_i18n( 'H:i', strtotime( $end ) ) ); ?></span><?php endif; ?>
											<?php endif; ?>
										</div>
										<div class="cead-acad-agenda-body">
											<span class="cead-acad-agenda-tipo"><?php echo esc_html( Cead_Acad_Schedule_CPT::type_label( $type ?: 'evento' ) ); ?></span>
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
