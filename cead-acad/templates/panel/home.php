<?php
/**
 * Panel — home (stub F0). Las siguientes fases reemplazarán esto con el shell
 * sidebar+topbar+slot definitivo.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user = wp_get_current_user();
$role_slug = cead_acad_user_role( $user->ID );
$roles = Cead_Acad_Capabilities::roles();
$role_display = $roles[ $role_slug ]['display'] ?? $role_slug;

$sub = isset( $sub ) ? (string) $sub : '';

get_header();
?>
<section class="cead-acad-section">
	<div class="container">
		<span class="eyebrow"><?php esc_html_e( 'Panel', 'cead-acad' ); ?></span>
		<h1 class="display-h2"><?php
			/* translators: %s: nombre del usuario. */
			printf( esc_html__( 'Hola, %s', 'cead-acad' ), esc_html( $user->display_name ) );
		?></h1>
		<p class="cead-acad-section-intro">
			<?php
			/* translators: %s: rol del usuario. */
			printf( esc_html__( 'Tu rol: %s.', 'cead-acad' ), '<strong>' . esc_html( $role_display ) . '</strong>' );
			?>
		</p>

		<?php if ( $sub ) : ?>
			<div class="cead-acad-card">
				<p><strong><?php esc_html_e( 'Sección solicitada:', 'cead-acad' ); ?></strong> <code><?php echo esc_html( $sub ); ?></code></p>
				<p><em><?php esc_html_e( 'Este módulo llega en una fase próxima.', 'cead-acad' ); ?></em></p>
			</div>
		<?php else : ?>
			<div class="cead-acad-grid cead-acad-grid--3">
				<div class="cead-acad-card">
					<span class="eyebrow"><?php esc_html_e( 'Próximamente', 'cead-acad' ); ?></span>
					<h3><?php esc_html_e( 'Comunicados', 'cead-acad' ); ?></h3>
					<p><?php esc_html_e( 'Vas a poder leer los comunicados dirigidos a tu curso, rol o personalmente.', 'cead-acad' ); ?></p>
				</div>
				<div class="cead-acad-card">
					<span class="eyebrow"><?php esc_html_e( 'Próximamente', 'cead-acad' ); ?></span>
					<h3><?php esc_html_e( 'Encuestas', 'cead-acad' ); ?></h3>
					<p><?php esc_html_e( 'Responder encuestas y, según tu rol, crearlas y ver resultados.', 'cead-acad' ); ?></p>
				</div>
				<div class="cead-acad-card">
					<span class="eyebrow"><?php esc_html_e( 'Próximamente', 'cead-acad' ); ?></span>
					<h3><?php esc_html_e( 'Horarios', 'cead-acad' ); ?></h3>
					<p><?php esc_html_e( 'Calendario de clases, reuniones, exámenes y eventos.', 'cead-acad' ); ?></p>
				</div>
			</div>
		<?php endif; ?>

		<p class="cead-acad-section-intro" style="margin-top:2.5rem">
			<a href="<?php echo esc_url( cead_acad_url( 'salir' ) ); ?>" class="cead-acad-btn cead-acad-btn--ghost"><?php esc_html_e( 'Cerrar sesión', 'cead-acad' ); ?></a>
		</p>
	</div>
</section>
<?php
get_footer();
