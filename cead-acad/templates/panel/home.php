<?php
/**
 * Panel — home (dashboard contextual por rol).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user  = wp_get_current_user();
$role  = cead_acad_user_role( $user->ID );
$roles = Cead_Acad_Capabilities::roles();
$rdisp = $roles[ $role ]['display'] ?? $role;

$sub = isset( $sub ) ? (string) $sub : '';

$page_title = __( 'Inicio', 'cead-acad' );

$body = function () use ( $user, $rdisp, $sub ) {
	$unread = (int) Cead_Acad_Broadcasts_Reads::count_unread_for_user( $user->ID );
	$recent_broadcasts = Cead_Acad_Broadcasts_Feed::for_user( $user->ID, [ 'per_page' => 3 ] );
	$course_ids = Cead_Acad_Courses_Roster::courses_for_user( $user->ID );
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Bienvenido/a', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h">
			<?php
			/* translators: %s nombre. */
			printf( esc_html__( 'Hola, %s', 'cead-acad' ), esc_html( $user->display_name ) );
			?>
		</h2>
		<p class="cead-acad-panel-sub">
			<?php
			/* translators: %s rol. */
			printf( esc_html__( 'Estás ingresando como %s.', 'cead-acad' ), '<strong>' . esc_html( $rdisp ) . '</strong>' );
			?>
		</p>

		<?php if ( $sub ) : ?>
			<div class="cead-acad-card" style="margin-top:1.5rem">
				<span class="cead-acad-pill"><?php esc_html_e( 'En desarrollo', 'cead-acad' ); ?></span>
				<h3><?php esc_html_e( 'Sección no disponible', 'cead-acad' ); ?></h3>
				<p><?php
					/* translators: %s ruta solicitada. */
					printf( esc_html__( 'La sección %s todavía no está implementada.', 'cead-acad' ), '<code>' . esc_html( $sub ) . '</code>' );
				?></p>
			</div>
		<?php else : ?>
			<div class="cead-acad-grid cead-acad-grid--3" style="margin-top:2rem">
				<a class="cead-acad-card cead-acad-card--link" href="<?php echo esc_url( cead_acad_url( 'panel/comunicados' ) ); ?>">
					<span class="cead-acad-eyebrow"><?php esc_html_e( 'Comunicados', 'cead-acad' ); ?></span>
					<h3>
						<?php
						if ( $unread > 0 ) {
							printf( esc_html( _n( '%d sin leer', '%d sin leer', $unread, 'cead-acad' ) ), (int) $unread );
						} else {
							esc_html_e( 'Al día', 'cead-acad' );
						}
						?>
					</h3>
					<p><?php esc_html_e( 'Mensajes dirigidos a tu rol, curso o personalmente.', 'cead-acad' ); ?></p>
				</a>

				<div class="cead-acad-card">
					<span class="cead-acad-eyebrow"><?php esc_html_e( 'Tu(s) curso(s)', 'cead-acad' ); ?></span>
					<h3><?php echo (int) count( $course_ids ); ?></h3>
					<p>
					<?php if ( $course_ids ) :
						$titles = array_filter( array_map( function ( $id ) { return get_the_title( $id ); }, $course_ids ) );
						echo esc_html( implode( ' · ', array_slice( $titles, 0, 3 ) ) );
					else : esc_html_e( 'Todavía no estás asignado a un curso.', 'cead-acad' ); endif; ?>
					</p>
				</div>

				<div class="cead-acad-card">
					<span class="cead-acad-eyebrow"><?php esc_html_e( 'Próximamente', 'cead-acad' ); ?></span>
					<h3><?php esc_html_e( 'Encuestas · Horarios', 'cead-acad' ); ?></h3>
					<p><?php esc_html_e( 'Estos módulos llegan en las próximas fases.', 'cead-acad' ); ?></p>
				</div>
			</div>

			<?php if ( $recent_broadcasts ) :
				$read_ids = Cead_Acad_Broadcasts_Reads::read_ids_for_user( $user->ID );
			?>
				<h3 class="cead-acad-section-h"><?php esc_html_e( 'Últimos comunicados', 'cead-acad' ); ?></h3>
				<div class="cead-acad-grid cead-acad-grid--2">
					<?php foreach ( $recent_broadcasts as $p ) :
						$read = in_array( (int) $p->ID, $read_ids, true );
					?>
						<a class="cead-acad-card cead-acad-card--link" href="<?php echo esc_url( cead_acad_url( 'panel/comunicados/' . $p->ID ) ); ?>">
							<?php if ( ! $read ) : ?><span class="cead-acad-pill"><?php esc_html_e( 'Nuevo', 'cead-acad' ); ?></span><?php endif; ?>
							<span class="cead-acad-eyebrow"><?php echo esc_html( get_the_date( '', $p ) ); ?></span>
							<h3><?php echo esc_html( get_the_title( $p ) ); ?></h3>
							<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $p->post_content ), 20 ) ); ?></p>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
