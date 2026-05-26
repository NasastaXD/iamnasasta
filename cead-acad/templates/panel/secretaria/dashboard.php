<?php
/**
 * /panel/secretaria: hub para secretaría (también accesible para dirección).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! current_user_can( 'cead_acad_manage_invitations' ) && ! current_user_can( 'cead_acad_import_data' ) ) {
	wp_die( esc_html__( 'Esta sección es solo para secretaría y dirección.', 'cead-acad' ), 403 );
}

global $wpdb;

// Conteos rápidos.
$invitations_table = cead_acad_table( 'invitations' );
$pending_invites   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$invitations_table} WHERE used_at IS NULL AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()" );

$courses_count = wp_count_posts( Cead_Acad_Courses_CPT::POST_TYPE );
$courses_pub   = (int) ( $courses_count->publish ?? 0 );

$students = count_users();
$students_count = (int) ( $students['avail_roles']['cead_acad_student'] ?? 0 );

$broadcasts_count = wp_count_posts( Cead_Acad_Broadcasts_CPT::POST_TYPE );
$broadcasts_drafts = (int) ( $broadcasts_count->draft ?? 0 );

$import_jobs = Cead_Acad_Importer_Job::recent( 5 );

$page_title = __( 'Secretaría', 'cead-acad' );

$body = function () use ( $pending_invites, $courses_pub, $students_count, $broadcasts_drafts, $import_jobs ) {
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Hub administrativo', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Secretaría', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Acceso rápido a invitaciones, cursos, alumnado e importadores.', 'cead-acad' ); ?></p>

		<div class="cead-acad-grid cead-acad-grid--4" style="margin-top:2rem">
			<div class="cead-acad-card">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Invitaciones vigentes', 'cead-acad' ); ?></span>
				<h3><?php echo (int) $pending_invites; ?></h3>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=cead-acad-invitations' ) ); ?>"><?php esc_html_e( 'Gestionar →', 'cead-acad' ); ?></a></p>
			</div>
			<div class="cead-acad-card">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Cursos publicados', 'cead-acad' ); ?></span>
				<h3><?php echo (int) $courses_pub; ?></h3>
				<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Cead_Acad_Courses_CPT::POST_TYPE ) ); ?>"><?php esc_html_e( 'Ver cursos →', 'cead-acad' ); ?></a></p>
			</div>
			<div class="cead-acad-card">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Alumnado', 'cead-acad' ); ?></span>
				<h3><?php echo (int) $students_count; ?></h3>
				<p><a href="<?php echo esc_url( admin_url( 'users.php?role=cead_acad_student' ) ); ?>"><?php esc_html_e( 'Listar →', 'cead-acad' ); ?></a></p>
			</div>
			<div class="cead-acad-card">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Borradores comunicados', 'cead-acad' ); ?></span>
				<h3><?php echo (int) $broadcasts_drafts; ?></h3>
				<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_status=draft&post_type=' . Cead_Acad_Broadcasts_CPT::POST_TYPE ) ); ?>"><?php esc_html_e( 'Editar →', 'cead-acad' ); ?></a></p>
			</div>
		</div>

		<h3 class="cead-acad-section-h"><?php esc_html_e( 'Importaciones recientes', 'cead-acad' ); ?></h3>
		<?php if ( ! $import_jobs ) : ?>
			<div class="cead-acad-card cead-acad-card--empty">
				<p><?php esc_html_e( 'Sin importaciones todavía.', 'cead-acad' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=cead-acad-importers' ) ); ?>"><?php esc_html_e( 'Subir CSV →', 'cead-acad' ); ?></a></p>
			</div>
		<?php else : ?>
			<div class="cead-acad-feed">
				<?php foreach ( $import_jobs as $j ) : ?>
					<a class="cead-acad-feed-item is-read" href="<?php echo esc_url( admin_url( 'admin.php?page=cead-acad-importers&job=' . (int) $j['id'] ) ); ?>">
						<div class="cead-acad-feed-item-meta">
							<span class="cead-acad-eyebrow"><?php echo esc_html( ucfirst( $j['type'] ) ); ?> · <?php echo esc_html( $j['status'] ); ?></span>
						</div>
						<h3 class="cead-acad-feed-item-title"><?php echo esc_html( $j['original_filename'] ); ?></h3>
						<p class="cead-acad-feed-item-excerpt">
							<?php
							printf(
								esc_html__( '%1$d OK · %2$d errores · %3$s', 'cead-acad' ),
								(int) $j['rows_ok'],
								(int) $j['rows_failed'],
								esc_html( mysql2date( get_option( 'date_format' ), $j['created_at'] ) )
							);
							?>
						</p>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<h3 class="cead-acad-section-h"><?php esc_html_e( 'Accesos rápidos', 'cead-acad' ); ?></h3>
		<div class="cead-acad-grid cead-acad-grid--3">
			<a class="cead-acad-card cead-acad-card--link" href="<?php echo esc_url( admin_url( 'admin.php?page=cead-acad-invitations' ) ); ?>">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Acción', 'cead-acad' ); ?></span>
				<h3><?php esc_html_e( 'Generar invitaciones', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Crear tokens para sumar usuarios al sistema.', 'cead-acad' ); ?></p>
			</a>
			<a class="cead-acad-card cead-acad-card--link" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Cead_Acad_Broadcasts_CPT::POST_TYPE ) ); ?>">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Acción', 'cead-acad' ); ?></span>
				<h3><?php esc_html_e( 'Nuevo comunicado', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Publicar un comunicado dirigido.', 'cead-acad' ); ?></p>
			</a>
			<a class="cead-acad-card cead-acad-card--link" href="<?php echo esc_url( admin_url( 'admin.php?page=cead-acad-importers' ) ); ?>">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Acción', 'cead-acad' ); ?></span>
				<h3><?php esc_html_e( 'Importar CSV', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Alumnado o calificaciones.', 'cead-acad' ); ?></p>
			</a>
		</div>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
