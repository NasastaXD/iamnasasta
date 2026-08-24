<?php
/**
 * Panel — home (dashboard). Pensado para el alumnado: accesos rápidos, clases de
 * hoy, próximos eventos y últimos comunicados.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user  = wp_get_current_user();
$role  = cead_acad_user_role( $user->ID );
$roles = Cead_Acad_Capabilities::roles();
$rdisp = $roles[ $role ]['display'] ?? $role;

$sub = isset( $sub ) ? (string) $sub : '';

// Curso del usuario (para "clases de hoy").
$course_id = (int) get_user_meta( $user->ID, '_cead_acad_current_course_id', true );
if ( ! $course_id && class_exists( 'Cead_Acad_Courses_Roster' ) ) {
	$cs = Cead_Acad_Courses_Roster::courses_for_user( $user->ID );
	if ( $cs ) { $course_id = (int) $cs[0]; }
}
$today_dow = (int) current_time( 'N' ); // 1=Lunes … 7=Domingo
$today_classes = [];
if ( $course_id ) {
	$raw   = get_post_meta( $course_id, '_cead_acad_horario', true );
	$slots = is_array( $raw ) ? $raw : ( is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : [] );
	foreach ( (array) $slots as $s ) {
		if ( (int) ( $s['dia'] ?? 0 ) === $today_dow ) { $today_classes[] = $s; }
	}
	usort( $today_classes, static function ( $a, $b ) { return strcmp( (string) ( $a['inicio'] ?? '' ), (string) ( $b['inicio'] ?? '' ) ); } );
}

// Próximos eventos (calendario).
$from   = current_time( 'Y-m-d 00:00:00' );
$to     = date( 'Y-m-d 23:59:59', strtotime( '+30 days', current_time( 'timestamp' ) ) );
$events = class_exists( 'Cead_Acad_Schedule_Feed' ) ? Cead_Acad_Schedule_Feed::for_user( $user->ID, $from, $to, 4 ) : [];

$unread            = (int) Cead_Acad_Broadcasts_Reads::count_unread_for_user( $user->ID );
$recent_broadcasts = Cead_Acad_Broadcasts_Feed::for_user( $user->ID, [ 'per_page' => 3 ] );

// Accesos rápidos (según permisos).
$quick = [
	[ 'href' => 'panel/horarios',    'label' => __( 'Mi horario', 'cead-acad' ),  'icon' => '📚' ],
	[ 'href' => 'panel/calendario',  'label' => __( 'Calendario', 'cead-acad' ),  'icon' => '📅' ],
	[ 'href' => 'panel/comunicados', 'label' => __( 'Comunicados', 'cead-acad' ), 'icon' => '📣' ],
	[ 'href' => 'panel/recursos',    'label' => __( 'Recursos', 'cead-acad' ),    'icon' => '📁' ],
];
// El carné va apagado hasta que el colegio lo adopte (cead_acad_carne_activo()).
if ( cead_acad_carne_activo() ) {
	$quick[] = [ 'href' => 'panel/carne', 'label' => __( 'Mi carné', 'cead-acad' ), 'icon' => '🪪' ];
}
if ( current_user_can( 'cead_acad_view_own_grades' ) ) {
	$quick[] = [ 'href' => 'panel/boletin', 'label' => __( 'Boletín', 'cead-acad' ), 'icon' => '📝' ];
}
if ( class_exists( 'Cead_Acad_Turismo' ) && '' !== Cead_Acad_Turismo::rol_de( get_current_user_id() ) ) {
	$quick[] = [ 'href' => 'panel/turismo', 'label' => __( 'Portal turístico', 'cead-acad' ), 'icon' => '🌴' ];
}
$quick[] = [ 'href' => 'panel/encuestas', 'label' => __( 'Encuestas', 'cead-acad' ), 'icon' => '🗳️' ];
if ( current_user_can( 'cead_acad_view_delegates' ) ) {
	$quick[] = [ 'href' => 'panel/delegados', 'label' => __( 'Delegados', 'cead-acad' ), 'icon' => '📇' ];
}
if ( current_user_can( 'cead_acad_manage_reports' ) || current_user_can( 'cead_acad_manage_suggestions' ) ) {
	$quick[] = [ 'href' => 'panel/buzon', 'label' => __( 'Buzón', 'cead-acad' ), 'icon' => '📨' ];
}

$page_title = __( 'Inicio', 'cead-acad' );

$body = function () use ( $user, $rdisp, $quick, $today_classes, $events, $unread, $recent_broadcasts ) {
	$read_ids = Cead_Acad_Broadcasts_Reads::read_ids_for_user( $user->ID );
	$days_es  = [ 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo' ];
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php echo esc_html( ucfirst( wp_date( 'l, j \d\e F' ) ) ); ?></span>
		<h2 class="cead-acad-panel-h"><?php /* translators: %s: nombre del usuario */ printf( esc_html__( 'Hola, %s 👋', 'cead-acad' ), esc_html( $user->display_name ) ); ?></h2>
		<p class="cead-acad-panel-sub"><?php /* translators: %s: rol del usuario */ printf( esc_html__( 'Estás en el panel del CEAD como %s.', 'cead-acad' ), '<strong>' . esc_html( $rdisp ) . '</strong>' ); ?></p>

		<!-- Accesos rápidos -->
		<div class="cead-acad-quick">
			<?php foreach ( $quick as $q ) : ?>
				<a class="cead-acad-quick-item" href="<?php echo esc_url( cead_acad_url( $q['href'] ) ); ?>">
					<span class="cead-acad-quick-ico" aria-hidden="true"><?php echo esc_html( $q['icon'] ); ?></span>
					<span class="cead-acad-quick-lbl"><?php echo esc_html( $q['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>

		<div class="cead-acad-grid cead-acad-grid--2" style="margin-top:2rem;align-items:start">
			<!-- Clases de hoy -->
			<div class="cead-acad-card">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Clases de hoy', 'cead-acad' ); ?></span>
				<?php if ( $today_classes ) : ?>
					<ul class="cead-acad-today">
						<?php foreach ( $today_classes as $c ) :
							$hi = (string) ( $c['inicio'] ?? '' );
							$hf = (string) ( $c['fin'] ?? '' );
						?>
							<li>
								<span class="cead-acad-today-time"><?php echo esc_html( $hi !== '' ? $hi : '—' ); ?><?php echo $hf !== '' ? ' – ' . esc_html( $hf ) : ''; ?></span>
								<span class="cead-acad-today-mat"><?php echo esc_html( (string) ( $c['materia'] ?? '' ) ); ?></span>
								<?php if ( ! empty( $c['docente'] ) ) : ?><span class="cead-acad-today-doc">👤 <?php echo esc_html( (string) $c['docente'] ); ?></span><?php endif; ?>
								<?php if ( ! empty( $c['aula'] ) ) : ?><span class="cead-acad-today-doc">📍 <?php echo esc_html( (string) $c['aula'] ); ?></span><?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<h3><?php echo (int) $today_dow >= 6 ? esc_html__( '¡Buen finde!', 'cead-acad' ) : esc_html__( 'Sin clases cargadas', 'cead-acad' ); ?></h3>
					<p><?php esc_html_e( 'Tu horario de hoy aparecerá acá cuando esté cargado.', 'cead-acad' ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Próximos eventos -->
			<div class="cead-acad-card">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Próximos eventos', 'cead-acad' ); ?></span>
				<?php if ( $events ) : ?>
					<ul class="cead-acad-today">
						<?php foreach ( $events as $e ) :
							$st = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
						?>
							<li>
								<span class="cead-acad-today-time"><?php echo $st ? esc_html( date_i18n( 'd/m H:i', strtotime( $st ) ) ) : ''; ?></span>
								<a class="cead-acad-today-mat" href="<?php echo esc_url( cead_acad_url( 'panel/calendario/' . $e->ID ) ); ?>"><?php echo esc_html( get_the_title( $e ) ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
					<p><a href="<?php echo esc_url( cead_acad_url( 'panel/calendario' ) ); ?>"><?php esc_html_e( 'Ver el calendario completo →', 'cead-acad' ); ?></a></p>
				<?php else : ?>
					<h3><?php esc_html_e( 'Nada por ahora', 'cead-acad' ); ?></h3>
					<p><?php esc_html_e( 'No hay eventos próximos en tu calendario.', 'cead-acad' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<!-- Últimos comunicados -->
		<h3 class="cead-acad-section-h" style="margin-top:2rem">
			<?php esc_html_e( 'Últimos comunicados', 'cead-acad' ); ?>
			<?php if ( $unread > 0 ) : ?><span class="cead-acad-pill"><?php /* translators: %d: cantidad de comunicados sin leer */ printf( esc_html( _n( '%d sin leer', '%d sin leer', $unread, 'cead-acad' ) ), (int) $unread ); ?></span><?php endif; ?>
		</h3>
		<?php if ( $recent_broadcasts ) : ?>
			<div class="cead-acad-grid cead-acad-grid--3">
				<?php foreach ( $recent_broadcasts as $p ) :
					$read = in_array( (int) $p->ID, $read_ids, true );
				?>
					<a class="cead-acad-card cead-acad-card--link" href="<?php echo esc_url( cead_acad_url( 'panel/comunicados/' . $p->ID ) ); ?>">
						<?php if ( ! $read ) : ?><span class="cead-acad-pill"><?php esc_html_e( 'Nuevo', 'cead-acad' ); ?></span><?php endif; ?>
						<span class="cead-acad-eyebrow"><?php echo esc_html( get_the_date( '', $p ) ); ?></span>
						<h3><?php echo esc_html( get_the_title( $p ) ); ?></h3>
						<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $p->post_content ), 18 ) ); ?></p>
					</a>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p class="cead-acad-panel-sub"><?php esc_html_e( 'No hay comunicados todavía.', 'cead-acad' ); ?></p>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
